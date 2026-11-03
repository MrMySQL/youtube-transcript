<?php

namespace MrMySQL\YoutubeTranscript\Formatter;

/**
 * Turns fetched transcripts into a string of a given format (JSON, plain text, SRT, WebVTT, ...).
 *
 * A transcript is the array returned by Transcript::fetch(). Implement this interface to add your own format.
 *
 * @api
 */
interface FormatterInterface
{
    /**
     * Formats a single transcript.
     *
     * @param array<array{text: string, start: float, duration: float}> $transcript
     */
    public function formatTranscript(array $transcript): string;

    /**
     * Formats several transcripts (e.g. one per language) into one string.
     *
     * @param array<array<array{text: string, start: float, duration: float}>> $transcripts
     */
    public function formatTranscripts(array $transcripts): string;
}
