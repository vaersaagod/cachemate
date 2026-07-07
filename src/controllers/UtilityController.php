<?php

namespace vaersaagod\cachemate\controllers;

use Craft;
use craft\web\Controller;

use vaersaagod\cachemate\CacheMate;
use vaersaagod\cachemate\utilities\CacheMateUtility;

use yii\web\Response;

/**
 * Handles actions from the CacheMate CP utility.
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class UtilityController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Clears the entire static page cache.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:' . CacheMateUtility::id());

        $purge = CacheMate::getInstance()->getCachePurge();
        $purge->purgeAll();
        $purge->flush();

        Craft::$app->getSession()->setNotice(Craft::t('cachemate', 'Static page cache cleared.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Purges a single entry's cached pages, exactly like saving it would
     * (purge rules apply). Backs the entry sidebar button.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\web\ForbiddenHttpException
     * @throws \yii\web\NotFoundHttpException
     */
    public function actionPurgeEntry(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $elementId = (int)$this->request->getRequiredBodyParam('elementId');
        $element = Craft::$app->getElements()->getElementById($elementId);

        if ($element === null) {
            throw new \yii\web\NotFoundHttpException('Element not found');
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || !$element->canView($user)) {
            throw new \yii\web\ForbiddenHttpException('User is not permitted to purge this element');
        }

        $purge = CacheMate::getInstance()->getCachePurge();
        $purge->purgeElement($element);
        $purge->flush();

        return $this->asSuccess(Craft::t('cachemate', 'Purged from the static cache.'));
    }

    /**
     * Deletes expired cached pages.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionSweep(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:' . CacheMateUtility::id());

        $deleted = CacheMate::getInstance()->getCacheStorage()->deleteExpired();

        Craft::$app->getSession()->setNotice(Craft::t('cachemate', '{count,plural,=0{No expired pages} =1{1 expired page} other{# expired pages}} deleted.', [
            'count' => $deleted,
        ]));

        return $this->redirectToPostedUrl();
    }
}
