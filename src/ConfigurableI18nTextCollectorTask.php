<?php

namespace LeKoala\Multilingual;

use SilverStripe\i18n\i18n;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\Director;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use LeKoala\Multilingual\TranslatorFactory;

/**
 * A better task for collecting text
 *
 * Use our TextCollector under the hood that actually works...
 */
class ConfigurableI18nTextCollectorTask extends BuildTask
{
    use BuildTaskTools;

    /**
     * @var string
     */
    private static $segment = 'ConfigurableI18nTextCollectorTask';

    /**
     * @var string
     */
    protected $title = "Configurable i18n Textcollector Task";

    /**
     * @var string
     */
    protected $description = "
		Traverses through files in order to collect the 'entity master tables'
		stored in each module. Provides the ability to choose modules and clear/merge translations.
	";

    /**
     * This is the main method to build the master string tables with the original strings.
     * It will search for existent modules that use the i18n feature, parse the _t() calls
     * and write the resultant files in the lang folder of each module.
     *
     * @uses DataObject->collectI18nStatics()
     * @param \SilverStripe\Control\HTTPRequest $request
     * @return void
     */
    public function run($request)
    {
        HTTPCacheControlMiddleware::singleton()->disableCache(true);

        $this->request = $request;
        $this->increaseTimeLimitTo();

        $modules = $this->getModulesAndThemes();

        $this->addOption("locale", "Locale to use", LangHelper::get_lang());
        $this->addOption("merge", "Merge with previous translations", true);
        $this->addOption("clear_unused", "Remove keys that are not used anymore", false);
        $this->addOption("debug", "Show debug messages and prevent write", false);
        $this->addOption("module", "Module", 'default', $modules);

        $options = $this->askOptions();

        $locale = $options['locale'];
        $merge = $options['merge'];
        $module = $options['module'];
        $clearUnused = $options['clear_unused'];
        $debug = $options['debug'];

        $themes = Director::baseFolder() . '/themes';
        $folders = glob($themes . '/*');
        if (!$folders) {
            $folders = [];
        }
        $toCollect = ['app'];
        foreach ($folders as $f) {
            $toCollect[] = 'themes:' . basename($f);
        }
        if ($module && $module != 'default') {
            $toCollect = [$module];
        }
        if ($locale) {
            foreach ($toCollect as $moduleName) {
                $this->message("Proceeding with locale $locale for module $moduleName");
                $collector = MultilingualTextCollector::create($locale);
                $collector->setMergeWithExisting($merge);
                $collector->setClearUnused($clearUnused);
                $collector->setDebug($debug);

                $result = $collector->run([$moduleName], $merge);
                if ($result) {
                    foreach ($result as $resultModule => $entities) {
                        $this->message("Collected " . count($entities) . " messages for module $resultModule");
                    }
                }
            }
        }
    }
}
