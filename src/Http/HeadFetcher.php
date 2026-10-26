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

use Closure;
use Datlechin\LinkPreview\Http\Exception\BlockedUrlException;
use Datlechin\LinkPreview\Http\Exception\FetchFailedException;
use Datlechin\LinkPreview\Http\Exception\LinkPreviewException;
use Datlechin\LinkPreview\Http\Exception\UnsafeUrlException;
use Datlechin\LinkPreview\Http\Exception\UnsupportedContentException;
use SplObjectStorage;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HeadFetcher
{
    public const MAX_HEAD_BYTES = 2097152;

    public const MAX_REDIRECTS = 3;

    private const ALTERNATE_PORTS = [8080, 8443];

    private const OPTIONS = [
        'max_redirects' => 0,
        'timeout' => 4,
        'max_duration' => 8,
        'buffer' => false,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (compatible; FlarumLinkPreview/1.0; +https://github.com/datlechin/flarum-link-preview)',
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'en-US,en;q=0.9',
        ],
    ];

    private readonly HttpClientInterface $client;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client->withOptions(self::OPTIONS);
    }

    public function fetch(string $url, ?Closure $allows = null): FetchResult
    {
        $result = $this->fetchMany([$url], $allows)[$url];

        if ($result instanceof LinkPreviewException) {
            throw $result;
        }

        return $result;
    }

    /** @param list<string> $urls */
    public function fetchMany(array $urls, ?Closure $allows = null): array
    {
        $results = [];
        $hops = array_combine($urls, $urls);

        for ($redirects = 0; $hops !== []; $redirects++) {
            /** @var SplObjectStorage<ResponseInterface, array-key> $requests */
            $requests = new SplObjectStorage();

            foreach ($hops as $requested => $url) {
                try {
                    $requests[$this->request($url, $allows)] = $requested;
                } catch (LinkPreviewException $exception) {
                    $results[$requested] = $exception;
                }
            }

            $next = [];

            foreach ($this->read($requests, $hops) as $requested => $outcome) {
                if (! is_string($outcome)) {
                    $results[$requested] = $outcome;
                } elseif ($redirects < self::MAX_REDIRECTS) {
                    $next[$requested] = $outcome;
                } else {
                    $results[$requested] = new FetchFailedException("$requested redirects more than ".self::MAX_REDIRECTS.' times.');
                }
            }

            $hops = $next;
        }

        $ordered = [];

        foreach ($urls as $url) {
            $ordered[$url] = $results[$url];
        }

        return $ordered;
    }

    private function request(string $url, ?Closure $allows): ResponseInterface
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new UnsafeUrlException("Refusing to fetch $url, which is not http or https.");
        }

        if ($allows !== null && ! $allows($url)) {
            throw new BlockedUrlException("Refusing to fetch $url, which the forum's lists do not allow.");
        }

        $port = parse_url($url, PHP_URL_PORT);

        if (is_int($port) && $port !== ($scheme === 'https' ? 443 : 80) && ! in_array($port, self::ALTERNATE_PORTS, true)) {
            throw new UnsafeUrlException("Refusing to fetch $url, which names port $port.");
        }

        try {
            return $this->client->request('GET', $url);
        } catch (TransportExceptionInterface $exception) {
            throw new UnsafeUrlException("Refusing to fetch $url.", 0, $exception);
        }
    }

    /** @param SplObjectStorage<ResponseInterface, array-key> $requests */
    private function read(SplObjectStorage $requests, array $hops): array
    {
        $outcomes = [];

        /** @var array<string, HeadBuffer> $heads */
        $heads = [];

        foreach ($this->client->stream($requests) as $response => $chunk) {
            $requested = $requests[$response];
            $url = $hops[$requested];

            if (isset($outcomes[$requested])) {
                continue;
            }

            try {
                if ($chunk->isFirst()) {
                    $location = $response->getInfo('redirect_url');

                    if (is_string($location)) {
                        $outcomes[$requested] = $location;
                        $response->cancel();

                        continue;
                    }

                    self::assertDocument($response, $url);
                    $heads[$requested] = new HeadBuffer(self::MAX_HEAD_BYTES);
                }

                if ($heads[$requested]->append($chunk->getContent()) || $chunk->isLast()) {
                    $outcomes[$requested] = new FetchResult($heads[$requested]->html(), $url, self::contentType($response));
                    $response->cancel();
                }
            } catch (TransportExceptionInterface $exception) {
                $outcomes[$requested] = new FetchFailedException("Could not reach $url.", 0, $exception);
            } catch (LinkPreviewException $exception) {
                $outcomes[$requested] = $exception;
                $response->cancel();
            }
        }

        return $outcomes;
    }

    private static function assertDocument(ResponseInterface $response, string $url): void
    {
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new FetchFailedException("$url answered with status $status.", $status);
        }

        $contentType = self::contentType($response) ?? '';
        $type = strtolower(ltrim($contentType));

        if (! str_starts_with($type, 'text/html') && ! str_starts_with($type, 'application/xhtml+xml')) {
            throw new UnsupportedContentException("$url served '$contentType', which has no metadata to read.");
        }
    }

    private static function contentType(ResponseInterface $response): ?string
    {
        return $response->getHeaders(false)['content-type'][0] ?? null;
    }
}
