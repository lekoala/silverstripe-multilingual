<?php

namespace LeKoala\Base\i18n;

use Exception;
use SilverStripe\Dev\Debug;
use SilverStripe\View\SSViewer;
use SilverStripe\Control\Director;
use LeKoala\Multilingual\LangHelper;
use SilverStripe\Core\Manifest\Module;
use SilverStripe\i18n\Messages\Reader;
use SilverStripe\i18n\Messages\Writer;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\i18n\TextCollection\Parser;
use LeKoala\Base\Helpers\GoogleTranslateHelper;
use SilverStripe\i18n\TextCollection\i18nTextCollector;

/**
 * Improved text collector
 */
class MultilingualTextCollector extends i18nTextCollector
{
    /**
     * @var boolean
     */
    protected $debug = false;

    /**
     * @var boolean
     */
    protected $clearUnused = false;

    /**
     * @var array<string>
     */
    protected $restrictToModules = [];

    /**
     * @var boolean
     */
    protected $mergeWithExisting = true;

    /**
     * @var boolean
     */
    protected $preventWrite = false;

    /**
     * @var boolean
     */
    protected $autoTranslate = false;

    /**
     * @param ?string $locale
     */
    public function __construct($locale = null)
    {
        parent::__construct($locale);

        // Somehow the injector is confused so we inject ourself
        $this->reader = Injector::inst()->create(Reader::class);
        $this->writer = Injector::inst()->create(Writer::class);
    }

    /**
     * This is the main method to build the master string tables with the
     * original strings. It will search for existent modules that use the
     * i18n feature, parse the _t() calls and write the resultant files
     * in the lang folder of each module.
     *
     * @param array<string> $restrictToModules
     * @param bool $mergeWithExisting Merge new master strings with existing
     * ones already defined in language files, rather than replacing them.
     * This can be useful for long-term maintenance of translations across
     * releases, because it allows "translation backports" to older releases
     * without removing strings these older releases still rely on.
     * @return array<string,mixed>|null $result
     */
    public function run($restrictToModules = null, $mergeWithExisting = false)
    {
        $entitiesByModule = $this->collect($restrictToModules, $mergeWithExisting);
        if (empty($entitiesByModule)) {
            Debug::message("No entities have been collected");
            return null;
        }
        if ($this->debug) {
            Debug::message("Debug mode is enabled and no files have been written");
            Debug::dump($entitiesByModule);
            return null;
        }

        // Write each module language file
        foreach ($entitiesByModule as $moduleName => $entities) {
            // Skip empty translations
            if (empty($entities)) {
                continue;
            }

            // Clean sorting prior to writing
            ksort($entities);
            $module = ModuleLoader::inst()->getManifest()->getModule($moduleName);
            $this->write($module, $entities);
        }

        return $entitiesByModule;
    }

    /**
     * Extract all strings from modules and return these grouped by module name
     *
     * @param array<string> $restrictToModules
     * @param bool $mergeWithExisting
     * @return array<string,mixed>|null
     */
    public function collect($restrictToModules = null, $mergeWithExisting = null)
    {
        if ($restrictToModules === null) {
            $restrictToModules = $this->getRestrictToModules();
        } else {
            $this->setRestrictToModules($restrictToModules);
        }

        // Restrict modules we update to just the specified ones (if any passed)
        if (!empty($restrictToModules)) {
            // Normalise module names
            $modules = array_filter(array_map(function ($name) {
                $module = ModuleLoader::inst()->getManifest()->getModule($name);
                //@phpstan-ignore-next-line
                return $module ? $module->getName() : null;
            }, $restrictToModules));
            // No module, throw an exception
            if (empty($modules)) {
                $availableModules = array_keys(ModuleLoader::inst()->getManifest()->getModules());
                throw new Exception("Could not find any of these modules : " . implode(', ', $restrictToModules) . ". Available modules are : " . implode(', ', $availableModules));
            }
        }

        if ($mergeWithExisting === null) {
            $mergeWithExisting = $this->getMergeWithExisting();
        } else {
            $this->setMergeWithExisting($mergeWithExisting);
        }

        $entitiesByModule = $this->getEntitiesByModule();

        // Optionally merge with existing master strings
        if ($mergeWithExisting) {
            $entitiesByModule = $this->mergeWithExisting($entitiesByModule);
        }

        return $entitiesByModule;
    }

    /**
     * Merge all entities with existing strings
     *
     * @param array<string,mixed> $entitiesByModule
     * @return array<string,mixed>|null
     */
    protected function mergeWithExisting($entitiesByModule)
    {
        // For each module do a simple merge of the default yml with these strings
        foreach ($entitiesByModule as $module => $messages) {
            $moduleInst = ModuleLoader::inst()->getManifest()->getModule($module);
            $modulePath = $moduleInst->getRelativePath();

            // Load existing localisations
            $masterFile = "{$this->basePath}/{$modulePath}/lang/{$this->defaultLocale}.yml";

            // YamlReader fails silently if path is not correct
            if (!is_file($masterFile)) {
                throw new Exception("File $masterFile does not exist. Please collect without merge first.");
            }
            $existingMessages = $this->getReader()->read($this->defaultLocale, $masterFile);

            // Merge
            if (!$existingMessages) {
                throw new Exception("No existing messages were found in $masterFile. Please collect without merge first.");
            }

            $newMessages = array_diff_key($messages, $existingMessages);

            // attempt auto translation
            if ($this->autoTranslate) {
                foreach ($newMessages as $newMessageKey => $newMessageVal) {
                    try {
                        if (is_array($newMessageVal)) {
                            $result = [];
                            foreach ($newMessageVal as $newMessageValItem) {
                                $result[] = GoogleTranslateHelper::translate($newMessageValItem, $this->defaultLocale);
                            }
                        } else {
                            $result = GoogleTranslateHelper::translate($newMessageVal, $this->defaultLocale);
                        }
                        sleep(1);
                        $messages[$newMessageKey] = $result;
                    } catch (Exception $ex) {
                        Debug::dump($ex->getMessage());
                    }
                }
            }

            if ($this->debug) {
                Debug::dump($existingMessages);
            }
            $entitiesByModule[$module] = array_merge(
                $messages,
                $existingMessages
            );

            // Clear unused
            if ($this->getClearUnused()) {
                $unusedEntities = array_diff(
                    array_keys($existingMessages),
                    array_keys($messages)
                );
                foreach ($unusedEntities as $unusedEntity) {
                    // Skip globals
                    if (strpos($unusedEntity, LangHelper::GLOBAL_ENTITY . '.') !== false) {
                        continue;
                    }
                    if ($this->debug) {
                        Debug::message("Removed $unusedEntity");
                    }
                    unset($entitiesByModule[$unusedEntity]);
                }
            }
        }
        return $entitiesByModule;
    }

    /**
     * Collect all entities grouped by module
     *
     * @return array<string,mixed>|null
     */
    protected function getEntitiesByModule()
    {
        $restrictToModules = $this->getRestrictToModules();
        if (empty($restrictToModules)) {
            throw new Exception("TextCollector does not support collecting from all modules");
        }

        // A master string tables array (one mst per module)
        $entitiesByModule = array();
        $modules = ModuleLoader::inst()->getManifest()->getModules();
        foreach ($modules as $module) {
            $moduleName = $module->getName();
            if (!in_array($moduleName, $restrictToModules)) {
                continue;
            }

            // we store the master string tables
            $processedEntities = $this->processModule($module);

            // in mysite, collect theme as well
            if ($moduleName == 'mysite' || $moduleName == 'app') {
                $themeEntities = $this->collectFromTheme($module);
                $processedEntities = array_merge($processedEntities, $themeEntities);
                ksort($processedEntities);
            }

            $entitiesByModule[$moduleName] = $processedEntities;

            // Extract all entities for "foreign" modules ('module' key in array form)
            foreach ($entitiesByModule[$moduleName] as $fullName => $spec) {
                if (!is_array($spec)) {
                    continue;
                }

                $specModuleName = $moduleName;

                // Rewrite spec if module is specified
                if (is_array($spec) && isset($spec['module'])) {
                    // Normalise name (in case non-composer name is specified)
                    $specModule = ModuleLoader::inst()->getManifest()->getModule($spec['module']);
                    //@phpstan-ignore-next-line
                    if ($specModule) {
                        $specModuleName = $specModule->getName();
                    }
                    unset($spec['module']);

                    // If only element is default, simplify
                    if (count($spec) === 1 && !empty($spec['default'])) {
                        $spec = $spec['default'];
                    }
                }

                // Remove from source module
                if ($specModuleName !== $moduleName) {
                    unset($entitiesByModule[$moduleName][$fullName]);
                }

                // Write to target module
                if (!isset($entitiesByModule[$specModuleName])) {
                    $entitiesByModule[$specModuleName] = [];
                }
                $entitiesByModule[$specModuleName][$fullName] = $spec;
            }
        }

        return $entitiesByModule;
    }

    /**
     * @param Module $module
     * @return array<string,mixed>|null
     */
    public function collectFromTheme(Module $module)
    {
        $themeDir = $this->getThemeDir();
        $themeFolder = Director::baseFolder() . '/' . $themeDir . '/Templates';

        $files = $this->getFilesRecursive($themeFolder, [], 'ss');

        $entities = [];
        foreach ($files as $file) {
            $fileContent = file_get_contents($file);
            if (!$fileContent) {
                continue;
            }
            $fileEntities = $this->collectFromTemplate($fileContent, $file, $module);
            if ($fileEntities) {
                $entities = array_merge($entities, $fileEntities);
            }
        }

        return $entities;
    }

    /**
     * Extracts translatables from .ss templates (Self referencing)
     *
     * @param string $content The text content of a parsed template-file
     * @param string $fileName The name of a template file when method is used in self-referencing mode
     * @param Module $module Module being collected
     * @param array<mixed> $parsedFiles
     * @return array<string,mixed>|null $entities An array of entities representing the extracted template function calls
     */
    public function collectFromTemplate($content, $fileName, Module $module, &$parsedFiles = [])
    {
        // Get namespace either from $fileName or $module fallback
        $namespace = $fileName ? basename($fileName) : $module->getName();

        // use parser to extract <%t style translatable entities
        $entities = Parser::getTranslatables($content, $this->getWarnOnEmptyDefault());

        // use the old method of getting _t() style translatable entities is forbidden
        if (preg_match_all('/(_t\([^\)]*?\))/ms', $content, $matches)) {
            throw new Exception("Old _t calls in $fileName are not allowed in templates. Please use <%t instead.");
        }

        foreach ($entities as $entity => $spec) {
            unset($entities[$entity]);
            $entities[$this->normalizeEntity($entity, $namespace)] = $spec;
        }
        ksort($entities);

        return $entities;
    }

    /**
     * Get current theme dir (regardless of current theme set)
     * This will work in admin for instance
     *
     * @return string
     */
    public function getThemeDir()
    {
        $themes = SSViewer::config()->themes;
        if (!$themes) {
            $themes = SSViewer::get_themes();
        }
        if ($themes) {
            do {
                $mainTheme = array_shift($themes);
            } while (strpos($mainTheme, '$') === 0);

            return 'themes/' . $mainTheme;
        }
        return project();
    }

    /**
     * @return boolean
     */
    public function isAdminTheme()
    {
        $themes = SSViewer::get_themes();
        if (empty($themes)) {
            return false;
        }
        $theme = $themes[0];
        return strpos($theme, 'silverstripe/admin') === 0;
    }

    /**
     * Get the value of clearUnused
     *
     * @return boolean
     */
    public function getClearUnused()
    {
        return $this->clearUnused;
    }

    /**
     * Set the value of clearUnused
     *
     * @param boolean $clearUnused
     *
     * @return self
     */
    public function setClearUnused($clearUnused)
    {
        $this->clearUnused = $clearUnused;
        return $this;
    }

    /**
     * Get the value of restrictToModules
     *
     * @return array<string>
     */
    public function getRestrictToModules()
    {
        return $this->restrictToModules;
    }

    /**
     * Set the value of restrictToModules
     *
     * @param array<string> $restrictToModules
     *
     * @return self
     */
    public function setRestrictToModules($restrictToModules)
    {
        $this->restrictToModules = $restrictToModules;
        return $this;
    }

    /**
     * Get the value of mergeWithExisting
     *
     * @return boolean
     */
    public function getMergeWithExisting()
    {
        return $this->mergeWithExisting;
    }

    /**
     * Set the value of mergeWithExisting
     *
     * @param boolean $mergeWithExisting
     *
     * @return self
     */
    public function setMergeWithExisting($mergeWithExisting)
    {
        $this->mergeWithExisting = $mergeWithExisting;
        return $this;
    }

    /**
     * Get the value of debug
     *
     * @return boolean
     */
    public function getDebug()
    {
        return $this->debug;
    }

    /**
     * Set the value of debug
     *
     * @param boolean $debug
     *
     * @return self
     */
    public function setDebug($debug)
    {
        $this->debug = $debug;
        return $this;
    }

    /**
     * Get the value of autoTranslate
     * @return boolean
     */
    public function getAutoTranslate()
    {
        return $this->autoTranslate;
    }

    /**
     * Set the value of autoTranslate
     *
     * @param boolean $autoTranslate
     * @return self
     */
    public function setAutoTranslate($autoTranslate)
    {
        $this->autoTranslate = $autoTranslate;
        return $this;
    }
}
