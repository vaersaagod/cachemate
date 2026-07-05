<?php

namespace vaersaagod\cachemate\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\events\InvalidateElementCachesEvent;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use craft\models\Site;

use vaersaagod\cachemate\CacheMate;

use vaersaagod\cachemate\helpers\SiteHelper;
use vaersaagod\cachemate\jobs\DeleteTrashedCacheDirs;

use yii\base\Component;

/**
 * Purges cached pages when content changes.
 *
 * Purge intents accumulate in memory during the request (or queue job) and
 * are flushed once, after the request/job completes. Targeted purges are
 * derived from Craft's own elements_sites table — no dependency tracking of
 * any kind. Whenever a change isn't covered by the element's own URLs and the
 * configured purge rules — or the number of targets exceeds the threshold —
 * the purge escalates to clearing the entire cache, which is O(1).
 *
 * @author    Værsågod
 * @package   CacheMate
 * @since     1.0.0
 */
class CachePurgeService extends Component
{
    // Private Properties
    // =========================================================================

    /** @var bool Whether the pending purge has escalated to a full clear */
    private bool $_purgeAll = false;

    /** @var bool Whether a flush has been scheduled for the end of the request */
    private bool $_flushScheduled = false;

    /** @var array Pending element purge intents, keyed by element ID (scalars only) */
    private array $_elements = [];

    /** @var array Pre-save element URIs, keyed by element ID (siteId => uri) */
    private array $_oldUris = [];

    /** @var array Pending manual path intents ("hostKey|path" => recursive) */
    private array $_paths = [];

    /** @var array Memoized per-site cache prefixes (siteId => ['hostKey' => ..., 'prefix' => ...]|null) */
    private array $_sitePrefixes = [];

    // Public Methods
    // =========================================================================

    /**
     * Handles Craft's element cache invalidation event — the single signal
     * that covers element saves, deletes, restores, slug/URI updates and
     * structure moves.
     *
     * @param InvalidateElementCachesEvent $event
     * @return void
     */
    public function handleInvalidateCaches(InvalidateElementCachesEvent $event): void
    {
        if (!$this->isPurgeEnabled() || $this->_purgeAll) {
            return;
        }

        if ($event->element !== null) {
            $this->purgeElement($event->element);

            return;
        }

        // Tags-only invalidations. Exact type-level tags (fired for structure
        // moves, section/URI format changes etc.) escalate to a full clear.
        // The bare 'element' tag is deliberately ignored — Craft's garbage
        // collection invalidates it routinely.
        $settings = CacheMate::getInstance()->getSettings();

        foreach ($event->tags as $tag) {
            foreach ($settings->trackedElementTypes as $elementType) {
                if ($tag === 'element::' . $elementType) {
                    $this->purgeAll();

                    return;
                }
            }
        }
    }

    /**
     * Queues purging of an element's cached pages, per the purge rules.
     *
     * @param ElementInterface $element
     * @return void
     */
    public function purgeElement(ElementInterface $element): void
    {
        if (!$this->isPurgeEnabled() || $this->_purgeAll) {
            return;
        }

        $root = $this->getTrackedRoot($element);

        if ($root === null || !$root->id) {
            return;
        }

        if (!isset($this->_elements[$root->id])) {
            $this->_elements[$root->id] = [
                'ruleKeys' => $this->getRuleKeys($root),
                'newAsset' => $root instanceof Asset && $root->firstSave,
                'snapshot' => ($root->uri !== null && $root->siteId) ? [$root->siteId => $root->uri] : [],
            ];
        }

        $settings = CacheMate::getInstance()->getSettings();

        if (count($this->_elements) > $settings->maxTargetedPurges) {
            $this->purgeAll();

            return;
        }

        $this->scheduleFlush();
    }

    /**
     * Captures an element's current (pre-save) URIs, so that pages at old
     * URLs are purged when slugs/URIs change. Without this, web server
     * rewrites would serve orphaned files at old URLs forever.
     *
     * @param ElementInterface $element
     * @return void
     */
    public function captureElementUris(ElementInterface $element): void
    {
        if (!$this->isPurgeEnabled() || $this->_purgeAll) {
            return;
        }

        $root = $this->getTrackedRoot($element);

        if ($root === null || !$root->id || $root->firstSave) {
            return;
        }

        // First capture wins, and the stash is memory-bounded
        if (isset($this->_oldUris[$root->id]) || count($this->_oldUris) > CacheMate::getInstance()->getSettings()->maxTargetedPurges) {
            return;
        }

        $rows = (new Query())
            ->select(['siteId', 'uri'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $root->id])
            ->andWhere(['not', ['uri' => null]])
            ->all();

        $this->_oldUris[$root->id] = array_column($rows, 'uri', 'siteId');
    }

    /**
     * Queues purging of a site-relative path.
     *
     * @param string $path A site-relative path ('/' or '' = the homepage)
     * @param int|null $siteId The site to purge the path for, or null for all sites
     * @param bool $recursive Whether cached descendant pages are purged too
     * @return void
     */
    public function purgePath(string $path, ?int $siteId = null, bool $recursive = false): void
    {
        if ($this->_purgeAll) {
            return;
        }

        $sites = $siteId !== null
            ? array_filter([Craft::$app->getSites()->getSiteById($siteId, true)])
            : Craft::$app->getSites()->getAllSites(true);

        foreach ($sites as $site) {
            $sitePrefix = $this->getSiteCachePrefix($site);

            if ($sitePrefix === null) {
                continue;
            }

            $fullPath = $this->joinPath($sitePrefix['prefix'], trim($path, '/'));
            $this->_paths[$sitePrefix['hostKey'] . '|' . $fullPath] = ($this->_paths[$sitePrefix['hostKey'] . '|' . $fullPath] ?? false) || $recursive;
        }

        $this->scheduleFlush();
    }

    /**
     * Queues clearing of the entire cache.
     *
     * @return void
     */
    public function purgeAll(): void
    {
        $this->_purgeAll = true;
        $this->_elements = [];
        $this->_oldUris = [];
        $this->_paths = [];

        $this->scheduleFlush();
    }

    /**
     * Executes all pending purge intents. Idempotent; called automatically
     * after the current request or queue job completes.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->_flushScheduled = false;

        if (!$this->_purgeAll && empty($this->_elements) && empty($this->_paths)) {
            return;
        }

        $storage = CacheMate::getInstance()->getCacheStorage();
        $targets = !$this->_purgeAll ? $this->resolveTargets() : null;

        if ($targets === null) {
            // Full clear — O(1) renames into the trash area
            $trashed = $storage->clearAll();
            $this->reset();

            if ($trashed) {
                $this->scheduleTrashSweep();
            }

            Craft::info('Cleared all cached pages', __METHOD__);

            return;
        }

        $trashed = false;
        $deleted = 0;

        foreach ($targets as $key => $recursive) {
            if ($this->isCoveredByRecursiveAncestor($key, $targets)) {
                continue;
            }

            [$hostKey, $path] = explode('|', $key, 2);

            if ($recursive) {
                $trashed = $storage->trashPath($hostKey, $path) || $trashed;
            } else {
                $storage->deletePath($hostKey, $path);
            }

            ++$deleted;
        }

        $this->reset();

        if ($trashed) {
            $this->scheduleTrashSweep();
        }

        if ($deleted > 0) {
            Craft::info('Purged ' . $deleted . ' cached path(s)', __METHOD__);
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves all pending intents to a target map ("hostKey|path" =>
     * recursive), or null if the purge should escalate to a full clear.
     *
     * @return array|null
     */
    private function resolveTargets(): ?array
    {
        $settings = CacheMate::getInstance()->getSettings();
        $targets = $this->_paths;

        // One query for all affected elements' current URIs, across all sites
        $urisByElement = [];

        if (!empty($this->_elements)) {
            $rows = (new Query())
                ->select(['elementId', 'siteId', 'uri'])
                ->from(Table::ELEMENTS_SITES)
                ->where(['elementId' => array_keys($this->_elements)])
                ->andWhere(['not', ['uri' => null]])
                ->all();

            foreach ($rows as $row) {
                $urisByElement[$row['elementId']][$row['siteId']] = $row['uri'];
            }
        }

        foreach ($this->_elements as $elementId => $info) {
            $siteUris = $urisByElement[$elementId] ?? null;
            $hardDeleted = $siteUris === null && !empty($info['snapshot']);

            if ($siteUris === null) {
                $siteUris = $info['snapshot'];
            }

            // The element's own URLs, present and past
            foreach ($siteUris as $siteId => $uri) {
                $site = Craft::$app->getSites()->getSiteById((int)$siteId, true);

                if ($site === null) {
                    continue;
                }

                $sitePrefix = $this->getSiteCachePrefix($site);

                if ($sitePrefix === null) {
                    if ($site->hasUrls) {
                        // A site we should be able to purge but can't resolve — fail safe
                        Craft::warning('Could not resolve a cache path for site "' . $site->handle . '" — clearing the entire cache. Make sure the site has an absolute base URL.', __METHOD__);

                        return null;
                    }

                    continue;
                }

                $path = $this->joinPath($sitePrefix['prefix'], $uri === Element::HOMEPAGE_URI ? '' : $uri);
                $this->addTarget($targets, $sitePrefix['hostKey'], $path, $hardDeleted);

                $oldUri = $this->_oldUris[$elementId][$siteId] ?? null;

                if ($oldUri !== null && $oldUri !== $uri) {
                    // The URI changed — purge the old location (and any cached
                    // descendants under it) recursively, and the new location's
                    // subtree in case it previously existed
                    $this->addTarget($targets, $sitePrefix['hostKey'], $this->joinPath($sitePrefix['prefix'], $oldUri === Element::HOMEPAGE_URI ? '' : $oldUri), true);
                    $this->addTarget($targets, $sitePrefix['hostKey'], $path, true);
                }
            }

            // Purge rules
            $rule = $this->resolveRule($info['ruleKeys'], $settings->purgeRules);

            if ($rule === null) {
                if ($info['newAsset']) {
                    // A just-uploaded asset can't be on any cached page yet
                    continue;
                }

                // No matching rule — over-invalidate
                return null;
            }

            if (!is_array($rule)) {
                // 'all' (or any unrecognized value — fail safe)
                return null;
            }

            if (!$this->applyRulePaths($targets, $rule, array_keys($siteUris))) {
                return null;
            }
        }

        if (count($targets) > $settings->maxTargetedPurges) {
            return null;
        }

        return $targets;
    }

    /**
     * Adds a rule's paths to the target map, for the given sites (or all
     * sites, for elements without URIs). Returns false if the rule requires
     * escalating to a full clear.
     *
     * @param array $targets
     * @param array $rule
     * @param array $siteIds
     * @return bool
     */
    private function applyRulePaths(array &$targets, array $rule, array $siteIds): bool
    {
        $sites = [];

        foreach ($siteIds as $siteId) {
            $site = Craft::$app->getSites()->getSiteById((int)$siteId, true);

            if ($site !== null) {
                $sites[] = $site;
            }
        }

        if (empty($sites)) {
            // URI-less elements (assets, globals) apply their rule paths to all sites
            $sites = Craft::$app->getSites()->getAllSites(true);
        }

        foreach ($sites as $site) {
            $sitePrefix = $this->getSiteCachePrefix($site);

            if ($sitePrefix === null) {
                continue;
            }

            // Resolve per-site path lists ('*' as fallback)
            if (!array_is_list($rule)) {
                $paths = $rule[$site->handle] ?? $rule['*'] ?? [];

                if (!is_array($paths)) {
                    // 'all' (or any unrecognized value — fail safe)
                    return false;
                }
            } else {
                $paths = $rule;
            }

            foreach ($paths as $rulePath) {
                $rulePath = trim((string)$rulePath, '/');
                $recursive = false;

                if ($rulePath === '*' || str_ends_with($rulePath, '/*')) {
                    $recursive = true;
                    $rulePath = rtrim(substr($rulePath, 0, -1), '/');
                }

                if ($recursive && $rulePath === '' && $sitePrefix['prefix'] === '') {
                    // '/*' on a site with no path prefix = the whole host — a
                    // full clear expresses that more cleanly
                    return false;
                }

                $this->addTarget($targets, $sitePrefix['hostKey'], $this->joinPath($sitePrefix['prefix'], $rulePath), $recursive);
            }
        }

        return true;
    }

    /**
     * Resolves the first matching purge rule for an element's rule keys.
     *
     * @param array $ruleKeys
     * @param array $rules
     * @return string|array|null 'all', an array of paths (possibly site-keyed), or null if no rule matched
     */
    private function resolveRule(array $ruleKeys, array $rules): string|array|null
    {
        foreach ($ruleKeys as $key) {
            if (array_key_exists($key, $rules)) {
                return $rules[$key];
            }
        }

        return null;
    }

    /**
     * Returns the ordered rule key candidates for an element, most specific
     * first.
     *
     * @param ElementInterface $element
     * @return string[]
     */
    private function getRuleKeys(ElementInterface $element): array
    {
        $keys = [];

        try {
            if ($element instanceof Entry) {
                $sectionHandle = $element->getSection()?->handle;

                if ($sectionHandle !== null) {
                    $keys[] = 'section:' . $sectionHandle;
                }

                $keys[] = 'entry';
            } elseif ($element instanceof Category) {
                $keys[] = 'categoryGroup:' . $element->getGroup()->handle;
                $keys[] = 'category';
            } elseif ($element instanceof Asset) {
                $keys[] = 'volume:' . $element->getVolume()->handle;
                $keys[] = 'asset';
            } elseif ($element instanceof GlobalSet) {
                $keys[] = 'globalSet:' . $element->handle;
                $keys[] = 'globalSet';
            }
        } catch (\Throwable) {
            // Fall through to the catch-all key
        }

        $keys[] = '*';

        return $keys;
    }

    /**
     * Resolves an element to its root owner, and returns it if it's a
     * tracked, canonical element — or null if it shouldn't trigger purging.
     *
     * @param ElementInterface $element
     * @return ElementInterface|null
     */
    private function getTrackedRoot(ElementInterface $element): ?ElementInterface
    {
        try {
            $root = $element->getRootOwner();
        } catch (\Throwable) {
            $root = $element;
        }

        $settings = CacheMate::getInstance()->getSettings();
        $tracked = false;

        foreach ($settings->trackedElementTypes as $elementType) {
            if ($root instanceof $elementType) {
                $tracked = true;
                break;
            }
        }

        if (!$tracked || ElementHelper::isDraftOrRevision($root)) {
            return null;
        }

        return $root;
    }

    /**
     * Returns the cache location for a site — the host directory key and the
     * site's path prefix (for path-based sites) — or null if it can't be
     * derived from the site's base URL.
     *
     * @param Site $site
     * @return array|null
     */
    private function getSiteCachePrefix(Site $site): ?array
    {
        if (array_key_exists($site->id, $this->_sitePrefixes)) {
            return $this->_sitePrefixes[$site->id];
        }

        return $this->_sitePrefixes[$site->id] = SiteHelper::getSiteCachePrefix($site);
    }

    /**
     * Adds a target to the target map, OR-merging the recursive flag.
     *
     * @param array $targets
     * @param string $hostKey
     * @param string $path
     * @param bool $recursive
     * @return void
     */
    private function addTarget(array &$targets, string $hostKey, string $path, bool $recursive): void
    {
        $key = $hostKey . '|' . $path;
        $targets[$key] = ($targets[$key] ?? false) || $recursive;
    }

    /**
     * Returns whether a target is already covered by another, recursive
     * target higher up in the tree (or the same path).
     *
     * @param string $key
     * @param array $targets
     * @return bool
     */
    private function isCoveredByRecursiveAncestor(string $key, array $targets): bool
    {
        [$hostKey, $path] = explode('|', $key, 2);

        foreach ($targets as $otherKey => $otherRecursive) {
            if (!$otherRecursive || $otherKey === $key) {
                continue;
            }

            [$otherHostKey, $otherPath] = explode('|', $otherKey, 2);

            if ($otherHostKey !== $hostKey) {
                continue;
            }

            if ($otherPath === '' || $otherPath === $path || str_starts_with($path, $otherPath . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Joins a site path prefix and a URI into a cache path.
     *
     * @param string $prefix
     * @param string $uri
     * @return string
     */
    private function joinPath(string $prefix, string $uri): string
    {
        return implode('/', array_filter([trim($prefix, '/'), trim($uri, '/')], static fn(string $part): bool => $part !== ''));
    }

    /**
     * Schedules a flush for when the current request (or queue job) has
     * completed, so purging never runs before the save transaction commits
     * and all intents coalesce into one flush.
     *
     * @return void
     */
    private function scheduleFlush(): void
    {
        if ($this->_flushScheduled) {
            return;
        }

        $this->_flushScheduled = true;

        Craft::$app->onAfterRequest(function(): void {
            $this->flush();
        });
    }

    /**
     * Pushes the trash sweep job.
     *
     * @return void
     */
    private function scheduleTrashSweep(): void
    {
        Queue::push(new DeleteTrashedCacheDirs());
    }

    /**
     * Resets all pending purge state.
     *
     * @return void
     */
    private function reset(): void
    {
        $this->_purgeAll = false;
        $this->_elements = [];
        $this->_oldUris = [];
        $this->_paths = [];
    }

    /**
     * @return bool
     */
    private function isPurgeEnabled(): bool
    {
        return CacheMate::getInstance()->getSettings()->purgeEnabled;
    }
}
