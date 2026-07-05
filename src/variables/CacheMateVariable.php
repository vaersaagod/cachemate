<?php

namespace vaersaagod\cachemate\variables;

use Craft;
use craft\helpers\Html;
use craft\helpers\Template;

use Twig\Markup;

use vaersaagod\cachemate\assets\FragmentAsset;
use vaersaagod\cachemate\CacheMate;
use vaersaagod\cachemate\helpers\FragmentHelper;

/**
 * The `craft.cachemate` template variable
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class CacheMateVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Opts the current request out of static caching, without affecting the
     * response's cache headers. To opt out *and* prevent downstream/browser
     * caching, use `craft.app.response.setNoCacheHeaders()` instead — CacheMate
     * respects that too.
     *
     * @return void
     */
    public function exclude(): void
    {
        CacheMate::getInstance()->getCacheRequest()->exclude();
    }

    /**
     * Overrides the cache duration for the current page. Accepts seconds, a
     * DateInterval duration string (e.g. 'PT1H'), or 0 for no expiry.
     *
     * Note that pages served directly by web server rewrites don't expire
     * until the `cachemate/cache/sweep` console command runs — schedule it
     * via cron.
     *
     * @param mixed $duration
     * @return void
     */
    public function setCacheDuration(mixed $duration): void
    {
        CacheMate::getInstance()->getCacheRequest()->setCacheDuration($duration);
    }

    /**
     * Outputs a placeholder that is replaced client-side with the rendered
     * template after page load — so the surrounding page can be statically
     * cached while this fragment stays dynamic.
     *
     * Params must be JSON-serializable scalars (or arrays of scalars) — pass
     * element IDs, not elements. Config keys: 'tag' (default 'div'),
     * 'placeholder' (raw fallback HTML shown until the fragment loads, and
     * kept if it fails), 'attributes' (attribute array for the wrapper).
     *
     * @param string $template A site template path
     * @param array $params
     * @param array $config
     * @return Markup
     * @throws \InvalidArgumentException
     * @throws \yii\base\InvalidConfigException
     */
    public function fragment(string $template, array $params = [], array $config = []): Markup
    {
        $url = FragmentHelper::getFragmentUrl($template, $params);

        $this->registerFragmentAsset();

        $attributes = array_merge($config['attributes'] ?? [], [
            'data-cachemate-fragment' => $url,
        ]);

        return Template::raw(Html::tag($config['tag'] ?? 'div', $config['placeholder'] ?? '', $attributes));
    }

    /**
     * Outputs a placeholder that is replaced client-side with a real CSRF
     * hidden input. Unlike `{{ csrfInput() }}`, this never generates a token
     * during the page render, so the page stays statically cacheable.
     *
     * @return Markup
     * @throws \yii\base\InvalidConfigException
     */
    public function csrfInput(): Markup
    {
        $this->registerFragmentAsset();

        return Template::raw(Html::tag('span', '', [
            'data-cachemate-csrf' => FragmentHelper::getCsrfInputUrl(),
            'hidden' => true,
        ]));
    }

    // Private Methods
    // =========================================================================

    /**
     * Registers the fragment inject script (idempotent).
     *
     * @return void
     * @throws \yii\base\InvalidConfigException
     */
    private function registerFragmentAsset(): void
    {
        Craft::$app->getView()->registerAssetBundle(FragmentAsset::class);
    }
}
