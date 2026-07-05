<?php

namespace vaersaagod\cachemate\services;

use Craft;
use craft\helpers\ConfigHelper;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;

use vaersaagod\cachemate\CacheMate;
use vaersaagod\cachemate\models\CacheableUri;

use yii\base\Component;

/**
 * Reads and writes statically cached pages on disk. The only class that
 * touches the cache storage.
 *
 * File layout: {cachePath}/{hostKey}/{path}/[_q/{queryString}/]index.html
 * — designed so a web server rewrite (e.g. nginx try_files) can serve cache
 * hits directly, without invoking PHP.
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class CacheStorageService extends Component
{
    // Const Properties
    // =========================================================================

    /** @var string Marker directory separating query string variants from sub-paths */
    public const QUERY_DIR = '_q';

    /** @var string Reserved directory for per-site cached 404 pages */
    public const NOT_FOUND_DIR = '__404__';

    /** @var string The file name cached pages are stored as */
    public const FILE_NAME = 'index.html';

    /** @var string Suffix for expiry sidecar files (index.html.meta) */
    public const META_SUFFIX = '.meta';

    // Public Methods
    // =========================================================================

    /**
     * Returns the absolute file path a URI is cached at, or null if a safe
     * path can't be derived.
     *
     * @param CacheableUri $uri
     * @return string|null
     */
    public function getFilePath(CacheableUri $uri): ?string
    {
        $root = $this->getRootPath();

        if ($root === null || $uri->hostKey === '') {
            return null;
        }

        $segments = [$root, $uri->hostKey];

        if ($uri->path !== '') {
            $segments[] = $uri->path;
        }

        if ($uri->queryString !== '') {
            $segments[] = self::QUERY_DIR . '/' . $uri->queryString;
        }

        $segments[] = self::FILE_NAME;

        $path = FileHelper::normalizePath(implode('/', $segments));

        // Second traversal guard — never trust the caller; the resolved path
        // must remain inside the cache root
        if (!str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    /**
     * Returns the cached content for a URI, or null if there is no fresh
     * cached page for it.
     *
     * @param CacheableUri $uri
     * @return string|null
     */
    public function get(CacheableUri $uri): ?string
    {
        $path = $this->getFilePath($uri);

        if ($path === null || !is_file($path)) {
            return null;
        }

        // TTL check, for the PHP serve path. A per-page expiry sidecar wins
        // over the global duration (0 = no expiry, in both cases).
        $expires = $this->getSidecarExpiry($path);

        if ($expires !== null) {
            if ($expires > 0 && $expires < time()) {
                return null;
            }
        } else {
            $duration = ConfigHelper::durationInSeconds(CacheMate::getInstance()->getSettings()->cacheDuration);

            if ($duration > 0) {
                $modifiedAt = @filemtime($path);

                if ($modifiedAt === false || $modifiedAt + $duration < time()) {
                    return null;
                }
            }
        }

        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    /**
     * Saves cached content for a URI. The write is atomic (temp file + rename)
     * so readers and web server rewrites never see partial files.
     *
     * A `['expires' => <unix timestamp>]` meta array (0 = never expires)
     * writes an expiry sidecar next to the cached file, overriding the global
     * cache duration for the page.
     *
     * @param CacheableUri $uri
     * @param string $content
     * @param array|null $meta
     * @return bool
     * @throws \yii\base\Exception
     */
    public function save(CacheableUri $uri, string $content, ?array $meta = null): bool
    {
        $path = $this->getFilePath($uri);

        if ($path === null) {
            return false;
        }

        FileHelper::createDirectory(dirname($path));

        // Write the expiry sidecar first — a failed content write can leave an
        // orphaned sidecar behind, which the sweep cleans up
        if (isset($meta['expires'])) {
            if (!$this->writeFileAtomic($path . self::META_SUFFIX, (string)json_encode(['expires' => (int)$meta['expires']]))) {
                return false;
            }
        }

        if (!$this->writeFileAtomic($path, $content)) {
            return false;
        }

        if (!isset($meta['expires'])) {
            @unlink($path . self::META_SUFFIX);
        }

        return true;
    }

    /**
     * Deletes the cached page (and its query string variants) for a single
     * path, leaving any cached child pages untouched.
     *
     * @param string $hostKey
     * @param string $path
     * @return bool Whether anything was deleted
     */
    public function deletePath(string $hostKey, string $path): bool
    {
        $dir = $this->getDirPath($hostKey, $path);

        if ($dir === null) {
            return false;
        }

        $deleted = false;
        $file = $dir . DIRECTORY_SEPARATOR . self::FILE_NAME;

        if (is_file($file) && @unlink($file)) {
            $deleted = true;
        }

        @unlink($file . self::META_SUFFIX);

        $queryDir = $dir . DIRECTORY_SEPARATOR . self::QUERY_DIR;

        if (is_dir($queryDir)) {
            try {
                FileHelper::removeDirectory($queryDir);
                $deleted = true;
            } catch (\Throwable $throwable) {
                Craft::error('Failed to delete cache directory "' . $queryDir . '": ' . $throwable->getMessage(), __METHOD__);
            }
        }

        return $deleted;
    }

    /**
     * Recursively purges a path — the page itself, its query string variants
     * and all cached descendant pages — by atomically moving its directory
     * into the trash area. Call deleteTrashed() (or push the
     * DeleteTrashedCacheDirs job) afterwards to reclaim disk space.
     *
     * @param string $hostKey
     * @param string $path
     * @return bool Whether anything was trashed
     */
    public function trashPath(string $hostKey, string $path): bool
    {
        $dir = $this->getDirPath($hostKey, $path);

        if ($dir === null || !is_dir($dir)) {
            return false;
        }

        return $this->moveToTrash($dir);
    }

    /**
     * Clears the entire cache by atomically moving all host directories into
     * the trash area. O(number of hosts), regardless of cache size.
     *
     * @return bool Whether anything was trashed
     */
    public function clearAll(): bool
    {
        $root = $this->getRootPath();

        if ($root === null || !is_dir($root)) {
            return false;
        }

        $trashed = false;

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $dir = $root . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($dir) && $this->moveToTrash($dir)) {
                $trashed = true;
            }
        }

        return $trashed;
    }

    /**
     * Deletes everything in the trash area. Idempotent — safe to run from
     * overlapping queue jobs.
     *
     * @return int The number of trashed directories deleted
     */
    public function deleteTrashed(): int
    {
        $trashPath = $this->getTrashPath();

        if ($trashPath === null || !is_dir($trashPath)) {
            return 0;
        }

        $deleted = 0;

        foreach (scandir($trashPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            try {
                FileHelper::removeDirectory($trashPath . DIRECTORY_SEPARATOR . $entry);
                ++$deleted;
            } catch (\Throwable $throwable) {
                Craft::error('Failed to delete trashed cache directory "' . $entry . '": ' . $throwable->getMessage(), __METHOD__);
            }
        }

        return $deleted;
    }

    /**
     * Deletes expired cached pages and orphaned expiry sidecars, and prunes
     * empty directories. Intended to run from cron via the
     * `cachemate/cache/sweep` console command — without it, pages served by
     * web server rewrites never expire.
     *
     * @return int The number of expired pages deleted
     */
    public function deleteExpired(): int
    {
        $root = $this->getRootPath();

        if ($root === null || !is_dir($root)) {
            return 0;
        }

        $globalDuration = ConfigHelper::durationInSeconds(CacheMate::getInstance()->getSettings()->cacheDuration);
        $now = time();
        $deleted = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $path = $item->getPathname();

            if ($item->isDir()) {
                // Empty directories are pruned; rmdir fails silently on
                // non-empty ones, which makes this race-safe
                @rmdir($path);

                continue;
            }

            if ($item->getFilename() === self::FILE_NAME) {
                $expires = $this->getSidecarExpiry($path);

                if ($expires !== null) {
                    $expired = $expires > 0 && $expires < $now;
                } else {
                    $expired = $globalDuration > 0 && $item->getMTime() + $globalDuration < $now;
                }

                if ($expired && @unlink($path)) {
                    @unlink($path . self::META_SUFFIX);
                    ++$deleted;
                }

                continue;
            }

            // Orphaned sidecars (no sibling cached file)
            if ($item->getFilename() === self::FILE_NAME . self::META_SUFFIX && !is_file(substr($path, 0, -strlen(self::META_SUFFIX)))) {
                @unlink($path);
            }
        }

        return $deleted;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the expiry timestamp from a cached file's sidecar, or null if
     * there is no (valid) sidecar. 0 means the page never expires.
     *
     * @param string $path The cached file's path
     * @return int|null
     */
    private function getSidecarExpiry(string $path): ?int
    {
        $metaPath = $path . self::META_SUFFIX;

        if (!is_file($metaPath)) {
            return null;
        }

        $meta = json_decode((string)@file_get_contents($metaPath), true);

        if (!is_array($meta) || !isset($meta['expires']) || !is_numeric($meta['expires'])) {
            return null;
        }

        return (int)$meta['expires'];
    }

    /**
     * Writes a file atomically (temp file + rename), so readers and web
     * server rewrites never see partial files.
     *
     * @param string $path
     * @param string $content
     * @return bool
     */
    private function writeFileAtomic(string $path, string $content): bool
    {
        $tempPath = $path . '.' . StringHelper::randomString(8) . '.tmp';

        if (@file_put_contents($tempPath, $content, LOCK_EX) === false) {
            Craft::error('Failed to write cache file "' . $tempPath . '"', __METHOD__);

            return false;
        }

        if (!@rename($tempPath, $path)) {
            Craft::error('Failed to move cache file into place at "' . $path . '"', __METHOD__);
            @unlink($tempPath);

            return false;
        }

        return true;
    }

    /**
     * Returns the cache directory for a host + path, derived via getFilePath()
     * so its traversal guards apply.
     *
     * @param string $hostKey
     * @param string $path
     * @return string|null
     */
    private function getDirPath(string $hostKey, string $path): ?string
    {
        $filePath = $this->getFilePath(new CacheableUri([
            'hostKey' => $hostKey,
            'path' => trim($path, '/'),
        ]));

        return $filePath !== null ? dirname($filePath) : null;
    }

    /**
     * Atomically moves a directory into the trash area.
     *
     * @param string $dir
     * @return bool
     */
    private function moveToTrash(string $dir): bool
    {
        $trashPath = $this->getTrashPath();

        if ($trashPath === null) {
            return false;
        }

        try {
            FileHelper::createDirectory($trashPath);
        } catch (\Throwable $throwable) {
            Craft::error('Failed to create trash directory "' . $trashPath . '": ' . $throwable->getMessage(), __METHOD__);

            return false;
        }

        $target = $trashPath . DIRECTORY_SEPARATOR . basename($dir) . '-' . StringHelper::randomString(8);

        if (!@rename($dir, $target)) {
            Craft::error('Failed to move "' . $dir . '" to trash', __METHOD__);

            return false;
        }

        return true;
    }

    /**
     * Returns the trash area path — a sibling of the cache root, so renames
     * are atomic (same filesystem) and trashed files can never be served by
     * web server rewrites.
     *
     * @return string|null
     */
    private function getTrashPath(): ?string
    {
        $root = $this->getRootPath();

        return $root !== null ? $root . '.trash' : null;
    }

    /**
     * Returns the normalized, alias-resolved cache root path.
     *
     * @return string|null
     */
    private function getRootPath(): ?string
    {
        $cachePath = Craft::getAlias(CacheMate::getInstance()->getSettings()->cachePath, false);

        if (!$cachePath) {
            return null;
        }

        return FileHelper::normalizePath($cachePath);
    }
}
