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

use Datlechin\LinkPreview\Http\HeadBuffer;
use Flarum\Testing\unit\TestCase;
use PHPUnit\Framework\Attributes\Test;

class HeadBufferTest extends TestCase
{
    #[Test]
    public function it_keeps_reading_until_the_head_closes(): void
    {
        $buffer = new HeadBuffer(1000);

        $this->assertFalse($buffer->append('<html><head><title>A</title>'));
        $this->assertTrue($buffer->append('</head><body>'));
        $this->assertSame('<html><head><title>A</title>', $buffer->html());
    }

    #[Test]
    public function a_closing_tag_split_across_chunks_is_found(): void
    {
        $buffer = new HeadBuffer(1000);

        $this->assertFalse($buffer->append('<head><title>A</title></he'));
        $this->assertTrue($buffer->append('ad><body>'));
        $this->assertSame('<head><title>A</title>', $buffer->html());
    }

    #[Test]
    public function the_closing_tag_is_matched_case_insensitively(): void
    {
        $buffer = new HeadBuffer(1000);

        $this->assertTrue($buffer->append('<HEAD><TITLE>A</TITLE></HEAD>'));
        $this->assertSame('<HEAD><TITLE>A</TITLE>', $buffer->html());
    }

    #[Test]
    public function it_stops_at_the_limit(): void
    {
        $buffer = new HeadBuffer(10);

        $this->assertFalse($buffer->append('<head>'));
        $this->assertTrue($buffer->append('<title>A</title>'));
        $this->assertSame('<head><tit', $buffer->html());
    }

    #[Test]
    public function a_closing_tag_past_the_limit_does_not_count(): void
    {
        $buffer = new HeadBuffer(10);

        $this->assertTrue($buffer->append('<head><title>A</title></head>'));
        $this->assertSame('<head><tit', $buffer->html());
    }
}
