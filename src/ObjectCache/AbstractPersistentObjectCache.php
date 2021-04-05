<?php

declare(strict_types=1);

/*
 * This file is part of Ymir WordPress plugin.
 *
 * (c) Carl Alexander <support@ymirapp.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ymir\Plugin\ObjectCache;

use Ymir\Plugin\Support\Collection;

/**
 * A persistent object cache stores data outside the PHP runtime.
 */
abstract class AbstractPersistentObjectCache implements PersistentObjectCacheInterface, PreloadedObjectCacheInterface
{
    /**
     * Save a cache item only if it doesn't exist.
     *
     * @var int
     */
    protected const MODE_ADD = 1;

    /**
     * Save a cache item only if it exists.
     *
     * @var int
     */
    protected const MODE_REPLACE = 2;

    /**
     * The current blog ID when multisite is active.
     *
     * @var int
     */
    private $blogId;

    /**
     * In-memory cache.
     *
     * @var array
     */
    private $cache;

    /**
     * List of global groups.
     *
     * @var array
     */
    private $globalGroups = [];

    /**
     * Flag whether this is a multisite installation or not.
     *
     * @var bool
     */
    private $isMultisite;

    /**
     * List of non-persistent groups.
     *
     * @var array
     */
    private $nonPersistentGroups = [];

    /**
     * Prefix used for all cache keys.
     *
     * @var string
     */
    private $prefix;

    /**
     * All the keys requested during the current script execution.
     *
     * @var array
     */
    private $requestedKeys;

    /**
     * Constructor.
     */
    public function __construct(bool $isMultisite, string $prefix = '')
    {
        $this->cache = [];
        $this->isMultisite = $isMultisite;
        $this->prefix = trim($prefix);
        $this->requestedKeys = [];

        if (!empty($this->prefix)) {
            $this->prefix = $this->sanitizeCacheKeyPart($this->prefix);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function add(string $group, string $key, $value, int $expire = 0): bool
    {
        if (function_exists('wp_suspend_cache_addition') && wp_suspend_cache_addition()) {
            return false;
        }

        return !$this->hasInMemory($this->generateCacheKey($group, $key)) ? $this->store($group, $key, $value, $expire, self::MODE_ADD) : false;
    }

    /**
     * {@inheritdoc}
     */
    public function addGlobalGroups(array $groups)
    {
        $this->globalGroups = array_unique(array_merge($this->globalGroups, $groups));
    }

    /**
     * {@inheritdoc}
     */
    public function addNonPersistentGroups(array $groups)
    {
        $this->nonPersistentGroups = array_unique(array_merge($this->nonPersistentGroups, $groups));
    }

    /**
     * {@inheritdoc}
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function decrement(string $group, string $key, int $offset = 1)
    {
        $cacheKey = $this->generateCacheKey($group, $key);
        $value = $this->getFromMemory($cacheKey);

        if (!is_int($value) && !$this->isNonPersistentGroup($group)) {
            $value = $this->getFromPersistentCache($cacheKey);
        }

        if (!is_int($value)) {
            return false;
        }

        $value -= $offset;
        $value = max(0, $value);

        return $this->store($group, $key, $value) ? $value : false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $group, string $key): bool
    {
        $cacheKey = $this->generateCacheKey($group, $key);

        if ($this->isNonPersistentGroup($group) && !$this->hasInMemory($cacheKey)) {
            return false;
        }

        unset($this->cache[$cacheKey], $this->requestedKeys[$cacheKey]);

        $result = true;

        if (!$this->isNonPersistentGroup($group)) {
            try {
                $result = $this->deleteFromPersistentCache($cacheKey);
            } catch (\Exception $exception) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): bool
    {
        $this->cache = [];
        $this->requestedKeys = [];

        try {
            return $this->flushPersistentCache();
        } catch (\Exception $exception) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $group, string $key, bool $force = false, &$found = null)
    {
        $cacheKey = $this->generateCacheKey($group, $key);

        if ($this->isNonPersistentGroup($group) && !$this->hasInMemory($cacheKey)) {
            $found = false;

            return false;
        } elseif ((!$force || $this->isNonPersistentGroup($group)) && $this->hasInMemory($cacheKey)) {
            $found = true;

            return $this->getFromMemory($cacheKey);
        }

        try {
            $value = $this->getFromPersistentCache($cacheKey);
        } catch (\Exception $exception) {
            $value = false;
        }

        $found = false !== $value;

        if (false !== $value) {
            $this->requestedKeys[$cacheKey] = true;
            $this->storeInMemory($cacheKey, $value);
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(string $group, array $keys, bool $force = false): array
    {
        $keys = (new Collection($keys))->map(function ($key) {
            return (string) $key;
        });

        $cacheKeys = $keys->mapWithKeys(function (string $key) use ($group) {
            return [$key => $this->generateCacheKey($group, $key)];
        });
        $cacheKeys->each(function (string $cacheKey) {
            $this->requestedKeys[$cacheKey] = true;
        });

        $values = $keys->mapWithKeys(function (string $key) use ($cacheKeys, $force, $group) {
            $cacheKey = $cacheKeys[$key] ?? '';
            $value = false;

            if ((!$force || $this->isNonPersistentGroup($group)) && $this->hasInMemory($cacheKey)) {
                $value = $this->getFromMemory($cacheKey);
            }

            return [$key => $value];
        });

        $keysWithMissingValues = $keys->diff($values->filter(function ($value) {
            return false !== $value;
        })->keys());

        if ($keysWithMissingValues->isEmpty() || $this->isNonPersistentGroup($group)) {
            return $values->all();
        }

        $valuesFromPersistentCache = new Collection($this->getFromPersistentCache($keysWithMissingValues->map(function (string $key) use ($cacheKeys) {
            return $cacheKeys[$key] ?? '';
        })->filter()->all()));

        $valuesFromPersistentCache->each(function ($value, string $key) {
            $this->storeInMemory($key, $value);
        });

        $keysWithMissingValues->each(function (string $key) use ($cacheKeys, $values, $valuesFromPersistentCache) {
            $cacheKey = $cacheKeys[$key] ?? '';

            if (empty($cacheKey)) {
                return;
            }

            $values[$key] = $valuesFromPersistentCache[$cacheKey] ?? false;
        });

        $order = $keys->flip()->all();
        $values = $values->all();

        uksort($values, function ($a, $b) use ($order) {
            return $order[$a] - $order[$b];
        });

        return $values;
    }

    /**
     * {@inheritdoc}
     */
    public function increment(string $group, string $key, int $offset = 1)
    {
        $cacheKey = $this->generateCacheKey($group, $key);
        $value = $this->getFromMemory($cacheKey);

        if (!is_int($value) && !$this->isNonPersistentGroup($group)) {
            $value = $this->getFromPersistentCache($cacheKey);
        }

        if (!is_int($value)) {
            return false;
        }

        $value += $offset;
        $value = max(0, $value);

        return $this->store($group, $key, $value) ? $value : false;
    }

    /**
     * {@inheritdoc}
     */
    public function load()
    {
        if (!isset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']) || !in_array(strtoupper($_SERVER['REQUEST_METHOD']), ['GET', 'HEAD'])) {
            return;
        }

        $requestKey = $this->generateCacheKey('ymir-preload', md5(serialize([
            'method' => $_SERVER['REQUEST_METHOD'],
            'host' => $_SERVER['HTTP_HOST'],
            'path' => urldecode($_SERVER['REQUEST_URI']),
            'query' => urldecode($_SERVER['QUERY_STRING'] ?? ''),
        ])));

        $preloadedValues = new Collection($this->getValuesFromPersistentCache((new Collection($this->getValuesFromPersistentCache($requestKey)))->keys()->all()));

        $this->cache = $preloadedValues->all();
        $this->requestedKeys = $preloadedValues->keys()->mapWithKeys(function (string $key) {
            return [$key => true];
        })->all();

        register_shutdown_function(function () use ($requestKey) {
            $this->storeValueInPersistentCache($requestKey, $this->requestedKeys);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function replace(string $group, string $key, $value, int $expire = 0): bool
    {
        return !$this->isNonPersistentGroup($group) || $this->hasInMemory($this->generateCacheKey($group, $key)) ? $this->store($group, $key, $value, $expire, self::MODE_REPLACE) : false;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $group, string $key, $value, int $expire = 0): bool
    {
        return $this->store($group, $key, $value, $expire);
    }

    /**
     * {@inheritdoc}
     */
    public function switchToBlog(int $blogId)
    {
        $this->blogId = $this->isMultisite ? $blogId : null;
    }

    /**
     * Delete the value stored in the persistent object cache for the given key.
     */
    abstract protected function deleteValueFromPersistentCache(string $key): bool;

    /**
     * Remove all values stored in the persistent object cache.
     */
    abstract protected function flushPersistentCache(): bool;

    /**
     * Get the values stored in the persistent object cache for the given keys.
     */
    abstract protected function getValuesFromPersistentCache($keys);

    /**
     * Store the given key-value pair in the persistent object cache.
     */
    abstract protected function storeValueInPersistentCache(string $key, $value, int $expire = 0, int $mode = 0): bool;

    /**
     * Delete the "alloptions" values stored in the persistent object cache.
     *
     * This option requires special handling because it can cause race conditions on high traffic sites.
     *
     * @see https://core.trac.wordpress.org/ticket/31245
     */
    private function deleteAllOptionsValueFromPersistentCache(): bool
    {
        $keys = $this->getAllOptionsKeys();

        foreach ($keys as $key) {
            $this->delete('alloptions_values', (string) $key);
        }

        return $this->delete('options', 'alloptions_keys');
    }

    /**
     * Delete the value stored in the persistent object cache for the given key.
     */
    private function deleteFromPersistentCache(string $key): bool
    {
        return $this->isAllOptionsCacheKey($key) ? $this->deleteAllOptionsValueFromPersistentCache() : $this->deleteValueFromPersistentCache($key);
    }

    /**
     * Generate a cache key from the given group and key.
     */
    private function generateCacheKey(string $group, string $key): string
    {
        $cacheKey = sprintf('%s:%s', $this->sanitizeCacheKeyPart($group), $this->sanitizeCacheKeyPart($key));
        $prefix = !empty($this->prefix) ? $this->prefix.':' : '';

        if ($this->isMultisite && null !== $this->blogId && !$this->isGlobalGroup($group)) {
            $prefix .= $this->blogId.':';
        }

        return $prefix.$cacheKey;
    }

    /**
     * Get the cache key for the `alloptions` cache value.
     */
    private function getAllOptionsCacheKey(): string
    {
        return $this->generateCacheKey('options', 'alloptions');
    }

    /**
     * Get all the array keys in the "alloptions" array stored in the object cache.
     */
    private function getAllOptionsKeys(): array
    {
        $allOptionsKeys = $this->get('options', 'alloptions_keys');

        return !empty($allOptionsKeys) ? (new Collection($allOptionsKeys))->keys()->all() : [];
    }

    /**
     * Get the "alloptions" value stored in the persistent object cache.
     *
     * This option requires special handling because it can cause race conditions on high traffic sites.
     *
     * @see https://core.trac.wordpress.org/ticket/31245
     */
    private function getAllOptionsValueFromPersistentCache()
    {
        return $this->getMultiple('alloptions_values', $this->getAllOptionsKeys());
    }

    /**
     * Get the data stored in the in-memory object cache for the given key.
     */
    private function getFromMemory(string $key)
    {
        if (!isset($this->cache[$key])) {
            return null;
        }

        return is_object($this->cache[$key]) ? clone $this->cache[$key] : $this->cache[$key];
    }

    /**
     * Get the values stored in the persistent object cache for the given keys.
     */
    private function getFromPersistentCache($keys)
    {
        return is_string($keys) && $this->isAllOptionsCacheKey($keys) ? $this->getAllOptionsValueFromPersistentCache() : $this->getValuesFromPersistentCache($keys);
    }

    /**
     * Checks if the given key has data stored in the in-memory object cache.
     */
    private function hasInMemory(string $key): bool
    {
        return isset($this->cache[$key]);
    }

    /**
     * Checks if the given cache key is for the `alloptions` cache value.
     */
    private function isAllOptionsCacheKey(string $key): bool
    {
        return $this->getAllOptionsCacheKey() === $key;
    }

    /**
     * Checks if the given group is a global group.
     */
    private function isGlobalGroup(string $group): bool
    {
        return in_array($group, $this->globalGroups);
    }

    /**
     * Checks if the given group is a non-persistent group.
     */
    private function isNonPersistentGroup(string $group): bool
    {
        return in_array($group, $this->nonPersistentGroups);
    }

    /**
     * Sanitize part of the cache key.
     */
    private function sanitizeCacheKeyPart(string $part): string
    {
        return preg_replace('/[: ]/', '-', strtolower($part));
    }

    /**
     * Store the given key-value pair.
     */
    private function store(string $group, string $key, $value, int $expire = 0, int $mode = 0): bool
    {
        $cacheKey = $this->generateCacheKey($group, $key);
        $result = true;

        if (!$this->isNonPersistentGroup($group)) {
            try {
                $result = $this->storeInPersistentCache($cacheKey, $value, $expire, $mode);
            } catch (\Exception $exception) {
                $result = false;
            }
        }

        if ($result) {
            $this->storeInMemory($cacheKey, $value);
        }

        return $result;
    }

    /**
     * Store the "alloptions" value in the persistent object cache.
     *
     * This option requires special handling because it can cause race conditions on high traffic sites.
     *
     * @see https://core.trac.wordpress.org/ticket/31245
     */
    private function storeAllOptionsInPersistentCache($options, int $expire = 0): bool
    {
        $keys = (new Collection($this->getAllOptionsKeys()))->mapWithKeys(function (string $key) {
            return [$key => true];
        })->all();
        $options = new Collection($options);
        $newOptions = $storedOptions = new Collection($this->getAllOptionsValueFromPersistentCache());

        $options->filter(function ($value, $key) use ($storedOptions) {
            return !isset($storedOptions[$key]) || $storedOptions[$key] !== $value;
        })->each(function ($value, $key) use (&$keys) {
            if (!isset($keys[$key])) {
                $keys[$key] = true;
            }
        })->each(function ($value, $key) use ($expire, $newOptions) {
            if (!$this->set('alloptions_values', (string) $key, $value, $expire)) {
                throw new \RuntimeException('Unable to set alloptions value');
            }

            $newOptions[$key] = $value;
        });

        $storedOptions->keys()->filter(function ($key) use ($options) {
            return !isset($options[$key]);
        })->each(function ($key) use (&$keys) {
            if (isset($keys[$key])) {
                unset($keys[$key]);
            }
        })->each(function ($key) use ($newOptions) {
            if (!$this->delete('alloptions_values', (string) $key)) {
                throw new \RuntimeException('Unable to delete alloptions value');
            }

            unset($newOptions[$key]);
        });

        if (!$this->set('options', 'alloptions_keys', $keys)) {
            throw new \RuntimeException('Unable to save alloptions keys');
        }

        $this->storeInMemory($this->getAllOptionsCacheKey(), $newOptions->all());

        return true;
    }

    /**
     * Store the given data in the in-memory object cache.
     */
    private function storeInMemory(string $key, $value)
    {
        $this->cache[$key] = is_object($value) ? clone $value : $value;
    }

    /**
     * Store the given key-value pair in the persistent object cache.
     */
    private function storeInPersistentCache(string $key, $value, int $expire = 0, int $mode = 0): bool
    {
        return $this->isAllOptionsCacheKey($key) ? $this->storeAllOptionsInPersistentCache($value, $expire) : $this->storeValueInPersistentCache($key, $value, $expire, $mode);
    }
}