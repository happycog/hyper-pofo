<?php
namespace verbb\hyper;

use verbb\hyper\base\PluginTrait;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\fieldlayoutelements\AriaLabelField;
use verbb\hyper\fieldlayoutelements\ClassesField;
use verbb\hyper\fieldlayoutelements\CustomAttributesField;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fieldlayoutelements\LinkTextField;
use verbb\hyper\fieldlayoutelements\LinkTitleField;
use verbb\hyper\fieldlayoutelements\UrlSuffixField;
use verbb\hyper\integrations\feedme\fields\Hyper as FeedMeHyperField;
use verbb\hyper\models\Settings;
use verbb\hyper\variables\HyperVariable;

use Craft;
use craft\base\Plugin;
use craft\elements\db\ElementQuery;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\PopulateElementEvent;
use craft\events\RebuildConfigEvent; 
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\ProjectConfig;
use craft\web\Application;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;

use yii\base\Event;

use verbb\supertable\services\Service as SuperTableService;

use craft\feedme\events\RegisterFeedMeFieldsEvent;
use craft\feedme\services\Fields as FeedMeFields;

class Hyper extends Plugin
{
    // Properties
    // =========================================================================

    public bool $hasCpSettings = true;
    public string $schemaVersion = '1.0.0';


    // Traits
    // =========================================================================

    use PluginTrait;


    // Public Methods
    // =========================================================================

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        $this->_setPluginComponents();
        $this->_setLogging();
        $this->_registerVariables();
        $this->_registerFieldTypes();
        $this->_registerFieldLayoutElements();
        $this->_registerProjectConfigEventListeners();
        $this->_registerCraftEventListeners();
        $this->_registerThirdPartyEventListeners();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpRoutes();
        }

        $this->_registerCachePreload();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('hyper/settings'));
    }


    // Protected Methods
    // =========================================================================

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }


    // Private Methods
    // =========================================================================

    private function _registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['hyper'] = 'hyper/plugin/settings';
            $event->rules['hyper/settings'] = 'hyper/plugin/settings';
        });
    }

    private function _registerVariables(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('hyper', HyperVariable::class);
        });
    }

    private function _registerFieldTypes(): void
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = HyperField::class;
        });
    }

    private function _registerFieldLayoutElements(): void
    {
        Event::on(FieldLayout::class, FieldLayout::EVENT_DEFINE_NATIVE_FIELDS, function(DefineFieldLayoutFieldsEvent $event) {
            if (is_subclass_of($event->sender->type, LinkInterface::class)) {
                $event->fields[] = AriaLabelField::class;
                $event->fields[] = ClassesField::class;
                $event->fields[] = CustomAttributesField::class;
                $event->fields[] = LinkField::class;
                $event->fields[] = LinkTextField::class;
                $event->fields[] = LinkTitleField::class;
                $event->fields[] = UrlSuffixField::class;
            }
        });
    }

    private function _registerProjectConfigEventListeners(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $projectConfig
            ->onAdd(ProjectConfig::PATH_FIELDS . '.{uid}', [$this->getService(), 'handleChangedField'])
            ->onUpdate(ProjectConfig::PATH_FIELDS . '.{uid}', [$this->getService(), 'handleChangedField'])
            ->onRemove(ProjectConfig::PATH_FIELDS . '.{uid}', [$this->getService(), 'handleDeletedField']);

        // Special case for some fields like Matrix, that don't emit the change event for nested fields.
        $projectConfig
            ->onAdd(ProjectConfig::PATH_MATRIX_BLOCK_TYPES . '.{uid}', [$this->getService(), 'handleChangedBlockType'])
            ->onUpdate(ProjectConfig::PATH_MATRIX_BLOCK_TYPES . '.{uid}', [$this->getService(), 'handleChangedBlockType'])
            ->onRemove(ProjectConfig::PATH_MATRIX_BLOCK_TYPES . '.{uid}', [$this->getService(), 'handleDeletedBlockType']);

        if (class_exists(SuperTableService::class)) {
            $projectConfig
                ->onAdd(SuperTableService::CONFIG_BLOCKTYPE_KEY . '.{uid}', [$this->getService(), 'handleChangedBlockType'])
                ->onUpdate(SuperTableService::CONFIG_BLOCKTYPE_KEY . '.{uid}', [$this->getService(), 'handleChangedBlockType'])
                ->onRemove(SuperTableService::CONFIG_BLOCKTYPE_KEY . '.{uid}', [$this->getService(), 'handleDeletedBlockType']);
        }

        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, function(RebuildConfigEvent $event) {
            // Ensure the are serialized as JSON for link types. This is because rebuilding doesn't trigger `Field::beforeSave()`
            foreach ($event->config['fields'] as $fieldKey => $field) {
                if ($field['type'] === HyperField::class) {
                    foreach (($field['settings']['linkTypes'] ?? []) as $linkTypeKey => $linkType) {
                        if ($linkType instanceof LinkInterface) {
                            $event->config['fields'][$fieldKey]['settings']['linkTypes'][$linkTypeKey] = $linkType->getSettingsConfig();
                        }
                    }

                    // Not sure why this isn't applied automatically, but for consistency, ensure the settings are the same as from `Field::beforeSave()`.
                    $event->config['fields'][$fieldKey]['settings'] = ProjectConfigHelper::packAssociativeArrays($event->config['fields'][$fieldKey]['settings']);
                }
            }
        });
    }

    private function _registerCraftEventListeners(): void
    {
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, [$this->getElementCache(), 'onSaveElement']);
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, [$this->getElementCache(), 'onDeleteElement']);
        
        Event::on(Fields::class, Fields::EVENT_AFTER_SAVE_FIELD, [$this->getFieldCache(), 'onSaveField']);
        Event::on(Fields::class, Fields::EVENT_AFTER_DELETE_FIELD, [$this->getFieldCache(), 'onDeleteField']);
    }

    private function _registerCachePreload(): void
    {
        // Before rendering a site-based template, preload the cache for element links for significant
        // performance gains. This allows us to query every Hyper link field used on the page in one
        // go, rather than one at a time, for each field.
        Event::on(Application::class, Application::EVENT_INIT, function() {
            // When rendering a page, do a single Hyper cache query for all Hyper fields on the page.
            Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function(Event $event) {
                if (!Craft::$app->getRequest()->getIsSiteRequest()) {
                    return;
                }

                if (Craft::$app->getResponse()->getIsOk()) {
                    Hyper::$plugin->getElementCache()->preloadCache();
                }
            });

            // For every element that is populated (on the front-end), record it in our render cache for Hyper.
            // We'll use this to check against any element-link Hyper links that refer to _this_ element being recorded.
            Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT, function(PopulateElementEvent $event) {
                if (!Craft::$app->getRequest()->getIsSiteRequest()) {
                    return;
                }

                if (Craft::$app->getResponse()->getIsOk()) {
                    Hyper::$plugin->getElementCache()->addToRenderCache($event->element->id, $event->element->siteId);
                }
            });
        });
    }

    private function _registerThirdPartyEventListeners(): void
    {
        if (class_exists(FeedMeFields::class)) {
            Event::on(FeedMeFields::class, FeedMeFields::EVENT_REGISTER_FEED_ME_FIELDS, function(RegisterFeedMeFieldsEvent $event) {
                $event->fields[] = FeedMeHyperField::class;
            });
        }
    }
}
