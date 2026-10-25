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

class PreviewsProfilesFromTheDatabaseTest extends TestCase
{
    use PreviewsLinks;
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('datlechin-link-preview');
        $this->isolateTheNetwork();
        $this->setting('forum_title', 'The Cast Iron Club');
        $this->setting('favicon_path', 'favicon-abc.png');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser() + [
                    'joined_at' => '2025-03-04 05:06:07',
                    'comment_count' => 7,
                ],
                [
                    'id' => 3,
                    'username' => 'photogenic',
                    'password' => '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim',
                    'email' => 'photogenic@machine.local',
                    'is_email_confirmed' => 1,
                    'avatar_url' => 'https://cdn.test/photogenic.png',
                    'joined_at' => '2025-06-07 08:09:10',
                    'comment_count' => 1,
                ],
            ],
            'group_user' => [['user_id' => 2, 'group_id' => 3]],
        ]);
    }

    #[Test]
    public function a_link_to_a_profile_is_answered_from_the_database(): void
    {
        $response = $this->preview('http://localhost/u/normal');
        $data = $this->data($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('user', $data['type']);
        $this->assertSame('normal', $data['title'], 'the display name, which here is the username');
        $this->assertSame('The Cast Iron Club', $data['siteName']);
        $this->assertSame('http://localhost/assets/favicon-abc.png', $data['favicon']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function the_card_lists_a_post_count_and_a_join_date(): void
    {
        $meta = $this->meta($this->data($this->preview('http://localhost/u/normal')));

        $this->assertCount(2, $meta);
        $this->assertSame(['type' => 'count', 'key' => 'datlechin-link-preview.forum.meta.posts', 'count' => 7], $meta[0]);
        $this->assertSame('date', $meta[1]['type']);
        $this->assertSameInstant('2025-03-04 05:06:07', $meta[1]['date']);
    }

    #[Test]
    public function a_member_with_an_avatar_gets_it_as_the_card_image(): void
    {
        $data = $this->data($this->preview('http://localhost/u/photogenic'));

        $this->assertSame('https://cdn.test/photogenic.png', $data['image']['url']);
    }

    #[Test]
    public function a_member_with_no_avatar_gets_no_image(): void
    {
        $this->assertNull($this->data($this->preview('http://localhost/u/normal'))['image']);
    }

    #[Test]
    public function a_member_the_reader_cannot_see_is_a_failure_rather_than_a_card(): void
    {
        $this->revokePermission('viewForum');

        $data = $this->data($this->preview('http://localhost/u/photogenic', ['authenticatedAs' => 2]));

        $this->assertSame('no_metadata', $data['error']);

        $this->assertArrayNotHasKey('title', $data);
        $this->assertArrayNotHasKey('description', $data);
        $this->assertArrayNotHasKey('image', $data);
        $this->assertArrayNotHasKey('meta', $data);
    }

    #[Test]
    public function that_same_member_still_gets_their_own_profile(): void
    {
        $this->revokePermission('viewForum');

        $data = $this->data($this->preview('http://localhost/u/normal', ['authenticatedAs' => 2]));

        $this->assertSame('user', $data['type']);
        $this->assertSame('normal', $data['title']);
    }

    #[Test]
    public function a_guest_who_may_not_read_the_forum_sees_no_profile_at_all(): void
    {
        $this->revokePermission('viewForum');

        $response = $this->preview('http://localhost/u/photogenic');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no_metadata', $this->data($response)['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_profile_that_belongs_to_nobody_is_a_failure(): void
    {
        $this->assertSame('no_metadata', $this->data($this->preview('http://localhost/u/nobody'))['error']);
        $this->assertSame([], $this->web->requested);
    }
}
