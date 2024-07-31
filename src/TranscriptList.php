<?php

namespace MrMySQL\YoutubeTranscript;

use Traversable;
use ArrayIterator;
use IteratorAggregate;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use MrMySQL\YoutubeTranscript\Exception\NoTranscriptFoundException;

class TranscriptList implements IteratorAggregate
{
    private string $video_id;
    /** Transcript[] */
    private array $manually_created_transcripts;
    /** Transcript[] */
    private array $generated_transcripts;
    private array $translation_languages;

    private function __construct(string $video_id, array $manually_created_transcripts, array $generated_transcripts, array $translation_languages)
    {
        $this->video_id = $video_id;
        $this->manually_created_transcripts = $manually_created_transcripts;
        $this->generated_transcripts = $generated_transcripts;
        $this->translation_languages = $translation_languages;
    }

    public static function build(ClientInterface $http_client, RequestFactoryInterface $request_factory, string $video_id, array $captions_json)
    {
        $translation_languages = array_map(function ($translation_language) {
            return [
                'language' => $translation_language['languageName']['simpleText'],
                'language_code' => $translation_language['languageCode']
            ];
        }, $captions_json['translationLanguages'] ?? []);

        $manually_created_transcripts = [];
        $generated_transcripts = [];

        foreach ($captions_json['captionTracks'] as $caption) {
            $t = new Transcript(
                $http_client,
                $request_factory,
                $video_id,
                $caption['baseUrl'],
                $caption['name']['simpleText'],
                $caption['languageCode'],
                ($caption['kind'] ?? '') === 'asr',
                $translation_languages
            );

            if (($caption['kind'] ?? '') === 'asr') {
                $generated_transcripts[$caption['languageCode']] = $t;
            } else {
                $manually_created_transcripts[$caption['languageCode']] = $t;   
            }
        }

        return new TranscriptList(
            $video_id,
            $manually_created_transcripts,
            $generated_transcripts,
            $translation_languages
        );
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_merge($this->manually_created_transcripts, $this->generated_transcripts));
    }

    public function getAvailableLanguageCodes(): array
    {
        return array_reduce(
            array_merge($this->manually_created_transcripts, $this->generated_transcripts),
            function(array $accumulator, Transcript $item) {
                $accumulator[] = $item->language_code;
                return $accumulator;
            },
            []
        );
    }

    public function findTranscript(array $language_codes): Transcript
    {
        return $this->_find_transcript($language_codes, [$this->manually_created_transcripts, $this->generated_transcripts]);
    }

    public function findGeneratedTranscript(array $language_codes): Transcript
    {
        return $this->_find_transcript($language_codes, [$this->generated_transcripts]);
    }

    public function findManuallyCreatedTranscript(array $language_codes): Transcript
    {
        return $this->_find_transcript($language_codes, [$this->manually_created_transcripts]);
    }

    /**
     * @param Transcript[] $transcript_dicts
     */
    private function _find_transcript(array $language_codes, array $transcript_dicts): Transcript
    {
        foreach ($language_codes as $language_code) {
            foreach ($transcript_dicts as $transcript_dict) {
                if (isset($transcript_dict[$language_code])) {
                    return $transcript_dict[$language_code];
                }
            }
        }

        throw new NoTranscriptFoundException($this->video_id);
    }

    public function __toString(): string
    {
        return sprintf(
            'For this video (%s) transcripts are available in the following languages:\n\n' .
            '(MANUALLY CREATED)\n%s\n\n' .
            '(GENERATED)\n%s\n\n' .
            '(TRANSLATION LANGUAGES)\n%s',
            $this->video_id,
            $this->getLanguageDescription(array_map('strval', $this->manually_created_transcripts)),
            $this->getLanguageDescription(array_map('strval', $this->generated_transcripts)),
            $this->getLanguageDescription(array_map(function ($translation_language) {
                return sprintf('%s ("%s")', $translation_language['language_code'], $translation_language['language']);
            }, $this->translation_languages))
        );
    }

    private function getLanguageDescription(array $transcript_strings): string
    {
        $description = implode("\n", array_map(function ($transcript) {
            return ' - ' . $transcript;
        }, $transcript_strings));
        return $description ?: 'None';
    }
}
