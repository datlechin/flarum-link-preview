<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Preview;

use Datlechin\LinkPreview\Html\Metadata;

/**
 * One card's worth of data, on its way to the browser.
 *
 * The three named constructors are the only shapes a response body can take,
 * so there is nowhere for a half-filled preview to come from: a card either
 * describes a page, describes a discussion, or says why neither happened.
 */
final class Preview
{
    private const TYPE_LINK = 'link';

    private const TYPE_DISCUSSION = 'discussion';

    private const LAYOUT_COMPACT = 'compact';

    /**
     * @param  array{url: string, width: int|null, height: int|null}|null  $image
     * @param  array{id: int, commentCount: int, participantCount: int, author: string|null, createdAt: string, tags: list<array{name: string}>}|null  $discussion
     */
    private function __construct(
        private readonly string $url,
        private readonly ?PreviewError $error,
        private readonly string $type = self::TYPE_LINK,
        private readonly string $layout = self::LAYOUT_COMPACT,
        private readonly ?string $title = null,
        private readonly ?string $description = null,
        private readonly ?string $siteName = null,
        private readonly ?string $favicon = null,
        private readonly ?array $image = null,
        private readonly ?array $discussion = null,
    ) {
    }

    public static function link(string $url, Metadata $metadata): self
    {
        return new self(
            url: $url,
            error: null,
            type: self::TYPE_LINK,
            layout: $metadata->layout(),
            title: $metadata->title,
            description: $metadata->description,
            siteName: $metadata->siteName,
            favicon: $metadata->faviconUrl,
            image: $metadata->imageUrl === null ? null : [
                'url' => $metadata->imageUrl,
                'width' => $metadata->imageWidth,
                'height' => $metadata->imageHeight,
            ],
        );
    }

    /**
     * @param  array{id: int, commentCount: int, participantCount: int, author: string|null, createdAt: string, tags: list<array{name: string}>}  $discussion
     */
    public static function discussion(string $url, string $title, ?string $description, ?string $siteName, ?string $favicon, array $discussion): self
    {
        // Always compact: a discussion card carries reply and participant
        // counts instead of an image, and there is no image to grow around.
        return new self(
            url: $url,
            error: null,
            type: self::TYPE_DISCUSSION,
            layout: self::LAYOUT_COMPACT,
            title: $title,
            description: $description,
            siteName: $siteName,
            favicon: $favicon,
            discussion: $discussion,
        );
    }

    public static function error(string $url, PreviewError $error): self
    {
        return new self(self::echoable($url), $error);
    }

    /**
     * What is safe to hand back to the page that asked.
     *
     * An error card puts this address on screen and links to it, so a `data:`
     * or `javascript:` URL that was just rejected for its scheme must not come
     * back out of the endpoint wearing a link. Anything that is not plain http
     * or https becomes nothing at all, and the card renders without a link.
     */
    private static function echoable(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) ? $url : '';
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->error !== null) {
            return [
                'url' => $this->url,
                'error' => $this->error->value,
            ];
        }

        $data = [
            'url' => $this->url,
            'type' => $this->type,
            'layout' => $this->layout,
            'title' => $this->title,
            'description' => $this->description,
            'siteName' => $this->siteName,
            'favicon' => $this->favicon,
            'image' => $this->image,
        ];

        if ($this->discussion !== null) {
            $data['discussion'] = $this->discussion;
        }

        return $data;
    }
}
