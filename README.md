# Cache [![Latest Stable Version](https://poser.pugx.org/flightphp/cache/version)](https://packagist.org/packages/flightphp/cache) [![License](https://poser.pugx.org/flightphp/cache/license)](https://packagist.org/packages/flightphp/cache)
Light, simple and standalone PHP in-file caching class

### Advantages
- Light, standalone and simple
- All code in one file - no pointless drivers.
- Secure - every generated cache file have a php header with `die`, making direct access impossible even if someone knows the path and your server is not configured properly
- Well documented and tested
- Handles concurrency correctly via flock
- Supports PHP 7.4+
- Free under a MIT license

### Requirements and Installation
You need PHP 7.4+ for usage

Require with composer:<br>
`composer require flightphp/cache`

### Usage
```php
<?php
use flight\Cache;
require_once __DIR__ . "/vendor/autoload.php";

$cache = new Cache();

$data = $cache->refreshIfExpired("simple-cache-test", function () {
    return date("H:i:s"); // return data to be cached
}, 10);

echo "Latest cache save: $data";
```
See [examples](https://github.com/flightphp/cache/tree/master/examples) for more
