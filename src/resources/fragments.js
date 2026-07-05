/**
 * CacheMate fragment inject script.
 *
 * Replaces `data-cachemate-fragment` and `data-cachemate-csrf` placeholders
 * with server-rendered content after page load, so the surrounding page can
 * be statically cached. Identical URLs are fetched once. Dispatches
 * `cachemate:loaded` per swapped element, `cachemate:error` on failure, and
 * `cachemate:done` on the document when everything has settled.
 */
(function () {
    'use strict';

    var MAX_DEPTH = 3;

    function dispatch(target, name, detail) {
        target.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: detail || {} }));
    }

    function swapFragment(el, html, url, depth) {
        // The HTML comes from CacheMate's own same-origin fragment endpoint,
        // which only renders developer-authored site templates addressed by an
        // HMAC-signed URL — the same trust level as the page's own markup
        el.innerHTML = html;
        el.setAttribute('data-cachemate-loaded', '');
        dispatch(el, 'cachemate:loaded', { url: url });

        // Process fragments nested inside the injected content
        if (depth < MAX_DEPTH) {
            return process(el, depth + 1);
        }

        return Promise.resolve();
    }

    function swapCsrf(el, html, url) {
        var template = document.createElement('template');
        template.innerHTML = html.trim();

        var input = template.content.firstElementChild;

        if (input) {
            el.replaceWith(input);
            dispatch(input, 'cachemate:loaded', { url: url });
        }
    }

    function fail(el, url, status) {
        el.setAttribute('data-cachemate-error', '');
        dispatch(el, 'cachemate:error', { url: url, status: status });
    }

    function process(root, depth) {
        var groups = {};

        var collect = function (attribute, isCsrf) {
            root.querySelectorAll('[' + attribute + ']:not([data-cachemate-loaded]):not([data-cachemate-error])').forEach(function (el) {
                var url = el.getAttribute(attribute);

                if (url) {
                    (groups[url] = groups[url] || { csrf: isCsrf, els: [] }).els.push(el);
                }
            });
        };

        collect('data-cachemate-fragment', false);
        collect('data-cachemate-csrf', true);

        var requests = Object.keys(groups).map(function (url) {
            var group = groups[url];

            return fetch(url, { credentials: 'same-origin' })
                .then(function (res) {
                    if (!res.ok) {
                        throw res.status;
                    }

                    return res.text();
                })
                .then(function (html) {
                    return Promise.all(group.els.map(function (el) {
                        if (group.csrf) {
                            swapCsrf(el, html, url);

                            return Promise.resolve();
                        }

                        return swapFragment(el, html, url, depth);
                    }));
                })
                .catch(function (status) {
                    group.els.forEach(function (el) {
                        fail(el, url, status);
                    });
                });
        });

        return Promise.allSettled(requests);
    }

    function init() {
        process(document, 1).then(function () {
            dispatch(document, 'cachemate:done');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
