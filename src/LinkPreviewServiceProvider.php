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

final class LinkPreviewServiceProvider extends AbstractServiceProvider
{
    /**
     * `SafeFetcher` asks for the interface, which the container cannot build on
     * its own, so without this every request to either endpoint answers 500.
     *
     * Bound rather than shared: a resolver that outlived one request would hold
     * an answer past the point where it is still true.
     */
    public function register(): void
    {
        $this->container->bind(Resolver::class, SystemResolver::class);
    }
}
