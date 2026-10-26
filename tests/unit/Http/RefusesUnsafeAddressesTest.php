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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;

class RefusesUnsafeAddressesTest extends TestCase
{
    use BuildsAFetcher;

    #[Test]
    #[DataProvider('addressesInsideTheNetwork')]
    public function a_host_that_only_answers_inside_this_network_is_refused(string $address): void
    {
        $fetcher = $this->fetcher([self::page()], ['inside.test' => $address]);

        $this->expectException(UnsafeUrlException::class);

        try {
            $fetcher->fetch('https://inside.test/a');
        } finally {
            $this->assertRequestsMade(0);
        }
    }

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
            'the IPv4 loopback written as an IPv6 address' => ['::ffff:127.0.0.1'],
            'carrier grade NAT' => ['100.64.0.1'],
            'the far end of carrier grade NAT' => ['100.127.255.254'],
            'protocol assignments' => ['192.0.0.1'],
            'Oracle Cloud metadata' => ['192.0.0.192'],
            'benchmarking' => ['198.18.0.1'],
            'the far end of benchmarking' => ['198.19.255.254'],
            'reserved for the future' => ['240.0.0.1'],
            'the broadcast address' => ['255.255.255.255'],
            'IPv6 site local' => ['fec0::1'],
            'the far end of IPv6 site local' => ['feff:ffff::1'],
            'multicast' => ['239.255.255.250'],
            'IPv6 multicast' => ['ff02::1'],
            'NAT64' => ['64:ff9b::7f00:1'],
            '6to4' => ['2002:7f00:1::'],
            'IPv4-compatible IPv6' => ['::a00:5'],
        ];
    }

    #[Test]
    public function the_loopback_written_as_an_ipv6_address_in_the_url_is_refused_too(): void
    {
        $fetcher = $this->fetcher([self::page()]);

        $this->expectException(UnsafeUrlException::class);

        try {
            $fetcher->fetch('http://[::ffff:127.0.0.1]/admin');
        } finally {
            $this->assertRequestsMade(0);
        }
    }

    #[Test]
    public function an_address_written_straight_into_the_url_is_checked_too(): void
    {
        $fetcher = $this->fetcher([self::page()]);

        $this->expectException(UnsafeUrlException::class);

        $fetcher->fetch('http://169.254.169.254/latest/meta-data/');
    }

    #[Test]
    #[DataProvider('portsThePublicWebIsNotServedOn')]
    public function a_port_that_is_not_the_default_for_the_scheme_is_refused(string $url): void
    {
        $fetcher = $this->fetcher([self::page()]);

        $this->expectException(UnsafeUrlException::class);

        try {
            $fetcher->fetch($url);
        } finally {
            $this->assertRequestsMade(0);
        }
    }

    public static function portsThePublicWebIsNotServedOn(): array
    {
        return [
            'redis' => ['http://example.com:6379/a'],
            'a database' => ['http://example.com:3306/a'],
            'ssh' => ['http://example.com:22/a'],
            'smtp' => ['http://example.com:25/a'],
            'a development server' => ['http://example.com:3000/a'],
            'an application server' => ['http://example.com:9000/a'],
            'port one' => ['http://example.com:1/a'],
        ];
    }

    #[Test]
    public function the_two_alternate_web_ports_are_allowed(): void
    {
        $this->assertInstanceOf(FetchResult::class, $this->fetcher([self::page()])->fetch('http://example.com:8080/a'));
        $this->assertInstanceOf(FetchResult::class, $this->fetcher([self::page()])->fetch('https://example.com:8443/a'));
    }

    #[Test]
    public function the_default_port_written_out_is_still_the_default(): void
    {
        $this->assertInstanceOf(FetchResult::class, $this->fetcher([self::page()])->fetch('https://example.com:443/a'));
        $this->assertInstanceOf(FetchResult::class, $this->fetcher([self::page()])->fetch('http://example.com:80/a'));
    }

    #[Test]
    #[DataProvider('schemesThatAreNotTheWeb')]
    public function a_scheme_that_is_not_http_is_refused(string $url): void
    {
        $fetcher = $this->fetcher([self::page()]);

        $this->expectException(UnsafeUrlException::class);

        try {
            $fetcher->fetch($url);
        } finally {
            $this->assertRequestsMade(0);
        }
    }

    public static function schemesThatAreNotTheWeb(): array
    {
        return [
            'ftp' => ['ftp://example.com/a'],
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://example.com:70/1'],
            'dict' => ['dict://127.0.0.1:6379/info'],
            'no scheme at all' => ['example.com/a'],
        ];
    }

    #[Test]
    #[DataProvider('redirectsThatAreNotFollowed')]
    public function a_redirect_is_checked_like_the_first_request(string $location): void
    {
        $fetcher = $this->fetcher([self::redirect($location), self::page()], ['inside.test' => '10.0.0.5']);

        $this->expectException(UnsafeUrlException::class);

        try {
            $fetcher->fetch('https://public.test/a');
        } finally {
            $this->assertRequestsMade(1);
        }
    }

    public static function redirectsThatAreNotFollowed(): array
    {
        return [
            'an address inside the network' => ['http://169.254.169.254/latest/meta-data/'],
            'a name that resolves inside the network' => ['https://inside.test/admin'],
            'another port' => ['http://example.com:6379/a'],
            'another scheme' => ['file:///etc/passwd'],
        ];
    }

    #[Test]
    public function a_batch_refuses_one_url_without_giving_up_on_the_rest(): void
    {
        $fetcher = $this->fetcher([self::page()], ['inside.test' => '192.168.0.9']);

        $results = $fetcher->fetchMany(['https://inside.test/a', 'https://public.test/b']);

        $this->assertInstanceOf(UnsafeUrlException::class, $results['https://inside.test/a']);
        $this->assertInstanceOf(FetchResult::class, $results['https://public.test/b']);
    }

    private static function page(): MockResponse
    {
        return self::html('<html><head><title>Secret</title></head></html>');
    }
}
