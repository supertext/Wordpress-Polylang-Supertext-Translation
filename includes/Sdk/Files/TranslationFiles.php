<?php

declare(strict_types=1);

namespace Supertext\Polylang\Sdk\Files;

use Supertext\Polylang\Sdk\V1\HtmlDocument;
use Supertext\Polylang\Sdk\V1\Segment;

/**
 * Translation files: export segments as XLIFF 1.2 or HTML, translate them outside the API (a CAT tool,
 * a translator, Supertext's web app), import them again (spec/translation-file.json, integration guide
 * → Translation files).
 *
 * Not tied to an API version: nothing here talks to the API. The HTML file is the same document
 * `HtmlDocument::build()` makes, plus metadata in its head.
 *
 * Imported files are untrusted. `read()` refuses DTDs and files over MAX_FILE_CHARACTERS;
 * `importTranslations()` takes markup only from the integration's current source, never from the file.
 *
 * @phpstan-type Token array{kind: 'text', text: string}|array{kind: 'start', name: string, raw: string, selfClosing: bool, attrs: list<array{string, string|null}>}|array{kind: 'end', name: string, raw: string}|array{kind: 'comment', raw: string}
 * @phpstan-type Code array{code: 'bpt'|'ept'|'ph', id: int, raw: string}
 * @phpstan-type TargetPart string|array{code: 'bpt'|'ept'|'ph', id: int}|array{code: 'invalid'}
 * @phpstan-type UnitData array{key: string, checksum: string|null, target: list<TargetPart>|string|null}
 */
final class TranslationFiles
{
    /** Larger files are refused by read() (PHP counts bytes). */
    public const MAX_FILE_CHARACTERS = 10_000_000;

    /** One whitespace character as JavaScript's `\s` defines it (the reference implementation), as UTF-8 bytes. */
    private const WS = '(?:[\t\n\x0B\f\r ]|\xC2\xA0|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80|\xEF\xBB\xBF)';

    private const NAME = '(?:(?!' . self::WS . ')[^"\'>/=])+';
    private const UNQUOTED = '(?:(?!' . self::WS . ')[^"\'=<>`])+';

    /** A comment, an end tag or a start tag (with its attributes and a self-closing slash). */
    private const TAG = '~<(?:!--.*?-->|/([a-zA-Z][a-zA-Z0-9:-]*)' . self::WS . '*>|([a-zA-Z][a-zA-Z0-9:-]*)((?:' . self::WS . '+'
        . self::NAME . '(?:' . self::WS . '*=' . self::WS . '*(?:"[^"]*"|\'[^\']*\'|' . self::UNQUOTED . '))?)*)' . self::WS . '*(/?)>)~s';

    /** One attribute (never starting inside a multibyte character). */
    private const ATTR = '~(?![\x80-\xBF])(' . self::NAME . ')(?:' . self::WS . '*=' . self::WS . '*(?:"([^"]*)"|\'([^\']*)\'|(' . self::UNQUOTED . ')))?~s';

    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const ENTITY = '/&(#[xX][0-9a-fA-F]+|#[0-9]+|[a-zA-Z][a-zA-Z0-9]*);/';
    private const KNOWN_ENTITY = '/^(?:amp|lt|gt|quot|apos|nbsp|#[xX][0-9a-fA-F]+|#[0-9]+)\z/';

    /** Runs of characters XML 1.0 can hold (valid UTF-8), followed by one byte that isn't. */
    private const XML_INVALID = '/\G((?:[\x09\x0A\x0D\x20-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE][\x80-\xBF]{2}'
        . '|\xEF(?:[\x80-\xBE][\x80-\xBF]|\xBF[\x80-\xBD])|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}'
        . '|\xF4[\x80-\x8F][\x80-\xBF]{2})*+)./s';

    private const CONTROL = '/[\x00-\x1F\x7F]/';

    private const XML_NAME = '[A-Za-z_:][-A-Za-z0-9_:.]*';
    private const XML_REF = '/^&(?:#x([0-9a-fA-F]+)|#([0-9]+)|(amp|lt|gt|quot|apos));/';
    private const PREDEFINED = ['amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'"];

    private const CHECKSUM = '/^[0-9a-f]{8}\z/';
    private const META = '/<meta\b((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>/i';

    private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'poster', 'cite', 'xlink:href'];
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** @var \WeakMap<TranslationFile, array<array-key, UnitData>>|null the per-unit target data of the files read() returned */
    private static ?\WeakMap $internals = null;

    private function __construct()
    {
    }

    /**
     * FNV-1a (32 bit) of the packed segment's UTF-8 bytes, 8 lowercase hex digits. Detects changed
     * sources; not a security check.
     *
     * @param FileSegment|array{text: string, html?: bool} $segment
     */
    public static function checksum(FileSegment|array $segment): string
    {
        $packed = $segment instanceof FileSegment
            ? HtmlDocument::pack(new Segment($segment->text, $segment->html))
            : HtmlDocument::pack(new Segment($segment['text'], $segment['html'] ?? false));
        $hash = 0x811C9DC5;
        $length = \strlen($packed);
        for ($i = 0; $i < $length; $i++) {
            $hash = (($hash ^ \ord($packed[$i])) * 0x01000193) & 0xFFFFFFFF;
        }

        return \sprintf('%08x', $hash);
    }

    // ---- Export ----

    /**
     * One XLIFF 1.2 file with one `<file>` per job. Blank segments are left out; a job with only blank
     * segments is refused.
     *
     * @param list<FileJob> $jobs
     *
     * @throws TranslationFileError invalid_job
     */
    public static function exportXliff(array $jobs): string
    {
        if ($jobs === []) {
            self::fail(TranslationFileError::INVALID_JOB, 'at least one job is needed');
        }
        $out = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<xliff version=\"1.2\" xmlns=\"urn:oasis:names:tc:xliff:document:1.2\">\n";
        foreach ($jobs as $job) {
            $segments = self::checkJob($job);
            $out .= '  <file original="' . self::xmlAttr($job->reference) . '" source-language="' . self::xmlAttr($job->sourceLanguage)
                . '" target-language="' . self::xmlAttr($job->targetLanguage) . '" datatype="html" product-name="' . self::xmlAttr($job->plugin)
                . "\" tool-id=\"supertext-sdk\">\n"
                . "    <header>\n      <tool tool-id=\"supertext-sdk\" tool-name=\"Supertext SDK\"/>\n    </header>\n    <body>\n";
            foreach ($segments as $segment) {
                $datatype = $segment->html ? '' : ' datatype="plaintext"';
                $out .= '      <trans-unit id="' . self::xmlAttr($segment->key) . '"' . $datatype . ' extradata="' . self::checksum($segment)
                    . "\" xml:space=\"preserve\">\n"
                    . '        <source>' . self::xliffSource($segment) . "</source>\n      </trans-unit>\n";
            }
            $out .= "    </body>\n  </file>\n";
        }

        return $out . "</xliff>\n";
    }

    /**
     * The HTML document for one job (what HtmlDocument::build() makes) with the job's metadata in its
     * head. Blank segments are left out; a job with only blank segments is refused.
     *
     * @throws TranslationFileError invalid_job
     */
    public static function exportHtml(FileJob $job): string
    {
        $segments = self::checkJob($job);
        $out = "<!DOCTYPE html>\n<html lang=\"" . self::htmlAttr($job->sourceLanguage) . "\"><head><meta charset=\"utf-8\">\n"
            . '<meta name="supertext:plugin" content="' . self::htmlAttr($job->plugin) . "\">\n"
            . '<meta name="supertext:reference" content="' . self::htmlAttr($job->reference) . "\">\n"
            . '<meta name="supertext:source-language" content="' . self::htmlAttr($job->sourceLanguage) . "\">\n"
            . '<meta name="supertext:target-language" content="' . self::htmlAttr($job->targetLanguage) . "\">\n";
        foreach ($segments as $i => $segment) {
            $out .= '<meta name="supertext:segment" content="' . $i . ' ' . self::checksum($segment) . ' ' . self::htmlAttr($segment->key) . "\">\n";
        }
        $out .= "</head><body>\n";
        foreach ($segments as $i => $segment) {
            $out .= '<div data-st-id="' . $i . '">' . HtmlDocument::pack(new Segment($segment->text, $segment->html)) . "</div>\n";
        }

        return $out . "</body></html>\n";
    }

    // ---- Reading ----

    /**
     * Reads an exported file after translation: XLIFF (one result per `<file>`) or HTML (one result).
     *
     * @return list<TranslationFile>
     *
     * @throws TranslationFileError too_large, invalid_file (not XLIFF or HTML, not well-formed, a DTD), missing_metadata
     */
    public static function read(string $content): array
    {
        if (\strlen($content) > self::MAX_FILE_CHARACTERS) {
            self::fail(TranslationFileError::TOO_LARGE, 'the file is larger than ' . self::MAX_FILE_CHARACTERS . ' characters');
        }
        $start = strtolower(substr(self::trimStart(self::stripBom($content)), 0, 200));
        if (str_starts_with($start, '<?xml') || str_starts_with($start, '<xliff')) {
            return self::readXliff($content);
        }
        if (str_starts_with($start, '<!doctype html') || preg_match('~^<html(?:' . self::WS . '|>)~', $start) === 1) {
            return [self::readHtml($content)];
        }

        self::fail(TranslationFileError::INVALID_FILE, 'neither an XLIFF nor an HTML file');
    }

    // ---- Importing ----

    /**
     * The translations for the integration's current segments (collected again from the item the file's
     * `reference` names), one per non-blank segment in their order. Markup comes from the current source
     * only: XLIFF codes are replaced by the source's tags; HTML translations may only use the source's
     * tags, attributes and comments, and safe URLs.
     *
     * @param list<FileSegment> $current
     *
     * @return list<ImportedSegment>
     *
     * @throws TranslationFileError invalid_file (a file not returned by read())
     */
    public static function importTranslations(TranslationFile $file, array $current): array
    {
        $units = self::$internals[$file] ?? null;
        if ($units === null) {
            self::fail(TranslationFileError::INVALID_FILE, 'not a file returned by read()');
        }
        $out = [];
        foreach ($current as $segment) {
            if (self::isBlank($segment->text)) {
                continue;
            }
            $unit = $units[$segment->key] ?? null;
            if ($unit === null) {
                $out[] = new ImportedSegment($segment->key, ImportedSegment::MISSING, null);
                continue;
            }
            // false: untranslated, null: invalid markup, string: the translation
            $target = $unit['target'];
            if ($target === null) {
                $text = false;
            } elseif (\is_string($target)) {
                if ($segment->html) {
                    $text = self::isBlank($target) ? false : self::sanitizeHtml($target, $segment->text);
                } else {
                    $plain = HtmlDocument::plain($target);
                    $text = $plain === '' ? false : $plain;
                }
            } else {
                $hasCode = false;
                $blank = true;
                foreach ($target as $part) {
                    if (\is_string($part)) {
                        $blank = $blank && self::isBlank($part);
                    } else {
                        $hasCode = true;
                    }
                }
                if (!$hasCode && $blank) {
                    $text = false;
                } elseif ($segment->html) {
                    $text = self::rebuildHtml($target, $segment->text);
                } else {
                    $text = $hasCode ? null : implode('', array_filter($target, 'is_string'));
                }
            }
            if ($text === false) {
                $out[] = new ImportedSegment($segment->key, ImportedSegment::UNTRANSLATED, null);
            } elseif ($text === null) {
                $out[] = new ImportedSegment($segment->key, ImportedSegment::INVALID_MARKUP, null);
            } else {
                $status = $unit['checksum'] === self::checksum($segment) ? ImportedSegment::OK : ImportedSegment::SOURCE_CHANGED;
                $out[] = new ImportedSegment($segment->key, $status, $text);
            }
        }

        return $out;
    }

    // ---- Helpers ----

    /** @return never */
    private static function fail(string $code, string $message): never
    {
        throw new TranslationFileError($code, $message);
    }

    private static function isBlank(string $text): bool
    {
        return preg_match('/^[ \t\r\n\f]*\z/', $text) === 1;
    }

    private static function stripBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }

    private static function trimStart(string $text): string
    {
        return (string) preg_replace('~\A' . self::WS . '+~', '', $text);
    }

    /** JavaScript's String.prototype.trim(). */
    private static function trim(string $text): string
    {
        return (string) preg_replace('~\A' . self::WS . '+|' . self::WS . '+\z~', '', $text);
    }

    private static function isXmlChar(int $cp): bool
    {
        return $cp === 0x9 || $cp === 0xA || $cp === 0xD || ($cp >= 0x20 && $cp <= 0xD7FF) || ($cp >= 0xE000 && $cp <= 0xFFFD)
            || ($cp >= 0x10000 && $cp <= 0x10FFFF);
    }

    /** The code point of the first UTF-8 character of a (valid, non-empty) string. */
    private static function firstCodePoint(string $text): int
    {
        $b = \ord($text[0]);
        if ($b < 0x80) {
            return $b;
        }
        if ($b < 0xE0) {
            return (($b & 0x1F) << 6) | (\ord($text[1]) & 0x3F);
        }
        if ($b < 0xF0) {
            return (($b & 0x0F) << 12) | ((\ord($text[1]) & 0x3F) << 6) | (\ord($text[2]) & 0x3F);
        }

        return (($b & 0x07) << 18) | ((\ord($text[1]) & 0x3F) << 12) | ((\ord($text[2]) & 0x3F) << 6) | (\ord($text[3]) & 0x3F);
    }

    // ---- HTML fragments: tokens and inline codes ----

    /**
     * The tags, comments and text of an HTML fragment.
     *
     * @return list<Token>
     */
    private static function tokenize(string $html): array
    {
        /** @var list<Token> $tokens */
        $tokens = [];
        $last = 0;
        $offset = 0;
        while (preg_match(self::TAG, $html, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset) === 1) {
            $raw = (string) $m[0][0];
            $index = $m[0][1];
            if ($index > $last) {
                $tokens[] = ['kind' => 'text', 'text' => substr($html, $last, $index - $last)];
            }
            if (str_starts_with($raw, '<!--')) {
                $tokens[] = ['kind' => 'comment', 'raw' => $raw];
            } elseif (($m[1][0] ?? null) !== null) {
                $tokens[] = ['kind' => 'end', 'name' => strtolower($m[1][0]), 'raw' => $raw];
            } else {
                $attrs = [];
                preg_match_all(self::ATTR, (string) ($m[3][0] ?? ''), $found, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
                foreach ($found as $a) {
                    $attrs[] = [strtolower((string) $a[1]), $a[2] ?? $a[3] ?? $a[4] ?? null];
                }
                $tokens[] = ['kind' => 'start', 'name' => strtolower((string) ($m[2][0] ?? '')), 'raw' => $raw, 'selfClosing' => ($m[4][0] ?? '') === '/', 'attrs' => $attrs];
            }
            $last = $index + \strlen($raw);
            $offset = $last;
        }
        if ($last < \strlen($html)) {
            $tokens[] = ['kind' => 'text', 'text' => substr($html, $last)];
        }

        return $tokens;
    }

    /**
     * The inline codes of an HTML segment (integration guide → Translation files): a start tag with its
     * matching end tag becomes bpt/ept, everything else (void and self-closing tags, unmatched tags,
     * comments, entities XML can't hold) a ph. Ids count from 1 in order of appearance; an ept has its
     * bpt's id. Text has the known entities decoded.
     *
     * @return list<string|Code>
     */
    private static function segmentParts(string $html): array
    {
        $tokens = self::tokenize($html);
        $endOf = [];
        $startOf = [];
        $stack = [];
        foreach ($tokens as $i => $t) {
            if ($t['kind'] === 'start' && !$t['selfClosing'] && !\in_array($t['name'], self::VOID, true)) {
                $stack[] = ['name' => $t['name'], 'index' => $i];
            } elseif ($t['kind'] === 'end' && $stack !== [] && $stack[\count($stack) - 1]['name'] === $t['name']) {
                $start = array_pop($stack)['index'];
                $endOf[$start] = $i;
                $startOf[$i] = $start;
            }
        }

        /** @var list<string|Code> $parts */
        $parts = [];
        $ids = [];
        $next = 1;
        foreach ($tokens as $i => $t) {
            if ($t['kind'] === 'text') {
                $last = 0;
                preg_match_all(self::ENTITY, $t['text'], $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($found as $m) {
                    [$entity, $at] = $m[0];
                    if ($at > $last) {
                        self::appendText($parts, substr($t['text'], $last, $at - $last));
                    }
                    $decoded = preg_match(self::KNOWN_ENTITY, $m[1][0]) === 1 ? HtmlDocument::decode($entity) : $entity;
                    if ($decoded !== $entity && self::isXmlChar(self::firstCodePoint($decoded))) {
                        self::appendText($parts, $decoded);
                    } else {
                        $parts[] = ['code' => 'ph', 'id' => $next++, 'raw' => $entity];
                    }
                    $last = $at + \strlen($entity);
                }
                if ($last < \strlen($t['text'])) {
                    self::appendText($parts, substr($t['text'], $last));
                }
            } elseif ($t['kind'] === 'start' && isset($endOf[$i])) {
                $id = $next++;
                $ids[$i] = $id;
                $parts[] = ['code' => 'bpt', 'id' => $id, 'raw' => $t['raw']];
            } elseif ($t['kind'] === 'end' && isset($startOf[$i])) {
                $parts[] = ['code' => 'ept', 'id' => $ids[$startOf[$i]], 'raw' => $t['raw']];
            } else {
                $parts[] = ['code' => 'ph', 'id' => $next++, 'raw' => $t['raw']];
            }
        }

        return $parts;
    }

    /**
     * Adds text to the parts, joined with the text before it.
     *
     * @param list<string|Code> $parts
     */
    private static function appendText(array &$parts, string $text): void
    {
        $last = \count($parts) - 1;
        if ($last >= 0 && \is_string($parts[$last])) {
            $parts[$last] .= $text;
        } else {
            $parts[] = $text;
        }
    }

    // ---- Export helpers ----

    private static function xmlText(string $text): string
    {
        $text = (string) preg_replace(self::XML_INVALID, '$1', $text);

        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', "\r" => '&#13;']);
    }

    private static function xmlAttr(string $text): string
    {
        return str_replace('"', '&quot;', self::xmlText($text));
    }

    private static function htmlAttr(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
    }

    /**
     * @return list<FileSegment> the non-blank segments
     */
    private static function checkJob(FileJob $job): array
    {
        foreach (['plugin' => $job->plugin, 'reference' => $job->reference, 'sourceLanguage' => $job->sourceLanguage, 'targetLanguage' => $job->targetLanguage] as $field => $value) {
            if (self::trim($value) === '' || preg_match(self::CONTROL, $value) === 1) {
                self::fail(TranslationFileError::INVALID_JOB, $field . ' must be a non-empty string without control characters');
            }
        }
        $keys = [];
        $segments = [];
        foreach ($job->segments as $segment) {
            if ($segment->key === '' || preg_match(self::CONTROL, $segment->key) === 1) {
                self::fail(TranslationFileError::INVALID_JOB, 'segment keys must be non-empty strings without control characters');
            }
            if (isset($keys[$segment->key])) {
                self::fail(TranslationFileError::INVALID_JOB, 'duplicate segment key ' . json_encode($segment->key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            }
            $keys[$segment->key] = true;
            if (!self::isBlank($segment->text)) {
                $segments[] = $segment;
            }
        }
        if ($segments === []) {
            self::fail(TranslationFileError::INVALID_JOB, 'the job has nothing to translate (all segments blank)');
        }

        return $segments;
    }

    private static function xliffSource(FileSegment $segment): string
    {
        if (!$segment->html) {
            return self::xmlText(str_replace(["\r\n", "\r"], "\n", $segment->text));
        }
        $out = '';
        foreach (self::segmentParts($segment->text) as $part) {
            $out .= \is_string($part)
                ? self::xmlText($part)
                : '<' . $part['code'] . ' id="' . $part['id'] . '">' . self::xmlText($part['raw']) . '</' . $part['code'] . '>';
        }

        return $out;
    }

    // ---- Reading helpers ----

    private static function badXml(string $what): never
    {
        self::fail(TranslationFileError::INVALID_FILE, 'not a well-formed XML file (' . $what . ')');
    }

    private static function decodeXml(string $text): string
    {
        $out = '';
        $i = 0;
        $length = \strlen($text);
        while ($i < $length) {
            $amp = strpos($text, '&', $i);
            if ($amp === false) {
                return $out . substr($text, $i);
            }
            $out .= substr($text, $i, $amp - $i);
            if (preg_match(self::XML_REF, substr($text, $amp, 12), $m, PREG_UNMATCHED_AS_NULL) !== 1) {
                self::badXml('unknown entity');
            }
            if (($m[3] ?? null) !== null) {
                $out .= self::PREDEFINED[$m[3]];
            } else {
                $cp = ($m[1] ?? null) !== null ? (int) hexdec($m[1]) : (int) $m[2];
                if (!self::isXmlChar($cp)) {
                    self::badXml('character reference');
                }
                $out .= self::chr($cp);
            }
            $i = $amp + \strlen((string) $m[0]);
        }

        return $out;
    }

    private static function chr(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp & 0xFF);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12)) . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
        }

        return \chr((0xF0 | ($cp >> 18)) & 0xFF) . \chr(0x80 | (($cp >> 12) & 0x3F)) . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
    }

    /** A small XML parser: elements, attributes, text, CDATA; comments and processing instructions skipped; no DTD. */
    private static function parseXml(string $source): XmlElement
    {
        $s = str_replace(["\r\n", "\r"], "\n", self::stripBom($source));
        $length = \strlen($s);
        $i = 0;
        $root = null;
        /** @var list<XmlElement> $stack */
        $stack = [];
        while ($i < $length) {
            $lt = strpos($s, '<', $i);
            $textEnd = $lt === false ? $length : $lt;
            if ($textEnd > $i) {
                $text = substr($s, $i, $textEnd - $i);
                if ($stack !== []) {
                    $stack[\count($stack) - 1]->children[] = self::decodeXml($text);
                } elseif (!self::isBlank($text)) {
                    self::badXml('text outside the root element');
                }
                if (str_contains($text, ']]>')) {
                    self::badXml(']]> in text');
                }
            }
            if ($lt === false) {
                break;
            }
            if (substr_compare($s, '<!--', $lt, 4) === 0) {
                $end = strpos($s, '-->', $lt + 4);
                if ($end === false) {
                    self::badXml('unclosed comment');
                }
                $i = $end + 3;
            } elseif (substr_compare($s, '<![CDATA[', $lt, 9) === 0) {
                $end = strpos($s, ']]>', $lt + 9);
                if ($end === false || $stack === []) {
                    self::badXml('CDATA');
                }
                $stack[\count($stack) - 1]->children[] = substr($s, $lt + 9, $end - $lt - 9);
                $i = $end + 3;
            } elseif (substr_compare($s, '<?', $lt, 2) === 0) {
                $end = strpos($s, '?>', $lt + 2);
                if ($end === false) {
                    self::badXml('unclosed processing instruction');
                }
                $i = $end + 2;
            } elseif (substr_compare($s, '<!', $lt, 2) === 0) {
                self::badXml('declarations are not allowed');
            } elseif (substr_compare($s, '</', $lt, 2) === 0) {
                $name = preg_match('/\G' . self::XML_NAME . '/', $s, $nm, 0, $lt + 2) === 1 ? $nm[0] : null;
                $end = strpos($s, '>', $lt);
                if ($name === null || $end === false || self::trim(substr($s, $lt + 2 + \strlen($name), $end - $lt - 2 - \strlen($name))) !== '') {
                    self::badXml('end tag');
                }
                $open = array_pop($stack);
                if ($open === null || $open->name !== $name) {
                    self::badXml('mismatched end tag');
                }
                $i = $end + 1;
            } else {
                if (preg_match('/\G' . self::XML_NAME . '/', $s, $nm, 0, $lt + 1) !== 1) {
                    self::badXml('start tag');
                }
                $name = $nm[0];
                $j = $lt + 1 + \strlen($name);
                $element = new XmlElement($name);
                $seen = [];
                for (;;) {
                    $ws = preg_match('~^' . self::WS . '*~', substr($s, $j, 200), $wm) === 1 ? \strlen($wm[0]) : 0;
                    $j += $ws;
                    if (substr_compare($s, '/>', $j, 2) === 0 || ($s[$j] ?? '') === '>') {
                        break;
                    }
                    if ($ws === 0) {
                        self::badXml('attribute');
                    }
                    if (preg_match('/^' . self::XML_NAME . '/', substr($s, $j, 200), $am) !== 1) {
                        self::badXml('attribute');
                    }
                    $attr = $am[0];
                    $j += \strlen($attr);
                    if (preg_match('~^' . self::WS . '*=' . self::WS . '*(["\'])~', substr($s, $j, 200), $eq) !== 1) {
                        self::badXml('attribute value');
                    }
                    $j += \strlen($eq[0]);
                    $close = strpos($s, $eq[1], $j);
                    if ($close === false) {
                        self::badXml('attribute value');
                    }
                    $raw = substr($s, $j, $close - $j);
                    if (str_contains($raw, '<')) {
                        self::badXml('< in attribute value');
                    }
                    if (isset($seen[$attr])) {
                        self::badXml('duplicate attribute');
                    }
                    $seen[$attr] = true;
                    $colon = strrpos($attr, ':');
                    $element->attrs[$colon === false ? $attr : substr($attr, $colon + 1)] = self::decodeXml(strtr($raw, "\t\n", '  '));
                    $j = $close + 1;
                }
                $selfClosing = ($s[$j] ?? '') === '/';
                $i = $j + ($selfClosing ? 2 : 1);
                if ($stack !== []) {
                    $stack[\count($stack) - 1]->children[] = $element;
                } elseif ($root !== null) {
                    self::badXml('more than one root element');
                } else {
                    $root = $element;
                }
                if (!$selfClosing) {
                    $stack[] = $element;
                }
            }
        }
        if ($root === null || $stack !== []) {
            self::badXml('unclosed element');
        }

        return $root;
    }

    /**
     * @param list<XmlElement|string> $nodes
     * @param list<TargetPart>        $out
     *
     * @return list<TargetPart>
     */
    private static function targetParts(array $nodes, array $out = []): array
    {
        foreach ($nodes as $node) {
            if (\is_string($node)) {
                $last = \count($out) - 1;
                if ($last >= 0 && \is_string($out[$last])) {
                    $out[$last] .= $node;
                } else {
                    $out[] = $node;
                }
            } else {
                $name = $node->localName();
                if ($name === 'mrk') {
                    $out = self::targetParts($node->children, $out);
                } elseif (($name === 'bpt' || $name === 'ept' || $name === 'ph') && preg_match('/^[1-9][0-9]{0,8}\z/', $node->attrs['id'] ?? '') === 1) {
                    $out[] = ['code' => $name, 'id' => (int) $node->attrs['id']];
                } else {
                    $out[] = ['code' => 'invalid'];
                }
            }
        }

        return $out;
    }

    /**
     * @return list<TranslationFile>
     */
    private static function readXliff(string $content): array
    {
        $root = self::parseXml($content);
        if (!$root->is('xliff')) {
            self::fail(TranslationFileError::INVALID_FILE, 'not an XLIFF file');
        }
        $files = array_values(array_filter($root->children, static fn ($c): bool => $c instanceof XmlElement && $c->is('file')));
        if ($files === []) {
            self::fail(TranslationFileError::INVALID_FILE, 'the XLIFF file has no <file> element');
        }
        $out = [];
        foreach ($files as $file) {
            $original = $file->attrs['original'] ?? '';
            $sourceLanguage = $file->attrs['source-language'] ?? '';
            $targetLanguage = $file->attrs['target-language'] ?? '';
            $plugin = $file->attrs['product-name'] ?? '';
            if ($original === '' || $sourceLanguage === '' || $targetLanguage === '' || $plugin === '') {
                self::fail(TranslationFileError::MISSING_METADATA, 'the <file> element lacks original, source-language, target-language or product-name');
            }
            /** @var array<array-key, UnitData> $units */
            $units = [];
            $walk = static function (array $nodes) use (&$walk, &$units): void {
                foreach ($nodes as $node) {
                    if (!$node instanceof XmlElement) {
                        continue;
                    }
                    if ($node->is('group')) {
                        $walk($node->children);
                    } elseif ($node->is('trans-unit')) {
                        $key = $node->attrs['id'] ?? '';
                        if ($key === '' || isset($units[$key])) {
                            continue;
                        }
                        $target = null;
                        foreach ($node->children as $child) {
                            if ($child instanceof XmlElement && $child->is('target')) {
                                $target = $child;
                                break;
                            }
                        }
                        $extradata = $node->attrs['extradata'] ?? '';
                        $units[$key] = [
                            'key' => $key,
                            'checksum' => preg_match(self::CHECKSUM, $extradata) === 1 ? $extradata : null,
                            'target' => $target !== null ? self::targetParts($target->children) : null,
                        ];
                    }
                }
            };
            foreach ($file->children as $body) {
                if ($body instanceof XmlElement && $body->is('body')) {
                    $walk($body->children);
                }
            }
            $out[] = self::register(TranslationFile::XLIFF, $plugin, $original, $sourceLanguage, $targetLanguage, $units);
        }

        return $out;
    }

    private static function readHtml(string $content): TranslationFile
    {
        $meta = [];
        $segments = [];
        preg_match_all(self::META, $content, $metas, PREG_SET_ORDER);
        foreach ($metas as $m) {
            $attrs = [];
            preg_match_all(self::ATTR, $m[1], $found, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($found as $a) {
                $name = strtolower((string) $a[1]);
                if (!\array_key_exists($name, $attrs)) {
                    $attrs[$name] = HtmlDocument::decode($a[2] ?? $a[3] ?? $a[4] ?? '');
                }
            }
            $name = strtolower($attrs['name'] ?? '');
            $value = $attrs['content'] ?? null;
            if (!str_starts_with($name, 'supertext:') || $value === null) {
                continue;
            }
            if ($name === 'supertext:segment') {
                if (preg_match('/^([0-9]{1,9}) ([0-9a-f]{8}) (.+)\z/s', $value, $sm) === 1) {
                    $segments[] = ['position' => (int) $sm[1], 'checksum' => $sm[2], 'key' => $sm[3]];
                }
            } elseif (!\array_key_exists($name, $meta)) {
                $meta[$name] = $value;
            }
        }
        $plugin = $meta['supertext:plugin'] ?? '';
        $reference = $meta['supertext:reference'] ?? '';
        $sourceLanguage = $meta['supertext:source-language'] ?? '';
        $targetLanguage = $meta['supertext:target-language'] ?? '';
        if ($plugin === '' || $reference === '' || $sourceLanguage === '' || $targetLanguage === '' || $segments === []) {
            self::fail(TranslationFileError::MISSING_METADATA, 'the HTML file lacks the supertext:* metadata in its head');
        }
        $positions = [];
        foreach ($segments as $segment) {
            $positions[$segment['position']] = true;
        }
        $inner = HtmlDocument::htmlSegments($content, $positions);
        usort($segments, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        /** @var array<array-key, UnitData> $units */
        $units = [];
        $taken = [];
        foreach ($segments as $segment) {
            if (isset($units[$segment['key']]) || isset($taken[$segment['position']])) {
                continue;
            }
            $taken[$segment['position']] = true;
            $units[$segment['key']] = ['key' => $segment['key'], 'checksum' => $segment['checksum'], 'target' => $inner[$segment['position']] ?? null];
        }

        return self::register(TranslationFile::HTML, $plugin, $reference, $sourceLanguage, $targetLanguage, $units);
    }

    /**
     * @param array<array-key, UnitData> $units
     */
    private static function register(string $format, string $plugin, string $reference, string $sourceLanguage, string $targetLanguage, array $units): TranslationFile
    {
        $list = [];
        foreach ($units as $unit) {
            $list[] = new FileUnit($unit['key'], $unit['checksum']);
        }
        $file = new TranslationFile($format, $plugin, $reference, $sourceLanguage, $targetLanguage, $list);
        self::$internals ??= new \WeakMap();
        self::$internals[$file] = $units;

        return $file;
    }

    // ---- Import helpers ----

    /**
     * Rebuilds an HTML segment from XLIFF target parts with the current source's codes; null if the
     * target uses codes the source doesn't have.
     *
     * @param list<TargetPart> $parts
     */
    private static function rebuildHtml(array $parts, string $source): ?string
    {
        $codes = [];
        foreach (self::segmentParts($source) as $part) {
            if (!\is_string($part)) {
                $codes[$part['code'] . ':' . $part['id']] = $part['raw'];
            }
        }
        $open = [];
        $used = [];
        $out = '';
        foreach ($parts as $part) {
            if (\is_string($part)) {
                $out .= strtr($part, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
                continue;
            }
            if ($part['code'] === 'invalid') {
                return null;
            }
            $raw = $codes[$part['code'] . ':' . $part['id']] ?? null;
            if ($raw === null) {
                return null;
            }
            if ($part['code'] === 'bpt') {
                if (isset($used[$part['id']])) {
                    return null;
                }
                $used[$part['id']] = true;
                $open[] = $part['id'];
            } elseif ($part['code'] === 'ept') {
                if (array_pop($open) !== $part['id']) {
                    return null;
                }
            }
            $out .= $raw;
        }

        return $open === [] ? $out : null;
    }

    private static function safeUrl(string $value): bool
    {
        $v = strtolower((string) preg_replace('/[\x00-\x20\x7F]/', '', HtmlDocument::decode($value)));
        $head = (preg_split('~[/?#]~', $v) ?: [''])[0];
        if (str_contains($head, '&')) {
            return false;
        }
        $colon = strpos($head, ':');

        return $colon === false || \in_array(substr($head, 0, $colon), self::SAFE_SCHEMES, true);
    }

    /**
     * A translated HTML segment from an HTML file, rebuilt; null if it has tags, attributes, comments or
     * URLs the source doesn't allow.
     */
    private static function sanitizeHtml(string $inner, string $source): ?string
    {
        /** @var array<string, array<string, true>> $allowed */
        $allowed = [];
        $comments = [];
        foreach (self::tokenize($source) as $t) {
            if ($t['kind'] === 'comment') {
                $comments[$t['raw']] = true;
            } elseif ($t['kind'] !== 'text') {
                $names = $allowed[$t['name']] ?? [];
                if ($t['kind'] === 'start') {
                    foreach ($t['attrs'] as [$name]) {
                        $names[$name] = true;
                    }
                }
                $allowed[$t['name']] = $names;
            }
        }
        $out = '';
        foreach (self::tokenize($inner) as $t) {
            if ($t['kind'] === 'text') {
                $out .= str_replace('<', '&lt;', $t['text']);
            } elseif ($t['kind'] === 'comment') {
                if (!isset($comments[$t['raw']])) {
                    return null;
                }
                $out .= $t['raw'];
            } else {
                $names = $allowed[$t['name']] ?? null;
                if ($names === null) {
                    return null;
                }
                if ($t['kind'] === 'start') {
                    foreach ($t['attrs'] as [$name, $value]) {
                        if (!isset($names[$name])) {
                            return null;
                        }
                        if ($value !== null && \in_array($name, self::URL_ATTRIBUTES, true) && !self::safeUrl($value)) {
                            return null;
                        }
                        if ($value !== null && $name === 'srcset') {
                            foreach (explode(',', $value) as $candidate) {
                                $url = (string) (preg_split('~' . self::WS . '+~', self::trim($candidate)) ?: [''])[0];
                                if (!self::safeUrl($url)) {
                                    return null;
                                }
                            }
                        }
                    }
                }
                $out .= $t['raw'];
            }
        }

        return $out;
    }
}
