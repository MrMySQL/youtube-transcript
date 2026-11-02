<?php

namespace MrMySQL\YoutubeTranscript;

use MrMySQL\YoutubeTranscript\Exception\FailedToCreateConsentCookieException;
use MrMySQL\YoutubeTranscript\Exception\NoTranscriptAvailableException;
use MrMySQL\YoutubeTranscript\Exception\TooManyRequestsException;
use MrMySQL\YoutubeTranscript\Exception\RequestBlockedException;
use MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException;
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
        [$video_page_html, $consent_cookie] = $this->fetchVideoHtml($video_id);
        $api_key = $this->extractInnertubeApiKey($video_page_html, $video_id);
        $innertube_data = $this->fetchInnertubeData($video_id, $api_key, $consent_cookie);
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

    private function fetchInnertubeData(string $video_id, string $api_key, ?string $consent_cookie = null): array
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
        if ($consent_cookie !== null) {
            $request = $request->withHeader('Cookie', $consent_cookie);
        }
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

        $playability_status = $innertube_data['playabilityStatus'];
        $status = $playability_status['status'] ?? null;
        $reason = $playability_status['reason'] ?? '';

        // YouTube refuses suspected bot traffic with LOGIN_REQUIRED and a reason like
        // "Sign in to confirm you’re not a bot", stripping captions from the response.
        // Surface this distinctly so callers don't mistake a blocked IP/proxy for a
        // video that genuinely has transcripts disabled.
        if ($status === 'LOGIN_REQUIRED' && stripos($reason, 'bot') !== false) {
            throw new RequestBlockedException($video_id . ': ' . $reason);
        }

        $captions_json = $innertube_data['captions']['playerCaptionsTracklistRenderer'] ?? null;
        if ($captions_json === null) {
            throw new TranscriptsDisabledException($video_id);
        }
        if (!isset($captions_json['captionTracks'])) {
            throw new NoTranscriptAvailableException($video_id);
        }
        return $captions_json;
    }

    /**
     * @return array{0: string, 1: ?string} the watch page HTML and, if a consent
     *                                      wall had to be passed, the Cookie header
     *                                      value to reuse on follow-up requests
     */
    private function fetchVideoHtml(string $video_id): array
    {
        $html = $this->fetchHtml($video_id);
        if (strpos($html, 'action="https://consent.youtube.com/s"') === false) {
            return [$html, null];
        }

        $consent_cookie = $this->createConsentCookie($html, $video_id);
        $html = $this->fetchHtml($video_id, $consent_cookie);
        if (strpos($html, 'action="https://consent.youtube.com/s"') !== false) {
            throw new FailedToCreateConsentCookieException($video_id);
        }
        return [$html, $consent_cookie];
    }

    private function createConsentCookie(string $html, string $video_id): string
    {
        // The value must be a non-empty RFC 6265 cookie-octet string, otherwise it
        // would produce a malformed (or injected) Cookie header.
        if (
            !preg_match('/name="v" value="(.*?)"/', $html, $match)
            || !preg_match('/^[\x21\x23-\x2B\x2D-\x3A\x3C-\x5B\x5D-\x7E]+$/', $match[1])
        ) {
            throw new FailedToCreateConsentCookieException($video_id);
        }
        return 'CONSENT=YES+' . $match[1];
    }

    private function fetchHtml(string $video_id, ?string $consent_cookie = null): string
    {
        $url = sprintf(self::WATCH_URL, $video_id);
        $request = $this->request_factory->createRequest('GET', $url)
            ->withHeader('Accept-Language', 'en-US');

        if ($consent_cookie !== null) {
            $request = $request->withHeader('Cookie', $consent_cookie);
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
