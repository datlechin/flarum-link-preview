<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Internal;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final class RouteCards
{
    private array $cards = [];

    public function __construct(private Container $container)
    {
    }

    public function add(string $route, string $card): void
    {
        if (! is_a($card, RouteCard::class, true)) {
            throw new InvalidArgumentException("$card must implement ".RouteCard::class);
        }

        $this->cards[$route] = $card;
    }

    public function for(string $route): ?RouteCard
    {
        $card = $this->cards[$route] ?? null;

        if ($card === null) {
            return null;
        }

        return $this->container->make($card);
    }
}
