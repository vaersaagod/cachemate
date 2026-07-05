<?php

namespace vaersaagod\cachemate\controllers;

use Craft;
use craft\helpers\Html;
use craft\web\Controller;

use yii\web\Response;

/**
 * Serves CSRF inputs for statically cached pages.
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class CsrfController extends Controller
{
    // Protected Properties
    // =========================================================================

    /** @inheritdoc */
    protected array|bool|int $allowAnonymous = ['input'];

    // Public Methods
    // =========================================================================

    /**
     * Returns a rendered CSRF hidden input. The response sets the CSRF cookie
     * — this is an action request, so it's never statically cached, and the
     * CSRF cookie doesn't bypass the cache for subsequent page views.
     *
     * @return Response
     */
    public function actionInput(): Response
    {
        $request = Craft::$app->getRequest();

        if (!$request->enableCsrfCookie) {
            // With enableCsrfCookie disabled, the token lives in the PHP
            // session — which starts a session and makes this visitor bypass
            // the static cache from here on
            Craft::warning('CSRF tokens are stored in the PHP session (enableCsrfCookie is disabled) — visitors requesting CSRF inputs will bypass the static cache.', __METHOD__);
        }

        $html = Html::hiddenInput($request->csrfParam, $request->getCsrfToken());

        $this->response->setNoCacheHeaders();
        $this->response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');

        return $this->asRaw($html);
    }
}
