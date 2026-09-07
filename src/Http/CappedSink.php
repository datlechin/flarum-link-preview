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

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A body buffer that stops taking bytes once it has enough of them.
 *
 * Without ext-curl there is no progress callback to abort a transfer with, so
 * Guzzle's stream handler copies the whole response into the sink before the
 * promise resolves and a link in a post names how much memory that is. The
 * copy runs through `GuzzleHttp\Psr7\Utils::copyToStream()`, which gives up as
 * soon as a write is refused and then closes the source, so refusing is what
 * bounds the read and drops the connection.
 *
 * What is lost against the cURL path is precision and speed, not the bound:
 * the copy only notices a full sink after the buffer it is holding, so up to
 * one 8KB read past the cap crosses the wire, and the transfer runs to that
 * point rather than being cut at the byte.
 */
final class CappedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $written = 0;

    public function __construct(private StreamInterface $stream, private int $maxBytes)
    {
    }

    public static function ofBytes(int $maxBytes): self
    {
        return new self(Utils::streamFor(), $maxBytes);
    }

    public function write(string $string): int
    {
        $room = $this->maxBytes - $this->written;

        if ($room <= 0) {
            return 0;
        }

        $written = $this->stream->write(substr($string, 0, $room));
        $this->written += $written;

        return $written;
    }
}
