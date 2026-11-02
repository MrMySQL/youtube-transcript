<?php

namespace MrMySQL\YoutubeTranscript\Tests;

use PHPUnit\Framework\TestCase;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;
use MrMySQL\YoutubeTranscript\TranscriptList;
use MrMySQL\YoutubeTranscript\Exception\FailedToCreateConsentCookieException;
use MrMySQL\YoutubeTranscript\Exception\TooManyRequestsException;
use MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\Exception\RequestBlockedException;
use MrMySQL\YoutubeTranscript\Exception\YouTubeRequestFailedException;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\RequestInterface;

class TranscriptListFetcherTest extends TestCase
{
    private ClientInterface&MockObject $httpClientMock;
    private RequestFactoryInterface&MockObject $requestFactoryMock;
    private StreamFactoryInterface&MockObject $streamFactoryMock;
    private TranscriptListFetcher $transcriptListFetcher;
    private string $validHtml;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->requestFactoryMock = $this->createMock(RequestFactoryInterface::class);
        $this->streamFactoryMock = $this->createMock(StreamFactoryInterface::class);
        $this->transcriptListFetcher = new TranscriptListFetcher(
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock
        );
        $this->validHtml = '<html><meta name="title" content="Test Video Title">{"INNERTUBE_API_KEY":"test_api_key"}</html>';
    }

    public function testFetchSuccess(): void
    {
        $videoId = 'video123';
        $innertubeJson = [
            'playabilityStatus' => ['status' => 'OK'],
            'captions' => [
                'playerCaptionsTracklistRenderer' => [
                    'captionTracks' => [
                        [
                            'languageCode' => 'en',
                            'baseUrl' => 'http://example.com/captions/en',
                            'name' => ['runs' => [['text' => 'English']]],
                        ],
                        [
                            'languageCode' => 'es',
                            'baseUrl' => 'http://example.com/captions/es',
                            'name' => ['runs' => [['text' => 'Spanish']]],
                        ]
                    ],
                    'translationLanguages' => [
                        [
                            'languageName' => ['runs' => [['text' => 'French']]],
                            'languageCode' => 'fr',
                        ]
                    ]
                ]
            ]
        ];
        $this->mockRequest($this->validHtml, json_encode($innertubeJson));

        $transcriptList = $this->transcriptListFetcher->fetch($videoId);

        $this->assertInstanceOf(TranscriptList::class, $transcriptList);
    }

    public function testFetchTooManyRequestsException(): void
    {
        $this->expectException(TooManyRequestsException::class);

        $videoId = 'video123';
        $html = '<div class="g-recaptcha"></div>';
        $this->mockRequest($html);

        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchTooManyRequestsExceptionOnWatchPage429(): void
    {
        $this->expectException(TooManyRequestsException::class);
        $this->mockStatusSequence([429]);

        $this->transcriptListFetcher->fetch('video123');
    }

    public function testWatchPage429IsLoggedAndCarriesRetryAfter(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('YouTube request failed'), $this->callback(
                fn (array $context) => $context['status_code'] === 429
            ));
        $fetcher = new TranscriptListFetcher(
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
            $logger
        );
        $this->mockStatusSequence([429], ['Retry-After' => '120']);

        try {
            $fetcher->fetch('video123');
            $this->fail('Expected TooManyRequestsException');
        } catch (TooManyRequestsException $e) {
            $this->assertSame('video123: YouTube responded with HTTP 429 Too Many Requests (Retry-After: 120)', $e->getMessage());
            $this->assertSame('120', $e->getRetryAfter());
            $this->assertInstanceOf(YouTubeRequestFailedException::class, $e);
        }
    }

    public function testFetchTooManyRequestsExceptionOnInnertube429(): void
    {
        $this->expectException(TooManyRequestsException::class);
        $this->mockStatusSequence([200, 429]);

        $this->transcriptListFetcher->fetch('video123');
    }

    /**
     * Mocks one response per request with the given status codes; the 200 response
     * carries the valid watch-page HTML.
     *
     * @param int[] $statuses
     * @param array<string, string> $headers headers on every response
     */
    private function mockStatusSequence(array $statuses, array $headers = []): void
    {
        $responses = array_map(function (int $status) use ($headers) {
            $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
            $stream->method('getContents')->willReturn($status === 200 ? $this->validHtml : '');
            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn($status);
            $response->method('getReasonPhrase')->willReturn($status === 429 ? 'Too Many Requests' : 'OK');
            $response->method('getBody')->willReturn($stream);
            $response->method('getHeaderLine')->willReturnCallback(fn (string $name) => $headers[$name] ?? '');
            return $response;
        }, $statuses);
        $this->httpClientMock->method('sendRequest')->willReturnOnConsecutiveCalls(...$responses);

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();
        $this->requestFactoryMock->method('createRequest')->willReturn($request);
        $this->streamFactoryMock
            ->method('createStream')
            ->willReturn($this->createMock(\Psr\Http\Message\StreamInterface::class));
    }

    public function testFetchTranscriptsDisabledException(): void
    {
        $this->expectException(TranscriptsDisabledException::class);

        $videoId = 'video123';
        $html = '<html>{"INNERTUBE_API_KEY":"test_api_key"}</html>';
        $innertubeJson = [
            'playabilityStatus' => ['status' => 'OK'],
            'captions' => [
                'playerCaptionsTracklistRenderer' => null
            ]
        ];
        $this->mockRequest($html, json_encode($innertubeJson));

        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchRequestBlockedExceptionWhenLoginRequired(): void
    {
        // When YouTube bot-blocks the request (e.g. from a flagged datacenter/proxy IP),
        // the innertube response has playabilityStatus.status = LOGIN_REQUIRED and no captions.
        // This must be reported as a RequestBlockedException, NOT TranscriptsDisabledException,
        // so callers can tell a blocked proxy apart from a genuinely caption-less video.
        $this->expectException(RequestBlockedException::class);

        $videoId = 'video123';
        $html = '<html>{"INNERTUBE_API_KEY":"test_api_key"}</html>';
        $innertubeJson = [
            'playabilityStatus' => [
                'status' => 'LOGIN_REQUIRED',
                'reason' => 'Sign in to confirm you’re not a bot',
            ],
        ];
        $this->mockRequest($html, json_encode($innertubeJson));

        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchFailedToCreateConsentCookieException(): void
    {
        $this->expectException(FailedToCreateConsentCookieException::class);

        $videoId = 'video123';
        $html = '<html><script>window.ytplayer={};</script><form action="https://consent.youtube.com/s"></form>{"INNERTUBE_API_KEY":"test_api_key"}</html>';
        $this->mockRequest($html);
    
        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchYouTubeRequestFailedException(): void
    {
        $this->expectException(YouTubeRequestFailedException::class);

        $videoId = 'video123';
        $html = '<html><body>random alpha</body></html>';
        $this->mockRequest($html);

        $this->transcriptListFetcher->fetch($videoId);
    }

    private function mockRequest(string $html, ?string $json = null): void
    {
        $responseHtml = $this->createMock(ResponseInterface::class);
        $responseHtml->method('getStatusCode')->willReturn(200);
        $streamHtml = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $responseHtml->method('getBody')->willReturn($streamHtml);
        $streamHtml->method('getContents')->willReturn($html);

        $requestMockGet = $this->createMock(\Psr\Http\Message\RequestInterface::class);
        $requestMockGet->method('withHeader')->willReturnSelf();
        $requestMockGet->method('withBody')->willReturnSelf();
        $requestMockPost = $this->createMock(\Psr\Http\Message\RequestInterface::class);
        $requestMockPost->method('withHeader')->willReturnSelf();
        $requestMockPost->method('withBody')->willReturnSelf();

        if ($json !== null) {
            $responseJson = $this->createMock(ResponseInterface::class);
            $responseJson->method('getStatusCode')->willReturn(200);
            $streamJson = $this->createMock(\Psr\Http\Message\StreamInterface::class);
            $responseJson->method('getBody')->willReturn($streamJson);
            $streamJson->method('getContents')->willReturn($json);

            $this->httpClientMock
                ->method('sendRequest')
                ->willReturnOnConsecutiveCalls($responseHtml, $responseJson);

            $this->requestFactoryMock
                ->method('createRequest')
                ->willReturnCallback(function() use ($requestMockGet, $requestMockPost) {
                    static $count = 0;
                    $count++;
                    $mock = $count === 1 ? $requestMockGet : $requestMockPost;
                    // Debug output
                    fwrite(STDERR, "Returning request mock #$count: " . get_class($mock) . "\n");
                    return $mock;
                });
        } else {
            $this->httpClientMock
                ->method('sendRequest')
                ->willReturn($responseHtml);
            $this->requestFactoryMock
                ->method('createRequest')
                ->willReturnCallback(function() use ($requestMockGet) {
                    fwrite(STDERR, "Returning single request mock: " . get_class($requestMockGet) . "\n");
                    return $requestMockGet;
                });
        }
        $this->streamFactoryMock
            ->method('createStream')
            ->willReturn($this->createMock(\Psr\Http\Message\StreamInterface::class));
    }
}
