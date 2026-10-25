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
use Flarum\Http\SlugManager;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class TagCard implements RouteCard
{
    public function __construct(private SlugManager $slugs)
    {
    }

    public function card(MatchedRoute $route, User $actor): ?Card
    {
        try {
            $tag = $this->slugs->forResource(Tag::class)->fromSlug($route->parameter('slug'), $actor);
        } catch (ModelNotFoundException) {
            return null;
        }

        if (! $tag instanceof Tag) {
            return null;
        }

        return new Card(
            title: $tag->name,
            description: is_string($tag->description) && $tag->description !== '' ? $tag->description : null,
            meta: [Meta::count('datlechin-link-preview.forum.meta.discussions', $tag->discussion_count)],
        );
    }
}
