<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Tests\Formatter;

use JsonException;
use MrMySQL\YoutubeTranscript\Exception\FormattingFailedException;
use MrMySQL\YoutubeTranscript\Exception\YoutubeTranscriptExceptionInterface;
use MrMySQL\YoutubeTranscript\Formatter\JsonFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonFormatter::class)]
#[CoversClass(FormattingFailedException::class)]
final class JsonFormatterTest extends TestCase
{
    private const TRANSCRIPT = [
        ['text' => 'Hello world', 'start' => 0.0, 'duration' => 1.5],
        ['text' => 'Ça va? 日本語 🎉 a/b "q" \\', 'start' => 1.5, 'duration' => 2.0],
        ['text' => "line one\nline two --> <i>x</i>", 'start' => 3.5, 'duration' => 1.25],
    ];

    public function testFormatsTranscript(): void
    {
        $this->assertSame(
            '[{"text":"Hello world","start":0.0,"duration":1.5},'
            . '{"text":"Ça va? 日本語 🎉 a/b \"q\" \\\\","start":1.5,"duration":2.0},'
            . '{"text":"line one\nline two --> <i>x</i>","start":3.5,"duration":1.25}]',
            (new JsonFormatter())->formatTranscript(self::TRANSCRIPT)
        );
    }

    public function testRoundTripsThroughJsonDecode(): void
    {
        $json = (new JsonFormatter())->formatTranscript(self::TRANSCRIPT);

        $this->assertSame(self::TRANSCRIPT, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testEmptyTranscript(): void
    {
        $this->assertSame('[]', (new JsonFormatter())->formatTranscript([]));
        $this->assertSame('[]', (new JsonFormatter())->formatTranscripts([]));
        $this->assertSame('[[]]', (new JsonFormatter())->formatTranscripts([[]]));
    }

    public function testSingleCue(): void
    {
        $this->assertSame(
            '[{"text":"Only cue","start":2.0,"duration":3.5}]',
            (new JsonFormatter())->formatTranscript([['text' => 'Only cue', 'start' => 2.0, 'duration' => 3.5]])
        );
    }

    public function testExtraFlagsAreAddedToTheDefaults(): void
    {
        $expected = <<<'JSON'
            [
                {
                    "text": "日本語 a/b",
                    "start": 1.0,
                    "duration": 2.0
                }
            ]
            JSON;

        $this->assertSame(
            $expected,
            (new JsonFormatter(JSON_PRETTY_PRINT))->formatTranscript([
                ['text' => '日本語 a/b', 'start' => 1.0, 'duration' => 2.0],
            ])
        );
    }

    public function testOutputIsAListEvenForNonSequentialKeys(): void
    {
        $this->assertSame(
            '[[{"text":"a","start":0.0,"duration":1.0}]]',
            (new JsonFormatter())->formatTranscripts([
                'en' => [3 => ['text' => 'a', 'start' => 0.0, 'duration' => 1.0]],
            ])
        );
    }

    public function testFormatTranscripts(): void
    {
        $this->assertSame(
            '[[{"text":"Hello","start":0.0,"duration":1.0}],[{"text":"Hallo","start":0.0,"duration":1.0}]]',
            (new JsonFormatter())->formatTranscripts([
                [['text' => 'Hello', 'start' => 0.0, 'duration' => 1.0]],
                [['text' => 'Hallo', 'start' => 0.0, 'duration' => 1.0]],
            ])
        );
    }

    public function testInvalidUtf8ThrowsLibraryException(): void
    {
        try {
            (new JsonFormatter())->formatTranscript([['text' => "bad \xB1\x31", 'start' => 0.0, 'duration' => 1.0]]);
            $this->fail('Expected FormattingFailedException');
        } catch (FormattingFailedException $e) {
            $this->assertInstanceOf(YoutubeTranscriptExceptionInterface::class, $e);
            $this->assertInstanceOf(JsonException::class, $e->getPrevious());
            $this->assertStringStartsWith('Failed to encode the transcript as JSON: ', $e->getMessage());
        }
    }
}
