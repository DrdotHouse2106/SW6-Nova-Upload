<?php declare(strict_types=1);

namespace Nova\Upload\Service;

/**
 * Prüft, ob der Inhalt einer hochgeladenen Datei zur Endung passt – ohne Shopware-Abhängigkeit
 * (ohne Kernel testbar).
 *
 * - Bilder: Signatur (Magic Bytes) muss zum Format passen, zusätzlich muss der per finfo erkannte
 *   Typ genau der erwartete sein (kein beliebiges image/*, also z. B. kein SVG als .jpg).
 * - PDF: muss mit „%PDF-“ beginnen.
 * - SVG: wird als XML geparst (ohne Netzwerk, ohne Entity-Ersetzung) und nur angenommen, wenn es
 *   keinen DOCTYPE/keine Entities, keine Verarbeitungsanweisungen, keine Skripte, kein
 *   foreignObject, keine Animationen, keine Ereignis-Attribute (on…) und keine externen Verweise
 *   enthält. Im Zweifel wird abgelehnt – Kunden können stattdessen PDF oder PNG schicken.
 * - Alle Endungen: per finfo erkannte Web-/Programminhalte werden abgelehnt.
 */
final class ContentInspector
{
    /** Erwartete Typen je Bild-Endung (finfo), keine Platzhalter */
    private const IMAGE_MIME = [
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp', 'image/x-bmp'],
        'tif' => ['image/tiff'],
        'tiff' => ['image/tiff'],
        // HEIC wird je nach libmagic-Version nicht erkannt – dort entscheidet die Signatur
        'heic' => ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'application/octet-stream'],
        'heif' => ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'application/octet-stream'],
    ];

    private const PDF_MIME = ['application/pdf', 'application/x-pdf', 'application/acrobat'];

    /** Web- und Programminhalte, unabhängig von der Endung nie erlaubt */
    private const FORBIDDEN_MIME = [
        'text/html', 'application/xhtml+xml', 'text/javascript', 'application/javascript', 'application/x-javascript',
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'text/x-shellscript', 'application/x-sh',
        'application/x-msdownload', 'application/x-dosexec', 'application/vnd.microsoft.portable-executable',
        'application/x-executable', 'application/x-sharedlib', 'application/x-mach-binary', 'application/java-archive',
        'application/x-pie-executable', 'text/x-python', 'text/x-perl', 'application/hta',
    ];

    /** SVG-Elemente, die ein harmloses Bild beschreiben (ohne Skript, Animation, Links, fremde Inhalte) */
    private const SVG_ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata', 'style', 'switch',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textpath', 'tref',
        'lineargradient', 'radialgradient', 'stop', 'pattern', 'clippath', 'mask', 'marker', 'image', 'view',
        'filter', 'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite', 'feconvolvematrix',
        'fediffuselighting', 'fedisplacementmap', 'fedistantlight', 'fedropshadow', 'feflood', 'fefunca',
        'fefuncb', 'fefuncg', 'fefuncr', 'fegaussianblur', 'feimage', 'femerge', 'femergenode',
        'femorphology', 'feoffset', 'fepointlight', 'fespecularlighting', 'fespotlight', 'fetile',
        'feturbulence', 'font', 'font-face', 'font-face-src', 'font-face-name', 'glyph', 'missing-glyph',
        'hkern', 'vkern', 'color-profile', 'solidcolor', 'meshgradient', 'meshrow', 'meshpatch', 'hatch', 'hatchpath',
    ];

    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    /** Namensräume, deren Elemente der Browser ausführt bzw. als Dokument darstellt */
    private const FORBIDDEN_NAMESPACES = [
        'http://www.w3.org/1999/xhtml',
        'http://www.w3.org/1998/Math/MathML',
        'http://www.w3.org/2001/xml-events',
        'http://www.w3.org/1999/XSL/Transform',
        'http://www.mozilla.org/keymaster/gatekeeper/there.is.only.xul',
    ];

    /** Eingebettete Rasterbilder als data:-URL sind erlaubt, alles andere nicht */
    private const DATA_IMAGE = '/^data:image\/(png|jpe?g|gif|webp);base64,[a-z0-9+\/=\s]*$/i';

    public static function matches(string $path, string $extension): bool
    {
        $extension = strtolower($extension);
        $head = self::head($path);
        if ($head === null) {
            return false;
        }

        $mime = self::sniff($path);
        if ($mime !== null && \in_array($mime, self::FORBIDDEN_MIME, true)) {
            return false;
        }

        if ($extension === 'svg') {
            $content = @file_get_contents($path);

            return \is_string($content) && self::isSafeSvg($content);
        }

        // SVG gehört nur in .svg (sonst greift die SVG-Prüfung nicht)
        if ($mime === 'image/svg+xml') {
            return false;
        }

        if (isset(self::IMAGE_MIME[$extension])) {
            return self::hasImageSignature($head, $extension)
                && ($mime === null || \in_array($mime, self::IMAGE_MIME[$extension], true));
        }

        if ($extension === 'pdf') {
            return str_starts_with($head, '%PDF-')
                && ($mime === null || \in_array($mime, self::PDF_MIME, true));
        }

        return true;
    }

    public static function hasImageSignature(string $head, string $extension): bool
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'png' => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
            'gif' => str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a'),
            'webp' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
            'bmp' => str_starts_with($head, 'BM'),
            'tif', 'tiff' => str_starts_with($head, "II*\x00") || str_starts_with($head, "MM\x00*")
                || str_starts_with($head, "II+\x00") || str_starts_with($head, "MM\x00+"),
            'heic', 'heif' => substr($head, 4, 4) === 'ftyp'
                && \in_array(substr($head, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1', 'heif'], true),
            default => false,
        };
    }

    /**
     * Strenge SVG-Prüfung über den XML-Baum (nicht per Regex auf den Rohbytes – der Browser
     * dekodiert Zeichenreferenzen, bevor er Attribute auswertet; der Parser hier auch).
     */
    public static function isSafeSvg(string $content): bool
    {
        if ($content === '' || str_contains($content, "\0")) {
            return false;
        }

        // Nur UTF-8 (oder ASCII): andere Kodierungen könnten die Vorprüfung unten umgehen
        if (str_starts_with($content, "\xFE\xFF") || str_starts_with($content, "\xFF\xFE") || !mb_check_encoding($content, 'UTF-8')) {
            return false;
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        // DOCTYPE/Entities gar nicht erst parsen (Entity-Expansion, externe Entities)
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $content)) {
            return false;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // Ohne LIBXML_NOENT/LIBXML_DTDLOAD: keine Entity-Ersetzung, kein Nachladen; LIBXML_NONET: kein Netz
            $loaded = $document->loadXML($content, \LIBXML_NONET | \LIBXML_NOCDATA);
        } catch (\Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded || $document->doctype !== null) {
            return false;
        }

        $encoding = $document->xmlEncoding;
        if ($encoding !== null && !\in_array(strtolower($encoding), ['utf-8', 'us-ascii', 'ascii'], true)) {
            return false;
        }

        $root = $document->documentElement;
        if ($root === null || strtolower($root->localName ?? '') !== 'svg') {
            return false;
        }

        foreach ($document->childNodes as $node) {
            if (!self::isSafeNode($node)) {
                return false;
            }
        }

        return true;
    }

    private static function isSafeNode(\DOMNode $node): bool
    {
        switch ($node->nodeType) {
            case \XML_TEXT_NODE:
            case \XML_CDATA_SECTION_NODE:
            case \XML_COMMENT_NODE:
                return true;
            case \XML_ELEMENT_NODE:
                break;
            default:
                // Verarbeitungsanweisungen (xml-stylesheet), Entity-Verweise, DTD …
                return false;
        }

        \assert($node instanceof \DOMElement);
        if (!self::isSafeElement($node)) {
            return false;
        }

        foreach ($node->childNodes as $child) {
            if (!self::isSafeNode($child)) {
                return false;
            }
        }

        return true;
    }

    private static function isSafeElement(\DOMElement $element): bool
    {
        $name = strtolower($element->localName ?? '');
        $namespace = $element->namespaceURI;

        if ($namespace !== null && \in_array($namespace, self::FORBIDDEN_NAMESPACES, true)) {
            return false;
        }

        // SVG-Elemente (auch ohne Namensraum) nur aus der Positivliste; Elemente aus fremden
        // Namensräumen (Metadaten von Grafikprogrammen) stellt der Browser nicht dar
        if (($namespace === null || $namespace === self::SVG_NAMESPACE) && !\in_array($name, self::SVG_ELEMENTS, true)) {
            return false;
        }

        if ($name === 'style' && !self::isSafeCss($element->textContent)) {
            return false;
        }

        foreach ($element->attributes ?? [] as $attribute) {
            if (!self::isSafeAttribute(strtolower($attribute->localName ?? ''), $attribute->value)) {
                return false;
            }
        }

        return true;
    }

    private static function isSafeAttribute(string $name, string $value): bool
    {
        // Ereignis-Attribute (onload, onclick, onbegin …) in jedem Namensraum
        if (str_starts_with($name, 'on')) {
            return false;
        }

        $compact = self::compact($value);
        if (preg_match('/(javascript|vbscript|livescript):|data:(?!image\/(png|jpe?g|gif|webp);)/', $compact)) {
            return false;
        }

        if ($name === 'href' || $name === 'src') {
            return self::isLocalReference($value);
        }

        if ($name === 'style') {
            return self::isSafeCss($value);
        }

        // Präsentationsattribute wie fill="url(#verlauf)"
        return !str_contains($compact, 'url(') || self::isSafeCss($value);
    }

    /**
     * Nur Verweise ins eigene Dokument (#id) oder eingebettete Rasterbilder – nichts von außen
     */
    private static function isLocalReference(string $value): bool
    {
        $value = trim($value);

        return $value === '' || str_starts_with($value, '#') || preg_match(self::DATA_IMAGE, $value) === 1;
    }

    private static function isSafeCss(string $css): bool
    {
        $compact = self::compact($css);

        // CSS-Escapes (u\72l), @import, alte IE-Ausdrücke, Bindings
        if (str_contains($css, '\\') || str_contains($compact, '@import') || str_contains($compact, 'expression(')
            || str_contains($compact, '-moz-binding') || str_contains($compact, 'behavior:')) {
            return false;
        }

        if (preg_match('/(javascript|vbscript|livescript):/', $compact)) {
            return false;
        }

        if (!preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/is', $css, $matches)) {
            return !str_contains($compact, 'url(');
        }

        foreach ($matches[2] as $target) {
            if (!self::isLocalReference($target)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Klein, ohne Leer- und Steuerzeichen – wie Browser „java\tscript:“ lesen
     */
    private static function compact(string $value): string
    {
        return strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $value));
    }

    private static function head(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $head = fread($handle, 64);
        } finally {
            fclose($handle);
        }

        return \is_string($head) ? $head : null;
    }

    private static function sniff(string $path): ?string
    {
        if (!class_exists(\finfo::class)) {
            return null;
        }

        try {
            $mime = (new \finfo(\FILEINFO_MIME_TYPE))->file($path);
        } catch (\Throwable) {
            return null;
        }

        return \is_string($mime) && $mime !== '' ? strtolower($mime) : null;
    }
}
