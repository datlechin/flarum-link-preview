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
use Flarum\Testing\unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * How big a hole the card is allowed to make in the post.
 *
 * The server only says `large` when it is sure: a page that declares a banner,
 * or one that asked for a large card outright. Everything else starts compact
 * and the browser upgrades it once the image's real size is known, because a
 * large card that turns out to hold a 16x16 sprite cannot be taken back.
 */
class ChoosesTheCardLayoutTest extends TestCase
{
    private function withImage(?int $width, ?int $height, bool $prefersLarge = false): Metadata
    {
        return new Metadata(
            title: 'Document title',
            description: null,
            siteName: null,
            imageUrl: 'https://cdn.example.com/card.png',
            imageWidth: $width,
            imageHeight: $height,
            faviconUrl: null,
            prefersLargeImage: $prefersLarge,
        );
    }

    #[Test]
    public function a_summary_large_image_card_is_large_even_without_dimensions(): void
    {
        $this->assertSame('large', $this->withImage(null, null, true)->layout());
    }

    #[Test]
    public function no_image_is_always_compact(): void
    {
        $metadata = new Metadata(
            title: 'Document title',
            description: 'Description.',
            siteName: 'Example',
            imageUrl: null,
            imageWidth: 1200,
            imageHeight: 630,
            faviconUrl: null,
            prefersLargeImage: true,
        );

        $this->assertSame('compact', $metadata->layout());
    }

    #[Test]
    public function an_image_of_unknown_size_is_compact(): void
    {
        $this->assertSame('compact', $this->withImage(null, null)->layout());
        $this->assertSame('compact', $this->withImage(1200, null)->layout());
        $this->assertSame('compact', $this->withImage(null, 630)->layout());
    }

    #[Test]
    public function a_banner_is_large(): void
    {
        $this->assertSame('large', $this->withImage(1200, 630)->layout());
    }

    #[Test]
    #[DataProvider('compactShapes')]
    public function a_shape_the_large_frame_would_ruin_stays_compact(int $width, int $height): void
    {
        $this->assertSame('compact', $this->withImage($width, $height)->layout());
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function compactShapes(): array
    {
        return [
            'square' => [800, 800],
            'taller than it is wide' => [600, 1200],
            'too small however well shaped' => [400, 200],
            'a letterbox the 2:1 frame would crop to nothing' => [1200, 300],
        ];
    }

    #[Test]
    public function six_hundred_pixels_wide_is_wide_enough(): void
    {
        $this->assertSame('large', $this->withImage(600, 400)->layout());
        $this->assertSame('compact', $this->withImage(599, 400)->layout());
    }

    #[Test]
    public function the_ratio_band_includes_both_of_its_ends(): void
    {
        $this->assertSame('large', $this->withImage(600, 500)->layout());
        $this->assertSame('compact', $this->withImage(600, 501)->layout());

        $this->assertSame('large', $this->withImage(1200, 400)->layout());
        $this->assertSame('compact', $this->withImage(1200, 399)->layout());
    }

    #[Test]
    public function a_declared_height_of_zero_does_not_divide_by_zero(): void
    {
        $this->assertSame('compact', $this->withImage(1200, 0)->layout());
    }

    #[Test]
    public function a_card_is_empty_only_when_it_has_nothing_to_show(): void
    {
        $nothing = new Metadata(null, null, 'Example', null, null, null, 'https://example.com/favicon.ico', false);

        $this->assertTrue($nothing->isEmpty());
        $this->assertFalse($this->withImage(null, null)->isEmpty());
        $this->assertFalse((new Metadata(null, 'Description.', null, null, null, null, null, false))->isEmpty());
    }
}
