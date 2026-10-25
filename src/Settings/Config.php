<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Settings;

use Flarum\Settings\SettingsRepositoryInterface;

final class Config
{
    public const MAX_BATCH_SIZE = 20;

    private const PREFIX = 'datlechin-link-preview.';

    public const DEFAULTS = [
        self::PREFIX.'enable_batch_requests' => true,
        self::PREFIX.'cache_time' => 60,
        self::PREFIX.'blocklist' => '',
        self::PREFIX.'allowlist' => '',
        self::PREFIX.'google_favicon_fallback' => false,
        self::PREFIX.'open_links_in_new_tab' => true,
        self::PREFIX.'skip_media_links' => false,
        self::PREFIX.'preview_internal_links' => true,
        self::PREFIX.'max_previews_per_post' => 5,
    ];

    private const MAX_NEGATIVE_CACHE_SECONDS = 600;

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function cacheSeconds(): int
    {
        $minutes = $this->settings->get(self::PREFIX.'cache_time');

        if (! is_numeric($minutes)) {
            $minutes = self::number('cache_time');
        }

        return max(0, (int) $minutes) * 60;
    }

    public function negativeCacheSeconds(): int
    {
        return min($this->cacheSeconds(), self::MAX_NEGATIVE_CACHE_SECONDS);
    }

    public function blocklist(): array
    {
        return $this->list('blocklist');
    }

    public function allowlist(): array
    {
        return $this->list('allowlist');
    }

    public function googleFaviconFallback(): bool
    {
        return $this->boolean('google_favicon_fallback');
    }

    public function previewInternalLinks(): bool
    {
        return $this->boolean('preview_internal_links');
    }

    public function maxPreviewsPerPost(): int
    {
        return self::previewLimit($this->settings->get(self::PREFIX.'max_previews_per_post'));
    }

    public static function previewLimit(mixed $value): int
    {
        return is_numeric($value) ? max(1, (int) $value) : self::number('max_previews_per_post');
    }

    private function list(string $key): array
    {
        $stored = $this->settings->get(self::PREFIX.$key);

        if (! is_string($stored) || $stored === '') {
            return [];
        }

        $entries = preg_split('/[,\n]/', $stored) ?: [];

        return array_values(array_filter(array_map(trim(...), $entries), fn (string $entry) => $entry !== ''));
    }

    private function boolean(string $key): bool
    {
        $stored = $this->settings->get(self::PREFIX.$key);

        return $stored === null ? self::DEFAULTS[self::PREFIX.$key] === true : (bool) $stored;
    }

    private static function number(string $key): int
    {
        $default = self::DEFAULTS[self::PREFIX.$key] ?? null;

        return is_int($default) ? $default : 0;
    }
}
