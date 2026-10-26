<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Tests\unit\Http;

use Flarum\Testing\unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class SaysWhatItIsToTheSiteItFetchesTest extends TestCase
{
    use BuildsAFetcher;

    #[Test]
    public function the_forum_names_itself_rather_than_claiming_to_be_a_browser(): void
    {
        $this->assertMatchesRegularExpression('~link[ _-]?preview~i', $this->header('user-agent'));
    }

    #[Test]
    #[DataProvider('theBrowserItIsNot')]
    public function nothing_in_it_claims_to_be_a_person_at_a_browser(string $token): void
    {
        $this->assertStringNotContainsStringIgnoringCase($token, $this->header('user-agent'));
    }

    public static function theBrowserItIsNot(): array
    {
        return [
            'Chrome' => ['Chrome'],
            'AppleWebKit' => ['AppleWebKit'],
            'Safari' => ['Safari'],
            'Windows' => ['Windows NT'],
        ];
    }

    #[Test]
    public function nothing_in_it_says_which_reader_asked(): void
    {
        $this->assertNull($this->header('cookie'));
        $this->assertNull($this->header('referer'));
        $this->assertNull($this->header('x-forwarded-for'));
    }

    #[Test]
    public function compression_is_left_to_the_client(): void
    {
        $this->assertNull($this->header('accept-encoding'));
    }

    private function header(string $name): ?string
    {
        $this->fetcher([self::html('<html><head><title>A page</title></head></html>')])->fetch('https://example.com/a');

        $this->assertNotEmpty($this->requests, 'the fetcher never sent a request');

        $headers = $this->requests[0]['options']['normalized_headers'];

        return isset($headers[$name][0]) ? substr($headers[$name][0], strlen($name) + 2) : null;
    }
}
