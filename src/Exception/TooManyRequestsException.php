<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Exception;

use Exception;

class TooManyRequestsException extends Exception implements YoutubeTranscriptExceptionInterface
{

}
