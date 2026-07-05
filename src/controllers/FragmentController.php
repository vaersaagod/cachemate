<?php

namespace vaersaagod\cachemate\controllers;

use Craft;
use craft\helpers\FileHelper;
use craft\web\Controller;
use craft\web\View;

use vaersaagod\cachemate\helpers\FragmentHelper;

use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Renders dynamic fragments for statically cached pages.
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class FragmentController extends Controller
{
    // Protected Properties
    // =========================================================================

    /** @inheritdoc */
    protected array|bool|int $allowAnonymous = ['render'];

    // Public Methods
    // =========================================================================

    /**
     * Renders a fragment template from a signed payload. Fragment responses
     * are never cached — by CacheMate (action requests aren't candidates) or
     * by browsers/proxies (no-cache headers).
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     * @throws \Twig\Error\Error
     * @throws \yii\base\Exception
     */
    public function actionRender(): Response
    {
        if (!$this->request->getIsGet()) {
            throw new BadRequestHttpException('Fragments can only be requested with GET');
        }

        $data = FragmentHelper::validatePayload((string)$this->request->getRequiredQueryParam(FragmentHelper::PAYLOAD_PARAM));

        if ($data === null) {
            throw new BadRequestHttpException('Invalid fragment payload (has the security key been rotated?)');
        }

        if ($data['siteId'] !== null) {
            $site = Craft::$app->getSites()->getSiteById($data['siteId'], true);

            if ($site !== null) {
                Craft::$app->getSites()->setCurrentSite($site);
                Craft::$app->language = $site->language;
            }
        }

        $view = Craft::$app->getView();
        $resolvedTemplate = $view->resolveTemplate($data['template'], View::TEMPLATE_MODE_SITE);

        if ($resolvedTemplate === false) {
            throw new NotFoundHttpException('Fragment template not found');
        }

        // Defense in depth behind the payload signature — fragments can only
        // render site templates
        $siteTemplatesPath = FileHelper::normalizePath(Craft::$app->getPath()->getSiteTemplatesPath());

        if (!str_starts_with(FileHelper::normalizePath($resolvedTemplate), $siteTemplatesPath . DIRECTORY_SEPARATOR)) {
            throw new BadRequestHttpException('Invalid fragment template');
        }

        $html = $view->renderTemplate($data['template'], $data['params'], View::TEMPLATE_MODE_SITE);

        $this->response->setNoCacheHeaders();
        $this->response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');

        return $this->asRaw($html);
    }
}
