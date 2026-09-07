<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Preview;

use Datlechin\LinkPreview\Html\Metadata;
use Datlechin\LinkPreview\Html\MetadataExtractor;
use Datlechin\LinkPreview\Http\Exception\LinkPreviewException;
use Datlechin\LinkPreview\Http\Exception\UnsafeUrlException;
use Datlechin\LinkPreview\Http\Exception\UnsupportedContentException;
use Datlechin\LinkPreview\Http\FetchResult;
use Datlechin\LinkPreview\Http\SafeFetcher;
use Datlechin\LinkPreview\Settings\Config;
use Flarum\User\User;
use Illuminate\Cache\Repository;

/**
 * Turns addresses into cards.
 *
 * Everything that can be decided without leaving the server is decided first:
 * a malformed address, a filtered host, a link back to this forum, a preview
 * already in the cache. Only what is left goes to the network, and it goes in
 * one call, because a post with six links should cost the slowest of the six
 * rather than the sum of them.
 *
 * Failures are cached too, for a shorter time than successes. Without that, a
 * domain that has gone away costs a fresh connect timeout for every reader who
 * scrolls past the post, which is how a single dead link makes a whole forum
 * feel slow.
 */
final class Previewer
{
    private const CACHE_PREFIX = 'datlechin-link-preview:v2:';

    private ?UrlFilter $filter = null;

    public function __construct(
        private Config $config,
        private SafeFetcher $fetcher,
        private MetadataExtractor $extractor,
        private DiscussionPreviewer $discussions,
        private Repository $cache,
    ) {
    }

    public function preview(string $url, User $actor): Preview
    {
        $settled = $this->settle($url, $actor);

        if ($settled !== null) {
            return $settled;
        }

        try {
            return $this->fromResult($url, $this->fetcher->fetch($url, $this->hopFilter()));
        } catch (LinkPreviewException $exception) {
            return $this->fromFailure($url, $exception);
        }
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, Preview>
     */
    public function previewMany(array $urls, User $actor): array
    {
        $previews = [];

        /** @var array<string, true> $pending */
        $pending = [];

        foreach ($urls as $url) {
            if (isset($previews[$url]) || isset($pending[$url])) {
                continue;
            }

            $settled = $this->settle($url, $actor);

            if ($settled !== null) {
                $previews[$url] = $settled;

                continue;
            }

            $pending[$url] = true;
        }

        if ($pending === []) {
            return $previews;
        }

        $remaining = array_keys($pending);
        $results = $this->fetcher->fetchMany($remaining, $this->hopFilter());

        foreach ($remaining as $url) {
            $result = $results[$url] ?? null;

            $previews[$url] = $result instanceof FetchResult
                ? $this->fromResult($url, $result)
                : $this->fromFailure($url, $result);
        }

        return $previews;
    }

    /**
     * Everything an answer can be built from without opening a socket.
     *
     * Null means the URL still needs fetching, which is the one thing the
     * caller has to batch.
     */
    private function settle(string $url, User $actor): ?Preview
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! self::isHttp($url)) {
            return Preview::error($url, PreviewError::InvalidUrl);
        }

        if (! $this->filter()->allows($url)) {
            return Preview::error($url, PreviewError::Blocked);
        }

        if ($this->discussions->isInternal($url)) {
            // The browser is expected to have skipped this link without
            // asking. The answer is here for the page still running last
            // week's bundle, and it is a refusal rather than a fetch, because
            // a forum reading its own pages over the network is the one
            // request that can wait on itself.
            if (! $this->config->previewInternalLinks()) {
                return Preview::error($url, PreviewError::NotPreviewable);
            }

            // Not cached: it is one query, and what it is allowed to say
            // depends on who is asking.
            return $this->discussions->preview($url, $actor)
                ?? Preview::error($url, PreviewError::NoMetadata);
        }

        return $this->cached($url);
    }

    private function fromResult(string $url, FetchResult $result): Preview
    {
        // The hop that produced this body was checked before it was requested,
        // so this is the backstop rather than the rule: a fetcher that ignored
        // the guard still must not turn a blocklisted host into a card.
        if (! $this->filter()->allows($result->effectiveUrl)) {
            return $this->fail($url, PreviewError::Blocked);
        }

        $metadata = $this->extractor->extract($result->body, $result->effectiveUrl, $result->contentType);

        if ($metadata->isEmpty()) {
            return $this->fail($url, PreviewError::NoMetadata);
        }

        $this->store($url, [
            'url' => $result->effectiveUrl,
            'title' => $metadata->title,
            'description' => $metadata->description,
            'siteName' => $metadata->siteName,
            'imageUrl' => $metadata->imageUrl,
            'imageWidth' => $metadata->imageWidth,
            'imageHeight' => $metadata->imageHeight,
            'faviconUrl' => $metadata->faviconUrl,
            'prefersLargeImage' => $metadata->prefersLargeImage,
        ], $this->config->cacheSeconds());

        return Preview::link($result->effectiveUrl, $metadata);
    }

    private function fromFailure(string $url, ?LinkPreviewException $exception): Preview
    {
        return $this->fail($url, match (true) {
            $exception instanceof BlockedUrlException => PreviewError::Blocked,
            $exception instanceof UnsafeUrlException => PreviewError::UnsafeAddress,
            $exception instanceof UnsupportedContentException => PreviewError::NotPreviewable,
            self::answered($exception) => PreviewError::HttpError,
            default => PreviewError::Unreachable,
        });
    }

    /**
     * Whether the site answered and the answer was a refusal.
     *
     * A 404, a 400 or a Cloudflare 403 is not the event a name that does not
     * resolve is, and telling a reader "this site did not respond" about a site
     * that responded and said no is simply wrong. The status rides on the
     * exception's code, which every failure that never got an answer leaves at
     * zero.
     */
    private static function answered(?LinkPreviewException $exception): bool
    {
        $status = $exception === null ? 0 : $exception->getCode();

        return $status >= 100 && $status <= 599;
    }

    /**
     * The check the fetcher runs before it opens each connection.
     *
     * A callable rather than the filter itself, so that the fetcher stays a
     * fetcher and never learns what an allowlist is. It refuses by throwing
     * because a throw is what carries the reason back out of a batch attached
     * to the URL that was asked for, rather than ending the whole round.
     *
     * @return callable(string): bool
     */
    private function hopFilter(): callable
    {
        $filter = $this->filter();

        return static function (string $url) use ($filter): bool {
            if (! $filter->allows($url)) {
                throw new BlockedUrlException("Refusing to fetch $url, which the forum's lists do not allow.");
            }

            return true;
        };
    }

    private function fail(string $url, PreviewError $error): Preview
    {
        $this->store($url, ['url' => $url, 'error' => $error->value], $this->config->negativeCacheSeconds());

        return Preview::error($url, $error);
    }

    private function filter(): UrlFilter
    {
        return $this->filter ??= new UrlFilter($this->config->allowlist(), $this->config->blocklist());
    }

    private function cached(string $url): ?Preview
    {
        if ($this->config->cacheSeconds() === 0) {
            return null;
        }

        return self::decode($this->cache->get(self::key($url)));
    }

    /**
     * @param  array<string, scalar|null>  $entry
     */
    private function store(string $url, array $entry, int $seconds): void
    {
        if ($seconds > 0) {
            $this->cache->put(self::key($url), $entry, $seconds);
        }
    }

    /**
     * Rebuild a preview from what the cache holds.
     *
     * A flat array rather than a serialised object, and every field checked on
     * the way back in: cache entries outlive deploys, and an entry written by
     * a version whose classes have since changed shape has to read as a miss
     * rather than as a fatal error on somebody's page load.
     */
    private static function decode(mixed $entry): ?Preview
    {
        if (! is_array($entry)) {
            return null;
        }

        $url = $entry['url'] ?? null;

        if (! is_string($url)) {
            return null;
        }

        $error = $entry['error'] ?? null;

        if (is_string($error)) {
            $case = PreviewError::tryFrom($error);

            return $case === null ? null : Preview::error($url, $case);
        }

        return Preview::link($url, new Metadata(
            title: self::text($entry['title'] ?? null),
            description: self::text($entry['description'] ?? null),
            siteName: self::text($entry['siteName'] ?? null),
            imageUrl: self::text($entry['imageUrl'] ?? null),
            imageWidth: self::number($entry['imageWidth'] ?? null),
            imageHeight: self::number($entry['imageHeight'] ?? null),
            faviconUrl: self::text($entry['faviconUrl'] ?? null),
            prefersLargeImage: self::flag($entry['prefersLargeImage'] ?? null),
        ));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function number(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * A cache driver is free to give a boolean back as `1` or `"1"`, which a
     * strict comparison against `true` would read as false.
     */
    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private static function key(string $url): string
    {
        return self::CACHE_PREFIX.sha1(self::canonical($url));
    }

    /**
     * The form in which two links to the same page are the same link.
     *
     * Keeps the scheme, which the version this replaces threw away, so that
     * `http://example.com` and `https://example.com` no longer share one entry
     * and hand each other's readers the wrong card.
     */
    private static function canonical(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $canonical = $scheme.'://';

        if (isset($parts['user'])) {
            $canonical .= $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@';
        }

        $canonical .= strtolower($parts['host']);

        $port = $parts['port'] ?? null;

        if ($port !== null && $port !== ($scheme === 'https' ? 443 : 80)) {
            $canonical .= ':'.$port;
        }

        $path = $parts['path'] ?? '';

        if ($path !== '/') {
            $canonical .= $path;
        }

        if (isset($parts['query'])) {
            $canonical .= '?'.$parts['query'];
        }

        return $canonical;
    }

    private static function isHttp(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
