<?php

namespace MrMySQL\YoutubeTranscript;

class TranscriptParser
{
    private const FORMATTING_TAGS = [
        'strong',  // important
        'em',      // emphasized
        'b',       // bold
        'i',       // italic
        'mark',    // marked
        'small',   // smaller
        'del',     // deleted
        'ins',     // inserted
        'sub',     // subscript
        'sup',     // superscript
    ];

    /**
     * Parses the given plain data into an array of transcripts.
     * 
     * @return array<array{text: string, start: float, duration: float}>
     */
    public static function parse(string $plain_data, bool $preserve_formatting = false): array
    {
        if (!$plain_data) {
            return [];
        }

        $html_regex = self::getHtmlRegex($preserve_formatting);
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($plain_data);
        libxml_clear_errors();

        $transcripts = [];
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('/transcript/text');
    
        foreach ($nodes as $node) {
            $innerXml = '';
            foreach ($node->childNodes as $child) {
                $innerXml .= $dom->saveXML($child);
            }

            $decodedContent = html_entity_decode($innerXml, ENT_QUOTES | ENT_XML1);
            if ($node->nodeValue !== null) {
                $transcripts[] = [
                    'text' => preg_replace($html_regex, '', $decodedContent),
                    'start' => (float) $node->getAttribute('start'),
                    'duration' => (float) $node->getAttribute('dur')
                ];
            }
        }
    
        return $transcripts;
    }

    /**
     * @return non-empty-string
     */
    private static function getHtmlRegex(bool $preserve_formatting): string
    {
        if ($preserve_formatting) {
            $formats_regex = implode('|', self::FORMATTING_TAGS);
            $formats_regex = sprintf('<\/?(?!\/?(%s)\b).*?\b>', $formats_regex);
            return sprintf('/%s/i', $formats_regex);
        } else {
            return '/<[^>]*>/i';
        }
    }
}