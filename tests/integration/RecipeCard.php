<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Tests\integration;

use Carbon\Carbon;
use Datlechin\LinkPreview\Internal\Card;
use Datlechin\LinkPreview\Internal\MatchedRoute;
use Datlechin\LinkPreview\Internal\Meta;
use Datlechin\LinkPreview\Internal\RouteCard;
use Flarum\User\User;
use RuntimeException;

final class RecipeCard implements RouteCard
{
    public const HIDDEN_FROM_GUESTS = '13';

    public const BROKEN = '500';

    public function card(MatchedRoute $route, User $actor): ?Card
    {
        $id = $route->parameter('id');

        if ($id === self::BROKEN) {
            throw new RuntimeException('A card that fails to build.');
        }

        if ($id === self::HIDDEN_FROM_GUESTS && $actor->isGuest()) {
            return null;
        }

        return new Card(
            title: "Recipe $id",
            description: 'Flour, water, salt.',
            image: 'https://cdn.test/recipe.png',
            meta: [
                Meta::text('Bread'),
                Meta::user('normal'),
                Meta::count('acme-recipes.forum.link_preview.steps', 4),
                Meta::date(Carbon::parse('2026-02-03 04:05:06')),
            ],
        );
    }
}
