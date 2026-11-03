<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Formatter;

/**
 * @internal
 */
final class CueText
{
    /**
     * Splits cue text into its non-blank lines, trimmed. Handles LF, CRLF and CR line endings.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        $lines = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
