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
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * The line between a request that is wrong and an address that cannot be
 * previewed.
 *
 * A caller that sends no URL has made a mistake and gets a 400. An address
 * that turns out to be unreachable, blocked or empty has not: that is an
 * ordinary answer with an error code in it, cacheable and renderable, and the
 * frontend depends on the two never being confused. Both endpoints take a JSON
 * body over POST and neither answers a GET, so neither can be triggered from
 * somebody else's page.
 */
class RefusesMalformedRequestsTest extends TestCase
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
    public function a_request_carrying_no_url_at_all_is_refused(): void
    {
        $response = $this->post(['json' => []]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_request_carrying_no_body_at_all_is_refused(): void
    {
        $response = $this->post();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_url_that_is_not_a_string_is_refused(): void
    {
        $response = $this->post(['json' => ['url' => ['https://example.test/']]]);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function the_endpoint_cannot_be_reached_with_a_get(): void
    {
        // An `<img src>` pointing here would make any visitor's browser ask
        // this forum to open a connection, with no preflight in the way.
        $response = $this->send(
            $this->request('GET', '/api/datlechin-link-preview')
                ->withQueryParams(['url' => 'https://example.test/'])
        );

        $this->assertContains($response->getStatusCode(), [404, 405]);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function the_batch_endpoint_cannot_be_reached_with_a_get_either(): void
    {
        $response = $this->send(
            $this->request('GET', '/api/datlechin-link-preview/batch')
                ->withQueryParams(['urls' => ['https://example.test/']])
        );

        $this->assertContains($response->getStatusCode(), [404, 405]);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_batch_carrying_no_urls_is_refused(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/datlechin-link-preview/batch', ['json' => []])
                ->withAttribute('bypassCsrfToken', true)
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function a_batch_whose_urls_are_not_a_list_is_refused(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/datlechin-link-preview/batch', ['json' => ['urls' => 'https://example.test/']])
                ->withAttribute('bypassCsrfToken', true)
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function an_empty_batch_is_refused(): void
    {
        $this->assertSame(400, $this->previewBatch([])->getStatusCode());
    }

    #[Test]
    public function an_address_that_cannot_be_previewed_is_not_a_bad_request(): void
    {
        $response = $this->preview('not a url');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('invalid_url', $this->data($response)['error']);
    }

    /**
     * The single endpoint asked with a body of the test's own choosing, since
     * every case here is about a body {@see PreviewsLinks::preview()} would
     * never build.
     *
     * @param  array<string, mixed>  $options
     */
    private function post(array $options = []): ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/datlechin-link-preview', $options)
                ->withAttribute('bypassCsrfToken', true)
        );
    }
}
