<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Formatter;

use MrMySQL\YoutubeTranscript\Exception\FormattingFailedException;

/**
 * SubRip (.srt): numbered cues starting at 1, `HH:MM:SS,mmm` timestamps, cues separated by a blank line.
 *
 * SRT has no escape mechanism, and parsers find the timing line by its `-->`, so an arrow in the text (`-->`,
 * `--->`, ...) is shortened to `->`. The tags SRT players support, `<b>`, `<i>`, `<u>` and `<font ...>`, are kept
 * with their attributes. Line endings are LF.
 *
 * @api
 */
final class SrtFormatter extends TextBasedFormatter
{
    #[\Override]
    protected function supportedTags(): array
    {
        return ['b', 'i', 'u', 'font'];
    }

    #[\Override]
    protected function keepsTagAttributes(): bool
    {
        return true;
    }

    #[\Override]
    protected function formatTimestamp(int $hours, int $minutes, int $seconds, int $milliseconds): string
    {
        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $seconds, $milliseconds);
    }

    #[\Override]
    protected function escapeText(string $text): string
    {
        return preg_replace('/-{2,}>/', '->', $text)
            ?? throw new FormattingFailedException(preg_last_error_msg());
    }

    #[\Override]
    protected function formatCue(int $index, string $timing, string $text): string
    {
        return $index . "\n" . $timing . "\n" . $text;
    }

    #[\Override]
    protected function formatFile(array $cues): string
    {
        return $cues === [] ? '' : implode("\n\n", $cues) . "\n";
    }
}
