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

use Datlechin\LinkPreview\Http\Resolver;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Container\Container;
use Psr\Http\Message\RequestInterface;

final class FakeWeb implements ExtenderInterface, Resolver
{
    public array $requested = [];

    private array $hosts = [];

    private array $pages = [];

    public function host(string $host, string ...$addresses): self
    {
        $this->hosts[strtolower($host)] = array_values($addresses);

        return $this;
    }

    public function page(string $url, string $body, string $contentType = 'text/html; charset=utf-8', int $status = 200): self
    {
        $this->pages[$url] = ['status' => $status, 'headers' => ['Content-Type' => $contentType], 'body' => $body];

        return $this;
    }

    public function redirect(string $from, string $to, int $status = 302): self
    {
        $this->pages[$from] = ['status' => $status, 'headers' => ['Location' => $to], 'body' => ''];

        return $this;
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->instance(Resolver::class, $this);
        $container->instance(ClientInterface::class, new Client(['handler' => HandlerStack::create($this)]));
    }

    public function resolve(string $host): array
    {
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        return $this->hosts[strtolower($host)] ?? [];
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $url = (string) $request->getUri();

        $this->requested[] = $url;

        $page = $this->pages[$url] ?? null;

        if ($page === null) {
            return Create::rejectionFor(new ConnectException("Nothing answers at $url.", $request));
        }

        return Create::promiseFor(new Response(
            $page['status'],
            $page['headers'],
            $page['body'],
        ));
    }
}
