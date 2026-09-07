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
 * The three named constructors are the only shapes a response body can take, so
 * a half-filled preview has nowhere to come from: a card either describes a
 * page elsewhere, describes something this forum holds, or says why neither
 * happened.
 *
 * @phpstan-type MetaItem array{key: string, text: string}|array{key: string, count: int}|array{key: string, date: string}
 */
final class Preview
{
    private const TYPE_LINK = 'link';

    private const LAYOUT_COMPACT = 'compact';

    /**
     * @param  array{url: string, width: int|null, height: int|null}|null  $image
     * @param  list<MetaItem>  $meta
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
        private readonly array $meta = [],
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
     * A card for something this forum holds itself.
     *
     * Always compact: what these carry in place of a page's image is a short
     * list of facts, and an avatar, the only image any of them has, is small by
     * definition, so there is nothing to grow a large card around.
     *
     * @param  'discussion'|'user'|'tag'|'forum'  $type
     * @param  list<MetaItem>  $meta
     */
    public static function internal(
        string $url,
        string $type,
        ?string $title,
        ?string $description = null,
        ?string $siteName = null,
        ?string $favicon = null,
        array $meta = [],
        ?string $image = null,
    ): self {
        return new self(
            url: $url,
            error: null,
            type: $type,
            layout: self::LAYOUT_COMPACT,
            title: $title,
            description: $description,
            siteName: $siteName,
            favicon: $favicon,
            image: $image === null ? null : ['url' => $image, 'width' => null, 'height' => null],
            meta: $meta,
        );
    }

    public static function error(string $url, PreviewError $error): self
    {
        return new self(self::echoable($url), $error);
    }

    /**
     * What is safe to hand back to the page that asked.
     *
     * A failure still echoes the address it was asked about, so a `data:` or
     * `javascript:` URL rejected for its scheme must not come back out of the
     * endpoint. Anything but http or https becomes empty.
     */
    private static function echoable(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) ? $url : '';
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

        if ($this->meta !== []) {
            $data['meta'] = $this->meta;
        }

        return $data;
    }
}
