<?php

declare(strict_types=1);

namespace Supertext\Polylang\Sdk\V1;

/**
 * Small UTF-8 helpers, so the SDK doesn't need ext-mbstring.
 *
 * @internal
 */
final class Utf8
{
    /** Length in Unicode code points (the API counts characters, not bytes). */
    public static function length(string $text): int
    {
        $count = preg_match_all('/./su', $text);
        // Invalid UTF-8: fall back to bytes rather than failing.
        return $count === false ? \strlen($text) : $count;
    }

    /** The first $max code points of $text. */
    public static function truncate(string $text, int $max): string
    {
        if (preg_match('/^.{0,' . $max . '}/su', $text, $match) === 1) {
            return $match[0];
        }

        return substr($text, 0, $max);
    }

    /** UTF-8 encoding of a code point (0 < $codePoint <= 0x10FFFF). */
    public static function chr(int $codePoint): string
    {
        if ($codePoint < 0x80) {
            return \chr($codePoint & 0xFF);
        }
        if ($codePoint < 0x800) {
            return \chr(0xC0 | ($codePoint >> 6)) . \chr(0x80 | ($codePoint & 0x3F));
        }
        if ($codePoint < 0x10000) {
            return \chr(0xE0 | ($codePoint >> 12)) . \chr(0x80 | (($codePoint >> 6) & 0x3F)) . \chr(0x80 | ($codePoint & 0x3F));
        }

        return \chr((0xF0 | ($codePoint >> 18)) & 0xFF) . \chr(0x80 | (($codePoint >> 12) & 0x3F))
            . \chr(0x80 | (($codePoint >> 6) & 0x3F)) . \chr(0x80 | ($codePoint & 0x3F));
    }
}
