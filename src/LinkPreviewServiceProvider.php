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

use Datlechin\LinkPreview\Http\HeadFetcher;
use Datlechin\LinkPreview\Internal\RouteCards;
use Datlechin\LinkPreview\Preview\InternalPreviewer;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Http\RouteCollection;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LinkPreviewServiceProvider extends AbstractServiceProvider
{
    public const TRANSPORT = 'datlechin-link-preview.transport';

    public const HTTP_CLIENT = 'datlechin-link-preview.http_client';

    public const BLOCKED_SUBNETS = [
        ...IpUtils::PRIVATE_SUBNETS,
        '192.0.0.0/24',
        'fec0::/10',
        '224.0.0.0/4',
        'ff00::/8',
    ];

    public function register(): void
    {
        $this->container->bind(self::TRANSPORT, fn () => HttpClient::create());

        $this->container->bind(self::HTTP_CLIENT, fn (Container $container) => new NoPrivateNetworkHttpClient(
            $container->make(self::TRANSPORT),
            self::BLOCKED_SUBNETS,
        ));

        $this->container->when(HeadFetcher::class)
            ->needs(HttpClientInterface::class)
            ->give(self::HTTP_CLIENT);

        $this->container->singleton(RouteCards::class);

        $this->container->when(InternalPreviewer::class)
            ->needs(RouteCollection::class)
            ->give(fn (Container $container) => $container->make('flarum.forum.routes'));
    }
}
