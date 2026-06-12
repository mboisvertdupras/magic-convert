<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\HTAccessRules;
use MagicConvert\OutputFormat;
use ReflectionClass;
use ReflectionMethod;

/**
 * Snapshot / shape tests for the AVIF .htaccess rule generation (roadmap 2.4).
 *
 * HTAccessRules::generateHTAccessRulesFromConfigObj() as a whole is WordPress-bound
 * (Paths / Config / capability tests). But the redirect-to-existing rule strings are
 * produced by a format-parameterised builder — redirectToExistingRulesForFormat($fmt) —
 * whose "mingled" branches read ONLY static properties (no Paths:: calls). That is the
 * pure/static generation seam we snapshot here.
 *
 * We exercise the builder through reflection, setting the same private statics that
 * setInternalProperties() would, restricted to the mingled + uploads configuration
 * (which deliberately skips the cache-dir block that would otherwise hit Paths::).
 *
 * The central guarantee under test:
 *   - The WebP output of the refactored builder is BYTE-FOR-BYTE the fixture captured
 *     from the pre-change rule shapes (so existing installs see no churn).
 *   - The AVIF output mirrors the WebP shape with '.avif' / 'image/avif' substituted,
 *     every branch still guarded by a '-f' file-exists condition (clean fallthrough).
 */
class HTAccessAvifRulesTest extends TestCase
{
    /** Invoke the private static builder for a given OutputFormat. */
    private function build(OutputFormat $fmt): string
    {
        $m = new ReflectionMethod(HTAccessRules::class, 'redirectToExistingRulesForFormat');
        $m->setAccessible(true);
        return $m->invoke(null, $fmt);
    }

    /**
     * Set the private statics the builder reads, for a pure (Paths-free) mingled+uploads
     * configuration. $extension is 'append' or 'set'; $docRoot toggles the doc-root branch.
     */
    private function setStatics(string $extension, bool $docRoot, bool $addVary): void
    {
        $ref = new ReflectionClass(HTAccessRules::class);
        $set = function (string $name, $value) use ($ref) {
            $p = $ref->getProperty($name);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        };

        $set('mingled', true);
        $set('htaccessDir', 'uploads');        // mingled+uploads => cache-dir block skipped (Paths-free)
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

    // --- WebP byte-identity (regression guard against the refactor) ---------------

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
        // extension 'set' on a non-doc-root structure => appendWebP false => "%1\.webp" (no %2)
        $this->setStatics('set', false, false);
        $expected =
            "  # Redirect to existing converted image in same dir (if browser supports webp)\n" .
            "  RewriteCond %{HTTP_ACCEPT} image/webp\n" .
            "  RewriteCond %{REQUEST_FILENAME} (?i)(.*)(\.jpe?g|\.png)$\n" .
            "  RewriteCond %1\.webp -f\n" .
            "  RewriteRule (?i)(.*)(\.jpe?g|\.png)$ %1\.webp [T=image/webp,E=EXISTING:1,L]\n\n";

        $this->assertSame($expected, $this->build(OutputFormat::webp()));
    }

    // --- AVIF shape mirrors WebP, with .avif / image/avif -------------------------

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

    // --- Cross-cutting invariants the fallthrough policy depends on ---------------

    public function testAvifRuleAlwaysGuardedByFileExistsCondition(): void
    {
        // Every avif redirect must be preceded by a "-f" condition so a missing .avif
        // falls through cleanly to the webp rules. (No converter route for avif.)
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
        $this->setStatics('append', true, false);   // addVary OFF
        $rulesNoVary = $this->build(OutputFormat::byId('avif'));
        $this->assertStringContainsString('T=image/avif', $rulesNoVary);
        $this->assertStringNotContainsString('E=ADDVARY:1', $rulesNoVary);

        $this->setStatics('append', true, true);    // addVary ON
        $rulesVary = $this->build(OutputFormat::byId('avif'));
        $this->assertStringContainsString('E=ADDVARY:1', $rulesVary);
    }
}
