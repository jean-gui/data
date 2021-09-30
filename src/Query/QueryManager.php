<?php

declare(strict_types=1);

namespace Strata\Data\Query;

use Strata\Data\Collection;
use Strata\Data\DataProviderInterface;
use Strata\Data\Exception\CacheException;
use Strata\Data\Exception\MissingDataProviderException;
use Strata\Data\Exception\QueryManagerException;
use Strata\Data\Http\Http;
use Strata\Data\Http\Response\CacheableResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class to help manage running queries against data providers
 */
class QueryManager
{
    const DATA_PROVIDER_NAME = 'name';
    const DATA_PROVIDER_OBJECT = 'object';
    const DATA_PROVIDER_CLASS = 'class';
    const DATA_PROVIDER_QUERIES = 'queries';

    /** @var QueryInterface[] */
    private array $queries = [];
    private array $dataProviders = [];

    private ?HttpClientInterface $httpClient = null;
    private array $httpDefaultOptions = [];
    private bool $cacheEnabled = false;
    private ?CacheInterface $cache = null;
    private ?int $cacheLifetime = null;
    private array $cacheTags = [];

    /**
     * Set a shared HTTP client to be used across all HTTP data providers
     *
     * This is useful to run concurrent requests
     *
     * @param HttpClientInterface $httpClient
     */
    public function setHttpClient(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
        foreach ($this->getDataProviders() as $dataProvider) {
            if ($dataProvider instanceof Http) {
                $dataProvider->setHttpClient($httpClient);
            }
        }
    }

    /**
     * Share the same HTTP client across all HTTP compatible data providers
     *
     * This gets the HTTP client from the first data provider and sets this across all HTTP data providers
     * Any future data providers you add will also have the same HTTP client set
     * This is useful to run concurrent requests
     *
     * @throws MissingDataProviderException
     */
    public function shareHttpClient()
    {
        $httpDataProvider = null;
        foreach ($this->getDataProviders() as $dataProvider) {
            if ($dataProvider instanceof Http) {
                $httpDataProvider = $dataProvider;
                break;
            }
        }
        if (!($httpDataProvider instanceof Http)) {
            throw new MissingDataProviderException('You must setup at least one HTTP data provider before sharing HTTP client across all HTTP data providers');
        }
        $this->setHttpClient($httpDataProvider->getHttpClient());
    }

    /**
     * Set default HTTP options for all subsequent HTTP requests
     *
     * Please note default options are merged with existing default options
     *
     * @see https://symfony.com/doc/current/reference/configuration/framework.html#reference-http-client
     * @param array $options
     */
    public function setHttpDefaultOptions(array $options)
    {
        $this->httpDefaultOptions = $options;
        foreach ($this->getDataProviders() as $dataProvider) {
            if ($dataProvider instanceof Http) {
                $dataProvider->setDefaultOptions($options);
            }
        }
    }

    /**
     * Add a data provider to use with queries
     * @param string $name
     * @param DataProviderInterface $dataProvider
     */
    public function addDataProvider(string $name, DataProviderInterface $dataProvider)
    {
        if ($this->isCacheEnabled()) {
            // Enable cache on data provider
            $dataProvider->setCache($this->cache, $this->cacheLifetime);
        } elseif ($this->hasCache()) {
            // If cache exists but disabled, add to data provider but disable it
            $dataProvider->setCache($this->cache, $this->cacheLifetime);
            $dataProvider->disableCache();
        }
        if (!empty($this->cacheTags)) {
            // If cache tags exist and cache adapter is compatible, set them
            if ($dataProvider->getCache()->isTaggable()) {
                $dataProvider->setCacheTags($this->cacheTags);
            }
        }

        if ($dataProvider instanceof Http) {
            // Set shared HTTP client
            if (!is_null($this->httpClient) && !$dataProvider->hasHttpClient()) {
                $dataProvider->setHttpClient($this->httpClient);
            }
            // Set default options
            if (!empty($this->httpDefaultOptions)) {
                $dataProvider->setDefaultOptions($this->httpDefaultOptions);
            }
        }

        $this->dataProviders[$name] = [
            self::DATA_PROVIDER_NAME => $name,
            self::DATA_PROVIDER_CLASS => get_class($dataProvider),
            self::DATA_PROVIDER_OBJECT => $dataProvider,
            self::DATA_PROVIDER_QUERIES => [],
        ];
    }

    /**
     * Does the named data provider exist?
     *
     * @param string $name
     * @return bool
     */
    public function hasDataProvider(string $name): bool
    {
        return (isset($this->dataProviders[$name]));
    }

    /**
     * Return all data provider objects
     *
     * @return DataProviderInterface[]
     */
    public function getDataProviders(): array
    {
        $dataProviders = [];
        foreach ($this->dataProviders as $item) {
            $dataProviders[] = $item[self::DATA_PROVIDER_OBJECT];
        }
        return $dataProviders;
    }

    /**
     * Return data provider by name
     *
     * @param string $name Data provider name
     * @return DataProviderInterface
     * @throws MissingDataProviderException
     */
    public function getDataProvider(string $name): DataProviderInterface
    {
        if (!$this->hasDataProvider($name)) {
            throw new MissingDataProviderException(sprintf('Cannot find data provider %s', $name));
        }
        return $this->dataProviders[$name][self::DATA_PROVIDER_OBJECT];
    }

    /**
     * Return queries for a named data provider
     *
     * @param string $name
     * @return QueryInterface[]
     * @throws MissingDataProviderException
     */
    public function getDataProviderQueries(string $name): array
    {
        if (!$this->hasDataProvider($name)) {
            throw new MissingDataProviderException(sprintf('Cannot find data provider %s', $name));
        }
        return $this->dataProviders[$name][self::DATA_PROVIDER_QUERIES];
    }

    /**
     * Return classname for the named data provider object
     *
     * @param string $name
     * @return string
     * @throws MissingDataProviderException
     */
    public function getDataProviderClass(string $name): string
    {
        if (!$this->hasDataProvider($name)) {
            throw new MissingDataProviderException(sprintf('Cannot find data provider %s', $name));
        }
        return $this->dataProviders[$name][self::DATA_PROVIDER_CLASS];
    }

    /**
     * Return first compatible data provider name we can find for passed query
     * @param QueryInterface $query
     * @return string Data provider name
     * @throws MissingDataProviderException
     */
    public function getDataProviderNameForQuery(QueryInterface $query): string
    {
        $requiredClass = $query->getRequiredDataProviderClass();
        foreach ($this->dataProviders as $item) {
            $class = $item[self::DATA_PROVIDER_CLASS];
            if ($class === $requiredClass) {
                return $item[self::DATA_PROVIDER_NAME];
            }
        }
        throw new MissingDataProviderException(sprintf('Cannot find a compatible data provider (%s) for query', $class));
    }

    /**
     * Add a query (does not run the query, this happens on data access)
     *
     * @todo Need to uniquely identify each data provider so we can add defaults (e.g. site ID, preview token)
     *
     * @param string $queryName
     * @param QueryInterface $query Query
     * @param string|null $dataProviderName Data provider to use with query, if not set use last added data provider
     * @throws QueryManagerException
     */
    public function add(string $queryName, QueryInterface $query, ?string $dataProviderName = null)
    {
        if ($this->hasQuery($queryName)) {
            throw new QueryManagerException(sprintf('Query name %s already exists in the Query Manager, please give this query a unique name', $queryName));
        }

        if (!$query->hasDataProvider()) {
            if ($dataProviderName === null) {
                // Get compatible data provider if not set in method argument
                $dataProviderName = $this->getDataProviderNameForQuery($query);
            }
            $dataProvider = $this->getDataProvider($dataProviderName);
            $query->setDataProvider($dataProvider);
        }

        // Prepare request
        $query->prepare();

        // Add query to query stack
        $this->queries[$queryName] = $query;

        // Add query to data providers array
        $this->dataProviders[$dataProviderName][self::DATA_PROVIDER_QUERIES][$queryName] = $query;
    }

    /**
     * Run queries on data access
     *
     * Only runs a query once, you can force a query to be re-run via $query->clearResponse()
     *
     * This should run multiple queries concurrently
     */
    protected function runQueries()
    {
        foreach ($this->queries as $query) {
            // Skip if already run, you can still manually re-run a query via $query->run()
            if ($query->hasResponseRun()) {
                continue;
            }

            // Run a query
            $query->run();
        }
    }

    /**
     * Does a named query exist?
     * @param string $name
     * @return bool
     */
    public function hasQuery(string $name): bool
    {
        return array_key_exists($name, $this->queries);
    }

    /**
     * Return a query by name
     * @param string $name
     * @return QueryInterface|null
     */
    public function getQuery(string $name): ?QueryInterface
    {
        if ($this->hasQuery($name)) {
            return $this->queries[$name];
        }
        return null;
    }

    /**
     * Return all queries in the query manager
     * @return QueryInterface[]
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * Get response by query name
     * @param string $queryName
     * @return CacheableResponse
     * @throws QueryManagerException
     */
    public function getResponse(string $queryName): CacheableResponse
    {
        if (!$this->hasQuery($queryName)) {
            throw new QueryManagerException(sprintf('Cannot find query with query name "%s"', $queryName));
        }
        $query = $this->getQuery($queryName);

        if (!$query->hasResponseRun()) {
            $this->runQueries();
        }
        if (!$query->hasResponseRun()) {
            throw new QueryManagerException(sprintf('Response has not run for query name "%s"', $queryName));
        }

        return $query->getResponse();
    }

    /**
     * Clear response from a query (allows you to re-run queries)
     *
     * @param string $queryName
     * @throws QueryManagerException
     */
    public function clearResponse(string $queryName)
    {
        if (!$this->hasQuery($queryName)) {
            throw new QueryManagerException(sprintf('Cannot find query with query name "%s"', $queryName));
        }
        $query = $this->getQuery($queryName);

        $query->clearResponse();
    }

    /**
     * Whether a query response has been returned from the cache
     *
     * @param string $queryName
     * @return bool|null True if query response returned from cache, null if query response has not yet run
     * @throws QueryManagerException
     */
    public function isHit(string $queryName): ?bool
    {
        if (!$this->hasQuery($queryName)) {
            throw new QueryManagerException(sprintf('Cannot find query with query name "%s"', $queryName));
        }
        $query = $this->getQuery($queryName);

        if (!$query->hasResponseRun()) {
            return null;
        }

        return $query->getResponse()->isHit();
    }

    /**
     * Return a data item
     *
     * Default functionality is to return decoded data as an array
     *
     * @param string $queryName
     * @param string|null $rootPropertyPath Property path to root element to select data from, null if no data returned
     * @return mixed
     * @throws QueryManagerException
     */
    public function get(string $queryName, ?string $rootPropertyPath = null)
    {
        if (!$this->hasQuery($queryName)) {
            throw new QueryManagerException(sprintf('Cannot find query with query name "%s"', $queryName));
        }
        $query = $this->getQuery($queryName);

        if (!$query->hasResponseRun()) {
            $this->runQueries();
        }
        if (!$query->hasResponseRun()) {
            throw new QueryManagerException(sprintf('Response has not run for query name "%s"', $queryName));
        }

        // Set root property path, then reset it
        if ($rootPropertyPath !== null) {
            $originalPath = $query->getRootPropertyPath();
            $query->setRootPropertyPath($rootPropertyPath);
        }
        $data = $query->get();
        if ($rootPropertyPath !== null && is_string($originalPath)) {
            $query->setRootPropertyPath($originalPath);
        }

        return $data;
    }

    /**
     * Return a collection of data items with pagination
     *
     * Default functionality is to return decoded data as an array with pagination
     *
     * @param string $queryName
     * @param string|null $rootPropertyPath Property path to root element to select data from
     * @throws QueryManagerException
     */
    public function getCollection(string $queryName, ?string $rootPropertyPath = null): Collection
    {
        if (!$this->hasQuery($queryName)) {
            throw new QueryManagerException(sprintf('Cannot find query with query name "%s"', $queryName));
        }
        $query = $this->getQuery($queryName);

        if (!$query->hasResponseRun()) {
            $this->runQueries();
        }
        if (!$query->hasResponseRun()) {
            throw new QueryManagerException(sprintf('Response has not run for query name "%s"', $queryName));
        }

        // Set root property path, then reset it
        if ($rootPropertyPath !== null) {
            $originalPath = $query->getRootPropertyPath();
            $query->setRootPropertyPath($rootPropertyPath);
        }
        $data = $query->getCollection();
        if ($rootPropertyPath !== null && is_string($originalPath)) {
            $query->setRootPropertyPath($originalPath);
        }

        return $data;
    }

    /**
     * Set and enable the cache
     *
     * @param CacheInterface $cache
     * @param int $defaultLifetime Default cache lifetime
     */
    public function setCache(CacheInterface $cache, ?int $defaultLifetime = null)
    {
        $this->cache = $cache;
        $this->cacheEnabled = true;

        foreach ($this->getDataProviders() as $dataProvider) {
            $dataProvider->setCache($this->cache, $defaultLifetime);
        }
    }

    /**
     * Whether a cache object is set
     * @return bool
     */
    public function hasCache(): bool
    {
        return $this->cache instanceof CacheInterface;
    }

    /**
     * Is the cache enabled?
     *
     * @return bool
     */
    public function isCacheEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    /**
     * Enable cache for subsequent data requests
     *
     * @param ?int $lifetime
     * @throws CacheException If cache not set
     */
    public function enableCache(?int $lifetime = null)
    {
        $this->cacheEnabled = true;
        $this->cacheLifetime = $lifetime;
        foreach ($this->getDataProviders() as $dataProvider) {
            $dataProvider->enableCache($lifetime);
        }
    }

    /**
     * Disable cache for subsequent data requests
     *
     */
    public function disableCache()
    {
        $this->cacheEnabled = false;
        foreach ($this->getDataProviders() as $dataProvider) {
            $dataProvider->disableCache();
        }
    }

    /**
     * Set cache tags to apply to all future saved cache items
     *
     * To remove tags do not pass any arguments and tags will be reset to an empty array
     *
     * @param array $tags
     * @throws CacheException
     * @throws QueryManagerException
     */
    public function setCacheTags(array $tags = [])
    {
        $taggable = 0;
        $this->cacheTags = $tags;
        foreach ($this->getDataProviders() as $dataProvider) {
            // If cache adapter is compatible, set them
            if ($dataProvider->isCacheEnabled() && $dataProvider->getCache()->isTaggable()) {
                $dataProvider->setCacheTags($tags);
                $taggable++;
            }
        }

        if ($taggable === 0) {
            throw new QueryManagerException('No data providers contain a cache adapter that is compatible with tagging (must implement Symfony\Component\Cache\Adapter\TagAwareAdapter)');
        }
    }

    /**
     * Return cache tags currently set to the query manager
     * @return array
     */
    public function getCacheTags(): array
    {
        return $this->cacheTags;
    }
}
