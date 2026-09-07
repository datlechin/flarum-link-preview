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

use Datlechin\LinkPreview\Api\LinkPreviewThrottler;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * The ceiling on how often one reader may ask the forum to fetch remote pages.
 *
 * Reading costs one batch request per page view, so the limit is far above
 * anything a person produces. Reaching it means asking for addresses nobody has
 * asked for before, which is what using a forum as a port scanner looks like.
 *
 * A throttler is handed every API request, so one that answers for routes it
 * knows nothing about would either throttle the whole forum or, by returning
 * false, exempt it from everybody else's limits.
 */
class ThrottlesPreviewRequestsTest extends TestCase
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
    public function a_reader_asking_far_more_often_than_reading_needs_is_turned_away(): void
    {
        for ($i = 1; $i <= LinkPreviewThrottler::MAX_REQUESTS_PER_MINUTE; $i++) {
            $this->assertSame(200, $this->exhaust()->getStatusCode(), "request $i");
        }

        $this->assertSame(429, $this->exhaust()->getStatusCode());
    }

    #[Test]
    public function the_limit_stops_at_this_extension(): void
    {
        for ($i = 0; $i <= LinkPreviewThrottler::MAX_REQUESTS_PER_MINUTE; $i++) {
            $this->exhaust();
        }

        $this->assertSame(429, $this->exhaust()->getStatusCode(), 'the preview endpoint is still shut');
        $this->assertSame(200, $this->send($this->request('GET', '/api/'))->getStatusCode());
    }

    /**
     * One request against the budget, costing nothing but the count: an address
     * that is rejected before the filters, let alone the network.
     */
    private function exhaust(): ResponseInterface
    {
        return $this->preview('nonsense');
    }
}
