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
    public function register(): void
    {
        $this->container->bind(Resolver::class, SystemResolver::class);
    }
}
