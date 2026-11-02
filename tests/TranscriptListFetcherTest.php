<?php

namespace MrMySQL\YoutubeTranscript\Tests;

use PHPUnit\Framework\TestCase;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;
use MrMySQL\YoutubeTranscript\TranscriptList;
use MrMySQL\YoutubeTranscript\Exception\AgeRestrictedException;
use MrMySQL\YoutubeTranscript\Exception\FailedToCreateConsentCookieException;
use MrMySQL\YoutubeTranscript\Exception\InvalidVideoIdException;
use MrMySQL\YoutubeTranscript\Exception\TooManyRequestsException;
use MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\Exception\RequestBlockedException;
use MrMySQL\YoutubeTranscript\Exception\VideoUnavailableException;
use MrMySQL\YoutubeTranscript\Exception\VideoUnplayableException;
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

    public function testFetchAgeRestrictedException(): void
    {
        $this->mockPlayability([
            'status' => 'LOGIN_REQUIRED',
            'reason' => 'This video may be inappropriate for some users.',
        ]);

        try {
            $this->transcriptListFetcher->fetch('video123');
            $this->fail('Expected AgeRestrictedException');
        } catch (AgeRestrictedException $e) {
            // Specific playability exceptions are VideoUnplayableExceptions carrying the reason.
            $this->assertInstanceOf(VideoUnplayableException::class, $e);
            $this->assertSame('This video may be inappropriate for some users.', $e->getReason());
            $this->assertSame('video123: This video may be inappropriate for some users.', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function ageGatedProvider(): array
    {
        return [
            'inappropriate reason' => [['status' => 'LOGIN_REQUIRED', 'reason' => 'This video may be inappropriate for some users.']],
            'confirm your age reason' => [['status' => 'LOGIN_REQUIRED', 'reason' => 'Sign in to confirm your age']],
            'age verification status' => [['status' => 'AGE_VERIFICATION_REQUIRED', 'reason' => 'Verify your age']],
            'age check status' => [['status' => 'AGE_CHECK_REQUIRED']],
            'legacy age gate marker' => [['status' => 'UNPLAYABLE', 'desktopLegacyAgeGateReason' => 1]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ageGatedProvider')]
    public function testFetchDetectsAgeGateVariants(array $playabilityStatus): void
    {
        $this->expectException(AgeRestrictedException::class);
        $this->mockPlayability($playabilityStatus);

        $this->transcriptListFetcher->fetch('video123');
    }

    public function testFetchGenericErrorStatusIsUnplayableNotUnavailable(): void
    {
        $this->mockPlayability(['status' => 'ERROR', 'reason' => 'Playback on other websites has been disabled']);

        try {
            $this->transcriptListFetcher->fetch('video123');
            $this->fail('Expected VideoUnplayableException');
        } catch (VideoUnplayableException $e) {
            $this->assertNotInstanceOf(VideoUnavailableException::class, $e);
            $this->assertNotInstanceOf(AgeRestrictedException::class, $e);
            $this->assertSame('Playback on other websites has been disabled', $e->getReason());
        }
    }

    public function testFetchEmptyCaptionTracksDoNotBypassPlayabilityCheck(): void
    {
        $this->expectException(VideoUnavailableException::class);
        $this->mockRequest($this->validHtml, json_encode([
            'playabilityStatus' => ['status' => 'ERROR', 'reason' => 'This video is unavailable'],
            'captions' => ['playerCaptionsTracklistRenderer' => ['captionTracks' => []]],
        ]));

        $this->transcriptListFetcher->fetch('video123');
    }

    public function testVideoUnavailableExceptionKeepsExceptionStyleConstructor(): void
    {
        // It existed before as a plain Exception subclass; `new X($message)` must keep working.
        $e = new VideoUnavailableException('some message');

        $this->assertSame('some message', $e->getMessage());
        $this->assertSame('', $e->getReason());
        $this->assertSame([], $e->getSubReasons());
    }

    public function testFetchVideoUnavailableException(): void
    {
        $this->mockPlayability(['status' => 'ERROR', 'reason' => 'This video is unavailable']);

        try {
            $this->transcriptListFetcher->fetch('video123');
            $this->fail('Expected VideoUnavailableException');
        } catch (VideoUnavailableException $e) {
            $this->assertInstanceOf(VideoUnplayableException::class, $e);
            $this->assertSame('This video is unavailable', $e->getReason());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidVideoIdProvider(): array
    {
        return [
            'watch url' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'short url without scheme' => ['youtu.be/dQw4w9WgXcQ'],
            'query fragment' => ['dQw4w9WgXcQ&t=10'],
            'empty' => [''],
            // PCRE `$` would accept this; the id must be rejected before any request.
            'trailing newline' => ["dQw4w9WgXcQ\n"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidVideoIdProvider')]
    public function testFetchRejectsInvalidVideoIdWithoutRequests(string $videoId): void
    {
        $this->httpClientMock->expects($this->never())->method('sendRequest');
        $this->expectException(InvalidVideoIdException::class);

        $this->transcriptListFetcher->fetch($videoId);
    }

    public function testFetchReturnsCaptionsDespiteNonOkStatus(): void
    {
        // Some non-OK statuses still ship caption tracks; use them rather than failing.
        $this->mockRequest($this->validHtml, json_encode([
            'playabilityStatus' => ['status' => 'LIVE_STREAM_OFFLINE', 'reason' => 'Premiere has ended'],
            'captions' => ['playerCaptionsTracklistRenderer' => ['captionTracks' => [[
                'languageCode' => 'en',
                'baseUrl' => 'http://example.com/captions/en',
                'name' => ['runs' => [['text' => 'English']]],
            ]]]],
        ]));

        $transcriptList = $this->transcriptListFetcher->fetch('video123');

        $this->assertSame(['en'], $transcriptList->getAvailableLanguageCodes());
    }

    public function testFetchDoesNotTreatWordsContainingBotAsBotBlock(): void
    {
        $this->expectException(VideoUnplayableException::class);
        $this->mockPlayability(['status' => 'LOGIN_REQUIRED', 'reason' => 'Sign in to view both parts']);

        $this->transcriptListFetcher->fetch('video123');
    }

    public function testFetchReadsStructuredReasonAndSubreason(): void
    {
        $this->mockPlayability([
            'status' => 'UNPLAYABLE',
            'reason' => ['simpleText' => 'Video unavailable'],
            'errorScreen' => ['playerErrorMessageRenderer' => ['subreason' => ['runs' => [
                ['text' => 'Sign in'],
                ['text' => ' if you have been granted access to this video'],
            ]]]],
        ]);

        try {
            $this->transcriptListFetcher->fetch('video123');
            $this->fail('Expected VideoUnplayableException');
        } catch (VideoUnplayableException $e) {
            $this->assertSame('Video unavailable', $e->getReason());
            // Runs are fragments of one sentence, not separate sub-reasons.
            $this->assertSame(['Sign in if you have been granted access to this video'], $e->getSubReasons());
        }
    }

    public function testFetchVideoUnplayableExceptionCarriesReasons(): void
    {
        $this->mockPlayability([
            'status' => 'LOGIN_REQUIRED',
            'reason' => 'This video is private',
            'errorScreen' => ['playerErrorMessageRenderer' => ['subreason' => ['runs' => [
                ['text' => 'Sign in if you have been granted access.'],
                ['text' => ''],
                'not-a-run',
            ]]]],
        ]);

        try {
            $this->transcriptListFetcher->fetch('video123');
            $this->fail('Expected VideoUnplayableException');
        } catch (VideoUnplayableException $e) {
            $this->assertSame('video123', $e->getVideoId());
            $this->assertSame('This video is private', $e->getReason());
            $this->assertSame(['Sign in if you have been granted access.'], $e->getSubReasons());
            $this->assertStringContainsString('This video is private', $e->getMessage());
        }
    }

    public function testFetchVideoUnplayableExceptionForUnknownLoginRequiredReason(): void
    {
        // Previously fell through to TranscriptsDisabledException.
        $this->expectException(VideoUnplayableException::class);
        $this->mockPlayability(['status' => 'LOGIN_REQUIRED', 'reason' => 'Join this channel to get access']);

        $this->transcriptListFetcher->fetch('video123');
    }

    private function mockPlayability(array $playabilityStatus): void
    {
        $this->mockRequest($this->validHtml, json_encode(['playabilityStatus' => $playabilityStatus]));
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
