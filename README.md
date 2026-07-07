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

### enabled [bool|array]
*Default: `true`*  
Whether static caching is enabled. Can be a boolean, or an array keyed by site
handle, with `'*'` as the fallback for sites not listed explicitly:

```php
'enabled' => [
    '*' => true,
    'intranet' => false,
],
```

### cachePath [string]
*Default: `'@webroot/cachemate'`*  
Where cached pages are stored. Must be inside the web root for web server
rewrites to be able to serve cache hits without PHP.

### cacheDuration [int|string]
*Default: `0`*  
How long cached pages are considered fresh. Can be a number of seconds, a
valid PHP date interval string (e.g. `'P1D'`), or `0` for no expiry (pages
live until they're purged). Only enforced on the PHP serve path — pages served
by web server rewrites don't expire until the [sweep](#cache-duration--sweeping)
runs.

### serveWithPhp [bool]
*Default: `true`*  
Whether to serve cached pages from PHP, early in the request cycle, when no
web server rewrite is configured (or the rewrite didn't match). Disabling this
only makes sense on installs where the rewrite is guaranteed to handle all
cache hits.

### cacheControlHeader [string]
*Default: `'public, s-maxage=31536000, max-age=0'`*  
The `Cache-Control` header value sent with cached responses served by PHP.
Note that purging only deletes local files — if there's a CDN or proxy in
front honoring `s-maxage`, consider a shorter value.

### maxUriLength [int]
*Default: `2048`*  
The maximum length of a cacheable request URI (path + query string). Longer
requests are served dynamically.

### debugHeaders [bool|string]
*Default: `'auto'`*  
Whether the [X-CacheMate header](#the-x-cachemate-header) includes reason
keywords (e.g. `bypass; session`, `miss; sets-cookies`). Set to `'auto'` to
include reasons only when `devMode` is enabled, or `true`/`false` to force
them on or off. The bare states (`hit`/`miss`/`bypass`) are always sent on
front-end responses.

### cacheQueryStrings [bool|array]
*Default: `false`*  
Whether/how query strings participate in caching:

- `false`: requests with query strings (after removing `ignoredQueryParams`)
  are never cached
- `true`: query string URLs are cached as unique pages; all params are part of
  the cache key, sorted by name
- an array of param names: a whitelist — listed params are kept (sorted) in
  the cache key; what happens to non-whitelisted params depends on
  `strictQueryParams`

See [Query strings](#query-strings) above.

### strictQueryParams [bool]
*Default: `false`*  
Only relevant when `cacheQueryStrings` is an array. When `false`,
non-whitelisted params are stripped from the cache key and the request is
still cached/served. When `true`, a request carrying any param *not* in the
whitelist (and not in `ignoredQueryParams`) is not cacheable at all.

### ignoredQueryParams [array]
*Default: `['utm_*', 'gclid', 'gclsrc', 'dclid', 'fbclid', 'msclkid', 'twclid', 'ttclid', 'mc_cid', 'mc_eid', '_ga', '_gl']`*  
Params that never affect the cache key and never block caching, in any query
string mode. Supports trailing-`*` wildcards.

### excludedUriPatterns [array]
*Default: `[]`*  
URI patterns to exclude from caching. Regex fragments, matched
case-insensitively against the site-relative path with a leading slash. Can be
a flat array of patterns, or an array keyed by site handle with `'*'` as the
fallback:

```php
'excludedUriPatterns' => ['^/account', '/search'],

// or per site:
'excludedUriPatterns' => [
    '*' => ['^/account'],
    'english' => ['^/account', '^/members'],
],
```

### purgeEnabled [bool]
*Default: `true`*  
Whether cached pages are automatically purged when content changes. See
[Purging](#purging).

### trackedElementTypes [array]
*Default: `[Entry::class, Category::class, Asset::class, GlobalSet::class]`*  
The element types that trigger purging when they change. Element types not in
this list (users, for instance) never purge anything.

### purgeRules [array]
*Default: `[]`*  
Rules for what to purge when an element changes, in addition to the element's
own URLs (which are always purged). **When no rule matches a changed element,
the entire cache is cleared** — rules are the tool to contain purges on bigger
sites. Keys are tried most-specific-first (`section:handle`,
`categoryGroup:handle`, `volume:handle`, `globalSet:handle`, then `entry`/
`category`/`asset`/`globalSet`, then `'*'`). Values can be `'all'` (clear
everything), an array of site-relative paths (trailing `/*` = recursive), an
array keyed by site handle, or `[]` (own URLs only). See
[Purging](#purging) for examples.

### maxTargetedPurges [int]
*Default: `200`*  
The maximum number of targeted purge paths per flush. When exceeded, the purge
escalates to clearing the entire cache — which is faster, since clearing is an
O(1) rename.

### entryPurgeButton [bool]
*Default: `true`*  
Whether to show the "Static cache" panel with a purge button in the entry edit
sidebar. Only shown when purging is enabled, for published entries with URLs.

### cache404s [bool|array]
*Default: `false`*  
Whether to cache 404 responses — one page per site, never per URI. Can be a
boolean, or an array keyed by site handle with `'*'` as the fallback. ⚠️ Only
enable this if your 404 template does **not** render the requested URL/path or
anything else request-specific. See [Cached 404s](#cached-404s).

### cache404Duration [int|string]
*Default: `3600`*  
How long cached 404 pages are considered fresh. Seconds, a PHP date interval
string, or `0` for no expiry. Cached 404s are always served via PHP, so this
TTL is always enforced (no sweep needed).

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

There's also a **CacheMate utility** in the control panel (Utilities →
CacheMate) showing cached page counts and disk usage per host, with buttons
for clearing the cache and deleting expired pages. Access is controlled by
the standard utility permission.

Entry edit pages get a **"Static cache" panel** in the sidebar (for published
entries with URLs) showing the entry's cached state per site — when it was
cached, and when it expires — along with a "Purge from static cache" button
for purging the entry manually. The purge behaves exactly like saving the
entry would — purge rules apply, including the full-clear fallback when no
rule matches. Disable the panel with `'entryPurgeButton' => false`.

Note: purging resolves site URLs via each site's base URL. Sites with relative
base URLs (e.g. `/no/`) are resolved against the `@web` alias or the primary
site's host — if neither yields an absolute URL, purging fails safe by
clearing the entire cache.

## Web server rewrite (nginx)

Cached pages are stored as
`{cachePath}/{host}/{uri}/index.html` (query string variants under
`.../{uri}/_q/{query}/index.html`), so nginx can serve hits directly.

Both variants below start with the same candidacy checks — cached pages are
only served for GET/HEAD requests from visitors without a Craft
session/identity cookie; everything else falls through to Craft:

```nginx
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
```

### Variant 1: try_files

The smallest possible integration — add `$cachemate_path` as the first
`try_files` argument in the existing `location /`:

```nginx
location / {
    try_files $cachemate_path $uri $uri/ /index.php?$query_string;
}
```

Cache hits are served in place, with nginx's default static file headers.

### Variant 2: dedicated location (recommended)

Instead of serving the file in place, internally redirect hits into a
dedicated location. This leaves the existing `location /` untouched, and gives
you a block scoped exactly to cache hits — for a debug header, a proper
`Cache-Control` header for downstream proxies/CDNs, and `internal` (which
blocks direct external requests to `/cachemate/...` URLs):

```nginx
# Internal redirect to the cache location on a hit ('?' drops the query string)
if (-f $document_root$cachemate_path) {
    rewrite ^ $cachemate_path? last;
}

location /cachemate/ {
    internal;
    add_header X-CacheMate "hit; nginx";
    add_header Cache-Control "public, s-maxage=31536000, max-age=0";
}
```

With this variant the serving layer is visible per response — see the
`X-CacheMate` header reference below.

### Notes

- nginx matches the query string verbatim. Requests with unsorted or ignorable
  params fall through to the PHP early-serve, which normalizes them and still
  serves the cached file without routing — always correct, just slightly slower.
- If you've renamed `phpSessionName` in Craft's general config, update the
  cookie regex accordingly.
- Without the rewrite, everything works through the PHP early-serve fallback
  (`serveWithPhp`), which skips routing and element queries but does boot Craft.

## The X-CacheMate header

Every front-end response served by Craft (plus nginx-served hits with rewrite
variant 2) carries an `X-CacheMate` header telling you what CacheMate did:

| Header | Meaning |
|---|---|
| `X-CacheMate: hit; nginx` | Served by the web server rewrite (variant 2), no PHP involved |
| `X-CacheMate: hit` | Served by CacheMate's PHP early-serve |
| `X-CacheMate: miss` | The request was a cache candidate, but was rendered live (and captured, if the response qualified) |
| `X-CacheMate: bypass` | The request was not a cache candidate |
| *(no header)* | CP/action/console responses — or CacheMate isn't installed |

With `debugHeaders` enabled (`'auto'`, the default, enables it in devMode; use
`true`/`false` to force it on or off), a reason keyword is appended:

- **bypass reasons** (request-level): `method`, `preview`, `token`, `session`,
  `no-cache`, `disabled`, `uri` (too long/reserved segments/index.php),
  `excluded` (matched `excludedUriPatterns`), `query` (query string rules),
  `system`
- **miss reasons** (the rendered response wasn't stored): `opt-out`, `status`,
  `format`, `content`, `no-cache-headers`, `sets-cookies`, `encoded`, `totp`

Miss reasons are also written to `storage/logs/cachemate-*.log`, regardless
of the `debugHeaders` setting.


## Changelog

See [CHANGELOG.MD](https://raw.githubusercontent.com/vaersaagod/cachemate/master/CHANGELOG.md).

## Credits

Brought to you by [Værsågod](https://www.vaersaagod.no)

Icon designed by [Freepik from Flaticon](https://www.flaticon.com/authors/freepik).
