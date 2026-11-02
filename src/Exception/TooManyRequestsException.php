<?php

namespace MrMySQL\YoutubeTranscript\Exception;

/**
 * Thrown when YouTube rate-limits / IP-blocks the client: either the watch page
 * serves a reCAPTCHA, or any request (watch page, Innertube API, caption
 * download) responds with HTTP 429.
 *
 * Note: as of 2026, translated caption URLs (`&tlang=`) are answered with 429
 * by YouTube's bot protection even when untranslated captions load fine.
 *
 * Extends YouTubeRequestFailedException so existing handlers that catch the
 * generic request failure keep catching rate limits.
 */
class TooManyRequestsException extends YouTubeRequestFailedException
{

}
