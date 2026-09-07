<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Http;

use Datlechin\LinkPreview\Http\Exception\FetchFailedException;
use Datlechin\LinkPreview\Http\Exception\LinkPreviewException;
use Datlechin\LinkPreview\Http\Exception\UnsafeUrlException;
use Datlechin\LinkPreview\Http\Exception\UnsupportedContentException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Redirects are followed here rather than by Guzzle, which would revalidate
 * nothing: `Location: http://169.254.169.254/` from a host that passed every
 * check is the whole attack, so every hop is checked the way the first was.
 */
final class SafeFetcher
{
    /**
     * Measured byte offset of `</head>`: GitHub 31KB, YouTube 713KB, which
     * inlines a very large script ahead of its meta tags. The read stops at
     * `</head>`, so a cap this high costs a normal page nothing.
     */
    public const MAX_BYTES = 1048576;

    public const MAX_REDIRECTS = 3;
    public const TIMEOUT = 8;
    public const CONNECT_TIMEOUT = 4;

    private const CHUNK_BYTES = 8192;

    /** The cURL options bound the transport; an injected client's is not ours to bound. */
    private const READ_SECONDS = 5;

    /**
     * A trickle, not a budget. TIMEOUT is the real bound; these only hang up
     * early on a connection that has stopped delivering. The allowance is
     * deliberately slack so a site that thinks for a few seconds before it
     * answers is not cut off.
     */
    private const MIN_BYTES_PER_SECOND = 256;
    private const STALL_SECONDS = 5;

    /** cURL's `CURLE_ABORTED_BY_CALLBACK`, which is defined only with ext-curl. */
    private const CURL_ABORTED_BY_CALLBACK = 42;

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private const ALTERNATE_PORTS = [8080, 8443];

    private const MAPPED_IPV4_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /**
     * Ranges `filter_var()` admits anyway. Its flags cover loopback, link local
     * and RFC1918, and the exact list beyond that moves between PHP builds.
     */
    private const BLOCKED_RANGES = [
        // The local host to most stacks, and Linux routes the rest there too.
        '0.0.0.0' => 8,
        // Carrier grade NAT: the ISP's own network, and cloud node networks.
        '100.64.0.0' => 10,
        // IETF protocol assignments: 192.0.0.192 and the rest of the addresses
        // infrastructure answers on rather than hosts.
        '192.0.0.0' => 24,
        // Benchmarking: test interfaces on network gear, reachable from inside.
        '198.18.0.0' => 15,
        // Reserved, including the 255.255.255.255 broadcast.
        '240.0.0.0' => 4,
        // IPv6 site local: deprecated, and not covered by the private flag.
        'fec0::' => 10,
        // NAT64: 64:ff9b::7f00:1 is loopback with an extra hop.
        '64:ff9b::' => 96,
        // Multicast: 239.255.255.250 is every UPnP device in the rack.
        '224.0.0.0' => 4,
        'ff00::' => 8,
        // 6to4: 2002:7f00:1:: is loopback behind a tunnel.
        '2002::' => 16,
        // IPv4-compatible IPv6: the reserved flag refuses `::10.0.0.5` and
        // admits `::a00:5`, the same sixteen bytes.
        '::' => 96,
    ];

    /**
     * Measured: facebook.com answers 400 to a desktop Chrome agent arriving
     * from a server and 200 to a bot that names itself.
     */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; FlarumLinkPreview/1.0; +https://github.com/datlechin/flarum-link-preview)';

    private readonly bool $capsBodyInSink;

    public function __construct(private Resolver $resolver, private ?ClientInterface $client = null)
    {
        $this->capsBodyInSink = $client === null && ! extension_loaded('curl');
    }

    /**
     * @throws LinkPreviewException
     */
    public function fetch(string $url, ?callable $guard = null): FetchResult
    {
        $result = $this->fetchMany([$url], $guard)[$url];

        if ($result instanceof LinkPreviewException) {
            throw $result;
        }

        return $result;
    }

    /**
     * @param list<string> $urls
     * @param null|callable(string): bool $guard run against every hop, redirect targets included
     *
     * @return array<string, FetchResult|LinkPreviewException> keyed by the input url
     */
    public function fetchMany(array $urls, ?callable $guard = null): array
    {
        $results = [];

        $pending = [];

        foreach ($urls as $url) {
            $pending[$url] = $url;
        }

        for ($hop = 0; $pending !== []; $hop++) {
            /** @var array<string, PromiseInterface> $promises */
            $promises = [];

            foreach ($pending as $requested => $url) {
                try {
                    $promises[$requested] = $this->client()->requestAsync('GET', $url, $this->optionsFor($url, $guard));
                } catch (LinkPreviewException $exception) {
                    $results[$requested] = $exception;
                } catch (MalformedUriException $exception) {
                    // `http://good.test:+80/a` satisfies parse_url and every
                    // check above, then is refused by Guzzle's URI parser here
                    // rather than inside the promise: uncaught it takes the
                    // rest of the batch with it.
                    $results[$requested] = new UnsafeUrlException("Refusing to fetch $url, which is not a URL.", 0, $exception);
                }
            }

            if ($promises === []) {
                break;
            }

            /** @var array<string, array{state: string, value?: mixed, reason?: mixed}> $settled */
            $settled = Utils::settle($promises)->wait();

            $next = [];

            foreach ($settled as $requested => $outcome) {
                $url = $pending[$requested];
                $reason = $outcome['reason'] ?? null;
                $response = $outcome['value'] ?? $this->cappedResponse($reason);

                if (! $response instanceof ResponseInterface) {
                    $results[$requested] = new FetchFailedException(
                        "Could not reach $url.",
                        0,
                        $reason instanceof Throwable ? $reason : null,
                    );

                    continue;
                }

                try {
                    $location = $this->redirectTarget($response, $url);

                    if ($location === null) {
                        $results[$requested] = $this->read($response, $url);

                        continue;
                    }

                    if ($hop >= self::MAX_REDIRECTS) {
                        throw new FetchFailedException("$url redirects more than ".self::MAX_REDIRECTS.' times.');
                    }

                    $next[$requested] = $location;
                } catch (LinkPreviewException $exception) {
                    $results[$requested] = $exception;
                }
            }

            $pending = $next;
        }

        $ordered = [];

        // $results is filled in the order rounds settled, not the caller's.
        foreach ($urls as $url) {
            $ordered[$url] = $results[$url];
        }

        return $ordered;
    }

    private function client(): ClientInterface
    {
        return $this->client ??= new Client($this->clientConfig());
    }

    /**
     * Left alone, Guzzle wraps the cURL handler in a streaming proxy whenever
     * `allow_url_fopen` is on, and that proxy's stream handler ignores the
     * `curl` option the CURLOPT_RESOLVE pin rides on, besides being synchronous.
     *
     * @return array<string, mixed>
     */
    private function clientConfig(): array
    {
        // Without cURL there is no pin to land. The address check still runs
        // before every hop, so what is missing is the rebinding window, not the rule.
        if (! extension_loaded('curl')) {
            return [];
        }

        return ['handler' => HandlerStack::create(new CurlMultiHandler())];
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsFor(string $url, ?callable $guard): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException("Refusing to fetch $url, which is not http or https.");
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new UnsafeUrlException("Refusing to fetch $url, which names no host.");
        }

        // Before the lookup, so a hop the caller refuses costs no DNS.
        if ($guard !== null && ! $guard($url)) {
            throw new UnsafeUrlException("Refusing to fetch $url, which the caller declined.");
        }

        $port = $this->allowedPortFor($url, $scheme);
        $ip = $this->allowedAddressFor($host);

        if ($ip === null) {
            throw new UnsafeUrlException("Refusing to fetch $url: $host has no address outside this network.");
        }

        $options = $this->options();

        if (defined('CURLOPT_RESOLVE')) {
            $options['curl'] = $this->curlOptions($host, $port, $ip);
        }

        return $options;
    }

    /**
     * `verify` is never turned off: an unverified TLS connection would let
     * anyone on the path choose what the forum caches under someone else's
     * domain.
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => true,
            'timeout' => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            // The transport's own allow list, below the scheme check above.
            'protocols' => ['http', 'https'],
            // The byte cap counts wire bytes, so a content encoding would let
            // a few compressed kilobytes expand past it.
            'decode_content' => false,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ];

        // Fresh per request: the same sink handed to two of them would interleave.
        if ($this->capsBodyInSink) {
            $options['sink'] = CappedSink::ofBytes(self::MAX_BYTES);
        }

        return $options;
    }

    /**
     * @return array<int, mixed>
     */
    private function curlOptions(string $host, int $port, string $ip): array
    {
        $options = [
            // Pin the address just validated, against a record that rebinds
            // between the lookup and the connect.
            CURLOPT_RESOLVE => [$this->pin($host, $port, $ip)],
            // A body arriving a few bytes at a time never trips the byte cap.
            CURLOPT_LOW_SPEED_LIMIT => self::MIN_BYTES_PER_SECOND,
            CURLOPT_LOW_SPEED_TIME => self::STALL_SECONDS,
        ];

        // cURL hands Guzzle the whole body before resolving the promise, so
        // only aborting the transfer can stop a gigabyte. Both options are
        // deprecated in guzzle 7.11 and rejected by 8.0, which points at the
        // `progress` option: not a replacement, because Guzzle throws away
        // what that callback returns.
        if (defined('CURLOPT_XFERINFOFUNCTION')) {
            $options[CURLOPT_NOPROGRESS] = false;
            $options[CURLOPT_XFERINFOFUNCTION] = static function (mixed $handle, int $expected, int $downloaded): int {
                // Anything but zero aborts the transfer.
                return $downloaded > self::MAX_BYTES ? 1 : 0;
            };
        }

        return $options;
    }

    /**
     * cURL matches on the host as the URL spells it, without brackets for an
     * IPv6 literal, and wants the address with them: `::1:443:[::1]`. Either
     * half the other way round is a pin that is not pinning.
     */
    private function pin(string $host, int $port, string $ip): string
    {
        $name = trim($host, '[]');
        $address = str_contains($ip, ':') ? "[$ip]" : $ip;

        return "$name:$port:$address";
    }

    /**
     * Left open, a posted link times the answer from any port of any host,
     * which maps what is listening from the forum's own address. Two ports
     * beyond the scheme default, because a real site is occasionally on one.
     */
    private function allowedPortFor(string $url, string $scheme): int
    {
        $default = $scheme === 'https' ? 443 : 80;
        $port = parse_url($url, PHP_URL_PORT);

        if (! is_int($port)) {
            return $default;
        }

        if ($port !== $default && ! in_array($port, self::ALTERNATE_PORTS, true)) {
            throw new UnsafeUrlException("Refusing to fetch $url, which names port $port.");
        }

        return $port;
    }

    private function allowedAddressFor(string $host): ?string
    {
        foreach ($this->resolver->resolve($host) as $ip) {
            $address = $this->routableAddress($ip);

            if ($address !== null) {
                return $address;
            }
        }

        return null;
    }

    private function routableAddress(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $ip = $this->unwrapMappedIpv4($ip);

        // Reserved covers loopback and link local, where the cloud metadata
        // endpoint at 169.254.169.254 lives; private covers the operator's LAN.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE) === false) {
            return null;
        }

        foreach (self::BLOCKED_RANGES as $subnet => $bits) {
            if ($this->isInRange($ip, $subnet, $bits)) {
                return null;
            }
        }

        return $ip;
    }

    /** `::ffff:127.0.0.1` is loopback in an IPv6 coat; the ranges above are per family. */
    private function unwrapMappedIpv4(string $ip): string
    {
        $packed = inet_pton($ip);

        if (! is_string($packed) || strlen($packed) !== 16 || ! str_starts_with($packed, self::MAPPED_IPV4_PREFIX)) {
            return $ip;
        }

        $unwrapped = inet_ntop(substr($packed, 12));

        return $unwrapped === false ? $ip : $unwrapped;
    }

    private function isInRange(string $ip, string $subnet, int $bits): bool
    {
        $address = inet_pton($ip);
        $network = inet_pton($subnet);

        if (! is_string($address) || ! is_string($network) || strlen($address) !== strlen($network)) {
            return false;
        }

        $whole = intdiv($bits, 8);
        $rest = $bits % 8;

        if (substr($address, 0, $whole) !== substr($network, 0, $whole)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = chr(0xFF << (8 - $rest) & 0xFF);

        return ($address[$whole] & $mask) === ($network[$whole] & $mask);
    }

    /**
     * An aborted transfer arrives as a rejection carrying the headers and the
     * bytes that did land, and the front of a document is all a preview wanted.
     */
    private function cappedResponse(mixed $reason): ?ResponseInterface
    {
        if (! $reason instanceof RequestException) {
            return null;
        }

        if (($reason->getHandlerContext()['errno'] ?? null) !== self::CURL_ABORTED_BY_CALLBACK) {
            return null;
        }

        $response = $reason->getResponse();

        if ($response === null) {
            return null;
        }

        // Guzzle rewinds only a body whose transfer finished.
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $response;
    }

    private function redirectTarget(ResponseInterface $response, string $url): ?string
    {
        if (! in_array($response->getStatusCode(), self::REDIRECT_STATUSES, true)) {
            return null;
        }

        // getHeaderLine() would join two Location headers into a third that is neither.
        $location = $response->getHeader('Location')[0] ?? '';

        if ($location === '') {
            return null;
        }

        try {
            return (string) UriResolver::resolve(new Uri($url), new Uri($location));
        } catch (InvalidArgumentException $exception) {
            throw new FetchFailedException("$url redirects to a location that is not a URL.", 0, $exception);
        }
    }

    private function read(ResponseInterface $response, string $url): FetchResult
    {
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new FetchFailedException("$url answered with status $status.", $status);
        }

        $contentType = $response->getHeaderLine('Content-Type');
        $type = strtolower(ltrim($contentType));

        if (! str_starts_with($type, 'text/html') && ! str_starts_with($type, 'application/xhtml+xml')) {
            throw new UnsupportedContentException("$url served $contentType, which has no metadata to read.");
        }

        return new FetchResult(
            $this->readHead($response, $url),
            $url,
            $contentType === '' ? null : $contentType,
        );
    }

    /**
     * Content-Length is not consulted: it is optional, it lies, and it says
     * nothing about a chunked response.
     */
    private function readHead(ResponseInterface $response, string $url): string
    {
        $stream = $response->getBody();
        $body = '';
        $deadline = microtime(true) + self::READ_SECONDS;

        try {
            while (! $stream->eof() && strlen($body) < self::MAX_BYTES) {
                if (microtime(true) > $deadline) {
                    break;
                }

                $chunk = $stream->read(self::CHUNK_BYTES);

                // A stream may return nothing without being at its end.
                if ($chunk === '') {
                    break;
                }

                $body .= $chunk;

                if (stripos($body, '</head>') !== false) {
                    break;
                }
            }
        } catch (RuntimeException $exception) {
            throw new FetchFailedException("The body of $url stopped arriving.", 0, $exception);
        }

        return substr($body, 0, self::MAX_BYTES);
    }
}
