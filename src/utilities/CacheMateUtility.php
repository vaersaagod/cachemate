<?php

namespace vaersaagod\cachemate\utilities;

use Craft;
use craft\base\Utility;

use vaersaagod\cachemate\CacheMate;

/**
 * The CacheMate CP utility — cache statistics, clearing and sweeping.
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class CacheMateUtility extends Utility
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('cachemate', 'CacheMate');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'cachemate';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return 'bolt';
    }

    /**
     * @inheritdoc
     * @throws \Throwable
     */
    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('cachemate/utility.twig', [
            'stats' => CacheMate::getInstance()->getCacheStorage()->getStats(),
            'settings' => CacheMate::getInstance()->getSettings(),
        ]);
    }
}
