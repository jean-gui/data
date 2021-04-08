<?php

declare(strict_types=1);

namespace Strata\Data\Http\Response;

use Psr\Cache\CacheItemInterface;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class CacheableResponse implements ResponseInterface, StreamableInterface
{
    use DecoratedResponseTrait;

    private bool $isCacheHit = false;
    private ?CacheItemInterface $cacheItem = null;

    /**
     * Constructor
     *
     * @param ResponseInterface $response Response we are decorating
     * @param bool $hit Whether this response came from the cache (and has a cache hit)
     * @param CacheItem $item Cache item
     */
    public function __construct(ResponseInterface $response, ?bool $hit = null, ?CacheItemInterface $item = null)
    {
        $this->decorated = $response;
        if ($hit !== null) {
            $this->setHit($hit);
        }
        if ($item !== null) {
            $this->setCacheItem($item);
        }
    }

    /**
     * Whether this response can be saved to the cache
     *
     * @return bool
     */
    public function isCacheable(): bool
    {
        return $this->cacheItem instanceof CacheItemInterface;
    }

    public function setHit(bool $hit)
    {
        $this->isCacheHit = $hit;
    }

    /**
     * Whether the HTTP response was loaded from the cache (true) or was loaded live (false)
     *
     * @return bool
     */
    public function isHit()
    {
        return $this->isCacheHit;
    }

    public function setCacheItem(CacheItemInterface $item)
    {
        $this->cacheItem = $item;
    }

    /**
     * Unset cache item to conserve memory
     */
    public function unsetCacheItem()
    {
        $this->cacheItem = null;
    }

    /**
     * Return cache item, or null if not set
     *
     * @return ?CacheItem
     */
    public function getCacheItem(): ?CacheItemInterface
    {
        return $this->cacheItem;
    }
}
