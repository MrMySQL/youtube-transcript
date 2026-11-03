<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Formatter;

/**
 * Plain text without timestamps: one line per cue.
 *
 * Line breaks inside a cue are joined with a space, so line N of the output is always cue N. Several transcripts are
 * separated by two blank lines.
 *
 * @api
 */
final class TextFormatter implements FormatterInterface
{
    #[\Override]
    public function formatTranscript(array $transcript): string
    {
        return implode("\n", array_map(
            static fn (array $snippet): string => implode(' ', CueText::lines($snippet['text'])),
            $transcript
        ));
    }

    #[\Override]
    public function formatTranscripts(array $transcripts): string
    {
        return implode("\n\n\n", array_map($this->formatTranscript(...), $transcripts));
    }
}
