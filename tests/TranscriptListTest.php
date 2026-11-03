<?php

use PHPUnit\Framework\TestCase;
use MrMySQL\YoutubeTranscript\TranscriptList;
use MrMySQL\YoutubeTranscript\Exception\NoTranscriptFoundException;
use MrMySQL\YoutubeTranscript\Exception\NotTranslatableException;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

class TranscriptListTest extends TestCase
{
    private ClientInterface&MockObject $httpClientMock;
    private RequestFactoryInterface&MockObject $requestFactoryMock;
    private array $captionsJson;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->requestFactoryMock = $this->createMock(RequestFactoryInterface::class);
        $this->captionsJson = [
            'captionTracks' => [
                [
                    'baseUrl' => 'http://example.com/1',
                    'name' => ['runs' => [['text' => 'English']]],
                    'languageCode' => 'en',
                    'kind' => '',
                ],
                [
                    'baseUrl' => 'http://example.com/2',
                    'name' => ['runs' => [['text' => 'Auto-Spanish']]],
                    'languageCode' => 'es',
                    'kind' => 'asr',
                ],
            ],
            'translationLanguages' => [
                ['languageName' => ['runs' => [['text' => 'French']]], 'languageCode' => 'fr'],
            ],
        ];
    }

    public function testBuildCreatesTranscriptList(): void
    {
        $transcriptList = TranscriptList::build(
            $this->httpClientMock,
            $this->requestFactoryMock,
            'video123',
            $this->captionsJson,
            'Test Video'
        );

        $this->assertInstanceOf(TranscriptList::class, $transcriptList);
        $this->assertEquals('Test Video', $transcriptList->getTitle());
    }

    public function testGetIteratorReturnsCorrectTranscripts(): void
    {
        $transcriptList = TranscriptList::build(
            $this->httpClientMock,
            $this->requestFactoryMock,
            'video123',
            $this->captionsJson,
            'Test Video'
        );

        $iterator = $transcriptList->getIterator();
        $this->assertCount(2, iterator_to_array($iterator));
    }

    public function testGetAvailableLanguageCodesReturnsCorrectCodes(): void
    {
        $transcriptList = TranscriptList::build(
            $this->httpClientMock,
            $this->requestFactoryMock,
            'video123',
            $this->captionsJson,
            'Test Video'
        );

        $languageCodes = $transcriptList->getAvailableLanguageCodes();
        $this->assertEquals(['en', 'es'], $languageCodes);
    }

    public function testFindTranscriptReturnsCorrectTranscript(): void
    {
        $transcriptList = TranscriptList::build(
            $this->httpClientMock,
            $this->requestFactoryMock,
            'video123',
            $this->captionsJson,
            'Test Video'
        );

        $result = $transcriptList->findTranscript(['en']);
        $this->assertSame('en', $result->language_code);
        $this->assertSame('http://example.com/1', $result->getUrl());
    }

    public function testFindGeneratedTranscript(): void
    {
        $transcriptList = TranscriptList::build(
            $this->httpClientMock,
            $this->requestFactoryMock,
            'video123',
            $this->captionsJson,
            'Test Video'
        );

        $result = $transcriptList->findGeneratedTranscript(['es']);
        $this->assertSame('es', $result->language_code);
        $this->assertSame('http://example.com/2', $result->getUrl());
    }

    public function testFindGeneratedTranscriptThrowsException(): void
    {
        $this->expectException(NoTranscriptFoundException::class);

        $captionsJson = [
            'captionTracks' => [],
            'translationLanguages' => [],
        ];

        $transcriptList = TranscriptList::build(
            $this->httpClientMock,
            $this->requestFactoryMock,
            'video123',
            $captionsJson,
            'Test Video'
        );

        $transcriptList->findGeneratedTranscript(['en']);
    }

    public function testFindManuallyCreatedTranscript(): void
    {
        $transcriptList = TranscriptList::build(
            $this->httpClientMock,
            $this->requestFactoryMock,
            'video123',
            $this->captionsJson,
            'Test Video'
        );

        $result = $transcriptList->findManuallyCreatedTranscript(['en']);
        $this->assertSame('en', $result->language_code);
        $this->assertSame('http://example.com/1', $result->getUrl());
    }


    public function testFindManuallyCreatedTranscriptThrowsException(): void
    {
        $this->expectException(NoTranscriptFoundException::class);

        $captionsJson = [
            'captionTracks' => [],
            'translationLanguages' => [],
        ];

        $transcriptList = TranscriptList::build(
            $this->httpClientMock,
            $this->requestFactoryMock,
            'video123',
            $captionsJson,
            'Test Video'
        );

        $transcriptList->findManuallyCreatedTranscript(['en']);
    }

    public function testToStringUsesRealNewlines(): void
    {
        $captionsJson = $this->captionsJson;
        $captionsJson['translationLanguages'] = [];

        $transcriptList = TranscriptList::build(
            $this->httpClientMock,
            $this->requestFactoryMock,
            'video123',
            $captionsJson
        );

        $this->assertSame(
            "For this video (video123) transcripts are available in the following languages:\n\n"
            . "(MANUALLY CREATED)\n"
            . " - en (\"English\")\n\n"
            . "(GENERATED)\n"
            . " - es (\"Auto-Spanish\")\n\n"
            . "(TRANSLATION LANGUAGES)\n"
            . "None",
            (string) $transcriptList
        );
    }

    public function testToStringOutputsCorrectly(): void
    {
        $transcriptList = TranscriptList::build(
            $this->httpClientMock,
            $this->requestFactoryMock,
            'video123',
            $this->captionsJson,
            'Test Video'
        );

        $stringOutput = (string)$transcriptList;
        $this->assertStringContainsString('English', $stringOutput);
        $this->assertStringContainsString('French', $stringOutput);
    }

    public function testOnlyTracksFlaggedTranslatableGetTranslationLanguages(): void
    {
        $captionsJson = $this->captionsJson;
        $captionsJson['captionTracks'][0]['isTranslatable'] = true;
        $captionsJson['captionTracks'][1]['isTranslatable'] = false;

        $transcriptList = TranscriptList::build(
            $this->httpClientMock,
            $this->requestFactoryMock,
            'video123',
            $captionsJson
        );

        $this->assertTrue($transcriptList->findTranscript(['en'])->isTranslatable());
        $this->assertSame('fr', $transcriptList->findTranscript(['en'])->translate('fr')->language_code);

        $spanish = $transcriptList->findTranscript(['es']);
        $this->assertFalse($spanish->isTranslatable());
        $this->expectException(NotTranslatableException::class);
        $spanish->translate('fr');
    }

    public function testTrackWithoutIsTranslatableFlagIsNotTranslatable(): void
    {
        $transcriptList = TranscriptList::build(
            $this->httpClientMock,
            $this->requestFactoryMock,
            'video123',
            $this->captionsJson
        );

        $this->assertFalse($transcriptList->findTranscript(['en'])->isTranslatable());
    }
}
