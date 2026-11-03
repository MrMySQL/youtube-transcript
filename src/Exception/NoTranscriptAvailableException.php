<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Exception;

use Exception;

/**
 * @api
 */
class NoTranscriptAvailableException extends Exception implements YoutubeTranscriptExceptionInterface
{

}
