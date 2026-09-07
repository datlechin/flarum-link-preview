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
 * Links back to this forum, answered without leaving it.
 *
 * A forum fetching its own pages waits on a request the same server has to
 * serve, which on a single worker never finishes, and the fetch would carry no
 * session, so a guest would be handed a preview of a discussion the guest is
 * not allowed to read. Both of those are covered here.
 */
class PreviewsDiscussionsFromTheDatabaseTest extends TestCase
{
    use PreviewsLinks;
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('datlechin-link-preview');
        $this->isolateTheNetwork();
        $this->setting('forum_title', 'The Cast Iron Club');

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
                [
                    'id' => 2,
                    'title' => 'Somewhere nobody else is invited',
                    'slug' => 'somewhere-nobody-else-is-invited',
                    'created_at' => Carbon::parse('2026-01-01 00:00:00')->toDateTimeString(),
                    'user_id' => 1,
                    'first_post_id' => 2,
                    'comment_count' => 1,
                    'participant_count' => 1,
                    'is_private' => 1,
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
                [
                    'id' => 2,
                    'discussion_id' => 2,
                    'number' => 1,
                    'created_at' => Carbon::parse('2026-01-01 00:00:00')->toDateTimeString(),
                    'user_id' => 1,
                    'type' => 'comment',
                    'content' => '<t><p>Only for the people already here.</p></t>',
                ],
            ],
        ]);
    }

    #[Test]
    public function a_link_to_a_discussion_is_answered_from_the_database(): void
    {
        $response = $this->preview('http://localhost/d/1-ways-to-season-a-cast-iron-pan');
        $data = $this->data($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('discussion', $data['type']);
        $this->assertSame('compact', $data['layout']);
        $this->assertSame('Ways to season a cast iron pan', $data['title']);
        $this->assertStringContainsString('Rub it with oil', (string) $data['description']);
        $this->assertSame('The Cast Iron Club', $data['siteName']);
        $this->assertNull($data['image']);
    }

    #[Test]
    public function what_the_card_lists_arrives_as_meta(): void
    {
        // One list for every internal type, so the browser renders all of them
        // through one component rather than one per kind of page.
        $meta = $this->meta($this->data($this->preview('http://localhost/d/1')));

        $this->assertCount(3, $meta, 'no tags here: the tags extension is not enabled');
        $this->assertSame(['key' => 'author', 'text' => 'normal'], $meta[0]);
        $this->assertSame(['key' => 'replies', 'count' => 3], $meta[1], 'four comments is three replies');
        $this->assertSame('created', $meta[2]['key']);
        $this->assertSameInstant('2026-01-01 00:00:00', $meta[2]['date']);
    }

    #[Test]
    public function the_discussion_object_the_card_used_to_carry_is_gone(): void
    {
        $data = $this->data($this->preview('http://localhost/d/1'));

        $this->assertArrayNotHasKey('discussion', $data);
    }

    #[Test]
    public function the_position_at_the_end_of_the_address_is_ignored(): void
    {
        $data = $this->data($this->preview('http://localhost/d/1-ways-to-season-a-cast-iron-pan/3'));

        $this->assertSame('discussion', $data['type']);
        $this->assertSame('Ways to season a cast iron pan', $data['title']);
    }

    #[Test]
    public function a_discussion_the_reader_cannot_see_has_no_metadata(): void
    {
        // Asked for without the slug, so a title leaking into the answer would
        // be the server's doing rather than something the request already said.
        $response = $this->preview('http://localhost/d/2');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no_metadata', $this->data($response)['error']);
        $this->assertStringNotContainsString('nobody else is invited', (string) $response->getBody());
    }

    #[Test]
    public function a_member_who_is_not_in_it_cannot_see_it_either(): void
    {
        $response = $this->preview('http://localhost/d/2', ['authenticatedAs' => 2]);

        $this->assertSame('no_metadata', $this->data($response)['error']);
    }

    #[Test]
    public function nothing_leaves_the_server_for_a_link_to_this_forum(): void
    {
        // No host in the fake network resolves and no page answers it, so a
        // fetch would have come back unsafe_address instead of a discussion.
        $data = $this->data($this->preview('http://localhost/d/1'));

        $this->assertSame('discussion', $data['type']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_discussion_address_carrying_something_that_is_not_an_id_has_no_metadata(): void
    {
        // Never fetched either: the forum answers for its own addresses or it
        // answers with nothing.
        $this->assertSame('no_metadata', $this->data($this->preview('http://localhost/d/twelve'))['error']);
        $this->assertSame('no_metadata', $this->data($this->preview('http://localhost/d/'))['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function an_internal_address_is_refused_outright_when_the_setting_is_off(): void
    {
        // The answer for a page still running an older bundle, which asks
        // instead of skipping. Still a refusal rather than a fetch: a forum
        // reading its own pages is the one request that can wait on itself.
        $this->setting('datlechin-link-preview.preview_internal_links', 0);

        $response = $this->preview('http://localhost/d/1-ways-to-season-a-cast-iron-pan');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('not_previewable', $this->data($response)['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function nothing_leaves_the_server_for_an_internal_address_with_the_setting_off_either(): void
    {
        $this->setting('datlechin-link-preview.preview_internal_links', 0);

        $data = $this->data($this->previewBatch([
            'http://localhost/d/1',
            'http://localhost/u/normal',
            'http://localhost/',
        ]));

        $this->assertSame('not_previewable', $data['http://localhost/d/1']['error']);
        $this->assertSame('not_previewable', $data['http://localhost/u/normal']['error']);
        $this->assertSame('not_previewable', $data['http://localhost/']['error']);
        $this->assertSame([], $this->web->requested);
    }
}
