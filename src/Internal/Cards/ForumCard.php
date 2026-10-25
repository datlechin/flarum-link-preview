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
use Datlechin\LinkPreview\Internal\RouteCard;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

final class ForumCard implements RouteCard
{
    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function card(MatchedRoute $route, User $actor): ?Card
    {
        $title = $this->settings->get('forum_title');

        if (! is_string($title) || $title === '') {
            return null;
        }

        $description = $this->settings->get('forum_description');

        return new Card(
            title: $title,
            description: is_string($description) && $description !== '' ? $description : null,
        );
    }
}
