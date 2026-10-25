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

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class FiltersAddressesTest extends TestCase
{
    use PreviewsLinks;
    use RetrievesAuthorizedUsers;

    private const PUBLIC_ADDRESS = '93.184.216.34';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('datlechin-link-preview');
        $this->isolateTheNetwork();

        $this->prepareDatabase([
            'users' => [$this->normalUser()],
        ]);
    }

    #[Test]
    public function something_that_is_not_an_address_is_reported_as_invalid(): void
    {
        $response = $this->preview('not a url');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('invalid_url', $this->data($response)['error']);
        $this->assertSame([], $this->web->requested);

        $this->assertSame('', $this->data($response)['url']);
    }

    #[Test]
    public function an_address_that_is_reported_back_is_one_the_page_can_safely_link_to(): void
    {
        $this->web->host('gone.test', self::PUBLIC_ADDRESS);

        $this->assertSame('https://gone.test/a', $this->data($this->preview('https://gone.test/a'))['url']);

        $this->assertSame('', $this->data($this->preview('javascript:alert(1)'))['url']);
        $this->assertSame('', $this->data($this->preview('data:text/html,<script>alert(1)</script>'))['url']);
    }

    #[Test]
    public function a_scheme_the_forum_does_not_fetch_is_reported_as_invalid(): void
    {
        $this->assertSame('invalid_url', $this->data($this->preview('ftp://example.test/notes.txt'))['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_blocklisted_host_is_refused_before_anything_leaves_the_server(): void
    {
        $this->setting('datlechin-link-preview.blocklist', "tracker.test\nads.test");
        $this->web->host('tracker.test', self::PUBLIC_ADDRESS)
            ->page('https://tracker.test/pixel', self::article());

        $response = $this->preview('https://tracker.test/pixel');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('blocked', $this->data($response)['error']);

        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_blocked_host_covers_its_subdomains(): void
    {
        $this->setting('datlechin-link-preview.blocklist', 'tracker.test');
        $this->web->host('cdn.tracker.test', self::PUBLIC_ADDRESS);

        $this->assertSame('blocked', $this->data($this->preview('https://cdn.tracker.test/pixel'))['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function an_allowlist_shuts_out_every_host_it_does_not_name(): void
    {
        $this->setting('datlechin-link-preview.allowlist', 'good.test');
        $this->web->host('other.test', self::PUBLIC_ADDRESS)
            ->page('https://other.test/a', self::article());

        $this->assertSame('blocked', $this->data($this->preview('https://other.test/a'))['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function an_allowlisted_host_is_still_previewed(): void
    {
        $this->setting('datlechin-link-preview.allowlist', 'good.test');
        $this->web->host('good.test', self::PUBLIC_ADDRESS)
            ->page('https://good.test/a', self::article());

        $data = $this->data($this->preview('https://good.test/a'));

        $this->assertSame('link', $data['type']);
        $this->assertSame('A perfectly ordinary article', $data['title']);
        $this->assertSame(['https://good.test/a'], $this->web->requested);
    }

    #[Test]
    #[DataProvider('otherWaysToWriteTheSameHost')]
    public function a_blocked_host_is_blocked_however_the_address_spells_it(string $url): void
    {
        $this->setting('datlechin-link-preview.blocklist', 'tracker.test');
        $this->web->host('tracker.test', self::PUBLIC_ADDRESS)
            ->page($url, self::article());

        $this->assertSame('blocked', $this->data($this->preview($url))['error']);
        $this->assertSame([], $this->web->requested);
    }

    public static function otherWaysToWriteTheSameHost(): array
    {
        return [
            'with a username in front of it' => ['https://someone@tracker.test/pixel'],
            'with a username and a password' => ['https://someone:hunter2@tracker.test/pixel'],
            'with a port after it' => ['https://tracker.test:8443/pixel'],
            'as the fully qualified name it really is' => ['https://tracker.test./pixel'],
            'in capitals' => ['https://TRACKER.TEST/pixel'],
            'with the www an entry did not have' => ['https://www.tracker.test/pixel'],
        ];
    }

    #[Test]
    public function a_blocklisted_host_is_still_blocked_one_redirect_away(): void
    {
        $this->setting('datlechin-link-preview.blocklist', 'tracker.test');

        $this->web->host('hop.test', self::PUBLIC_ADDRESS)
            ->host('tracker.test', self::PUBLIC_ADDRESS)
            ->redirect('https://hop.test/go', 'https://tracker.test/pixel')
            ->page('https://tracker.test/pixel', self::article());

        $this->assertSame('blocked', $this->data($this->preview('https://hop.test/go'))['error']);

        $this->assertSame(
            ['https://hop.test/go'],
            $this->web->requested,
            'the blocked host must be refused before the connection, not after the answer',
        );
    }

    #[Test]
    public function an_allowlist_still_applies_one_redirect_away(): void
    {
        $this->setting('datlechin-link-preview.allowlist', 'good.test');

        $this->web->host('good.test', self::PUBLIC_ADDRESS)
            ->host('elsewhere.test', self::PUBLIC_ADDRESS)
            ->redirect('https://good.test/go', 'https://elsewhere.test/a')
            ->page('https://elsewhere.test/a', self::article());

        $this->assertSame('blocked', $this->data($this->preview('https://good.test/go'))['error']);
        $this->assertSame(
            ['https://good.test/go'],
            $this->web->requested,
            'an allowlist is a list of hosts the forum may connect to, so the hop off it must not be made',
        );
    }

    #[Test]
    public function a_redirect_that_stays_inside_the_allowlist_is_followed(): void
    {
        $this->setting('datlechin-link-preview.allowlist', 'good.test');

        $this->web->host('good.test', self::PUBLIC_ADDRESS)
            ->redirect('https://good.test/go', 'https://good.test/article')
            ->page('https://good.test/article', self::article());

        $data = $this->data($this->preview('https://good.test/go'));

        $this->assertSame('A perfectly ordinary article', $data['title']);
        $this->assertSame('https://good.test/article', $data['url']);
    }

    #[Test]
    public function a_loopback_address_is_refused(): void
    {
        $response = $this->preview('http://127.0.0.1/admin');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('unsafe_address', $this->data($response)['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_private_address_is_refused(): void
    {
        $this->assertSame('unsafe_address', $this->data($this->preview('http://192.168.1.1/setup'))['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_public_name_pointing_into_the_private_network_is_refused(): void
    {
        $this->web->host('holiday-photos.test', '10.0.0.5');

        $this->assertSame('unsafe_address', $this->data($this->preview('https://holiday-photos.test/'))['error']);
        $this->assertSame([], $this->web->requested);
    }

    #[Test]
    public function a_host_that_does_not_resolve_at_all_is_refused(): void
    {
        $this->assertSame('unsafe_address', $this->data($this->preview('https://nowhere.test/'))['error']);
        $this->assertSame([], $this->web->requested);
    }
}
