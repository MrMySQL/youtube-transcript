YouTube Transcript
============================

YouTube Transcript is a PHP library designed to fetch transcripts for YouTube videos. It allows you to retrieve captions in multiple languages, including auto-generated captions, and provides functionality to translate transcripts into other available languages. Inspired by [jdepoix/youtube-transcript-api](https://github.com/jdepoix/youtube-transcript-api). I've generated this readme using one of these fancy [A]utocompletion [I]nstruments, so it might be incorrect somewhere, but overall looks good.

[![codecov](https://codecov.io/gh/MrMySQL/youtube-transcript/graph/badge.svg?token=2LPZ8B1MRH)](https://codecov.io/gh/MrMySQL/youtube-transcript)


Features
--------

-   Retrieve both manually created and auto-generated transcripts.
-   Fetch transcripts in various languages.
-   Translate transcripts to other available languages.

Requirements
------------

-   PHP 7.4 or higher (including support for PHP 8.2)
-   PSR-18 HTTP Client (`psr/http-client`) and HTTP Factory (`psr/http-factory`)

Installation
------------

To include this library in your project, use Composer:

```php
composer require mrmysql/youtube-transcript
```

Usage
-----

### 1\. Initialize the Transcript Fetcher

To begin, you need to set up an HTTP client and a request factory that implements the PSR-18 and PSR-17 interfaces, respectively. These dependencies are required by the `TranscriptListFetcher` class.


```php
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$http_client = new Client();
$request_factory = new HttpFactory();

$fetcher = new TranscriptListFetcher($http_client, $request_factory);
```

### 2\. Fetch the Transcript List

You can fetch the transcript list for a given YouTube video ID:

```php
$video_id = 'YOUR_YOUTUBE_VIDEO_ID';
$transcript_list = $fetcher->fetch($video_id);
```

### 3\. Retrieve and Display Transcripts

You can iterate over the available transcripts and display them:

```php
foreach ($transcript_list as $transcript) {
    echo $transcript . "\n";
}
```

### 4\. Fetch a Specific Transcript

To fetch a transcript in a specific language:

```php
$language_codes = ['en', 'es']; // Prioritized language codes
$transcript = $transcript_list->findTranscript($language_codes);
$transcript_text = $transcript->fetch();
print_r($transcript_text);
```

You can also take all available locale codes directly from the list:

```php
$language_codes = $transcript_list->getAvailableLanguageCodes();
$transcript = $transcript_list->findTranscript($language_codes);
$transcript_text = $transcript->fetch();
print_r($transcript_text);
```

### 5\. Translate a Transcript

If translation is available, you can translate the transcript to another language:

```php
$translated_transcript = $transcript->translate('fr');
$translated_text = $translated_transcript->fetch();
print_r($translated_text);
```

Exception Handling
------------------

This library comes with a set of custom exceptions to handle different scenarios:

-   `YouTubeRequestFailedException`: Thrown when the YouTube request fails.
-   `NoTranscriptFoundException`: Thrown when no transcript is found for the given language codes.
-   `NotTranslatableException`: Thrown when attempting to translate a transcript that is not translatable.
-   `TranscriptsDisabledException`: Thrown when transcripts are disabled for the video.
-   `TooManyRequestsException`: Thrown when YouTube imposes a rate limit.

### Example:

```php
try {
    $transcript = $transcript_list->findGeneratedTranscript(['en']);
    print_r($transcript->fetch());
} catch (YouTubeRequestFailedException $e) {
    echo "Failed to fetch the transcript: " . $e->getMessage();
}
```

