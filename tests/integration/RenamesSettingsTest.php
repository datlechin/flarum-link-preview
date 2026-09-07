<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Tests\integration;

use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The upgrade path for a forum that has been running the old version.
 *
 * Four settings changed name and two were withdrawn. Getting this wrong is
 * quiet: a blocklist that silently empties itself does not fail, it just starts
 * previewing the sites an administrator asked it not to.
 */
class RenamesSettingsTest extends TestCase
{
    private const PREFIX = 'datlechin-link-preview.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('datlechin-link-preview');
    }

    #[Test]
    public function the_renamed_settings_keep_their_values(): void
    {
        $this->store([
            'use_google_favicons' => '1',
            'convert_media_urls' => '1',
            'blacklist' => 'tracker.test',
            'whitelist' => 'good.test',
        ]);

        $this->migrate('up');

        $this->assertSame('1', $this->value('google_favicon_fallback'));
        $this->assertSame('1', $this->value('skip_media_links'));
        $this->assertSame('tracker.test', $this->value('blocklist'));
        $this->assertSame('good.test', $this->value('allowlist'));

        foreach (['use_google_favicons', 'convert_media_urls', 'blacklist', 'whitelist'] as $old) {
            $this->assertNull($this->value($old), "$old was left behind");
        }
    }

    #[Test]
    public function a_value_the_administrator_has_since_chosen_is_left_alone(): void
    {
        // The second run of a migration, or an upgrade where the new settings
        // page was opened first. The stale value must not win.
        $this->store(['blacklist' => 'stale.test', 'blocklist' => 'chosen.test']);

        $this->migrate('up');

        $this->assertSame('chosen.test', $this->value('blocklist'));
        $this->assertNull($this->value('blacklist'));
    }

    #[Test]
    public function the_external_api_settings_are_removed_rather_than_renamed(): void
    {
        $this->store([
            'external_api_fallback' => '1',
            'external_api_url' => 'https://unfurl.example/?url=',
        ]);

        $this->migrate('up');

        $this->assertNull($this->value('external_api_fallback'));
        $this->assertNull($this->value('external_api_url'));

        // The address was configured once and forgotten, and there is nowhere
        // honest to carry it to, so it must not survive under another name.
        $this->assertSame(0, $this->database()->table('settings')->where('value', 'like', '%unfurl.example%')->count());
    }

    #[Test]
    public function running_it_a_second_time_changes_nothing(): void
    {
        $this->store(['blacklist' => 'tracker.test']);
        $this->migrate('up');

        $this->database()->table('settings')
            ->where('key', self::PREFIX.'blocklist')
            ->update(['value' => 'ads.test']);

        $this->migrate('up');

        $this->assertSame('ads.test', $this->value('blocklist'));
    }

    #[Test]
    public function a_forum_that_never_touched_the_old_settings_gains_nothing(): void
    {
        $this->migrate('up');

        $this->assertSame(0, $this->database()->table('settings')->where('key', 'like', self::PREFIX.'%')->count());
    }

    #[Test]
    public function rolling_back_puts_the_old_names_back(): void
    {
        $this->store([
            'google_favicon_fallback' => '1',
            'skip_media_links' => '1',
            'blocklist' => 'tracker.test',
            'allowlist' => 'good.test',
        ]);

        $this->migrate('down');

        $this->assertSame('1', $this->value('use_google_favicons'));
        $this->assertSame('1', $this->value('convert_media_urls'));
        $this->assertSame('tracker.test', $this->value('blacklist'));
        $this->assertSame('good.test', $this->value('whitelist'));

        foreach (['google_favicon_fallback', 'skip_media_links', 'blocklist', 'allowlist'] as $new) {
            $this->assertNull($this->value($new), "$new was left behind");
        }
    }

    private function migrate(string $direction): void
    {
        /** @var array<string, callable> $migration */
        $migration = require __DIR__.'/../../migrations/2026_09_06_000000_rename_settings.php';

        $migration[$direction]($this->database()->getSchemaBuilder());
    }

    /**
     * @param  array<string, string>  $values
     */
    private function store(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->database()->table('settings')->insert(['key' => self::PREFIX.$key, 'value' => $value]);
        }
    }

    private function value(string $key): ?string
    {
        $value = $this->database()->table('settings')->where('key', self::PREFIX.$key)->value('value');

        return is_string($value) ? $value : null;
    }
}
