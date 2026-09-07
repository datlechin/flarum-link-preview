<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Tests\unit\Preview;

use Datlechin\LinkPreview\Preview\UrlFilter;
use Flarum\Testing\unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * An administrator's list names sites, so it is matched against sites: every
 * entry is anchored at both ends, and a `*` in the host cannot cross a dot.
 */
class MatchesTheAllowAndBlockListsTest extends TestCase
{
    #[Test]
    public function empty_lists_allow_everything(): void
    {
        $filter = new UrlFilter([], []);

        $this->assertTrue($filter->allows('https://example.com/article'));
        $this->assertTrue($filter->allows('http://192.0.2.10:8080/page?x=1'));
        $this->assertTrue($filter->allows('https://любой.example/статья'));
    }

    #[Test]
    #[DataProvider('coveredByTheHostEntry')]
    public function a_host_entry_covers_the_host_and_everything_under_it(string $url): void
    {
        $this->assertFalse((new UrlFilter([], ['example.com']))->allows($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function coveredByTheHostEntry(): array
    {
        return [
            'the host itself' => ['https://example.com'],
            'with a path' => ['https://example.com/blog/post'],
            'with a query' => ['https://example.com/search?q=flarum'],
            'over plain http' => ['http://example.com/'],
            'with the www prefix the entry did not have' => ['https://www.example.com/'],
            'a subdomain' => ['https://cdn.example.com/card.png'],
            'a subdomain of a subdomain' => ['https://a.b.example.com/x'],
            'in capitals' => ['https://EXAMPLE.COM/X'],
        ];
    }

    #[Test]
    #[DataProvider('notCoveredByTheHostEntry')]
    public function a_host_entry_covers_nothing_else(string $url): void
    {
        $this->assertTrue((new UrlFilter([], ['example.com']))->allows($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notCoveredByTheHostEntry(): array
    {
        return [
            'another domain entirely' => ['https://example.org/article'],
            'a host that merely ends the same way' => ['https://notexample.com/article'],
            'a host that starts with it' => ['https://example.com.evil.test/article'],
            'the name in a path' => ['https://other.test/go?to=example.com'],
        ];
    }

    #[Test]
    public function an_entry_no_longer_matches_wherever_it_appears_in_the_url(): void
    {
        $filter = new UrlFilter([], ['com']);

        $this->assertTrue($filter->allows('https://welcome.test/page'));
        $this->assertTrue($filter->allows('https://example.org/comments'));
        $this->assertTrue($filter->allows('https://uncommon.net/'));
    }

    #[Test]
    public function a_bare_entry_is_still_read_as_a_host(): void
    {
        // A single label is a legal host, so `com` covers every `.com` address
        // under the subdomain rule. This contradicts the contract's claim that
        // `com` no longer blocks `example.com`; the code here is the truth.
        $this->assertFalse((new UrlFilter([], ['com']))->allows('https://example.com/x'));
    }

    #[Test]
    public function a_name_ending_in_the_entry_is_not_a_subdomain_of_it(): void
    {
        $filter = new UrlFilter([], ['evil.com']);

        $this->assertTrue($filter->allows('https://notevil.com.example.org/a'));
        $this->assertTrue($filter->allows('https://notevil.com/a'));
        $this->assertFalse($filter->allows('https://evil.com/a'));
        $this->assertFalse($filter->allows('https://mail.evil.com/a'));
    }

    #[Test]
    #[DataProvider('otherWaysToWriteTheSameHost')]
    public function the_host_is_read_from_the_address_rather_than_from_the_text(string $url): void
    {
        // Userinfo, a port and a trailing dot all reach the same site, so a
        // filter that string-matched the text would be walked past by typing.
        $this->assertFalse((new UrlFilter([], ['example.com']))->allows($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherWaysToWriteTheSameHost(): array
    {
        return [
            'with a username in front of it' => ['https://someone@example.com/a'],
            'with a username and a password' => ['https://someone:hunter2@example.com/a'],
            'with something that looks like a host as the username' => ['https://example.org@example.com/a'],
            'with a port after it' => ['https://example.com:8443/a'],
            'with the default port written out' => ['https://example.com:443/a'],
            'as the fully qualified name it really is' => ['https://example.com./a'],
            'fully qualified and on a port' => ['https://example.com.:8443/a'],
            'fully qualified under a subdomain' => ['https://cdn.example.com./a'],
            'with a username and a port together' => ['https://someone@example.com:8443/a'],
        ];
    }

    #[Test]
    public function an_entry_written_with_a_trailing_dot_names_the_same_host(): void
    {
        $filter = new UrlFilter([], ['example.com.']);

        $this->assertFalse($filter->allows('https://example.com/a'));
        $this->assertFalse($filter->allows('https://example.com./a'));
        $this->assertTrue($filter->allows('https://example.org/a'));
    }

    #[Test]
    public function a_username_that_names_a_blocked_host_does_not_block_the_address(): void
    {
        // This address goes to example.com, so a filter reading the userinfo
        // as a host would refuse the wrong site.
        $this->assertTrue((new UrlFilter([], ['tracker.test']))->allows('https://tracker.test@example.com/a'));
    }

    #[Test]
    public function an_entry_with_a_path_matches_only_at_a_path_boundary(): void
    {
        $filter = new UrlFilter([], ['example.com/blog']);

        $this->assertFalse($filter->allows('https://example.com/blog'));
        $this->assertFalse($filter->allows('https://example.com/blog/'));
        $this->assertFalse($filter->allows('https://example.com/blog/post'));
        $this->assertFalse($filter->allows('https://example.com/blog?page=2'));

        $this->assertTrue($filter->allows('https://example.com/blogger'));
        $this->assertTrue($filter->allows('https://example.com/'));
        $this->assertTrue($filter->allows('https://example.com/news/blog'));
    }

    #[Test]
    public function a_path_entry_is_matched_against_the_path_and_nothing_after_it(): void
    {
        // A query string is where somebody else's address ends up and a
        // fragment never reaches the server, so neither is part of a rule.
        $filter = new UrlFilter([], ['example.com/blog']);

        $this->assertFalse($filter->allows('https://example.com/blog#introduction'));
        $this->assertFalse($filter->allows('https://example.com/blog/post#introduction'));

        $this->assertTrue($filter->allows('https://example.com/search?q=/blog'));
        $this->assertTrue($filter->allows('https://example.com/news#/blog'));
    }

    #[Test]
    public function a_path_entry_is_matched_in_one_case(): void
    {
        // An administrator writing a rule cannot know which case a link will
        // arrive in, and a host is already case insensitive.
        $filter = new UrlFilter([], ['example.com/Blog']);

        $this->assertFalse($filter->allows('https://example.com/blog/post'));
        $this->assertFalse($filter->allows('https://EXAMPLE.com/BLOG'));
    }

    #[Test]
    public function an_entry_with_a_path_does_not_reach_into_subdomains(): void
    {
        // A path only means something on the host it was written for, so this
        // entry is not the shorthand for a site that the bare host entry is.
        $filter = new UrlFilter([], ['example.com/blog']);

        $this->assertTrue($filter->allows('https://cdn.example.com/blog'));
    }

    #[Test]
    public function a_wildcard_in_the_host_does_not_cross_a_dot(): void
    {
        $filter = new UrlFilter([], ['evil*.test']);

        $this->assertFalse($filter->allows('https://evilcorp.test/a'));
        $this->assertFalse($filter->allows('https://evil123.test/a'));
        $this->assertTrue($filter->allows('https://evil.co.test/a'));
        $this->assertTrue($filter->allows('https://notevil.test/a'));
    }

    #[Test]
    public function a_wildcard_label_matches_one_label(): void
    {
        $filter = new UrlFilter([], ['*.example.com']);

        $this->assertFalse($filter->allows('https://cdn.example.com/a'));
        $this->assertFalse($filter->allows('https://a.b.example.com/a'));
        $this->assertTrue($filter->allows('https://example.com/a'));
    }

    #[Test]
    public function a_wildcard_in_a_path_may_cross_a_slash(): void
    {
        $filter = new UrlFilter([], ['example.com/*/edit']);

        $this->assertFalse($filter->allows('https://example.com/posts/1/edit'));
        $this->assertTrue($filter->allows('https://example.com/edit'));
        $this->assertTrue($filter->allows('https://example.com/posts/1/editor'));
    }

    #[Test]
    public function a_pattern_is_anchored_at_both_ends(): void
    {
        $filter = new UrlFilter([], ['*.example.com']);

        $this->assertTrue($filter->allows('https://cdn.example.com.evil.test/a'));
        $this->assertTrue($filter->allows('https://prefixcdn.example.org/a'));
    }

    #[Test]
    public function an_entry_written_as_a_url_means_the_same_as_a_host(): void
    {
        $filter = new UrlFilter([], ['HTTPS://WWW.Example.COM/']);

        $this->assertFalse($filter->allows('http://example.com/page'));
        $this->assertFalse($filter->allows('https://www.example.com'));
        $this->assertTrue($filter->allows('https://example.org/page'));
    }

    #[Test]
    public function an_empty_entry_matches_nothing(): void
    {
        // A trailing comma in the settings field is not a rule to block the
        // whole forum with.
        $filter = new UrlFilter([], ['', '   ']);

        $this->assertTrue($filter->allows('https://example.com/a'));
    }

    #[Test]
    public function an_allowlist_shuts_out_everything_it_does_not_name(): void
    {
        $filter = new UrlFilter(['example.com', 'docs.other.test'], []);

        $this->assertTrue($filter->allows('https://example.com/a'));
        $this->assertTrue($filter->allows('https://cdn.example.com/a'));
        $this->assertTrue($filter->allows('https://docs.other.test/a'));
        $this->assertFalse($filter->allows('https://other.test/a'));
        $this->assertFalse($filter->allows('https://elsewhere.test/a'));
    }

    #[Test]
    public function the_blocklist_carves_holes_in_the_allowlist(): void
    {
        $filter = new UrlFilter(['example.com'], ['secret.example.com', 'example.com/admin']);

        $this->assertTrue($filter->allows('https://example.com/blog/post'));
        $this->assertFalse($filter->allows('https://secret.example.com/a'));
        $this->assertFalse($filter->allows('https://example.com/admin/users'));
        $this->assertFalse($filter->allows('https://elsewhere.test/a'));
    }
}
