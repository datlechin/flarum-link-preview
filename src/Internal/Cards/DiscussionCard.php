<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Internal\Cards;

use Datlechin\LinkPreview\Internal\Card;
use Datlechin\LinkPreview\Internal\MatchedRoute;
use Datlechin\LinkPreview\Internal\Meta;
use Datlechin\LinkPreview\Internal\RouteCard;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\CommentPost;
use Flarum\Tags\Tag;
use Flarum\User\User;
use s9e\TextFormatter\Utils;

final class DiscussionCard implements RouteCard
{
    private const MAX_DESCRIPTION_LENGTH = 400;

    public function __construct(private ExtensionManager $extensions)
    {
    }

    public function card(MatchedRoute $route, User $actor): ?Card
    {
        $discussion = Discussion::whereVisibleTo($actor)->find((int) $route->parameter('id'));

        if (! $discussion instanceof Discussion) {
            return null;
        }

        $meta = array_map(Meta::text(...), $this->tagNames($discussion, $actor));

        $author = $discussion->user?->username;

        if ($author !== null) {
            $meta[] = Meta::user($author);
        }

        $meta[] = Meta::count('datlechin-link-preview.forum.meta.replies', max(0, $discussion->comment_count - 1));
        $meta[] = Meta::date($discussion->created_at);

        return new Card(
            title: $discussion->title,
            description: $this->excerpt($discussion, $actor),
            meta: $meta,
        );
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

        return $plain === '' ? null : mb_substr($plain, 0, self::MAX_DESCRIPTION_LENGTH);
    }

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

        foreach ($query->get(['tags.name']) as $tag) {
            $names[] = $tag->name;
        }

        return $names;
    }
}
