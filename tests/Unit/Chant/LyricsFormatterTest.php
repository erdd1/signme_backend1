<?php

namespace Tests\Unit\Chant;

use App\Modules\Chant\Application\Services\LyricsFormatter;
use PHPUnit\Framework\TestCase;

class LyricsFormatterTest extends TestCase
{
    private LyricsFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new LyricsFormatter();
    }

    public function test_splits_blocks_on_blank_lines(): void
    {
        $sections = $this->formatter->toSections("Ligne A\nLigne B\n\nLigne C\nLigne D");

        $this->assertCount(2, $sections);
        $this->assertSame(['Ligne A', 'Ligne B'], $sections[0]['lines']);
        $this->assertSame('strophe', $sections[0]['type']);
    }

    public function test_repeated_block_is_a_refrain(): void
    {
        $lyrics = "Couplet un\nsuite\n\nGloire à Dieu\nAmen\n\nCouplet deux\nsuite\n\nGloire à Dieu\nAmen";

        $types = array_column($this->formatter->toSections($lyrics), 'type');

        $this->assertSame(['strophe', 'refrain', 'strophe', 'refrain'], $types);
    }

    public function test_explicit_label_types_next_block_and_is_hidden(): void
    {
        $sections = $this->formatter->toSections("[Refrain]\nAlléluia\n\nCouplet 1:\nPremière strophe");

        $this->assertSame('refrain', $sections[0]['type']);
        $this->assertSame(['Alléluia'], $sections[0]['lines']);
        $this->assertSame(['Première strophe'], $sections[1]['lines']);
    }

    public function test_label_alone_on_its_own_block_applies_to_following_block(): void
    {
        $sections = $this->formatter->toSections("Chorus\n\nHallelujah\nHallelujah");

        $this->assertCount(1, $sections);
        $this->assertSame('refrain', $sections[0]['type']);
    }

    public function test_handles_windows_line_endings_and_extra_blank_lines(): void
    {
        $sections = $this->formatter->toSections("A\r\nB\r\n\r\n\r\n\r\nC");

        $this->assertCount(2, $sections);
    }

    public function test_excerpt_skips_labels_and_blank_lines(): void
    {
        $excerpt = $this->formatter->excerpt("[Refrain]\n\nPremière ligne\nDeuxième ligne\nTroisième");

        $this->assertSame(['Première ligne', 'Deuxième ligne'], $excerpt);
    }
}
