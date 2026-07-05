<?php

namespace vaersaagod\cachemate\assets;

use craft\web\AssetBundle;
use craft\web\View;

/**
 * The fragment inject script bundle. Published as an external file (not
 * inlined), so it works with strict Content-Security-Policy setups.
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class FragmentAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@vaersaagod/cachemate/resources';
        $this->js = ['fragments.js'];
        $this->jsOptions = ['position' => View::POS_END];

        parent::init();
    }
}
