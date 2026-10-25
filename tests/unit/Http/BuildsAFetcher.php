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
use Datlechin\LinkPreview\Http\SafeFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;
use Throwable;

trait BuildsAFetcher
{
    private MockHandler $handler;

    private function fetcher(array $responses, ?Resolver $resolver = null): SafeFetcher
    {
        $this->handler = new MockHandler($responses);

        return new SafeFetcher(
            $resolver ?? new FakeResolver(),
            new Client(['handler' => HandlerStack::create($this->handler)]),
        );
    }

    private function assertResponsesLeft(int $expected): void
    {
        $this->assertCount($expected, $this->handler, 'Queued responses the fetcher should not have asked for.');
    }
}
