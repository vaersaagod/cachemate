<?php

namespace vaersaagod\cachemate\models;

use craft\base\Model;

/**
 * A normalized, cache-candidate request URI
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class CacheableUri extends Model
{
    // Public Properties
    // =========================================================================

    /** @var string Lowercased hostname, without port */
    public string $hostKey = '';

    /** @var string Decoded, normalized request path without leading/trailing slashes ('' = homepage) */
    public string $path = '';

    /** @var string Canonical (sorted, RFC 3986-encoded) query string, '' if none */
    public string $queryString = '';

    /** @var int|null The resolved current site ID, if any */
    public ?int $siteId = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns a unique key for this URI, for logging and cache indexing.
     *
     * @return string
     */
    public function getKey(): string
    {
        $key = $this->hostKey . '/' . $this->path;

        if ($this->queryString !== '') {
            $key .= '?' . $this->queryString;
        }

        return $key;
    }
}
