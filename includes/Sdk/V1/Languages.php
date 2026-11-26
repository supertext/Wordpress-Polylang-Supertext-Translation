<?php

declare(strict_types=1);

namespace Supertext\Polylang\Sdk\V1;

/**
 * Languages of the Supertext AI file translation API v1 (spec/languages.json, from the live `GET features`,
 * October 2026).
 *
 * An integration maps its CMS languages to these codes (a combo box per language, suggestTarget()
 * preselected). A CMS code that equals a Supertext code (apart from case, "_" for "-" and surrounding
 * spaces) is used as it is; a bare language Supertext offers only with a region or script gets its
 * default ("de" → "de-DE", see DEFAULT_TARGETS); a region of a bare target is dropped ("es-ES" → "es");
 * anything else is an unsupported_language error until the administrator maps it. For the live list,
 * use Client::getFeatures().
 *
 *     Languages::target($cmsLocale, ['de' => 'de-CH']); // "de" → "de-CH", "fr_fr" → "fr-FR", "en" → "en-US", "xx" → error
 *     Languages::source('en-US');                       // "en"
 */
final class Languages
{
    /**
     * Source languages (bare codes).
     *
     * @var list<array{code: string, name: string}>
     */
    public const SOURCES = [
        ['code' => 'bg', 'name' => 'Bulgarian'],
        ['code' => 'cs', 'name' => 'Czech'],
        ['code' => 'da', 'name' => 'Danish'],
        ['code' => 'de', 'name' => 'German'],
        ['code' => 'el', 'name' => 'Greek'],
        ['code' => 'en', 'name' => 'English'],
        ['code' => 'es', 'name' => 'Spanish'],
        ['code' => 'fi', 'name' => 'Finnish'],
        ['code' => 'fr', 'name' => 'French'],
        ['code' => 'gsw', 'name' => 'Swiss German'],
        ['code' => 'hr', 'name' => 'Croatian'],
        ['code' => 'hu', 'name' => 'Hungarian'],
        ['code' => 'id', 'name' => 'Indonesian'],
        ['code' => 'it', 'name' => 'Italian'],
        ['code' => 'ja', 'name' => 'Japanese'],
        ['code' => 'ko', 'name' => 'Korean'],
        ['code' => 'nb', 'name' => 'Norwegian Bokmål'],
        ['code' => 'nl', 'name' => 'Dutch'],
        ['code' => 'pl', 'name' => 'Polish'],
        ['code' => 'pt', 'name' => 'Portuguese'],
        ['code' => 'rm', 'name' => 'Romansh'],
        ['code' => 'ro', 'name' => 'Romanian'],
        ['code' => 'ru', 'name' => 'Russian'],
        ['code' => 'sk', 'name' => 'Slovak'],
        ['code' => 'sl', 'name' => 'Slovenian'],
        ['code' => 'sq', 'name' => 'Albanian'],
        ['code' => 'sr', 'name' => 'Serbian'],
        ['code' => 'sv', 'name' => 'Swedish'],
        ['code' => 'th', 'name' => 'Thai'],
        ['code' => 'tr', 'name' => 'Turkish'],
        ['code' => 'vi', 'name' => 'Vietnamese'],
        ['code' => 'zh', 'name' => 'Chinese'],
    ];

    /**
     * Target languages, exactly as Supertext expects them. `politeness`: whether formal/informal
     * (Politeness::MORE/LESS) is offered; otherwise the API answers FEATURE_ERROR (unsupported_feature).
     *
     * @var list<array{code: string, name: string, politeness: bool}>
     */
    public const TARGETS = [
        ['code' => 'bg', 'name' => 'Bulgarian', 'politeness' => true],
        ['code' => 'cs', 'name' => 'Czech', 'politeness' => true],
        ['code' => 'da', 'name' => 'Danish', 'politeness' => true],
        ['code' => 'de-AT', 'name' => 'German (Austria)', 'politeness' => true],
        ['code' => 'de-CH', 'name' => 'German (Switzerland)', 'politeness' => true],
        ['code' => 'de-DE', 'name' => 'German (Germany)', 'politeness' => true],
        ['code' => 'el', 'name' => 'Greek', 'politeness' => true],
        ['code' => 'en-GB', 'name' => 'English (United Kingdom)', 'politeness' => false],
        ['code' => 'en-US', 'name' => 'English (United States)', 'politeness' => false],
        ['code' => 'es', 'name' => 'Spanish', 'politeness' => true],
        ['code' => 'fi', 'name' => 'Finnish', 'politeness' => true],
        ['code' => 'fr-CH', 'name' => 'French (Switzerland)', 'politeness' => true],
        ['code' => 'fr-FR', 'name' => 'French (France)', 'politeness' => true],
        ['code' => 'gsw-u-sd-chbe', 'name' => 'Swiss German (Bern)', 'politeness' => false],
        ['code' => 'gsw-u-sd-chzh', 'name' => 'Swiss German (Zurich)', 'politeness' => false],
        ['code' => 'hr', 'name' => 'Croatian', 'politeness' => true],
        ['code' => 'hu', 'name' => 'Hungarian', 'politeness' => true],
        ['code' => 'id', 'name' => 'Indonesian', 'politeness' => true],
        ['code' => 'it-CH', 'name' => 'Italian (Switzerland)', 'politeness' => true],
        ['code' => 'it-IT', 'name' => 'Italian (Italy)', 'politeness' => true],
        ['code' => 'ja', 'name' => 'Japanese', 'politeness' => true],
        ['code' => 'ko', 'name' => 'Korean', 'politeness' => true],
        ['code' => 'nb', 'name' => 'Norwegian Bokmål', 'politeness' => true],
        ['code' => 'nl', 'name' => 'Dutch', 'politeness' => true],
        ['code' => 'pl', 'name' => 'Polish', 'politeness' => true],
        ['code' => 'pt-BR', 'name' => 'Portuguese (Brazil)', 'politeness' => true],
        ['code' => 'pt-PT', 'name' => 'Portuguese (Portugal)', 'politeness' => true],
        ['code' => 'rm', 'name' => 'Romansh', 'politeness' => false],
        ['code' => 'ro', 'name' => 'Romanian', 'politeness' => true],
        ['code' => 'ru', 'name' => 'Russian', 'politeness' => true],
        ['code' => 'sk', 'name' => 'Slovak', 'politeness' => true],
        ['code' => 'sl', 'name' => 'Slovenian', 'politeness' => true],
        ['code' => 'sq', 'name' => 'Albanian', 'politeness' => false],
        ['code' => 'sr-Cyrl', 'name' => 'Serbian (Cyrillic)', 'politeness' => false],
        ['code' => 'sr-Latn', 'name' => 'Serbian (Latin)', 'politeness' => false],
        ['code' => 'sv', 'name' => 'Swedish', 'politeness' => true],
        ['code' => 'th', 'name' => 'Thai', 'politeness' => true],
        ['code' => 'tr', 'name' => 'Turkish', 'politeness' => true],
        ['code' => 'vi', 'name' => 'Vietnamese', 'politeness' => true],
        ['code' => 'zh-Hans', 'name' => 'Chinese (Simplified)', 'politeness' => true],
        ['code' => 'zh-Hant', 'name' => 'Chinese (Traditional)', 'politeness' => true],
    ];

    /**
     * The default target of a bare language that Supertext offers only with a region or script. Swiss
     * German (gsw) has none: neither dialect is the obvious one.
     *
     * @var array<string, string>
     */
    public const DEFAULT_TARGETS = [
        'de' => 'de-DE',
        'en' => 'en-US',
        'fr' => 'fr-FR',
        'it' => 'it-IT',
        'pt' => 'pt-PT',
        'sr' => 'sr-Latn',
        'zh' => 'zh-Hans',
    ];

    private const CHINESE_REGIONS = ['tw' => 'zh-Hant', 'hk' => 'zh-Hant', 'mo' => 'zh-Hant', 'cn' => 'zh-Hans', 'sg' => 'zh-Hans'];

    private function __construct()
    {
    }

    /** The supported target that equals $code (apart from case and "_"/"-"), else null. "de" → null, "de_ch" → "de-CH". */
    public static function matchTarget(string $code): ?string
    {
        return self::findTarget($code)['code'] ?? null;
    }

    /**
     * The default target for a CMS code that has no exact match, else null: a bare language's DEFAULT_TARGETS
     * entry ("de" → "de-DE"), Chinese regions ("zh-TW" → "zh-Hant", "zh-CN" → "zh-Hans"), language-Script-REGION
     * ("sr-Latn-RS" → "sr-Latn") and language-REGION of a bare target ("es-ES" → "es"). Never a guessed
     * region: "en-IE", "de-LI" → null.
     */
    public static function defaultTarget(string $code): ?string
    {
        $key = self::key($code);
        if ($key === '' || self::findTarget($key) !== null) {
            return null;
        }
        $parts = explode('-', $key);
        $lang = $parts[0];
        $count = \count($parts);
        if ($count === 1) {
            return self::DEFAULT_TARGETS[$lang] ?? null;
        }
        if ($count === 2 && $lang === 'zh') {
            return self::CHINESE_REGIONS[$parts[1]] ?? null;
        }
        if ($count === 3 && preg_match('/^[a-z]{4}$/', $parts[1]) === 1 && self::isRegion($parts[2])) {
            return self::matchTarget($lang . '-' . $parts[1]);
        }
        if ($count === 2 && self::isRegion($parts[1])) {
            return self::matchTarget($lang);
        }

        return null;
    }

    /** What a settings screen preselects for a CMS language: the exact match, else the default, else null. */
    public static function suggestTarget(string $code): ?string
    {
        return self::matchTarget($code) ?? self::defaultTarget($code);
    }

    /**
     * The targets sorted by English name, for the language combo box. Show the name localized where the
     * platform can (e.g. Symfony Intl's Languages/Locales, PHP's Locale::getDisplayName) and the code next to it.
     *
     * @return list<array{code: string, name: string, politeness: bool}>
     */
    public static function targetOptions(): array
    {
        $options = self::TARGETS;
        usort($options, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $options;
    }

    /** The supported source for $code: exact match, else its first subtag ("en-US" → "en"), else null. */
    public static function matchSource(string $code): ?string
    {
        $key = self::key($code);
        if ($key === '') {
            return null;
        }

        return self::findSource($key) ?? self::findSource(explode('-', $key)[0]);
    }

    /**
     * The target code to send: the mapping's value (sent as it is, even if it isn't in the list), else an
     * exact match, else the default (defaultTarget()).
     *
     * @param array<string, string> $mapping CMS language → Supertext code, e.g. ['de' => 'de-CH', 'no' => 'nb']
     *
     * @throws SupertextException unsupported_language (with targetLang) when there is none of them; nothing is sent
     */
    public static function target(string $code, array $mapping = []): string
    {
        $result = self::mapped($code, $mapping) ?? self::matchTarget($code) ?? self::defaultTarget($code);
        if ($result === null) {
            $trimmed = trim($code);
            throw new SupertextException(SupertextException::UNSUPPORTED_LANGUAGE, targetLang: $trimmed !== '' ? $trimmed : null);
        }

        return $result;
    }

    /**
     * The source code to send: the mapping's value, else matchSource(). "" (or blank) stays "" (auto-detect).
     *
     * @param array<string, string> $mapping CMS language → Supertext code
     *
     * @throws SupertextException unsupported_language (with sourceLang) when there is neither
     */
    public static function source(string $code, array $mapping = []): string
    {
        if (trim($code) === '') {
            return '';
        }
        // One mapping serves both directions: a mapped target code is sent in its source form (de-CH → de).
        $fromMapping = self::mapped($code, $mapping);
        $result = $fromMapping !== null ? (self::matchSource($fromMapping) ?? $fromMapping) : self::matchSource($code);
        if ($result === null) {
            throw new SupertextException(SupertextException::UNSUPPORTED_LANGUAGE, sourceLang: trim($code));
        }

        return $result;
    }

    /** Whether a supported target offers formal/informal; null for a code that isn't in the list. */
    public static function supportsPoliteness(string $targetCode): ?bool
    {
        return self::findTarget($targetCode)['politeness'] ?? null;
    }

    public static function isSupportedTarget(string $code): bool
    {
        return self::matchTarget($code) !== null;
    }

    public static function isSupportedSource(string $code): bool
    {
        return self::findSource(self::key($code)) !== null;
    }

    private static function isRegion(string $subtag): bool
    {
        return preg_match('/^(?:[a-z]{2}|[0-9]{3})$/', $subtag) === 1;
    }

    /** Case, "_" vs "-" and surrounding spaces don't matter when comparing codes. */
    private static function key(string $code): string
    {
        return strtolower(str_replace('_', '-', trim($code)));
    }

    /** @return array{code: string, name: string, politeness: bool}|null */
    private static function findTarget(string $code): ?array
    {
        $key = self::key($code);
        foreach (self::TARGETS as $language) {
            if (strtolower($language['code']) === $key) {
                return $language;
            }
        }

        return null;
    }

    private static function findSource(string $key): ?string
    {
        foreach (self::SOURCES as $language) {
            if (strtolower($language['code']) === $key) {
                return $language['code'];
            }
        }

        return null;
    }

    /**
     * The non-blank mapping value for $code (trimmed), if any.
     *
     * @param array<array-key, mixed> $mapping
     */
    private static function mapped(string $code, array $mapping): ?string
    {
        $wanted = self::key($code);
        foreach ($mapping as $from => $to) {
            if (self::key((string) $from) === $wanted && \is_string($to) && trim($to) !== '') {
                return trim($to);
            }
        }

        return null;
    }
}
