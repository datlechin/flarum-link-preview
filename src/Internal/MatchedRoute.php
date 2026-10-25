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

final class MatchedRoute
{
    public function __construct(
        public readonly string $name,
        public readonly array $parameters,
        public readonly string $url,
    ) {
    }

    public function parameter(string $name): string
    {
        return $this->parameters[$name] ?? '';
    }
}
