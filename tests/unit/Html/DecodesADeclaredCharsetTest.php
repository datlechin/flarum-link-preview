<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Tests\unit\Html;

use Datlechin\LinkPreview\Html\MetadataExtractor;
use Flarum\Testing\unit\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A page is decoded before it is read, not after.
 *
 * Mojibake is worse than a missing preview: it gets cached and then shown as
 * if it were the site's own words. The two places a page declares its encoding
 * are the response header and its own head, and plenty of pages that are not
 * UTF-8 use one or the other.
 */
class DecodesADeclaredCharsetTest extends TestCase
{
    private const JAPANESE = '日本語のページ';

    private const GERMAN = 'Café Münster';

    private function encoded(string $markup, string $charset): string
    {
        $bytes = mb_convert_encoding($markup, $charset, 'UTF-8');

        $this->assertIsString($bytes);
        $this->assertNotSame($markup, $bytes);

        return $bytes;
    }

    #[Test]
    public function shift_jis_declared_in_the_content_type_header(): void
    {
        $html = $this->encoded('<html><head><title>'.self::JAPANESE.'</title></head></html>', 'SJIS');

        $metadata = (new MetadataExtractor())->extract($html, 'https://example.jp/a', 'text/html; charset=Shift_JIS');

        $this->assertSame(self::JAPANESE, $metadata->title);
    }

    #[Test]
    public function shift_jis_declared_only_in_a_meta_tag(): void
    {
        $html = $this->encoded(
            '<html><head><meta charset="Shift_JIS"><title>'.self::JAPANESE.'</title></head></html>',
            'SJIS',
        );

        $metadata = (new MetadataExtractor())->extract($html, 'https://example.jp/a');

        $this->assertSame(self::JAPANESE, $metadata->title);
    }

    #[Test]
    public function iso_8859_1_declared_in_the_content_type_header(): void
    {
        $html = $this->encoded('<html><head><title>'.self::GERMAN.'</title></head></html>', 'ISO-8859-1');

        $metadata = (new MetadataExtractor())->extract($html, 'https://example.de/a', 'text/html; charset=ISO-8859-1');

        $this->assertSame(self::GERMAN, $metadata->title);
    }

    #[Test]
    public function iso_8859_1_declared_only_in_an_http_equiv_tag(): void
    {
        $html = $this->encoded(
            '<html><head><meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1">'
                .'<title>'.self::GERMAN.'</title></head></html>',
            'ISO-8859-1',
        );

        $metadata = (new MetadataExtractor())->extract($html, 'https://example.de/a');

        $this->assertSame(self::GERMAN, $metadata->title);
    }

    #[Test]
    public function the_header_is_believed_over_the_page(): void
    {
        // Servers are reconfigured more often than templates are, so the
        // header describes the bytes that actually arrived and a stale meta
        // tag does not.
        $html = $this->encoded(
            '<html><head><meta charset="UTF-8"><title>'.self::GERMAN.'</title></head></html>',
            'ISO-8859-1',
        );

        $metadata = (new MetadataExtractor())->extract($html, 'https://example.de/a', 'text/html; charset=iso-8859-1');

        $this->assertSame(self::GERMAN, $metadata->title);
    }

    #[Test]
    public function a_page_that_declares_nothing_is_read_as_utf8(): void
    {
        $html = '<html><head><title>'.self::JAPANESE.'</title></head></html>';

        $metadata = (new MetadataExtractor())->extract($html, 'https://example.jp/a');

        $this->assertSame(self::JAPANESE, $metadata->title);
    }

    #[Test]
    public function a_charset_nobody_has_heard_of_does_not_lose_the_page(): void
    {
        $html = '<html><head><title>'.self::GERMAN.'</title></head></html>';

        $metadata = (new MetadataExtractor())->extract($html, 'https://example.de/a', 'text/html; charset=x-made-up');

        $this->assertSame(self::GERMAN, $metadata->title);
    }
}
