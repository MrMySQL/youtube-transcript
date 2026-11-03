<?php

namespace MrMySQL\YoutubeTranscript\Tests\Formatter;

use MrMySQL\YoutubeTranscript\Exception\UnknownFormatterException;
use MrMySQL\YoutubeTranscript\Exception\YoutubeTranscriptExceptionInterface;
use MrMySQL\YoutubeTranscript\Formatter\FormatterInterface;
use MrMySQL\YoutubeTranscript\Formatter\FormatterLoader;
use MrMySQL\YoutubeTranscript\Formatter\JsonFormatter;
use MrMySQL\YoutubeTranscript\Formatter\PrettyPrintFormatter;
use MrMySQL\YoutubeTranscript\Formatter\SrtFormatter;
use MrMySQL\YoutubeTranscript\Formatter\TextFormatter;
use MrMySQL\YoutubeTranscript\Formatter\WebVttFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatterLoaderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function typeProvider(): iterable
    {
        yield 'json' => ['json', JsonFormatter::class];
        yield 'pretty' => ['pretty', PrettyPrintFormatter::class];
        yield 'text' => ['text', TextFormatter::class];
        yield 'webvtt' => ['webvtt', WebVttFormatter::class];
        yield 'srt' => ['srt', SrtFormatter::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('typeProvider')]
    public function testLoadsFormatterByName(string $type, string $class): void
    {
        $this->assertSame($class, get_class((new FormatterLoader())->load($type)));
    }

    public function testEveryTypeLoads(): void
    {
        $this->assertSame(['json', 'pretty', 'text', 'webvtt', 'srt'], FormatterLoader::TYPES);
        foreach (FormatterLoader::TYPES as $type) {
            $this->assertInstanceOf(FormatterInterface::class, (new FormatterLoader())->load($type));
        }
    }

    public function testDefaultsToPretty(): void
    {
        $this->assertInstanceOf(PrettyPrintFormatter::class, (new FormatterLoader())->load());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownTypeProvider(): iterable
    {
        yield 'unknown' => ['xml'];
        yield 'empty' => [''];
        yield 'names are case-sensitive' => ['JSON'];
        yield 'file extension is not a name' => ['vtt'];
    }

    #[DataProvider('unknownTypeProvider')]
    public function testUnknownTypeThrows(string $type): void
    {
        try {
            (new FormatterLoader())->load($type);
            $this->fail('Expected UnknownFormatterException');
        } catch (UnknownFormatterException $e) {
            $this->assertInstanceOf(YoutubeTranscriptExceptionInterface::class, $e);
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertSame(
                "The format '$type' is not supported. Choose one of the following formats: json, pretty, text, webvtt, srt",
                $e->getMessage()
            );
        }
    }
}
