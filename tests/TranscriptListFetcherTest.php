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
    private ClientInterface&MockObject $httpClient;
    private RequestFactoryInterface&MockObject $requestFactory;
    private TranscriptListFetcher $transcriptListFetcher;
    private string $validHtml;

    public function __construct(string $name)
    {
        parent::__construct($name);
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

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->requestFactory = $this->createMock(RequestFactoryInterface::class);
        $this->transcriptListFetcher = new TranscriptListFetcher($this->httpClient, $this->requestFactory);
    }

    public function testFetchSuccess(): void
    {
        $videoId = 'video123';
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $stream->method('getContents')->willReturn($this->validHtml);

        $this->httpClient->method('sendRequest')->willReturn($response);

        $this->requestFactory
            ->method('createRequest')
            ->willReturn($this->createMock(\Psr\Http\Message\RequestInterface::class));

        $transcriptList = $this->transcriptListFetcher->fetch($videoId);

        $this->assertInstanceOf(TranscriptList::class, $transcriptList);
    }

    public function testFetchInvalidVideoIdException(): void
    {
        $this->expectException(InvalidVideoIdException::class);

        $videoId = 'https://invalid_video_id';
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $stream->method('getContents')->willReturn('<html></html>');
        $this->httpClient->method('sendRequest')->willReturn($response);
        $this->requestFactory
            ->method('createRequest')
            ->willReturn($this->createMock(\Psr\Http\Message\RequestInterface::class));

        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchTooManyRequestsException(): void
    {
        $this->expectException(TooManyRequestsException::class);

        $videoId = 'video123';
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $stream->method('getContents')->willReturn('<div class="g-recaptcha"></div>');
        $this->httpClient->method('sendRequest')->willReturn($response);
        $this->requestFactory
            ->method('createRequest')
            ->willReturn($this->createMock(\Psr\Http\Message\RequestInterface::class));

        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchVideoUnavailableException(): void
    {
        $this->expectException(VideoUnavailableException::class);

        $videoId = 'video123';
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $stream->method('getContents')->willReturn('');
        $this->httpClient
            ->method('sendRequest')
            ->willReturn($response);
        $this->requestFactory
            ->method('createRequest')
            ->willReturn($this->createMock(\Psr\Http\Message\RequestInterface::class));

        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchTranscriptsDisabledException(): void
    {
        $this->expectException(TranscriptsDisabledException::class);

        $videoId = 'video123';
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $stream->method('getContents')->willReturn('"playabilityStatus":');
        $this->httpClient
            ->method('sendRequest')
            ->willReturn($response);
        $this->requestFactory
            ->method('createRequest')
            ->willReturn($this->createMock(\Psr\Http\Message\RequestInterface::class));

        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchFailedToCreateConsentCookieException(): void
    {
        $this->expectException(FailedToCreateConsentCookieException::class);

        $html = '<html><script>window.ytplayer={};</script><form action="https://consent.youtube.com/s"></form></html>';
        $videoId = 'video123';
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $stream->method('getContents')->willReturn($html);
        $this->httpClient
            ->method('sendRequest')
            ->willReturn($response);
        $this->requestFactory
            ->method('createRequest')
            ->willReturn($this->createMock(\Psr\Http\Message\RequestInterface::class));
    
        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchYouTubeRequestFailedException(): void
    {
        $this->expectException(YouTubeRequestFailedException::class);

        $videoId = 'video123';
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $this->httpClient
            ->method('sendRequest')
            ->willReturn($response);
        $this->requestFactory
            ->method('createRequest')
            ->willReturn($this->createMock(\Psr\Http\Message\RequestInterface::class));
    
        $this->transcriptListFetcher->fetch($videoId);
    }
}
