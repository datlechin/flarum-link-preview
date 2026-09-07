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

use Datlechin\LinkPreview\Settings\Config;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * One request for a whole page of links.
 *
 * The client holds its cards in a map keyed by the href it read out of the
 * post, so the answer has to come back under the same strings it asked with.
 * Anything the server does to an address on the way through, lowercasing a
 * host or dropping a default port, has to stay on the server.
 */
class BatchesPreviewsTest extends TestCase
{
    use PreviewsLinks;
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('datlechin-link-preview');
        $this->isolateTheNetwork();

        $this->prepareDatabase([
            'users' => [$this->normalUser()],
        ]);
    }

    #[Test]
    public function the_answer_is_keyed_by_the_urls_the_client_sent(): void
    {
        $this->web->host('example.test', '93.184.216.34')
            ->page('https://example.test/one', self::article());

        // The second entry is the same page after canonicalisation, so a server
        // that answered under its own idea of the address would collapse the
        // two and leave one card on the page waiting forever.
        $urls = [
            'https://example.test/one',
            'https://EXAMPLE.test/one',
            'not a url',
        ];

        $response = $this->previewBatch($urls);
        $data = $this->data($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEqualsCanonicalizing($urls, array_keys($data));
        $this->assertSame('A perfectly ordinary article', $data['https://example.test/one']['title']);
        $this->assertSame('A perfectly ordinary article', $data['https://EXAMPLE.test/one']['title']);
        $this->assertSame('invalid_url', $data['not a url']['error']);
    }

    #[Test]
    public function urls_past_the_cap_are_dropped_rather_than_refused(): void
    {
        // A post with fifty links is a reason to preview less of it, not a
        // reason to show the reader an error where the cards should be.
        $urls = array_map(fn (int $n): string => "nonsense-$n", range(1, Config::MAX_BATCH_SIZE + 5));

        $response = $this->previewBatch($urls);
        $data = $this->data($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(Config::MAX_BATCH_SIZE, $data);
        $this->assertArrayHasKey('nonsense-1', $data);
        $this->assertArrayHasKey('nonsense-'.Config::MAX_BATCH_SIZE, $data);
        $this->assertArrayNotHasKey('nonsense-'.(Config::MAX_BATCH_SIZE + 1), $data);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function one_url_asked_for_twice_costs_one_request(): void
    {
        $this->setting('datlechin-link-preview.cache_time', 0);

        $this->web->host('example.test', '93.184.216.34')
            ->page('https://example.test/one', self::article());

        $data = $this->data($this->previewBatch([
            'https://example.test/one',
            'https://example.test/one',
        ]));

        $this->assertCount(1, $data);
        $this->assertSame(['https://example.test/one'], $this->web->requested);
    }
}
