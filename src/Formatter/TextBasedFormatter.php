<?php

namespace MrMySQL\YoutubeTranscript\Formatter;

/**
 * Shared logic of the timed subtitle formats (SRT, WebVTT).
 *
 * A cue ends at `start + duration`, or at the next cue's start if that comes earlier, so cues never overlap. Blank
 * lines inside a cue are dropped (a blank line would end the cue) and cues without any text are skipped.
 *
 * @api
 */
abstract class TextBasedFormatter implements FormatterInterface
{
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

    abstract protected function formatTimestamp(int $hours, int $minutes, int $seconds, int $milliseconds): string;

    /**
     * Makes cue text safe for the format. It is already split into non-blank lines joined with "\n".
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
            $lines = CueText::lines($snippet['text']);
            if ($lines === []) {
                continue;
            }
            $cues[] = [
                'start' => $snippet['start'],
                'end' => $snippet['start'] + $snippet['duration'],
                'text' => $this->escapeText(implode("\n", $lines)),
            ];
        }

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
