<?php

namespace MrMySQL\YoutubeTranscript\Formatter;

/**
 * Renders transcripts as a readable PHP array literal, the PHP counterpart of Python's `pprint.pformat()`.
 *
 * Like `var_export()`, the output is valid PHP that evaluates back to the input, but it uses short `[]` syntax,
 * four-space indentation, omits the keys of lists, and writes control characters as escapes (`"a\nb"`) so every
 * value stays on one line.
 *
 * @api
 */
class PrettyPrintFormatter implements FormatterInterface
{
    private const INDENT = '    ';

    private const ESCAPES = [
        "\n" => '\n',
        "\r" => '\r',
        "\t" => '\t',
        "\v" => '\v',
        "\e" => '\e',
        "\f" => '\f',
        '\\' => '\\\\',
        '"' => '\"',
        '$' => '\$',
    ];

    #[\Override]
    public function formatTranscript(array $transcript): string
    {
        return $this->export(array_values($transcript), 0);
    }

    #[\Override]
    public function formatTranscripts(array $transcripts): string
    {
        return $this->export(array_values(array_map('array_values', $transcripts)), 0);
    }

    private function export(mixed $value, int $depth): string
    {
        if (is_string($value)) {
            return $this->exportString($value);
        }
        if (!is_array($value)) {
            return var_export($value, true);
        }
        if ($value === []) {
            return '[]';
        }

        $is_list = array_is_list($value);
        $indent = str_repeat(self::INDENT, $depth + 1);
        $output = "[\n";
        /** @psalm-suppress MixedAssignment */
        foreach ($value as $key => $item) {
            $output .= $indent
                . ($is_list ? '' : var_export($key, true) . ' => ')
                . $this->export($item, $depth + 1)
                . ",\n";
        }

        return $output . str_repeat(self::INDENT, $depth) . ']';
    }

    private function exportString(string $value): string
    {
        if (!preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return var_export($value, true);
        }

        // Double-quoted so control characters can be escaped; only escapes PHP understands are used.
        return '"' . preg_replace_callback(
            '/[\x00-\x1f\x7f\\\\"$]/',
            static fn (array $m): string => self::ESCAPES[$m[0]] ?? sprintf('\x%02x', ord($m[0])),
            $value
        ) . '"';
    }
}
