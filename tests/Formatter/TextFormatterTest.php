<?php

namespace MrMySQL\YoutubeTranscript\Tests\Formatter;

use MrMySQL\YoutubeTranscript\Formatter\TextFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextFormatterTest extends TestCase
{
    public function testFormatsTranscriptOneCuePerLine(): void
    {
        $transcript = [
            ['text' => 'Hello world', 'start' => 0.0, 'duration' => 1.5],
            ['text' => 'Ça va? 日本語 🎉', 'start' => 1.5, 'duration' => 2.25],
            ['text' => "line one\nline two", 'start' => 3.75, 'duration' => 1.0],
        ];

        $this->assertSame(
            "Hello world\nÇa va? 日本語 🎉\nline one line two",
            (new TextFormatter())->formatTranscript($transcript)
        );
    }

    public function testEmptyTranscript(): void
    {
        $this->assertSame('', (new TextFormatter())->formatTranscript([]));
        $this->assertSame('', (new TextFormatter())->formatTranscripts([]));
    }

    public function testSingleCue(): void
    {
        $this->assertSame('Only cue', (new TextFormatter())->formatTranscript([['text' => 'Only cue', 'start' => 0.0, 'duration' => 1.0]]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textProvider(): iterable
    {
        yield 'arrow is plain text' => ['a --> b', 'a --> b'];
        yield 'markup is plain text' => ['<i>a</i> & b', '<i>a</i> & b'];
        yield 'LF' => ["a\nb", 'a b'];
        yield 'CRLF' => ["a\r\nb", 'a b'];
        yield 'CR' => ["a\rb", 'a b'];
        yield 'blank lines' => ["a\n\n \nb", 'a b'];
        yield 'spaces around line break' => ["a  \n  b", 'a b'];
        yield 'leading and trailing newlines' => ["\na\n", 'a'];
        yield 'multibyte' => ['Ελληνικά — 中文 — 🎬', 'Ελληνικά — 中文 — 🎬'];
    }

    #[DataProvider('textProvider')]
    public function testText(string $text, string $expected): void
    {
        $this->assertSame($expected, (new TextFormatter())->formatTranscript([['text' => $text, 'start' => 0.0, 'duration' => 1.0]]));
    }

    public function testBlankCueKeepsItsLine(): void
    {
        $transcript = [
            ['text' => 'a', 'start' => 0.0, 'duration' => 1.0],
            ['text' => "\n", 'start' => 1.0, 'duration' => 1.0],
            ['text' => 'b', 'start' => 2.0, 'duration' => 1.0],
        ];

        $this->assertSame("a\n\nb", (new TextFormatter())->formatTranscript($transcript));
    }

    public function testFormatTranscriptsSeparatesTranscriptsWithTwoBlankLines(): void
    {
        $transcripts = [
            [['text' => 'Hello', 'start' => 0.0, 'duration' => 1.0], ['text' => 'World', 'start' => 1.0, 'duration' => 1.0]],
            [['text' => 'Hallo', 'start' => 0.0, 'duration' => 1.0]],
        ];

        $this->assertSame("Hello\nWorld\n\n\nHallo", (new TextFormatter())->formatTranscripts($transcripts));
    }
}
