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
 * Text arrives as markup and leaves as something a card can print.
 *
 * The card has two lines for a title and two for a description, so the limits
 * are there to stop a page that pastes its whole first paragraph into
 * `og:description` from being cut mid-word by the browser instead.
 */
class CleansUpExtractedTextTest extends TestCase
{
    private function title(string $raw): ?string
    {
        return (new MetadataExtractor())
            ->extract('<html><head><title>'.$raw.'</title></head></html>', 'https://example.com/a')
            ->title;
    }

    private function description(string $raw): ?string
    {
        return (new MetadataExtractor())
            ->extract('<html><head><meta name="description" content="'.$raw.'"></head></html>', 'https://example.com/a')
            ->description;
    }

    #[Test]
    public function entities_are_decoded(): void
    {
        $this->assertSame("Tom & Jerry's day", $this->title('Tom &amp; Jerry&#39;s day'));
        $this->assertSame('3 < 5 > 1', $this->title('3 &lt; 5 &gt; 1'));
        $this->assertSame('Say "hello"', $this->description('Say &quot;hello&quot;'));
    }

    #[Test]
    public function whitespace_runs_collapse_to_one_space(): void
    {
        $this->assertSame('One two three', $this->title("  One \n\t two    three  "));
    }

    #[Test]
    public function invisible_spacing_characters_count_as_whitespace(): void
    {
        // A run of non-breaking spaces is invisible once decoded, so without
        // this the card shows a gap it cannot explain.
        $this->assertSame('One two', $this->title('One&nbsp;&nbsp;&nbsp;two'));
        $this->assertSame('One two', $this->title("One\u{200b}\u{feff} two"));
    }

    #[Test]
    public function text_that_is_only_whitespace_becomes_null(): void
    {
        $this->assertNull($this->title('   '));
        $this->assertNull($this->title('&nbsp;'));
        $this->assertNull($this->description('  '));
    }

    #[Test]
    public function a_title_at_the_limit_is_left_alone(): void
    {
        $exact = str_repeat('a', MetadataExtractor::MAX_TITLE_LENGTH);

        $this->assertSame($exact, $this->title($exact));
    }

    #[Test]
    public function a_title_one_character_over_the_limit_is_cut(): void
    {
        $over = str_repeat('a', MetadataExtractor::MAX_TITLE_LENGTH + 1);

        $title = $this->title($over);

        $this->assertSame(MetadataExtractor::MAX_TITLE_LENGTH, mb_strlen((string) $title));
        $this->assertStringEndsWith('…', (string) $title);
    }

    #[Test]
    public function a_long_title_is_cut_at_a_word_boundary(): void
    {
        // Sixty words of five characters is 299 characters, which the limit
        // cuts inside the fortieth word; the whole word goes.
        $title = $this->title(str_repeat('word ', 60));

        $this->assertSame(implode(' ', array_fill(0, 39, 'word')).'…', $title);
    }

    #[Test]
    public function a_long_description_is_cut_at_a_word_boundary(): void
    {
        $description = $this->description(str_repeat('lorem ', 100));

        $this->assertSame(implode(' ', array_fill(0, 66, 'lorem')).'…', $description);
    }

    #[Test]
    public function trailing_punctuation_does_not_survive_the_cut(): void
    {
        $title = $this->title(str_repeat('word, ', 60));

        $this->assertStringEndsWith('word…', (string) $title);
        $this->assertStringNotContainsString(',…', (string) $title);
    }

    #[Test]
    public function text_with_no_late_word_boundary_is_cut_mid_word(): void
    {
        // Japanese and Chinese do not space their words, so a single early
        // space must not throw away everything after it.
        $title = $this->title('ab '.str_repeat('x', 250));

        $this->assertSame(MetadataExtractor::MAX_TITLE_LENGTH, mb_strlen((string) $title));
        $this->assertStringStartsWith('ab xxx', (string) $title);
    }

    #[Test]
    public function the_description_limit_is_the_longer_of_the_two(): void
    {
        $long = str_repeat('a', MetadataExtractor::MAX_DESCRIPTION_LENGTH);

        $this->assertSame($long, $this->description($long));
        $this->assertSame(
            MetadataExtractor::MAX_DESCRIPTION_LENGTH,
            mb_strlen((string) $this->description(str_repeat('a', MetadataExtractor::MAX_DESCRIPTION_LENGTH + 1))),
        );
    }
}
