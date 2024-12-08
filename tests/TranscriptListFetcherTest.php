<?php

namespace MrMySQL\YoutubeTranscript\Tests;

use PHPUnit\Framework\TestCase;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;
use MrMySQL\YoutubeTranscript\TranscriptList;
use MrMySQL\YoutubeTranscript\Exception\FailedToCreateConsentCookieException;
use MrMySQL\YoutubeTranscript\Exception\InvalidVideoIdException;
use MrMySQL\YoutubeTranscript\Exception\TooManyRequestsException;
use MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\Exception\VideoUnavailableException;
use MrMySQL\YoutubeTranscript\Exception\YouTubeRequestFailedException;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class TranscriptListFetcherTest extends TestCase
{
    private ClientInterface&MockObject $httpClientMock;
    private RequestFactoryInterface&MockObject $requestFactoryMock;
    private TranscriptListFetcher $transcriptListFetcher;
    private string $validHtml;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->requestFactoryMock = $this->createMock(RequestFactoryInterface::class);
        $this->transcriptListFetcher = new TranscriptListFetcher($this->httpClientMock, $this->requestFactoryMock);
        $this->validHtml = '<html><meta name="title" content="Test Video Title"><script>
            var ytInitialPlayerResponse = {
            "captions":{
                "playerCaptionsTracklistRenderer": {
                "captionTracks": [
                    {
                    "languageCode": "en",
                    "baseUrl": "http://example.com/captions/en",
                    "name": {
                        "simpleText": "English"
                    }
                    },
                    {
                    "languageCode": "es",
                    "baseUrl": "http://example.com/captions/es",
                    "name": {
                        "simpleText": "Spanish"
                    }
                    }
                ]
                }
            },"videoDetails": {
                "title": "Sample Video",
                "videoId": "abc123"
            }
            };
        </script></html>';
    }

    public function testFetchSuccess(): void
    {
        $videoId = 'video123';
        $this->mockRequest($this->validHtml);

        $transcriptList = $this->transcriptListFetcher->fetch($videoId);

        $this->assertInstanceOf(TranscriptList::class, $transcriptList);
    }

    public function testFetchInvalidVideoIdException(): void
    {
        $this->expectException(InvalidVideoIdException::class);

        $videoId = 'https://invalid_video_id';
        $html = '<html></html>';
        $this->mockRequest($html);
        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchTooManyRequestsException(): void
    {
        $this->expectException(TooManyRequestsException::class);

        $videoId = 'video123';
        $html = '<div class="g-recaptcha"></div>';
        $this->mockRequest($html);

        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchVideoUnavailableException(): void
    {
        $this->expectException(VideoUnavailableException::class);

        $videoId = 'video123';
        $this->mockRequest('');

        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchTranscriptsDisabledException(): void
    {
        $this->expectException(TranscriptsDisabledException::class);

        $videoId = 'video123';
        $html = '"playabilityStatus":';
        $this->mockRequest($html);

        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchFailedToCreateConsentCookieException(): void
    {
        $this->expectException(FailedToCreateConsentCookieException::class);

        $videoId = 'video123';
        $html = '<html><script>window.ytplayer={};</script><form action="https://consent.youtube.com/s"></form></html>';
        $this->mockRequest($html);
    
        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchYouTubeRequestFailedException(): void
    {
        $this->expectException(YouTubeRequestFailedException::class);

        $videoId = 'video123';
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($response);
        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createMock(\Psr\Http\Message\RequestInterface::class));
    
        $this->transcriptListFetcher->fetch($videoId);
    }

    private function mockRequest(string $html): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $stream->method('getContents')->willReturn($html);
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($response);
        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createMock(\Psr\Http\Message\RequestInterface::class));
    }
}
