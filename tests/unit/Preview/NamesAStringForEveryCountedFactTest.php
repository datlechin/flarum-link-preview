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

/**
 * The locale file, against the payload it has to answer for.
 *
 * A meta item carrying a count is the one shape whose wording lives here: the
 * other two carry their own text or a date the browser formats. Asserted here
 * rather than in an integration test, where an extension's locale file is not
 * loaded at all and every key comes back as itself.
 */
class NamesAStringForEveryCountedFactTest extends TestCase
{
    /**
     * The `count` items `InternalPreviewer` can put on a card, one per type.
     */
    private const COUNTED = ['replies', 'posts', 'discussions'];

    /**
     * @return array<string, mixed>
     */
    private function forum(): array
    {
        /** @var array<string, mixed> $file */
        $file = Yaml::parseFile(__DIR__.'/../../../locale/en.yml');

        /** @var array<string, mixed> $forum */
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
        // A tag name, an author and a date carry themselves. A string left
        // here for one of them is a string nothing will ever ask for.
        $this->assertSame(self::COUNTED, array_keys($this->forum()['meta']));
    }

    #[Test]
    public function the_failure_wordings_are_gone(): void
    {
        // A failed preview leaves the link alone now, so there is nowhere left
        // for these to be shown.
        $this->assertArrayNotHasKey('errors', $this->forum());
    }
}
