<?php

namespace MrMySQL\YoutubeTranscript\Tests\Formatter;

use MrMySQL\YoutubeTranscript\Formatter\WebVttFormatter;
use MrMySQL\YoutubeTranscript\TranscriptParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebVttFormatterTest extends TestCase
{
    public function testFormatsTranscript(): void
    {
        $transcript = [
            ['text' => 'Hello world', 'start' => 0.0, 'duration' => 1.5],
            ['text' => 'Ça va? 日本語 🎉', 'start' => 1.5, 'duration' => 2.25],
            ['text' => "line one\nline two", 'start' => 3.75, 'duration' => 1.0],
        ];

        $expected = <<<VTT
            WEBVTT

            00:00:00.000 --> 00:00:01.500
            Hello world

            00:00:01.500 --> 00:00:03.750
            Ça va? 日本語 🎉

            00:00:03.750 --> 00:00:04.750
            line one
            line two

            VTT;

        $this->assertSame($expected, (new WebVttFormatter())->formatTranscript($transcript));
    }

    public function testEmptyTranscriptIsHeaderOnly(): void
    {
        $this->assertSame("WEBVTT\n", (new WebVttFormatter())->formatTranscript([]));
        $this->assertSame("WEBVTT\n", (new WebVttFormatter())->formatTranscripts([]));
        $this->assertSame("WEBVTT\n", (new WebVttFormatter())->formatTranscript([['text' => "\n", 'start' => 0.0, 'duration' => 1.0]]));
    }

    public function testSingleCue(): void
    {
        $this->assertSame(
            "WEBVTT\n\n00:00:02.000 --> 00:00:05.500\nOnly cue\n",
            (new WebVttFormatter())->formatTranscript([['text' => 'Only cue', 'start' => 2.0, 'duration' => 3.5]])
        );
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function timestampProvider(): iterable
    {
        yield 'zero' => [0.0, '00:00:00.000'];
        yield 'upstream docstring example' => [6.93, '00:00:06.930'];
        yield 'rounds up into the next second' => [1.9996, '00:00:02.000'];
        yield 'rounds up into the next hour' => [3599.9996, '01:00:00.000'];
        yield 'all fields' => [3661.123, '01:01:01.123'];
        yield 'hours past 99 are not truncated' => [362439.5, '100:40:39.500'];
    }

    #[DataProvider('timestampProvider')]
    public function testTimestamp(float $seconds, string $expected): void
    {
        $this->assertSame(
            "WEBVTT\n\n$expected --> $expected\nx\n",
            (new WebVttFormatter())->formatTranscript([['text' => 'x', 'start' => $seconds, 'duration' => 0.0]])
        );
    }

    public function testOverlappingCueEndsAtNextStartAndLastCueKeepsItsDuration(): void
    {
        $transcript = [
            ['text' => 'a', 'start' => 0.0, 'duration' => 5.0],
            ['text' => 'b', 'start' => 2.0, 'duration' => 1.0],
            ['text' => 'c', 'start' => 10.0, 'duration' => 4.25],
        ];

        $this->assertSame(
            "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\na\n\n00:00:02.000 --> 00:00:03.000\nb\n\n00:00:10.000 --> 00:00:14.250\nc\n",
            (new WebVttFormatter())->formatTranscript($transcript)
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textProvider(): iterable
    {
        yield 'arrow is forbidden in cue text' => ['a --> b', 'a --&gt; b'];
        yield 'lone gt' => ['a > b', 'a &gt; b'];
        yield 'lone lt' => ['a < b', 'a &lt; b'];
        yield 'ampersand' => ['Tom & Jerry', 'Tom &amp; Jerry'];
        yield 'already-escaped entity stays literal' => ['&amp;', '&amp;amp;'];
        yield 'formatting tags are kept' => ['<i>a</i> <b>b</b> <u>c</u>', '<i>a</i> <b>b</b> <u>c</u>'];
        yield 'tag with dashes is escaped' => ['<b-->x', '&lt;b--&gt;x'];
        yield 'LF' => ["a\nb", "a\nb"];
        yield 'CRLF' => ["a\r\nb", "a\nb"];
        yield 'blank line would end the cue' => ["a\n\n\nb", "a\nb"];
        yield 'leading and trailing newlines' => ["\na\n", 'a'];
        yield 'multibyte' => ['Ελληνικά — 中文 — 🎬', 'Ελληνικά — 中文 — 🎬'];
    }

    #[DataProvider('textProvider')]
    public function testText(string $text, string $expected): void
    {
        $this->assertSame(
            "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\n$expected\n",
            (new WebVttFormatter())->formatTranscript([['text' => $text, 'start' => 0.0, 'duration' => 1.0]])
        );
    }

    public function testFormatsParsedTranscriptWithPreservedFormatting(): void
    {
        $xml = '<transcript>'
            . '<text start="0.5" dur="1.25">Fish &amp; <b>chips</b></text>'
            . '<text start="1.75" dur="2">a &lt; b --&gt; c</text>'
            . '</transcript>';

        $this->assertSame(
            "WEBVTT\n\n00:00:00.500 --> 00:00:01.750\nFish &amp; <b>chips</b>\n\n00:00:01.750 --> 00:00:03.750\na &lt; b --&gt; c\n",
            (new WebVttFormatter())->formatTranscript(TranscriptParser::parse($xml, true))
        );
    }

    public function testFormatTranscriptsWritesOneHeader(): void
    {
        $english = [['text' => 'Hello', 'start' => 1.0, 'duration' => 1.0]];
        $german = [['text' => 'Hallo', 'start' => 0.0, 'duration' => 1.0]];

        $this->assertSame(
            "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHallo\n\n00:00:01.000 --> 00:00:02.000\nHello\n",
            (new WebVttFormatter())->formatTranscripts([$english, $german])
        );
    }
}
