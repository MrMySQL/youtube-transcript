<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Formatter;

use MrMySQL\YoutubeTranscript\Exception\FormattingFailedException;

/**
 * Turns fetched transcripts into a string of a given format (JSON, plain text, SRT, WebVTT, ...).
 *
 * A transcript is the array returned by Transcript::fetch(). Implement this interface to add your own format, or
 * extend TextBasedFormatter for another timed subtitle format.
 *
 * @api
 */
interface FormatterInterface
{
    /**
     * Formats a single transcript.
     *
     * @param array<array{text: string, start: float, duration: float}> $transcript
     * @throws FormattingFailedException if the transcript cannot be encoded in this format
     */
    public function formatTranscript(array $transcript): string;

    /**
     * Formats several transcripts (e.g. one per language) into one string.
     *
     * @param array<array<array{text: string, start: float, duration: float}>> $transcripts
     * @throws FormattingFailedException if a transcript cannot be encoded in this format
     */
    public function formatTranscripts(array $transcripts): string;
}
