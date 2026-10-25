<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Extend;

use Datlechin\LinkPreview\Internal\RouteCard;
use Datlechin\LinkPreview\Internal\RouteCards;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;

class LinkPreview implements ExtenderInterface
{
    private array $routes = [];

    public function route(string $name, string $card): self
    {
        $this->routes[$name] = $card;

        return $this;
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->extend(RouteCards::class, function (RouteCards $cards): RouteCards {
            foreach ($this->routes as $name => $card) {
                $cards->add($name, $card);
            }

            return $cards;
        });
    }
}
