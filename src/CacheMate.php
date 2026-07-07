<?php

namespace vaersaagod\cachemate;

use Craft;
use craft\base\Element;
use craft\base\Event;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\InvalidateElementCachesEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\log\MonologTarget;
use craft\queue\Queue;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Sites;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\twig\variables\CraftVariable;

use Monolog\Formatter\LineFormatter;

use Psr\Log\LogLevel;

use vaersaagod\cachemate\models\Settings;
use vaersaagod\cachemate\services\CachePurgeService;
use vaersaagod\cachemate\services\CacheRequestService;
use vaersaagod\cachemate\services\CacheStorageService;
use vaersaagod\cachemate\utilities\CacheMateUtility;
use vaersaagod\cachemate\variables\CacheMateVariable;

/**
 * CacheMate plugin
 *
 * @method static CacheMate getInstance()
 * @method Settings getSettings()
 *
 * @property-read CachePurgeService $cachePurge
 * @property-read CacheRequestService $cacheRequest
 * @property-read CacheStorageService $cacheStorage
 */
class CacheMate extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = false;

    public static function config(): array
    {
        return [
            'components' => [
                'cachePurge' => CachePurgeService::class,
                'cacheRequest' => CacheRequestService::class,
                'cacheStorage' => CacheStorageService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Register a custom log target, keeping the format as simple as possible.
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => 'cachemate',
            'categories' => ['cachemate', 'vaersaagod\\cachemate\\*'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => false,
            'formatter' => new LineFormatter(
                format: "%datetime% %message%\n",
                dateFormat: 'Y-m-d H:i:s',
            ),
        ]);

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function() {
            $this->attachEventHandlers();

            // Serve and/or capture statically cached pages. This runs at
            // Application::EVENT_INIT — before routing and element queries —
            // so cache hits end the request as early as possible.
            if (Craft::$app->getRequest() instanceof \craft\web\Request) {
                $this->getCacheRequest()->handleRequest();
            }
        });
    }

    /**
     * @return CachePurgeService
     * @throws \yii\base\InvalidConfigException
     */
    public function getCachePurge(): CachePurgeService
    {
        /** @var CachePurgeService */
        return $this->get('cachePurge');
    }

    /**
     * @return CacheRequestService
     * @throws \yii\base\InvalidConfigException
     */
    public function getCacheRequest(): CacheRequestService
    {
        /** @var CacheRequestService */
        return $this->get('cacheRequest');
    }

    /**
     * @return CacheStorageService
     * @throws \yii\base\InvalidConfigException
     */
    public function getCacheStorage(): CacheStorageService
    {
        /** @var CacheStorageService */
        return $this->get('cacheStorage');
    }

    private function attachEventHandlers(): void
    {
        // Register the craft.cachemate template variable
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(\yii\base\Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('cachemate', CacheMateVariable::class);
            }
        );

        // Register the CP utility
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = CacheMateUtility::class;
            }
        );

        // Add the purge button to the entry edit sidebar
        if ($this->getSettings()->entryPurgeButton && $this->getSettings()->purgeEnabled) {
            Event::on(
                Entry::class,
                Element::EVENT_DEFINE_SIDEBAR_HTML,
                static function(DefineHtmlEvent $event): void {
                    if ($event->static) {
                        return;
                    }

                    /** @var Entry $entry */
                    $entry = $event->sender;
                    $canonical = $entry->getIsRevision() ? null : $entry->getCanonical();

                    if ($canonical === null || !$canonical->id || $canonical->getIsDraft() || $canonical->uri === null) {
                        return;
                    }

                    $event->html .= Craft::$app->getView()->renderTemplate('cachemate/entry-sidebar.twig', [
                        'element' => $canonical,
                        'status' => CacheMate::getInstance()->getCacheStorage()->getElementCacheStatus($canonical),
                    ]);
                }
            );
        }

        // Register the "Clear Caches" utility option
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function(RegisterCacheOptionsEvent $event): void {
                $event->options[] = [
                    'key' => 'cachemate-pages',
                    'label' => Craft::t('cachemate', 'CacheMate static page cache'),
                    'action' => function(): void {
                        $this->getCachePurge()->purgeAll();
                        $this->getCachePurge()->flush();
                    },
                ];
            }
        );

        // Serve/capture cached 404 pages. Attached from onInit() — after every
        // plugin's init() — so this handler runs AFTER RedirectMate's
        // EVENT_BEFORE_HANDLE_EXCEPTION handler, and only when no redirect
        // matched (a matched redirect ends the process).
        if ($this->getSettings()->cache404s !== false && Craft::$app->getRequest() instanceof \craft\web\Request) {
            Event::on(
                \craft\web\ErrorHandler::class,
                \craft\web\ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION,
                function(\craft\events\ExceptionEvent $event): void {
                    $this->getCacheRequest()->handleNotFoundException($event);
                }
            );
        }

        if (!$this->getSettings()->purgeEnabled) {
            return;
        }

        // The single content-change signal — fired by Craft after element
        // saves, deletes, restores, slug/URI updates and structure moves
        Event::on(
            Elements::class,
            Elements::EVENT_INVALIDATE_CACHES,
            function(InvalidateElementCachesEvent $event): void {
                $this->getCachePurge()->handleInvalidateCaches($event);
            }
        );

        // Capture pre-save URIs, so pages at old URLs are purged on slug/URI changes
        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_SAVE_ELEMENT,
            function(ElementEvent $event): void {
                $this->getCachePurge()->captureElementUris($event->element);
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_UPDATE_SLUG_AND_URI,
            function(ElementEvent $event): void {
                $this->getCachePurge()->captureElementUris($event->element);
            }
        );

        // Queue workers never reach EVENT_AFTER_REQUEST, so flush pending
        // purges after each job too (flush() is an idempotent no-op)
        Event::on(
            Queue::class,
            Queue::EVENT_AFTER_EXEC,
            function(): void {
                $this->getCachePurge()->flush();
            }
        );

        Event::on(
            Queue::class,
            Queue::EVENT_AFTER_ERROR,
            function(): void {
                $this->getCachePurge()->flush();
            }
        );

        // Field and site changes can affect any page. (Craft's own
        // invalidate-all signal can't be used for this — garbage collection
        // triggers it routinely.)
        foreach ([
            [Fields::class, Fields::EVENT_AFTER_SAVE_FIELD],
            [Fields::class, Fields::EVENT_AFTER_DELETE_FIELD],
            [Sites::class, Sites::EVENT_AFTER_SAVE_SITE],
            [Sites::class, Sites::EVENT_AFTER_DELETE_SITE],
        ] as [$class, $eventName]) {
            Event::on($class, $eventName, function(): void {
                $this->getCachePurge()->purgeAll();
            });
        }
    }

    /**
     * @return Model|null
     * @throws \yii\base\InvalidConfigException
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }
}
