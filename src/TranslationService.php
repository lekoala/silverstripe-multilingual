<?php

namespace LeKoala\Multilingual;

use Exception;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Dev\Debug;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\i18n\Messages\Reader;
use SilverStripe\i18n\Messages\Writer;
use SilverStripe\Core\Path;

/**
 * Shared service for translation and review logic
 */
class TranslationService
{
    use Configurable;
    use Injectable;
    use TranslatorUtils;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * @return TranslatorInterface
     */
    public function getTranslator(): TranslatorInterface
    {
        return $this->translator;
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
     * @param string $moduleName
     * @param string $sourceLang
     * @param string $targetLang
     * @param array $options
     * @return array{messages:array,corrected:int,translated:int}
     */
    public function translateModule(string $moduleName, string $sourceLang, string $targetLang, array $options = []): array
    {
        $debug = $options['debug'] ?? false;
        $write = $options['write'] ?? false;
        $enrich = $options['enrich'] ?? false;
        $autoTranslate = $options['auto_translate'] ?? true;
        $reviewTranslations = $options['review'] ?? false;
        $batchSize = $options['batch_size'] ?? 15;
        $limit = $options['limit'] ?? 1000;

        $modules = ModuleLoader::inst()->getManifest()->getModules();
        $module = $modules[$moduleName] ?? null;
        if (!$module) {
            throw new Exception("Module $moduleName not found");
        }

        $sourceMessages = [];
        if ($enrich) {
            $collector = MultilingualTextCollector::create($sourceLang);
            // Collect will return rich context
            $entitiesByModule = $collector->collect([$moduleName], true);
            $sourceMessages = $entitiesByModule[$moduleName] ?? [];
        } else {
            $reader = Injector::inst()->create(Reader::class);
            $sourceFile = Path::join($module->getPath(), 'lang', "$sourceLang.yml");
            if (file_exists($sourceFile)) {
                $sourceMessages = $reader->read($sourceLang, $sourceFile);
            }
        }

        $targetFile = Path::join($module->getPath(), 'lang', "$targetLang.yml");
        $reader = Injector::inst()->create(Reader::class);
        $targetMessages = [];
        if (file_exists($targetFile)) {
            $targetMessages = $reader->read($targetLang, $targetFile);
        }

        $translated = 0;
        $corrected = 0;

        // Auto translate missing keys
        if ($autoTranslate) {
            $toTranslate = array_diff_key($sourceMessages, $targetMessages);
            if (!empty($toTranslate)) {
                $batch = [];
                foreach ($toTranslate as $key => $spec) {
                    if ($translated >= $limit) break;

                    $context = $this->deriveContext($key, $spec);
                    $stringVal = is_array($spec) ? ($spec['default'] ?? '') : (string)$spec;

                    if (empty($stringVal)) continue;

                    $batch[] = [
                        'key' => $key,
                        'value' => $stringVal,
                        'context' => $context,
                        'original' => $spec,
                    ];

                    if (count($batch) >= $batchSize) {
                        $this->processTranslateBatch($batch, $targetLang, $sourceLang, $targetMessages);
                        $translated += count($batch);
                        $batch = [];
                    }
                }
                if (!empty($batch)) {
                    $this->processTranslateBatch($batch, $targetLang, $sourceLang, $targetMessages);
                    $translated += count($batch);
                }
            }
        }

        // Review existing translations
        if ($reviewTranslations) {
            $batch = [];
            foreach ($targetMessages as $key => $targetVal) {
                if ($corrected >= $limit) break;

                $sourceVal = $sourceMessages[$key] ?? null;
                if (!$sourceVal) continue;

                $sourceStr = is_array($sourceVal) ? ($sourceVal['default'] ?? '') : (string)$sourceVal;
                $targetStr = is_array($targetVal) ? ($targetVal['default'] ?? '') : (string)$targetVal;

                if (empty($sourceStr) || empty($targetStr) || $sourceStr === $targetStr) continue;

                $batch[] = [
                    'key' => $key,
                    'source' => $sourceStr,
                    'translation' => $targetStr,
                    'context' => $this->deriveContext($key, $sourceVal),
                ];

                if (count($batch) >= $batchSize) {
                    $corrected += $this->processReviewBatch($batch, $targetLang, $sourceLang, $targetMessages, $sourceMessages);
                    $batch = [];
                }
            }
            if (!empty($batch)) {
                $corrected += $this->processReviewBatch($batch, $targetLang, $sourceLang, $targetMessages, $sourceMessages);
            }
        }

        return [
            'messages' => $targetMessages,
            'translated' => $translated,
            'corrected' => $corrected,
        ];
    }

    protected function processTranslateBatch(array $batch, string $targetLang, string $sourceLang, array &$targetMessages)
    {
        $results = $this->translator->translateBatch($batch, $targetLang, $sourceLang);
        foreach ($batch as $entry) {
            $key = $entry['key'];
            if (isset($results[$key])) {
                $targetMessages[$key] = $results[$key];
            }
        }
    }

    protected function processReviewBatch(array $batch, string $targetLang, string $sourceLang, array &$targetMessages, array $sourceMessages): int
    {
        $corrected = 0;
        $results = $this->translator->reviewBatch($batch, $targetLang, $sourceLang);
        foreach ($results as $key => $result) {
            if (!$result['valid'] && $result['correction']) {
                $sourceVal = $sourceMessages[$key] ?? '';
                $sourceStr = is_array($sourceVal) ? ($sourceVal['default'] ?? '') : (string)$sourceVal;

                if ($this->checkVariables($sourceStr, $result['correction'])) {
                    $targetMessages[$key] = $result['correction'];
                    $corrected++;
                }
            }
        }
        return $corrected;
    }

    /**
     * Derive context from an entity key and its value
     *
     * @param string $key Entity key (e.g. "SilverStripe\\Security\\Member.FIRSTNAME")
     * @param mixed $value Entity value (string or array with 'context' key)
     * @return string|null
     */
    public function deriveContext(string $key, $value): ?string
    {
        $context = null;
        if (is_array($value)) {
            $context = $value['context'] ?? null;
        }
        if (!$context) {
            $keyParts = explode('.', $key);
            if (count($keyParts) > 1) {
                $className = basename(str_replace('\\', '/', $keyParts[0]));
                $fieldName = end($keyParts);
                $context = "Field '$fieldName' in '$className'";
            }
        }
        return $context;
    }

    /**
     * @param string $modulePath
     * @param string $sourceLang
     * @param string $targetLang
     * @return array<string,string>
     */
    public function loadGlossary(string $modulePath, string $sourceLang, string $targetLang): array
    {
        $path = $modulePath . '/lang/glossaries';
        $file = "$path/$sourceLang-$targetLang.csv";

        if (!file_exists($file)) {
            return [];
        }

        $entries = [];
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (count($data) >= 2) {
                    $entries[trim($data[0])] = trim($data[1]);
                }
            }
            fclose($handle);
        }
        return $entries;
    }

    /**
     * @param string|null $context
     * @param array $glossary
     * @param string|null $sourceContent
     * @return string
     */
    public function appendGlossaryToContext(?string $context, array $glossary, ?string $sourceContent = null): string
    {
        $filteredGlossary = [];
        // Filter based on source content if provided
        if ($sourceContent) {
            foreach ($glossary as $term => $translation) {
                // Case insensitive check
                if (stripos($sourceContent, $term) !== false) {
                    $filteredGlossary[$term] = $translation;
                }
            }
        } else {
            $filteredGlossary = $glossary;
        }

        if (empty($filteredGlossary)) {
            return $context ?? "";
        }

        $context = $context ? "$context. " : "";
        $context .= "IMPORTANT: The following JSON contains mandatory translations (case insensitive). You MUST use these values:\n";
        $context .= json_encode($filteredGlossary);
        return trim($context);
    }
}
