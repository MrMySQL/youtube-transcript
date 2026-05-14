<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript;

use Psr\Http\Client\ClientInterface;
use MrMySQL\YoutubeTranscript\Exception\NotTranslatableException;
use MrMySQL\YoutubeTranscript\Exception\YouTubeRequestFailedException;
use MrMySQL\YoutubeTranscript\Exception\TranslationLanguageNotAvailableException;
use Psr\Http\Message\RequestFactoryInterface;

class Transcript
{
    /** @var array<string, string> */
    private array $translation_languages_dict;

    public function __construct(
        private ClientInterface $http_client,
        private RequestFactoryInterface $request_factory,
        public string $video_id,
        private string $url,
        public string $language,
        public string $language_code,
        private bool $is_generated,
        private array $translation_languages
    ) {
        $this->video_id = $video_id;
        $this->url = $url;
        $this->language = $language;
        $this->language_code = $language_code;
        $this->is_generated = $is_generated;
        $this->translation_languages = $translation_languages;
        /** @psalm-suppress MixedPropertyTypeCoercion */
        $this->translation_languages_dict = array_column($translation_languages, 'language', 'language_code');
    }

    /**
     * @return array<array{text: string, start: float, duration: float}>
     */
    public function fetch(bool $preserve_formatting = false): array
    {
        if (strpos($this->url, '&exp=xpe') !== false) {
            throw new Exception\PoTokenRequiredException();
        }
        try {
            $request = $this->request_factory->createRequest('GET', $this->url)
                ->withHeader('Accept-Language', 'en-US')
                ->withHeader('Content-Type', 'text/html; charset=utf-8');
            $response = $this->http_client->sendRequest($request);
            if ($response->getStatusCode() >= 400) {
                throw new YouTubeRequestFailedException($response->getReasonPhrase());
            }

            return TranscriptParser::parse(
                $response->getBody()->getContents(),
                $preserve_formatting
            );
        } catch (YouTubeRequestFailedException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new YouTubeRequestFailedException($e->getMessage(), 0, $e);
        }
    }

    public function __toString(): string
    {
        return sprintf('%s ("%s")%s', $this->language_code, $this->language, $this->isTranslatable() ? '[TRANSLATABLE]' : '');
    }

    public function isTranslatable(): bool
    {
        return !empty($this->translation_languages);
    }

    public function translate(string $language_code): Transcript
    {
        if (!$this->isTranslatable()) {
            throw new NotTranslatableException($this->video_id);
        }

        if (!isset($this->translation_languages_dict[$language_code])) {
            throw new TranslationLanguageNotAvailableException($this->video_id);
        }

        return new Transcript(
            $this->http_client,
            $this->request_factory,
            $this->video_id,
            $this->url . '&tlang=' . urlencode($language_code),
            $this->translation_languages_dict[$language_code],
            $language_code,
            true,
            []
        );
    }

    public function isGenerated(): bool
    {
        return $this->is_generated;
    }

    public function getUrl(): string
    {
        return $this->url;
    }
}
