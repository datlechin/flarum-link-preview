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

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use s9e\TextFormatter\Utils;

/**
 * Answered from the database, never over the network: on a single worker the
 * fetch would wait on the request serving it, and carrying no session it would
 * show a guest a discussion they cannot read. Every lookup is scoped to the
 * reader, so a card never says more than its page would.
 *
 * @phpstan-import-type MetaItem from Preview
 */
final class InternalPreviewer
{
    private const MAX_DESCRIPTION_LENGTH = 400;

    /**
     * @var array{scheme: string, host: string, port: int, path: string}|null
     */
    private ?array $origin = null;

    /**
     * `false` while unread, so a forum with no favicon is looked up once rather
     * than once per link.
     */
    private string|false|null $favicon = false;

    public function __construct(
        private UrlGenerator $url,
        private SettingsRepositoryInterface $settings,
        private SlugManager $slugs,
        private ExtensionManager $extensions,
    ) {
    }

    /**
     * Host and port, never the scheme: the http spelling of a forum's own https
     * link is still its own link. For the same reason a link naming no port
     * reads as this forum's port, not the scheme's default.
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
        $path = $this->path($url);

        if ($path === null) {
            return null;
        }

        if ($path === '') {
            return $this->forum($url);
        }

        if (preg_match('~^/d/(\d+)(?:-[^/]*)?(?:/([^/]*))?$~', $path, $matches) === 1) {
            $near = $matches[2] ?? '';

            return $near === '' || ctype_digit($near)
                ? $this->discussion($url, (int) $matches[1], $actor)
                : null;
        }

        if (preg_match('~^/u/([^/]+)$~', $path, $matches) === 1) {
            return $this->user($url, $matches[1], $actor);
        }

        if (preg_match('~^/t/([^/]+)$~', $path, $matches) === 1) {
            return $this->extensions->isEnabled('flarum-tags')
                ? $this->tag($url, $matches[1], $actor)
                : null;
        }

        return null;
    }

    private function discussion(string $url, int $id, User $actor): ?Preview
    {
        $discussion = Discussion::whereVisibleTo($actor)->find($id);

        if (! $discussion instanceof Discussion) {
            return null;
        }

        /** @var list<MetaItem> $meta */
        $meta = [];

        foreach ($this->tagNames($discussion, $actor) as $name) {
            $meta[] = ['key' => 'tag', 'text' => $name];
        }

        $author = $discussion->user?->username;

        if ($author !== null) {
            $meta[] = ['key' => 'author', 'text' => $author];
        }

        // The count includes the opening post, which is not a reply to itself.
        $meta[] = ['key' => 'replies', 'count' => max(0, $discussion->comment_count - 1)];
        $meta[] = ['key' => 'created', 'date' => $discussion->created_at->toAtomString()];

        return Preview::internal(
            url: $url,
            type: 'discussion',
            title: $discussion->title,
            description: $this->excerpt($discussion, $actor),
            siteName: self::text($this->settings->get('forum_title')),
            favicon: $this->faviconUrl(),
            meta: $meta,
        );
    }

    /**
     * No description: a bio arrives with an extension that owns the rule for
     * who may read one, and guessing it here is how a card says more than the
     * profile would.
     */
    private function user(string $url, string $slug, User $actor): ?Preview
    {
        $user = $this->fromSlug(User::class, $slug, $actor);

        if (! $user instanceof User) {
            return null;
        }

        /** @var list<MetaItem> $meta */
        $meta = [['key' => 'posts', 'count' => $user->comment_count]];

        if ($user->joined_at !== null) {
            $meta[] = ['key' => 'joined', 'date' => $user->joined_at->toAtomString()];
        }

        return Preview::internal(
            url: $url,
            type: 'user',
            title: $user->display_name,
            siteName: self::text($this->settings->get('forum_title')),
            favicon: $this->faviconUrl(),
            meta: $meta,
            image: $user->avatar_url,
        );
    }

    private function tag(string $url, string $slug, User $actor): ?Preview
    {
        $tag = $this->fromSlug(Tag::class, $slug, $actor);

        if (! $tag instanceof Tag) {
            return null;
        }

        return Preview::internal(
            url: $url,
            type: 'tag',
            title: $tag->name,
            description: self::text($tag->description),
            siteName: self::text($this->settings->get('forum_title')),
            favicon: $this->faviconUrl(),
            meta: [['key' => 'discussions', 'count' => $tag->discussion_count]],
        );
    }

    private function forum(string $url): Preview
    {
        return Preview::internal(
            url: $url,
            type: 'forum',
            title: self::text($this->settings->get('forum_title')),
            description: self::text($this->settings->get('forum_description')),
            siteName: $this->origin()['host'],
            favicon: $this->faviconUrl(),
        );
    }

    private function path(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if ($path === false) {
            return null;
        }

        $path = is_string($path) ? $path : '';
        $base = $this->origin()['path'];

        // A forum installed at example.com/forum does not own example.com/d/1,
        // so the base path has to match in full before what follows it means
        // anything.
        if ($base !== '') {
            if ($path !== $base && ! str_starts_with($path, $base.'/')) {
                return null;
            }

            $path = substr($path, strlen($base));
        }

        return rtrim($path, '/');
    }

    /**
     * Read through the configured slug driver rather than by column: a forum
     * set to id slugs writes `/u/5`, which a query by username would never find.
     *
     * @template T of AbstractModel
     *
     * @param  class-string<T>  $resource
     * @return T|null
     */
    private function fromSlug(string $resource, string $slug, User $actor): ?AbstractModel
    {
        try {
            return $this->slugs->forResource($resource)->fromSlug($slug, $actor);
        } catch (ModelNotFoundException) {
            return null;
        }
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

        $plain = trim(preg_replace('/\s+/', ' ', Utils::removeFormatting($xml)) ?? '');

        if ($plain === '') {
            return null;
        }

        return mb_substr($plain, 0, self::MAX_DESCRIPTION_LENGTH);
    }

    /**
     * Scoped rather than read off the discussion, so a tag the reader may not
     * see never reaches a card as a name. `$discussion->tags` is the obvious
     * simplification and leaks them.
     *
     * @return list<string>
     */
    private function tagNames(Discussion $discussion, User $actor): array
    {
        if (! $this->extensions->isEnabled('flarum-tags')) {
            return [];
        }

        $names = [];

        $query = Tag::whereVisibleTo($actor)
            ->join('discussion_tag', 'discussion_tag.tag_id', '=', 'tags.id')
            ->where('discussion_tag.discussion_id', $discussion->id)
            ->orderBy('tags.position');

        /** @var Tag $tag */
        foreach ($query->get(['tags.name']) as $tag) {
            $names[] = $tag->name;
        }

        return $names;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

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
     * A host is case insensitive, may carry the root's trailing dot, and is
     * bracketed when it is an IPv6 literal.
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
