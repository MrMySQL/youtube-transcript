<?php

namespace MrMySQL\YoutubeTranscript\Tests;

use MrMySQL\YoutubeTranscript\TranscriptParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranscriptParserTest extends TestCase
{
    /**
     * Test parsing a valid XML string without preserving formatting.
     */
    public function testParseValidXmlWithoutFormatting(): void
    {
        $xml = <<<XML
<transcript>
    <text start="0.0" dur="1.5">Hello, world!</text>
    <text start="1.5" dur="2.0">Welcome to testing.</text>
</transcript>
XML;

        $expected = [
            ['text' => 'Hello, world!', 'start' => 0.0, 'duration' => 1.5],
            ['text' => 'Welcome to testing.', 'start' => 1.5, 'duration' => 2.0],
        ];

        $result = TranscriptParser::parse($xml, false);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test parsing a valid XML string with formatting preservation.
     */
    public function testParseValidXmlWithFormatting(): void
    {
        $xml = <<<XML
<transcript>
    <text start="0.0" dur="1.5">Hello, <b>world</b>!</text>
    <text start="1.5" dur="2.0">Welcome to <i>testing</i>.</text>
</transcript>
XML;

        $expected = [
            ['text' => 'Hello, <b>world</b>!', 'start' => 0.0, 'duration' => 1.5],
            ['text' => 'Welcome to <i>testing</i>.', 'start' => 1.5, 'duration' => 2.0],
        ];

        $result = TranscriptParser::parse($xml, true);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test parsing an empty XML string.
     */
    public function testParseEmptyXml(): void
    {
        $result = TranscriptParser::parse('', false);
        $this->assertEmpty($result);
    }

    /**
     * Test parsing an XML string with unsupported tags.
     */
    public function testParseXmlWithUnsupportedTags(): void
    {
        $xml = <<<XML
<transcript>
    <text start="0.0" dur="1.5">Hello, <custom>world</custom>!</text>
    <text start="1.5" dur="2.0">Welcome to <unknown>testing</unknown>.</text>
</transcript>
XML;

        $expected = [
            ['text' => 'Hello, world!', 'start' => 0.0, 'duration' => 1.5],
            ['text' => 'Welcome to testing.', 'start' => 1.5, 'duration' => 2.0],
        ];

        $result = TranscriptParser::parse($xml, false);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test parsing an invalid XML string.
     */
    public function testParseInvalidXml(): void
    {
        $xml = <<<XML
<transcript>
    <text start="0.0" dur="1.5">Hello, world!</text>
    <text start="1.5" dur="2.0">Welcome to testing.
</transcript>
XML;

        $result = TranscriptParser::parse($xml, false);

        // Expecting an empty result due to invalid XML.
        $this->assertEmpty($result);
    }

    /**
     * Empty cues (self-closing or with no content) are skipped instead of
     * producing snippets with empty text.
     */
    public function testParseSkipsEmptyCues(): void
    {
        $xml = <<<XML
<transcript>
    <text start="0.0" dur="1.5">Hello</text>
    <text start="1.5" dur="1.0"/>
    <text start="2.5" dur="1.0"></text>
    <text start="3.5" dur="2.0">world</text>
</transcript>
XML;

        $expected = [
            ['text' => 'Hello', 'start' => 0.0, 'duration' => 1.5],
            ['text' => 'world', 'start' => 3.5, 'duration' => 2.0],
        ];

        $this->assertEquals($expected, TranscriptParser::parse($xml, false));
        $this->assertEquals($expected, TranscriptParser::parse($xml, true));
    }

    /**
     * Cues that are whitespace-only or contain only markup (as elements or
     * entity-escaped, the way YouTube sends formatting) carry no text either.
     */
    #[DataProvider('textlessCueProvider')]
    public function testParseSkipsTextlessCues(string $cue): void
    {
        $xml = '<transcript><text start="0.0" dur="1.0">Hi</text>' . $cue . '</transcript>';
        $expected = [['text' => 'Hi', 'start' => 0.0, 'duration' => 1.0]];

        $this->assertEquals($expected, TranscriptParser::parse($xml, false));
        $this->assertEquals($expected, TranscriptParser::parse($xml, true));
    }

    public static function textlessCueProvider(): array
    {
        return [
            'whitespace only' => ['<text start="1" dur="1">  </text>'],
            'newline only' => ["<text start=\"1\" dur=\"1\">\n</text>"],
            'empty element markup' => ['<text start="1" dur="1"><font></font></text>'],
            'escaped empty formatting tag' => ['<text start="1" dur="1">&lt;i&gt;&lt;/i&gt;</text>'],
            'escaped markup and whitespace' => ['<text start="1" dur="1"> &lt;font color="#fff"&gt; &lt;/font&gt; </text>'],
        ];
    }

    /**
     * A cue whose only text is inside an escaped formatting tag is kept.
     */
    public function testParseKeepsEscapedFormattedCue(): void
    {
        $xml = '<transcript><text start="0.0" dur="1.0">&lt;i&gt;music&lt;/i&gt;</text></transcript>';

        $this->assertEquals(
            [['text' => 'music', 'start' => 0.0, 'duration' => 1.0]],
            TranscriptParser::parse($xml, false)
        );
        $this->assertEquals(
            [['text' => '<i>music</i>', 'start' => 0.0, 'duration' => 1.0]],
            TranscriptParser::parse($xml, true)
        );
    }

    /**
     * A cue that starts with a tag has content and must be kept (upstream's
     * `.text is None` check would drop it; we deliberately don't).
     */
    public function testParseKeepsCueStartingWithTag(): void
    {
        $xml = '<transcript><text start="0.0" dur="1.0"><b>Bold</b> start</text></transcript>';

        $this->assertEquals(
            [['text' => 'Bold start', 'start' => 0.0, 'duration' => 1.0]],
            TranscriptParser::parse($xml, false)
        );
        $this->assertEquals(
            [['text' => '<b>Bold</b> start', 'start' => 0.0, 'duration' => 1.0]],
            TranscriptParser::parse($xml, true)
        );
    }

    /**
     * Test parsing an XML string with missing attributes.
     */
    public function testParseXmlWithMissingAttributes(): void
    {
        $xml = <<<XML
<transcript>
    <text>Hello, world!</text>
    <text start="1.5">Welcome to testing.</text>
</transcript>
XML;

        $expected = [
            ['text' => 'Hello, world!', 'start' => 0.0, 'duration' => 0.0],
            ['text' => 'Welcome to testing.', 'start' => 1.5, 'duration' => 0.0],
        ];

        $result = TranscriptParser::parse($xml, false);

        $this->assertEquals($expected, $result);
    }
}
