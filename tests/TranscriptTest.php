<?php

namespace MrMySQL\YoutubeTranscript\Tests;

use PHPUnit\Framework\TestCase;
use MrMySQL\YoutubeTranscript\Transcript;
use MrMySQL\YoutubeTranscript\Exception\NotTranslatableException;
use MrMySQL\YoutubeTranscript\Exception\YouTubeRequestFailedException;
use MrMySQL\YoutubeTranscript\Exception\TranslationLanguageNotAvailableException;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class TranscriptTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private RequestFactoryInterface&MockObject $requestFactory;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->requestFactory = $this->createMock(RequestFactoryInterface::class);
    }

    public function testFetchSuccess(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response_stream = $this->createMock(StreamInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($response_stream);
        $response_stream->method('getContents')->willReturn('<transcript><text start="0" dur="1">Hello World</text></transcript>');

        $this->requestFactory->method('createRequest')->willReturn($request);

        $this->httpClient->method('sendRequest')->willReturn($response);

        $transcript = new Transcript(
            $this->httpClient,
            $this->requestFactory,
            'video123',
            'https://example.com',
            'English',
            'en',
            false,
            []
        );

        $result = $transcript->fetch();
        $this->assertNotEmpty($result);
    }

    public function testFetchFailure(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $this->requestFactory->method('createRequest')->willReturn($request);

        $this->httpClient
            ->method('sendRequest')
            ->willThrowException(new \RuntimeException('Request failed'));

        $transcript = new Transcript(
            $this->httpClient,
            $this->requestFactory,
            'video123',
            'https://example.com',
            'English',
            'en',
            false,
            []
        );

        $this->expectException(YouTubeRequestFailedException::class);
        $transcript->fetch();
    }

    public function testTranslateSuccess(): void
    {
        $transcript = new Transcript(
            $this->httpClient,
            $this->requestFactory,
            'video123',
            'https://example.com',
            'English',
            'en',
            false,
            [
                ['language' => 'Spanish', 'language_code' => 'es']
            ]
        );

        $translatedTranscript = $transcript->translate('es');

        $this->assertInstanceOf(Transcript::class, $translatedTranscript);
        $this->assertEquals('https://example.com&tlang=es', $translatedTranscript->getUrl());
        $this->assertEquals('Spanish', $translatedTranscript->language);
    }

    public function testTranslateFailureWhenNotTranslatable(): void
    {
        $transcript = new Transcript(
            $this->httpClient,
            $this->requestFactory,
            'video123',
            'https://example.com',
            'English',
            'en',
            false,
            []
        );

        $this->expectException(NotTranslatableException::class);
        $transcript->translate('es');
    }

    public function testTranslateFailureWhenLanguageNotAvailable(): void
    {
        $transcript = new Transcript(
            $this->httpClient,
            $this->requestFactory,
            'video123',
            'https://example.com',
            'English',
            'en',
            false,
            [
                ['language' => 'Spanish', 'language_code' => 'es']
            ]
        );

        $this->expectException(TranslationLanguageNotAvailableException::class);
        $transcript->translate('fr');
    }

    public function testToString(): void
    {
        $transcript = new Transcript(
            $this->httpClient,
            $this->requestFactory,
            'video123',
            'https://example.com',
            'English',
            'en',
            false,
            [
                ['language' => 'Spanish', 'language_code' => 'es']
            ]
        );

        $this->assertEquals('en ("English")[TRANSLATABLE]', (string) $transcript);
    }

    public function testIsGenerated(): void
    {
        $transcript = new Transcript(
            $this->httpClient,
            $this->requestFactory,
            'video123',
            'https://example.com',
            'English',
            'en',
            true,
            []
        );

        $this->assertTrue($transcript->isGenerated());

        $transcript = new Transcript(
            $this->httpClient,
            $this->requestFactory,
            'video123',
            'https://example.com',
            'English',
            'en',
            false,
            []
        );

        $this->assertFalse($transcript->isGenerated());
    }
}
