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
 * Every URL that leaves the extractor is absolute and points at the web.
 *
 * Pages routinely put a relative path in `og:image`, and a card handing the
 * browser `/img/card.png` renders broken against the forum's own origin.
 * `data:` and `javascript:` are dropped here rather than shipped for the
 * frontend to hide.
 */
class ResolvesImageAndFaviconUrlsTest extends TestCase
{
    private function extract(string $head, string $baseUrl = 'https://example.com/blog/post'): Metadata
    {
        return (new MetadataExtractor())->extract("<html><head>$head</head></html>", $baseUrl);
    }

    private function image(string $content, string $baseUrl = 'https://example.com/blog/post'): ?string
    {
        return $this->extract('<meta property="og:image" content="'.$content.'">', $baseUrl)->imageUrl;
    }

    #[Test]
    public function a_relative_image_resolves_against_the_page(): void
    {
        $this->assertSame('https://example.com/blog/card.png', $this->image('card.png'));
    }

    #[Test]
    public function a_root_relative_image_resolves_against_the_origin(): void
    {
        $this->assertSame('https://example.com/img/card.png', $this->image('/img/card.png'));
    }

    #[Test]
    public function a_protocol_relative_image_takes_the_pages_scheme(): void
    {
        $this->assertSame('https://cdn.example.com/card.png', $this->image('//cdn.example.com/card.png'));
        $this->assertSame('http://cdn.example.com/card.png', $this->image('//cdn.example.com/card.png', 'http://example.com/blog/post'));
    }

    #[Test]
    public function an_absolute_image_is_left_alone(): void
    {
        $this->assertSame('https://cdn.other.test/card.png', $this->image('https://cdn.other.test/card.png'));
    }

    #[Test]
    public function a_javascript_url_is_dropped(): void
    {
        $this->assertNull($this->image('javascript:alert(1)'));
    }

    #[Test]
    public function a_data_url_is_dropped(): void
    {
        $this->assertNull($this->image('data:image/png;base64,iVBORw0KGgo='));
    }

    #[Test]
    public function an_image_that_points_back_at_the_page_is_dropped(): void
    {
        $this->assertNull($this->image('#'));
    }

    #[Test]
    public function the_query_and_fragment_of_the_page_do_not_leak_into_a_relative_image(): void
    {
        $this->assertSame(
            'https://example.com/blog/card.png',
            $this->image('card.png', 'https://example.com/blog/post?utm_source=forum#section'),
        );
    }

    #[Test]
    public function the_plain_icon_wins_over_the_other_declared_icons(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <link rel="apple-touch-icon" href="/apple-touch-icon.png">
            <link rel="shortcut icon" href="/shortcut.ico">
            <link rel="icon" href="/icon.png">
            HTML);

        $this->assertSame('https://example.com/icon.png', $metadata->faviconUrl);
    }

    #[Test]
    public function a_shortcut_icon_wins_over_an_apple_touch_icon(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <link rel="apple-touch-icon" href="/apple-touch-icon.png">
            <link rel="shortcut icon" href="/shortcut.ico">
            HTML);

        $this->assertSame('https://example.com/shortcut.ico', $metadata->faviconUrl);
    }

    #[Test]
    public function an_apple_touch_icon_is_better_than_nothing(): void
    {
        $metadata = $this->extract('<link rel="apple-touch-icon" href="https://cdn.example.com/tile.png">');

        $this->assertSame('https://cdn.example.com/tile.png', $metadata->faviconUrl);
    }

    #[Test]
    public function a_link_that_is_not_an_icon_is_ignored(): void
    {
        $metadata = $this->extract(<<<'HTML'
            <link rel="stylesheet" href="/site.css">
            <link rel="canonical" href="https://example.com/blog/post">
            HTML);

        $this->assertSame('https://example.com/favicon.ico', $metadata->faviconUrl);
    }

    #[Test]
    public function the_origin_favicon_is_the_fallback(): void
    {
        $metadata = $this->extract('<title>Document title</title>', 'https://example.com/blog/post?page=2#top');

        $this->assertSame('https://example.com/favicon.ico', $metadata->faviconUrl);
    }

    #[Test]
    public function a_declared_icon_the_browser_could_not_load_falls_back_to_the_origin(): void
    {
        $metadata = $this->extract('<link rel="icon" href="data:image/x-icon;base64,AAABAAA=">');

        $this->assertSame('https://example.com/favicon.ico', $metadata->faviconUrl);
    }

    #[Test]
    public function a_base_url_that_is_not_a_url_leaves_nothing_to_resolve_against(): void
    {
        $metadata = $this->extract('<meta property="og:image" content="/img/card.png">', 'not a url');

        $this->assertNull($metadata->imageUrl);
        $this->assertNull($metadata->faviconUrl);
    }
}
