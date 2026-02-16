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
        $this->addOption("locale", "Initial locale to verify (default: current locale)", null);
        $this->addOption("source_lang", "Source language for translation (default: en)", 'en');
        
        $defaultDriver = TranslatorFactory::getDefaultDriver();
        $this->addOption("driver", "Translator driver (ollama or deepl) [default: $defaultDriver]", null);
        $this->addOption("model", "Ollama model", null);
        $this->addOption("limit", "Limit number of translations per file (safety)", 50);
        $this->addOption("write", "Commit changes to files", false);

        $options = $this->askOptions();

        $module = $options['module'];
        $driver = $options['driver'];
        $model = $options['model'];
        $sourceLang = $options['source_lang'];
        $locale = $options['locale'];
        $limit = $options['limit'];
        $write = $options['write'];

        if (!$module) {
            $this->message("Please select a module");
            return;
        }

        $translator = TranslatorFactory::create($driver, $model);
        $this->message("Using driver: " . get_class($translator));

        if ($module) {
            $this->processModule($module, $translator, $sourceLang, $locale, $limit, $write);
        }
    }

    protected function processModule($module, $translator, $sourceLang, $targetLocale = null, $limit = 50, $write = false)
    {
        $langPath = ModuleResourceLoader::resourcePath($module . ':lang');
        $fullLangPath = Director::baseFolder() . '/' . str_replace([':', '\\'], '/', $langPath);

        $translationFiles = glob($fullLangPath . '/*.yml');
        if (!$translationFiles) {
            $this->message("No YML files found in $fullLangPath");
            return;
        }

        // Load configured source language messages
        $sourceMessages = [];
        $sourceFile = $fullLangPath . '/' . $sourceLang . '.yml';
        if (file_exists($sourceFile)) {
             $reader = new YamlReader;
             $sourceMessages = $reader->read($sourceLang, $sourceFile);
        } else {
            $this->message("Source language file $sourceLang.yml not found. Using default.");
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

            $reader = new YamlReader();
            $messages = $reader->read($lang, $file);
            $count = 0;
            $updated = false;

            // Find missing keys from source
            foreach ($sourceMessages as $key => $sourceVal) {
                if ($count >= $limit) {
                    break;
                }

                // Check if key exists in target
                $currentVal = $messages[$key] ?? null;

                // Translate if missing or empty
                if (empty($currentVal) && !empty($sourceVal)) {
                    // Context
                    $context = null;
                    $keyParts = explode('.', $key);
                    if (count($keyParts) > 1) {
                         $className = basename(str_replace('\\', '/', $keyParts[0]));
                         $fieldName = end($keyParts);
                         $context = "Field '$fieldName' in '$className'";
                    }

                    // Translate
                    try {
                        if (is_array($sourceVal)) {
                            // Skip array values for now or handle plural
                             continue;
                        }
                        
                        $this->message("Translating '$key' ($lang)...");
                        $translated = $translator->translateWithReference($sourceVal, $lang, $sourceLang, $sourceVal, $sourceLang, $context);
                        
                        if ($translated) {
                            $messages[$key] = $translated;
                            $count++;
                            $updated = true;
                        }
                    } catch (Exception $e) {
                         $this->message("Error translating $key: " . $e->getMessage(), "error");
                    }
                }
            }

            if ($updated) {
                if ($write) {
                    $this->message("Writing $count new translations to $lang.yml");
                    // Writer expects entities, locale, path
                    $writer->write($messages, $lang, $fullLangPath);
                } else {
                    $this->message("Would write $count new translations to $lang.yml (Dry Run)");
                }
            } else {
                $this->message("$lang.yml is up to date with source.");
            }
        }
    }

    public function isEnabled(): bool
    {
        return Director::isDev();
    }
}
