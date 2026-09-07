<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Html;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use ValueError;

final class MetadataExtractor
{
    public const MAX_TITLE_LENGTH = 200;

    public const MAX_DESCRIPTION_LENGTH = 400;

    /**
     * Browsers stop believing a charset declaration after roughly a kilobyte
     * of head.
     */
    private const CHARSET_SNIFF_BYTES = 2048;

    /**
     * Keeps a hostile document from turning one script tag into unbounded
     * recursion.
     */
    private const MAX_JSON_LD_DEPTH = 4;

    public function extract(string $html, string $baseUrl, ?string $contentType = null): Metadata
    {
        $document = $this->parse($this->toUtf8($html, $contentType));

        if ($document === null) {
            return new Metadata(null, null, null, null, null, null, null, false);
        }

        $xpath = new DOMXPath($document);
        $meta = $this->metaTags($xpath);
        $jsonLd = $this->jsonLd($xpath);
        $base = $this->baseUri($baseUrl);

        $imageUrl = $this->resolveUrl(
            $meta['og:image']
                ?? $meta['og:image:url']
                ?? $meta['og:image:secure_url']
                ?? $meta['twitter:image']
                ?? $meta['twitter:image:src']
                ?? $this->jsonLdImage($jsonLd),
            $base,
        );

        return new Metadata(
            title: $this->clean(
                $meta['og:title']
                    ?? $meta['twitter:title']
                    ?? $this->documentTitle($xpath)
                    ?? $this->jsonLdString($jsonLd, 'headline', 'name'),
                self::MAX_TITLE_LENGTH,
            ),
            description: $this->clean(
                $meta['og:description']
                    ?? $meta['twitter:description']
                    ?? $meta['description']
                    ?? $this->jsonLdString($jsonLd, 'description'),
                self::MAX_DESCRIPTION_LENGTH,
            ),
            siteName: $this->clean(
                $meta['og:site_name']
                    ?? $this->twitterSite($meta)
                    ?? $this->jsonLdPublisher($jsonLd),
                self::MAX_TITLE_LENGTH,
            ),
            imageUrl: $imageUrl,
            imageWidth: $imageUrl === null ? null : $this->dimension($meta, 'og:image:width'),
            imageHeight: $imageUrl === null ? null : $this->dimension($meta, 'og:image:height'),
            faviconUrl: $this->resolveUrl($this->faviconHref($xpath), $base) ?? $this->originFavicon($base),
            prefersLargeImage: strtolower($meta['twitter:card'] ?? '') === 'summary_large_image',
        );
    }

    private function toUtf8(string $html, ?string $contentType): string
    {
        $charset = $this->detectCharset($html, $contentType);

        try {
            $converted = mb_convert_encoding($html, 'UTF-8', $charset);
        } catch (ValueError) {
            // UTF-8 to UTF-8 is not a no-op: it replaces any sequence the
            // parser would choke on.
            $converted = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        }

        return is_string($converted) ? $converted : $html;
    }

    private function detectCharset(string $html, ?string $contentType): string
    {
        if ($contentType !== null && preg_match('/charset\s*=\s*["\']?\s*([a-z0-9_\-:.]+)/i', $contentType, $matches) === 1) {
            return $matches[1];
        }

        $head = substr($html, 0, self::CHARSET_SNIFF_BYTES);

        if (preg_match('/<meta\b[^>]*?charset\s*=\s*["\']?\s*([a-z0-9_\-:.]+)/i', $head, $matches) === 1) {
            return $matches[1];
        }

        return 'UTF-8';
    }

    private function parse(string $html): ?DOMDocument
    {
        $html = $this->declareUtf8($html);

        if (trim($html) === '') {
            return null;
        }

        // Process-wide switch, so it is restored.
        $previous = libxml_use_internal_errors(true);

        $document = new DOMDocument();
        $loaded = $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    /**
     * The bytes are UTF-8 by this point, but libxml still reads the page's own
     * charset declaration and would decode them a second time as whatever the
     * origin claimed. A page that declares nothing it reads as ISO-8859-1.
     */
    private function declareUtf8(string $html): string
    {
        $html = preg_replace('/^\s*<\?xml\b[^?]*\?>/i', '', $html, 1) ?? $html;
        $html = preg_replace('/(<meta\b[^>]*?charset\s*=\s*["\']?)[a-z0-9_\-:.]+/i', '${1}utf-8', $html) ?? $html;

        return '<?xml encoding="UTF-8">'.$html;
    }

    /**
     * @return array<string, string>
     */
    private function metaTags(DOMXPath $xpath): array
    {
        $values = [];

        foreach ($this->elements($xpath, '//meta[@content]') as $node) {
            $key = strtolower(trim($node->getAttribute('property') ?: $node->getAttribute('name')));
            $content = trim($node->getAttribute('content'));

            if ($key === '' || $content === '' || isset($values[$key])) {
                continue;
            }

            $values[$key] = $content;
        }

        return $values;
    }

    private function documentTitle(DOMXPath $xpath): ?string
    {
        foreach ($this->elements($xpath, '//title') as $node) {
            $text = trim($node->textContent);

            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $meta
     */
    private function twitterSite(array $meta): ?string
    {
        $handle = ltrim($meta['twitter:site'] ?? '', '@');

        return $handle === '' ? null : $handle;
    }

    /**
     * @param  array<string, string>  $meta
     */
    private function dimension(array $meta, string $key): ?int
    {
        $value = trim($meta[$key] ?? '');

        if (preg_match('/^\d{1,6}$/', $value) !== 1) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    private function faviconHref(DOMXPath $xpath): ?string
    {
        $best = null;
        $bestRank = PHP_INT_MAX;

        foreach ($this->elements($xpath, '//link[@rel][@href]') as $node) {
            $rank = $this->faviconRank($node->getAttribute('rel'));
            $href = trim($node->getAttribute('href'));

            if ($rank === null || $rank >= $bestRank || $href === '') {
                continue;
            }

            $best = $href;
            $bestRank = $rank;
        }

        return $best;
    }

    /**
     * Lower is a better candidate; `null` is not an icon at all.
     */
    private function faviconRank(string $rel): ?int
    {
        $rel = strtolower(trim((string) preg_replace('/\s+/', ' ', $rel)));

        return match (true) {
            $rel === 'icon' => 0,
            $rel === 'shortcut icon', $rel === 'icon shortcut' => 1,
            $rel === 'apple-touch-icon' => 2,
            str_contains($rel, 'icon') => 3,
            default => null,
        };
    }

    private function originFavicon(?UriInterface $base): ?string
    {
        if ($base === null) {
            return null;
        }

        return (string) $base
            ->withPath('/favicon.ico')
            ->withQuery('')
            ->withFragment('');
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function jsonLd(DOMXPath $xpath): array
    {
        $nodes = [];

        foreach ($this->elements($xpath, '//script[@type]') as $script) {
            if (! str_contains(strtolower($script->getAttribute('type')), 'ld+json')) {
                continue;
            }

            foreach ($this->flattenJsonLd(json_decode(trim($script->textContent), true)) as $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function flattenJsonLd(mixed $value, int $depth = 0): array
    {
        if (! is_array($value) || $depth > self::MAX_JSON_LD_DEPTH) {
            return [];
        }

        $nodes = [];

        if (array_is_list($value)) {
            foreach ($value as $item) {
                foreach ($this->flattenJsonLd($item, $depth + 1) as $node) {
                    $nodes[] = $node;
                }
            }

            return $nodes;
        }

        $nodes[] = $value;

        foreach ($this->flattenJsonLd($value['@graph'] ?? null, $depth + 1) as $node) {
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Each key is tried across every node before the next key is, so an
     * `Article`'s `headline` beats a `WebSite` node's `name` no matter which
     * came first in the graph.
     *
     * @param  list<array<array-key, mixed>>  $nodes
     */
    private function jsonLdString(array $nodes, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            foreach ($nodes as $node) {
                $value = $node[$key] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<array-key, mixed>>  $nodes
     */
    private function jsonLdPublisher(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            $publisher = $node['publisher'] ?? null;

            if (is_string($publisher) && trim($publisher) !== '') {
                return $publisher;
            }

            $name = is_array($publisher) ? ($publisher['name'] ?? null) : null;

            if (is_string($name) && trim($name) !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  list<array<array-key, mixed>>  $nodes
     */
    private function jsonLdImage(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            $url = $this->jsonLdImageValue($node['image'] ?? null);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function jsonLdImageValue(mixed $value, int $depth = 0): ?string
    {
        if (is_string($value)) {
            return trim($value) === '' ? null : $value;
        }

        if (! is_array($value) || $depth > self::MAX_JSON_LD_DEPTH) {
            return null;
        }

        if (array_is_list($value)) {
            foreach ($value as $item) {
                $url = $this->jsonLdImageValue($item, $depth + 1);

                if ($url !== null) {
                    return $url;
                }
            }

            return null;
        }

        $url = $value['url'] ?? null;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }

    private function baseUri(string $baseUrl): ?UriInterface
    {
        try {
            $uri = new Uri($baseUrl);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $uri->getScheme() !== '' && $uri->getHost() !== '' ? $uri : null;
    }

    /**
     * `data:` and `javascript:` are dropped here rather than shipped for the
     * frontend to hide.
     */
    private function resolveUrl(?string $value, ?UriInterface $base): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($value === '') {
            return null;
        }

        try {
            $target = new Uri($value);
        } catch (InvalidArgumentException) {
            return null;
        }

        // A bare `#` resolves to the page itself: valid per RFC 3986, and a
        // broken thumbnail as an image.
        if ($this->isSameDocument($target)) {
            return null;
        }

        $resolved = $base === null ? $target : UriResolver::resolve($base, $target);
        $scheme = strtolower($resolved->getScheme());

        if (($scheme !== 'http' && $scheme !== 'https') || $resolved->getHost() === '') {
            return null;
        }

        return (string) $resolved;
    }

    private function isSameDocument(UriInterface $reference): bool
    {
        return $reference->getScheme() === ''
            && $reference->getAuthority() === ''
            && $reference->getPath() === ''
            && $reference->getQuery() === '';
    }

    private function clean(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = trim((string) preg_replace('/[\s\x{00a0}\x{200b}\x{feff}]+/u', ' ', $decoded));

        return $text === '' ? null : $this->truncate($text, $limit);
    }

    private function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit - 1);
        $boundary = mb_strrpos($cut, ' ');

        // Japanese and Chinese do not space their words, so a single early
        // space would otherwise throw the whole description away.
        if ($boundary !== false && $boundary >= intdiv($limit, 2)) {
            $cut = mb_substr($cut, 0, $boundary);
        }

        return rtrim($cut, " \t\n\r\0\x0B,;:.!?-").'…';
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(DOMXPath $xpath, string $expression): array
    {
        $nodes = $xpath->query($expression);

        if ($nodes === false) {
            return [];
        }

        $elements = [];

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }
}
