<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Internal;

use DateTimeInterface;

final class Meta
{
    private function __construct(private readonly array $item)
    {
    }

    public static function text(string $text): self
    {
        return new self(['type' => 'text', 'text' => $text]);
    }

    public static function user(string $username): self
    {
        return new self(['type' => 'user', 'text' => $username]);
    }

    public static function count(string $translationKey, int $count): self
    {
        return new self(['type' => 'count', 'key' => $translationKey, 'count' => $count]);
    }

    public static function date(DateTimeInterface $date): self
    {
        return new self(['type' => 'date', 'date' => $date->format(DateTimeInterface::ATOM)]);
    }

    public function toArray(): array
    {
        return $this->item;
    }
}
