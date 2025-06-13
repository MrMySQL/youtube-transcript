<?php

namespace MrMySQL\YoutubeTranscript;

use MrMySQL\YoutubeTranscript\Exception\FailedToCreateConsentCookieException;
use MrMySQL\YoutubeTranscript\Exception\InvalidVideoIdException;
use MrMySQL\YoutubeTranscript\Exception\NoTranscriptAvailableException;
use MrMySQL\YoutubeTranscript\Exception\TooManyRequestsException;
use MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\Exception\VideoUnavailableException;
use MrMySQL\YoutubeTranscript\Exception\YouTubeRequestFailedException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

class TranscriptListFetcher
{
    private const WATCH_URL = 'https://www.youtube.com/watch?v=%s';
    private const INNERTUBE_API_URL = 'https://www.youtube.com/youtubei/v1/player?key=%s';
    private const INNERTUBE_CONTEXT = [
        'client' => [
            'clientName' => 'ANDROID',
            'clientVersion' => '20.10.38',
        ],
    ];

    public function __construct(
        private ClientInterface $http_client,
        private RequestFactoryInterface $request_factory,
        private StreamFactoryInterface $stream_factory,
        private ?LoggerInterface $logger = null,
    ) {

    }

    public function fetch(string $video_id): TranscriptList
    {
        $video_page_html = $this->fetchVideoHtml($video_id);
        $api_key = $this->extractInnertubeApiKey($video_page_html, $video_id);
        $innertube_data = $this->fetchInnertubeData($video_id, $api_key);
        try {
            return TranscriptList::build(
                $this->http_client,
                $this->request_factory,
                $video_id,
                $this->extractCaptionsJson($innertube_data, $video_id),
                $this->extractVideoTitle($video_page_html)
            );
        } catch (\Throwable $th) {
            $this->logger?->debug('Loaded video page content. video id: {video_id}, content: {content}', [
                'video_id' => $video_id,
                'content' => $video_page_html,
            ]);
            throw $th;
        }
    }

    private function extractInnertubeApiKey(string $html, string $video_id): string
    {
        if (preg_match('/"INNERTUBE_API_KEY"\s*:\s*"([a-zA-Z0-9_-]+)"/', $html, $matches) && isset($matches[1])) {
            return $matches[1];
        }
        if (strpos($html, 'class="g-recaptcha"') !== false) {
            throw new TooManyRequestsException($video_id);
        }
        throw new YouTubeRequestFailedException('Could not extract INNERTUBE_API_KEY');
    }

    private function fetchInnertubeData(string $video_id, string $api_key): array
    {
        $url = sprintf(self::INNERTUBE_API_URL, $api_key);
        $body = json_encode([
            'context' => self::INNERTUBE_CONTEXT,
            'videoId' => $video_id,
        ]);
        $stream = $this->stream_factory->createStream($body);
        $request = $this->request_factory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept-Language', 'en-US')
            ->withBody($stream);
        $response = $this->http_client->sendRequest($request);
        if ($response->getStatusCode() >= 400) {
            throw new YouTubeRequestFailedException($response->getReasonPhrase());
        }
        $json = json_decode($response->getBody()->getContents(), true);
        if (!is_array($json)) {
            throw new YouTubeRequestFailedException('Invalid JSON from Innertube API');
        }
        return $json;
    }

    private function extractCaptionsJson(array $innertube_data, string $video_id): array
    {
        if (!isset($innertube_data['playabilityStatus'])) {
            throw new YouTubeRequestFailedException('Missing playabilityStatus');
        }
        // TODO: Add playability status checks and throw appropriate exceptions as in Python
        $captions_json = $innertube_data['captions']['playerCaptionsTracklistRenderer'] ?? null;
        if ($captions_json === null) {
            throw new TranscriptsDisabledException($video_id);
        }
        if (!isset($captions_json['captionTracks'])) {
            throw new NoTranscriptAvailableException($video_id);
        }
        return $captions_json;
    }

    private function fetchVideoHtml(string $video_id): string
    {
        $html = $this->fetchHtml($video_id);
        if (strpos($html, 'action="https://consent.youtube.com/s"') !== false) {
            preg_match('/name="v" value="(.*?)"/', $html, $match);
            if (!$match) {
                throw new FailedToCreateConsentCookieException($video_id);
            }

            $html = $this->fetchHtml($video_id, $match[1]);
            if (strpos($html, 'action="https://consent.youtube.com/s"') !== false) {
                throw new FailedToCreateConsentCookieException($video_id);
            }
        }
        return $html;
    }

    private function fetchHtml(string $video_id, string $with_consent = ''): string
    {
        $url = sprintf(self::WATCH_URL, $video_id);
        $request = $this->request_factory->createRequest('GET', $url);
        $request->withHeader('Accept-Language', 'en-US');

        if ($with_consent) {
            $request->withHeader('Set-Cookie', 'CONSENT=YES+' . $with_consent . '; Domain=.youtube.com; Path=/; HttpOnly');
        }

        $response = $this->http_client->sendRequest($request);

        if ($response->getStatusCode() >= 400) {
            $this->logger?->error('YouTube request failed. Status code: {status_code}, Reason: {reason}, Response: {response}', [
                'status_code' => $response->getStatusCode(),
                'reason' => $response->getReasonPhrase(),
                'response' => $response->getBody()->getContents(),
            ]);
            throw new YouTubeRequestFailedException($response->getReasonPhrase());
        }
        return htmlspecialchars_decode($response->getBody()->getContents());
    }

    public function extractVideoTitle(string $html): string
    {
        preg_match('/<meta name="title" content="(.*?)"/', $html, $match);
        if (!$match) {
            return '';
        }
        return $match[1];
    }
}
