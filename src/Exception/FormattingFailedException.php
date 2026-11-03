<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Exception;

use RuntimeException;

/**
 * Thrown when a formatter cannot encode the given transcript, e.g. JSON-encoding text that is not valid UTF-8.
 *
 * @api
 */
final class FormattingFailedException extends RuntimeException implements YoutubeTranscriptExceptionInterface
{
}
