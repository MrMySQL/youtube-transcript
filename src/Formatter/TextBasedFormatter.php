<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Formatter;

use MrMySQL\YoutubeTranscript\Exception\FormattingFailedException;

/**
 * Shared logic of the timed subtitle formats (SRT, WebVTT).
 *
 * Cues are ordered by start time. A cue ends at `start + duration`, or at the next cue's start if that comes earlier,
 * so the cues of one transcript never overlap. Blank lines inside a cue are dropped (a blank line would end the cue)
 * and cues without visible text are skipped.
 *
 * Simple formatting tags (as kept by `fetch(preserve_formatting: true)`) are lowercased, `<strong>` and `<em>` become
 * `<b>` and `<i>`, and tags the format does not support (see supportedTags()) or empty ones like `<i/>` are removed
 * while their content is kept. Attributes are kept only if keepsTagAttributes() says so.
 *
 * @api
 */
abstract class TextBasedFormatter implements FormatterInterface
{
    /** An opening, closing or self-closing tag: 1 = closing slash, 2 = name, 3 = attributes, 4 = self-closing slash. */
    private const TAG_REGEX = '/<(\/?)([a-zA-Z][a-zA-Z0-9]*)((?:\s[^<>]*?)?)\s*(\/?)>/';

    private const TAG_ALIASES = ['strong' => 'b', 'em' => 'i'];

    #[\Override]
    public function formatTranscript(array $transcript): string
    {
        return $this->render($this->buildCues($transcript));
    }

    /**
     * Merges the cues of all transcripts into one file, ordered by start time. Overlap trimming is done per
     * transcript, so e.g. an original and a translated transcript can be shown together as dual subtitles.
     */
    #[\Override]
    public function formatTranscripts(array $transcripts): string
    {
        $cues = [];
        foreach ($transcripts as $transcript) {
            array_push($cues, ...$this->buildCues($transcript));
        }
        // usort() is stable, so cues starting at the same time keep the order of $transcripts.
        usort($cues, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $this->render($cues);
    }

    /**
     * Lowercase names of the tags the format supports, written as `<name>` and `</name>`.
     *
     * @return list<string>
     */
    abstract protected function supportedTags(): array;

    /**
     * Whether supported tags keep their attributes (e.g. `<font color="red">`); otherwise they are dropped.
     */
    abstract protected function keepsTagAttributes(): bool;

    abstract protected function formatTimestamp(int $hours, int $minutes, int $seconds, int $milliseconds): string;

    /**
     * Makes cue text safe for the format. It is already split into non-blank lines joined with "\n", and only tags
     * from supportedTags() are left in it, as `<name>` or `</name>`.
     */
    abstract protected function escapeText(string $text): string;

    /**
     * @param int $index 1-based position of the cue in the file
     * @param string $timing e.g. "00:00:01.000 --> 00:00:02.500"
     */
    abstract protected function formatCue(int $index, string $timing, string $text): string;

    /**
     * @param list<string> $cues the formatted cues
     */
    abstract protected function formatFile(array $cues): string;

    /**
     * Converts seconds to a timestamp, rounded to the nearest millisecond. Negative and non-finite values become 0.
     */
    protected function secondsToTimestamp(float $seconds): string
    {
        $total_ms = is_finite($seconds) && $seconds > 0 ? (int) round($seconds * 1000.0) : 0;

        return $this->formatTimestamp(
            intdiv($total_ms, 3_600_000),
            intdiv($total_ms % 3_600_000, 60_000),
            intdiv($total_ms % 60_000, 1000),
            $total_ms % 1000
        );
    }

    /**
     * @param array<array{text: string, start: float, duration: float}> $transcript
     * @return list<array{start: float, end: float, text: string}>
     */
    private function buildCues(array $transcript): array
    {
        $cues = [];
        foreach ($transcript as $snippet) {
            $text = implode("\n", CueText::lines($this->normalizeTags($snippet['text'])));
            if (trim($this->stripTags($text)) === '') {
                continue;
            }
            // Clamp here rather than only when rendering, so sorting and trimming see the same times as the output:
            // a negative or non-finite start is 0, and a cue never ends before it starts. The end is computed from the
            // unclamped start, so a cue starting before 0 still ends at start + duration.
            $raw_start = is_finite($snippet['start']) ? $snippet['start'] : 0.0;
            $start = max($raw_start, 0.0);
            $end = $raw_start + $snippet['duration'];
            $cues[] = [
                'start' => $start,
                'end' => is_finite($end) && $end > $start ? $end : $start,
                'text' => $this->escapeText($text),
            ];
        }
        // YouTube's cues are already in order; sorting (stable) only matters for hand-built transcripts.
        usort($cues, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        // A cue ends at the next cue's start if that comes before its own end. The last cue keeps its duration.
        $trimmed = [];
        foreach ($cues as $i => $cue) {
            $next = $cues[$i + 1] ?? null;
            if ($next !== null && $next['start'] < $cue['end']) {
                $cue['end'] = $next['start'];
            }
            $trimmed[] = $cue;
        }

        return $trimmed;
    }

    private function normalizeTags(string $text): string
    {
        if (!str_contains($text, '<')) {
            return $text;
        }

        $supported = $this->supportedTags();
        $keep_attributes = $this->keepsTagAttributes();

        // Repeat until stable: removing a tag can join its neighbours into a new one (`<<i/>script>` -> `<script>`).
        // A pass either removes a tag (shortening the text) or only normalizes tags in place, which is idempotent, so
        // this terminates.
        do {
            $previous = $text;
            $text = preg_replace_callback(
                self::TAG_REGEX,
                static function (array $m) use ($supported, $keep_attributes): string {
                    $name = strtolower($m[2]);
                    $name = self::TAG_ALIASES[$name] ?? $name;
                    if ($m[4] !== '' || !in_array($name, $supported, true)) {
                        return '';
                    }

                    // Line breaks inside the attributes would split the tag across cue lines.
                    $attributes = $keep_attributes && $m[1] === '' ? strtr(rtrim($m[3]), "\r\n\t\v\f", '     ') : '';

                    return '<' . $m[1] . $name . $attributes . '>';
                },
                $text
            ) ?? throw new FormattingFailedException(preg_last_error_msg());
        } while ($text !== $previous);

        return $text;
    }

    private function stripTags(string $text): string
    {
        return preg_replace(self::TAG_REGEX, '', $text) ?? throw new FormattingFailedException(preg_last_error_msg());
    }

    /**
     * @param list<array{start: float, end: float, text: string}> $cues
     */
    private function render(array $cues): string
    {
        $formatted = [];
        foreach ($cues as $i => $cue) {
            $timing = $this->secondsToTimestamp($cue['start']) . ' --> ' . $this->secondsToTimestamp($cue['end']);
            $formatted[] = $this->formatCue($i + 1, $timing, $cue['text']);
        }

        return $this->formatFile($formatted);
    }
}
