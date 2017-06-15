<?php

namespace SwagEssentials\RedisStore;

use SwagEssentials\Common\RedisFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpCache\StoreInterface;

/**
 * Based on https://github.com/solilokiam/HttpRedisCache
 *
 * Copyright (c) 2014 Miquel Company Rodriguez
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished
 * to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 */
class RedisStore implements StoreInterface
{
    protected $redisClient;

    protected $cacheKey;
    protected $metadataKey;
    protected $lockKey;
    protected $keyCache;

    public function __construct($options)
    {
        $this->redisClient = RedisFactory::factory($options['redisConnections']);

        $this->cacheKey = 'c_cache';
        $this->lockKey = 'c_lock';
        $this->metadataKey = 'c_meta';
        $this->keyCache = new \SplObjectStorage();
    }

    /**
     * Check if there is a cached result for a given request. Return null otherwise
     *
     * @param Request $request
     * @return null|Response
     */
    public function lookup(Request $request)
    {
        $key = $this->getMetadataKey($request);

        if (!$entries = $this->getMetadata($key)) {
            return null;
        }

        // find a cached entry that matches the request.
        $match = null;
        foreach ($entries as $entry) {
            if ($this->requestsMatch(
                isset($entry[1]['vary'][0]) ? $entry[1]['vary'][0] : '',
                $request->headers->all(),
                $entry[0]
            )
            ) {
                $match = $entry;
                break;
            }
        }

        if (null === $match) {
            return null;
        }

        list($headers) = array_slice($match, 1, 1);

        $body = $this->redisClient->get($headers['x-content-digest'][0]);

        if ($body) {
            return $this->recreateResponse($headers, $body);
        }

        return null;
    }

    /**
     * Cache a given response for a given request
     *
     * @param Request $request
     * @param Response $response
     * @return string
     */
    public function write(Request $request, Response $response)
    {
        // write the response body to the entity store if this is the original response
        if (!$response->headers->has('X-Content-Digest')) {
            $digest = $this->generateContentDigestKey($response);

            if (false === $this->save($digest, $response->getContent())) {
                throw new \RuntimeException('Unable to store the entity.');
            }

            $response->headers->set('X-Content-Digest', $digest);

            if (!$response->headers->has('Transfer-Encoding')) {
                $response->headers->set('Content-Length', strlen($response->getContent()));
            }
        }

        // read existing cache entries, remove non-varying, and add this one to the list
        $entries = array();
        $vary = $response->headers->get('vary');
        $requestHeaders = $this->getRequestHeaders($request);
        $metadataKey = $this->getMetadataKey($request);

        foreach ($this->getMetadata($metadataKey) as $entry) {
            if (!isset($entry[1]['vary'][0])) {
                $entry[1]['vary'] = array('');
            }

            if ($vary != $entry[1]['vary'][0] || !$this->requestsMatch($vary, $entry[0], $requestHeaders)) {
                $entries[] = $entry;
            }
        }

        $headers = $this->getResponseHeaders($response);

        unset($headers['age']);

        array_unshift($entries, array($requestHeaders, $headers));

        if (false === $this->save($metadataKey, serialize($entries))) {
            throw new \RuntimeException('Unable to store the metadata.');
        }

        return $this->storeLookupOptimization($metadataKey, $response);
    }



    /**
     * Invalidates all cache entries that match the request.
     *
     * @param Request $request A Request instance
     */
    public function invalidate(Request $request)
    {
        $modified = false;
        $newEntries = array();

        $key = $this->getMetadataKey($request);

        foreach ($this->getMetadata($key) as $entry) {
            //We pass an empty body we only care about headers.
            $response = $this->recreateResponse($entry[1], null);

            if ($response->isFresh()) {
                $response->expire();
                $modified = true;
                $newEntries[] = array($entry[0], $this->getResponseHeaders($response));
            } else {
                $entries[] = $entry;
            }
        }

        if ($modified) {
            if (false === $this->save($key, serialize($newEntries))) {
                throw new \RuntimeException('Unable to store the metadata.');
            }
        }
    }

    /**
     * Locks the cache for a given Request.
     *
     * @param Request $request A Request instance
     *
     * @return Boolean|string true if the lock is acquired, the path to the current lock otherwise
     */
    public function lock(Request $request)
    {
        $metadataKey = $this->getMetadataKey($request);

        $result = $this->redisClient->hSetNx($this->lockKey, $metadataKey, 1);

        return $result == 1;
    }

    /**
     * Releases the lock for the given Request.
     *
     * @param Request $request A Request instance
     *
     * @return Boolean False if the lock file does not exist or cannot be unlocked, true otherwise
     */
    public function unlock(Request $request)
    {
        $metadataKey = $this->getMetadataKey($request);

        $result = $this->redisClient->hdel($this->lockKey, $metadataKey);

        return $result == 1;
    }

    /**
     * Returns whether or not a lock exists.
     *
     * @param Request $request A Request instance
     *
     * @return Boolean true if lock exists, false otherwise
     */
    public function isLocked(Request $request)
    {
        $metadataKey = $this->getMetadataKey($request);

        $result = $this->redisClient->hget($this->lockKey, $metadataKey);

        return $result == 1;
    }

    /**
     * Purges data for the given URL.
     *
     * @param string $url A URL
     *
     * @return Boolean true if the URL exists and has been purged, false otherwise
     */
    public function purge($url)
    {
        $metadataKey = $this->getMetadataKey(Request::create($url));

        $result = $this->redisClient->del($metadataKey);

        return $result == 1;
    }

    /**
     * Cleanups locks
     */
    public function cleanup()
    {
        $this->redisClient->del($this->lockKey);

        return true;
    }

    /**
     * Returns a metadata key for the given request
     *
     * @param Request $request
     * @return string
     */
    private function getMetadataKey(Request $request)
    {
        if (isset($this->keyCache[$request])) {
            return $this->keyCache[$request];
        }

        return $this->keyCache[$request] = $this->metadataKey . sha1($request->getUri());
    }

    /**
     * Return the request's headers
     *
     * @param Request $request
     * @return array
     */
    private function getRequestHeaders(Request $request)
    {
        return $request->headers->all();
    }

    /**
     * Create content hash
     *
     * @param Response $response
     * @return string
     */
    protected function generateContentDigestKey(Response $response)
    {
        return $this->cacheKey . sha1($response->getContent());
    }

    /**
     * Returns the meta information of a cached item. Will contain e.g. headers and the content hash
     *
     * @param $key
     * @return array
     */
    private function getMetadata($key)
    {
        if (false === $entries = $this->load($key)) {
            return array();
        }

        return unserialize($entries);
    }


    /**
     * Store an item to the cache
     *
     * @param $key
     * @param $data
     */
    private function save($key, $data)
    {
        $this->redisClient->set($key, $data);
    }

    /**
     * Load an cached item
     *
     * @param $key
     * @return bool|string
     */
    private function load($key)
    {
        $values = $this->redisClient->get($key);

        return $values;
    }


    /**
     * Determines whether two Request HTTP header sets are non-varying based on
     * the vary response header value provided.
     *
     * @param string $vary A Response vary header
     * @param array  $env1 A Request HTTP header array
     * @param array  $env2 A Request HTTP header array
     *
     * @return bool true if the two environments match, false otherwise
     */
    private function requestsMatch($vary, $env1, $env2)
    {
        if (empty($vary)) {
            return true;
        }

        foreach (preg_split('/[\s,]+/', $vary) as $header) {
            $key = strtr(strtolower($header), '_', '-');
            $v1 = isset($env1[$key]) ? $env1[$key] : null;
            $v2 = isset($env2[$key]) ? $env2[$key] : null;
            if ($v1 !== $v2) {
                return false;
            }
        }

        return true;
    }

    /**
     * Persists the Response HTTP headers.
     *
     * @param Response $response A Response instance
     *
     * @return array An array of HTTP headers
     */
    private function getResponseHeaders(Response $response)
    {
        $headers = $response->headers->all();
        $headers['X-Status'] = array($response->getStatusCode());

        return $headers;
    }

    /**
     * Create a response object with the desired headers and body
     *
     * @param $headers
     * @param $body
     * @return Response
     */
    private function recreateResponse($headers, $body)
    {
        $status = $headers['X-Status'][0];
        unset($headers['X-Status']);

        return new Response($body, $status, $headers);
    }

    /**
     * Clears the cache completely
     */
    public function purgeAll()
    {
        $this->redisClient->flushdb();
    }

    /**
     * Clears all cached pages with certain headers in them
     *
     * @param $name
     * @param null $value
     * @return bool
     */
    public function purgeByHeader($name, $value = null)
    {
        if ($name == 'x-shopware-cache-id') {
            return $this->purgeByShopwareId($value);
        }

        throw new \RuntimeException("RedisStore does not support purging by headers other than `x-shopware-cache-id`");
    }


    /**
     * Clear all cached pages with a certain shopwareID in them
     * @param $id
     * @return bool
     */
    private function purgeByShopwareId($id)
    {
        if (!$id) {
            return false;
        }


        $cacheInvalidateKey = 'ci' . hash('sha256', $id);

        if (!$content = json_decode($this->load($cacheInvalidateKey), true)) {
            return false;
        }

        // unlink all cache files which contain the given id
        foreach ($content as $cacheKey => $headerKey) {
            // remove cache entry
            $this->redisClient->del($cacheKey);
            // remove header information
            $this->redisClient->del($headerKey);
            // remove invalidation key
            $this->redisClient->del($cacheInvalidateKey);
        }

        return true;
    }

    /**
     * Get the affected shopwareIDs from the current response and store them in a separate key, so that we are able
     * to easily invalidate all pages with a certain ID
     *
     * @param $metadataKey
     * @param Response $response
     * @return mixed
     */
    private function storeLookupOptimization($metadataKey, Response $response)
    {
        if (!$response->headers->has('x-shopware-cache-id') || !$response->headers->has('x-content-digest')) {
            return $metadataKey;
        }

        $cacheIds = array_filter(explode(';', $response->headers->get('x-shopware-cache-id')));
        $cacheKey = $response->headers->get('x-content-digest');

        foreach ($cacheIds as $cacheId) {
            $key = 'ci' . hash('sha256', $cacheId);
            if (!$content = json_decode($this->load($key), true)) {
                $content = [];
            }


            // Storing the headerKey and the cacheKey will increase the lookup file size a bit
            // but save a lot of reads when invalidating
            $content[$cacheKey] = $metadataKey;

            if (!false === $this->save($key, json_encode($content))) {
                throw new \RuntimeException("Could not write cacheKey $key");
            }
        }

        return $metadataKey;
    }

}