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
    private function forum(): array
    {
        $file = Yaml::parseFile(__DIR__.'/../../../locale/en.yml');

        $forum = $file['datlechin-link-preview']['forum'];

        return $forum;
    }

    private function countedByTheBuiltInCards(): array
    {
        $keys = [];

        foreach (glob(__DIR__.'/../../../src/Internal/Cards/*.php') ?: [] as $file) {
            preg_match_all("/Meta::count\('datlechin-link-preview\.forum\.meta\.([a-z_]+)'/", (string) file_get_contents($file), $matches);

            array_push($keys, ...$matches[1]);
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    #[Test]
    public function the_built_in_cards_count_something(): void
    {
        $this->assertNotEmpty($this->countedByTheBuiltInCards(), 'or the pattern above no longer finds them');
    }

    #[Test]
    public function every_counted_fact_has_a_plural_string(): void
    {
        $meta = $this->forum()['meta'];

        $this->assertIsArray($meta);

        foreach ($this->countedByTheBuiltInCards() as $key) {
            $this->assertArrayHasKey($key, $meta);
            $this->assertStringContainsString('plural', (string) $meta[$key], "$key counts, so it has to decline");
        }
    }

    #[Test]
    public function nothing_else_is_named_under_meta(): void
    {
        $named = array_keys($this->forum()['meta']);
        sort($named);

        $this->assertSame($this->countedByTheBuiltInCards(), $named);
    }

    #[Test]
    public function the_failure_wordings_are_gone(): void
    {
        $this->assertArrayNotHasKey('errors', $this->forum());
    }
}
