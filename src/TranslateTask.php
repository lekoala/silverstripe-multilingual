<?php

namespace LeKoala\Multilingual;

use Exception;
use SilverStripe\Dev\Debug;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\Director;
use SilverStripe\i18n\Messages\Writer;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\i18n\Messages\YamlReader;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\i18n\i18n;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Path;

/**
 * Batch translate YML files
 */
class TranslateTask extends BuildTask
{
    use BuildTaskTools;

    private static $segment = 'TranslateTask';
    protected $title = "Batch Translate Task";
    protected $description = "Translate missing keys in your YML files";

    /**
     * @param \SilverStripe\Control\HTTPRequest $request
     * @return void
     */
    public function run($request)
    {
        $this->request = $request;
        $modules = $this->getModulesAndThemes();

        // Options
        $this->addOption("module", "Module to translate", null, $modules);
        $this->addOption("locale", "Target locale (default: all files in lang folder)", null);
        $this->addOption("source_lang", "Source language for translation (default: en)", 'en');

        $defaultDriver = TranslatorFactory::getDefaultDriver();
        $this->addOption("driver", "Translator driver (ollama or deepl) [default: $defaultDriver]", null);
        $this->addOption("model", "Ollama model", null);
        $this->addOption("enrich", "Enrich with code context (collects strings in memory, slow but accurate)", false);
        $this->addOption("review", "Review existing translations", false);
        $this->addOption("limit", "Limit number of translations/reviews (safety)", 50);
        $this->addOption("write", "Commit changes to files", false);

        $options = $this->askOptions();

        $moduleName = $options['module'];
        $driver = $options['driver'];
        $model = $options['model'];
        $sourceLang = $options['source_lang'];
        $targetLocale = $options['locale'];
        $write = $options['write'];

        if (!$moduleName) {
            $this->message("Please select a module");
            return;
        }

        $translator = TranslatorFactory::create($driver, $model);
        $service = new TranslationService($translator);

        $this->message("Using driver: " . get_class($translator));

        $this->processModule($moduleName, $service, $sourceLang, $targetLocale, $options);
    }

    protected function processModule($moduleName, TranslationService $service, $sourceLang, $targetLocale = null, $options = [])
    {
        $write = $options['write'] ?? false;
        $modules = ModuleLoader::inst()->getManifest()->getModules();
        $module = $modules[$moduleName] ?? null;
        if (!$module) {
            $this->message("Module $moduleName not found", "error");
            return;
        }

        $fullLangPath = Path::join($module->getPath(), 'lang');
        $translationFiles = glob($fullLangPath . '/*.yml');

        if (!$translationFiles) {
            $this->message("No YML files found in $fullLangPath");
            return;
        }

        $writer = Injector::inst()->create(Writer::class);

        foreach ($translationFiles as $file) {
            $lang = pathinfo($file, PATHINFO_FILENAME);

            // Skip source lang
            if ($lang === $sourceLang) {
                continue;
            }
            // Skip if not target locale matches
            if ($targetLocale && $lang !== $targetLocale) {
                continue;
            }

            $this->message("Processing $lang.yml...");
            $result = $service->translateModule($moduleName, $sourceLang, $lang, $options);

            $translated = $result['translated'];
            $corrected = $result['corrected'];
            $messages = $result['messages'];

            if ($translated > 0 || $corrected > 0) {
                if ($write) {
                    $this->message("Writing improvements ($translated translated, $corrected corrected) to $lang.yml");
                    $writer->write($messages, $lang, $fullLangPath);
                } else {
                    $this->message("Dry Run: Found $translated new translations and $corrected corrections for $lang.yml");
                }
            } else {
                $this->message("$lang.yml is up to date.");
            }
        }
    }

    public function isEnabled(): bool
    {
        return Director::isDev();
    }
}
