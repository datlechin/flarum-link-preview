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
 * Fetches the head of a remote page on behalf of whoever posted the link.
 *
 * Any URL a forum member types is a URL the server can be made to request, so
 * this is the security boundary of the extension. It is modelled on core's own
 * avatar fetcher in `Flarum\Api\Resource\UserResource::retrieveAvatarFromUrl()`,
 * with four deliberate differences:
 *
 * - RFC1918 ranges are blocked as well as reserved ones, and so is everything
 *   in {@see self::BLOCKED_RANGES} that `filter_var()` lets through. The
 *   avatar fetcher leaves private LAN addresses reachable so that Docker
 *   networks and reverse proxies keep working. A preview of a link someone
 *   pasted into a post has no such need, and the forum's own URLs are answered
 *   by {@see \Datlechin\LinkPreview\Preview\DiscussionPreviewer} without ever
 *   reaching this class.
 * - Redirects are followed here rather than by Guzzle, because Guzzle would
 *   revalidate nothing: `Location: http://169.254.169.254/` from a host that
 *   passed every check is the whole attack, and every hop has to be checked
 *   the same way the first one was.
 * - The status code is honoured. The version this replaces sent
 *   `http_errors => false` and then parsed whatever came back, so a Cloudflare
 *   challenge page was cached and shown as a real preview titled
 *   "Just a moment...". That is the other suspected cause of issue #39.
 * - The transport is named rather than left to Guzzle, because the address pin
 *   below only exists in the cURL one. The avatar fetcher has the same latent
 *   hole and is not an argument that leaving the choice to Guzzle is safe;
 *   {@see self::clientConfig()} says why.
 */
final class SafeFetcher
{
    /**
     * How much of a body may be read before the head is given up on.
     *
     * Measured byte offset of `</head>` on real pages: Wikipedia 9KB, PS
     * Store 12KB, GitHub 31KB, discuss.flarum.org 65KB, YouTube 713KB.
     * YouTube inlines a very large script into the head ahead of its meta
     * tags, so at the previous 256KB the most linked site on the web came
     * back as no_metadata. The read still stops at `</head>`, so the other
     * four cost exactly what they cost before.
     */
    public const MAX_BYTES = 1048576;

    public const MAX_REDIRECTS = 3;
    public const TIMEOUT = 8;
    public const CONNECT_TIMEOUT = 4;

    private const CHUNK_BYTES = 8192;

    /**
     * How long {@see self::readHead()} may spend collecting one body.
     *
     * The byte cap bounds a flood and the cURL options bound a trickle, but a
     * handler that is neither, a test double or a PSR-18 client someone
     * injected, is bounded by nothing else once the response object exists.
     */
    private const READ_SECONDS = 5;

    /**
     * Below this many bytes a second for this many seconds, the transfer is
     * treated as stalled and dropped. `timeout` is the real bound; this only
     * gives the connection back sooner, and is deliberately slack so that a
     * site that thinks for a few seconds before answering is not cut off.
     */
    private const MIN_BYTES_PER_SECOND = 256;
    private const STALL_SECONDS = 5;

    /**
     * cURL's `CURLE_ABORTED_BY_CALLBACK`, spelled out because the constant
     * exists only when ext-curl is loaded.
     */
    private const CURL_ABORTED_BY_CALLBACK = 42;

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /**
     * Ports the forum will speak HTTP to besides the scheme's own.
     */
    private const ALTERNATE_PORTS = [8080, 8443];

    /**
     * The twelve bytes an IPv4 address wears when it is written as IPv6.
     */
    private const MAPPED_IPV4_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /**
     * Subnet => prefix length, for ranges `filter_var()` admits anyway.
     *
     * `FILTER_FLAG_NO_RES_RANGE` and `FILTER_FLAG_NO_PRIV_RANGE` between them
     * cover loopback, link local and RFC1918, and the exact list beyond that
     * moves between PHP builds. Everything here either reaches something that
     * is not the public internet or is not a host at all, so each range is
     * refused by hand rather than left to the filter.
     *
     * @var array<string, int>
     */
    private const BLOCKED_RANGES = [
        // "This network". 0.0.0.0 is the local host to most stacks, and Linux
        // routes the rest of the range to it as well.
        '0.0.0.0' => 8,
        // Carrier grade NAT, which is the ISP's own network on a home
        // connection and the node network inside several cloud providers.
        '100.64.0.0' => 10,
        // IETF protocol assignments, which contains 192.0.0.192 and the rest
        // of the addresses infrastructure answers on rather than hosts.
        '192.0.0.0' => 24,
        // Benchmarking, which network gear uses for test interfaces that are
        // reachable from inside the network and from nowhere else.
        '198.18.0.0' => 15,
        // Reserved for future use, including the 255.255.255.255 broadcast
        // address that a stack may take as "everything on this segment".
        '240.0.0.0' => 4,
        // IPv6 site local. Deprecated in favour of unique local addresses,
        // still configured on plenty of hardware, and not covered by the
        // private range flag.
        'fec0::' => 10,
        // NAT64, which is an IPv4 address in the low 32 bits and a router
        // willing to forward to it. 64:ff9b::7f00:1 is loopback with an
        // extra hop in front of it.
        '64:ff9b::' => 96,
        // Multicast, which is a group of listeners rather than a host. The
        // groups that answer are the ones on the segment the forum is on,
        // and 239.255.255.250 is every UPnP device in the rack.
        '224.0.0.0' => 4,
        'ff00::' => 8,
        // 6to4, an IPv4 address in bits 16 to 48 and a relay willing to
        // carry to it. 2002:7f00:1:: is loopback behind a tunnel.
        '2002::' => 16,
        // IPv4-compatible IPv6, the deprecated `::a.b.c.d`, whose low 32
        // bits a stack that still parses it treats as an IPv4 destination.
        // The reserved flag appears to cover this, but it decides on the
        // notation rather than the value: `::10.0.0.5` is refused and
        // `::a00:5`, the same sixteen bytes, is not.
        '::' => 96,
    ];

    /**
     * An honest crawler identity rather than an impersonation of a browser.
     *
     * The string this replaces claimed to be Chrome on Windows, inherited
     * from the code this class replaced. Measured: facebook.com answers 400
     * to a desktop Chrome agent arriving from a server and 200 to a bot that
     * names itself. Working better is the smaller half of the reason. A site
     * that would rather not be previewed can only decide that if the request
     * admits what it is, and a forged browser agent takes the decision away
     * from them. Shaped after facebookexternalhit and Discoursebot, because
     * that is the shape operators already write rules against. Still no
     * cookie jar and still nothing about the reader who triggered it, so a
     * preview cannot be used to fingerprint anyone.
     */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; FlarumLinkPreview/1.0; +https://github.com/datlechin/flarum-link-preview)';

    /**
     * Whether the body has to be bounded by refusing to buffer it.
     *
     * True only for the client this class builds on a host without ext-curl,
     * because that is the one case where the transport is known to be PHP's
     * stream wrapper. An injected client is a test double or somebody else's
     * wiring and its handler is not ours to guess at.
     */
    private readonly bool $capsBodyInSink;

    public function __construct(private Resolver $resolver, private ?ClientInterface $client = null)
    {
        $this->capsBodyInSink = $client === null && ! extension_loaded('curl');
    }

    /**
     * @param  null|callable(string): bool  $guard  see {@see self::fetchMany()}
     *
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
     * Fetch a batch concurrently, in rounds, one round per redirect hop.
     *
     * A post can carry twenty links and each of them can take the full
     * {@see self::TIMEOUT}, so doing this one at a time would let a single slow
     * host hold the request open for most of three minutes. Redirects are
     * followed a round at a time rather than per URL so that a chain in one of
     * them does not serialise the rest of the batch behind it.
     *
     * @param list<string> $urls
     * @param null|callable(string): bool $guard a check the caller runs against
     *        every hop before it is connected to, including each redirect
     *        target. It refuses by throwing a {@see LinkPreviewException},
     *        which is attributed to the URL that was asked for rather than
     *        ending the round. This class never learns what the check is for.
     *
     * @return array<string, FetchResult|LinkPreviewException> keyed by the input url
     */
    public function fetchMany(array $urls, ?callable $guard = null): array
    {
        /** @var array<string, FetchResult|LinkPreviewException> $results */
        $results = [];

        /** @var array<string, string> $pending requested url => url of this hop */
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
                    // `http://good.test:+80/a` satisfies parse_url, and so
                    // every check above, and is then refused by the URI parser
                    // Guzzle builds the request with. That happens here rather
                    // than inside the promise, so left uncaught it leaves
                    // fetchMany, 500s the endpoint, and takes every other URL
                    // in the batch with it.
                    $results[$requested] = new UnsafeUrlException("Refusing to fetch $url, which is not a URL.", 0, $exception);
                }
            }

            if ($promises === []) {
                break;
            }

            /** @var array<string, array{state: string, value?: mixed, reason?: mixed}> $settled */
            $settled = Utils::settle($promises)->wait();

            /** @var array<string, string> $next */
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

        // A URL rejected before it was ever sent lands in $results a round
        // earlier than one that had to be fetched, so without this the batch
        // comes back in an order that has nothing to do with the request.
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
     * Guzzle is told which transport to use rather than asked to pick one.
     *
     * Left alone, it wraps the cURL handler in a streaming proxy whenever
     * `allow_url_fopen` is on, which is the PHP default, and the proxy hands
     * every request marked `stream` to the stream handler instead. That
     * handler ignores the `curl` request option, so the CURLOPT_RESOLVE pin
     * never reached the transport and the address a record rebound to between
     * the lookup and the connect was the one connected to. It is also
     * synchronous, so {@see Utils::settle()} resolved the batch one URL at a
     * time and nothing about `fetchMany()` was concurrent. Naming the multi
     * handler fixes both.
     *
     * @return array<string, mixed>
     */
    private function clientConfig(): array
    {
        // Without cURL there is no pin to land, no concurrency to be had and
        // no way to abort a transfer. The address check still runs before
        // every hop, so what is missing here is the narrow rebinding window
        // rather than the rule itself, and the byte cap comes back as the sink
        // {@see self::options()} attaches on this path.
        if (! extension_loaded('curl')) {
            return [];
        }

        return ['handler' => HandlerStack::create(new CurlMultiHandler())];
    }

    /**
     * Validate one hop and produce the options to fetch it with.
     *
     * @param  null|callable(string): bool  $guard
     *
     * @return array<string, mixed>
     *
     * @throws LinkPreviewException
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

        // After the checks that decide whether this is a URL at all and before
        // the lookup, so that a hop the caller refuses costs no DNS and is
        // reported as their refusal rather than as a bad address.
        if ($guard !== null && ! $guard($url)) {
            throw new UnsafeUrlException("Refusing to fetch $url, which the caller declined.");
        }

        $port = $this->allowedPortFor($url, $scheme);
        $ip = $this->allowedAddressFor($host);

        if ($ip === null) {
            throw new UnsafeUrlException("Refusing to fetch $url: $host has no address outside this network.");
        }

        $options = $this->options();

        // Needs the cURL handler, which clientConfig() asks for whenever the
        // extension is there, and is harmless without it.
        if (defined('CURLOPT_RESOLVE')) {
            $options['curl'] = $this->curlOptions($host, $port, $ip);
        }

        return $options;
    }

    /**
     * Options every request in every round shares.
     *
     * `verify` is never turned off. The page being previewed is untrusted
     * input either way, but an unverified TLS connection would also let anyone
     * on the path choose what the forum caches under someone else's domain.
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
            // Guzzle turns this into the transport's own allow list, so a URL
            // that somehow got past the scheme check above cannot reach
            // file:// or gopher:// through cURL either.
            'protocols' => ['http', 'https'],
            // The body is now buffered before the promise resolves, and the
            // byte cap counts what arrives on the wire. Accepting a content
            // encoding would let a few compressed kilobytes expand into a
            // buffer nothing bounds, so the forum asks for none.
            'decode_content' => false,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ];

        // Nothing else bounds the body on the stream transport: it has no
        // progress callback to abort a transfer from, and it buffers the whole
        // thing into the sink before the promise resolves. A sink that stops
        // accepting bytes is the only lever left. Fresh per request, because
        // the same one handed to two of them would interleave.
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
            // Pin the connection to the address just validated, so a record
            // that rebinds between the lookup and the connect cannot swap in a
            // blocked one.
            CURLOPT_RESOLVE => [$this->pin($host, $port, $ip)],
            // A body that arrives a few bytes at a time stays under the byte
            // cap for as long as the total timeout allows. This drops it as
            // soon as it is clear nothing is really coming.
            CURLOPT_LOW_SPEED_LIMIT => self::MIN_BYTES_PER_SECOND,
            CURLOPT_LOW_SPEED_TIME => self::STALL_SECONDS,
        ];

        // cURL hands Guzzle the whole body before it resolves the promise, so
        // the cap in readHead() cannot stop a gigabyte that a link in a post
        // asked for; only aborting the transfer can. The abort comes back as a
        // rejection, which cappedResponse() turns back into what did arrive.
        //
        // Both options below are deprecated as of guzzlehttp/guzzle 7.11 and
        // rejected outright by 8.0, which points at the `progress` request
        // option instead. That option is not a replacement for this: Guzzle
        // wraps the callback and throws away what it returns, and the return
        // value is the entire mechanism, so on Guzzle 8 the byte cap needs a
        // handler of its own rather than a swapped option.
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
     * One CURLOPT_RESOLVE entry.
     *
     * cURL matches the entry against the host as the URL spells it, which for
     * an IPv6 literal is the address without the brackets it wears in a URL,
     * and wants the address to use with them: `::1:443:[::1]`. Writing either
     * half the other way round makes the entry match nothing, and an entry
     * that matches nothing is a pin that is not pinning.
     */
    private function pin(string $host, int $port, string $ip): string
    {
        $name = trim($host, '[]');
        $address = str_contains($ip, ':') ? "[$ip]" : $ip;

        return "$name:$port:$address";
    }

    /**
     * The port to connect on.
     *
     * The address check says the host is somewhere on the public internet, not
     * that the port answers HTTP. Left open, a posted link is a way to make
     * the forum connect to any port of any host and to time the answer, which
     * maps what is listening from the forum's own address and reaches whatever
     * only trusts that address. Two extra ports because a real site is
     * occasionally served on one.
     *
     * @throws UnsafeUrlException
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

    /**
     * The address to connect to, or null when the forum must not reach it.
     *
     * @see self::BLOCKED_RANGES for what the filter flags miss
     */
    private function routableAddress(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $ip = $this->unwrapMappedIpv4($ip);

        // Reserved covers loopback and link local, which is where the cloud
        // metadata endpoint at 169.254.169.254 lives; private covers the LAN
        // the forum shares with whatever else the operator is running.
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

    /**
     * `::ffff:127.0.0.1` is loopback wearing an IPv6 coat, and the rules that
     * decide whether those four bytes are reachable are the IPv4 ones. Every
     * range is written in the family it belongs to, so the address has to be
     * put in that family before any of them is asked about it.
     */
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

        // Different lengths are different families, and comparing them would
        // ask an IPv6 range about the first four bytes of an IPv4 address.
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
     * What a transfer this class cut short had already delivered, or null when
     * the request failed for a reason of its own.
     *
     * The byte cap has to abort rather than stop reading, and an abort arrives
     * as a rejection. The headers and the bytes that did land are on the
     * exception, and the front of a document is all a preview ever wanted, so
     * the response goes on to be read exactly as a whole one would be.
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

        // Guzzle rewinds the body of a transfer that finished, and only that
        // one, so this body is still sitting at the byte the abort stopped on.
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $response;
    }

    /**
     * The absolute URL of the next hop, or null when this is not a redirect.
     *
     * @throws FetchFailedException
     */
    private function redirectTarget(ResponseInterface $response, string $url): ?string
    {
        if (! in_array($response->getStatusCode(), self::REDIRECT_STATUSES, true)) {
            return null;
        }

        // Two Location headers are two answers, and getHeaderLine() joins them
        // into a third that is neither. Whatever the second one is for, the
        // first is the hop a browser takes and the one worth caching as the
        // address the card points at.
        $location = $response->getHeader('Location')[0] ?? '';

        // A redirect status with nothing to redirect to falls through to the
        // status check below, which rejects it.
        if ($location === '') {
            return null;
        }

        try {
            return (string) UriResolver::resolve(new Uri($url), new Uri($location));
        } catch (InvalidArgumentException $exception) {
            throw new FetchFailedException("$url redirects to a location that is not a URL.", 0, $exception);
        }
    }

    /**
     * @throws LinkPreviewException
     */
    private function read(ResponseInterface $response, string $url): FetchResult
    {
        $status = $response->getStatusCode();

        if ($status !== 200) {
            // The status rides on the code, because that is how
            // {@see \Datlechin\LinkPreview\Preview\Previewer::answered()} tells
            // a host that refused from a host that never answered at all.
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
     * Read as much of the body as could contain metadata, and no more.
     *
     * Everything worth extracting lives in the head, so the read stops at the
     * closing tag and never buffers the article, the comments and the ad
     * scripts underneath it. Content-Length is not consulted: it is optional,
     * it lies, and it says nothing about a chunked response.
     *
     * @throws FetchFailedException
     */
    private function readHead(ResponseInterface $response, string $url): string
    {
        $stream = $response->getBody();
        $body = '';
        $deadline = microtime(true) + self::READ_SECONDS;

        try {
            while (! $stream->eof() && strlen($body) < self::MAX_BYTES) {
                // A stream that hands over a byte at a time never trips the
                // byte cap, and the cURL guard against that is not there when
                // the response came from some other client.
                if (microtime(true) > $deadline) {
                    break;
                }

                $chunk = $stream->read(self::CHUNK_BYTES);

                // A stream is allowed to return nothing without being at its
                // end, and looping on that would never terminate.
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
