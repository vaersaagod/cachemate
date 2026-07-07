<?php

namespace vaersaagod\cachemate\helpers;

use Craft;
use craft\web\Request;

use vaersaagod\cachemate\models\CacheableUri;
use vaersaagod\cachemate\models\Settings;
use vaersaagod\cachemate\services\CacheStorageService;

/**
 * URI-level (Phase B) cache candidacy checks and normalization.
 *
 * The normalization methods are deliberately kept free of `Craft::$app`
 * dependencies beyond the passed-in settings, so a future standalone rewrite
 * script can reuse them without bootstrapping Craft.
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class UriHelper
{
    // Public Methods
    // =========================================================================

    /**
     * Creates a normalized CacheableUri for the current request, or returns
     * null if the request URI is not a cache candidate — in which case
     * `$reason` is set to a keyword explaining why.
     *
     * @param Request $request
     * @param Settings $settings
     * @param string|null $reason
     * @return CacheableUri|null
     */
    public static function createCacheableUri(Request $request, Settings $settings, ?string &$reason = null): ?CacheableUri
    {
        // Junk-URI guard — overly long request URIs are served dynamically
        if (strlen($request->getUrl()) > $settings->maxUriLength) {
            $reason = 'uri';

            return null;
        }

        $path = $request->getFullPath();

        // Traversal guard — no control chars, and no period-only or overly long path segments.
        // The storage layer re-verifies the final path structurally (defense in depth).
        if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
            $reason = 'uri';

            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if (strlen($segment) > 255 || preg_match('/^(\.|%2e)+$/i', $segment)) {
                $reason = 'uri';

                return null;
            }

            // Reserved cache-tree names can never be cached as real pages
            if (strcasecmp($segment, CacheStorageService::QUERY_DIR) === 0 || strcasecmp($segment, CacheStorageService::NOT_FOUND_DIR) === 0) {
                $reason = 'uri';

                return null;
            }
        }

        // Duplicate-content URIs via index.php are never cached
        if (str_contains(strtolower($path), 'index.php')) {
            $reason = 'uri';

            return null;
        }

        // Excluded URI patterns (opt-out policy — excluded always wins)
        $excludedPatterns = $settings->getLocalizedConfigSetting('excludedUriPatterns');

        if (!empty($excludedPatterns) && static::matchesUriPatterns('/' . $path, $excludedPatterns)) {
            $reason = 'excluded';

            return null;
        }

        // Normalize the query string; null means the query params make the request uncacheable
        $queryParams = $request->getQueryParams();
        unset($queryParams[Craft::$app->getConfig()->getGeneral()->pathParam ?? 'p']);

        $queryParams = static::normalizeQueryParams($queryParams, $settings);

        if ($queryParams === null) {
            $reason = 'query';

            return null;
        }

        try {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        } catch (\Throwable) {
            $siteId = null;
        }

        return new CacheableUri([
            'hostKey' => strtolower($request->getHostName() ?? ''),
            'path' => trim($path, '/'),
            'queryString' => http_build_query($queryParams),
            'siteId' => $siteId,
        ]);
    }

    /**
     * Normalizes an array of query params according to the query string
     * settings. Returns null if the params make the request uncacheable, or
     * the params that should be part of the cache key, sorted by name.
     *
     * @param array $params
     * @param Settings $settings
     * @return array|null
     */
    public static function normalizeQueryParams(array $params, Settings $settings): ?array
    {
        // Strip params that never affect the cache key (wildcard-aware)
        foreach (array_keys($params) as $name) {
            foreach ($settings->ignoredQueryParams as $pattern) {
                if (self::matchesParamPattern((string)$name, $pattern)) {
                    unset($params[$name]);
                    break;
                }
            }
        }

        if (empty($params)) {
            return [];
        }

        if ($settings->cacheQueryStrings === false) {
            return null;
        }

        if (is_array($settings->cacheQueryStrings)) {
            $kept = array_intersect_key($params, array_flip($settings->cacheQueryStrings));

            if ($settings->strictQueryParams && count($kept) !== count($params)) {
                return null;
            }

            $params = $kept;
        }

        self::ksortRecursive($params);

        return $params;
    }

    /**
     * Returns whether a path matches any of the given regex pattern fragments.
     * Patterns are matched case-insensitively, RedirectMate-style.
     *
     * @param string $path
     * @param array $patterns
     * @return bool
     */
    public static function matchesUriPatterns(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            try {
                if (preg_match('`' . $pattern . '`i', $path) === 1) {
                    return true;
                }
            } catch (\Throwable $throwable) {
                Craft::error('An error occurred when trying to match pattern "' . $pattern . '": ' . $throwable->getMessage(), __METHOD__);
            }
        }

        return false;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns whether a param name matches a pattern with optional trailing-*
     * wildcards (e.g. 'utm_*').
     *
     * @param string $name
     * @param string $pattern
     * @return bool
     */
    private static function matchesParamPattern(string $name, string $pattern): bool
    {
        if (!str_contains($pattern, '*')) {
            return strcasecmp($name, $pattern) === 0;
        }

        return preg_match('/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i', $name) === 1;
    }

    /**
     * Sorts an array by key, recursively (handles array params like
     * filter[b]=1&filter[a]=2).
     *
     * @param array $array
     * @return void
     */
    private static function ksortRecursive(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }
}
