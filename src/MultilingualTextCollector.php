<?php

namespace LeKoala\Multilingual;

use Exception;
use SilverStripe\Dev\Debug;
use SilverStripe\View\SSViewer;
use SilverStripe\Control\Director;
use SilverStripe\Core\Manifest\Module;
use SilverStripe\i18n\Messages\Reader;
use SilverStripe\i18n\Messages\Writer;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\i18n\TextCollection\Parser;
use SilverStripe\i18n\TextCollection\i18nTextCollector;
use SilverStripe\Core\Path;
use SilverStripe\Core\Environment;
use LeKoala\Multilingual\GlossaryTask;

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

        $modules = $this->getModulesAndThemesExposed();

        // Write each module language file
        foreach ($entitiesByModule as $moduleName => $entities) {
            // Skip empty translations
            if (empty($entities)) {
                continue;
            }

            // Clean sorting prior to writing
            ksort($entities);
            $module = $modules[$moduleName];
            $this->write($module, $entities);
        }

        return $entitiesByModule;
    }

    public function write(Module $module, $entities)
    {
        $cleanEntities = [];
        foreach ($entities as $key => $spec) {
            $cleanEntities[$key] = $this->flattenSpec($spec);
        }
        return parent::write($module, $cleanEntities);
    }

    public function flattenSpec($spec)
    {
        if (!is_array($spec)) {
            return $spec;
        }
        // If it's a rich spec with 'default'
        if (isset($spec['default'])) {
            return $spec['default'];
        }
        // If it's a plural spec (no 'default' but has 'one', 'other', etc.)
        // We keep it as is because YamlWriter handles plurals
        return $spec;
    }

    protected function getModulesAndThemesExposed()
    {
        $refObject = new \ReflectionObject($this);
        $refMethod = $refObject->getMethod('getModulesAndThemes');
        $refMethod->setAccessible(true);
        return $refMethod->invoke($this);
    }

    protected function getModuleNameExposed($arg1, $arg2)
    {
        $refObject = new \ReflectionObject($this);
        $refMethod = $refObject->getMethod('getModuleName');
        $refMethod->setAccessible(true);
        return $refMethod->invoke($this, $arg1, $arg2);
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
        if ($mergeWithExisting === null) {
            $mergeWithExisting = $this->getMergeWithExisting();
        } else {
            $this->setMergeWithExisting($mergeWithExisting);
        }
        if ($restrictToModules === null) {
            $restrictToModules = $this->getRestrictToModules();
        } else {
            $this->setRestrictToModules($restrictToModules);
        }

        return parent::collect($restrictToModules, $mergeWithExisting);
    }

    /**
     * Collect all entities grouped by module
     *
     * @return array
     */
    protected function getEntitiesByModule()
    {
        $allModules = $this->getModulesAndThemesExposed();
        $modules = [];
        foreach ($this->restrictToModules as $m) {
            if (array_key_exists($m, $allModules)) {
                $modules[$m] = $allModules[$m];
            }
        }

        // A master string tables array (one mst per module)
        $entitiesByModule = [];
        foreach ($modules as $moduleName => $module) {
            // we store the master string tables
            $processedEntities = $this->processModule($module);
            $moduleName = $this->getModuleNameExposed($moduleName, $module);
            if (isset($entitiesByModule[$moduleName])) {
                $entitiesByModule[$moduleName] = array_merge_recursive(
                    $entitiesByModule[$moduleName],
                    $processedEntities
                );
            } else {
                $entitiesByModule[$moduleName] = $processedEntities;
            }

            // Extract all entities for "foreign" modules ('module' key in array form)
            // @see CMSMenu::provideI18nEntities for an example usage
            foreach ($entitiesByModule[$moduleName] as $fullName => $spec) {
                $specModuleName = $moduleName;

                // Rewrite spec if module is specified
                if (is_array($spec) && isset($spec['module'])) {
                    // Normalise name (in case non-composer name is specified)
                    $specModule = ModuleLoader::inst()->getManifest()->getModule($spec['module']);
                    if ($specModule) {
                        $specModuleName = $specModule->getName();
                    }
                    unset($spec['module']);

                    // If only element is default, simplify
                    if (count($spec ?? []) === 1 && !empty($spec['default'])) {
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
     * Merge all entities with existing strings
     *
     * @param array<string,mixed> $entitiesByModule
     * @return array<string,mixed>|null
     */
    protected function mergeWithExisting($entitiesByModule)
    {
        $collectedKeys = [];
        if ($this->getClearUnused()) {
            foreach ($entitiesByModule as $moduleName => $entities) {
                $collectedKeys[$moduleName] = array_keys($entities);
            }
        }

        $entitiesByModule = parent::mergeWithExisting($entitiesByModule);

        // Filter out keys no longer present in the source code if clearUnused is enabled
        if ($this->getClearUnused()) {
            foreach ($entitiesByModule as $moduleName => $entities) {
                if (isset($collectedKeys[$moduleName])) {
                    $entitiesByModule[$moduleName] = array_intersect_key($entities, array_flip($collectedKeys[$moduleName]));
                }
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
            $normalizedKey = $this->normalizeEntity($entity, $namespace);
            if (!is_array($spec)) {
                $spec = ['default' => $spec];
            }
            $spec['file'] = $fileName;
            $entities[$normalizedKey] = $spec;
        }
        ksort($entities);

        return $entities;
    }

    public function collectFromCode($content, $fileName, Module $module)
    {
        $entities = parent::collectFromCode($content, $fileName, $module);
        foreach ($entities as $key => $spec) {
            if (!is_array($spec)) {
                $spec = ['default' => $spec];
            }
            $spec['file'] = $fileName;
            $entities[$key] = $spec;
        }
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
     * @param string $source
     * @param string $target
     * @return boolean
     */
    public function checkVariables($source, $target)
    {
        if (empty($source) || empty($target)) {
            return true;
        }
        preg_match_all('/\{(\w+)\}/', $source, $sourceMatches);
        preg_match_all('/\{(\w+)\}/', $target, $targetMatches);

        $sourceVars = $sourceMatches[1];
        $targetVars = $targetMatches[1];

        sort($sourceVars);
        sort($targetVars);

        return $sourceVars === $targetVars;
    }

    /**
     * @param string $driver
     * @return self
     */
    public function setTranslatorDriver(string $driver)
    {
        return $this;
    }

    /**
     * @param string $module
     * @param string $sourceLang
     * @param string $targetLang
     * @return array<string,string>
     */
    protected function loadGlossary($module, string $sourceLang, string $targetLang): array
    {
        return [];
    }

    protected function appendGlossaryToContext(?string $context, array $glossary, ?string $sourceContent = null): string
    {
        return $context ?? "";
    }
}
