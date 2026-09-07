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

use Datlechin\LinkPreview\Http\Exception\FetchFailedException;
use Datlechin\LinkPreview\Http\Exception\LinkPreviewException;
use Datlechin\LinkPreview\Http\Exception\UnsupportedContentException;
use Datlechin\LinkPreview\Http\FetchResult;
use Datlechin\LinkPreview\Http\SafeFetcher;
use Flarum\Testing\unit\TestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\StreamInterface;

/**
 * What the fetcher agrees to hand back, and how much of it it reads.
 *
 * The status code is the important half: a Cloudflare challenge arrives as a
 * 403 carrying a perfectly parseable title, so a fetcher that parses whatever
 * comes back caches a preview reading "Just a moment...".
 */
class ReadsOnlyTheHeadOfAPageTest extends TestCase
{
    use BuildsAFetcher;

    private const HTML = '<html><head><title>Document title</title></head><body>Body copy.</body></html>';

    #[Test]
    public function a_page_comes_back_with_the_url_it_was_read_from(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], self::HTML)]);

        $result = $fetcher->fetch('https://example.com/article');

        $this->assertSame(self::HTML, $result->body);
        $this->assertSame('https://example.com/article', $result->effectiveUrl);
        $this->assertSame('text/html; charset=utf-8', $result->contentType);
        $this->assertResponsesLeft(0);
    }

    #[Test]
    public function an_xhtml_document_is_a_page_too(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'application/xhtml+xml'], self::HTML)]);

        $this->assertSame('application/xhtml+xml', $fetcher->fetch('https://example.com/a')->contentType);
    }

    #[Test]
    public function a_forbidden_page_is_a_failure_rather_than_a_preview(): void
    {
        $fetcher = $this->fetcher([
            new Response(403, ['Content-Type' => 'text/html'], '<html><head><title>Just a moment...</title></head></html>'),
        ]);

        $this->expectException(LinkPreviewException::class);

        $fetcher->fetch('https://example.com/a');
    }

    #[Test]
    #[DataProvider('statusesThatAreNotAPage')]
    public function only_a_two_hundred_carries_a_page(int $status): void
    {
        // The base class, because all this asserts is that nothing was handed
        // back to be parsed. Which subclass decides between `unreachable` and
        // `http_error` on the card is pinned in the integration suite.
        $fetcher = $this->fetcher([new Response($status, ['Content-Type' => 'text/html'], self::HTML)]);

        $this->expectException(LinkPreviewException::class);

        $fetcher->fetch('https://example.com/a');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function statusesThatAreNotAPage(): array
    {
        return [
            'unauthorised' => [401],
            'forbidden' => [403],
            'gone' => [404],
            'rate limited' => [429],
            'server error' => [500],
            'no content' => [204],
            'a redirect with nowhere to go' => [302],
        ];
    }

    #[Test]
    #[DataProvider('contentTypesWithoutMetadata')]
    public function anything_that_is_not_a_document_is_not_previewable(string $contentType): void
    {
        $headers = $contentType === '' ? [] : ['Content-Type' => $contentType];

        $fetcher = $this->fetcher([new Response(200, $headers, 'binary')]);

        $this->expectException(UnsupportedContentException::class);

        $fetcher->fetch('https://example.com/a');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function contentTypesWithoutMetadata(): array
    {
        return [
            'a pdf' => ['application/pdf'],
            'an image' => ['image/png'],
            'a video' => ['video/mp4'],
            'a json api' => ['application/json; charset=utf-8'],
            'plain text' => ['text/plain'],
            'nothing at all' => [''],
        ];
    }

    #[Test]
    public function a_document_content_type_is_read_case_insensitively(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => ' TEXT/HTML; charset=UTF-8'], self::HTML)]);

        $this->assertInstanceOf(FetchResult::class, $fetcher->fetch('https://example.com/a'));
    }

    #[Test]
    public function a_redirect_chain_ends_at_the_page_it_reached(): void
    {
        $fetcher = $this->fetcher([
            new Response(302, ['Location' => '/one']),
            new Response(301, ['Location' => '/two']),
            new Response(308, ['Location' => 'https://elsewhere.test/final']),
            new Response(200, ['Content-Type' => 'text/html'], self::HTML),
        ]);

        $result = $fetcher->fetch('https://example.com/a');

        // The last hop is the base a relative `og:image` resolves against, and
        // it is the address the card links to.
        $this->assertSame('https://elsewhere.test/final', $result->effectiveUrl);
        $this->assertResponsesLeft(0);
    }

    #[Test]
    public function one_hop_more_than_the_limit_is_a_failure(): void
    {
        $fetcher = $this->fetcher([
            new Response(302, ['Location' => '/one']),
            new Response(302, ['Location' => '/two']),
            new Response(302, ['Location' => '/three']),
            new Response(302, ['Location' => '/four']),
            new Response(200, ['Content-Type' => 'text/html'], self::HTML),
        ]);

        $this->expectException(FetchFailedException::class);

        try {
            $fetcher->fetch('https://example.com/a');
        } finally {
            $this->assertResponsesLeft(1);
        }
    }

    #[Test]
    public function a_redirect_to_something_that_is_not_a_url_is_a_failure(): void
    {
        $fetcher = $this->fetcher([new Response(302, ['Location' => 'http://'])]);

        $this->expectException(FetchFailedException::class);

        $fetcher->fetch('https://example.com/a');
    }

    #[Test]
    public function a_connection_that_never_happened_is_a_failure(): void
    {
        $fetcher = $this->fetcher([
            new ConnectException('Connection timed out', new Request('GET', 'https://example.com/a')),
        ]);

        $this->expectException(FetchFailedException::class);

        $fetcher->fetch('https://example.com/a');
    }

    #[Test]
    public function the_cap_on_one_body_is_a_mebibyte(): void
    {
        // Twenty of these can be in flight for one batch request, so the
        // number is also the ceiling on what a single page view can make the
        // forum hold in memory at once.
        $this->assertSame(1048576, SafeFetcher::MAX_BYTES);
    }

    #[Test]
    public function a_body_larger_than_the_cap_is_truncated_rather_than_buffered(): void
    {
        $stream = $this->streamOf(str_repeat('a', SafeFetcher::MAX_BYTES * 2));

        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html'], $stream)]);

        $result = $fetcher->fetch('https://example.com/a');

        $this->assertSame(SafeFetcher::MAX_BYTES, strlen($result->body));

        // The rest was never pulled off the wire: the cap is a memory bound,
        // not a substr.
        $this->assertSame(SafeFetcher::MAX_BYTES, $stream->tell());
        $this->assertFalse($stream->eof());
    }

    #[Test]
    public function reading_stops_as_soon_as_the_head_is_closed(): void
    {
        $head = '<html><head><title>Document title</title></head>';
        $stream = $this->streamOf($head.str_repeat('x', SafeFetcher::MAX_BYTES));

        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html'], $stream)]);

        $result = $fetcher->fetch('https://example.com/a');

        $this->assertStringStartsWith($head, $result->body);
        $this->assertLessThan(SafeFetcher::MAX_BYTES, strlen($result->body));
        $this->assertSame(strlen($result->body), $stream->tell());
        $this->assertFalse($stream->eof());
    }

    #[Test]
    public function a_head_that_closes_late_is_still_read_whole(): void
    {
        $body = '<html><head>'.str_repeat('<meta name="filler" content="x">', 500).'</head><body>Body copy.</body></html>';

        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html'], $body)]);

        $this->assertStringContainsString('</head>', $fetcher->fetch('https://example.com/a')->body);
    }

    #[Test]
    public function a_batch_answers_every_url_it_was_given_in_the_order_it_was_given(): void
    {
        $fetcher = $this->fetcher([
            new Response(200, ['Content-Type' => 'text/html'], self::HTML),
            new Response(500, ['Content-Type' => 'text/html'], 'boom'),
            new Response(200, ['Content-Type' => 'image/png'], 'binary'),
        ]);

        $urls = ['https://one.test/a', 'https://two.test/b', 'https://three.test/c'];
        $results = $fetcher->fetchMany($urls);

        $this->assertSame($urls, array_keys($results));
        $this->assertInstanceOf(FetchResult::class, $results['https://one.test/a']);
        $this->assertInstanceOf(LinkPreviewException::class, $results['https://two.test/b']);
        $this->assertInstanceOf(UnsupportedContentException::class, $results['https://three.test/c']);
    }

    #[Test]
    public function an_empty_batch_asks_for_nothing(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html'], self::HTML)]);

        $this->assertSame([], $fetcher->fetchMany([]));
        $this->assertResponsesLeft(1);
    }

    private function streamOf(string $body): StreamInterface
    {
        return Utils::streamFor($body);
    }
}
