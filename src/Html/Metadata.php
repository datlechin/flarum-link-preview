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
 * Every value is already cleaned and absolute, or `null`.
 */
final class Metadata
{
    private const LARGE_MIN_WIDTH = 600;

    /**
     * Outside this band an image cropped to the large card's 2:1 frame loses
     * the part that mattered.
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
     * Unknown dimensions mean `compact` rather than a guess: the browser knows
     * the natural size once the image loads and upgrades the card there.
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
