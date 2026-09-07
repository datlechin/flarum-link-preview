<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Tests\unit\Settings;

use Datlechin\LinkPreview\Settings\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\unit\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Settings arrive as strings and have to be used as numbers, flags and lists.
 *
 * A row saved through the admin page is a string whatever the extender
 * declared, and a forum that has never opened the page has no row at all.
 * Every read goes through here so that the two cases mean the same thing
 * everywhere rather than whatever the calling site happened to cast.
 */
class ReadsTheStoredSettingsTest extends TestCase
{
    private const PREFIX = 'datlechin-link-preview.';

    /**
     * Rows are stored under the real prefix, so a key read without it comes
     * back as a missing row and the test sees the default instead.
     *
     * @param  array<string, mixed>  $stored
     */
    private function config(array $stored): Config
    {
        $rows = [];

        foreach ($stored as $key => $value) {
            $rows[self::PREFIX.$key] = $value;
        }

        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturnUsing(
            fn (string $key, mixed $default = null): mixed => $rows[$key] ?? $default,
        );

        return new Config($settings);
    }

    #[Test]
    public function a_list_splits_on_commas_and_on_newlines(): void
    {
        $config = $this->config([
            'blocklist' => "example.com, cdn.example.com\nother.test,\r\n  spaced.test  ",
        ]);

        $this->assertSame(
            ['example.com', 'cdn.example.com', 'other.test', 'spaced.test'],
            $config->blocklist(),
        );
    }

    #[Test]
    public function a_list_drops_the_gaps_an_administrator_leaves(): void
    {
        // A trailing comma and a blank line are how a list gets edited, not a
        // rule that matches the empty string.
        $config = $this->config(['allowlist' => "example.com,\n\n , ,\ndocs.example.com,"]);

        $this->assertSame(['example.com', 'docs.example.com'], $config->allowlist());
    }

    #[Test]
    public function an_unset_list_is_empty(): void
    {
        $config = $this->config([]);

        $this->assertSame([], $config->blocklist());
        $this->assertSame([], $config->allowlist());
        $this->assertSame([], $this->config(['blocklist' => ''])->blocklist());
    }

    #[Test]
    public function a_single_entry_needs_no_separator(): void
    {
        $this->assertSame(['example.com'], $this->config(['blocklist' => '  example.com '])->blocklist());
    }

    #[Test]
    public function the_cache_time_is_stored_in_minutes_and_used_in_seconds(): void
    {
        $this->assertSame(3600, $this->config(['cache_time' => '60'])->cacheSeconds());
        $this->assertSame(300, $this->config(['cache_time' => 5])->cacheSeconds());
    }

    #[Test]
    public function a_cache_time_of_zero_turns_caching_off(): void
    {
        $config = $this->config(['cache_time' => '0']);

        $this->assertSame(0, $config->cacheSeconds());
        $this->assertSame(0, $config->negativeCacheSeconds());
    }

    #[Test]
    public function an_unreadable_cache_time_falls_back_to_the_declared_default(): void
    {
        $this->assertSame(3600, $this->config([])->cacheSeconds());
        $this->assertSame(3600, $this->config(['cache_time' => 'an hour'])->cacheSeconds());
        $this->assertSame(3600, $this->config(['cache_time' => ''])->cacheSeconds());
    }

    #[Test]
    public function a_negative_cache_time_is_read_as_off(): void
    {
        $this->assertSame(0, $this->config(['cache_time' => '-5'])->cacheSeconds());
    }

    #[Test]
    public function a_failure_is_remembered_for_less_time_than_a_page(): void
    {
        // A site that was down for a minute must not stay blank for the hour a
        // successful preview is kept for.
        $this->assertSame(600, $this->config(['cache_time' => '60'])->negativeCacheSeconds());
        $this->assertSame(600, $this->config(['cache_time' => '10080'])->negativeCacheSeconds());
    }

    #[Test]
    public function a_short_cache_time_shortens_the_failure_too(): void
    {
        $this->assertSame(300, $this->config(['cache_time' => '5'])->negativeCacheSeconds());
        $this->assertSame(60, $this->config(['cache_time' => '1'])->negativeCacheSeconds());
    }

    #[Test]
    public function a_missing_flag_keeps_the_behaviour_the_extension_advertises(): void
    {
        $config = $this->config([]);

        $this->assertFalse($config->googleFaviconFallback());
        $this->assertTrue($config->previewInternalLinks());
    }

    #[Test]
    public function a_flag_saved_as_a_string_is_read_as_a_flag(): void
    {
        $this->assertTrue($this->config(['google_favicon_fallback' => '1'])->googleFaviconFallback());
        $this->assertFalse($this->config(['google_favicon_fallback' => '0'])->googleFaviconFallback());
        $this->assertFalse($this->config(['preview_internal_links' => '0'])->previewInternalLinks());
        $this->assertTrue($this->config(['preview_internal_links' => '1'])->previewInternalLinks());
    }

    #[Test]
    public function every_row_is_read_under_the_extensions_own_prefix(): void
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->with(self::PREFIX.'cache_time')->andReturn('5');
        $settings->shouldReceive('get')->with(self::PREFIX.'blocklist')->andReturn('example.com');
        $settings->shouldReceive('get')->andReturn(null);

        $config = new Config($settings);

        $this->assertSame(300, $config->cacheSeconds());
        $this->assertSame(['example.com'], $config->blocklist());
    }

    #[Test]
    public function a_cap_of_zero_is_read_as_a_cap_of_one(): void
    {
        // An administrator who clears the field or types a zero has asked for
        // fewer previews, not for a forum where no link ever gets one. The
        // browser reads this same number out of the forum payload, where
        // `extend.php` sends it through the clamp below rather than raw, so a
        // stored zero cannot mean one thing here and none at all there.
        $this->assertSame(1, $this->config(['max_previews_per_post' => '0'])->maxPreviewsPerPost());
        $this->assertSame(1, $this->config(['max_previews_per_post' => 0])->maxPreviewsPerPost());
        $this->assertSame(1, $this->config(['max_previews_per_post' => '-3'])->maxPreviewsPerPost());
    }

    #[Test]
    public function a_cap_that_is_not_a_number_falls_back_to_the_declared_default(): void
    {
        $this->assertSame(5, $this->config([])->maxPreviewsPerPost());
        $this->assertSame(5, $this->config(['max_previews_per_post' => ''])->maxPreviewsPerPost());
        $this->assertSame(5, $this->config(['max_previews_per_post' => 'lots'])->maxPreviewsPerPost());
    }

    #[Test]
    public function the_cap_an_administrator_chose_is_the_cap(): void
    {
        $this->assertSame(3, $this->config(['max_previews_per_post' => '3'])->maxPreviewsPerPost());
        $this->assertSame(12, $this->config(['max_previews_per_post' => 12])->maxPreviewsPerPost());
    }

    #[Test]
    public function the_clamp_the_two_sides_share_is_reachable_without_a_repository(): void
    {
        // `extend.php` calls this while the extenders are being built, where
        // there is no settings repository to hand and only the one row.
        $this->assertSame(1, Config::previewLimit('0'));
        $this->assertSame(1, Config::previewLimit(0));
        $this->assertSame(5, Config::previewLimit(null));
        $this->assertSame(5, Config::previewLimit('every one of them'));
        $this->assertSame(4, Config::previewLimit('4'));
    }

    #[Test]
    public function the_batch_size_the_frontend_chunks_to_is_fixed(): void
    {
        // The frontend queue chunks to the same number, so a page full of
        // links never sends a request the server would truncate.
        $this->assertSame(20, Config::MAX_BATCH_SIZE);
    }
}
