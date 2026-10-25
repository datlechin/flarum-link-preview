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

use Datlechin\LinkPreview\Extend\LinkPreview;
use Datlechin\LinkPreview\Internal\RouteCards;
use Flarum\Extend;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

class LetsOtherExtensionsDescribeTheirPagesTest extends TestCase
{
    use PreviewsLinks;
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('datlechin-link-preview');
        $this->isolateTheNetwork();
        $this->setting('forum_title', 'The Cast Iron Club');

        $this->extend(
            (new Extend\Frontend('forum'))
                ->route('/recipes', 'recipes.index')
                ->route('/recipes/{id:\d+}[/{step:\d+}]', 'recipes.show'),
            (new LinkPreview())
                ->route('recipes.show', RecipeCard::class)
                ->route('recipes.nowhere', RecipeCard::class),
        );

        $this->prepareDatabase([
            'users' => [$this->normalUser()],
        ]);
    }

    #[Test]
    public function a_registered_route_gets_the_card_its_extension_builds(): void
    {
        $data = $this->data($this->preview('http://localhost/recipes/7'));

        $this->assertSame('recipes.show', $data['type']);
        $this->assertSame('Recipe 7', $data['title']);
        $this->assertSame('Flour, water, salt.', $data['description']);
        $this->assertSame(['url' => 'https://cdn.test/recipe.png', 'width' => null, 'height' => null], $data['image']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function the_forum_names_itself_as_the_site_whoever_built_the_card(): void
    {
        $this->assertSame('The Cast Iron Club', $this->data($this->preview('http://localhost/recipes/7'))['siteName']);
    }

    #[Test]
    public function every_kind_of_fact_arrives_in_the_shape_the_browser_renders(): void
    {
        $meta = $this->meta($this->data($this->preview('http://localhost/recipes/7')));

        $this->assertSame(['type' => 'text', 'text' => 'Bread'], $meta[0]);
        $this->assertSame(['type' => 'user', 'text' => 'normal'], $meta[1]);
        $this->assertSame(['type' => 'count', 'key' => 'acme-recipes.forum.link_preview.steps', 'count' => 4], $meta[2]);
        $this->assertSame('date', $meta[3]['type']);
        $this->assertSameInstant('2026-02-03 04:05:06', $meta[3]['date']);
    }

    #[Test]
    public function optional_segments_of_the_route_are_matched_too(): void
    {
        $this->assertSame('Recipe 7', $this->data($this->preview('http://localhost/recipes/7/2'))['title']);
        $this->assertSame('Recipe 7', $this->data($this->preview('http://localhost/recipes/7/'))['title']);
    }

    #[Test]
    public function a_card_that_declines_reads_as_no_metadata(): void
    {
        $guest = $this->data($this->preview('http://localhost/recipes/'.RecipeCard::HIDDEN_FROM_GUESTS));
        $member = $this->data($this->preview('http://localhost/recipes/'.RecipeCard::HIDDEN_FROM_GUESTS, ['authenticatedAs' => 2]));

        $this->assertSame('no_metadata', $guest['error']);
        $this->assertSame('Recipe 13', $member['title']);
    }

    #[Test]
    public function a_route_of_the_same_extension_with_no_card_is_not_previewable(): void
    {
        $this->assertSame('not_previewable', $this->data($this->preview('http://localhost/recipes'))['error']);
    }

    #[Test]
    public function a_card_that_throws_fails_alone_and_leaves_the_batch_standing(): void
    {
        $data = $this->data($this->previewBatch([
            'http://localhost/recipes/'.RecipeCard::BROKEN,
            'http://localhost/recipes/7',
        ]));

        $this->assertSame('no_metadata', $data['http://localhost/recipes/'.RecipeCard::BROKEN]['error']);
        $this->assertSame('Recipe 7', $data['http://localhost/recipes/7']['title']);
    }

    #[Test]
    public function a_card_registered_for_a_route_nobody_defined_is_harmless(): void
    {
        $this->assertSame('default', $this->data($this->preview('http://localhost/'))['type']);
    }

    #[Test]
    public function a_class_that_is_not_a_card_is_refused_where_it_is_registered(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app()->getContainer()->make(RouteCards::class)->add('recipes.show', stdClass::class);
    }
}
