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

class PreviewsTheForumItselfTest extends TestCase
{
    use PreviewsLinks;
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('datlechin-link-preview');
        $this->isolateTheNetwork();
        $this->setting('forum_title', 'The Cast Iron Club');
        $this->setting('forum_description', 'Cookware, and the people who scrub it');
        $this->setting('favicon_path', 'favicon-abc.png');

        $this->prepareDatabase([
            'users' => [$this->normalUser()],
        ]);
    }

    #[Test]
    public function a_link_to_the_index_is_a_card_about_the_forum(): void
    {
        $response = $this->preview('http://localhost/');
        $data = $this->data($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('default', $data['type']);
        $this->assertSame('The Cast Iron Club', $data['title']);
        $this->assertSame('Cookware, and the people who scrub it', $data['description']);
        $this->assertSame('http://localhost/assets/favicon-abc.png', $data['favicon']);
        $this->assertNull($data['image']);
        $this->assertSame([], $this->meta($data));
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function the_index_card_names_the_forum_like_every_other_internal_card(): void
    {
        $data = $this->data($this->preview('http://localhost/'));

        $this->assertSame('The Cast Iron Club', $data['siteName']);
    }

    #[Test]
    public function an_address_with_no_path_at_all_is_the_index_too(): void
    {
        $data = $this->data($this->preview('http://localhost'));

        $this->assertSame('default', $data['type']);
        $this->assertSame('The Cast Iron Club', $data['title']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_tag_link_on_a_forum_without_the_tags_extension_is_not_previewable(): void
    {
        $response = $this->preview('http://localhost/t/cooking');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('not_previewable', $this->data($response)['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_batch_carrying_that_tag_link_answers_the_rest_of_it(): void
    {
        $data = $this->data($this->previewBatch([
            'http://localhost/t/cooking',
            'http://localhost/',
        ]));

        $this->assertSame('not_previewable', $data['http://localhost/t/cooking']['error']);
        $this->assertSame('default', $data['http://localhost/']['type']);
    }

    #[Test]
    public function a_page_no_card_is_registered_for_is_not_previewable_and_is_never_fetched(): void
    {
        foreach (['http://localhost/settings', 'http://localhost/all', 'http://localhost/notifications'] as $url) {
            $this->assertSame('not_previewable', $this->data($this->preview($url))['error'], $url);
        }

        $this->assertSame([], $this->web->requested);
    }
}
