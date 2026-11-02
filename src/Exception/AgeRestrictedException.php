<?php

namespace MrMySQL\YoutubeTranscript\Exception;

use Exception;

/**
 * Thrown when the video is age-restricted and cannot be played without signing in
 * (playabilityStatus.status = LOGIN_REQUIRED, "This video may be inappropriate for some users.").
 */
class AgeRestrictedException extends Exception implements YoutubeTranscriptExceptionInterface
{

}
