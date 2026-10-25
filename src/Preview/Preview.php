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
use Datlechin\LinkPreview\Internal\Card;
use Datlechin\LinkPreview\Internal\Meta;

final class Preview
{
    private const TYPE_LINK = 'link';

    private const LAYOUT_COMPACT = 'compact';

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

    public static function internal(string $url, string $type, Card $card, ?string $siteName, ?string $favicon): self
    {
        return new self(
            url: $url,
            error: null,
            type: $type,
            layout: self::LAYOUT_COMPACT,
            title: $card->title,
            description: $card->description,
            siteName: $siteName,
            favicon: $favicon,
            image: $card->image === null ? null : ['url' => $card->image, 'width' => null, 'height' => null],
            meta: array_map(fn (Meta $item): array => $item->toArray(), $card->meta),
        );
    }

    public static function error(string $url, PreviewError $error): self
    {
        return new self(self::echoable($url), $error);
    }

    private static function echoable(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) ? $url : '';
    }

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
