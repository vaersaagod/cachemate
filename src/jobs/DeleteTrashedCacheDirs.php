<?php

namespace vaersaagod\cachemate\jobs;

use craft\i18n\Translation;
use craft\queue\BaseJob;

use vaersaagod\cachemate\CacheMate;

/**
 * Deletes trashed cache directories. Purging moves directories into a trash
 * area with atomic renames (O(1)); this job reclaims the disk space
 * asynchronously. Idempotent — overlapping jobs are harmless.
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class DeleteTrashedCacheDirs extends BaseJob
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        CacheMate::getInstance()->getCacheStorage()->deleteTrashed();
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('cachemate', 'Deleting trashed CacheMate cache directories');
    }
}
