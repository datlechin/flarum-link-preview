<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview;

use Datlechin\LinkPreview\Http\Resolver;
use Datlechin\LinkPreview\Http\SystemResolver;
use Datlechin\LinkPreview\Internal\RouteCards;
use Datlechin\LinkPreview\Preview\InternalPreviewer;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Http\RouteCollection;
use Illuminate\Contracts\Container\Container;

final class LinkPreviewServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(Resolver::class, SystemResolver::class);

        $this->container->singleton(RouteCards::class);

        $this->container->when(InternalPreviewer::class)
            ->needs(RouteCollection::class)
            ->give(fn (Container $container) => $container->make('flarum.forum.routes'));
    }
}
