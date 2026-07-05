<?php

namespace vaersaagod\cachemate\helpers;

use Craft;
use craft\models\Site;

/**
 * Site-related cache helpers.
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class SiteHelper
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the cache location for a site — the host directory key and the
     * site's path prefix (for path-based sites) — or null if it can't be
     * derived from the site's base URL.
     *
     * @param Site $site
     * @return array{hostKey: string, prefix: string}|null
     */
    public static function getSiteCachePrefix(Site $site): ?array
    {
        $baseUrl = $site->getBaseUrl();

        // Resolve relative base URLs (e.g. '/no/') against the @web alias, or
        // failing that, the primary site's host
        if ($baseUrl && !parse_url($baseUrl, PHP_URL_HOST)) {
            $fallback = Craft::getAlias('@web', false);

            if (!$fallback || !parse_url($fallback, PHP_URL_HOST)) {
                try {
                    $fallback = Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
                } catch (\Throwable) {
                    $fallback = null;
                }
            }

            $fallbackHost = $fallback ? parse_url($fallback, PHP_URL_HOST) : null;

            if ($fallbackHost) {
                $baseUrl = 'https://' . $fallbackHost . '/' . ltrim($baseUrl, '/');
            } else {
                $baseUrl = null;
            }
        }

        if (!$baseUrl) {
            return null;
        }

        $parsed = parse_url($baseUrl);
        $hostKey = strtolower($parsed['host'] ?? '');

        if ($hostKey === '') {
            return null;
        }

        return [
            'hostKey' => $hostKey,
            'prefix' => trim($parsed['path'] ?? '', '/'),
        ];
    }
}
