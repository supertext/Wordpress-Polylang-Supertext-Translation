<?php

declare(strict_types=1);

namespace Supertext\Polylang\Sdk\Http;

/**
 * Default transport, using ext-curl. Honours the usual proxy environment variables (libcurl does).
 *
 * ext-curl is only needed for this class: integrations that pass their own HttpTransport to the
 * Client (a framework's HTTP client) don't need it.
 */
final class CurlTransport implements HttpTransport
{
    public function __construct()
    {
        if (!self::isAvailable()) {
            throw new \LogicException(
                'The PHP curl extension is not loaded. Install it, or pass your own Supertext\\Polylang\\Sdk\\Http\\HttpTransport '
                . '(e.g. wrapping your framework\'s HTTP client) as the Client\'s transport.'
            );
        }
    }

    /** Whether ext-curl is loaded, i.e. whether this transport can be used. */
    public static function isAvailable(): bool
    {
        return \extension_loaded('curl');
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $handle = curl_init($request->url);
        if ($handle === false) {
            throw new TransportException('curl_init failed');
        }

        // An empty Expect stops curl from waiting for "100 Continue" before large uploads.
        $headers = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_CUSTOMREQUEST => $request->method === '' ? 'GET' : $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => max(1, (int) round($request->timeout * 1000)),
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a new response (after 100 Continue or a proxy CONNECT)
                }

                return \strlen($line);
            },
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        if (!\is_string($body)) {
            $message = curl_error($handle);
            throw new TransportException($message !== '' ? $message : 'HTTP request failed');
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        /** @var array<string, string> $responseHeaders */
        return new HttpResponse($status, $body, $responseHeaders);
    }
}
