<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\HTAccessRules;
use MagicConvert\OutputFormat;
use ReflectionClass;
use ReflectionMethod;

class HTAccessAvifRulesTest extends TestCase
{
    private function build(OutputFormat $fmt): string
    {
        $m = new ReflectionMethod(HTAccessRules::class, 'redirectToExistingRulesForFormat');
        $m->setAccessible(true);
        return $m->invoke(null, $fmt);
    }

    private function setStatics(string $extension, bool $docRoot, bool $addVary): void
    {
        $ref = new ReflectionClass(HTAccessRules::class);
        $set = function (string $name, $value) use ($ref) {
            $p = $ref->getProperty($name);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        };

        $set('mingled', true);
        $set('htaccessDir', 'uploads');
        $set('useDocRootForStructuringCacheDir', $docRoot);
        $set('docRootString', '%{DOCUMENT_ROOT}');
        $set('fileExt', 'jpe?g|png');
        $set('fileExtIncludingDot', '\.jpe?g|\.png');
        $set('appendWebP', $extension !== 'set');
        $set('setAddVaryEnvInRedirect', $addVary);
        $set('config', [
            'destination-extension' => $extension,
            'destination-folder' => 'mingled',
        ]);
    }

    public function testWebpMingledDocRootAppendIsByteIdenticalToFixture(): void
    {
        $this->setStatics('append', true, true);
        $expected =
            "  # Redirect to existing converted image in same dir (if browser supports webp)\n" .
            "  RewriteCond %{HTTP_ACCEPT} image/webp\n" .
            "  RewriteCond %{REQUEST_FILENAME}.webp -f\n" .
            "  RewriteRule ^/?(.*)\.(jpe?g|png)$ \$1.\$2.webp [NC,T=image/webp,E=EXISTING:1,E=ADDVARY:1,L]\n\n";

        $this->assertSame($expected, $this->build(OutputFormat::webp()));
    }

    public function testWebpMingledDocRootSetIsByteIdenticalToFixture(): void
    {
        $this->setStatics('set', true, true);
        $expected =
            "  # Redirect to existing converted image in same dir (if browser supports webp)\n" .
            "  RewriteCond %{HTTP_ACCEPT} image/webp\n" .
            "  RewriteCond %{REQUEST_URI} (?i)(.*)(\.jpe?g|\.png)$\n" .
            "  RewriteCond %{DOCUMENT_ROOT}%1\.webp -f\n" .
            "  RewriteRule (?i)(.*)(\.jpe?g|\.png)$ %1\.webp [T=image/webp,E=EXISTING:1,E=ADDVARY:1,L]\n\n";

        $this->assertSame($expected, $this->build(OutputFormat::webp()));
    }

    public function testWebpMingledNonDocRootIsByteIdenticalToFixture(): void
    {
        $this->setStatics('append', false, false);
        $expected =
            "  # Redirect to existing converted image in same dir (if browser supports webp)\n" .
            "  RewriteCond %{HTTP_ACCEPT} image/webp\n" .
            "  RewriteCond %{REQUEST_FILENAME} (?i)(.*)(\.jpe?g|\.png)$\n" .
            "  RewriteCond %1%2\.webp -f\n" .
            "  RewriteRule (?i)(.*)(\.jpe?g|\.png)$ %1%2\.webp [T=image/webp,E=EXISTING:1,L]\n\n";

        $this->assertSame($expected, $this->build(OutputFormat::webp()));
    }

    public function testWebpNonDocRootSetModeDropsTheExtensionGroup(): void
    {
        $this->setStatics('set', false, false);
        $expected =
            "  # Redirect to existing converted image in same dir (if browser supports webp)\n" .
            "  RewriteCond %{HTTP_ACCEPT} image/webp\n" .
            "  RewriteCond %{REQUEST_FILENAME} (?i)(.*)(\.jpe?g|\.png)$\n" .
            "  RewriteCond %1\.webp -f\n" .
            "  RewriteRule (?i)(.*)(\.jpe?g|\.png)$ %1\.webp [T=image/webp,E=EXISTING:1,L]\n\n";

        $this->assertSame($expected, $this->build(OutputFormat::webp()));
    }

    public function testAvifMingledDocRootAppendMirrorsWebpShape(): void
    {
        $this->setStatics('append', true, true);
        $expected =
            "  # Redirect to existing converted image in same dir (if browser supports avif)\n" .
            "  RewriteCond %{HTTP_ACCEPT} image/avif\n" .
            "  RewriteCond %{REQUEST_FILENAME}.avif -f\n" .
            "  RewriteRule ^/?(.*)\.(jpe?g|png)$ \$1.\$2.avif [NC,T=image/avif,E=EXISTING:1,E=ADDVARY:1,L]\n\n";

        $this->assertSame($expected, $this->build(OutputFormat::byId('avif')));
    }

    public function testAvifMingledNonDocRootMirrorsWebpShape(): void
    {
        $this->setStatics('append', false, false);
        $expected =
            "  # Redirect to existing converted image in same dir (if browser supports avif)\n" .
            "  RewriteCond %{HTTP_ACCEPT} image/avif\n" .
            "  RewriteCond %{REQUEST_FILENAME} (?i)(.*)(\.jpe?g|\.png)$\n" .
            "  RewriteCond %1%2\.avif -f\n" .
            "  RewriteRule (?i)(.*)(\.jpe?g|\.png)$ %1%2\.avif [T=image/avif,E=EXISTING:1,L]\n\n";

        $this->assertSame($expected, $this->build(OutputFormat::byId('avif')));
    }

    public function testAvifRuleAlwaysGuardedByFileExistsCondition(): void
    {
        foreach (['append', 'set'] as $ext) {
            foreach ([true, false] as $docRoot) {
                $this->setStatics($ext, $docRoot, false);
                $rules = $this->build(OutputFormat::byId('avif'));
                $this->assertStringContainsString(' -f', $rules, "ext=$ext docRoot=" . var_export($docRoot, true));
                $this->assertStringContainsString('image/avif', $rules);
                $this->assertStringNotContainsString('webp-on-demand', $rules);
                $this->assertStringNotContainsString('image/webp', $rules);
            }
        }
    }

    public function testAvifUsesTMimeFlagAndHonoursVaryGate(): void
    {
        $this->setStatics('append', true, false);
        $rulesNoVary = $this->build(OutputFormat::byId('avif'));
        $this->assertStringContainsString('T=image/avif', $rulesNoVary);
        $this->assertStringNotContainsString('E=ADDVARY:1', $rulesNoVary);

        $this->setStatics('append', true, true);
        $rulesVary = $this->build(OutputFormat::byId('avif'));
        $this->assertStringContainsString('E=ADDVARY:1', $rulesVary);
    }
}
