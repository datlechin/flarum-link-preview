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
 * The settings the browser is handed, in the form it acts on them in.
 *
 * Some of these are decisions the frontend makes before it asks the server
 * anything: a blocked host is dropped without a request, a post stops
 * collecting links at the cap. A row that reaches the browser meaning
 * something other than what the server reads leaves the two halves disagreeing.
 */
class SendsTheBrowserTheSettingsItActsOnTest extends TestCase
{
    private const PREFIX = 'datlechin-link-preview.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('datlechin-link-preview');
    }

    #[Test]
    public function a_cap_of_zero_reaches_the_browser_as_the_one_the_server_enforces(): void
    {
        // The browser reads 0 as a cap of nothing and collects no links, while
        // the server clamps the same row to one. Sent raw, a typed zero turns
        // every preview in the forum off.
        $this->setting(self::PREFIX.'max_previews_per_post', '0');

        $this->assertSame(1, $this->attribute('maxPreviewsPerPost'));
    }

    #[Test]
    public function a_cap_that_is_not_a_number_reaches_it_as_the_declared_default(): void
    {
        // A cast would have made these 0 as well, and the frontend has no way
        // to tell a cap of nothing from a row it should not have been sent.
        $this->setting(self::PREFIX.'max_previews_per_post', '');

        $this->assertSame(5, $this->attribute('maxPreviewsPerPost'));

        $this->setting(self::PREFIX.'max_previews_per_post', 'all of them');

        $this->assertSame(5, $this->attribute('maxPreviewsPerPost'));
    }

    #[Test]
    public function the_cap_an_administrator_chose_reaches_it_unchanged(): void
    {
        $this->setting(self::PREFIX.'max_previews_per_post', '3');

        $this->assertSame(3, $this->attribute('maxPreviewsPerPost'));
    }

    #[Test]
    public function a_forum_that_has_never_opened_the_settings_page_gets_the_declared_defaults(): void
    {
        $this->assertSame(5, $this->attribute('maxPreviewsPerPost'));
        $this->assertTrue($this->attribute('batchRequests'));
        $this->assertTrue($this->attribute('previewInternalLinks'));
        $this->assertFalse($this->attribute('googleFaviconFallback'));
        $this->assertFalse($this->attribute('skipMediaLinks'));
    }

    #[Test]
    public function a_flag_saved_as_a_string_reaches_the_browser_as_a_flag(): void
    {
        // Everything saved through the admin page comes back a string, and
        // `'0'` is true to JavaScript.
        $this->setting(self::PREFIX.'skip_media_links', '1');
        $this->setting(self::PREFIX.'preview_internal_links', '0');

        $this->assertTrue($this->attribute('skipMediaLinks'));
        $this->assertFalse($this->attribute('previewInternalLinks'));
    }

    #[Test]
    public function the_lists_reach_the_browser_so_a_blocked_link_costs_no_request(): void
    {
        $this->setting(self::PREFIX.'blocklist', "tracker.test\nads.test");
        $this->setting(self::PREFIX.'allowlist', 'good.test');

        $this->assertSame("tracker.test\nads.test", $this->attribute('blocklist'));
        $this->assertSame('good.test', $this->attribute('allowlist'));
    }

    private function attribute(string $name): mixed
    {
        $body = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true);

        $attributes = is_array($body) && is_array($body['data']['attributes'] ?? null)
            ? $body['data']['attributes']
            : [];

        $key = self::PREFIX.$name;

        $this->assertArrayHasKey($key, $attributes, "$key is not in the forum payload");

        return $attributes[$key];
    }
}
