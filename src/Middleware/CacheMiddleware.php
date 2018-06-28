<?php

namespace SilverStripe\GraphQL\Middleware;


use GraphQL\Executor\ExecutionResult;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use SilverStripe\GraphQL\Manager;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Enables graphql responses to be cached.
 * Internally uses QueryRecorderExtension to determine which records are queried in order to generate given responses.
 */
class CacheMiddleware implements QueryMiddleware
{
    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @param string $query
     * @param array $params
     * @param callable $next
     * @return array|ExecutionResult
     * @throws InvalidArgumentException
     */
    public function process($query, $params, callable $next)
    {
        $key = $this->generateCacheKey($query, $params);

        // Get successful cache response
        $response = $this->getCachedResponse($key);
        if ($response) {
            return $response;
        }

        // Closure begins / ends recording of classes queried by DataQuery.
        // ClassSpyExtension is added to DataQuery via yml
        $spy = QueryRecorderExtension::singleton();
        list ($classesUsed, $response) = $spy->recordClasses(function () use ($query, $params, $next) {
            return $next($query, $params);
        });

        // Save freshly generated response
        $this->storeCache($key, $response, $classesUsed);
        return $response;
    }

    /**
     * @return CacheInterface
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * @param CacheInterface $cache
     * @return $this
     */
    public function setCache($cache)
    {
        $this->cache = $cache;
        return $this;
    }

    /**
     * Generate cache key
     *
     * @param string $query
     * @param array $params
     * @return string
     */
    protected function generateCacheKey($query, $params): string
    {
        return md5(var_export(
            [
                'query' => $query,
                'params' => $params
            ],
            true
        ));
    }

    /**
     * Get and validate cached response.
     *
     * Note: Cached responses can only be returned in array format, not object format.
     *
     * @param string $key
     * @return array
     * @throws InvalidArgumentException
     */
    protected function getCachedResponse($key)
    {
        // Initially check if the cached value exists at all
        $cache = $this->getCache();
        $cached = $cache->get($key);
        if (!isset($cached)) {
            return null;
        }

        // On cache success validate against cached classes
        foreach ($cached['classes'] as $class) {
            // Note: Could combine these clases into a UNION to cut down on extravagant queries
            // Todo: We can get last-deleted/modified as well for versioned records
            $lastEditedDate = DataObject::get($class)->max('LastEdited');
            if (strtotime($lastEditedDate) > strtotime($cached['date'])) {
                // class modified, fail validation of cache
                return null;
            }
        }

        // On cache success + validation
        return $cached['response'];
    }

    /**
     * Send a successful response to the cache
     *
     * @param string $key
     * @param ExecutionResult|array $response
     * @param array $classesUsed
     * @throws InvalidArgumentException
     */
    protected function storeCache($key, $response, $classesUsed)
    {
        // Ensure we store serialisable version of result
        if ($response instanceof ExecutionResult) {
            $response = Manager::singleton()->serialiseResult($response);
        }

        $this->getCache()->set($key, [
            'classes' => $classesUsed,
            'response' => $response,
            'date' => DBDatetime::now()->getValue()
        ]);
    }
}
