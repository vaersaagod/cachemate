<?php

namespace vaersaagod\cachemate\helpers;

use Craft;
use craft\web\Request;

use vaersaagod\cachemate\models\Settings;

/**
 * Request-level (Phase A) cache candidacy checks.
 *
 * Everything in here must be evaluable without booting the PHP session,
 * querying the database, or validating tokens — these checks gate both the
 * early PHP serve and whether the capture handler is attached at all.
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class RequestHelper
{
    // Public Methods
    // =========================================================================

    /**
     * Returns whether the current request is a candidate for static caching.
     *
     * @param Request $request
     * @param Settings $settings
     * @return bool
     */
    public static function isCacheableRequest(Request $request, Settings $settings): bool
    {
        return static::getUncacheableRequestReason($request, $settings) === null;
    }

    /**
     * Returns the reason the current request is not a candidate for static
     * caching (a keyword, used in logs and the X-CacheMate debug header), or
     * null if it is a candidate.
     *
     * @param Request $request
     * @param Settings $settings
     * @return string|null
     */
    public static function getUncacheableRequestReason(Request $request, Settings $settings): ?string
    {
        // Only GET (serve + capture) and HEAD (serve only) requests are candidates
        if (!$request->getIsGet() && !$request->getIsHead()) {
            return 'method';
        }

        // Never on uninstalled or updating installs
        if (!Craft::$app->getIsInstalled()) {
            return 'system';
        }

        // Site requests only — never the control panel
        if (!$request->getIsSiteRequest() || $request->getIsCpRequest()) {
            return 'cp';
        }

        // Never action requests (also covers login/logout/set-password/update special paths)
        if ($request->getIsActionRequest() || $request->getIsLoginRequest()) {
            return 'action';
        }

        // Never previews. Raw param/header checks (rather than getIsPreview(), which
        // validates) so that even invalid preview params bypass the cache.
        if (
            $request->getIsLivePreview()
            || $request->getQueryParam('x-craft-preview') !== null
            || $request->getQueryParam('x-craft-live-preview') !== null
            || $request->getHeaders()->has('X-Craft-Preview-Token')
        ) {
            return 'preview';
        }

        // Never tokenized requests. Presence checks only — validating the token
        // would hit the database and consume single-use tokens.
        $generalConfig = Craft::$app->getConfig()->getGeneral();

        if (
            $request->getQueryParam($generalConfig->tokenParam) !== null
            || $request->getHeaders()->has('X-Craft-Token')
            || $request->getSiteToken() !== null
        ) {
            return 'token';
        }

        // Never for requests carrying a Craft identity or PHP session cookie —
        // logged-in users always see live pages, and anonymous sessions can carry
        // flash messages and per-session CSRF state
        if (static::hasUserSessionCookie()) {
            return 'session';
        }

        // Support a ?no-cache bypass param, but only in devMode
        if ($generalConfig->devMode && $request->getQueryParam('no-cache') !== null) {
            return 'no-cache';
        }

        // Per-site enable/disable
        if (!$settings->getLocalizedConfigSetting('enabled')) {
            return 'disabled';
        }

        return null;
    }

    /**
     * Returns whether the request carries a Craft identity cookie or a PHP
     * session cookie, without ever touching the user or session components
     * (which would boot the session).
     *
     * @return bool
     */
    public static function hasUserSessionCookie(): bool
    {
        // The identity cookie name, as derived in \craft\helpers\App::userConfig()
        $identityCookieName = md5('Craft.' . \yii\web\User::class . '.' . Craft::$app->id) . '_identity';

        if (isset($_COOKIE[$identityCookieName])) {
            return true;
        }

        $sessionCookieName = Craft::$app->getConfig()->getGeneral()->phpSessionName;

        return isset($_COOKIE[$sessionCookieName]);
    }
}
