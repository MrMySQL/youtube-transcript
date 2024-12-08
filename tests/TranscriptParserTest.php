<?php

namespace MrMySQL\YoutubeTranscript\Tests;

use MrMySQL\YoutubeTranscript\TranscriptParser;
use PHPUnit\Framework\TestCase;

class TranscriptParserTest extends TestCase
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
