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
 * The settings the server side actually reads, in the types it needs them in.
 *
 * `SettingsRepositoryInterface::get()` answers `mixed`, and a setting saved
 * through the admin page arrives as a string whatever the extender declared.
 * Every read is funnelled through here so that a `'0'` or an empty row means
 * the same thing everywhere rather than in whichever way the calling site
 * happened to cast it.
 */
final class Config
{
    /**
     * How many URLs one batch request may carry.
     *
     * Mirrored by the frontend queue, which chunks to the same number so a
     * page full of links never sends a request the server would truncate.
     */
    public const MAX_BATCH_SIZE = 20;

    private const PREFIX = 'datlechin-link-preview.';

    /**
     * What a forum that has never opened the settings page runs on.
     *
     * `extend.php` declares its defaults from this array and every read below
     * falls back to it, so the two cannot disagree about what a missing row
     * means. Keyed by the full setting key, which is the form the extender and
     * the settings repository both speak.
     *
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
     * The longest a failure is remembered for.
     *
     * A dead domain costs a full connect timeout per reader per page view
     * without negative caching, but a site that was down for a minute should
     * not stay blank for an hour, so failures expire well before successes.
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
     * At least one, whatever the row says.
     *
     * An administrator who clears the field or types a zero has asked for a
     * smaller number of previews, not for a forum where no link ever gets one,
     * and the browser reads this same number out of the forum payload.
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

    /**
     * A missing row falls back to the declared default rather than to false,
     * so a forum that has never opened the settings page gets the behaviour
     * the extension advertises.
     */
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
