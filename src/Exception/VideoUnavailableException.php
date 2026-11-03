<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Exception;

/**
 * Thrown when YouTube reports the video as unavailable (e.g. deleted, or the id
 * does not exist): playabilityStatus.status = ERROR, "This video is unavailable".
 *
 * @api
 */
class VideoUnavailableException extends VideoUnplayableException
{

}
