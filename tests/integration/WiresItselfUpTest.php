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

use Datlechin\LinkPreview\Api\Controller\ShowLinkPreview;
use Datlechin\LinkPreview\Api\Controller\ShowLinkPreviewBatch;
use Datlechin\LinkPreview\Http\HeadFetcher;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WiresItselfUpTest extends TestCase
{
    use PreviewsLinks;
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('datlechin-link-preview');

        $this->prepareDatabase([
            'users' => [$this->normalUser()],
        ]);
    }

    #[Test]
    public function the_container_can_build_the_fetcher(): void
    {
        $this->assertInstanceOf(HeadFetcher::class, $this->app()->getContainer()->make(HeadFetcher::class));
    }

    #[Test]
    public function the_fetcher_it_builds_refuses_private_addresses(): void
    {
        $this->assertSame('unsafe_address', $this->data($this->preview('http://127.0.0.1/'))['error']);
        $this->assertSame('unsafe_address', $this->data($this->preview('http://169.254.169.254/latest/meta-data/'))['error']);
        $this->assertSame('unsafe_address', $this->data($this->preview('http://192.0.0.192/'))['error']);
    }

    #[Test]
    public function the_container_can_build_both_controllers(): void
    {
        $container = $this->app()->getContainer();

        $this->assertInstanceOf(ShowLinkPreview::class, $container->make(ShowLinkPreview::class));
        $this->assertInstanceOf(ShowLinkPreviewBatch::class, $container->make(ShowLinkPreviewBatch::class));
    }

    #[Test]
    public function the_endpoint_answers_with_no_help_from_the_test(): void
    {
        $response = $this->preview('not a url');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('invalid_url', $this->data($response)['error']);
    }

    #[Test]
    public function the_batch_endpoint_answers_too(): void
    {
        $response = $this->previewBatch(['not a url']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('invalid_url', $this->data($response)['not a url']['error']);
    }
}
