<?php

namespace App\Parser\Support;

use DOMDocument;
use DOMNode;
use DOMXPath;

final class Html
{
    public static function xpath(string $html): DOMXPath
    {
        $html = self::forceUtf8Meta($html);
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    public static function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        return self::normalizeText($node->textContent ?? '');
    }

    public static function normalizeText(?string $text): string
    {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{00A0}", 'Â ', '&nbsp;'], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function absoluteUrl(string $baseUrl, string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($href, '/');
    }

    private static function forceUtf8Meta(string $html): string
    {
        return str_ireplace('windows-1251', 'UTF-8', $html);
    }
}
