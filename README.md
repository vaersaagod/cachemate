# CacheMate

Cache me if you can, mate!

CacheMate statically caches rendered pages as HTML files that your web server
can serve directly — no PHP, no database, no Craft. A PHP early-serve fallback
handles installs (or requests) the web server rewrite doesn't cover.

![CacheMate logo](resources/img/plugin-logo.png)

## Requirements

This plugin requires Craft CMS 5.4.0 or later, and PHP 8.2 or later.

---

## Price, license and support

The plugin is released under the Craft license and could be subject to license fees.
It's made for Værsågod and friends, and no support is given. Submitted issues are resolved if it scratches an itch.

## Installation

To install the plugin, follow these instructions:

1. Install with composer via `composer require vaersaagod/cachemate` from your project directory.
2. Install the plugin in the Craft Control Panel under Settings → Plugins, or from the command line via `./craft plugin/install cachemate`.
3. Add `/web/cachemate` (or your configured `cachePath`) to your project's `.gitignore`.
4. Optionally add the web server rewrite (below) so cache hits never touch PHP.

## How it works

Caching is **opt-out**: every qualifying site request is cached by default, and
you exclude what shouldn't be with `excludedUriPatterns` or the template
opt-outs.

A request is a cache candidate when **all** of the following hold:

- It's a front-end `GET` request (`HEAD` is served from cache, never captured)
- It's not a control panel, action, login, preview or tokenized request
- The visitor has no Craft identity or PHP session cookie (logged-in users —
  and anonymous sessions, which can carry flash messages and CSRF state —
  always get live pages)
- The URI doesn't match `excludedUriPatterns` and passes the query string rules
- Caching is `enabled` for the current site

A rendered response is stored when **all** of the following hold:

- The status is 200 and the response is a template-rendered HTML page
- The response doesn't set cookies (a page rendering `{{ csrfInput() }}` sets
  the CSRF cookie, and its markup contains a per-user token — such pages are
  skipped, with the reason logged to `storage/logs/cachemate-*.log`)
- The response has no `no-cache`/`no-store`/`private` cache headers
- The page hasn't opted out (see below)

With `devMode` enabled, appending `?no-cache` to any URL bypasses the cache
entirely (nothing served, nothing stored).

## Opting out from templates

Two mechanisms:

```twig
{# Don't statically cache this page, and tell browsers/proxies not to cache it either #}
{% do craft.app.response.setNoCacheHeaders() %}

{# Don't statically cache this page, but leave the response headers alone #}
{% do craft.cachemate.exclude() %}
```

## Configuration

CacheMate can be configured by creating a file named `cachemate.php` in your
Craft config folder. See [src/config.php](src/config.php) for a commented
example of every setting.

### Query strings

By default, requests with query strings are **not** cached (marketing params
like `utm_*`, `gclid` and `fbclid` are ignored, so those URLs still hit the
cache for the bare URI). Set `cacheQueryStrings` to `true` to cache
query-string URLs as unique pages, or to an array of param names to whitelist:

```php
// Only `page` participates in the cache key; other params are stripped from the key
'cacheQueryStrings' => ['page'],

// ...or, make the whitelist strict: requests with any OTHER param aren't cached at all
'cacheQueryStrings' => ['page'],
'strictQueryParams' => true,
```

Query string params are always sorted, so `?a=1&b=2` and `?b=2&a=1` share one
cache entry.

## Cache duration & sweeping

By default (`cacheDuration => 0`), cached pages live until they're purged.
A global `cacheDuration` or a per-page override sets an expiry instead:

```twig
{# This page expires after an hour #}
{% do craft.cachemate.setCacheDuration('PT1H') %}
```

Per-page expiries are stored in small `index.html.meta` sidecar files (only
written when the TTL differs from the global default). The PHP serve path
respects expiries automatically — but pages served directly by web server
rewrites don't expire until the sweep runs. If you use `cacheDuration` or
`setCacheDuration()` together with the nginx rewrite, schedule the sweep:

```cron
*/15 * * * * php craft cachemate/cache/sweep
```

The sweep deletes expired pages, orphaned sidecars and empty directories.

## Cached 404s

With `'cache404s' => true`, CacheMate caches **one 404 page per site** (never
per URI — bots probing random URLs can't flood the cache) and serves it for
subsequent 404s, skipping the error template render and its queries. Craft
still boots and plugins still run — notably, RedirectMate's redirects always
take priority over the cached 404, and its 404 tracking keeps working.

⚠️ Only enable this if your 404 template does **not** render the requested
URL/path or anything else request-specific — the first rendered 404 page is
served for every subsequent 404 on the site (for `cache404Duration`, default
one hour).

Cached 404s are always served via PHP with `Cache-Control: no-cache` (so
proxies never pin an error page to a URL), and are removed by full cache
clears. To purge one manually: `php craft cachemate/cache/purge /__404__`.

## Dynamic fragments & forms on cached pages

Pages that render `{{ csrfInput() }}` or per-user content are normally never
cached. Fragments fix that: the dynamic bits are deferred to the browser and
fetched after page load, so the page itself stays fully static.

```twig
{# A statically cached page with a working form: #}
<form method="post">
    {{ actionInput('some/action') }}
    {{ craft.cachemate.csrfInput() }}
    ...
</form>

{# A dynamic fragment, rendered client-side after load: #}
{{ craft.cachemate.fragment('_fragments/cart-status', { entryId: entry.id }, {
    placeholder: '<span>Loading…</span>',
}) }}
```

How it works:

- `fragment(template, params, config)` outputs a placeholder element with a
  **signed** URL (template + params + site are HMAC'ed with Craft's security
  key — no database records, no state). A small dependency-free script
  (external file, CSP-friendly) fetches all fragments after load, deduping
  identical URLs, and swaps them in. Config keys: `tag` (default `div`),
  `placeholder` (fallback HTML, kept if the fragment fails), `attributes`.
- `csrfInput()` outputs a placeholder that's replaced with a real CSRF hidden
  input. The token (and its cookie) comes from an uncacheable action request,
  so the page itself never sets cookies and caches cleanly.
- Params must be JSON-serializable scalars — pass element IDs, not elements.
  Fragment templates are partials: `{% js %}`/`{% css %}` won't work in them,
  and they must treat their params as public input.
- JS events: `cachemate:loaded` (per fragment, bubbles), `cachemate:error`
  (placeholder content is kept), `cachemate:done` (document, all settled).

Caveats:

- Fragments are progressive enhancement: no-JS visitors and most crawlers see
  the placeholder content. Don't put indexable content in fragments.
- Rotating Craft's `securityKey` invalidates all fragment URLs baked into
  cached pages — clear the cache afterwards.
- With `enableCsrfCookie => false`, CSRF tokens live in the PHP session, so
  any visitor who loads a form page will bypass the static cache from then on
  (CacheMate logs a warning if it detects this).

## Purging

Cached pages are purged automatically when content changes — no dependency
tracking, no database tables, no cache warming. Craft's element invalidation
signals are coalesced per request (or queue job) and flushed once, after the
save transaction commits.

The model is deliberately biased towards over-invalidation:

- A changed element's own URLs are always purged, across all of its sites —
  including its *old* URLs when a slug or URI changes (so web server rewrites
  never serve orphaned pages).
- What *else* gets purged is controlled by `purgeRules`. **When no rule matches
  a changed element, the entire cache is cleared** — an O(1) operation
  (directories are atomically renamed into a trash area and deleted
  asynchronously via the queue). With zero configuration every tracked edit
  clears everything: always correct, never stale. Rules are the tool to
  *contain* purges on bigger sites.
- Bulk operations (resaves etc.) and changes bigger than `maxTargetedPurges`
  paths escalate to a full clear too, instead of grinding through thousands of
  file deletes.
- Field and site setting changes, section/URI-format changes and structure
  moves always clear everything.

```php
'purgeRules' => [
    // News entries also purge the news listing page and the homepage
    'section:news' => ['/news', '/'],

    // Event entries only purge their own URLs
    'section:events' => [],

    // Per-site paths ('*' = fallback); trailing /* purges recursively
    'section:products' => ['*' => ['/products'], 'english' => ['/products/*']],

    // Asset changes in this volume purge the gallery section
    'volume:images' => ['/gallery/*'],

    // This global set is in the footer — everything must go
    'globalSet:footer' => 'all',
],
```

Rule keys are tried most-specific-first: `section:handle`, `categoryGroup:handle`,
`volume:handle`, `globalSet:handle`, then `entry`/`category`/`asset`/`globalSet`,
then `'*'`. Only element types in `trackedElementTypes` trigger purging (by
default: entries, categories, assets and global sets).

Manual purging:

```bash
# Clear everything (also available in the CP's Clear Caches utility)
php craft clear-caches/cachemate-pages
php craft cachemate/cache/clear

# Purge a specific path (optionally per site, optionally with descendants)
php craft cachemate/cache/purge /news --recursive --site=english
```

Note: purging resolves site URLs via each site's base URL. Sites with relative
base URLs (e.g. `/no/`) are resolved against the `@web` alias or the primary
site's host — if neither yields an absolute URL, purging fails safe by
clearing the entire cache.

## Web server rewrite (nginx)

Cached pages are stored as
`{cachePath}/{host}/{uri}/index.html` (query string variants under
`.../{uri}/_q/{q  uery}/index.html`), so nginx can serve hits directly:

```nginx
# Serve cached pages directly for GET/HEAD requests from visitors without a
# Craft session/identity cookie. Everything else falls through to Craft.
set $cachemate "";
if ($request_method ~ ^(GET|HEAD)$) {
    set $cachemate "G";
}
if ($http_cookie ~* "(CraftSessionId|_identity)") {
    set $cachemate "";
}
if ($args != "") {
    set $cachemate "${cachemate}Q";
}

set $cachemate_path "/cachemate/__miss__";
if ($cachemate = "G") {
    set $cachemate_path "/cachemate/$host$uri/index.html";
}
if ($cachemate = "GQ") {
    set $cachemate_path "/cachemate/$host$uri/_q/$args/index.html";
}

location / {
    try_files $cachemate_path $uri $uri/ /index.php?$query_string;
}
```

Notes:

- nginx matches the query string verbatim. Requests with unsorted or ignorable
  params fall through to the PHP early-serve, which normalizes them and still
  serves the cached file without routing — always correct, just slightly slower.
- If you've renamed `phpSessionName` in Craft's general config, update the
  cookie regex accordingly.
- Without the rewrite, everything works through the PHP early-serve fallback
  (`serveWithPhp`), which skips routing and element queries but does boot Craft.

Cached responses served by PHP include an `X-CacheMate: hit` header.
