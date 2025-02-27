<?php

declare(strict_types=1);

namespace flight;

/**
 * Cache - Light, simple and standalone PHP in-file caching class
 * This class was heavily inspired by Simple-PHP-Cache. Huge thanks to Christian Metz
 *
 * @license MIT
 * @author Wruczek https://github.com/Wruczek
 * @author n0nag0n <n0nag0n@sky-9.com>
 */
class Cache
{
    /**
     * Path to the cache directory
     *
     * @var string
     */
    protected $cacheDir;

    /**
     * Cache file name
     *
     * @var string
     */
    protected $cacheFilename;

    /**
     * Cache file name, hashed with sha1. Used as an actual file name
     *
     * @var string
     */
    protected $cacheFilenameHashed;

    /**
     * Cache file extension
     *
     * @var string
     */
    protected $cacheFileExtension;

    /**
     * Holds current cache
     *
     * @var array<string,mixed>
     */
    protected $cacheArray;

    /**
     * If true, cache expire after one second
     *
     * @var bool
     */
    protected $devMode = false;

    /**
     * Cache constructor.
     *
     * @param string $cacheDirPath cache directory. Must end with "/"
     * @param string $cacheFileName cache file name
     * @param string $cacheFileExtension cache file extension. Must end with .php
     *
     * @throws \Exception if there is a problem loading the cache
     */
    public function __construct(string $cacheDirPath = "cache/", string $cacheFileName = "defaultcache", string $cacheFileExtension = ".cache.php")
    {
        $this->setCacheFilename($cacheFileName);
        $this->setCacheDir($cacheDirPath);
        $this->setCacheFileExtension($cacheFileExtension);

        $this->reloadFromDisc();
    }

    /**
     * Loads cache
     *
     * @throws \Exception if there is a problem loading the cache
     *
     * @return array<string,mixed> array filled with data
     */
    protected function loadCacheFile()
    {
        $filepath = $this->getCacheFilePath();

        $fp = fopen($filepath, "r+");

        if (flock($fp, LOCK_SH)) {
            $file = fread($fp, filesize($filepath));
            flock($fp, LOCK_UN);
        } else {
            throw new \Exception("Could not get the lock for $filepath"); // @codeCoverageIgnore
        }
        fclose($fp);

        // Remove the first line which prevents direct access to the file
        $file = $this->stripFirstLine($file);
        $data = @unserialize($file);

        if ($data === false) {
            unlink($filepath);
            throw new \Exception("Cannot unserialize cache file, cache file deleted. ({$this->getCacheFilename()})");
        }

        if (isset($data["hash-sum"]) === false) {
            unlink($filepath);
            throw new \Exception("No hash found in cache file, cache file deleted");
        }

        $hash = $data["hash-sum"];
        unset($data["hash-sum"]);

        if ($hash !== $this->getStringHash(serialize($data))) {
            unlink($filepath);
            throw new \Exception("Cache data miss-hashed, cache file deleted");
        }

        return $data;
    }

    /**
     * Saves current cacheArray into the cache file
     *
     * @throws \Exception if the file cannot be saved
     *
     * @return $this
     */
    protected function saveCacheFile()
{
		if (file_exists($this->getCacheDir()) === false) {
			mkdir($this->getCacheDir(), 0777, true);
		}

		$cache = $this->cacheArray;
		$cache["hash-sum"] = $this->getStringHash(serialize($cache));
		$data = serialize($cache);
		$firstLine = '<?php die("Access denied") ?>' . PHP_EOL;
		$filePath = $this->getCacheFilePath();

		$fp = fopen($filePath, 'c');
		if ($fp === false) {
			throw new \Exception("Cannot open cache file for writing"); // @codeCoverageIgnore
		}

		if (flock($fp, LOCK_EX)) {
			ftruncate($fp, 0);
			rewind($fp);
			if (fwrite($fp, $firstLine . $data) === false) {
				// @codeCoverageIgnoreStart
				flock($fp, LOCK_UN);
				fclose($fp);
				throw new \Exception("Cannot write to cache file");
				// @codeCoverageIgnoreEnd
			}
			fflush($fp);
			flock($fp, LOCK_UN);
		} else {
			// @codeCoverageIgnoreStart
			fclose($fp);
			throw new \Exception("Cannot lock cache file for writing");
			// @codeCoverageIgnoreEnd
		}

		fclose($fp);
		return $this;
	}

    /**
     * Stores $data under $key for $expiration seconds
     * If $key is already used, then current data will be overwritten
     *
     * @param string $key key associated with the current data
     * @param mixed $data data to store
     * @param float $expiration number of seconds before the $key expires
     * @param bool $permanent if true, this item will not be automatically cleared after expiring
     *
     * @throws \Exception if the file cannot be saved
     *
     * @return $this
     */
    public function store(string $key, $data, float $expiration = 60, bool $permanent = false)
    {
        if ($this->isDevMode()) {
            $expiration = 0.1;
        }

        $storeData = [
            "time" => microtime(true),
            "expire" => $expiration,
            "data" => $data,
            "permanent" => $permanent
        ];

        $this->cacheArray[$key] = $storeData;
        $this->saveCacheFile();
        return $this;
    }

    /**
     * Returns data associated with $key
     *
     * @param string $key The cache key 
     * @param bool $meta if true, array will be returned containing metadata alongside data itself
     *
     * @throws \Exception if the file cannot be saved
     *
     * @return mixed|null returns data if $key is valid and not expired, NULL otherwise
     */
    public function retrieve(string $key, bool $meta = false)
    {
        $this->eraseExpired();

        if (isset($this->cacheArray[$key]) === false) {
            return null;
        }

        $data = $this->cacheArray[$key];
        return $meta ? $data : @$data["data"];
    }

    /**
     * Calls $refreshCallback if $key does not exists or is expired.
     * Also returns latest data associated with $key.
     * This is basically a shortcut, turns this:
     * <code>
     * if($cache->isExpired(key)) {
     *     $cache->store(key, $newdata, 10);
     * }
     *
     * $data = $cache->retrieve(key);
     * </code>
     *
     * to this:
     *
     * <code>
     * $data = $cache->refreshIfExpired(key, function () {
     *    return $newdata;
     * }, 10);
     * </code>
     *
     * @param string $key The cache key
     * @param callable $refreshCallback Callback called when data needs to be refreshed. Should return data to be cached.
     * @param float $cacheTime Cache time. Defaults to 60
     * @param bool $meta If true, returns data with meta. @see retrieve
     *
     * @throws \Exception if the file cannot be saved
     *
     * @return mixed|null Data currently stored under key
     */
    public function refreshIfExpired(string $key, callable $refreshCallback, float $cacheTime = 60, bool $meta = false)
    {
        if ($this->isExpired($key) === true) {
            $this->store($key, $refreshCallback(), $cacheTime);
        }

        return $this->retrieve($key, $meta);
    }

    /**
     * Erases data associated with $key
     *
     * @param string $key The cache key
     *
     * @throws \Exception if the file cannot be saved
     *
     * @return bool true if $key was found and removed, false otherwise
     */
    public function eraseKey(string $key)
    {
        if ($this->isCached($key, false) === false) {
            return false;
        }

        unset($this->cacheArray[$key]);
        $this->saveCacheFile();
        return true;
    }

    /**
     * Erases expired keys from cache
     *
     * @throws \Exception if the file cannot be saved
     *
     * @return int number of erased entries
     */
    public function eraseExpired()
    {
        $counter = 0;

        foreach ($this->cacheArray as $key => $value) {
            if (empty($value["permanent"]) && $this->isExpired($key, false)) {
                $this->eraseKey($key);
                $counter++;
            }
        }

        if ($counter > 0) {
            $this->saveCacheFile();
        }

        return $counter;
    }

    /**
     * Clears the cache
     *
     * @throws \Exception if the file cannot be saved
	 * 
	 * @return void
     */
    public function clearCache()
    {
        $this->cacheArray = [];
        $this->saveCacheFile();
    }

    /**
     * Checks if $key has expired
     *
     * @param string $key The cache key
     * @param bool $eraseExpired if true, expired data will
     * be cleared before running this function
     *
     * @throws \Exception if the file cannot be saved
     *
     * @return bool
     */
    public function isExpired(string $key, bool $eraseExpired = true)
    {
        if ($eraseExpired === true) {
            $this->eraseExpired();
        }

        if ($this->isCached($key, false) === false) {
            return true;
        }

        $item = $this->cacheArray[$key];

        return $this->isTimestampExpired($item["time"], $item["expire"]);
    }

    /**
     * Checks if $key is cached
     *
     * @param string $key The cache key
     * @param bool $eraseExpired if true, expired data will
     * be cleared before running this function
     *
     * @throws \Exception if the file cannot be saved
     *
     * @return bool
     */
    public function isCached(string $key, bool $eraseExpired = true)
    {
        if ($eraseExpired === true) {
            $this->eraseExpired();
        }

        return isset($this->cacheArray[$key]) === true;
    }

    /**
     * Checks if the timestamp expired
     *
     * @param int $timestamp timestamp in milliseconds
     * @param float $expiration number of milliseconds after the timestamp expires
     *
     * @return bool true if the timestamp expired, false otherwise
     */
    protected function isTimestampExpired(float $timestamp, float $expiration)
    {
        $timeDiff = microtime(true) - $timestamp;
        return $timeDiff >= $expiration;
    }

    /**
     * Prints cache file using var_dump, useful for debugging
	 * 
	 * @codeCoverageIgnore
	 * 
	 * @return void
     */
    public function debugCache()
    {
        if (file_exists($this->getCacheFilePath())) {
            var_dump(unserialize($this->stripFirstLine(file_get_contents($this->getCacheFilePath()))));
        }
    }

    /**
     * Reloads cache from disc. Can be used after changing file name, extension or cache dir
     * using functions instead of constructor. (This class loads data once, when is created)
     *
     * @throws \Exception if there is a problem loading the cache
	 * 
	 * @return void
     */
    public function reloadFromDisc()
    {
        // Try to load the cache, otherwise create a empty array
        $this->cacheArray = is_readable($this->getCacheFilePath()) === true ? $this->loadCacheFile() : [];
    }

    /**
     * Returns md5 hash of the given string.
     *
     * @param string $str String to be hashed
     *
     * @throws \InvalidArgumentException if $str is not a string
     *
     * @return string MD5 hash
     */
    protected function getStringHash(string $str)
    {
        return md5($str);
    }

    // Utils

    /**
     * Strips the first line from string
     * https://stackoverflow.com/a/7740485
     *
     * @param string $str
     *
     * @return string stripped text without the first line or false on failure
     */
    protected function stripFirstLine($str)
    {
        $position = strpos($str, "\n");

        if ($position === false) {
            return $str;
        }

        return substr($str, $position + 1);
    }

    // Generic setters and getters below

    /**
     * Returns cache directory
     *
     * @return string
     */
    public function getCacheDir()
    {
        return $this->cacheDir;
    }

    /**
     * Sets new cache directory. If you want to read data from new file, consider calling reloadFromDisc.
     *
     * @param string $cacheDir new cache directory. Must end with "/"
     *
     * @return $this
     */
    public function setCacheDir(string $cacheDir)
    {
        // Add "/" to the end if its not here
        if (substr($cacheDir, -1) !== "/") {
            $cacheDir .= "/";
        }

        $this->cacheDir = $cacheDir;
        return $this;
    }

    /**
     * Returns cache file name, hashed with sha1. Used as an actual file name
     * The new value is computed when using setCacheFilename method.
     *
     * @return string
     */
    public function getCacheFilenameHashed()
    {
        return $this->cacheFilenameHashed;
    }

    /**
     * Returns cache file name
     *
     * @return string
     */
    public function getCacheFilename()
    {
        return $this->cacheFilename;
    }

    /**
     * Sets new cache file name. If you want to read data from new file, consider calling reloadFromDisc.
     *
     * @param string $cacheFilename
     *
     * @throws \InvalidArgumentException if $cacheFilename is not a string
     *
     * @return $this
     */
    public function setCacheFilename(string $cacheFilename)
    {
        $this->cacheFilename = $cacheFilename;
        $this->cacheFilenameHashed = $this->getStringHash($cacheFilename);
        return $this;
    }

    /**
     * Returns cache file extension
     *
     * @return string
     */
    public function getCacheFileExtension()
    {
        return $this->cacheFileExtension;
    }

    /**
     * Sets new cache file extension. If you want to read data from new file, consider calling reloadFromDisc.
     *
     * @param string $cacheFileExtension new cache file extension. Must end with ".php"
     *
     * @return $this
     */
    public function setCacheFileExtension(string $cacheFileExtension)
    {
        // Add ".php" to the end if its not here
        if (substr($cacheFileExtension, -4) !== ".php") {
            $cacheFileExtension .= ".php";
        }

        $this->cacheFileExtension = $cacheFileExtension;
        return $this;
    }

    /**
     * Combines directory, filename and extension into a path
     *
     * @return string
     */
    public function getCacheFilePath()
    {
        return $this->getCacheDir() . $this->getCacheFilenameHashed() . $this->getCacheFileExtension();
    }

    /**
     * Returns raw cache array
     *
     * @return array<string,mixed>
     */
    public function getCacheArray()
    {
        return $this->cacheArray;
    }

    /**
     * Returns true if dev mode is on
     * If dev mode is on, cache expire after one second
     *
     * @return bool
     */
    public function isDevMode()
    {
        return $this->devMode;
    }

    /**
     * Sets dev mode on or off
     * If dev mode is on, cache expire after one second
     *
     * @param bool $devMode
     *
     * @return $this
     */
    public function setDevMode(bool $devMode)
    {
        $this->devMode = $devMode;
        return $this;
    }
}
