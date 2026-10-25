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

final class SafeFetcher
{
    public const MAX_BYTES = 1048576;

    public const MAX_REDIRECTS = 3;
    public const TIMEOUT = 8;
    public const CONNECT_TIMEOUT = 4;

    private const CHUNK_BYTES = 8192;

    private const READ_SECONDS = 5;

    private const MIN_BYTES_PER_SECOND = 256;
    private const STALL_SECONDS = 5;

    private const CURL_ABORTED_BY_CALLBACK = 42;

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private const ALTERNATE_PORTS = [8080, 8443];

    private const MAPPED_IPV4_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    private const BLOCKED_RANGES = [
        '0.0.0.0' => 8,
        '100.64.0.0' => 10,
        '192.0.0.0' => 24,
        '198.18.0.0' => 15,
        '240.0.0.0' => 4,
        'fec0::' => 10,
        '64:ff9b::' => 96,
        '224.0.0.0' => 4,
        'ff00::' => 8,
        '2002::' => 16,
        '::' => 96,
    ];

    private const USER_AGENT = 'Mozilla/5.0 (compatible; FlarumLinkPreview/1.0; +https://github.com/datlechin/flarum-link-preview)';

    private readonly bool $capsBodyInSink;

    public function __construct(private Resolver $resolver, private ?ClientInterface $client = null)
    {
        $this->capsBodyInSink = $client === null && ! extension_loaded('curl');
    }

    public function fetch(string $url, ?callable $guard = null): FetchResult
    {
        $result = $this->fetchMany([$url], $guard)[$url];

        if ($result instanceof LinkPreviewException) {
            throw $result;
        }

        return $result;
    }

    public function fetchMany(array $urls, ?callable $guard = null): array
    {
        $results = [];

        $pending = [];

        foreach ($urls as $url) {
            $pending[$url] = $url;
        }

        for ($hop = 0; $pending !== []; $hop++) {
            $promises = [];

            foreach ($pending as $requested => $url) {
                try {
                    $promises[$requested] = $this->client()->requestAsync('GET', $url, $this->optionsFor($url, $guard));
                } catch (LinkPreviewException $exception) {
                    $results[$requested] = $exception;
                } catch (MalformedUriException $exception) {
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

        foreach ($urls as $url) {
            $ordered[$url] = $results[$url];
        }

        return $ordered;
    }

    private function client(): ClientInterface
    {
        return $this->client ??= new Client($this->clientConfig());
    }

    private function clientConfig(): array
    {
        if (! extension_loaded('curl')) {
            return [];
        }

        return ['handler' => HandlerStack::create(new CurlMultiHandler())];
    }

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

    private function options(): array
    {
        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => true,
            'timeout' => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'protocols' => ['http', 'https'],
            'decode_content' => false,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ];

        if ($this->capsBodyInSink) {
            $options['sink'] = CappedSink::ofBytes(self::MAX_BYTES);
        }

        return $options;
    }

    private function curlOptions(string $host, int $port, string $ip): array
    {
        $options = [
            CURLOPT_RESOLVE => [$this->pin($host, $port, $ip)],
            CURLOPT_LOW_SPEED_LIMIT => self::MIN_BYTES_PER_SECOND,
            CURLOPT_LOW_SPEED_TIME => self::STALL_SECONDS,
        ];

        if (defined('CURLOPT_XFERINFOFUNCTION')) {
            $options[CURLOPT_NOPROGRESS] = false;
            $options[CURLOPT_XFERINFOFUNCTION] = static function (mixed $handle, int $expected, int $downloaded): int {
                return $downloaded > self::MAX_BYTES ? 1 : 0;
            };
        }

        return $options;
    }

    private function pin(string $host, int $port, string $ip): string
    {
        $name = trim($host, '[]');
        $address = str_contains($ip, ':') ? "[$ip]" : $ip;

        return "$name:$port:$address";
    }

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
