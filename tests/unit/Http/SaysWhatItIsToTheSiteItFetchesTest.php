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
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the forum tells a site about itself when it asks for a page.
 *
 * The version this replaces sent a Chrome user agent string, which is a lie
 * that costs the operator of every previewed site the ability to see this
 * traffic for what it is: a robot, run by a forum, on behalf of somebody who
 * pasted a link. A site that wants to serve it something else, rate limit it or
 * refuse it has a right to be able to, and a `robots.txt` rule aimed at it has
 * to have a name to aim at.
 */
class SaysWhatItIsToTheSiteItFetchesTest extends TestCase
{
    use BuildsAFetcher;

    #[Test]
    public function the_forum_names_itself_rather_than_claiming_to_be_a_browser(): void
    {
        $this->fetch();

        $this->assertMatchesRegularExpression('~link[ _-]?preview~i', $this->userAgent());
    }

    #[Test]
    #[DataProvider('theBrowserItIsNot')]
    public function nothing_in_it_claims_to_be_a_person_at_a_browser(string $token): void
    {
        $this->fetch();

        $this->assertStringNotContainsStringIgnoringCase($token, $this->userAgent());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function theBrowserItIsNot(): array
    {
        return [
            'the browser it used to name' => ['Chrome'],
            'the engine underneath it' => ['AppleWebKit'],
            'the other name that engine answers to' => ['Safari'],
            'the desktop it claimed to be running on' => ['Windows NT'],
        ];
    }

    #[Test]
    public function nothing_in_it_says_which_reader_asked(): void
    {
        // A preview is triggered by whoever is reading the post, so anything
        // carried from that request would tell the site being previewed who is
        // on this forum and when.
        $this->fetch();

        $request = $this->handler->getLastRequest();

        $this->assertNotNull($request);
        $this->assertSame([], $request->getHeader('Cookie'));
        $this->assertSame([], $request->getHeader('Referer'));
        $this->assertSame([], $request->getHeader('X-Forwarded-For'));
    }

    private function fetch(): void
    {
        $fetcher = $this->fetcher([
            new Response(200, ['Content-Type' => 'text/html'], '<html><head><title>A page</title></head></html>'),
        ]);

        $fetcher->fetch('https://example.com/a');
    }

    private function userAgent(): string
    {
        $request = $this->handler->getLastRequest();

        $this->assertNotNull($request, 'the fetcher never sent a request');

        $agent = $request->getHeaderLine('User-Agent');

        $this->assertNotSame('', $agent, 'a request with no user agent is not an honest one either');

        return $agent;
    }
}
