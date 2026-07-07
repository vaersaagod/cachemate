<?php

namespace vaersaagod\cachemate\services;

use Craft;
use craft\events\ExceptionEvent;
use craft\helpers\ConfigHelper;
use craft\web\Request;
use craft\web\Response;
use craft\web\TemplateResponseFormatter;

use vaersaagod\cachemate\CacheMate;
use vaersaagod\cachemate\helpers\RequestHelper;
use vaersaagod\cachemate\helpers\SiteHelper;
use vaersaagod\cachemate\helpers\UriHelper;
use vaersaagod\cachemate\models\CacheableUri;

use yii\base\Component;
use yii\web\HttpException;

/**
 * Orchestrates static cache request handling: candidacy, early serving of
 * cached pages, and capturing rendered pages into the cache.
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class CacheRequestService extends Component
{
    // Private Properties
    // =========================================================================

    /** @var CacheableUri|null The memoized cache candidacy result for the current request */
    private ?CacheableUri $_cacheableUri = null;

    /** @var bool Whether candidacy has been resolved for the current request */
    private bool $_resolved = false;

    /** @var bool Whether the current request has been opted out at runtime (e.g. from a template) */
    private bool $_excluded = false;

    /** @var mixed A runtime per-page cache duration override (e.g. from a template) */
    private mixed $_cacheDuration = null;

    /** @var bool Whether a cached response is being served (capture handlers bail) */
    private bool $_serving = false;

    /** @var string|null The reason the current request isn't a cache candidate */
    private ?string $_bypassReason = null;

    // Public Methods
    // =========================================================================

    /**
     * Handles the current request: serves a cached page if one exists (ending
     * the request), or attaches the capture handler if the request is a cache
     * candidate. No-op for non-candidates.
     *
     * @return void
     */
    public function handleRequest(): void
    {
        $uri = $this->getCacheableUri();

        if ($uri === null) {
            // Not a candidate — send the bypass state on front-end responses
            // only, never on CP or action responses
            $request = Craft::$app->getRequest();

            if ($request instanceof Request && $request->getIsSiteRequest() && !$request->getIsCpRequest() && !$request->getIsActionRequest()) {
                $this->setStateHeader('bypass', $this->_bypassReason);
            }

            return;
        }

        if (CacheMate::getInstance()->getSettings()->serveWithPhp) {
            $this->serveCachedResponse($uri);
        }

        // The request is a cache candidate, but wasn't served from the cache
        $this->setStateHeader('miss');

        // Only capture GET requests (HEAD responses have no body to store)
        if (Craft::$app->getRequest()->getIsGet()) {
            $this->attachCaptureHandler($uri);
        }
    }

    /**
     * Returns the normalized cacheable URI for the current request, or null if
     * the request is not a cache candidate. Memoized; runs the request-level
     * (Phase A) and URI-level (Phase B) checks exactly once, shared by the
     * serve and capture paths.
     *
     * @return CacheableUri|null
     */
    public function getCacheableUri(): ?CacheableUri
    {
        if ($this->_resolved) {
            return $this->_cacheableUri;
        }

        $this->_resolved = true;

        $request = Craft::$app->getRequest();

        if (!$request instanceof Request) {
            return null;
        }

        $settings = CacheMate::getInstance()->getSettings();
        $this->_bypassReason = RequestHelper::getUncacheableRequestReason($request, $settings);

        if ($this->_bypassReason !== null) {
            return null;
        }

        $uri = UriHelper::createCacheableUri($request, $settings, $uriReason);

        if ($uri === null) {
            $this->_bypassReason = $uriReason ?? 'uri';
        }

        return $this->_cacheableUri = $uri;
    }

    /**
     * Serves the cached page for a URI, if a fresh one exists, and ends the
     * request. Returns normally on a cache miss.
     *
     * @param CacheableUri $uri
     * @param int $statusCode
     * @return void
     */
    public function serveCachedResponse(CacheableUri $uri, int $statusCode = 200): void
    {
        $content = CacheMate::getInstance()->getCacheStorage()->get($uri);

        if ($content === null) {
            return;
        }

        $settings = CacheMate::getInstance()->getSettings();
        $response = Craft::$app->getResponse();

        $this->_serving = true;

        $response->format = Response::FORMAT_RAW;
        $response->setStatusCode($statusCode);
        $response->content = Craft::$app->getRequest()->getIsHead() ? '' : $content;

        $headers = $response->getHeaders();
        $headers->set('Content-Type', 'text/html; charset=UTF-8');
        // Only 200s get the long-lived cache header — proxies/CDNs must not pin error pages to a URI
        $headers->set('Cache-Control', $statusCode === 200 ? $settings->cacheControlHeader : 'no-cache');
        $headers->set('X-CacheMate', 'hit');

        // Defensive — a cached response must never set cookies
        $response->getCookies()->removeAll();
        $headers->remove('Set-Cookie');

        $response->send();

        exit(0);
    }

    /**
     * Handles a thrown exception: serves the cached 404 page for the current
     * site if a fresh one exists (ending the request), or attaches the 404
     * capture handler. No-op for anything but cacheable 404s.
     *
     * Note: this handler is attached from Craft::$app->onInit() — after every
     * plugin's init() — so it always runs AFTER RedirectMate's exception
     * handler. A matched redirect ends the process before we get here; 404
     * tracking has already happened.
     *
     * @param ExceptionEvent $event
     * @return void
     */
    public function handleNotFoundException(ExceptionEvent $event): void
    {
        $exception = $event->exception;

        // The event fires before Craft unwraps Twig runtime errors
        if ($exception instanceof \Twig\Error\RuntimeError && $exception->getPrevious() !== null) {
            $exception = $exception->getPrevious();
        }

        if (!$exception instanceof HttpException || $exception->statusCode !== 404) {
            return;
        }

        $response = Craft::$app->getResponse();

        if (!$response instanceof Response || $response->isSent) {
            return;
        }

        $request = Craft::$app->getRequest();
        $settings = CacheMate::getInstance()->getSettings();

        if (
            !$request instanceof Request
            || !$settings->getLocalizedConfigSetting('cache404s')
            || !RequestHelper::isCacheableRequest($request, $settings)
        ) {
            return;
        }

        // URI-level checks; query string rules are skipped, since the cached
        // 404 page is shared for the whole site anyway
        if (strlen($request->getUrl()) > $settings->maxUriLength) {
            return;
        }

        $excludedPatterns = $settings->getLocalizedConfigSetting('excludedUriPatterns');

        if (!empty($excludedPatterns) && UriHelper::matchesUriPatterns('/' . $request->getFullPath(), $excludedPatterns)) {
            return;
        }

        $uri = $this->getNotFoundUri();

        if ($uri === null) {
            return;
        }

        $this->serveCachedResponse($uri, 404);

        if ($request->getIsGet()) {
            $this->attachNotFoundCaptureHandler($uri);
        }
    }

    /**
     * Attaches the capture handler to the current response. Rendered pages
     * that pass the response-level (Phase C) checks are stored in the cache;
     * the response itself is sent to the visitor untouched.
     *
     * @param CacheableUri $uri
     * @return void
     */
    public function attachCaptureHandler(CacheableUri $uri): void
    {
        Craft::$app->getResponse()->on(Response::EVENT_AFTER_PREPARE, function() use ($uri): void {
            if ($this->_serving) {
                return;
            }

            $response = Craft::$app->getResponse();
            $reason = $this->getUncacheableResponseReason($response);

            if ($reason !== null) {
                Craft::info('Not caching "' . $uri->getKey() . '" (reason: ' . $reason . ')', __METHOD__);
                $this->setStateHeader('miss', $reason);

                return;
            }

            $meta = $this->getSaveMeta($this->_cacheDuration);

            if (CacheMate::getInstance()->getCacheStorage()->save($uri, (string)$response->content, $meta)) {
                Craft::info('Cached "' . $uri->getKey() . '"', __METHOD__);
            }
        });
    }

    /**
     * Overrides the cache duration for the current page. Backs the
     * `craft.cachemate.setCacheDuration()` template variable.
     *
     * Note that pages served directly by web server rewrites don't expire
     * until the `cachemate/cache/sweep` console command runs (or the page is
     * purged) — schedule it via cron.
     *
     * @param mixed $duration Seconds, a DateInterval duration string, or 0 for no expiry
     * @return void
     */
    public function setCacheDuration(mixed $duration): void
    {
        $this->_cacheDuration = $duration;
    }

    /**
     * Returns whether a prepared response is cacheable (the Phase C checks).
     *
     * @param Response $response
     * @return bool
     */
    public function isCacheableResponse(Response $response): bool
    {
        return $this->getUncacheableResponseReason($response) === null;
    }

    /**
     * Opts the current request out of static caching, without affecting the
     * response's cache headers. Backs the `craft.cachemate.exclude()`
     * template variable.
     *
     * @return void
     */
    public function exclude(): void
    {
        $this->_excluded = true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the normalized cache location for the current site's shared 404
     * page, or null if it can't be derived. Memoized.
     *
     * @return CacheableUri|null
     */
    private function getNotFoundUri(): ?CacheableUri
    {
        try {
            $site = Craft::$app->getSites()->getCurrentSite();
        } catch (\Throwable) {
            return null;
        }

        $sitePrefix = SiteHelper::getSiteCachePrefix($site);

        if ($sitePrefix === null) {
            return null;
        }

        $path = $sitePrefix['prefix'] !== ''
            ? $sitePrefix['prefix'] . '/' . CacheStorageService::NOT_FOUND_DIR
            : CacheStorageService::NOT_FOUND_DIR;

        return new CacheableUri([
            'hostKey' => $sitePrefix['hostKey'],
            'path' => $path,
            'siteId' => $site->id,
        ]);
    }

    /**
     * Attaches the 404 capture handler to the current response, storing the
     * rendered error page as the site's shared cached 404.
     *
     * @param CacheableUri $uri
     * @return void
     */
    private function attachNotFoundCaptureHandler(CacheableUri $uri): void
    {
        Craft::$app->getResponse()->on(Response::EVENT_AFTER_PREPARE, function() use ($uri): void {
            if ($this->_serving) {
                return;
            }

            $response = Craft::$app->getResponse();
            $reason = $this->getUncacheableResponseReason($response, 404);

            if ($reason !== null) {
                Craft::info('Not caching the 404 page "' . $uri->getKey() . '" (reason: ' . $reason . ')', __METHOD__);

                return;
            }

            $meta = $this->getSaveMeta(CacheMate::getInstance()->getSettings()->cache404Duration);

            if (CacheMate::getInstance()->getCacheStorage()->save($uri, (string)$response->content, $meta)) {
                Craft::info('Cached the 404 page "' . $uri->getKey() . '"', __METHOD__);
            }
        });
    }

    /**
     * Returns the expiry meta to save a page with, or null when the given
     * duration doesn't differ from the global default (no sidecar needed).
     *
     * @param mixed $duration
     * @return array|null
     */
    private function getSaveMeta(mixed $duration): ?array
    {
        if ($duration === null) {
            return null;
        }

        $seconds = ConfigHelper::durationInSeconds($duration);
        $globalSeconds = ConfigHelper::durationInSeconds(CacheMate::getInstance()->getSettings()->cacheDuration);

        if ($seconds === $globalSeconds) {
            return null;
        }

        return ['expires' => $seconds > 0 ? time() + $seconds : 0];
    }

    /**
     * Returns the reason a prepared response can't be cached, or null if it's
     * cacheable.
     *
     * @param Response $response
     * @param int $expectedStatus
     * @return string|null
     */
    private function getUncacheableResponseReason(Response $response, int $expectedStatus = 200): ?string
    {
        if ($this->_excluded) {
            return 'opt-out';
        }

        if ($response->getStatusCode() !== $expectedStatus) {
            return 'status';
        }

        if ($response->format !== TemplateResponseFormatter::FORMAT && $response->format !== Response::FORMAT_HTML) {
            return 'format';
        }

        if (!str_starts_with($response->getContentType() ?? '', 'text/html')) {
            return 'format';
        }

        if ($response->stream !== null || !is_string($response->content) || $response->content === '') {
            return 'content';
        }

        $headers = $response->getHeaders();
        $cacheControl = implode(',', $headers->get('cache-control', [], false) ?? []);

        if (preg_match('/no-cache|no-store|private/i', $cacheControl)) {
            return 'no-cache-headers';
        }

        if ($response->getCookies()->getCount() > 0 || !empty($headers->get('set-cookie', null, false))) {
            return 'sets-cookies';
        }

        if ($headers->get('content-encoding') !== null) {
            return 'encoded';
        }

        if (str_contains($response->content, 'craft\auth\methods\TOTP')) {
            return 'totp';
        }

        return null;
    }

    /**
     * Sets the X-CacheMate response header. The reason is only included when
     * debug headers are enabled.
     *
     * @param string $state
     * @param string|null $reason
     * @return void
     */
    private function setStateHeader(string $state, ?string $reason = null): void
    {
        if ($reason !== null && $this->getIncludeHeaderReasons()) {
            $state .= '; ' . $reason;
        }

        Craft::$app->getResponse()->getHeaders()->set('X-CacheMate', $state);
    }

    /**
     * Returns whether X-CacheMate headers should include reason keywords, per
     * the debugHeaders setting ('auto' = only when devMode is enabled).
     *
     * @return bool
     */
    private function getIncludeHeaderReasons(): bool
    {
        $setting = CacheMate::getInstance()->getSettings()->debugHeaders;

        if ($setting === 'auto') {
            return Craft::$app->getConfig()->getGeneral()->devMode;
        }

        return (bool)$setting;
    }
}
