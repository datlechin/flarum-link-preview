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

/**
 * A setting saved through the admin page arrives as a string whatever the
 * extender declared, so every read is funnelled through here rather than cast
 * at the calling site.
 */
final class Config
{
    /**
     * Mirrored by the frontend queue, which chunks to the same number so a page
     * full of links never sends a request the server would truncate.
     */
    public const MAX_BATCH_SIZE = 20;

    private const PREFIX = 'datlechin-link-preview.';

    /**
     * @var array<string, mixed>
     */
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

    /**
     * A site that was down for a minute should not stay blank for an hour.
     */
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

    /**
     * @return list<string>
     */
    public function blocklist(): array
    {
        return $this->list('blocklist');
    }

    /**
     * @return list<string>
     */
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

    /**
     * At least one: an administrator who clears the field is asking for fewer
     * previews, not for a forum where no link ever gets one. The browser reads
     * the same number from the forum payload and applies the same floor.
     */
    public static function previewLimit(mixed $value): int
    {
        return is_numeric($value) ? max(1, (int) $value) : self::number('max_previews_per_post');
    }

    /**
     * @return list<string>
     */
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
