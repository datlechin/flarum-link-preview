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
use Datlechin\LinkPreview\Http\HeadFetcher;
use Flarum\Testing\unit\TestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;

class ReadsOnlyTheHeadOfAPageTest extends TestCase
{
    use BuildsAFetcher;

    private const HEAD = '<html><head><title>Document title</title>';

    private const HTML = self::HEAD.'</head><body>Body copy.</body></html>';

    #[Test]
    public function a_page_comes_back_with_the_url_it_was_read_from(): void
    {
        $fetcher = $this->fetcher([self::html(self::HTML, ['response_headers' => ['Content-Type' => 'text/html; charset=utf-8']])]);

        $result = $fetcher->fetch('https://example.com/article');

        $this->assertSame(self::HEAD, $result->html);
        $this->assertSame('https://example.com/article', $result->effectiveUrl);
        $this->assertSame('text/html; charset=utf-8', $result->contentType);
        $this->assertRequestsMade(1);
    }

    #[Test]
    public function an_xhtml_document_is_a_page_too(): void
    {
        $fetcher = $this->fetcher([self::html(self::HTML, ['response_headers' => ['Content-Type' => 'application/xhtml+xml']])]);

        $this->assertSame('application/xhtml+xml', $fetcher->fetch('https://example.com/a')->contentType);
    }

    #[Test]
    public function a_forbidden_page_is_a_failure_rather_than_a_preview(): void
    {
        $fetcher = $this->fetcher([
            self::html('<html><head><title>Just a moment...</title></head></html>', ['http_code' => 403]),
        ]);

        $this->expectException(LinkPreviewException::class);

        $fetcher->fetch('https://example.com/a');
    }

    #[Test]
    #[DataProvider('statusesThatAreNotAPage')]
    public function only_a_two_hundred_carries_a_page(int $status): void
    {
        $fetcher = $this->fetcher([self::html(self::HTML, ['http_code' => $status])]);

        $this->expectException(FetchFailedException::class);
        $this->expectExceptionCode($status);

        $fetcher->fetch('https://example.com/a');
    }

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
        $fetcher = $this->fetcher([new MockResponse('binary', ['response_headers' => $headers])]);

        $this->expectException(UnsupportedContentException::class);

        $fetcher->fetch('https://example.com/a');
    }

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
        $fetcher = $this->fetcher([self::html(self::HTML, ['response_headers' => ['Content-Type' => ' TEXT/HTML; charset=UTF-8']])]);

        $this->assertInstanceOf(FetchResult::class, $fetcher->fetch('https://example.com/a'));
    }

    #[Test]
    public function a_redirect_chain_ends_at_the_page_it_reached(): void
    {
        $fetcher = $this->fetcher([
            self::redirect('https://example.com/one'),
            self::redirect('https://example.com/two', 301),
            self::redirect('https://elsewhere.test/final', 308),
            self::html(self::HTML),
        ]);

        $result = $fetcher->fetch('https://example.com/a');

        $this->assertSame('https://elsewhere.test/final', $result->effectiveUrl);
        $this->assertRequestsMade(4);
    }

    #[Test]
    public function one_hop_more_than_the_limit_is_a_failure(): void
    {
        $fetcher = $this->fetcher([
            self::redirect('https://example.com/one'),
            self::redirect('https://example.com/two'),
            self::redirect('https://example.com/three'),
            self::redirect('https://example.com/four'),
            self::html(self::HTML),
        ]);

        $this->expectException(FetchFailedException::class);

        try {
            $fetcher->fetch('https://example.com/a');
        } finally {
            $this->assertRequestsMade(HeadFetcher::MAX_REDIRECTS + 1);
        }
    }

    #[Test]
    public function a_redirect_the_client_could_not_resolve_is_a_failure(): void
    {
        $fetcher = $this->fetcher([new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'http://']])]);

        $this->expectException(FetchFailedException::class);

        $fetcher->fetch('https://example.com/a');
    }

    #[Test]
    public function a_connection_that_never_happened_is_a_failure(): void
    {
        $fetcher = $this->fetcher([new MockResponse('', ['error' => 'Connection timed out'])]);

        $this->expectException(FetchFailedException::class);

        $fetcher->fetch('https://example.com/a');
    }

    #[Test]
    public function a_body_that_stops_arriving_is_a_failure(): void
    {
        $fetcher = $this->fetcher([self::html(self::chunks('<html><head>', new FetchFailedException('Connection reset')))]);

        $this->expectException(FetchFailedException::class);

        $fetcher->fetch('https://example.com/a');
    }

    #[Test]
    public function the_page_is_kept_only_up_to_the_end_of_its_head(): void
    {
        $fetcher = $this->fetcher([self::html(self::chunks(self::HEAD, '</head><body>', str_repeat('x', 8192)))]);

        $this->assertSame(self::HEAD, $fetcher->fetch('https://example.com/a')->html);
    }

    #[Test]
    public function a_head_longer_than_the_limit_is_cut_at_the_limit(): void
    {
        $chunk = str_repeat('a', 65536);
        $count = intdiv(HeadFetcher::MAX_HEAD_BYTES, strlen($chunk)) * 2;
        $fetcher = $this->fetcher([self::html(self::chunks(...array_fill(0, $count, $chunk)))]);

        $this->assertSame(HeadFetcher::MAX_HEAD_BYTES, strlen($fetcher->fetch('https://example.com/a')->html));
    }

    #[Test]
    public function a_head_past_one_mebibyte_is_read_whole(): void
    {
        $head = '<html><head><style>'.str_repeat('.a{}', 270000).'</style><meta property="og:title" content="App">';
        $fetcher = $this->fetcher([self::html($head.'</head><body></body></html>')]);

        $this->assertStringEndsWith('<meta property="og:title" content="App">', $fetcher->fetch('https://example.com/a')->html);
    }

    #[Test]
    public function a_batch_answers_every_url_it_was_given_in_the_order_it_was_given(): void
    {
        $fetcher = $this->fetcher([
            self::html(self::HTML),
            self::html('boom', ['http_code' => 500]),
            new MockResponse('binary', ['response_headers' => ['Content-Type' => 'image/png']]),
        ]);

        $urls = ['https://one.test/a', 'https://two.test/b', 'https://three.test/c'];
        $results = $fetcher->fetchMany($urls);

        $this->assertSame($urls, array_keys($results));
        $this->assertInstanceOf(FetchResult::class, $results['https://one.test/a']);
        $this->assertInstanceOf(FetchFailedException::class, $results['https://two.test/b']);
        $this->assertInstanceOf(UnsupportedContentException::class, $results['https://three.test/c']);
    }

    #[Test]
    public function an_empty_batch_asks_for_nothing(): void
    {
        $fetcher = $this->fetcher([self::html(self::HTML)]);

        $this->assertSame([], $fetcher->fetchMany([]));
        $this->assertRequestsMade(0);
    }

    private static function chunks(string|\Throwable ...$chunks): Generator
    {
        yield from $chunks;
    }
}
