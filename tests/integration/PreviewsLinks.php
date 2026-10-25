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

trait PreviewsLinks
{
    protected FakeWeb $web;

    protected function isolateTheNetwork(): void
    {
        $this->extend($this->web = new FakeWeb());
    }

    protected function preview(string $url, array $options = []): ResponseInterface
    {
        $options['json'] = ['url' => $url];

        return $this->send(
            $this->request('POST', '/api/datlechin-link-preview', $options)
                ->withAttribute('bypassCsrfToken', true)
        );
    }

    protected function previewBatch(array $urls, array $options = []): ResponseInterface
    {
        $options['json'] = ['urls' => $urls];

        return $this->send(
            $this->request('POST', '/api/datlechin-link-preview/batch', $options)
                ->withAttribute('bypassCsrfToken', true)
        );
    }

    protected function data(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    protected function meta(array $data): array
    {
        $meta = $data['meta'] ?? [];

        return is_array($meta) ? array_values($meta) : [];
    }

    protected function assertSameInstant(string $expected, mixed $actual, string $message = ''): void
    {
        $this->assertIsString($actual, $message);
        $this->assertSame(
            Carbon::parse($expected)->toAtomString(),
            Carbon::parse($actual)->toAtomString(),
            $message
        );
    }

    protected function revokePermission(string $permission): void
    {
        $this->database()->table('group_permission')->where('permission', $permission)->delete();
    }

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
