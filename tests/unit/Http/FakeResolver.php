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

/**
 * DNS, decided in the test rather than by the machine running it.
 *
 * The interesting cases are all ones the real resolver cannot be made to
 * produce on demand: a public-looking hostname that answers with a loopback
 * address, a host with no address at all, a redirect into the cloud metadata
 * endpoint. Any host the test did not name gets a routable address, so a test
 * about status codes does not have to describe a network as well.
 */
final class FakeResolver implements Resolver
{
    public const PUBLIC_ADDRESS = '93.184.216.34';

    /**
     * @param  array<string, list<string>>  $addresses
     */
    public function __construct(private array $addresses = [], private string $default = self::PUBLIC_ADDRESS)
    {
    }

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        return $this->addresses[$host] ?? [$this->default];
    }
}
