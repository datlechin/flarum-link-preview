<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Tests\unit\Http;

use Datlechin\LinkPreview\Http\Resolver;

final class FakeResolver implements Resolver
{
    public const PUBLIC_ADDRESS = '93.184.216.34';

    public function __construct(private array $addresses = [], private string $default = self::PUBLIC_ADDRESS)
    {
    }

    public function resolve(string $host): array
    {
        return $this->addresses[$host] ?? [$this->default];
    }
}
