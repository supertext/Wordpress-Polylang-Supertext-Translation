<?php

declare(strict_types=1);

namespace Supertext\Polylang\Sdk\V1;

/**
 * Corrections for the CMS (spec/corrections.json): the requests and answers of the review
 * service's two calls, without HTTP. Client::pendingCorrections() and acknowledgeCorrections()
 * use them. Only the review service answers these calls; Supertext's API answers 404 (not_found).
 */
final class Corrections
{
    public const PAGE_SIZE = 500;
    public const MAX_PAGES = 100;
    public const ACKNOWLEDGE_BATCH_SIZE = 1000;

    /** The path of one page of pending corrections. */
    public static function path(?string $targetLang, ?string $sourceLang, ?string $politeness, ?string $after): string
    {
        $query = [];
        foreach (['target_lang' => $targetLang, 'source_lang' => $sourceLang, 'politeness' => $politeness, 'after' => $after, 'limit' => (string) self::PAGE_SIZE] as $name => $value) {
            if ($value !== null) {
                $query[] = $name . '=' . rawurlencode($value);
            }
        }

        return 'review/corrections?' . implode('&', $query);
    }

    /**
     * One page: its valid corrections and the "after" for the next page (null: last page).
     *
     * @return array{list<Correction>, string|null}
     */
    public static function parsePage(string $body): array
    {
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [[], null];
        }
        if (!\is_array($data) || array_is_list($data) && $data !== [] || !isset($data['corrections']) || !\is_array($data['corrections']) || !array_is_list($data['corrections'])) {
            return [[], null];
        }
        $corrections = [];
        foreach ($data['corrections'] as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $id = $raw['id'] ?? null;
            $stId = $raw['st_id'] ?? null;
            $target = $raw['target_lang'] ?? null;
            $html = $raw['html'] ?? null;
            $version = $raw['version'] ?? null;
            if (!\is_string($id) || $id === '' || !\is_string($stId) || $stId === '' || !\is_string($target) || $target === ''
                || !\is_string($html) || $html === '' || !\is_string($version) || preg_match('/^[0-9a-f]{16}$/D', $version) !== 1) {
                continue;
            }
            $source = $raw['source_lang'] ?? null;
            $politeness = $raw['politeness'] ?? null;
            $corrections[] = new Correction(
                $id,
                $stId,
                \is_string($source) && $source !== '' ? $source : null,
                $target,
                $politeness === Politeness::MORE || $politeness === Politeness::LESS ? $politeness : Politeness::DEFAULT,
                self::str($raw['source_html'] ?? null),
                self::str($raw['previous_html'] ?? null),
                $html,
                self::str($raw['status'] ?? null),
                self::str($raw['corrected_at'] ?? null),
                $version,
            );
        }
        $next = $data['next_after'] ?? null;

        return [$corrections, \is_string($next) && preg_match('/^[0-9]+$/D', $next) === 1 ? $next : null];
    }

    /**
     * The acknowledge request body, byte-exact.
     *
     * @param list<Correction> $corrections
     */
    public static function acknowledgeBody(array $corrections): string
    {
        $items = array_map(static fn (Correction $c): array => ['id' => $c->id, 'version' => $c->version], $corrections);

        return json_encode(['corrections' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** The answer's "acknowledged" (0 when it isn't a non-negative integer). */
    public static function acknowledgedCount(string $body): int
    {
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 0;
        }
        $n = \is_array($data) ? ($data['acknowledged'] ?? null) : null;

        return \is_int($n) && $n >= 0 ? $n : 0;
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
