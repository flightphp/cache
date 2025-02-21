<?php

declare(strict_types=1);

namespace flight\tests;

use Exception;
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

    protected function tearDown(): void
    {
        foreach (glob(self::__TEST_DIR . "*") as $item) {
            @unlink($item);
        }

        @rmdir(self::__TEST_DIR);
    }

    public function testConstructor()
    {
        $cache = new Cache("testdir/", "fileName", ".test.php");

        $this->assertSame("fileName", $cache->getCacheFilename());
        $this->assertSame("testdir/", $cache->getCacheDir());
        $this->assertSame(".test.php", $cache->getCacheFileExtension());
    }

	public function testBadFileLoad()
	{
		mkdir(self::__TEST_DIR);
		$result = file_put_contents(self::__TEST_DIR . md5('defaultcache1').".cache.php", "i\nam\nnot\nserialized");
		$this->expectException(Exception::class);
		$this->expectExceptionMessage("Cannot unserialize cache file, cache file deleted. (defaultcache1)");
		$cache = new Cache(self::__TEST_DIR, 'defaultcache1');
	}

	public function testBadNoHashSum()
	{
		mkdir(self::__TEST_DIR);
		$result = file_put_contents(self::__TEST_DIR . md5('defaultcache2').".cache.php", serialize("test"));
		$this->expectException(Exception::class);
		$this->expectExceptionMessage("No hash found in cache file, cache file deleted");
		$cache = new Cache(self::__TEST_DIR, 'defaultcache2');
	}

	public function testBadWithInvalidHashSum()
	{
		mkdir(self::__TEST_DIR);
		$result = file_put_contents(self::__TEST_DIR . md5('defaultcache2').".cache.php", serialize(['hash-sum' => 'invalid', 'data' => 'test']));
		$this->expectException(Exception::class);
		$this->expectExceptionMessage("Cache data miss-hashed, cache file deleted");
		$cache = new Cache(self::__TEST_DIR, 'defaultcache2');
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

        $this->assertSame($this->testarray, $cache->retrieve("test"));
        $this->assertFalse($cache->isExpired("test"));
    }

	public function testStoreAndReload()
	{
		$cache = new Cache(self::__TEST_DIR);

		$cache->store("test", $this->testarray);

		$cache->reloadFromDisc();

		$this->assertEquals($this->testarray, $cache->retrieve("test"));
		$this->assertFalse($cache->isExpired("test"));
	}

	public function testStoreWithExpiration()
	{
		$cache = new Cache(self::__TEST_DIR);
		$cache->setDevMode(true);
		$cache->store("test", $this->testarray);

		$this->assertSame($this->testarray, $cache->retrieve("test"));
		$this->assertFalse($cache->isExpired("test"));
		usleep(200000);
		$this->assertNull($cache->retrieve("test"));
	}

    public function testKeyCharacters()
    {
        $cache = new Cache(self::__TEST_DIR);

        foreach (["'", "\"", "test & test", "óÓłć€$123"] as $key) {
            $this->assertSame($this->testarray, $cache->store($key, $this->testarray)->retrieve($key));
        }
    }

    public function testRetrieve()
    {
        $cache = new Cache(self::__TEST_DIR);

        $cache->store("test", $this->testarray);

        $this->assertEquals($this->testarray, $cache->retrieve("test"));
        $this->assertFalse($cache->isExpired("test"));
    }

    public function testRefreshIfExpired()
    {
        $cache = new Cache(self::__TEST_DIR);

        $data = $cache->refreshIfExpired("refreshtest", function () {
            return $this->testarray;
        }, 0.1);

        $this->assertEquals($this->testarray, $data);
        $this->assertEquals($this->testarray, $cache->retrieve("refreshtest"));
        usleep(200000);
        $this->assertNull($cache->retrieve("refreshtest"));
    }

    public function testEraseExpired()
    {
        $cache = new Cache(self::__TEST_DIR);

        $cache->store("test", "test123", 0.1);
		usleep(200000);
        $this->assertSame(1, $cache->eraseExpired());
        $this->assertNull($cache->retrieve("test"));
    }

    public function testOverride()
    {
        $cache = new Cache(self::__TEST_DIR);

        $cache->store("test", "first");
        $cache->store("test", "second");
        $this->assertSame("second", $cache->retrieve("test"));
    }

    public function testClear()
    {
        $cache = new Cache(self::__TEST_DIR);

        $cache->store("test2", "test123");

        $this->assertTrue($cache->eraseKey("test2"));
        $this->assertNull($cache->retrieve("test2"));

        $cache->store("test3", "test123");
        $cache->store("test4", "test123");

        $cache->clearCache();

        $this->assertNull($cache->retrieve("test3"));
        $this->assertNull($cache->retrieve("test4"));
    }

	public function testEraseKeyWithBadKey()
	{
		$cache = new Cache(self::__TEST_DIR);
		$this->assertFalse($cache->eraseKey("test"));
	}

	public function testIsCachedEraseExpired()
	{
		$cache = new Cache(self::__TEST_DIR);

		$cache->store("test", "test123", 0.1);
		$this->assertTrue($cache->isCached("test", true));

		$cachedArray = $cache->getCacheArray();
		$this->assertArrayHasKey("test", $cachedArray);
	}
}
