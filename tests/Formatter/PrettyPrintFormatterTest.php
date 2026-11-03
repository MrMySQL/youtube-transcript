<?php

namespace MrMySQL\YoutubeTranscript\Tests\Formatter;

use MrMySQL\YoutubeTranscript\Formatter\PrettyPrintFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrettyPrintFormatterTest extends TestCase
{
    public function testFormatsTranscript(): void
    {
        $transcript = [
            ['text' => 'Hello world', 'start' => 0.0, 'duration' => 1.5],
            ['text' => "It's 日本語 🎉", 'start' => 1.5, 'duration' => 2.25],
            ['text' => "line one\nline two", 'start' => 3.75, 'duration' => 1.0],
        ];

        $expected = <<<'PHP'
            [
                [
                    'text' => 'Hello world',
                    'start' => 0.0,
                    'duration' => 1.5,
                ],
                [
                    'text' => 'It\'s 日本語 🎉',
                    'start' => 1.5,
                    'duration' => 2.25,
                ],
                [
                    'text' => "line one\nline two",
                    'start' => 3.75,
                    'duration' => 1.0,
                ],
            ]
            PHP;

        $this->assertSame($expected, (new PrettyPrintFormatter())->formatTranscript($transcript));
    }

    public function testEmptyTranscript(): void
    {
        $this->assertSame('[]', (new PrettyPrintFormatter())->formatTranscript([]));
        $this->assertSame('[]', (new PrettyPrintFormatter())->formatTranscripts([]));
        $this->assertSame("[\n    [],\n]", (new PrettyPrintFormatter())->formatTranscripts([[]]));
    }

    public function testSingleCue(): void
    {
        $this->assertSame(
            "[\n    [\n        'text' => 'Only cue',\n        'start' => 2.0,\n        'duration' => 3.5,\n    ],\n]",
            (new PrettyPrintFormatter())->formatTranscript([['text' => 'Only cue', 'start' => 2.0, 'duration' => 3.5]])
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function stringProvider(): iterable
    {
        yield 'plain' => ['a --> b', "'a --> b'"];
        yield 'quote and backslash' => ["it's a\\b", "'it\\'s a\\\\b'"];
        yield 'LF' => ["a\nb", '"a\nb"'];
        yield 'CRLF and tab' => ["a\r\n\tb", '"a\r\n\tb"'];
        yield 'escapes PHP specials once double-quoted' => ["\$x \"q\" \\\n", '"\$x \"q\" \\\\\n"'];
        yield 'other control characters as hex' => ["\x00\x07\x08\x7f", '"\x00\x07\x08\x7f"'];
        yield 'multibyte' => ["日本語\n🎉", "\"日本語\\n🎉\""];
    }

    #[DataProvider('stringProvider')]
    public function testStringEscaping(string $text, string $expected): void
    {
        $this->assertSame(
            "[\n    [\n        'text' => $expected,\n        'start' => 0.0,\n        'duration' => 1.0,\n    ],\n]",
            (new PrettyPrintFormatter())->formatTranscript([['text' => $text, 'start' => 0.0, 'duration' => 1.0]])
        );
    }

    public function testOutputEvaluatesBackToTheInput(): void
    {
        $transcripts = [
            [
                ['text' => "it's \"quoted\" \$var {\$x} \\ \x00\x07\x1b\x7f", 'start' => 0.1 + 0.2, 'duration' => 1.0],
                ['text' => "日本語\r\n🎉\t-->", 'start' => 1.5, 'duration' => 2.0],
            ],
            [],
        ];

        $output = (new PrettyPrintFormatter())->formatTranscripts($transcripts);

        /** @psalm-suppress ForbiddenCode */
        $this->assertSame($transcripts, eval('return ' . $output . ';'));
    }

    public function testFormatTranscripts(): void
    {
        $expected = <<<'PHP'
            [
                [
                    [
                        'text' => 'Hello',
                        'start' => 0.0,
                        'duration' => 1.0,
                    ],
                ],
                [
                    [
                        'text' => 'Hallo',
                        'start' => 0.0,
                        'duration' => 1.0,
                    ],
                ],
            ]
            PHP;

        $this->assertSame($expected, (new PrettyPrintFormatter())->formatTranscripts([
            [['text' => 'Hello', 'start' => 0.0, 'duration' => 1.0]],
            [['text' => 'Hallo', 'start' => 0.0, 'duration' => 1.0]],
        ]));
    }
}
