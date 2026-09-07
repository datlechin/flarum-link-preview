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
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * An address is not always what the link that led to it says.
 *
 * datlechin/flarum-link-clicks rewrites a tracked link's `href` to this
 * forum's own `/lcc/track?u=` route, signed over a row id, so the destination
 * cannot be read out of the address and every link in a post looks internal.
 * The browser recovers it from the link's text and asks for that instead, so
 * the two stay distinct: the destination is fetched, the tracker route is this
 * forum and never is.
 */
class PreviewsWhereTheLinkReallyGoesTest extends TestCase
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
            Discussion::class => [
                [
                    'id' => 1,
                    'title' => 'Ways to season a cast iron pan',
                    'slug' => 'ways-to-season-a-cast-iron-pan',
                    'created_at' => Carbon::parse('2026-01-01 00:00:00')->toDateTimeString(),
                    'user_id' => 2,
                    'first_post_id' => 1,
                    'comment_count' => 4,
                    'participant_count' => 3,
                    'is_private' => 0,
                ],
            ],
            Post::class => [
                [
                    'id' => 1,
                    'discussion_id' => 1,
                    'number' => 1,
                    'created_at' => Carbon::parse('2026-01-01 00:00:00')->toDateTimeString(),
                    'user_id' => 2,
                    'type' => 'comment',
                    'content' => '<t><p>Rub it with oil and bake it upside down.</p></t>',
                ],
            ],
        ]);
    }

    #[Test]
    public function a_destination_no_href_ever_named_is_previewed_at_that_destination(): void
    {
        // What the browser sends for a tracked link: the address out of the
        // link's text, not the `/lcc/track?u=` route its `href` carries.
        $this->web->host('good.test', self::PUBLIC_ADDRESS)
            ->page('https://good.test/article', self::article());

        $data = $this->data($this->preview('https://good.test/article'));

        $this->assertSame('link', $data['type']);
        $this->assertSame('A perfectly ordinary article', $data['title']);
        $this->assertSame('https://good.test/article', $data['url']);
        $this->assertSame(['https://good.test/article'], $this->web->requested);
    }

    #[Test]
    public function the_tracking_route_is_this_forum_and_is_never_fetched(): void
    {
        // One of this forum's own addresses. Fetching it would have the forum
        // wait on a request the same server has to serve, and would count a
        // click nobody made.
        $this->web->host('good.test', self::PUBLIC_ADDRESS)
            ->page('https://good.test/article', self::article());

        $this->assertSame('no_metadata', $this->data($this->preview('http://localhost/lcc/track?u=9f2c1b'))['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function an_address_on_this_forum_is_answered_about_this_forum_whatever_it_carries(): void
    {
        // The abuse case: `[https://evil.test](https://localhost/d/1)` would
        // draw a card for evil.test out of this forum's own link. Core marks a
        // genuinely internal link `UrlLink--internal` so the browser never
        // asks, but should the request arrive the address decides alone.
        $this->web->host('evil.test', self::PUBLIC_ADDRESS)
            ->page('https://evil.test/', self::article());

        $data = $this->data($this->preview('http://localhost/d/1?u=https%3A%2F%2Fevil.test%2F'));

        $this->assertSame('discussion', $data['type']);
        $this->assertSame('Ways to season a cast iron pan', $data['title']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_batch_of_both_is_answered_the_same_way(): void
    {
        // A post carrying tracked links sends one batch, and the tracker route
        // is in it whenever the browser could not recover a destination.
        $this->web->host('good.test', self::PUBLIC_ADDRESS)
            ->page('https://good.test/article', self::article());

        $data = $this->data($this->previewBatch([
            'https://good.test/article',
            'http://localhost/lcc/track?u=9f2c1b',
        ]));

        $this->assertSame('A perfectly ordinary article', $data['https://good.test/article']['title']);
        $this->assertSame('no_metadata', $data['http://localhost/lcc/track?u=9f2c1b']['error']);
        $this->assertSame(['https://good.test/article'], $this->web->requested);
    }
}
