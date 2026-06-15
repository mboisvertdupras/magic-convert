<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\AlterHtml\PictureTags;

class AlterHtmlPictureTagsTest extends TestCase
{
    public function testUntouched(): void
    {
        $untouched = [
            'a',
            'a<p></p>b<p></p>c',
            '',
            '<body!><p><!-- bad html here!--></p></a>',
            '<img src="3.jpg.tiff">',
            '<H1>hi</H1>',
            'blah<BR>blah<br>blah',
            "<pre>hello\nline</pre>",
        ];
        foreach ($untouched as $html) {
            $this->assertSame($html, PictureTags::replace($html));
        }
    }

    public function testBasicSrc(): void
    {
        $this->assertSame(
            '<picture><source srcset="1.png.webp" type="image/webp">' .
                '<img src="1.png" alt="hello" class="webpexpress-processed"></picture>',
            PictureTags::replace('<img src="1.png" alt="hello">')
        );
    }

    public function testSrcAndSrcSet(): void
    {
        $this->assertSame(
            '<picture><source srcset="src-and-srcset.jpg.webp 1000w" type="image/webp">' .
                '<img srcset="src-and-srcset.jpg 1000w" src="3.jpg" class="webpexpress-processed"></picture>',
            PictureTags::replace('<img srcset="src-and-srcset.jpg 1000w" src="3.jpg">')
        );
    }

    /**
     * @dataProvider donorPureCases
     */
    public function testDonorPureCases(string $html, string $expected): void
    {
        $this->assertSame($expected, PictureTags::replace($html));
    }

    public static function donorPureCases(): array
    {
        return [
            'sizes copied to source, kept on img' => [
                '<img srcset="sizes.jpg 1000w" src="3.jpg" sizes="(max-width: 492px) 100vw, 492px">',
                '<picture><source srcset="sizes.jpg.webp 1000w" sizes="(max-width: 492px) 100vw, 492px" type="image/webp">' .
                    '<img srcset="sizes.jpg 1000w" src="3.jpg" sizes="(max-width: 492px) 100vw, 492px" class="webpexpress-processed"></picture>',
            ],
            'srcset + data-lazy-srcset both kept' => [
                '<img srcset="1.jpg 480w, 2.jpg 800w" data-lazy-srcset="1.jpg 480w, 2.jpg 800w">',
                '<picture><source srcset="1.jpg.webp 480w, 2.jpg.webp 800w" data-lazy-srcset="1.jpg.webp 480w, 2.jpg.webp 800w" type="image/webp">' .
                    '<img srcset="1.jpg 480w, 2.jpg 800w" data-lazy-srcset="1.jpg 480w, 2.jpg 800w" class="webpexpress-processed"></picture>',
            ],
            'src is a data: url -> untouched' => [
                '<img src="data:image/svg+xml,...jpg">',
                '<img src="data:image/svg+xml,...jpg">',
            ],
            'data: urls dropped from srcset' => [
                '<img srcset="1.jpg 100w, data:image/gif;base64,R0lGOD.jpg 777w" src="3.jpg">',
                '<picture><source srcset="1.jpg.webp 100w" type="image/webp">' .
                    '<img srcset="1.jpg 100w, data:image/gif;base64,R0lGOD.jpg 777w" src="3.jpg" class="webpexpress-processed"></picture>',
            ],
            'existing class preserved' => [
                '<img srcset="2.jpg 1000w" class="hero">',
                '<picture><source srcset="2.jpg.webp 1000w" type="image/webp">' .
                    '<img srcset="2.jpg 1000w" class="hero webpexpress-processed"></picture>',
            ],
            'multiple srcset sizes' => [
                '<img srcset="3.jpg 1000w, 4.jpg 2000w">',
                '<picture><source srcset="3.jpg.webp 1000w, 4.jpg.webp 2000w" type="image/webp">' .
                    '<img srcset="3.jpg 1000w, 4.jpg 2000w" class="webpexpress-processed"></picture>',
            ],
            'srcset entry missing width kept' => [
                '<img srcset="5.jpg 1000w, 6.jpg">',
                '<picture><source srcset="5.jpg.webp 1000w, 6.jpg.webp" type="image/webp">' .
                    '<img srcset="5.jpg 1000w, 6.jpg" class="webpexpress-processed"></picture>',
            ],
            'invalid: only data-lazy-src -> untouched' => [
                '<img data-lazy-src="no-src-attr-in-img.jpg">',
                '<img data-lazy-src="no-src-attr-in-img.jpg">',
            ],
            'invalid: only data-lazy-srcset -> untouched' => [
                '<img data-lazy-srcset="1.jpg 480w, 2.jpg 800w">',
                '<img data-lazy-srcset="1.jpg 480w, 2.jpg 800w">',
            ],
            'uppercase SRC attribute -> lowercased' => [
                '<img SRC="uppercase1.jpg">',
                '<picture><source srcset="uppercase1.jpg.webp" type="image/webp">' .
                    '<img src="uppercase1.jpg" class="webpexpress-processed"></picture>',
            ],
            'uppercase IMG tag -> lowercased' => [
                '<IMG SRC="uppercase2.jpg">',
                '<picture><source srcset="uppercase2.jpg.webp" type="image/webp">' .
                    '<img src="uppercase2.jpg" class="webpexpress-processed"></picture>',
            ],
            'real wordpress figure markup' => [
                '<figure class="wp-block-image"><img src="12.jpg" alt="" class="wp-image-6" srcset="12.jpg 492w, 12-300x265.jpg 300w" sizes="(max-width: 492px) 100vw, 492px"></figure>',
                '<figure class="wp-block-image"><picture><source srcset="12.jpg.webp 492w, 12-300x265.jpg.webp 300w" sizes="(max-width: 492px) 100vw, 492px" type="image/webp"><img src="12.jpg" alt="" class="wp-image-6 webpexpress-processed" srcset="12.jpg 492w, 12-300x265.jpg 300w" sizes="(max-width: 492px) 100vw, 492px"></picture></figure>',
            ],
            'density descriptors 1x/2x' => [
                '<img srcset="13a.jpg 1x, 13b.jpg 2x" class="hero">',
                '<picture><source srcset="13a.jpg.webp 1x, 13b.jpg.webp 2x" type="image/webp"><img srcset="13a.jpg 1x, 13b.jpg 2x" class="hero webpexpress-processed"></picture>',
            ],
            'img already inside a picture -> left untouched' => [
                '<picture><img src="img-in-existing-picture.png"></picture>',
                '<picture><img src="img-in-existing-picture.png"></picture>',
            ],
        ];
    }

    /**
     * @dataProvider donorUntouchedCases
     */
    public function testDonorUntouchedCases(string $html): void
    {
        $this->assertSame($html, PictureTags::replace($html));
    }

    public static function donorUntouchedCases(): array
    {
        return [
            ['<img src="7.gif">'],
            ['<img src="8.jpg.webp">'],
            ['<img src="9.jpg?width=200">'],
            ['<img src="10.jpglilo">'],
            ['src="header.jpeg"'],
            ['<script src="http://example.com/script.js?preload=image.jpg">'],
            ['<img><script src="http://example.com/script.js?preload=image.jpg">'],
            [
                '<div><picture><source srcset="1.png.webp" type="image/webp"><img src="1.png" alt="hello"></picture></div>' .
                    'hello<picture><img src="2.png"></picture>',
            ],
        ];
    }

    public function testAbortWhenAnySrcsetEntryMissing(): void
    {
        $input = '<img src="1.jpg" srcset="1.jpg 600w, 2.png 40w, 3.jpg 60w" sizes="(max-width: 600px) 100vw, 600px">';
        $this->assertSame($input, PictureTagsJpgOnly::replace($input));
    }

    public function testAvifAndWebpDoubleSourceOrdering(): void
    {
        $out = PictureTagsAvifAndWebp::replace('<img src="1.jpg" alt="hi">');

        $expected =
            '<picture>' .
            '<source srcset="1.jpg.avif" type="image/avif">' .
            '<source srcset="1.jpg.webp" type="image/webp">' .
            '<img src="1.jpg" alt="hi" class="webpexpress-processed">' .
            '</picture>';
        $this->assertSame($expected, $out);

        $avifPos = strpos($out, 'type="image/avif"');
        $webpPos = strpos($out, 'type="image/webp"');
        $this->assertNotFalse($avifPos);
        $this->assertNotFalse($webpPos);
        $this->assertLessThan($webpPos, $avifPos, 'avif <source> must precede webp <source>');
    }

    public function testAvifAndWebpSrcset(): void
    {
        $this->assertSame(
            '<picture>' .
            '<source srcset="a.jpg.avif 480w, b.jpg.avif 800w" type="image/avif">' .
            '<source srcset="a.jpg.webp 480w, b.jpg.webp 800w" type="image/webp">' .
            '<img srcset="a.jpg 480w, b.jpg 800w" class="webpexpress-processed">' .
            '</picture>',
            PictureTagsAvifAndWebp::replace('<img srcset="a.jpg 480w, b.jpg 800w">')
        );
    }

    public function testAvifMissingFallsBackToWebpSourceOnly(): void
    {
        $this->assertSame(
            '<picture><source srcset="1.jpg.webp" type="image/webp">' .
                '<img src="1.jpg" class="webpexpress-processed"></picture>',
            PictureTagsAvifMissing::replace('<img src="1.jpg">')
        );
    }

    public function testBothMissingLeavesImgUntouched(): void
    {
        $input = '<img src="1.jpg" alt="x">';
        $this->assertSame($input, PictureTagsNone::replace($input));
    }

    public function testAttributePreservation(): void
    {
        $out = PictureTagsAvifAndWebp::replace(
            '<img loading="lazy" width="100" height="80" src="p.jpg" alt="A & B" title="t" data-x="y">'
        );
        foreach (['loading="lazy"', 'width="100"', 'height="80"', 'src="p.jpg"', 'alt="A & B"', 'title="t"', 'data-x="y"', 'class="webpexpress-processed"'] as $needle) {
            $this->assertStringContainsString($needle, $out, $needle);
        }
        $this->assertStringContainsString('<source srcset="p.jpg.avif" type="image/avif">', $out);
    }

    public function testMalformedHtmlResilience(): void
    {
        $cases = [
            '<img src="ok.jpg" <<< broken >>>',
            '<<img src="ok.jpg">',
            'text <img src="a.jpg" no-close',
            '<img src=unquoted.jpg>',
            '<div><img src="a.jpg"></div',
        ];
        foreach ($cases as $html) {
            $out = PictureTagsAvifAndWebp::replace($html);
            $this->assertIsString($out);
        }
    }

    public function testIdempotencyDoesNotReprocess(): void
    {
        $once = PictureTagsAvifAndWebp::replace('<img src="1.jpg">');
        $twice = PictureTagsAvifAndWebp::replace($once);
        $this->assertSame($once, $twice);
    }
}

class PictureTagsJpgOnly extends PictureTags
{
    public function replaceUrlForFormat($url, $formatId)
    {
        if ($formatId !== 'webp') {
            return null;
        }
        if (!preg_match('#jpe?g$#', $url)) {
            return null;
        }
        return $url . '.webp';
    }
}

class PictureTagsAvifAndWebp extends PictureTags
{
    public function enabledFormatsInPreferenceOrder()
    {
        return ['avif', 'webp'];
    }
    public function replaceUrlForFormat($url, $formatId)
    {
        if (!preg_match('#(png|jpe?g)$#', $url)) {
            return null;
        }
        return $url . '.' . $formatId;
    }
}

class PictureTagsAvifMissing extends PictureTags
{
    public function enabledFormatsInPreferenceOrder()
    {
        return ['avif', 'webp'];
    }
    public function replaceUrlForFormat($url, $formatId)
    {
        if ($formatId === 'avif') {
            return null;
        }
        if (!preg_match('#(png|jpe?g)$#', $url)) {
            return null;
        }
        return $url . '.webp';
    }
}

class PictureTagsNone extends PictureTags
{
    public function enabledFormatsInPreferenceOrder()
    {
        return ['avif', 'webp'];
    }
    public function replaceUrlForFormat($url, $formatId)
    {
        return null;
    }
}
