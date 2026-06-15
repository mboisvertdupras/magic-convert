<?php

namespace MagicConvert\Tests;

use MagicConvert\RestApi;
use PHPUnit\Framework\TestCase;

class RestApiPaginationTest extends TestCase
{
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
        $this->assertSame(['offset' => 1000, 'length' => 200], RestApi::paginate(1200, 3, 500));
    }

    public function testPaginateBeyondEndYieldsEmptyWindow(): void
    {
        $this->assertSame(['offset' => 1200, 'length' => 0], RestApi::paginate(1200, 4, 500));
        $this->assertSame(['offset' => 1200, 'length' => 0], RestApi::paginate(1200, 99, 500));
    }

    public function testPaginateEmptyTotal(): void
    {
        $this->assertSame(['offset' => 0, 'length' => 0], RestApi::paginate(0, 1, 500));
    }

    public function testPaginateNormalizesBadPageAndPerPage(): void
    {
        $this->assertSame(['offset' => 0, 'length' => 1], RestApi::paginate(10, 0, 0));
        $this->assertSame(['offset' => 0, 'length' => 10], RestApi::paginate(10, -5, 500));
    }

    public function testPaginateExactMultiple(): void
    {
        $this->assertSame(['offset' => 500, 'length' => 500], RestApi::paginate(1000, 2, 500));
        $this->assertSame(['offset' => 1000, 'length' => 0], RestApi::paginate(1000, 3, 500));
    }

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
        $this->assertFalse(RestApi::isValidListId('ABCDEF12'));
        $this->assertFalse(RestApi::isValidListId('zz'));
        $this->assertFalse(RestApi::isValidListId('abc'));
        $this->assertFalse(RestApi::isValidListId(str_repeat('a', 65)));
        $this->assertFalse(RestApi::isValidListId("abcd1234\0"));
        $this->assertFalse(RestApi::isValidListId(12345678));
        $this->assertFalse(RestApi::isValidListId(null));
    }

    public function testListFilePathDerivesFromValidId(): void
    {
        $id = str_repeat('a', 32);
        $this->assertSame('/tmp/bulk-lists/' . $id . '.json', RestApi::listFilePath('/tmp/bulk-lists', $id));
        $this->assertSame('/tmp/bulk-lists/' . $id . '.json', RestApi::listFilePath('/tmp/bulk-lists/', $id));
    }

    public function testListFilePathRejectsBadId(): void
    {
        $this->assertNull(RestApi::listFilePath('/tmp/bulk-lists', '../../evil'));
        $this->assertNull(RestApi::listFilePath('/tmp/bulk-lists', 'a/b'));
    }

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

    public function testFlattenListProducesRootPathPairs(): void
    {
        $groups = [
            ['groupName' => 'uploads', 'root' => '/var/www/uploads', 'files' => ['2024/a.jpg', 'b.png']],
            ['groupName' => 'themes', 'root' => '/var/www/themes', 'files' => ['x/y.jpg']],
        ];
        $flat = RestApi::flattenList($groups);
        $this->assertSame([
            ['root' => 'uploads', 'path' => '2024/a.jpg', 'formats' => ['webp']],
            ['root' => 'uploads', 'path' => 'b.png', 'formats' => ['webp']],
            ['root' => 'themes', 'path' => 'x/y.jpg', 'formats' => ['webp']],
        ], $flat);
    }

    public function testFlattenListHandlesEmptyAndMalformed(): void
    {
        $this->assertSame([], RestApi::flattenList([]));
        $this->assertSame([], RestApi::flattenList('not-an-array'));
        $this->assertSame([], RestApi::flattenList([['groupName' => 'x']]));
    }

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
