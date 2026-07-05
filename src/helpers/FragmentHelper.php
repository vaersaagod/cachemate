<?php

namespace vaersaagod\cachemate\helpers;

use Craft;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;

/**
 * Builds and validates signed, stateless fragment URLs.
 *
 * The fragment template, params and site are encoded into the URL itself and
 * signed with a keyed HMAC (Craft's security key) — no database records. A
 * fragment URL can only exist if a developer actually output it from a
 * template; tampering with any part of the payload invalidates the signature.
 *
 * Note that rotating the security key invalidates every fragment URL baked
 * into cached pages (placeholders fall back to their fallback content) —
 * clear the cache after rotating.
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class FragmentHelper
{
    // Const Properties
    // =========================================================================

    /** @var string The query param carrying the signed payload */
    public const PAYLOAD_PARAM = 'payload';

    /** @var int Maximum length of a generated fragment URL */
    public const MAX_URL_LENGTH = 2048;

    // Public Methods
    // =========================================================================

    /**
     * Returns the signed, root-relative fragment render URL for a template.
     *
     * @param string $template A site template path
     * @param array $params Template variables — JSON-serializable scalars (or arrays of scalars) only
     * @param int|null $siteId The site to render the fragment for (defaults to the current site)
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function getFragmentUrl(string $template, array $params = [], ?int $siteId = null): string
    {
        static::validateTemplate($template);
        static::validateParams($params);

        if ($siteId === null) {
            try {
                $siteId = Craft::$app->getSites()->getCurrentSite()->id;
            } catch (\Throwable) {
                $siteId = null;
            }
        }

        $payload = StringHelper::base64UrlEncode(Json::encode([
            't' => $template,
            'p' => $params,
            's' => $siteId,
        ]));

        $url = UrlHelper::rootRelativeUrl(UrlHelper::actionUrl('cachemate/fragment/render', [
            self::PAYLOAD_PARAM => Craft::$app->getSecurity()->hashData($payload),
        ]));

        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw new \InvalidArgumentException('The fragment URL for "' . $template . '" exceeds ' . self::MAX_URL_LENGTH . ' characters — pass IDs, not data.');
        }

        return $url;
    }

    /**
     * Returns the root-relative CSRF input action URL.
     *
     * @return string
     */
    public static function getCsrfInputUrl(): string
    {
        return UrlHelper::rootRelativeUrl(UrlHelper::actionUrl('cachemate/csrf/input'));
    }

    /**
     * Verifies and decodes a signed fragment payload. Returns null on any
     * failure (bad signature, bad JSON, bad shape).
     *
     * @param string $signedPayload
     * @return array{template: string, params: array, siteId: int|null}|null
     */
    public static function validatePayload(string $signedPayload): ?array
    {
        $payload = Craft::$app->getSecurity()->validateData($signedPayload);

        if (!is_string($payload)) {
            return null;
        }

        try {
            $data = Json::decode(StringHelper::base64UrlDecode($payload));
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($data) || !is_string($data['t'] ?? null) || !is_array($data['p'] ?? null)) {
            return null;
        }

        try {
            static::validateTemplate($data['t']);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $siteId = $data['s'] ?? null;

        return [
            'template' => $data['t'],
            'params' => $data['p'],
            'siteId' => is_numeric($siteId) ? (int)$siteId : null,
        ];
    }

    /**
     * Ensures fragment params are JSON-serializable scalars (or arrays of
     * scalars), recursively.
     *
     * @param array $params
     * @return void
     * @throws \InvalidArgumentException
     */
    public static function validateParams(array $params): void
    {
        foreach ($params as $value) {
            if (is_array($value)) {
                static::validateParams($value);

                continue;
            }

            if ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException('Fragment params must be scalars or arrays of scalars — pass element IDs, not elements.');
            }
        }
    }

    /**
     * Ensures a fragment template path is a safe, relative site template path.
     * Applied both when signing and when rendering (defense in depth behind
     * the signature).
     *
     * @param string $template
     * @return void
     * @throws \InvalidArgumentException
     */
    public static function validateTemplate(string $template): void
    {
        if (
            $template === ''
            || str_starts_with($template, '/')
            || str_contains($template, '..')
            || str_contains($template, "\0")
        ) {
            throw new \InvalidArgumentException('Invalid fragment template path "' . $template . '".');
        }
    }
}
