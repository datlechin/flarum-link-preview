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
 * SafeFetcher asks for the {@see Resolver} interface so a test can hand it a
 * fixed set of addresses instead of whatever DNS says today. An interface is
 * not instantiable, so without this binding every request to either endpoint
 * answers 500.
 *
 * Bound rather than shared: resolving a host must be allowed to see a record
 * change.
 */
final class LinkPreviewServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(Resolver::class, SystemResolver::class);
    }
}
