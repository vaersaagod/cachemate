<?php

namespace vaersaagod\cachemate\models;

use craft\base\Model;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;

/**
 * CacheMate settings
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class Settings extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * Whether static caching is enabled.
     *
     * Can be a boolean, or an array keyed by site handle (with an optional '*'
     * key as the fallback for sites not listed explicitly).
     *
     * @var bool|array
     */
    public bool|array $enabled = true;

    /**
     * The path where cached pages are stored. Must be inside the web root for
     * web server rewrites (e.g. nginx try_files) to be able to serve cache hits
     * without invoking PHP.
     *
     * @var string
     */
    public string $cachePath = '@webroot/cachemate';

    /**
     * How long cached pages are considered fresh, for the PHP serve path.
     * Accepts a number of seconds, a valid DateInterval duration string
     * (e.g. 'P1D'), or 0 for no expiry.
     *
     * Note that web server rewrite hits bypass this check entirely; expiry
     * for those is handled by cache invalidation.
     *
     * @var mixed
     */
    public mixed $cacheDuration = 0;

    /**
     * Whether/how query strings participate in caching.
     *
     * - false: requests with query strings (after removing $ignoredQueryParams)
     *   are never cached.
     * - true:  requests with query strings are cached as unique pages; all
     *   params are part of the cache key, sorted by name.
     * - array: a whitelist of param names. Whitelisted params are kept (sorted)
     *   in the cache key. What happens to requests carrying non-whitelisted
     *   params depends on $strictQueryParams.
     *
     * @var bool|array
     */
    public bool|array $cacheQueryStrings = false;

    /**
     * Only relevant when $cacheQueryStrings is an array.
     *
     * - false (default): non-whitelisted params are stripped from the cache key,
     *   and the request is still cached/served (i.e. ?page=2&foo=bar shares a
     *   cache entry with ?page=2).
     * - true: a request carrying any param NOT in the whitelist (and not in
     *   $ignoredQueryParams) is not cacheable at all — the whitelist is the
     *   closed set of allowed params.
     *
     * @var bool
     */
    public bool $strictQueryParams = false;

    /**
     * Params that never affect the cache key and never block caching, in any
     * query string mode. Supports trailing-* wildcards.
     *
     * @var string[]
     */
    public array $ignoredQueryParams = [
        'utm_*',
        'gclid',
        'gclsrc',
        'dclid',
        'fbclid',
        'msclkid',
        'twclid',
        'ttclid',
        'mc_cid',
        'mc_eid',
        '_ga',
        '_gl',
    ];

    /**
     * URI patterns to exclude from caching. Regex fragments, matched
     * case-insensitively against the site-relative path with a leading slash
     * (e.g. '^/account', '/search').
     *
     * Can be a flat array of patterns, or an array keyed by site handle (with
     * an optional '*' key as the fallback), where each value is an array of
     * patterns.
     *
     * @var array
     */
    public array $excludedUriPatterns = [];

    /**
     * The maximum length of a cacheable request URI (path + query string).
     * Longer requests are served dynamically.
     *
     * @var int
     */
    public int $maxUriLength = 2048;

    /**
     * Whether to serve cached pages from PHP, early in the request cycle,
     * when no web server rewrite is configured (or the rewrite didn't match).
     *
     * @var bool
     */
    public bool $serveWithPhp = true;

    /**
     * The Cache-Control header value sent with cached responses served by PHP.
     *
     * @var string
     */
    public string $cacheControlHeader = 'public, s-maxage=31536000, max-age=0';

    /**
     * Whether cached pages are automatically purged when content changes.
     *
     * @var bool
     */
    public bool $purgeEnabled = true;

    /**
     * The element types that trigger purging when they change.
     *
     * @var string[]
     */
    public array $trackedElementTypes = [
        Entry::class,
        Category::class,
        Asset::class,
        GlobalSet::class,
    ];

    /**
     * Rules for what to purge when an element changes, in addition to the
     * element's own URLs (which are always purged).
     *
     * Keys are tried most-specific-first: 'section:handle', 'categoryGroup:handle',
     * 'volume:handle', 'globalSet:handle', then the element type keys 'entry',
     * 'category', 'asset' and 'globalSet', and finally '*'.
     *
     * Values can be:
     * - 'all': clear the entire cache
     * - an array of site-relative paths ('/' = the homepage; a trailing slash-*
     *   makes the purge recursive, e.g. '/news/*')
     * - an array keyed by site handle (with '*' as the fallback), where each
     *   value is an array of paths
     * - an empty array: purge the element's own URLs only, nothing extra
     *
     * When NO rule matches a changed element, the entire cache is cleared.
     *
     * @var array
     */
    public array $purgeRules = [];

    /**
     * The maximum number of targeted purge paths per flush. When exceeded, the
     * purge escalates to clearing the entire cache (which is faster).
     *
     * @var int
     */
    public int $maxTargetedPurges = 200;

    /**
     * Whether to show a "Purge from static cache" button in the entry edit
     * sidebar, letting editors purge individual entries manually. The purge
     * behaves exactly like saving the entry would (purge rules apply).
     *
     * Only shown when purging is enabled.
     *
     * @var bool
     */
    public bool $entryPurgeButton = true;

    /**
     * Whether to cache 404 responses. One 404 page is cached per site (never
     * per URI), and served for subsequent 404s — skipping the error template
     * render and its queries.
     *
     * IMPORTANT: only enable this if your 404 template does NOT render the
     * requested URL/path or anything else request-specific.
     *
     * Can be a boolean, or an array keyed by site handle (with '*' fallback).
     *
     * @var bool|array
     */
    public bool|array $cache404s = false;

    /**
     * How long cached 404 pages are considered fresh. Seconds, a DateInterval
     * duration string, or 0 for no expiry. Cached 404s are always served via
     * PHP, so this TTL is always enforced.
     *
     * @var mixed
     */
    public mixed $cache404Duration = 3600;

    // Public Methods
    // =========================================================================

    /**
     * Returns a config setting, taking per-site overrides into account. If the
     * setting is an array keyed by site handles, the value for the given site
     * (or the '*' fallback) is returned.
     *
     * @param string $name
     * @param string|null $siteHandle
     * @return mixed
     */
    public function getLocalizedConfigSetting(string $name, ?string $siteHandle = null): mixed
    {
        if ($siteHandle === null) {
            try {
                $siteHandle = \Craft::$app->getSites()->getCurrentSite()->handle;
            } catch (\Throwable) {
                return $this->$name;
            }
        }

        if (is_array($this->$name) && array_key_exists($siteHandle, $this->$name)) {
            return $this->$name[$siteHandle];
        }

        if (is_array($this->$name) && array_key_exists('*', $this->$name)) {
            return $this->$name['*'];
        }

        return $this->$name;
    }
}
