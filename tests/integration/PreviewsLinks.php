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

use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface;

/**
 * What every preview test needs: a forum with no way out to the internet, and
 * two one-line ways to ask it for a card.
 */
trait PreviewsLinks
{
    protected FakeWeb $web;

    /**
     * Must run from setUp, before the first request boots the application:
     * an extender registered after boot is never applied, and the tests would
     * then quietly reach the real network.
     */
    protected function isolateTheNetwork(): void
    {
        $this->extend($this->web = new FakeWeb());
    }

    /**
     * A POST carrying JSON, not a GET carrying a query string.
     *
     * A GET is reachable from any other site: an `<img src>` on a page a
     * member visits would turn their browser into an instruction for this
     * forum to open a connection. A JSON body needs a preflight the forum
     * does not answer cross site.
     *
     * @param  array<string, mixed>  $options
     */
    protected function preview(string $url, array $options = []): ResponseInterface
    {
        $options['json'] = ['url' => $url];

        return $this->send(
            $this->request('POST', '/api/datlechin-link-preview', $options)
                ->withAttribute('bypassCsrfToken', true)
        );
    }

    /**
     * @param  list<string>  $urls
     * @param  array<string, mixed>  $options
     */
    protected function previewBatch(array $urls, array $options = []): ResponseInterface
    {
        $options['json'] = ['urls' => $urls];

        return $this->send(
            $this->request('POST', '/api/datlechin-link-preview/batch', $options)
                ->withAttribute('bypassCsrfToken', true)
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * The facts listed under a card's title.
     *
     * Absent and empty are the same answer: the field is optional, and a card
     * with nothing to list is free to leave it out or send it empty.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    protected function meta(array $data): array
    {
        $meta = $data['meta'] ?? [];

        return is_array($meta) ? array_values($meta) : [];
    }

    /**
     * Asserts a date meta item names the given moment, whichever ISO 8601
     * spelling of it the server chose.
     */
    protected function assertSameInstant(string $expected, mixed $actual, string $message = ''): void
    {
        $this->assertIsString($actual, $message);
        $this->assertSame(
            Carbon::parse($expected)->toAtomString(),
            Carbon::parse($actual)->toAtomString(),
            $message
        );
    }

    /**
     * Takes a permission away from every group that was granted it, which is
     * how a forum hides what a guest or a member may not see.
     *
     * Runs before anything reads a permission, since the first read fills a
     * cache that a later delete leaves standing.
     */
    protected function revokePermission(string $permission): void
    {
        $this->database()->table('group_permission')->where('permission', $permission)->delete();
    }

    /**
     * A page with everything a card can be built from, so a test that gets no
     * preview knows the fixture was not the reason.
     */
    protected static function article(): string
    {
        return <<<'HTML'
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>Ignored in favour of the Open Graph title</title>
                <meta property="og:title" content="A perfectly ordinary article">
                <meta property="og:description" content="Something a person wrote down.">
                <meta property="og:site_name" content="Example">
                <meta property="og:image" content="/card.png">
                <meta property="og:image:width" content="1200">
                <meta property="og:image:height" content="630">
                <link rel="icon" href="/favicon.ico">
            </head>
            <body>The rest of the page, which is never read.</body>
            </html>
            HTML;
    }
}
