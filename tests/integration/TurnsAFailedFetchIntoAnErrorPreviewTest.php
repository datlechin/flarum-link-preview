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

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A link that cannot be fetched is an answer, not an accident.
 *
 * Every failure path is asked for on both endpoints with the status asserted,
 * because a catch clause naming a class that does not exist silently never
 * matches and a suite that only fetches pages that are there never notices.
 *
 * `unreachable` is DNS failing, a refusal or a timeout; `http_error` is any
 * status that is not 200. Collapsing them tells a reader a working site is
 * down.
 */
class TurnsAFailedFetchIntoAnErrorPreviewTest extends TestCase
{
    use PreviewsLinks;
    use RetrievesAuthorizedUsers;

    private const PUBLIC_ADDRESS = '93.184.216.34';

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
    public function a_host_that_answers_nothing_is_reported_as_unreachable(): void
    {
        // The host resolves and nothing answers on it, which is what a refused
        // connection and a timeout both look like from here.
        $this->web->host('gone.test', self::PUBLIC_ADDRESS);

        $response = $this->preview('https://gone.test/article');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('unreachable', $this->data($response)['error']);
        $this->assertSame(['https://gone.test/article'], $this->web->requested);
    }

    #[Test]
    public function the_batch_endpoint_reports_it_the_same_way(): void
    {
        $this->web->host('gone.test', self::PUBLIC_ADDRESS);

        $response = $this->previewBatch(['https://gone.test/article']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('unreachable', $this->data($response)['https://gone.test/article']['error']);
    }

    #[Test]
    #[DataProvider('statusesThatAreNotAPage')]
    public function a_host_that_answered_with_the_wrong_status_is_not_unreachable(int $status): void
    {
        // A challenge page carries a title, so a fetcher that parses it caches
        // a preview reading "Just a moment...". The host did answer, and a 404
        // on a site that is plainly up reads as the extension being broken.
        $this->web->host('guarded.test', self::PUBLIC_ADDRESS)
            ->page('https://guarded.test/a', '<html><head><title>Just a moment...</title></head></html>', 'text/html', $status);

        $this->assertSame('http_error', $this->data($this->preview('https://guarded.test/a'))['error']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function statusesThatAreNotAPage(): array
    {
        return [
            'unauthorised' => [401],
            'a challenge page' => [403],
            'gone' => [404],
            'rate limited' => [429],
            'server error' => [500],
            'no content' => [204],
        ];
    }

    #[Test]
    public function the_batch_endpoint_tells_the_two_apart_too(): void
    {
        $this->web->host('guarded.test', self::PUBLIC_ADDRESS)
            ->host('gone.test', self::PUBLIC_ADDRESS)
            ->page('https://guarded.test/a', '<html><head><title>Not for you</title></head></html>', 'text/html', 404);

        $data = $this->data($this->previewBatch(['https://guarded.test/a', 'https://gone.test/a']));

        $this->assertSame('http_error', $data['https://guarded.test/a']['error']);
        $this->assertSame('unreachable', $data['https://gone.test/a']['error']);
    }

    #[Test]
    public function something_that_is_not_a_document_is_reported_as_not_previewable(): void
    {
        $this->web->host('files.test', self::PUBLIC_ADDRESS)
            ->page('https://files.test/report.pdf', '%PDF-1.7', 'application/pdf');

        $response = $this->preview('https://files.test/report.pdf');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('not_previewable', $this->data($response)['error']);
    }

    #[Test]
    public function a_page_with_nothing_on_it_is_reported_as_having_no_metadata(): void
    {
        $this->web->host('bare.test', self::PUBLIC_ADDRESS)
            ->page('https://bare.test/a', '<html><head></head><body>Words.</body></html>');

        $this->assertSame('no_metadata', $this->data($this->preview('https://bare.test/a'))['error']);
    }

    #[Test]
    public function an_address_inside_the_network_answers_rather_than_throwing(): void
    {
        // Raised before the socket rather than by the fetch, so this is the
        // path most easily left uncaught. A reader who pasted a link to a
        // router still gets a card.
        $response = $this->preview('http://192.168.1.1/setup');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('unsafe_address', $this->data($response)['error']);
        $this->assertStringNotContainsString('Exception', (string) $response->getBody());
    }

    #[Test]
    public function an_address_inside_the_network_answers_in_a_batch_too(): void
    {
        $response = $this->previewBatch(['http://192.168.1.1/setup']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('unsafe_address', $this->data($response)['http://192.168.1.1/setup']['error']);
    }

    #[Test]
    public function one_failure_does_not_cost_the_rest_of_the_batch_its_answer(): void
    {
        $this->web->host('example.test', self::PUBLIC_ADDRESS)
            ->host('gone.test', self::PUBLIC_ADDRESS)
            ->host('files.test', self::PUBLIC_ADDRESS)
            ->page('https://example.test/one', self::article())
            ->page('https://files.test/report.pdf', '%PDF-1.7', 'application/pdf');

        $urls = [
            'https://example.test/one',
            'https://gone.test/two',
            'https://files.test/report.pdf',
            'http://10.0.0.5/three',
        ];

        $response = $this->previewBatch($urls);
        $data = $this->data($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEqualsCanonicalizing($urls, array_keys($data));
        $this->assertSame('A perfectly ordinary article', $data['https://example.test/one']['title']);
        $this->assertSame('unreachable', $data['https://gone.test/two']['error']);
        $this->assertSame('not_previewable', $data['https://files.test/report.pdf']['error']);
        $this->assertSame('unsafe_address', $data['http://10.0.0.5/three']['error']);
    }
}
