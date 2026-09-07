<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Html;

/**
 * What a page said about itself, once the markup has been thrown away.
 *
 * Everything here is already cleaned and absolute: a caller never has to ask
 * whether the image URL is relative or whether the title still has entities in
 * it, because a `Metadata` that could not answer those questions honestly
 * carries `null` instead.
 */
final class Metadata
{
    /**
     * Below this the image is too small to fill a card, whatever its shape.
     */
    private const LARGE_MIN_WIDTH = 600;

    /**
     * A banner is wider than it is tall but not a letterbox. Outside this band
     * an image cropped to the large card's 2:1 frame loses the part that
     * mattered, so it is better shown small and whole.
     */
    private const LARGE_MIN_RATIO = 1.2;

    private const LARGE_MAX_RATIO = 3.0;

    public function __construct(
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $siteName,
        public readonly ?string $imageUrl,
        public readonly ?int $imageWidth,
        public readonly ?int $imageHeight,
        public readonly ?string $faviconUrl,
        public readonly bool $prefersLargeImage,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->title === null
            && $this->description === null
            && $this->imageUrl === null;
    }

    /**
     * The card shape the server is asking for.
     *
     * Most pages give an image and no dimensions, so this deliberately says
     * `compact` rather than guess: the browser knows the natural size the
     * moment the image loads and upgrades the card there, which is a cheap
     * correction, whereas a large card that turns out to hold a 16x16 sprite
     * is a hole in the post that cannot be taken back.
     */
    public function layout(): string
    {
        if ($this->imageUrl === null) {
            return 'compact';
        }

        if ($this->prefersLargeImage) {
            return 'large';
        }

        if ($this->imageWidth === null || $this->imageHeight === null || $this->imageHeight <= 0) {
            return 'compact';
        }

        if ($this->imageWidth < self::LARGE_MIN_WIDTH) {
            return 'compact';
        }

        $ratio = $this->imageWidth / $this->imageHeight;

        return $ratio >= self::LARGE_MIN_RATIO && $ratio <= self::LARGE_MAX_RATIO ? 'large' : 'compact';
    }
}
