<?php

declare(strict_types=1);

namespace MrMySQL\YoutubeTranscript\Exception;

use Psr\Http\Message\ResponseInterface;

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
    private ?string $retry_after = null;

    /**
     * Throws if the response is an HTTP 429, with the status, reason phrase and
     * Retry-After (when present) in the message.
     *
     * @internal used by the library's request code
     */
    public static function throwIfRateLimited(ResponseInterface $response, string $video_id): void
    {
        if ($response->getStatusCode() !== 429) {
            return;
        }
        $retry_after = $response->getHeaderLine('Retry-After');
        $exception = new self(sprintf(
            '%s: YouTube responded with HTTP 429 %s%s',
            $video_id,
            $response->getReasonPhrase() ?: 'Too Many Requests',
            $retry_after !== '' ? sprintf(' (Retry-After: %s)', $retry_after) : ''
        ));
        $exception->retry_after = $retry_after !== '' ? $retry_after : null;
        throw $exception;
    }

    /**
     * The raw Retry-After header value (seconds or an HTTP date), if YouTube sent one.
     */
    public function getRetryAfter(): ?string
    {
        return $this->retry_after;
    }
}
