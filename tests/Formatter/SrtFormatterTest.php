<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Tests\Formatter;

use MrMySQL\YoutubeTranscript\Formatter\SrtFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use MrMySQL\YoutubeTranscript\Formatter\CueText;
use MrMySQL\YoutubeTranscript\Formatter\TextBasedFormatter;
use PHPUnit\Framework\TestCase;

#[CoversClass(SrtFormatter::class)]
#[CoversClass(TextBasedFormatter::class)]
#[CoversClass(CueText::class)]
final class SrtFormatterTest extends TestCase
{
    public function testFormatsTranscript(): void
    {
        $transcript = [
            ['text' => 'Hello world', 'start' => 0.0, 'duration' => 1.5],
            ['text' => 'Ça va? 日本語 🎉', 'start' => 1.5, 'duration' => 2.25],
            ['text' => "line one\nline two", 'start' => 3.75, 'duration' => 1.0],
        ];

        $expected = <<<SRT
            1
            00:00:00,000 --> 00:00:01,500
            Hello world

            2
            00:00:01,500 --> 00:00:03,750
            Ça va? 日本語 🎉

            3
            00:00:03,750 --> 00:00:04,750
            line one
            line two

            SRT;

        $this->assertSame($expected, (new SrtFormatter())->formatTranscript($transcript));
    }

    public function testEmptyTranscriptIsEmptyString(): void
    {
        $this->assertSame('', (new SrtFormatter())->formatTranscript([]));
        $this->assertSame('', (new SrtFormatter())->formatTranscripts([]));
        $this->assertSame('', (new SrtFormatter())->formatTranscripts([[], []]));
    }

    public function testSingleCue(): void
    {
        $this->assertSame(
            "1\n00:00:02,000 --> 00:00:05,500\nOnly cue\n",
            (new SrtFormatter())->formatTranscript([['text' => 'Only cue', 'start' => 2.0, 'duration' => 3.5]])
        );
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function timestampProvider(): iterable
    {
        yield 'zero' => [0.0, '00:00:00,000'];
        yield 'upstream docstring example' => [6.93, '00:00:06,930'];
        yield 'float noise' => [0.1 + 0.2, '00:00:00,300'];
        yield 'sub-millisecond rounds down' => [0.0004, '00:00:00,000'];
        yield 'rounds up into the next second' => [1.9996, '00:00:02,000'];
        yield 'rounds up into the next minute' => [59.9999, '00:01:00,000'];
        yield 'rounds up into the next hour' => [3599.9996, '01:00:00,000'];
        yield 'all fields' => [3661.123, '01:01:01,123'];
        yield 'largest two-digit hour' => [359999.999, '99:59:59,999'];
        yield 'hours past 99 are not truncated' => [360000.0, '100:00:00,000'];
        yield 'hours past 99 with fields' => [362439.5, '100:40:39,500'];
        yield 'negative is clamped' => [-1.0, '00:00:00,000'];
    }

    #[DataProvider('timestampProvider')]
    public function testTimestamp(float $seconds, string $expected): void
    {
        $this->assertSame(
            "1\n$expected --> $expected\nx\n",
            (new SrtFormatter())->formatTranscript([['text' => 'x', 'start' => $seconds, 'duration' => 0.0]])
        );
    }

    public function testOverlappingCueEndsAtNextStartAndLastCueKeepsItsDuration(): void
    {
        $transcript = [
            ['text' => 'overlaps the next', 'start' => 0.0, 'duration' => 5.0],
            ['text' => 'touches the next', 'start' => 2.0, 'duration' => 1.0],
            ['text' => 'gap before the next', 'start' => 3.0, 'duration' => 1.0],
            ['text' => 'last', 'start' => 10.0, 'duration' => 4.25],
        ];

        $expected = <<<SRT
            1
            00:00:00,000 --> 00:00:02,000
            overlaps the next

            2
            00:00:02,000 --> 00:00:03,000
            touches the next

            3
            00:00:03,000 --> 00:00:04,000
            gap before the next

            4
            00:00:10,000 --> 00:00:14,250
            last

            SRT;

        $this->assertSame($expected, (new SrtFormatter())->formatTranscript($transcript));
    }

    public function testCuesWithTheSameStartFollowUpstreamRule(): void
    {
        // Same as upstream: the first cue is cut to the second one's start, which leaves it zero-length.
        $transcript = [
            ['text' => 'a', 'start' => 1.0, 'duration' => 2.0],
            ['text' => 'b', 'start' => 1.0, 'duration' => 2.0],
        ];

        $this->assertSame(
            "1\n00:00:01,000 --> 00:00:01,000\na\n\n2\n00:00:01,000 --> 00:00:03,000\nb\n",
            (new SrtFormatter())->formatTranscript($transcript)
        );
    }

    public function testCueNeverEndsBeforeItStarts(): void
    {
        $this->assertSame(
            "1\n00:00:05,000 --> 00:00:05,000\na\n\n2\n00:00:06,000 --> 00:00:06,000\nb\n",
            (new SrtFormatter())->formatTranscript([
                ['text' => 'a', 'start' => 5.0, 'duration' => -1.0],
                ['text' => 'b', 'start' => 6.0, 'duration' => INF],
            ])
        );
    }

    public function testNegativeStartIsClampedWithoutMovingTheEnd(): void
    {
        $this->assertSame(
            "1\n00:00:00,000 --> 00:00:01,000\na\n\n2\n00:00:02,000 --> 00:00:03,000\nb\n",
            (new SrtFormatter())->formatTranscript([
                ['text' => 'a', 'start' => -2.0, 'duration' => 3.0],
                ['text' => 'b', 'start' => 2.0, 'duration' => 1.0],
            ])
        );
    }

    public function testNonFiniteStartIsSortedAsZero(): void
    {
        $this->assertSame(
            "1\n00:00:00,000 --> 00:00:01,000\nx\n\n2\n00:00:01,000 --> 00:00:02,000\ny\n",
            (new SrtFormatter())->formatTranscript([
                ['text' => 'y', 'start' => 1.0, 'duration' => 1.0],
                ['text' => 'x', 'start' => NAN, 'duration' => 1.0],
            ])
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textProvider(): iterable
    {
        yield 'arrow' => ['a --> b', 'a -> b'];
        yield 'longer arrow' => ['a ---> b', 'a -> b'];
        yield 'arrow-like timing line' => ['00:00:01,000 --> 00:00:02,000', '00:00:01,000 -> 00:00:02,000'];
        yield 'single dash arrow is kept' => ['a -> b', 'a -> b'];
        yield 'LF' => ["a\nb", "a\nb"];
        yield 'CRLF' => ["a\r\nb", "a\nb"];
        yield 'CR' => ["a\rb", "a\nb"];
        yield 'blank line would end the cue' => ["a\n\nb", "a\nb"];
        yield 'whitespace-only line' => ["a\n \t \nb", "a\nb"];
        yield 'leading and trailing newlines' => ["\na\n", 'a'];
        yield 'surrounding spaces' => ['  a  ', 'a'];
        yield 'supported tags and ampersand are passed through' => [
            '<i>a</i> & <b>b</b> <u>c</u>',
            '<i>a</i> & <b>b</b> <u>c</u>',
        ];
        yield 'HTML tags map to SRT tags' => ['<strong>a</strong> <EM>b</EM>', '<b>a</b> <i>b</i>'];
        yield 'unsupported tags are removed' => ['<mark>a</mark> <c>b</c> <sub>c</sub>', 'a b c'];
        yield 'self-closing tags are removed' => ['a<i/>b', 'ab'];
        yield 'font tag keeps its attributes' => ['<font color="red">a</font>', '<font color="red">a</font>'];
        yield 'tag names are lowercased, attributes kept' => [
            '<FONT color="#fff">a</FONT> <I >b</I >',
            '<font color="#fff">a</font> <i>b</i>',
        ];
        yield 'self-closing tag with attributes is removed' => ['a<br class="x" />b', 'ab'];
        yield 'tag joined by removing a nested tag is removed too' => ['a<<i/>script>b', 'ab'];
        yield 'line break in attributes stays on one line' => [
            "<font\ncolor=\"red\">a</font>",
            '<font color="red">a</font>',
        ];
        yield 'multibyte' => [
            'Ελληνικά — 中文 — עברית — 🎬',
            'Ελληνικά — 中文 — עברית — 🎬',
        ];
    }

    #[DataProvider('textProvider')]
    public function testText(string $text, string $expected): void
    {
        $this->assertSame(
            "1\n00:00:00,000 --> 00:00:01,000\n$expected\n",
            (new SrtFormatter())->formatTranscript([['text' => $text, 'start' => 0.0, 'duration' => 1.0]])
        );
    }

    public function testBlankCuesAreSkippedAndNumberingStaysContiguous(): void
    {
        $transcript = [
            ['text' => 'a', 'start' => 0.0, 'duration' => 5.0],
            ['text' => " \n ", 'start' => 1.0, 'duration' => 1.0],
            ['text' => '', 'start' => 2.0, 'duration' => 1.0],
            ['text' => 'b', 'start' => 3.0, 'duration' => 1.0],
        ];

        $this->assertSame(
            "1\n00:00:00,000 --> 00:00:03,000\na\n\n2\n00:00:03,000 --> 00:00:04,000\nb\n",
            (new SrtFormatter())->formatTranscript($transcript)
        );
    }

    public function testCuesWithoutVisibleTextAreSkipped(): void
    {
        $transcript = [
            ['text' => 'a', 'start' => 0.0, 'duration' => 1.0],
            ['text' => '<i> </i>', 'start' => 1.0, 'duration' => 1.0],
            ['text' => '<b/>', 'start' => 2.0, 'duration' => 1.0],
            ['text' => 'b', 'start' => 3.0, 'duration' => 1.0],
        ];

        $this->assertSame(
            "1\n00:00:00,000 --> 00:00:01,000\na\n\n2\n00:00:03,000 --> 00:00:04,000\nb\n",
            (new SrtFormatter())->formatTranscript($transcript)
        );
    }

    public function testOutOfOrderCuesAreSortedBeforeTrimming(): void
    {
        // Unsorted, the 2s cue would be followed by the 0s one and end before it starts.
        $transcript = [
            ['text' => 'b', 'start' => 2.0, 'duration' => 1.0],
            ['text' => 'a', 'start' => 0.0, 'duration' => 5.0],
            ['text' => 'c', 'start' => 2.0, 'duration' => 2.0],
        ];

        // Stable sort: the two cues at 2s keep their input order.
        $this->assertSame(
            "1\n00:00:00,000 --> 00:00:02,000\na\n\n"
            . "2\n00:00:02,000 --> 00:00:02,000\nb\n\n"
            . "3\n00:00:02,000 --> 00:00:04,000\nc\n",
            (new SrtFormatter())->formatTranscript($transcript)
        );
    }

    public function testAcceptsIntegerTimes(): void
    {
        // Hand-built transcripts often use ints; strict_types still allows int -> float.
        $this->assertSame(
            "1\n00:00:00,000 --> 00:00:02,000\na\n\n2\n00:00:02,000 --> 00:00:03,000\nb\n",
            (new SrtFormatter())->formatTranscript([
                ['text' => 'a', 'start' => 0, 'duration' => 5],
                ['text' => 'b', 'start' => 2, 'duration' => 1],
            ])
        );
    }

    public function testIgnoresArrayKeys(): void
    {
        $transcript = [
            5 => ['text' => 'a', 'start' => 0.0, 'duration' => 2.0],
            'x' => ['text' => 'b', 'start' => 1.0, 'duration' => 1.0],
        ];

        $this->assertSame(
            "1\n00:00:00,000 --> 00:00:01,000\na\n\n2\n00:00:01,000 --> 00:00:02,000\nb\n",
            (new SrtFormatter())->formatTranscript($transcript)
        );
    }

    public function testFormatTranscriptsMergesCuesByStartTime(): void
    {
        $english = [
            ['text' => 'Hello', 'start' => 0.0, 'duration' => 3.0],
            ['text' => 'World', 'start' => 2.0, 'duration' => 2.0],
        ];
        $german = [
            ['text' => 'Hallo', 'start' => 0.0, 'duration' => 2.5],
            ['text' => 'Welt', 'start' => 2.5, 'duration' => 1.5],
        ];

        // Cues are trimmed only against the next cue of the same transcript; equal starts keep the input order.
        $expected = <<<SRT
            1
            00:00:00,000 --> 00:00:02,000
            Hello

            2
            00:00:00,000 --> 00:00:02,500
            Hallo

            3
            00:00:02,000 --> 00:00:04,000
            World

            4
            00:00:02,500 --> 00:00:04,000
            Welt

            SRT;

        $this->assertSame($expected, (new SrtFormatter())->formatTranscripts([$english, $german]));
    }
}
