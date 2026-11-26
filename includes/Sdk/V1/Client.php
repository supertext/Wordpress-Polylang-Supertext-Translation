<?php

declare(strict_types=1);

namespace Supertext\Polylang\Sdk\V1;

use Supertext\Polylang\Sdk\Http\CurlTransport;
use Supertext\Polylang\Sdk\Http\HttpRequest;
use Supertext\Polylang\Sdk\Http\HttpResponse;
use Supertext\Polylang\Sdk\Http\HttpTransport;

/**
 * Client for the Supertext AI file translation API v1.
 *
 * One translation is: upload an HTML document, poll its status, download the translation, delete the
 * file. translateTexts() packs keyed texts into such documents and reads them back.
 *
 * Language codes are sent as given (trimmed): resolve CMS languages with Languages::target() and
 * Languages::source() first, e.g. Languages::target($cmsLocale, ['de' => 'de-CH']).
 */
final class Client
{
    private const USER_AGENT = 'supertext-sdk-php';

    private readonly string $baseUrl;
    private readonly HttpTransport $transport;
    private readonly string $userAgent;

    /** @var callable(float): void */
    private $sleep;

    /** @var callable(): float */
    private $clock;

    /** @var callable(): float */
    private $now;

    /**
     * A blank key doesn't fail here: the first request throws `missing_api_key` without sending anything,
     * so a client can be created before the integration is configured.
     *
     * @param string                       $apiKey         with or without the "Supertext-Auth-Key " prefix
     * @param string                       $baseUrl        API base URL (a trailing slash is added)
     * @param float                        $pollInterval   seconds between status polls
     * @param float                        $timeout        seconds to wait for a translation, from the first poll
     * @param float                        $requestTimeout seconds per HTTP request
     * @param int                          $maxRetries     retries after HTTP 429
     * @param string|null                  $userAgent      appended to the User-Agent, e.g. "MyCms-Supertext/1.2.0"
     * @param HttpTransport|null           $transport      defaults to CurlTransport (needs ext-curl; pass your own transport to do without)
     * @param (callable(float): void)|null $sleep          sleeps the given seconds (for tests)
     * @param (callable(): float)|null     $clock          monotonic time in seconds (for tests)
     * @param (callable(): float)|null     $now            wall-clock Unix time in seconds, for checkApiKey()'s checkedAt (for tests)
     */
    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        string $baseUrl = Protocol::LIVE_BASE_URL,
        private readonly float $pollInterval = 2.0,
        private readonly float $timeout = 180.0,
        private readonly float $requestTimeout = 30.0,
        private readonly int $maxRetries = Protocol::DEFAULT_MAX_RETRIES,
        ?string $userAgent = null,
        ?HttpTransport $transport = null,
        ?callable $sleep = null,
        ?callable $clock = null,
        ?callable $now = null,
    ) {
        $baseUrl = trim($baseUrl);
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : Protocol::LIVE_BASE_URL, '/') . '/';
        $userAgent = $userAgent === null ? '' : trim($userAgent);
        $this->userAgent = $userAgent === '' ? self::USER_AGENT : self::USER_AGENT . ' ' . $userAgent;
        $this->transport = $transport ?? new CurlTransport();
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    /** Keeps the key out of var_dump() and print_r(). */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'userAgent' => $this->userAgent];
    }

    /**
     * The language pairs the API offers right now, with their features ("supports_politeness", …): the live
     * version of Languages::SOURCES and TARGETS, e.g. for a settings page. Cost-free. Malformed entries are skipped.
     *
     * @return list<FeatureSupport>
     *
     * @throws SupertextException e.g. authentication_failure
     */
    public function getFeatures(): array
    {
        $list = self::json($this->request('GET', 'features'))['supported_features'] ?? null;
        if (!\is_array($list) || !array_is_list($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (!$item instanceof \stdClass) {
                continue;
            }
            $source = $item->source_lang ?? null;
            $target = $item->target_lang ?? null;
            if (!\is_string($source) || !\is_string($target)) {
                continue;
            }
            $features = isset($item->features) && \is_array($item->features)
                ? array_values(array_filter($item->features, '\is_string'))
                : [];
            $out[] = new FeatureSupport($source, $target, $features);
        }

        return $out;
    }

    /**
     * Cost-free check that the key is accepted.
     *
     * @throws SupertextException e.g. authentication_failure
     */
    public function validateApiKey(): void
    {
        $this->request('GET', 'features');
    }

    /**
     * The automatic API key check: `GET features` with a 10 s timeout (or the request timeout, if
     * shorter) and the usual 429 retries, mapped to ApiKeyCheck::OK, INVALID, MISSING, UNREACHABLE or
     * ERROR. Never throws. A blank key gives MISSING without a request.
     */
    public function checkApiKey(): ApiKeyCheck
    {
        $checkedAt = ApiKeyCheck::timestamp(($this->now)());
        $fingerprint = Protocol::apiKeyFingerprint($this->apiKey);
        if ($fingerprint === '') {
            return new ApiKeyCheck(ApiKeyCheck::MISSING, $checkedAt, null, null, '');
        }
        try {
            $response = $this->request('GET', 'features', timeout: min((float) ApiKeyCheck::TIMEOUT, $this->requestTimeout));

            return new ApiKeyCheck(ApiKeyCheck::OK, $checkedAt, $response->status, null, $fingerprint);
        } catch (\Throwable $e) {
            return ApiKeyCheck::fromError($e, $checkedAt, $fingerprint);
        }
    }

    /**
     * Uploads an HTML document for translation and returns its file id.
     *
     * The language codes are sent as given (trimmed): resolve CMS languages with Languages::target() and
     * Languages::source() first.
     *
     * @param string      $targetLang a Supertext target code, e.g. "de-CH" (Languages::target())
     * @param string|null $sourceLang a Supertext source code, e.g. "en" (Languages::source()). Null or "": auto-detect.
     * @param string      $politeness Politeness::DEFAULT, MORE (formal) or LESS (informal)
     *
     * @throws SupertextException unsupported_language for an empty target (nothing is sent)
     */
    public function submit(string $html, string $targetLang, ?string $sourceLang = null, string $politeness = Politeness::DEFAULT): string
    {
        $target = trim($targetLang);
        if ($target === '') {
            throw new SupertextException(SupertextException::UNSUPPORTED_LANGUAGE);
        }
        // Fields in the order the API documents; the file comes last.
        $fields = ['target_lang' => $target];
        $source = trim($sourceLang ?? ''); // omitted: auto-detect
        if ($source !== '') {
            $fields['source_lang'] = $source;
        }
        // Only "more" and "less" mean something; anything else (including "default") isn't sent.
        if ($politeness === Politeness::MORE || $politeness === Politeness::LESS) {
            $fields['politeness'] = $politeness;
        }

        $boundary = '----supertext-' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        // The part's Content-Type must be exactly "text/html": with a charset parameter the API answers 415.
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"content.html\"\r\n"
            . "Content-Type: text/html\r\n\r\n{$html}\r\n--{$boundary}--\r\n";

        $data = self::json($this->request('POST', 'translate/ai/file', $body, 'multipart/form-data; boundary=' . $boundary));
        $fileId = $data['file_id'] ?? null;
        if (!\is_string($fileId) && !\is_int($fileId)) {
            $fileId = '';
        }
        if ((string) $fileId === '') {
            throw new SupertextException(SupertextException::NO_FILE_ID);
        }

        return (string) $fileId;
    }

    /**
     * The file's status as the API reports it: "translating", "done", "error", "limit_exceeded", "deleted"
     * or anything the API adds later.
     */
    public function getStatus(string $fileId): string
    {
        $status = self::json($this->request('GET', self::filePath($fileId) . '/status'))['status'] ?? '';

        return \is_string($status) ? $status : '';
    }

    /**
     * Polls until the file is done.
     *
     * @throws SupertextException translation_error, quota_exceeded, file_deleted, timeout or an HTTP error
     */
    public function waitUntilDone(string $fileId): void
    {
        $start = ($this->clock)();
        while (true) {
            switch ($this->getStatus($fileId)) {
                case 'done':
                    return;
                case 'error':
                    throw new SupertextException(SupertextException::TRANSLATION_ERROR);
                case 'limit_exceeded':
                    throw new SupertextException(SupertextException::QUOTA_EXCEEDED);
                case 'deleted':
                    throw new SupertextException(SupertextException::FILE_DELETED);
            }
            // Unknown statuses ("translating", "queued", …) mean: not yet.
            if (($this->clock)() - $start + $this->pollInterval > $this->timeout) {
                throw new SupertextException(SupertextException::TIMEOUT);
            }
            ($this->sleep)($this->pollInterval);
        }
    }

    /**
     * The translated HTML.
     *
     * @throws SupertextException incomplete_response when the answer is empty
     */
    public function download(string $fileId): string
    {
        $body = $this->request('GET', self::filePath($fileId) . '/translation')->body;
        if (trim($body, " \t\n\r\f\v\0") === '') {
            throw new SupertextException(SupertextException::INCOMPLETE_RESPONSE);
        }

        return $body;
    }

    /**
     * Deletes the file at Supertext. Never throws: files expire after 24 hours anyway.
     */
    public function delete(string $fileId): void
    {
        try {
            $this->request('DELETE', self::filePath($fileId));
        } catch (\Throwable) {
            // Nothing the caller could do about it.
        }
    }

    /**
     * Translates a complete HTML document and returns the translated HTML. The file is always deleted.
     *
     * Elements to be matched back afterwards should carry data-st-id attributes (see HtmlDocument).
     */
    public function translateHtml(string $html, string $targetLang, ?string $sourceLang = null, string $politeness = Politeness::DEFAULT): string
    {
        $fileId = $this->submit($html, $targetLang, $sourceLang, $politeness);
        try {
            $this->waitUntilDone($fileId);

            return $this->download($fileId);
        } finally {
            $this->delete($fileId);
        }
    }

    /**
     * Translates keyed texts and returns the translations under the same keys, in the same order.
     *
     * Texts are packed into as few documents as $maxCharacters allows and translated one document after
     * the other. Blank texts come back unchanged without being sent; if nothing is left, no request is made.
     *
     * Note that PHP turns numeric string keys ("1") into integers; the result uses the same keys as the input.
     *
     * @template K of array-key
     *
     * @param array<K, Segment|string> $segments key → Segment (a string is plain text)
     *
     * @return array<K, string>
     *
     * @throws SupertextException incomplete_response (detail "missing: key1, key2") when segments are missing
     */
    public function translateTexts(
        array $segments,
        string $targetLang,
        ?string $sourceLang = null,
        string $politeness = Politeness::DEFAULT,
        int $maxCharacters = Protocol::DEFAULT_MAX_CHARACTERS,
    ): array {
        $result = [];
        $toSend = [];
        foreach ($segments as $key => $segment) {
            $segment = $segment instanceof Segment ? $segment : Segment::text($segment);
            $result[$key] = $segment->text;
            if (!$segment->isBlank()) {
                $toSend[$key] = $segment;
            }
        }

        foreach (self::batches($toSend, $maxCharacters) as $batch) {
            $keys = array_keys($batch);
            $list = array_values($batch);
            $translated = $this->translateHtml(HtmlDocument::build($list), $targetLang, $sourceLang, $politeness);
            $parsed = HtmlDocument::parse($translated, $list);

            $missing = [];
            foreach ($parsed as $index => $text) {
                if ($text === null) {
                    $missing[] = (string) $keys[$index];
                } else {
                    $result[$keys[$index]] = $text;
                }
            }
            if ($missing !== []) {
                throw new SupertextException(SupertextException::INCOMPLETE_RESPONSE, null, 'missing: ' . implode(', ', $missing));
            }
        }

        return $result;
    }

    /**
     * Splits segments into documents of at most $maxCharacters packed characters (a longer segment goes alone).
     *
     * @template K of array-key
     *
     * @param array<K, Segment> $segments non-blank segments, in order
     *
     * @return list<array<K, Segment>>
     */
    public static function batches(array $segments, int $maxCharacters = Protocol::DEFAULT_MAX_CHARACTERS): array
    {
        $batches = [];
        $current = [];
        $length = 0;
        foreach ($segments as $key => $segment) {
            $size = Utf8::length(HtmlDocument::pack($segment));
            if ($current !== [] && $length + $size > $maxCharacters) {
                $batches[] = $current;
                $current = [];
                $length = 0;
            }
            $current[$key] = $segment;
            $length += $size;
        }
        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * Corrections reviewers made that the integration hasn't written into the CMS yet
     * (Supertext Localization Review; spec/corrections.json). Only the review service answers
     * this: against Supertext's API it throws not_found. Follows all pages. segmentId is the
     * data-st-id the integration sent, so this is useful when the integration builds its
     * documents with its own ids (translateHtml()).
     *
     * @param string|null $politeness Politeness::DEFAULT, MORE or LESS; null: all
     *
     * @return list<Correction>
     *
     * @throws SupertextException
     */
    public function pendingCorrections(?string $targetLang = null, ?string $sourceLang = null, ?string $politeness = null): array
    {
        $all = [];
        $after = null;
        for ($page = 0; $page < Corrections::MAX_PAGES; $page++) {
            [$corrections, $next] = Corrections::parsePage($this->request('GET', Corrections::path($targetLang, $sourceLang, $politeness, $after))->body);
            array_push($all, ...$corrections);
            if ($next === null || $next === $after) {
                break;
            }
            $after = $next;
        }

        return $all;
    }

    /**
     * Tells the review service these corrections are in the CMS now; returns how many it accepted.
     * Only the fetched version counts: a newer correction stays pending.
     *
     * @param list<Correction> $corrections
     *
     * @throws SupertextException
     */
    public function acknowledgeCorrections(array $corrections): int
    {
        $total = 0;
        foreach (array_chunk($corrections, Corrections::ACKNOWLEDGE_BATCH_SIZE) as $chunk) {
            $total += Corrections::acknowledgedCount($this->request('POST', 'review/corrections/acknowledge', Corrections::acknowledgeBody($chunk), 'application/json')->body);
        }

        return $total;
    }

    private static function filePath(string $fileId): string
    {
        return 'translate/ai/file/' . rawurlencode($fileId);
    }

    /**
     * Sends a request, retrying HTTP 429, and returns the 2xx answer.
     *
     * @throws SupertextException
     */
    private function request(string $method, string $path, ?string $body = null, ?string $contentType = null, ?float $timeout = null): HttpResponse
    {
        // Throws missing_api_key before anything is sent.
        $authorization = Protocol::authHeader($this->apiKey);
        $headers = [
            'Authorization' => $authorization,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }
        $request = new HttpRequest($method, $this->baseUrl . $path, $headers, $body, $timeout ?? $this->requestTimeout);

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($request);
            } catch (\Throwable $e) {
                $message = trim($e->getMessage());
                $detail = self::redact($message, $authorization);
                // Keep the original for debugging, unless its message carries the key.
                $previous = $detail === $message ? $e : null;
                throw new SupertextException(SupertextException::TRANSPORT_ERROR, null, $detail !== '' ? $detail : null, previous: $previous);
            }
            if ($response->status !== 429 || $attempt >= $this->maxRetries) {
                break;
            }
            ($this->sleep)(Protocol::retryDelayMs($attempt, $response->header('Retry-After')) / 1000);
        }

        if ($response->status >= 200 && $response->status < 300) {
            return $response;
        }

        $error = self::errorFor($response);
        $detail = $error->detail === null ? null : self::redact($error->detail, $authorization);
        if ($detail !== $error->detail) {
            // Never pass the key on, not even if a proxy or server echoes it back.
            $error = new SupertextException($error->errorCode, $error->status, $detail, $error->apiErrorCode, $error->sourceLang, $error->targetLang, feature: $error->feature);
        }

        throw $error;
    }

    /** Removes the key (taken from the Authorization header value) from a text. */
    private static function redact(string $text, string $authorization): string
    {
        $key = substr($authorization, \strlen('Supertext-Auth-Key '));

        return $key === '' ? $text : str_replace($key, '[API key]', $text);
    }

    /** Maps a non-2xx answer to a SupertextException (see spec/errors.json). */
    public static function errorFor(HttpResponse $response): SupertextException
    {
        $status = $response->status;
        $data = self::json($response);
        $apiErrorCode = self::string($data, 'error_code');

        $detail = trim((string) preg_replace('/<[^>]*>/', '', $response->body), " \t\n\r\f");
        $detail = $detail === '' ? null : Utf8::truncate($detail, 200);

        $code = match (true) {
            // Checked on the raw body: the API has answered with JSON and with plain text, with HTTP 400
            // and (for a language it doesn't know at all, e.g. target "xx-YY") with HTTP 404.
            ($status === 400 || $status === 404) && str_contains($response->body, 'INVALID_LANGUAGE_PAIR') => SupertextException::INVALID_LANGUAGE_PAIR,
            // The pair exists, but not with this option (e.g. politeness for en-US).
            $status === 400 && $apiErrorCode === 'FEATURE_ERROR' => SupertextException::UNSUPPORTED_FEATURE,
            $status === 401, $status === 403 => SupertextException::AUTHENTICATION_FAILURE,
            $status === 404 => SupertextException::NOT_FOUND,
            $status === 413 => SupertextException::PAYLOAD_TOO_LARGE,
            $status === 429 => SupertextException::TOO_MANY_REQUESTS,
            $status >= 500 && $status <= 599 => SupertextException::SERVICE_UNAVAILABLE,
            default => SupertextException::UNEXPECTED_STATUS,
        };

        $sourceLang = $targetLang = $feature = null;
        if ($code === SupertextException::INVALID_LANGUAGE_PAIR || $code === SupertextException::UNSUPPORTED_FEATURE) {
            $sourceLang = self::string($data, 'source_lang');
            $targetLang = self::string($data, 'target_lang');
        }
        if ($code === SupertextException::UNSUPPORTED_FEATURE) {
            $feature = self::string($data, 'feature');
        }

        return new SupertextException($code, $status, $detail, $apiErrorCode, $sourceLang, $targetLang, feature: $feature);
    }

    /**
     * A non-empty string field of a decoded JSON object, else null (the API's JSON isn't trusted to have the documented types).
     *
     * @param array<mixed> $data
     */
    private static function string(array $data, string $name): ?string
    {
        return isset($data[$name]) && \is_string($data[$name]) && $data[$name] !== '' ? $data[$name] : null;
    }

    /** @return array<mixed> the JSON object in the body, or [] (also for JSON arrays and scalars) */
    private static function json(HttpResponse $response): array
    {
        $data = json_decode($response->body);

        return $data instanceof \stdClass ? (array) $data : [];
    }
}
