<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\AlterHtml\ImageUrlReplacer;

/**
 * Tests for the FORKED ImageUrlReplacer (MagicConvert\AlterHtml\ImageUrlReplacer).
 *
 * Ported from the donor library (rosell-dk/dom-util-for-webp, tests/ImageUrlReplacerTest.php, MIT),
 * adapted to the new namespace, plus a Magic Convert assertion that this mode stays WEBP-ONLY by
 * design (a plain URL swap cannot negotiate per-browser — avif belongs in picture-tag mode).
 */
class AlterHtmlImageUrlReplacerTest extends TestCase
{
    /** Donor testUntouched (pure subset): unrelated HTML is never altered (pass-through replacer). */
    public function testUntouched(): void
    {
        $untouched = [
            'a',
            'a<p></p>b<p></p>c',
            '',
            '<img src="/3.jpg">',
            '<img src="http://example.com/4.jpeg" alt="">',
            '<H1>hi</H1>',
            'blah<BR>blah<br>blah',
            "<pre>hello\nline</pre>",
        ];
        foreach ($untouched as $html) {
            $this->assertSame($html, IurPassThrough::replace($html));
        }
    }

    /**
     * Donor testBasic2 (append-webp): src / data-src / input / iframe / picture-source URL swaps.
     *
     * @dataProvider appendWebpCases
     */
    public function testAppendWebp(string $html, string $expected): void
    {
        $this->assertSame($expected, IurAppendWebP::replace($html));
    }

    public static function appendWebpCases(): array
    {
        return [
            ['<img src="http://example.com/1.jpg">', '<img src="http://example.com/1.jpg.webp">'],
            ['<img src="3.jpg"><img src="4.jpg">', '<img src="3.jpg.webp"><img src="4.jpg.webp">'],
            ['<img src="5.jpg" data-src="6.jpg">', '<img src="5.jpg.webp" data-src="6.jpg.webp">'],
            ['<img src="/5.jpg">', '<img src="/5.jpg.webp">'],
            ['<img src="/6.jpg"/>', '<img src="/6.jpg.webp"/>'],
            ['<input type="image" src="/flamingo13.jpg">', '<input type="image" src="/flamingo13.jpg.webp">'],
            ['<iframe src="/image14.jpg"></iframe>', '<iframe src="/image14.jpg.webp"></iframe>'],
            ['<picture><source src="16.jpg"><img src="17.jpg"></picture>', '<picture><source src="16.jpg.webp"><img src="17.jpg.webp"></picture>'],
            ['', ''],
        ];
    }

    /** Donor testSrcSetDetection: looksLikeSrcSet() recognizes w/x descriptors. */
    public function testSrcSetDetection(): void
    {
        $iur = new ImageUrlReplacer();
        $this->assertTrue((bool) $iur->looksLikeSrcSet('1.jpg 1000w'));
        $this->assertTrue((bool) $iur->looksLikeSrcSet('1.jpg 2x'));
        $this->assertFalse((bool) $iur->looksLikeSrcSet('2.jpg'));
    }

    /**
     * Donor testWholeEngine (pure subset): the real default ImageUrlReplacer with its webp
     * replaceUrl + srcset handling + css handling.
     *
     * @dataProvider wholeEngineCases
     */
    public function testWholeEngine(string $html, string $expected): void
    {
        $this->assertSame($expected, ImageUrlReplacer::replace($html));
    }

    public static function wholeEngineCases(): array
    {
        return [
            ['<img data-x="1.png">', '<img data-x="1.png.webp">'],
            ['<img data-x="2.jpg 1000w">', '<img data-x="2.jpg.webp 1000w">'],
            ['<img data-x="3.jpg 1000w, 4.jpg 2000w">', '<img data-x="3.jpg.webp 1000w, 4.jpg.webp 2000w">'],
            ['<img data-x="5.jpg 1000w, 6.jpg">', '<img data-x="5.jpg.webp 1000w, 6.jpg.webp">'],
            ['<img data-x="7.gif 1000w, 8.jpg">', '<img data-x="7.gif 1000w, 8.jpg.webp">'],   // gif left alone
            ['<img SRC="10.jpg">', '<img SRC="10.jpg.webp">'],
            ['<img srcset="12a.jpg 1x, 12b.jpg 2x">', '<img srcset="12a.jpg.webp 1x, 12b.jpg.webp 2x">'],
            ['<img src="http://www.example.com/11.jpg">', '<img src="http://www.example.com/11.jpg.webp">'],
            ['<img src="https://www.example.com/12.jpg">', '<img src="https://www.example.com/12.jpg.webp">'],
            // inline style background-image
            [
                '<header style="background-image: url(https://cdn.example.com/banner.jpg); ">',
                '<header style="background-image: url(https://cdn.example.com/banner.jpg.webp); ">',
            ],
        ];
    }

    /** Donor "leftUntouched": wrong ext / query string / wrong tag are never swapped. */
    public function testLeftUntouched(): void
    {
        $untouched = [
            '<img src="7.gif">',
            '<img src="8.jpg.webp">',
            '<img src="9.jpg?width=200">',
            '<img src="10.jpglilo">',
            '<script src="http://example.com/script.js?preload=image.jpg">',
        ];
        foreach ($untouched as $html) {
            $this->assertSame($html, ImageUrlReplacer::replace($html));
        }
    }

    /** Donor testCSS (pure subset): <style> background url() rewriting. */
    public function testCss(): void
    {
        $this->assertSame(
            '<style>background: url("/image.jpg.webp"); a {}</style>',
            ImageUrlReplacer::replace('<style>background: url("/image.jpg"); a {}</style>')
        );
        $this->assertSame(
            '<style>a {color:white}; b {color: black}</style>',
            ImageUrlReplacer::replace('<style>a {color:white}; b {color: black}</style>')
        );
    }

    // --- Magic Convert: webp-only-by-design assertion -----------------------------

    /**
     * URL-replacement mode is WEBP-ONLY by design. The default replaceUrl() must only ever
     * produce ".webp" URLs — never ".avif" — because a plain swap cannot negotiate per-browser.
     */
    public function testUrlModeIsWebpOnlyByDesign(): void
    {
        $iur = new ImageUrlReplacer();
        $this->assertSame('1.jpg.webp', $iur->replaceUrl('1.jpg'));
        $this->assertSame('1.png.webp', $iur->replaceUrl('1.png'));
        $this->assertNull($iur->replaceUrl('1.gif'));

        $out = ImageUrlReplacer::replace('<img src="photo.jpg">');
        $this->assertStringContainsString('.webp', $out);
        $this->assertStringNotContainsString('.avif', $out);
    }

    /** Empty input returns empty (donor contract). */
    public function testEmptyInput(): void
    {
        $this->assertSame('', ImageUrlReplacer::replace(''));
    }
}

// --- Test doubles (mirror donor helper subclasses) ------------------------------------

class IurPassThrough extends ImageUrlReplacer
{
    public function handleAttribute($attrValue)
    {
        return $attrValue;
    }
}

class IurAppendWebP extends ImageUrlReplacer
{
    public function handleAttribute($attrValue)
    {
        return $attrValue . '.webp';
    }
}
