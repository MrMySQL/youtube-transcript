<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Formatter;

use MrMySQL\YoutubeTranscript\Exception\FormattingFailedException;

/**
 * WebVTT (.vtt), see https://www.w3.org/TR/webvtt1/: a `WEBVTT` header, then `HH:MM:SS.mmm` cues separated by blank
 * lines.
 *
 * `&`, `<` and `>` in the text are written as `&amp;`, `&lt;` and `&gt;` (so `-->`, which the spec forbids in cue
 * text, becomes `--&gt;` and still displays as `-->`). The WebVTT tags `<b>`, `<i>`, `<u>`, `<c>`, `<ruby>` and `<rt>`
 * are kept, without attributes (WebVTT has none). `<v>` and `<lang>` are not, because they need an annotation. Line
 * endings are LF.
 *
 * @api
 */
final class WebVttFormatter extends TextBasedFormatter
{
    private const ENTITIES = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;'];

    #[\Override]
    protected function supportedTags(): array
    {
        return ['b', 'i', 'u', 'c', 'ruby', 'rt'];
    }

    #[\Override]
    protected function keepsTagAttributes(): bool
    {
        return false;
    }

    #[\Override]
    protected function formatTimestamp(int $hours, int $minutes, int $seconds, int $milliseconds): string
    {
        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $seconds, $milliseconds);
    }

    #[\Override]
    protected function escapeText(string $text): string
    {
        // Group 1 is a supported tag (the only tags left at this point) to keep; any other &, < or > is escaped.
        return preg_replace_callback(
            pattern: '/(<\/?[a-z]+>)|[&<>]/',
            callback: static fn (array $m): string => $m[1] ?? self::ENTITIES[$m[0]],
            subject: $text,
            flags: PREG_UNMATCHED_AS_NULL
        ) ?? throw new FormattingFailedException(preg_last_error_msg());
    }

    #[\Override]
    protected function formatCue(int $index, string $timing, string $text): string
    {
        return $timing . "\n" . $text;
    }

    #[\Override]
    protected function formatFile(array $cues): string
    {
        return $cues === [] ? "WEBVTT\n" : "WEBVTT\n\n" . implode("\n\n", $cues) . "\n";
    }
}
