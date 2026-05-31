<?php

namespace MrMySQL\YoutubeTranscript\Exception;

use Exception;

/**
 * Thrown when YouTube blocks the request as suspected bot traffic
 * (e.g. playabilityStatus.status = LOGIN_REQUIRED, "Sign in to confirm you’re not a bot").
 *
 * This is distinct from TranscriptsDisabledException: the video may well have
 * transcripts, but the requesting IP/proxy was refused. Callers can use this to
 * blacklist a blocked proxy rather than concluding the video has no captions.
 */
class RequestBlockedException extends Exception implements YoutubeTranscriptExceptionInterface
{

}
