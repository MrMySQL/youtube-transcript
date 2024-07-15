<?php

namespace MrMySQL\YoutubeTranscript;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\RequestExceptionInterface;
use MrMySQL\YoutubeTranscript\Exception\NotTranslatableException;
use MrMySQL\YoutubeTranscript\Exception\YouTubeRequestFailedException;
use MrMySQL\YoutubeTranscript\Exception\TranslationLanguageNotAvailableException;
use Psr\Http\Message\RequestFactoryInterface;

class Transcript
{
    private ClientInterface $http_client;
    private RequestFactoryInterface $request_factory;
    public string $video_id;
    private string $url;
    public string $language;
    public string $language_code;
    public bool $is_generated;
    public array $translation_languages;
    private array $translation_languages_dict;

    public function __construct(ClientInterface $http_client, RequestFactoryInterface $request_factory, string $video_id, string $url, string $language, string $language_code, bool $is_generated, array $translation_languages)
    {
        $this->http_client = $http_client;
        $this->request_factory = $request_factory;
        $this->video_id = $video_id;
        $this->url = $url;
        $this->language = $language;
        $this->language_code = $language_code;
        $this->is_generated = $is_generated;
        $this->translation_languages = $translation_languages;
        $this->translation_languages_dict = array_column($translation_languages, 'language', 'language_code');
    }

    public function fetch($preserve_formatting = false)
    {
        try {
            $request = $this->request_factory->createRequest('GET', $this->url); 
            $request->withHeader('Accept-Language', 'en-US');
            $request->withHeader('Content-Type', 'text/html; charset=utf-8');
            $response = $this->http_client->sendRequest($request);
            if ($response->getStatusCode() >= 400) {
                throw new YouTubeRequestFailedException($response->getReasonPhrase());
            }

            return TranscriptParser::parse(
                $response->getBody()->getContents(),
                $preserve_formatting
            );
        } catch (RequestExceptionInterface $e) {
            throw new YouTubeRequestFailedException($e->getMessage(), $this->video_id);
        }
    }

    public function __toString()
    {
        return sprintf('%s ("%s")%s', $this->language_code, $this->language, $this->isTranslatable() ? '[TRANSLATABLE]' : '');
    }

    public function isTranslatable(): bool
    {
        return !empty($this->translation_languages);
    }

    public function translate($language_code)
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
            $this->url . '&tlang=' . $language_code,
            $this->translation_languages_dict[$language_code],
            $language_code,
            true,
            []
        );
    }
}