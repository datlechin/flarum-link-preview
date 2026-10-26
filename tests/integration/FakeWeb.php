<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Tests\integration;

use Datlechin\LinkPreview\LinkPreviewServiceProvider;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FakeWeb implements ExtenderInterface
{
    public array $requested = [];

    private array $hosts = [];

    private array $pages = [];

    public function host(string $host, string $address): self
    {
        $this->hosts[strtolower($host)] = $address;

        return $this;
    }

    public function page(string $url, string $body, string $contentType = 'text/html; charset=utf-8', int $status = 200): self
    {
        $this->pages[$url] = [
            'body' => $body,
            'info' => ['http_code' => $status, 'response_headers' => ['Content-Type' => $contentType]],
        ];

        return $this;
    }

    public function redirect(string $from, string $to, int $status = 302): self
    {
        $this->pages[$from] = [
            'body' => '',
            'info' => ['http_code' => $status, 'response_headers' => ['Location' => $to], 'redirect_url' => $to],
        ];

        return $this;
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->instance(LinkPreviewServiceProvider::TRANSPORT, new MockHttpClient($this->respond(...)));

        $container->extend(
            LinkPreviewServiceProvider::HTTP_CLIENT,
            fn (HttpClientInterface $client) => $client->withOptions(['resolve' => $this->hosts]),
        );
    }

    private function respond(string $method, string $url, array $options): MockResponse
    {
        $this->requested[] = $url;

        $page = $this->pages[$url] ?? null;

        if ($page === null) {
            return new MockResponse('', ['error' => "Nothing answers at $url."]);
        }

        return new MockResponse($page['body'], $page['info']);
    }
}
