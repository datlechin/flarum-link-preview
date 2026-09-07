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
use Flarum\Foundation\AbstractServiceProvider;

/**
 * The one thing the container cannot work out on its own.
 *
 * SafeFetcher asks for the {@see Resolver} interface rather than for
 * {@see SystemResolver}, so that a test can hand it a fixed set of addresses
 * instead of whatever DNS happens to say today. An interface is not
 * instantiable, so without this binding the container cannot build the fetcher
 * and every request to either endpoint answers 500.
 *
 * Bound rather than shared: resolving a host is the one thing here that must be
 * allowed to see a record change, and the object holds nothing worth keeping.
 */
final class LinkPreviewServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(Resolver::class, SystemResolver::class);
    }
}
