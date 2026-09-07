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

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\UrlGenerator;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use s9e\TextFormatter\Utils;

/**
 * Previews for links that point back at this forum.
 *
 * Answered from the database, never over the network: on a single worker the
 * fetch would wait on the request serving it, and carrying no session it would
 * show a guest a discussion they cannot read. The address shape recognised here
 * must match core's `parseDiscussionUrl()` and `labelDiscussionLinks.ts`.
 */
final class DiscussionPreviewer
{
    private const MAX_DESCRIPTION_LENGTH = 400;

    /**
     * @var array{scheme: string, host: string, port: int, path: string}|null
     */
    private ?array $origin = null;

    /**
     * `false` while unread, so a forum with no favicon is looked up once
     * rather than on every link in a batch of twenty.
     */
    private string|false|null $favicon = false;

    public function __construct(
        private UrlGenerator $url,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * Whether this address is one this forum serves itself.
     *
     * Host and port, never the scheme: the http spelling of a forum's own https
     * link is still its own link, and comparing schemes sends it out to be
     * fetched from the server already handling the request. For the same reason
     * a link naming no port reads as this forum's port, not the scheme's default.
     */
    public function isInternal(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return false;
        }

        $origin = $this->origin();
        $port = $parts['port'] ?? null;

        return self::host($parts['host']) === $origin['host']
            && ($port === null || $port === $origin['port']);
    }

    public function preview(string $url, User $actor): ?Preview
    {
        $id = $this->discussionId($url);

        if ($id === null) {
            return null;
        }

        $discussion = Discussion::whereVisibleTo($actor)->find($id);

        if (! $discussion instanceof Discussion) {
            return null;
        }

        return Preview::discussion(
            $url,
            $discussion->title,
            $this->excerpt($discussion, $actor),
            $this->forumTitle(),
            $this->faviconUrl(),
            [
                'id' => $discussion->id,
                'commentCount' => $discussion->comment_count,
                'author' => $discussion->user?->username,
                'createdAt' => $discussion->created_at->toAtomString(),
                'tags' => $this->tags($discussion, $actor),
            ],
        );
    }

    private function discussionId(string $url): ?int
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $base = $this->origin()['path'];

        // A forum installed at example.com/forum does not own example.com/d/1,
        // so the base path has to be present in full before what follows it
        // means anything.
        if ($base !== '') {
            if ($path !== $base && ! str_starts_with($path, $base.'/')) {
                return null;
            }

            $path = substr($path, strlen($base));
        }

        if (! preg_match('~^/d/(\d+)(?:-[^/]*)?(?:/([^/]*))?/?$~', $path, $matches)) {
            return null;
        }

        // `/d/1/near-something` and other positions that are not a post number
        // keep their address, the same call core makes when labelling links.
        $near = $matches[2] ?? '';

        if ($near !== '' && ! ctype_digit($near)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function excerpt(Discussion $discussion, User $actor): ?string
    {
        $post = $discussion->firstPost;

        if (! $post instanceof CommentPost || ! $post->isVisibleTo($actor)) {
            return null;
        }

        $xml = $post->parsed_content;

        if (! is_string($xml) || $xml === '') {
            return null;
        }

        // Straight from the stored representation: no render, no formatter
        // callbacks, no HTML to strip off again.
        $plain = trim(preg_replace('/\s+/', ' ', Utils::removeFormatting($xml)) ?? '');

        if ($plain === '') {
            return null;
        }

        return mb_substr($plain, 0, self::MAX_DESCRIPTION_LENGTH);
    }

    /**
     * @return list<array{name: string}>
     */
    private function tags(Discussion $discussion, User $actor): array
    {
        /** @var ExtensionManager $extensions */
        $extensions = resolve(ExtensionManager::class);

        // The relation, the table and the model all belong to an extension the
        // forum may not have turned on.
        if (! $extensions->isEnabled('flarum-tags')) {
            return [];
        }

        $tags = [];

        // Scoped rather than read off the discussion, so a tag the actor may
        // not see does not arrive on a card as a name.
        $query = Tag::whereVisibleTo($actor)
            ->join('discussion_tag', 'discussion_tag.tag_id', '=', 'tags.id')
            ->where('discussion_tag.discussion_id', $discussion->id)
            ->orderBy('tags.position');

        /** @var Tag $tag */
        foreach ($query->get(['tags.name']) as $tag) {
            $tags[] = ['name' => $tag->name];
        }

        return $tags;
    }

    private function forumTitle(): ?string
    {
        $title = $this->settings->get('forum_title');

        return is_string($title) && $title !== '' ? $title : null;
    }

    /**
     * Resolved through the assets filesystem rather than assembled by hand, so
     * a forum serving its uploads from a CDN gets the CDN address here too.
     */
    private function faviconUrl(): ?string
    {
        if ($this->favicon === false) {
            $path = $this->settings->get('favicon_path');

            if (is_string($path) && $path !== '') {
                /** @var Factory $filesystem */
                $filesystem = resolve(Factory::class);

                /** @var Cloud $assets */
                $assets = $filesystem->disk('flarum-assets');

                $this->favicon = $assets->url($path);
            } else {
                $this->favicon = null;
            }
        }

        return $this->favicon;
    }

    /**
     * The forum's own scheme, host, port and base path. Kept rather than
     * rebuilt: a batch asks about twenty links and the answer cannot change
     * inside one request.
     *
     * @return array{scheme: string, host: string, port: int, path: string}
     */
    private function origin(): array
    {
        if ($this->origin !== null) {
            return $this->origin;
        }

        $base = parse_url($this->url->to('forum')->base()) ?: [];
        $scheme = strtolower($base['scheme'] ?? 'https');

        return $this->origin = [
            'scheme' => $scheme,
            'host' => self::host($base['host'] ?? ''),
            'port' => self::port($scheme, $base['port'] ?? null),
            'path' => rtrim($base['path'] ?? '', '/'),
        ];
    }

    /**
     * The one spelling of a name that has several: a host is case insensitive,
     * may carry the root's trailing dot, and is bracketed when it is an IPv6
     * literal, so the same address arrives here in four shapes.
     */
    private static function host(string $host): string
    {
        return trim(strtolower(rtrim(trim($host), '.')), '[]');
    }

    private static function port(string $scheme, ?int $port): int
    {
        return $port ?? (strtolower($scheme) === 'https' ? 443 : 80);
    }
}
