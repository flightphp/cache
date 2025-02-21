<?php

declare(strict_types=1);

namespace flight\tests;

use flight\Cache;
use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase
{
    const __TEST_DIR = __DIR__ . "/../testcache/";
    protected $testarray;

    protected function setUp(): void
    {
        $data = '';
        for ($i = 0; $i < 256; $i++) {
            $data .= chr($i);
        }

        $obj = new \stdClass();
        $obj->foo = "value";

        $this->testarray = [
            "string" => "asd123",
            "int" => 123,
            "array" => [[["test"]]],
            "object" => $obj,
            "bool" => false,
            "binary" => $data
        ];
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::__TEST_DIR . "*") as $item) {
            @unlink($item);
        }

        @rmdir(self::__TEST_DIR);
    }

    public function testConstructor()
    {
        $cache = new Cache("testdir/", "fileName", ".test.php");

        self::assertSame("fileName", $cache->getCacheFilename());
        self::assertSame("testdir/", $cache->getCacheDir());
        self::assertSame(".test.php", $cache->getCacheFileExtension());
    }

    public function testPathRequirements()
    {
        $cache = new Cache(self::__TEST_DIR);

        $cache->setCacheDir("directory");
        $this->assertSame("directory/", $cache->getCacheDir()); // "/" should be added automatically

        $cache->setCacheFileExtension(".test");
        $this->assertSame(".test.php", $cache->getCacheFileExtension()); // ".php" should be added automatically
    }

    public function testStore()
    {
        $cache = new Cache(self::__TEST_DIR);

        $cache->store("test", $this->testarray);

        self::assertSame($this->testarray, $cache->retrieve("test"));
        self::assertFalse($cache->isExpired("test"));
    }

    public function testKeyCharacters()
    {
        $cache = new Cache(self::__TEST_DIR);

        foreach (["'", "\"", "test & test", "óÓłć€$123"] as $key) {
            self::assertSame($this->testarray, $cache->store($key, $this->testarray)->retrieve($key));
        }
    }

    public function testRetrieve()
    {
        $cache = new Cache(self::__TEST_DIR);

        $cache->store("test", $this->testarray);

        self::assertEquals($this->testarray, $cache->retrieve("test"));
        self::assertFalse($cache->isExpired("test"));
    }

    public function testRefreshIfExpired()
    {
        $cache = new Cache(self::__TEST_DIR);

        $data = $cache->refreshIfExpired("refreshtest", function () {
            return $this->testarray;
        }, 1);

        self::assertEquals($this->testarray, $data);
        self::assertEquals($this->testarray, $cache->retrieve("refreshtest"));
        sleep(2);
        self::assertNull($cache->retrieve("refreshtest"));
    }


    public function testEraseExpired()
    {
        $cache = new Cache(self::__TEST_DIR);

        $cache->store("test", "test123", 1);
        sleep(2);
        self::assertSame(1, $cache->eraseExpired());
        self::assertNull($cache->retrieve("test"));
    }

    public function testOverride()
    {
        $cache = new Cache(self::__TEST_DIR);

        $cache->store("test", "first");
        $cache->store("test", "second");
        self::assertSame("second", $cache->retrieve("test"));
    }

    public function testClear()
    {
        $cache = new Cache(self::__TEST_DIR);

        $cache->store("test2", "test123");

        self::assertTrue($cache->eraseKey("test2"));
        self::assertNull($cache->retrieve("test2"));

        $cache->store("test3", "test123");
        $cache->store("test4", "test123");

        $cache->clearCache();

        self::assertNull($cache->retrieve("test3"));
        self::assertNull($cache->retrieve("test4"));
    }
}
