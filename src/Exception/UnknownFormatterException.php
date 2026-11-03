<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Exception;

use InvalidArgumentException;

/**
 * Thrown by FormatterLoader::load() for a format name it does not know.
 *
 * @api
 */
final class UnknownFormatterException extends InvalidArgumentException implements YoutubeTranscriptExceptionInterface
{
    /**
     * @param list<string> $supported_formats
     */
    public function __construct(private readonly string $format, array $supported_formats)
    {
        parent::__construct(sprintf(
            "The format '%s' is not supported. Choose one of the following formats: %s",
            $format,
            implode(', ', $supported_formats)
        ));
    }

    /**
     * The format name that was asked for.
     */
    public function getFormat(): string
    {
        return $this->format;
    }
}
