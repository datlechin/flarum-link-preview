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
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class UserCard implements RouteCard
{
    public function __construct(private SlugManager $slugs)
    {
    }

    public function card(MatchedRoute $route, User $actor): ?Card
    {
        try {
            $user = $this->slugs->forResource(User::class)->fromSlug($route->parameter('username'), $actor);
        } catch (ModelNotFoundException) {
            return null;
        }

        if (! $user instanceof User) {
            return null;
        }

        $meta = [Meta::count('datlechin-link-preview.forum.meta.posts', $user->comment_count)];

        if ($user->joined_at !== null) {
            $meta[] = Meta::date($user->joined_at);
        }

        return new Card(
            title: $user->display_name,
            image: $user->avatar_url,
            meta: $meta,
        );
    }
}
