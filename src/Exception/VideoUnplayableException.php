<?php

namespace MrMySQL\YoutubeTranscript\Exception;

use Exception;

/**
 * Thrown when YouTube reports the video as not playable (e.g. private video,
 * members-only, region-blocked). Subclassed by AgeRestrictedException and
 * VideoUnavailableException, so catching this also covers those two.
 *
 * Not covered: a bot block of your IP/proxy is reported as
 * RequestBlockedException, since it is not a property of the video.
 */
class VideoUnplayableException extends Exception implements YoutubeTranscriptExceptionInterface
{
    /**
     * @param string[] $sub_reasons
     */
    public function __construct(
        private string $video_id,
        private string $reason = '',
        private array $sub_reasons = [],
    ) {
        // With only one argument this behaves like a plain Exception ("new X($message)"),
        // which keeps VideoUnavailableException's pre-existing constructor usage working.
        $message = $reason !== '' ? sprintf('%s: %s', $video_id, $reason) : $video_id;
        if ($sub_reasons) {
            $message .= "\n" . implode("\n", array_map(fn (string $sub) => ' - ' . $sub, $sub_reasons));
        }
        parent::__construct($message);
    }

    public function getVideoId(): string
    {
        return $this->video_id;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * @return string[]
     */
    public function getSubReasons(): array
    {
        return $this->sub_reasons;
    }
}
