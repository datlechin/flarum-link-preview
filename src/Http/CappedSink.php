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
 * `Utils::copyToStream()` gives up as soon as a write is refused, so a short
 * write is what bounds a body on the transport with no progress callback to
 * abort from. The bound is loose: the copy notices a full sink only after the
 * buffer it holds, so up to one 8KB read past the cap crosses the wire.
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
