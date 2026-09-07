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

/**
 * The forum's own front page, and the addresses on it that get no card.
 *
 * The tags extension is deliberately off here: `/t/{slug}` is an ordinary
 * address on a forum without it, and the table, the relation and the model it
 * would be read through are all missing, so the answer has to be a failure
 * rather than a fatal.
 */
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
        $this->assertSame('forum', $data['type']);
        $this->assertSame('The Cast Iron Club', $data['title']);
        $this->assertSame('Cookware, and the people who scrub it', $data['description']);
        $this->assertSame('http://localhost/assets/favicon-abc.png', $data['favicon']);
        $this->assertNull($data['image']);
        $this->assertSame([], $this->meta($data));
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function the_index_card_names_the_host_rather_than_the_forum(): void
    {
        // The title is already on the card. Repeating it underneath says
        // nothing, where the address the reader is being sent to does.
        $data = $this->data($this->preview('http://localhost/'));

        $this->assertSame('localhost', $data['siteName']);
    }

    #[Test]
    public function an_address_with_no_path_at_all_is_the_index_too(): void
    {
        $data = $this->data($this->preview('http://localhost'));

        $this->assertSame('forum', $data['type']);
        $this->assertSame('The Cast Iron Club', $data['title']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_tag_link_on_a_forum_without_the_tags_extension_is_a_failure(): void
    {
        $response = $this->preview('http://localhost/t/cooking');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no_metadata', $this->data($response)['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_batch_carrying_that_tag_link_answers_the_rest_of_it(): void
    {
        // A fatal here would take the whole page's previews with it, not just
        // the one link, which is what makes the batch worth asserting on.
        $data = $this->data($this->previewBatch([
            'http://localhost/t/cooking',
            'http://localhost/',
        ]));

        $this->assertSame('no_metadata', $data['http://localhost/t/cooking']['error']);
        $this->assertSame('forum', $data['http://localhost/']['type']);
    }

    #[Test]
    public function an_internal_address_matching_none_of_the_routes_is_a_failure_and_is_never_fetched(): void
    {
        foreach (['http://localhost/settings', 'http://localhost/all', 'http://localhost/notifications'] as $url) {
            $this->assertSame('no_metadata', $this->data($this->preview($url))['error'], $url);
        }

        $this->assertSame([], $this->web->requested);
    }
}
