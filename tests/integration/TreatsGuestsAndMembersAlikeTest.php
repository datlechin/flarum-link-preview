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

class TreatsGuestsAndMembersAlikeTest extends TestCase
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
    public function a_guest_gets_exactly_what_a_member_gets(): void
    {
        $this->setting('datlechin-link-preview.cache_time', 0);

        $this->web->host('example.test', '93.184.216.34')
            ->page('https://example.test/article', self::article());

        $guest = $this->preview('https://example.test/article');
        $member = $this->preview('https://example.test/article', ['authenticatedAs' => 2]);

        $this->assertSame(200, $guest->getStatusCode());
        $this->assertSame(200, $member->getStatusCode());
        $this->assertSame((string) $guest->getBody(), (string) $member->getBody());
        $this->assertCount(2, $this->web->requested);

        $data = $this->data($guest);

        $this->assertSame('link', $data['type']);
        $this->assertSame('A perfectly ordinary article', $data['title']);
        $this->assertSame('Something a person wrote down.', $data['description']);
        $this->assertSame('Example', $data['siteName']);
        $this->assertSame('https://example.test/favicon.ico', $data['favicon']);
        $this->assertSame('https://example.test/card.png', $data['image']['url']);
        $this->assertSame('large', $data['layout']);
    }

    #[Test]
    public function the_batch_endpoint_is_open_to_a_guest_too(): void
    {
        $this->web->host('example.test', '93.184.216.34')
            ->page('https://example.test/article', self::article());

        $response = $this->previewBatch(['https://example.test/article']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'A perfectly ordinary article',
            $this->data($response)['https://example.test/article']['title']
        );
    }
}
