<?php

namespace MrMySQL\YoutubeTranscript\Exception;

use InvalidArgumentException;

/**
 * Thrown by FormatterLoader::load() for a format name it does not know.
 *
 * @api
 */
class UnknownFormatterException extends InvalidArgumentException implements YoutubeTranscriptExceptionInterface
{
    /**
     * @param list<string> $supported_formats
     */
    public function __construct(string $format, array $supported_formats)
    {
        parent::__construct(sprintf(
            "The format '%s' is not supported. Choose one of the following formats: %s",
            $format,
            implode(', ', $supported_formats)
        ));
    }
}
