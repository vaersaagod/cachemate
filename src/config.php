<?php

/**
 * CacheMate example config file
 *
 * Copy to your Craft project's config folder as `cachemate.php` to override
 * any of the default settings below. Supports Craft's multi-environment
 * config format.
 */

return [

    '*' => [

        /*
         * Whether static caching is enabled. Can be a boolean, or an array
         * keyed by site handle (with '*' as the fallback):
         *
         * 'enabled' => ['*' => true, 'intranet' => false],
         */
        //'enabled' => true,

        /*
         * Where cached pages are stored. Must be inside the web root for web
         * server rewrites to be able to serve cache hits without PHP.
         */
        //'cachePath' => '@webroot/cachemate',

        /*
         * How long cached pages are considered fresh, for the PHP serve path.
         * Seconds, a DateInterval duration string ('P1D'), or 0 for no expiry.
         */
        //'cacheDuration' => 0,

        /*
         * Whether/how query strings participate in caching:
         * - false: requests with query strings are never cached
         * - true:  cached as unique pages, all params in the cache key (sorted)
         * - array: whitelist of params kept (sorted) in the cache key
         */
        //'cacheQueryStrings' => false,

        /*
         * When `cacheQueryStrings` is an array: if true, requests carrying any
         * param NOT in the whitelist are not cached at all. If false, unknown
         * params are just stripped from the cache key.
         */
        //'strictQueryParams' => false,

        /*
         * Params that never affect the cache key and never block caching.
         * Trailing-* wildcards supported.
         */
        //'ignoredQueryParams' => ['utm_*', 'gclid', 'fbclid'],

        /*
         * URI patterns to exclude from caching. Regex fragments, matched
         * case-insensitively against the site-relative path with a leading
         * slash. Flat array, or keyed by site handle with '*' as fallback:
         *
         * 'excludedUriPatterns' => ['^/account', '/search'],
         * 'excludedUriPatterns' => ['*' => ['^/account'], 'english' => ['^/members']],
         */
        //'excludedUriPatterns' => [],

        /*
         * Requests with URIs longer than this are served dynamically.
         */
        //'maxUriLength' => 2048,

        /*
         * Whether to serve cached pages from PHP early in the request cycle,
         * when no web server rewrite is configured or the rewrite didn't match.
         */
        //'serveWithPhp' => true,

        /*
         * The Cache-Control header sent with cached responses served by PHP.
         */
        //'cacheControlHeader' => 'public, s-maxage=31536000, max-age=0',

        /*
         * Whether cached pages are automatically purged when content changes.
         */
        //'purgeEnabled' => true,

        /*
         * The element types that trigger purging when they change.
         */
        //'trackedElementTypes' => [
        //    \craft\elements\Entry::class,
        //    \craft\elements\Category::class,
        //    \craft\elements\Asset::class,
        //    \craft\elements\GlobalSet::class,
        //],

        /*
         * Rules for what to purge when an element changes, in addition to the
         * element's own URLs (which are always purged). When NO rule matches a
         * changed element, the entire cache is cleared — rules are the tool to
         * contain purges on bigger sites.
         *
         * Keys (tried most-specific-first): 'section:handle', 'categoryGroup:handle',
         * 'volume:handle', 'globalSet:handle', 'entry', 'category', 'asset',
         * 'globalSet', '*'.
         *
         * Values: 'all' (clear everything), an array of site-relative paths
         * ('/' = homepage, trailing slash-* = recursive), an array keyed by site
         * handle (with '*' fallback), or [] (own URLs only).
         */
        //'purgeRules' => [
        //    'section:news' => ['/news', '/'],
        //    'section:products' => ['*' => ['/products'], 'english' => ['/products', '/product-index']],
        //    'categoryGroup:topics' => 'all',
        //    'volume:images' => ['/gallery/*'],
        //    'globalSet:footer' => 'all',
        //],

        /*
         * The maximum number of targeted purge paths per flush. When exceeded,
         * the purge escalates to clearing the entire cache.
         */
        //'maxTargetedPurges' => 200,

        /*
         * Whether the X-CacheMate response header includes reason keywords
         * ('bypass; session', 'miss; sets-cookies' etc.). 'auto' = only when
         * devMode is enabled, true = always, false = never. The bare states
         * (hit/miss/bypass) are always sent on front-end responses.
         */
        //'debugHeaders' => 'auto',

        /*
         * Whether to show a "Purge from static cache" button in the entry
         * edit sidebar. The purge behaves exactly like saving the entry
         * would (purge rules apply). Only shown when purging is enabled.
         */
        //'entryPurgeButton' => true,

        /*
         * Whether to cache 404 responses. One 404 page is cached per site
         * (never per URI) and served for subsequent 404s, skipping the error
         * template render. IMPORTANT: only enable this if your 404 template
         * does NOT render the requested URL/path or anything else
         * request-specific. Boolean, or per-site map with '*' fallback.
         */
        //'cache404s' => false,

        /*
         * How long cached 404 pages are considered fresh. Seconds, a
         * DateInterval duration string, or 0 for no expiry.
         */
        //'cache404Duration' => 3600,

    ],

    'dev' => [
        //'enabled' => false,
    ],

];
