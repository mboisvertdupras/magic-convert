<?php

namespace MagicConvert\Tests;

use MagicConvert\RestApi;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the WordPress-independent, security-relevant helpers of
 * MagicConvert\RestApi.
 *
 * The WP-facing route handlers (register_rest_route / WP_REST_Response glue)
 * cannot run without WordPress and are intentionally thin. Everything with real
 * logic was pushed into pure static helpers so it CAN be tested here:
 *
 *   - paginate()          page math + over-paging behaviour
 *   - clampPerPage()      bounds + defaulting
 *   - isValidListId()     the hex-token traversal guard
 *   - listFilePath()      id -> path derivation (and rejection of bad ids)
 *   - expiredListFiles()  TTL-based cleanup selection (with injected $now)
 *   - flattenList()       grouped -> flat {root,path} transform
 *   - truthy()            REST boolean coercion (reconvert flag)
 */
class RestApiPaginationTest extends TestCase
{
    // --- paginate --------------------------------------------------------------

    public function testPaginateFirstPage(): void
    {
        $this->assertSame(['offset' => 0, 'length' => 500], RestApi::paginate(1200, 1, 500));
    }

    public function testPaginateMiddlePage(): void
    {
        $this->assertSame(['offset' => 500, 'length' => 500], RestApi::paginate(1200, 2, 500));
    }

    public function testPaginateLastPartialPage(): void
    {
        // 1200 items, page 3 of 500 => offset 1000, only 200 left.
        $this->assertSame(['offset' => 1000, 'length' => 200], RestApi::paginate(1200, 3, 500));
    }

    public function testPaginateBeyondEndYieldsEmptyWindow(): void
    {
        // Over-paging is valid: an empty slice at the boundary, not an error.
        $this->assertSame(['offset' => 1200, 'length' => 0], RestApi::paginate(1200, 4, 500));
        $this->assertSame(['offset' => 1200, 'length' => 0], RestApi::paginate(1200, 99, 500));
    }

    public function testPaginateEmptyTotal(): void
    {
        $this->assertSame(['offset' => 0, 'length' => 0], RestApi::paginate(0, 1, 500));
    }

    public function testPaginateNormalizesBadPageAndPerPage(): void
    {
        // page < 1 treated as 1; perPage < 1 treated as 1.
        $this->assertSame(['offset' => 0, 'length' => 1], RestApi::paginate(10, 0, 0));
        // page normalized to 1, perPage 500 but only 10 items exist => 10.
        $this->assertSame(['offset' => 0, 'length' => 10], RestApi::paginate(10, -5, 500));
    }

    public function testPaginateExactMultiple(): void
    {
        // 1000 items, page 2 of 500 => the exact second half.
        $this->assertSame(['offset' => 500, 'length' => 500], RestApi::paginate(1000, 2, 500));
        // page 3 is exactly at the end => empty.
        $this->assertSame(['offset' => 1000, 'length' => 0], RestApi::paginate(1000, 3, 500));
    }

    // --- clampPerPage ----------------------------------------------------------

    public function testClampPerPageDefaults(): void
    {
        $this->assertSame(500, RestApi::clampPerPage(null));
        $this->assertSame(500, RestApi::clampPerPage(''));
        $this->assertSame(500, RestApi::clampPerPage('not-a-number'));
        $this->assertSame(500, RestApi::clampPerPage(0));
        $this->assertSame(500, RestApi::clampPerPage(-10));
    }

    public function testClampPerPageWithinBounds(): void
    {
        $this->assertSame(1, RestApi::clampPerPage(1));
        $this->assertSame(250, RestApi::clampPerPage('250'));
        $this->assertSame(1000, RestApi::clampPerPage(1000));
    }

    public function testClampPerPageCeiling(): void
    {
        $this->assertSame(1000, RestApi::clampPerPage(5000));
        $this->assertSame(1000, RestApi::clampPerPage(1001));
    }

    // --- isValidListId (traversal guard) ---------------------------------------

    public function testValidListIdAcceptsHexTokens(): void
    {
        $this->assertTrue(RestApi::isValidListId(str_repeat('a', 32)));
        $this->assertTrue(RestApi::isValidListId('0123456789abcdef'));
        $this->assertTrue(RestApi::isValidListId(bin2hex(random_bytes(16))));
    }

    public function testInvalidListIdRejectsTraversalAndJunk(): void
    {
        $this->assertFalse(RestApi::isValidListId('../etc/passwd'));
        $this->assertFalse(RestApi::isValidListId('abc/def'));
        $this->assertFalse(RestApi::isValidListId('abc.def'));
        $this->assertFalse(RestApi::isValidListId('ABCDEF12'));        // uppercase not allowed
        $this->assertFalse(RestApi::isValidListId('zz'));              // non-hex + too short
        $this->assertFalse(RestApi::isValidListId('abc'));             // too short (<8)
        $this->assertFalse(RestApi::isValidListId(str_repeat('a', 65))); // too long (>64)
        $this->assertFalse(RestApi::isValidListId("abcd1234\0"));      // NUL
        $this->assertFalse(RestApi::isValidListId(12345678));          // not a string
        $this->assertFalse(RestApi::isValidListId(null));
    }

    // --- listFilePath ----------------------------------------------------------

    public function testListFilePathDerivesFromValidId(): void
    {
        $id = str_repeat('a', 32);
        $this->assertSame('/tmp/bulk-lists/' . $id . '.json', RestApi::listFilePath('/tmp/bulk-lists', $id));
        // Trailing slash on dir is normalized.
        $this->assertSame('/tmp/bulk-lists/' . $id . '.json', RestApi::listFilePath('/tmp/bulk-lists/', $id));
    }

    public function testListFilePathRejectsBadId(): void
    {
        $this->assertNull(RestApi::listFilePath('/tmp/bulk-lists', '../../evil'));
        $this->assertNull(RestApi::listFilePath('/tmp/bulk-lists', 'a/b'));
    }

    // --- expiredListFiles (TTL cleanup, injected $now) -------------------------

    public function testExpiredListFilesSelectsOnlyStaleHexJson(): void
    {
        $dir = sys_get_temp_dir() . '/mc-rest-lists-' . getmypid() . '-' . uniqid('', true);
        mkdir($dir, 0775, true);
        try {
            $fresh = $dir . '/' . str_repeat('a', 32) . '.json';
            $stale = $dir . '/' . str_repeat('b', 32) . '.json';
            $other = $dir . '/notalist.txt';
            file_put_contents($fresh, '[]');
            file_put_contents($stale, '[]');
            file_put_contents($other, 'x');

            $now = 1000000000;
            // fresh: 1h old; stale: 48h old; ttl: 24h.
            touch($fresh, $now - 3600);
            touch($stale, $now - (48 * 3600));
            touch($other, $now - (48 * 3600));

            $expired = RestApi::expiredListFiles($dir, $now, 24 * 3600);

            $this->assertContains($stale, $expired, 'the 48h-old list file should be expired');
            $this->assertNotContains($fresh, $expired, 'the 1h-old list file should be kept');
            $this->assertNotContains($other, $expired, 'non-list files must never be touched');
        } finally {
            @unlink($dir . '/' . str_repeat('a', 32) . '.json');
            @unlink($dir . '/' . str_repeat('b', 32) . '.json');
            @unlink($dir . '/notalist.txt');
            @rmdir($dir);
        }
    }

    public function testExpiredListFilesEmptyForMissingDir(): void
    {
        $this->assertSame([], RestApi::expiredListFiles('/no/such/dir/xyz', time(), 100));
    }

    // --- flattenList -----------------------------------------------------------

    public function testFlattenListProducesRootPathPairs(): void
    {
        $groups = [
            ['groupName' => 'uploads', 'root' => '/var/www/uploads', 'files' => ['2024/a.jpg', 'b.png']],
            ['groupName' => 'themes', 'root' => '/var/www/themes', 'files' => ['x/y.jpg']],
        ];
        $flat = RestApi::flattenList($groups);
        $this->assertSame([
            ['root' => 'uploads', 'path' => '2024/a.jpg'],
            ['root' => 'uploads', 'path' => 'b.png'],
            ['root' => 'themes', 'path' => 'x/y.jpg'],
        ], $flat);
    }

    public function testFlattenListHandlesEmptyAndMalformed(): void
    {
        $this->assertSame([], RestApi::flattenList([]));
        $this->assertSame([], RestApi::flattenList('not-an-array'));
        // groups without a files array are skipped.
        $this->assertSame([], RestApi::flattenList([['groupName' => 'x']]));
    }

    // --- truthy (reconvert coercion) -------------------------------------------

    public function testTruthyAcceptsCommonRepresentations(): void
    {
        $this->assertTrue(RestApi::truthy(true));
        $this->assertTrue(RestApi::truthy(1));
        $this->assertTrue(RestApi::truthy('1'));
        $this->assertTrue(RestApi::truthy('true'));
        $this->assertTrue(RestApi::truthy('TRUE'));
        $this->assertTrue(RestApi::truthy('yes'));
        $this->assertTrue(RestApi::truthy('on'));
    }

    public function testTruthyRejectsFalsey(): void
    {
        $this->assertFalse(RestApi::truthy(false));
        $this->assertFalse(RestApi::truthy(0));
        $this->assertFalse(RestApi::truthy('0'));
        $this->assertFalse(RestApi::truthy('false'));
        $this->assertFalse(RestApi::truthy('no'));
        $this->assertFalse(RestApi::truthy(''));
        $this->assertFalse(RestApi::truthy(null));
    }
}
