<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Tests\unit\Preview;

use Flarum\Testing\unit\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;

class NamesAStringForEveryCountedFactTest extends TestCase
{
    private const COUNTED = ['replies', 'posts', 'discussions'];

    private function forum(): array
    {
        $file = Yaml::parseFile(__DIR__.'/../../../locale/en.yml');

        $forum = $file['datlechin-link-preview']['forum'];

        return $forum;
    }

    #[Test]
    public function every_counted_fact_has_a_plural_string(): void
    {
        $meta = $this->forum()['meta'];

        $this->assertIsArray($meta);

        foreach (self::COUNTED as $key) {
            $this->assertArrayHasKey($key, $meta);
            $this->assertStringContainsString('plural', (string) $meta[$key], "$key counts, so it has to decline");
        }
    }

    #[Test]
    public function nothing_else_is_named_under_meta(): void
    {
        $this->assertSame(self::COUNTED, array_keys($this->forum()['meta']));
    }

    #[Test]
    public function the_failure_wordings_are_gone(): void
    {
        $this->assertArrayNotHasKey('errors', $this->forum());
    }
}
