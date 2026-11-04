<?php declare(strict_types=1);

namespace Nova\Upload\Tests\Unit;

use Nova\Upload\Service\ContentInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContentInspectorTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testRealPngIsAccepted(): void
    {
        $path = $this->file((string) base64_decode(self::PNG_1X1, true));

        self::assertTrue(ContentInspector::matches($path, 'png'));
        self::assertTrue(ContentInspector::matches($path, 'PNG'));
        self::assertFalse(ContentInspector::matches($path, 'jpg'), 'PNG mit .jpg-Endung passt nicht');
    }

    public function testSvgDisguisedAsImageIsRejected(): void
    {
        $path = $this->file('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        foreach (['jpg', 'png', 'gif', 'webp', 'heic', 'tif'] as $extension) {
            self::assertFalse(ContentInspector::matches($path, $extension), $extension);
        }
    }

    public function testFakeJpegSignatureWithHtmlIsRejected(): void
    {
        self::assertFalse(ContentInspector::matches($this->file('<html><body>x</body></html>'), 'jpg'));
        self::assertFalse(ContentInspector::matches($this->file('<!DOCTYPE html><html><script>1</script></html>'), 'txt'), 'HTML in jeder Endung');
    }

    public function testPdfNeedsSignature(): void
    {
        self::assertTrue(ContentInspector::matches($this->file("%PDF-1.7\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n"), 'pdf'));
        self::assertFalse(ContentInspector::matches($this->file(random_bytes(256)), 'pdf'), 'unbekannter Binärinhalt ist kein PDF');
        self::assertFalse(ContentInspector::matches($this->file('<html>%PDF-1.4</html>'), 'pdf'));
    }

    public function testOtherExtensionsOnlyUseForbiddenList(): void
    {
        self::assertTrue(ContentInspector::matches($this->file("solid cube\nendsolid cube\n"), 'stl'));
        self::assertTrue(ContentInspector::matches($this->file("a;b\n1;2\n"), 'csv'));
        self::assertFalse(ContentInspector::matches($this->file("<?php echo 1;\n"), 'txt'));
        self::assertFalse(ContentInspector::matches($this->file('<svg xmlns="http://www.w3.org/2000/svg"></svg>'), 'dxf'), 'SVG nur als .svg');
    }

    public function testImageSignatures(): void
    {
        self::assertTrue(ContentInspector::hasImageSignature("\xFF\xD8\xFF\xE0", 'jpeg'));
        self::assertTrue(ContentInspector::hasImageSignature('GIF89a', 'gif'));
        self::assertTrue(ContentInspector::hasImageSignature("RIFF\x00\x00\x00\x00WEBPVP8 ", 'webp'));
        self::assertTrue(ContentInspector::hasImageSignature("II*\x00", 'tiff'));
        self::assertTrue(ContentInspector::hasImageSignature("\x00\x00\x00\x18ftypheic", 'heic'));
        self::assertFalse(ContentInspector::hasImageSignature("\x00\x00\x00\x18ftypisom", 'heic'), 'MP4 ist kein HEIC');
        self::assertFalse(ContentInspector::hasImageSignature('<svg', 'png'));
    }

    #[DataProvider('safeSvgs')]
    public function testSafeSvgIsAccepted(string $svg): void
    {
        self::assertTrue(ContentInspector::isSafeSvg($svg));
        self::assertTrue(ContentInspector::matches($this->file($svg), 'svg'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeSvgs(): iterable
    {
        yield 'einfach' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#f00"/></svg>'];
        yield 'mit XML-Deklaration und BOM' => ["\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\"><circle r=\"4\"/></svg>"];
        yield 'Verlauf, use, Stil' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><defs><linearGradient id="g"><stop offset="0" stop-color="red"/></linearGradient><path id="p" d="M0 0h1"/></defs><style>.a{fill:url(#g)}</style><use xlink:href="#p" class="a" style="fill:url(\'#g\')"/><rect fill="url(#g)" width="1" height="1"/></svg>'];
        yield 'Grafikprogramm-Metadaten' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd" xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" inkscape:version="1.3"><!-- Kommentar --><sodipodi:namedview pagecolor="#fff"/><metadata><rdf:RDF/></metadata><g inkscape:label="Ebene 1"><text x="1" y="1">Hallo &amp; Tschüss</text></g></svg>'];
        yield 'eingebettetes PNG' => ['<svg xmlns="http://www.w3.org/2000/svg"><image width="1" height="1" href="data:image/png;base64,' . self::PNG_1X1 . '"/></svg>'];
    }

    #[DataProvider('dangerousSvgs')]
    public function testDangerousSvgIsRejected(string $svg): void
    {
        self::assertFalse(ContentInspector::isSafeSvg($svg));
        self::assertFalse(ContentInspector::matches($this->file($svg), 'svg'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousSvgs(): iterable
    {
        $open = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">';

        yield 'script' => [$open . '<script>alert(1)</script></svg>'];
        yield 'SCRIPT groß' => [$open . '<SCRIPT>alert(1)</SCRIPT></svg>'];
        yield 'onload' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'];
        yield 'onload mit Zeichenreferenz im Namen nicht möglich, aber im Wert' => [$open . '<rect onclick="&#97;lert(1)"/></svg>'];
        yield 'href mit Zeichenreferenz' => [$open . '<a href="j&#x61;vascript:alert(1)"><rect/></a></svg>'];
        yield 'xlink:href mit Dezimal-Referenz' => [$open . '<use xlink:href="&#106;avascript:alert(1)"/></svg>'];
        yield 'DOCTYPE mit Entity' => ['<!DOCTYPE svg [<!ENTITY js "javascript:alert(1)">]>' . $open . '<use href="&js;"/></svg>'];
        yield 'externe Entity' => ['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $open . '<text>&x;</text></svg>'];
        yield 'DOCTYPE allein' => ['<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . $open . '</svg>'];
        yield 'foreignObject' => [$open . '<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject></svg>'];
        yield 'XHTML-Skript im SVG' => [$open . '<html:script xmlns:html="http://www.w3.org/1999/xhtml">alert(1)</html:script></svg>'];
        yield 'set-Animation' => [$open . '<a><set attributeName="href" to="javascript:alert(1)"/><rect/></a></svg>'];
        yield 'animate mit Entities' => [$open . '<animate attributeName="href" values="&#106;avascript:alert(1)"/></svg>'];
        yield 'externes Bild' => [$open . '<image href="https://example.com/x.png"/></svg>'];
        yield 'externes use' => [$open . '<use href="other.svg#icon"/></svg>'];
        yield 'protokollrelativ' => [$open . '<image xlink:href="//example.com/x.png"/></svg>'];
        yield 'SVG in data-URL' => [$open . '<image href="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4="/></svg>'];
        yield 'data:text/html' => [$open . '<rect fill="data:text/html,x"/></svg>'];
        yield 'CSS url extern' => [$open . '<style>rect{fill:url(https://example.com/a.svg#x)}</style></svg>'];
        yield 'CSS @import' => [$open . '<style>@import "https://example.com/a.css";</style></svg>'];
        yield 'CSS-Escape' => [$open . '<style>rect{background:u\72l(https://example.com)}</style></svg>'];
        yield 'style-Attribut extern' => [$open . '<rect style="fill:url(http://example.com/x)"/></svg>'];
        yield 'xml-stylesheet' => ['<?xml-stylesheet href="https://example.com/a.xsl" type="text/xsl"?>' . $open . '</svg>'];
        yield 'iframe' => [$open . '<iframe src="https://example.com"/></svg>'];
        yield 'kein SVG' => ['<html><body>hi</body></html>'];
        yield 'kaputtes XML' => [$open . '<rect></svg>'];
        yield 'UTF-16' => [mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><svg xmlns="http://www.w3.org/2000/svg"/>', 'UTF-16LE', 'UTF-8')];
        yield 'leer' => [''];
        yield 'javascript mit Tab' => [$open . "<a href=\"java\tscript:alert(1)\"><rect/></a></svg>"];
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'nova-upload-test');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }
}
