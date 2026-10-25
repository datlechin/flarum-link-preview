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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class PreviewsAForumInASubdirectoryTest extends TestCase
{
    use PreviewsLinks;
    use RetrievesAuthorizedUsers;

    private const PUBLIC_ADDRESS = '93.184.216.34';

    protected function setUp(): void
    {
        parent::setUp();

        $this->config('url', 'http://localhost/forum');
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
            ],
        ]);
    }

    #[Test]
    public function a_discussion_under_the_base_path_is_answered_from_the_database(): void
    {
        $data = $this->data($this->preview('http://localhost/forum/d/1-ways-to-season-a-cast-iron-pan'));

        $this->assertSame('discussion', $data['type']);
        $this->assertSame('Ways to season a cast iron pan', $data['title']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function the_base_path_itself_is_the_forum(): void
    {
        foreach (['http://localhost/forum', 'http://localhost/forum/'] as $url) {
            $this->assertSame('forum', $this->data($this->preview($url))['type'], $url);
        }

        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_forum_page_without_a_card_is_not_fetched(): void
    {
        $this->assertSame('no_metadata', $this->data($this->preview('http://localhost/forum/settings'))['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    #[DataProvider('neighbours')]
    public function another_path_on_the_same_host_is_fetched(string $url): void
    {
        $this->web->host('localhost', self::PUBLIC_ADDRESS)
            ->page($url, self::article());

        $data = $this->data($this->preview($url));

        $this->assertSame('link', $data['type']);
        $this->assertSame('A perfectly ordinary article', $data['title']);
        $this->assertSame([$url], $this->web->requested);
    }

    public static function neighbours(): array
    {
        return [
            'next to the forum' => ['http://localhost/schools/detail/1'],
            'host root' => ['http://localhost/'],
            'path starting like the base path' => ['http://localhost/forums/d/1'],
        ];
    }

    #[Test]
    public function a_batch_handles_both(): void
    {
        $this->web->host('localhost', self::PUBLIC_ADDRESS)
            ->page('http://localhost/schools/detail/1', self::article());

        $data = $this->data($this->previewBatch([
            'http://localhost/forum/d/1',
            'http://localhost/schools/detail/1',
        ]));

        $this->assertSame('discussion', $data['http://localhost/forum/d/1']['type']);
        $this->assertSame('link', $data['http://localhost/schools/detail/1']['type']);
        $this->assertSame(['http://localhost/schools/detail/1'], $this->web->requested);
    }
}
