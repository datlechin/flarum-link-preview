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

final class HeadBuffer
{
    private const HEAD_END = '</head';

    private string $html = '';

    public function __construct(private readonly int $maxBytes)
    {
    }

    public function append(string $chunk): bool
    {
        $offset = max(0, strlen($this->html) - strlen(self::HEAD_END) + 1);

        $this->html .= $chunk;

        $end = stripos($this->html, self::HEAD_END, $offset);

        if ($end !== false && $end <= $this->maxBytes) {
            $this->html = substr($this->html, 0, $end);

            return true;
        }

        if (strlen($this->html) >= $this->maxBytes) {
            $this->html = substr($this->html, 0, $this->maxBytes);

            return true;
        }

        return false;
    }

    public function html(): string
    {
        return $this->html;
    }
}
