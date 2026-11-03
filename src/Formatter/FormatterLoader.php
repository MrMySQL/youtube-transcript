<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Formatter;

use MrMySQL\YoutubeTranscript\Exception\UnknownFormatterException;

/**
 * Picks a formatter by name, e.g. from a CLI option or a request parameter.
 *
 * @api
 */
final class FormatterLoader
{
    /** @var list<string> */
    public const TYPES = ['json', 'pretty', 'text', 'webvtt', 'srt'];

    /**
     * @throws UnknownFormatterException if $type is not one of self::TYPES
     */
    public function load(string $type = 'pretty'): FormatterInterface
    {
        return match ($type) {
            'json' => new JsonFormatter(),
            'pretty' => new PrettyPrintFormatter(),
            'text' => new TextFormatter(),
            'webvtt' => new WebVttFormatter(),
            'srt' => new SrtFormatter(),
            default => throw new UnknownFormatterException($type, self::TYPES),
        };
    }
}
