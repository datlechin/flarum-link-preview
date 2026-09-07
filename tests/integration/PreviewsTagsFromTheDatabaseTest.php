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
 * Tags, on their own card and on a discussion's.
 *
 * A restricted tag is the case worth having: its name is the thing a card
 * would say out loud, both on `/t/{slug}` and in the list under a discussion's
 * title, so both paths read the tag through `Tag::whereVisibleTo`.
 */
class PreviewsTagsFromTheDatabaseTest extends TestCase
{
    use PreviewsLinks;
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('datlechin-link-preview', 'flarum-tags');
        $this->isolateTheNetwork();
        $this->setting('forum_title', 'The Cast Iron Club');

        $this->prepareDatabase([
            'users' => [$this->normalUser()],
            'tags' => [
                [
                    'id' => 5,
                    'name' => 'Cooking',
                    'slug' => 'cooking',
                    'description' => 'Pans, pots and what goes in them',
                    'position' => 0,
                    'is_primary' => 1,
                    'discussion_count' => 3,
                ],
                [
                    'id' => 6,
                    'name' => 'Bakeware',
                    'slug' => 'bakeware',
                    'description' => 'Trays, tins and the things that stick to them',
                    'position' => 1,
                    'is_primary' => 1,
                    'discussion_count' => 1,
                ],
                [
                    'id' => 7,
                    'name' => 'Staff Only',
                    'slug' => 'staff-only',
                    'description' => 'Where the staff talk about everyone else',
                    'position' => 2,
                    'is_primary' => 1,
                    'is_restricted' => 1,
                    'discussion_count' => 2,
                ],
            ],
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
                    'title' => 'What the staff say about the members',
                    'slug' => 'what-the-staff-say-about-the-members',
                    'created_at' => Carbon::parse('2026-01-01 00:00:00')->toDateTimeString(),
                    'user_id' => 2,
                    'first_post_id' => 2,
                    'comment_count' => 1,
                    'participant_count' => 1,
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
                [
                    'id' => 2,
                    'discussion_id' => 2,
                    'number' => 1,
                    'created_at' => Carbon::parse('2026-01-01 00:00:00')->toDateTimeString(),
                    'user_id' => 2,
                    'type' => 'comment',
                    'content' => '<t><p>Not for the members to read.</p></t>',
                ],
            ],
            'discussion_tag' => [
                ['discussion_id' => 1, 'tag_id' => 5],
                ['discussion_id' => 1, 'tag_id' => 6],
                ['discussion_id' => 2, 'tag_id' => 7],
            ],
        ]);
    }

    #[Test]
    public function a_link_to_a_tag_is_answered_from_the_database(): void
    {
        $response = $this->preview('http://localhost/t/cooking');
        $data = $this->data($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('tag', $data['type']);
        $this->assertSame('Cooking', $data['title']);
        $this->assertSame('Pans, pots and what goes in them', $data['description']);
        $this->assertSame('The Cast Iron Club', $data['siteName']);
        $this->assertNull($data['image']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function the_card_lists_a_discussion_count(): void
    {
        $meta = $this->meta($this->data($this->preview('http://localhost/t/cooking')));

        $this->assertSame([['key' => 'discussions', 'count' => 3]], $meta);
    }

    #[Test]
    public function a_tag_the_reader_cannot_see_is_a_failure_rather_than_a_card(): void
    {
        $response = $this->preview('http://localhost/t/staff-only');
        $data = $this->data($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no_metadata', $data['error']);
        $this->assertArrayNotHasKey('title', $data);
        $this->assertStringNotContainsString('Staff Only', (string) $response->getBody());
        $this->assertStringNotContainsString('about everyone else', (string) $response->getBody());
    }

    #[Test]
    public function a_discussion_card_lists_its_tags_before_anything_else(): void
    {
        $meta = $this->meta($this->data($this->preview('http://localhost/d/1')));

        $this->assertCount(5, $meta);
        $this->assertSame(['key' => 'tag', 'text' => 'Cooking'], $meta[0]);
        $this->assertSame(['key' => 'tag', 'text' => 'Bakeware'], $meta[1]);
        $this->assertSame(['key' => 'author', 'text' => 'normal'], $meta[2]);
        $this->assertSame(['key' => 'replies', 'count' => 3], $meta[3]);
        $this->assertSame('created', $meta[4]['key']);
    }

    #[Test]
    public function a_discussion_inside_a_restricted_tag_is_a_failure_rather_than_a_card(): void
    {
        // Core hides the discussion itself once any of its tags is one the
        // reader may not view, so the tag name never gets as far as the list.
        $response = $this->preview('http://localhost/d/2');

        $this->assertSame('no_metadata', $this->data($response)['error']);
        $this->assertStringNotContainsString('what the staff say', strtolower((string) $response->getBody()));
        $this->assertStringNotContainsString('Staff Only', (string) $response->getBody());
    }

    #[Test]
    public function the_tag_index_is_left_alone(): void
    {
        // The only card this could carry is titled with the forum's own name,
        // which tells a reader less than the address it would replace.
        $data = $this->data($this->preview('http://localhost/tags'));

        $this->assertSame('no_metadata', $data['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_tag_nobody_created_is_a_failure(): void
    {
        $this->assertSame('no_metadata', $this->data($this->preview('http://localhost/t/nothing-here'))['error']);
        $this->assertSame([], $this->web->requested);
    }
}
