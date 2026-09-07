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

use Datlechin\LinkPreview\Http\Exception\UnsafeUrlException;
use Datlechin\LinkPreview\Http\FetchResult;
use Flarum\Testing\unit\TestCase;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The security boundary of the extension, exercised without a network.
 *
 * Any URL a member types is a URL the server can be made to request, so the
 * address behind the hostname is checked before the socket is opened rather
 * than the hostname being trusted. Every case here is one the real resolver
 * cannot be asked to produce, which is exactly why the resolver is a seam.
 */
class RefusesUnsafeAddressesTest extends TestCase
{
    use BuildsAFetcher;

    private function page(): Response
    {
        return new Response(200, ['Content-Type' => 'text/html'], '<html><head><title>Secret</title></head></html>');
    }

    #[Test]
    #[DataProvider('addressesInsideTheNetwork')]
    public function a_host_that_only_answers_inside_this_network_is_refused(string $address): void
    {
        $fetcher = $this->fetcher([$this->page()], new FakeResolver(['public.test' => [$address]]));

        $this->expectException(UnsafeUrlException::class);

        try {
            $fetcher->fetch('https://public.test/a');
        } finally {
            $this->assertResponsesLeft(1);
        }
    }

    /**
     * The ranges PHP's own reserved and private flags cover, and the ones they
     * do not.
     *
     * `FILTER_FLAG_NO_RES_RANGE` knows about loopback, link local, this
     * network and the future-use block; `FILTER_FLAG_NO_PRIV_RANGE` knows
     * about RFC1918 and `fc00::/7`. Neither has heard of carrier grade NAT,
     * the protocol assignments block, the benchmarking block or IPv6 site
     * local, and every one of those is routed inside somebody's network. They
     * are listed here alongside the ones PHP handles so that a rewrite of the
     * check cannot quietly drop half of them.
     *
     * @return array<string, array{string}>
     */
    public static function addressesInsideTheNetwork(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'loopback anywhere in the range' => ['127.99.1.5'],
            'IPv6 loopback' => ['::1'],
            'link local' => ['169.254.10.1'],
            'IPv6 link local' => ['fe80::1'],
            'RFC1918 ten' => ['10.0.0.5'],
            'RFC1918 one seven two' => ['172.16.0.1'],
            'RFC1918 one nine two' => ['192.168.1.1'],
            'this network' => ['0.0.0.0'],
            'anything else in this network' => ['0.1.2.3'],
            'IPv6 unique local' => ['fc00::1'],

            // The loopback address the operating system will happily connect
            // to, wearing the notation that gets it past a check written for
            // dotted quads.
            'the IPv4 loopback written as an IPv6 address' => ['::ffff:127.0.0.1'],

            // A carrier hands these to its own subscribers, so on a hosted
            // forum they are other people's machines on the same private path.
            'carrier grade NAT' => ['100.64.0.1'],
            'the far end of carrier grade NAT' => ['100.127.255.254'],

            // 192.0.0.0/24 is IETF protocol assignments and 198.18.0.0/15 is
            // the benchmarking block, both routed on plenty of internal
            // networks and neither of them anybody's web site.
            'protocol assignments' => ['192.0.0.1'],
            'the far end of protocol assignments' => ['192.0.0.255'],
            'benchmarking' => ['198.18.0.1'],
            'the far end of benchmarking' => ['198.19.255.254'],

            'reserved for the future' => ['240.0.0.1'],
            'the broadcast address' => ['255.255.255.255'],
            'IPv6 site local' => ['fec0::1'],
            'the far end of IPv6 site local' => ['feff:ffff::1'],
        ];
    }

    #[Test]
    public function the_loopback_written_as_an_ipv6_address_in_the_url_is_refused_too(): void
    {
        // Keyed both ways, because whether the brackets are trimmed before the
        // lookup is the fetcher's business and not what this is testing.
        $fetcher = $this->fetcher([$this->page()], new FakeResolver([
            '[::ffff:127.0.0.1]' => ['::ffff:127.0.0.1'],
            '::ffff:127.0.0.1' => ['::ffff:127.0.0.1'],
        ]));

        $this->expectException(UnsafeUrlException::class);

        try {
            $fetcher->fetch('http://[::ffff:127.0.0.1]/admin');
        } finally {
            $this->assertResponsesLeft(1);
        }
    }

    #[Test]
    #[DataProvider('portsThePublicWebIsNotServedOn')]
    public function a_port_that_is_not_the_default_for_the_scheme_is_refused(string $url): void
    {
        // The address check says which machines the forum may talk to. This
        // says which door: a public host is still a way to knock on every
        // service its operator runs, and a link preview has no business
        // anywhere but the web port.
        $fetcher = $this->fetcher([$this->page()]);

        $this->expectException(UnsafeUrlException::class);

        try {
            $fetcher->fetch($url);
        } finally {
            $this->assertResponsesLeft(1);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function portsThePublicWebIsNotServedOn(): array
    {
        return [
            'redis' => ['http://example.com:6379/a'],
            'a database' => ['http://example.com:3306/a'],
            'ssh' => ['http://example.com:22/a'],
            'the mail port a text protocol can be smuggled into' => ['http://example.com:25/a'],
            'a development server' => ['http://example.com:3000/a'],
            'an application server behind the proxy' => ['http://example.com:9000/a'],
            'the port a scan would walk' => ['http://example.com:1/a'],
        ];
    }

    #[Test]
    public function the_two_ports_a_real_site_is_sometimes_served_on_are_allowed(): void
    {
        // A rule nobody can satisfy is a rule administrators route around, and
        // 8080 and 8443 are ordinary enough that refusing them would cost more
        // than it saves.
        $this->assertInstanceOf(FetchResult::class, $this->fetcher([$this->page()])->fetch('http://example.com:8080/a'));
        $this->assertInstanceOf(FetchResult::class, $this->fetcher([$this->page()])->fetch('https://example.com:8443/a'));
    }

    #[Test]
    public function a_redirect_to_another_port_is_not_followed(): void
    {
        $fetcher = $this->fetcher([
            new Response(302, ['Location' => 'http://example.com:6379/a']),
            $this->page(),
        ]);

        $this->expectException(UnsafeUrlException::class);

        try {
            $fetcher->fetch('https://public.test/a');
        } finally {
            $this->assertResponsesLeft(1);
        }
    }

    #[Test]
    public function the_default_port_written_out_in_full_is_still_the_default(): void
    {
        // `https://example.com:443/` is the same address as
        // `https://example.com/`, and a site that writes it out is not doing
        // anything a reader should be shown an error for.
        $this->assertInstanceOf(FetchResult::class, $this->fetcher([$this->page()])->fetch('https://example.com:443/a'));
        $this->assertInstanceOf(FetchResult::class, $this->fetcher([$this->page()])->fetch('http://example.com:80/a'));
    }

    #[Test]
    public function the_cloud_metadata_endpoint_is_refused(): void
    {
        // The address every cloud host answers on with the credentials of the
        // machine the forum is running on.
        $fetcher = $this->fetcher([$this->page()], new FakeResolver(['metadata.test' => ['169.254.169.254']]));

        $this->expectException(UnsafeUrlException::class);

        $fetcher->fetch('http://metadata.test/latest/meta-data/iam/security-credentials/');
    }

    #[Test]
    public function an_address_written_straight_into_the_url_is_checked_too(): void
    {
        $fetcher = $this->fetcher([$this->page()], new FakeResolver(['169.254.169.254' => ['169.254.169.254']]));

        $this->expectException(UnsafeUrlException::class);

        $fetcher->fetch('http://169.254.169.254/latest/meta-data/');
    }

    #[Test]
    public function a_host_that_does_not_resolve_is_refused(): void
    {
        $fetcher = $this->fetcher([$this->page()], new FakeResolver(['nowhere.test' => []]));

        $this->expectException(UnsafeUrlException::class);

        try {
            $fetcher->fetch('https://nowhere.test/a');
        } finally {
            $this->assertResponsesLeft(1);
        }
    }

    #[Test]
    public function the_first_routable_address_is_the_one_used(): void
    {
        // A host with a private address alongside a public one is ordinary in
        // split-horizon DNS, and is not a reason to refuse the whole host.
        $fetcher = $this->fetcher(
            [$this->page()],
            new FakeResolver(['mixed.test' => ['10.0.0.5', FakeResolver::PUBLIC_ADDRESS]]),
        );

        $result = $fetcher->fetch('https://mixed.test/a');

        $this->assertStringContainsString('Secret', $result->body);
        $this->assertResponsesLeft(0);
    }

    #[Test]
    #[DataProvider('schemesThatAreNotTheWeb')]
    public function a_scheme_that_is_not_http_is_refused(string $url): void
    {
        $fetcher = $this->fetcher([$this->page()]);

        $this->expectException(UnsafeUrlException::class);

        try {
            $fetcher->fetch($url);
        } finally {
            $this->assertResponsesLeft(1);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function schemesThatAreNotTheWeb(): array
    {
        return [
            'ftp' => ['ftp://example.com/a'],
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://example.com:70/1'],
            'a redis payload dressed as a URL' => ['dict://127.0.0.1:6379/info'],
            'no scheme at all' => ['example.com/a'],
        ];
    }

    #[Test]
    public function a_redirect_into_the_network_is_not_followed(): void
    {
        // Guzzle would revalidate nothing on a redirect, so every hop is
        // checked the same way the first one was. The queued page proves the
        // second request was never made.
        $fetcher = $this->fetcher(
            [
                new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
                $this->page(),
            ],
            new FakeResolver(['169.254.169.254' => ['169.254.169.254']]),
        );

        $this->expectException(UnsafeUrlException::class);

        try {
            $fetcher->fetch('https://public.test/a');
        } finally {
            $this->assertResponsesLeft(1);
        }
    }

    #[Test]
    public function a_redirect_that_changes_to_a_scheme_that_is_not_the_web_is_not_followed(): void
    {
        $fetcher = $this->fetcher([
            new Response(302, ['Location' => 'file:///etc/passwd']),
            $this->page(),
        ]);

        $this->expectException(UnsafeUrlException::class);

        try {
            $fetcher->fetch('https://public.test/a');
        } finally {
            $this->assertResponsesLeft(1);
        }
    }

    #[Test]
    public function a_batch_refuses_one_url_without_giving_up_on_the_rest(): void
    {
        $fetcher = $this->fetcher(
            [$this->page()],
            new FakeResolver(['inside.test' => ['192.168.0.9']]),
        );

        $results = $fetcher->fetchMany(['https://inside.test/a', 'https://public.test/b']);

        $this->assertInstanceOf(UnsafeUrlException::class, $results['https://inside.test/a']);
        $this->assertInstanceOf(FetchResult::class, $results['https://public.test/b']);
        $this->assertSame('https://public.test/b', $results['https://public.test/b']->effectiveUrl);
    }
}
