<?php

namespace MrMySQL\YoutubeTranscript\Exception;

use Exception;

/**
 * Thrown when YouTube reports the video as not playable for a reason not covered
 * by a more specific exception (e.g. private video, members-only, region-blocked).
 */
class VideoUnplayableException extends Exception implements YoutubeTranscriptExceptionInterface
{
    /**
     * @param string[] $sub_reasons
     */
    public function __construct(
        private string $video_id,
        private string $reason,
        private array $sub_reasons = [],
    ) {
        $message = sprintf('%s: %s', $video_id, $reason !== '' ? $reason : 'No reason specified');
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
