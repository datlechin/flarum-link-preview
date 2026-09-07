<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Tests\unit\Html;

use Datlechin\LinkPreview\Html\Metadata;
use Datlechin\LinkPreview\Html\MetadataExtractor;
use Flarum\Testing\unit\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Which of a page's competing descriptions of itself is believed.
 *
 * A real page says the same thing three or four times over, in Open Graph, in
 * Twitter cards, in a bare meta tag and in JSON-LD, and the copies disagree.
 * The order is fixed so that two readers who load the same link get the same
 * card, and so that a site that fills only its structured data still gets one.
 */
class ReadsWhatAPageSaysAboutItselfTest extends TestCase
{
    private function extract(string $head, string $baseUrl = 'https://example.com/blog/post'): Metadata
    {
        return (new MetadataExtractor())->extract("<html><head>$head</head><body>Body copy.</body></html>", $baseUrl);
    }

    #[Test]
    public function open_graph_wins_over_everything_else(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <meta property="og:title" content="Open Graph title">
            <meta name="twitter:title" content="Twitter title">
            <title>Document title</title>
            <meta property="og:description" content="Open Graph description">
            <meta name="twitter:description" content="Twitter description">
            <meta name="description" content="Bare description">
            <meta property="og:site_name" content="Open Graph site">
            <meta name="twitter:site" content="@twittersite">
            <meta property="og:image" content="https://cdn.example.com/og.png">
            <meta name="twitter:image" content="https://cdn.example.com/twitter.png">
            <script type="application/ld+json">{"headline":"Structured title","description":"Structured description"}</script>
            HTML);

        $this->assertSame('Open Graph title', $metadata->title);
        $this->assertSame('Open Graph description', $metadata->description);
        $this->assertSame('Open Graph site', $metadata->siteName);
        $this->assertSame('https://cdn.example.com/og.png', $metadata->imageUrl);
    }

    #[Test]
    public function twitter_tags_are_read_when_open_graph_is_absent(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <title>Document title</title>
            <meta name="twitter:title" content="Twitter title">
            <meta name="twitter:description" content="Twitter description">
            <meta name="twitter:site" content="@twittersite">
            <meta name="twitter:image" content="https://cdn.example.com/twitter.png">
            HTML);

        $this->assertSame('Twitter title', $metadata->title);
        $this->assertSame('Twitter description', $metadata->description);
        $this->assertSame('https://cdn.example.com/twitter.png', $metadata->imageUrl);
    }

    #[Test]
    public function the_leading_at_sign_is_stripped_from_a_twitter_handle(): void
    {
        $metadata = $this->extract('<meta name="twitter:site" content="@twittersite">');

        $this->assertSame('twittersite', $metadata->siteName);
    }

    #[Test]
    public function a_tag_with_no_content_does_not_win(): void
    {
        // A content management system that emits the tag whether or not it has
        // anything to put in it would otherwise blank the card for the sake of
        // an empty string.
        $metadata = $this->extract(<<<'HTML'
            <meta property="og:title" content="   ">
            <meta property="og:description" content="">
            <meta property="og:site_name" content=" ">
            <meta name="twitter:title" content="Twitter title">
            <meta name="twitter:description" content="Twitter description">
            <meta name="twitter:site" content="@twittersite">
            HTML);

        $this->assertSame('Twitter title', $metadata->title);
        $this->assertSame('Twitter description', $metadata->description);
        $this->assertSame('twittersite', $metadata->siteName);
    }

    #[Test]
    public function the_bare_description_meta_is_the_third_choice(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <meta name="description" content="Bare description">
            <script type="application/ld+json">{"description":"Structured description"}</script>
            HTML);

        $this->assertSame('Bare description', $metadata->description);
    }

    #[Test]
    public function the_document_title_is_used_when_no_card_tags_exist(): void
    {
        $metadata = $this->extract('<title>Document title</title>');

        $this->assertSame('Document title', $metadata->title);
    }

    #[Test]
    public function the_document_title_beats_structured_data(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <title>Document title</title>
            <script type="application/ld+json">{"headline":"Structured title"}</script>
            HTML);

        $this->assertSame('Document title', $metadata->title);
    }

    #[Test]
    public function structured_data_fills_what_the_tags_left_empty(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <script type="application/ld+json">
            {"@context":"https://schema.org","@type":"Article",
             "headline":"Structured title",
             "description":"Structured description",
             "image":"https://cdn.example.com/structured.png",
             "publisher":{"@type":"Organization","name":"Structured publisher"}}
            </script>
            HTML);

        $this->assertSame('Structured title', $metadata->title);
        $this->assertSame('Structured description', $metadata->description);
        $this->assertSame('Structured publisher', $metadata->siteName);
        $this->assertSame('https://cdn.example.com/structured.png', $metadata->imageUrl);
    }

    #[Test]
    public function a_graph_wrapper_is_unwrapped(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <script type="application/ld+json">
            {"@context":"https://schema.org","@graph":[
              {"@type":"WebSite","name":"Site node name"},
              {"@type":"Article","headline":"Article node headline",
               "image":{"@type":"ImageObject","url":"https://cdn.example.com/graph.png"}}
            ]}
            </script>
            HTML);

        // `headline` is tried across every node before `name` is tried
        // anywhere, so the article wins over the site node it was listed after.
        $this->assertSame('Article node headline', $metadata->title);
        $this->assertSame('https://cdn.example.com/graph.png', $metadata->imageUrl);
    }

    #[Test]
    public function an_array_at_the_root_is_unwrapped(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <script type="application/ld+json">
            [{"@type":"Article","name":"Array node name",
              "image":["https://cdn.example.com/first.png","https://cdn.example.com/second.png"]}]
            </script>
            HTML);

        $this->assertSame('Array node name', $metadata->title);
        $this->assertSame('https://cdn.example.com/first.png', $metadata->imageUrl);
    }

    #[Test]
    public function malformed_structured_data_is_skipped_rather_than_fatal(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <script type="application/ld+json">{ this is not JSON at all }</script>
            <script type="application/ld+json">{"headline":</script>
            <script type="application/ld+json">{"headline":"Valid title"}</script>
            HTML);

        $this->assertSame('Valid title', $metadata->title);
    }

    #[Test]
    public function the_other_open_graph_image_keys_are_fallbacks(): void
    {
        $this->assertSame(
            'https://cdn.example.com/url.png',
            $this->extract('<meta property="og:image:url" content="https://cdn.example.com/url.png">')->imageUrl,
        );

        $this->assertSame(
            'https://cdn.example.com/secure.png',
            $this->extract('<meta property="og:image:secure_url" content="https://cdn.example.com/secure.png">')->imageUrl,
        );

        $this->assertSame(
            'https://cdn.example.com/src.png',
            $this->extract('<meta name="twitter:image:src" content="https://cdn.example.com/src.png">')->imageUrl,
        );
    }

    #[Test]
    public function image_dimensions_are_read_only_when_they_are_whole_numbers(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <meta property="og:image" content="https://cdn.example.com/card.png">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            HTML);

        $this->assertSame(1200, $metadata->imageWidth);
        $this->assertSame(630, $metadata->imageHeight);

        $junk = $this->extract(<<<'HTML'
            <meta property="og:image" content="https://cdn.example.com/card.png">
            <meta property="og:image:width" content="1200px">
            <meta property="og:image:height" content="0">
            HTML);

        $this->assertNull($junk->imageWidth);
        $this->assertNull($junk->imageHeight);
    }

    #[Test]
    public function dimensions_without_an_image_describe_nothing(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <title>Document title</title>
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            HTML);

        $this->assertNull($metadata->imageUrl);
        $this->assertNull($metadata->imageWidth);
        $this->assertNull($metadata->imageHeight);
    }

    #[Test]
    public function a_summary_large_image_card_asks_for_the_large_layout(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <meta name="twitter:card" content="Summary_Large_Image">
            <meta property="og:image" content="https://cdn.example.com/card.png">
            HTML);

        $this->assertTrue($metadata->prefersLargeImage);
        $this->assertSame('large', $metadata->layout());

        $this->assertFalse($this->extract('<meta name="twitter:card" content="summary">')->prefersLargeImage);
    }

    #[Test]
    public function a_page_that_says_nothing_is_empty(): void
    {
        $metadata = $this->extract('<link rel="icon" href="/icon.png">');

        $this->assertNull($metadata->title);
        $this->assertNull($metadata->description);
        $this->assertNull($metadata->siteName);
        $this->assertNull($metadata->imageUrl);
        $this->assertTrue($metadata->isEmpty());
    }

    #[Test]
    public function a_page_with_only_a_title_is_not_empty(): void
    {
        $this->assertFalse($this->extract('<title>Document title</title>')->isEmpty());
    }
}
