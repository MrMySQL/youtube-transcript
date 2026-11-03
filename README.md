YouTube Transcript
============================

YouTube Transcript is a PHP library designed to fetch transcripts for YouTube videos. It allows you to retrieve captions in multiple languages, including auto-generated captions, and provides functionality to translate transcripts into other available languages. Inspired by [jdepoix/youtube-transcript-api](https://github.com/jdepoix/youtube-transcript-api). I've generated this readme using one of these fancy [A]utocompletion [I]nstruments, so it might be incorrect somewhere, but overall looks good.

[![codecov](https://codecov.io/gh/MrMySQL/youtube-transcript/graph/badge.svg?token=2LPZ8B1MRH)](https://codecov.io/gh/MrMySQL/youtube-transcript)


Features
--------

-   Retrieve both manually created and auto-generated transcripts.
-   Fetch transcripts in various languages.
-   Translate transcripts to other available languages.
-   Format transcripts as JSON, plain text, SRT, WebVTT or a pretty-printed PHP array.

Requirements
------------

-   PHP 8.2 or higher
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
$stream_factory = new HttpFactory(); // GuzzleHttp\Psr7\HttpFactory implements StreamFactoryInterface

$fetcher = new TranscriptListFetcher($http_client, $request_factory, $stream_factory);
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

### 6\. Format a Transcript

Formatters turn the array returned by `fetch()` into a string in a given format, ready to be written to a file. They live in the `MrMySQL\YoutubeTranscript\Formatter` namespace:

| Formatter | Name | Output |
| --- | --- | --- |
| `JsonFormatter` | `json` | JSON, with Unicode and slashes unescaped |
| `PrettyPrintFormatter` | `pretty` | A readable PHP array literal (like `var_export()`, in short `[]` syntax) |
| `TextFormatter` | `text` | Plain text without timestamps, one cue per line |
| `SrtFormatter` | `srt` | SubRip subtitles (`.srt`) |
| `WebVttFormatter` | `webvtt` | WebVTT subtitles (`.vtt`) |

```php
use MrMySQL\YoutubeTranscript\Formatter\SrtFormatter;

$transcript = $transcript_list->findTranscript(['en'])->fetch();

file_put_contents('subtitles.srt', (new SrtFormatter())->formatTranscript($transcript));
```

```
1
00:00:00,000 --> 00:00:01,540
Hey there

2
00:00:01,540 --> 00:00:04,100
how are you
```

In the SRT and WebVTT output, cues are ordered by start time and each cue ends when the next cue of the same transcript starts, so cues within a transcript never overlap (cues of different transcripts merged by `formatTranscripts()` can). Text is made safe for each format: blank lines inside a cue are removed, cues without visible text are skipped, `-->` becomes `->` in SRT, and `&`, `<` and `>` are escaped in WebVTT. Formatting tags kept by `fetch(true)` are mapped to the format's own (`<strong>` → `<b>`, `<em>` → `<i>`), and tags the format doesn't support are removed, keeping their text.

`JsonFormatter` takes extra `JSON_*` flags, which are added to its defaults:

```php
use MrMySQL\YoutubeTranscript\Formatter\JsonFormatter;

$json = (new JsonFormatter(JSON_PRETTY_PRINT))->formatTranscript($transcript);
```

`formatTranscripts()` formats several transcripts at once, e.g. two languages. JSON and the pretty printer return a list of transcripts, text separates them with two blank lines, and SRT and WebVTT merge all cues into one file ordered by start time, which players show as dual subtitles:

```php
use MrMySQL\YoutubeTranscript\Formatter\WebVttFormatter;

$vtt = (new WebVttFormatter())->formatTranscripts([
    $transcript_list->findTranscript(['en'])->fetch(),
    $transcript_list->findTranscript(['de'])->fetch(),
]);
```

To choose the format at runtime, e.g. from a CLI option, use `FormatterLoader`. It throws `UnknownFormatterException` for a name it doesn't know:

```php
use MrMySQL\YoutubeTranscript\Formatter\FormatterLoader;

$formatter = (new FormatterLoader())->load('webvtt'); // json, pretty (default), text, webvtt or srt
echo $formatter->formatTranscript($transcript);
```

To add your own format, implement `FormatterInterface` (`formatTranscript()` and `formatTranscripts()`, both returning a string), or extend one of the formatters above.

**Note:** Cookie-based authentication is currently not supported due to recent changes in the YouTube API. Age-restricted videos and some protected content cannot be accessed until a new authentication method is implemented.

Exception Handling
------------------

This library comes with a set of custom exceptions to handle different scenarios:

-   `YouTubeRequestFailedException`: Thrown when the YouTube request fails.
-   `NoTranscriptFoundException`: Thrown when no transcript is found for the given language codes.
-   `NotTranslatableException`: Thrown when attempting to translate a transcript that is not translatable.
-   `TranscriptsDisabledException`: Thrown when transcripts are disabled for the video.
-   `TooManyRequestsException`: Thrown when YouTube rate-limits or blocks your IP (a reCAPTCHA page or an HTTP 429). It extends `YouTubeRequestFailedException`, so catch it **before** the generic exception if you want to handle it separately (e.g. to rotate a proxy); `getRetryAfter()` returns the Retry-After header if one was sent. Translated transcripts (`translate()`) currently always hit this; see [jdepoix/youtube-transcript-api#572](https://github.com/jdepoix/youtube-transcript-api/issues/572).
-   `PoTokenRequiredException`: Thrown when a PO Token is required to retrieve the transcript (please open a GitHub issue if you see this).
-   `InvalidVideoIdException`: Thrown before any request when the id is not a bare video id (e.g. a URL was passed). Pass `dQw4w9WgXcQ`, not `https://www.youtube.com/watch?v=dQw4w9WgXcQ`.
-   `RequestBlockedException`: Thrown when YouTube blocks the request as suspected bot traffic ("Sign in to confirm you're not a bot"), typically because of the IP/proxy, not the video.
-   `UnknownFormatterException`: Thrown by `FormatterLoader::load()` for an unknown format name. It extends `InvalidArgumentException`.
-   `FormattingFailedException`: Thrown when a formatter cannot encode a transcript, e.g. `JsonFormatter` given text that is not valid UTF-8.
-   `VideoUnplayableException`: Thrown when YouTube reports the video as not playable (private, members-only, region-blocked, …). `getReason()` and `getSubReasons()` return YouTube's explanation. Its subclasses are:
    -   `VideoUnavailableException`: the video doesn't exist or was removed.
    -   `AgeRestrictedException`: the video is age-restricted and needs a signed-in session.

Before these were added, unplayable videos were reported as `TranscriptsDisabledException`. If YouTube still returns caption tracks for a video it reports as not playable, the transcripts are returned as usual.

### Example:

```php
try {
    $transcript = $transcript_list->findGeneratedTranscript(['en']);
    print_r($transcript->fetch());
} catch (YouTubeRequestFailedException $e) {
    echo "Failed to fetch the transcript: " . $e->getMessage();
}
```

