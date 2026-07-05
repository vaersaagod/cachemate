<?php

namespace vaersaagod\cachemate\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;

use vaersaagod\cachemate\CacheMate;

use yii\console\ExitCode;

/**
 * Manages the CacheMate static page cache.
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class CacheController extends Controller
{
    // Public Properties
    // =========================================================================

    /** @var string|null The site handle to purge the path for (all sites if omitted) */
    public ?string $site = null;

    /** @var bool Whether cached descendant pages are purged too */
    public bool $recursive = false;

    /** @var string */
    public $defaultAction = 'clear';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'purge') {
            $options[] = 'site';
            $options[] = 'recursive';
        }

        return $options;
    }

    /**
     * Clears the entire static page cache.
     *
     * @return int
     */
    public function actionClear(): int
    {
        $storage = CacheMate::getInstance()->getCacheStorage();

        $storage->clearAll();
        $deleted = $storage->deleteTrashed();

        $this->stdout('Cleared the static page cache (' . $deleted . ' trashed director' . ($deleted === 1 ? 'y' : 'ies') . ' deleted).' . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Deletes expired cached pages and orphaned meta files, and prunes empty
     * directories. Intended to run from cron — without it, pages served by
     * web server rewrites never expire.
     *
     * @return int
     */
    public function actionSweep(): int
    {
        $deleted = CacheMate::getInstance()->getCacheStorage()->deleteExpired();

        $this->stdout('Deleted ' . $deleted . ' expired cached page' . ($deleted === 1 ? '' : 's') . '.' . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Purges a site-relative path from the static page cache, e.g.
     * `cachemate/cache/purge /news --recursive --site=english`.
     *
     * @param string $path
     * @return int
     */
    public function actionPurge(string $path): int
    {
        $siteId = null;

        if ($this->site !== null) {
            $site = Craft::$app->getSites()->getSiteByHandle($this->site, true);

            if ($site === null) {
                $this->stderr('No site exists with the handle "' . $this->site . '".' . PHP_EOL, Console::FG_RED);

                return ExitCode::UNSPECIFIED_ERROR;
            }

            $siteId = $site->id;
        }

        $purge = CacheMate::getInstance()->getCachePurge();
        $storage = CacheMate::getInstance()->getCacheStorage();

        $purge->purgePath($path, $siteId, $this->recursive);
        $purge->flush();
        $storage->deleteTrashed();

        $this->stdout('Purged "' . $path . '"' . ($this->recursive ? ' recursively' : '') . '.' . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
