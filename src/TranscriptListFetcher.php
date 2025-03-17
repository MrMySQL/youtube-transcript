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
use Psr\Log\LoggerInterface;

class TranscriptListFetcher
{
    private const WATCH_URL = 'https://www.youtube.com/watch?v=%s';

    public function __construct(
        private ClientInterface $http_client,
        private RequestFactoryInterface $request_factory,
        private ?LoggerInterface $logger = null,
    ) {

    }

    public function fetch(string $video_id): TranscriptList
    {
        $video_page_html = $this->fetchVideoHtml($video_id);

        $this->logger?->debug('Loaded video page content. video id: {video_id}, content: {content}', [
            'video_id' => $video_id,
            'content' => $video_page_html,
        ]);
        
        return TranscriptList::build(
            $this->http_client,
            $this->request_factory,
            $video_id,
            $this->extractCaptionsJson($video_page_html, $video_id),
            $this->extractVideoTitle($video_page_html)
        );
    }

    private function extractCaptionsJson(string $html, string $video_id): array
    {
        $splitted_html = explode('"captions":', $html);

        if (count($splitted_html) <= 1) {
            if (strpos($video_id, 'http://') === 0 || strpos($video_id, 'https://') === 0) {
                $this->logger?->error('Invalid video ID detected. video id: {video_id}', ['video_id' => $video_id]);
                throw new InvalidVideoIdException($video_id);
            }
            if (strpos($html, 'class="g-recaptcha"') !== false) {
                $this->logger?->error('Too many requests detected. video id: {video_id}', ['video_id' => $video_id]);
                throw new TooManyRequestsException($video_id);
            }
            if (strpos($html, '"playabilityStatus":') === false) {
                $this->logger?->error('Video unavailable. video id: {video_id}', ['video_id' => $video_id]);
                throw new VideoUnavailableException($video_id);
            }

            $this->logger?->error('Transcripts are disabled for the video. video id: {video_id}', ['video_id' => $video_id]);
            throw new TranscriptsDisabledException($video_id);
        }

        $captions_json = json_decode(
            explode(',"videoDetails', str_replace("\n", '', $splitted_html[1]))[0], true
        )['playerCaptionsTracklistRenderer'] ?? null;

        if ($captions_json === null) {
            $this->logger?->error('Captions JSON is null. video id: {video_id}', ['video_id' => $video_id]);
            throw new TranscriptsDisabledException($video_id);
        }

        if (!isset($captions_json['captionTracks'])) {
            $this->logger?->error('No transcript available for the video. video id: {video_id}', ['video_id' => $video_id]);
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
                $this->logger?->error('Failed to create consent cookie. video id: {video_id}', ['video_id' => $video_id]);
                throw new FailedToCreateConsentCookieException($video_id);
            }

            $html = $this->fetchHtml($video_id, $match[1]);
            if (strpos($html, 'action="https://consent.youtube.com/s"') !== false) {
                $this->logger?->error('Failed to create consent cookie after retry. video id: {video_id}', ['video_id' => $video_id]);
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
            $this->logger?->error('YouTube request failed. Status code: {status_code}, Reason: {reason}', [
                'status_code' => $response->getStatusCode(),
                'reason' => $response->getReasonPhrase(),
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
