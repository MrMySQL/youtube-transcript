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
    /** @var RequestInterface[] */
    private array $sentRequests = [];

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

    public function testFetchRetriesWatchPageWithConsentCookie(): void
    {
        // The EU consent interstitial must be answered by retrying the watch page with
        // a `Cookie: CONSENT=YES+<v>` request header (not a `Set-Cookie` response header).
        $consentHtml = '<form action="https://consent.youtube.com/s"><input type="hidden" name="v" value="cb.20260101-00-p0.en+FX+123"></form>';
        $innertubeJson = [
            'playabilityStatus' => ['status' => 'OK'],
            'captions' => ['playerCaptionsTracklistRenderer' => ['captionTracks' => []]],
        ];
        $this->mockResponses($consentHtml, $this->validHtml, json_encode($innertubeJson));

        $transcriptList = $this->transcriptListFetcher->fetch('video123');

        $this->assertSame('Test Video Title', $transcriptList->getTitle());
        $this->assertSame('', $this->sentRequests[0]->getHeaderLine('Cookie'));
        $this->assertSame('CONSENT=YES+cb.20260101-00-p0.en+FX+123', $this->sentRequests[1]->getHeaderLine('Cookie'));
        $this->assertSame('', $this->sentRequests[1]->getHeaderLine('Set-Cookie'));
        $this->assertSame('en-US', $this->sentRequests[1]->getHeaderLine('Accept-Language'));
    }

    public function testFetchYouTubeRequestFailedException(): void
    {
        $this->expectException(YouTubeRequestFailedException::class);

        $videoId = 'video123';
        $html = '<html><body>random alpha</body></html>';
        $this->mockRequest($html);

        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchSendsWatchPageRequestWithHeaders(): void
    {
        $innertubeJson = [
            'playabilityStatus' => ['status' => 'OK'],
            'captions' => ['playerCaptionsTracklistRenderer' => ['captionTracks' => []]],
        ];
        $this->mockRequest($this->validHtml, json_encode($innertubeJson));

        $this->transcriptListFetcher->fetch('video123');

        $this->assertSame('GET', $this->sentRequests[0]->getMethod());
        $this->assertSame('en-US', $this->sentRequests[0]->getHeaderLine('Accept-Language'));
        $this->assertSame('POST', $this->sentRequests[1]->getMethod());
        $this->assertSame('application/json', $this->sentRequests[1]->getHeaderLine('Content-Type'));
    }

    private function mockRequest(string $html, ?string $json = null): void
    {
        $this->mockResponses(...($json === null ? [$html] : [$html, $json]));
    }

    /**
     * Answers successive requests with the given bodies (status 200); once they run
     * out, the last one is repeated. Every request actually sent is recorded in
     * $this->sentRequests.
     */
    private function mockResponses(string ...$bodies): void
    {
        $responses = array_map(fn (string $body) => $this->createResponse($body), $bodies);
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$responses) {
                $this->sentRequests[] = $request;
                return count($responses) > 1 ? array_shift($responses) : $responses[0];
            });
        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturnCallback(fn (string $method) => $this->createImmutableRequest($method));
        $this->streamFactoryMock
            ->method('createStream')
            ->willReturn($this->createMock(\Psr\Http\Message\StreamInterface::class));
    }

    private function createResponse(string $body, int $status = 200): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getContents')->willReturn($body);
        $response->method('getBody')->willReturn($stream);
        return $response;
    }

    /**
     * A request mock with PSR-7 semantics: withHeader()/withBody() return a new
     * instance and leave the original unchanged, so a discarded return value means
     * the header is never sent - exactly as with a real PSR-7 implementation.
     *
     * @param array<string, string> $headers
     */
    private function createImmutableRequest(string $method, array $headers = []): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('withHeader')->willReturnCallback(
            fn (string $name, string $value) => $this->createImmutableRequest($method, [$name => $value] + $headers)
        );
        $request->method('withBody')->willReturnCallback(
            fn () => $this->createImmutableRequest($method, $headers)
        );
        $request->method('getHeaderLine')->willReturnCallback(fn (string $name) => $headers[$name] ?? '');
        return $request;
    }
}
