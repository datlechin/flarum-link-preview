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

use Datlechin\LinkPreview\Http\HeadFetcher;
use Datlechin\LinkPreview\LinkPreviewServiceProvider;
use LogicException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

trait BuildsAFetcher
{
    private array $requests = [];

    private function fetcher(array $responses, array $addresses = []): HeadFetcher
    {
        $this->requests = [];

        $transport = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['url' => $url, 'options' => $options];

            return array_shift($responses) ?? throw new LogicException("No response queued for $url.");
        });

        $client = new NoPrivateNetworkHttpClient($transport, LinkPreviewServiceProvider::BLOCKED_SUBNETS);

        return new HeadFetcher($client->withOptions(['resolve' => $addresses + self::publicHosts()]));
    }

    private function assertRequestsMade(int $expected): void
    {
        $this->assertCount($expected, $this->requests);
    }

    private static function html(string|iterable $body, array $info = []): MockResponse
    {
        return new MockResponse($body, $info + ['response_headers' => ['Content-Type' => 'text/html']]);
    }

    private static function redirect(string $to, int $status = 302): MockResponse
    {
        return new MockResponse('', [
            'http_code' => $status,
            'response_headers' => ['Location' => $to],
            'redirect_url' => $to,
        ]);
    }

    private static function publicHosts(): array
    {
        $hosts = ['example.com', 'elsewhere.test', 'public.test', 'one.test', 'two.test', 'three.test'];

        return array_fill_keys($hosts, '93.184.216.34');
    }
}
