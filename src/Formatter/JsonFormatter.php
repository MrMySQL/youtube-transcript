<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Formatter;

use JsonException;
use MrMySQL\YoutubeTranscript\Exception\FormattingFailedException;

/**
 * Encodes transcripts as JSON.
 *
 * Unicode and slashes are left unescaped and floats keep their fraction (`1.0`, not `1`), so the output
 * decodes back to the same `start`/`duration` floats. Pass extra `JSON_*` flags (e.g. `JSON_PRETTY_PRINT`)
 * to the constructor; they are added to the defaults.
 *
 * @api
 */
final class JsonFormatter implements FormatterInterface
{
    public const DEFAULT_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(private readonly int $flags = 0)
    {
    }

    /**
     * @throws FormattingFailedException if the transcript cannot be encoded (e.g. invalid UTF-8)
     */
    #[\Override]
    public function formatTranscript(array $transcript): string
    {
        return $this->encode(array_values($transcript));
    }

    /**
     * @throws FormattingFailedException if a transcript cannot be encoded (e.g. invalid UTF-8)
     */
    #[\Override]
    public function formatTranscripts(array $transcripts): string
    {
        return $this->encode(array_values(array_map(array_values(...), $transcripts)));
    }

    /**
     * @param list<mixed> $data
     */
    private function encode(array $data): string
    {
        try {
            // Never false: JSON_THROW_ON_ERROR turns every failure into an exception.
            return (string) json_encode($data, self::DEFAULT_FLAGS | $this->flags | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new FormattingFailedException('Failed to encode the transcript as JSON: ' . $e->getMessage(), 0, $e);
        }
    }
}
