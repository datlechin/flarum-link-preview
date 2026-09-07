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

/**
 * The only internet these tests have.
 *
 * DNS and HTTP are both here because the cases worth testing are the ones
 * where they disagree: a public-looking name answering with a LAN address
 * cannot be arranged against a real resolver.
 *
 * {@see $requested} is the proof of a negative, so a test asserting nothing
 * left the server can check it rather than trusting CI to have no network.
 */
final class FakeWeb implements ExtenderInterface, Resolver
{
    /**
     * Every URL the forum actually opened a request for, in order.
     *
     * @var list<string>
     */
    public array $requested = [];

    /**
     * @var array<string, list<string>>
     */
    private array $hosts = [];

    /**
     * @var array<string, array{status: int, headers: array<string, string>, body: string}>
     */
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

    /**
     * A hop, so a test can put the interesting address one move away from the
     * one the reader pasted. A redirect is the only way a stranger gets to
     * choose the second address, so every rule has to survive one.
     */
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

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $literal = trim($host, '[]');

        // Matches SystemResolver: a URL naming an address has nothing to look
        // up, and a test that writes one means the address it wrote.
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        return $this->hosts[strtolower($host)] ?? [];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $url = (string) $request->getUri();

        $this->requested[] = $url;

        $page = $this->pages[$url] ?? null;

        if ($page === null) {
            return Create::rejectionFor(new ConnectException("Nothing answers at $url.", $request));
        }

        // A fresh response every time: SafeFetcher reads the body as a stream
        // and a batch can ask for one page twice, so a shared object would
        // leave the second reader at end of file with an empty document.
        return Create::promiseFor(new Response(
            $page['status'],
            $page['headers'],
            $page['body'],
        ));
    }
}
