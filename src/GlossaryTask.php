<?php

namespace LeKoala\Multilingual;

use Exception;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\i18n\Messages\YamlReader;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use DeepL\DeepLClient;
use DeepL\MultilingualGlossaryDictionaryEntries;
use SilverStripe\ORM\ArrayLib;

/**
 * Manage DeepL Glossaries
 *
 * Actions:
 * - generate: Scan YML files for single-word translations and create CSV candidates
 * - sync: Upload local CSVs to DeepL as a single multilingual glossary
 * - list: Show remote glossaries
 */
class GlossaryTask extends BuildTask
{
    use BuildTaskTools;

    private static $segment = 'GlossaryTask';
    protected $title = "Glossary Task";
    protected $description = "Manage text glossaries (generate, sync, list)";

    const GLOSSARY_NAME = 'SilverStripe Glossary';

    /**
     * @param \SilverStripe\Control\HTTPRequest $request
     * @return void
     */
    public function run($request)
    {
        $this->request = $request;

        $actions = ArrayLib::valuekey(['generate', 'sync', 'list']);
        $this->addOption("action", "Action to perform", null, $actions);
        $this->addOption("module", "Module to scan (for generate)", 'app');
        $this->addOption("source_lang", "Source language", 'en');
        $this->addOption("target_lang", "Target language (for sync/generate specific)", null);
        $this->addOption("min_length", "Minimum length for glossary terms", 3);

        $options = $this->askOptions();

        $action = $options['action'];

        if ($action === 'generate') {
            $this->generate($options['module'], $options['source_lang'], $options['target_lang'], $options['min_length']);
        } elseif ($action === 'sync') {
            $this->sync($options['module'], $options['source_lang'], $options['target_lang']);
        } elseif ($action === 'list') {
            $this->listGlossaries();
        }
    }

    protected function getClient(): DeepLClient
    {
        $key = Environment::getEnv('DEEPL_API_KEY');
        if (!$key) {
            throw new Exception("DeepL API key is missing. Set DEEPL_API_KEY env var.");
        }
        return new DeepLClient($key);
    }

    protected function getLangPath(string $module): string
    {
        $langPath = ModuleResourceLoader::resourcePath($module . ':lang');
        return Director::baseFolder() . '/' . str_replace([':', '\\'], '/', $langPath);
    }

    /**
     * @return string Path to glossaries directory (created if missing)
     */
    protected function getGlossaryPath(string $module): string
    {
        $path = $this->getLangPath($module) . '/glossaries';
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        return $path;
    }

    /**
     * Scan YML files for single-word translations and create CSV glossary candidates
     */
    protected function generate(string $module, string $sourceLang, ?string $targetLangFilter = null, int $minLength = 3): void
    {
        $fullLangPath = $this->getLangPath($module);
        $glossaryPath = $this->getGlossaryPath($module);

        $sourceFile = $fullLangPath . '/' . $sourceLang . '.yml';
        if (!file_exists($sourceFile)) {
            $this->message("Source file not found: $sourceFile", "error");
            return;
        }

        $reader = new YamlReader;
        $sourceMessages = $reader->read($sourceLang, $sourceFile);

        $files = glob($fullLangPath . '/*.yml');
        foreach ($files as $file) {
            $targetLang = pathinfo($file, PATHINFO_FILENAME);
            if ($targetLang === $sourceLang) {
                $this->message("Target lang must be different than source lang", "error");
                continue;
            }
            if ($targetLangFilter && $targetLang !== $targetLangFilter) {
                continue;
            }

            $messages = $reader->read($targetLang, $file);
            $entries = [];
            $conflicts = [];

            foreach ($sourceMessages as $key => $sourceVal) {
                if (!is_string($sourceVal)) {
                    continue;
                }
                $targetVal = $messages[$key] ?? null;
                if (!$targetVal || !is_string($targetVal)) {
                    continue;
                }

                // Only single words (no spaces), meeting minimum length
                if (strpos($sourceVal, ' ') !== false || strpos($targetVal, ' ') !== false) {
                    continue;
                }
                if (strlen($sourceVal) < $minLength) {
                    continue;
                }

                // Deduplicate: warn on conflicting mappings for same source word
                $normalizedSource = mb_strtolower($sourceVal);
                if (isset($entries[$normalizedSource]) && $entries[$normalizedSource] !== $targetVal) {
                    $conflicts[$normalizedSource] = true;
                    continue;
                }
                $entries[$normalizedSource] = $targetVal;
            }

            if (!empty($conflicts)) {
                $this->message("Skipped " . count($conflicts) . " conflicting entries for $sourceLang-$targetLang: " . implode(', ', array_keys($conflicts)), "warning");
            }

            if (!empty($entries)) {
                $filename = "$glossaryPath/$sourceLang-$targetLang.csv";
                $fp = fopen($filename, 'w');
                foreach ($entries as $src => $trg) {
                    fputcsv($fp, [$src, $trg]);
                }
                fclose($fp);
                $this->message("Generated $sourceLang-$targetLang with " . count($entries) . " entries → $filename");
            } else {
                $this->message("No glossary candidates found for $sourceLang-$targetLang");
            }
        }
    }

    /**
     * Parse local CSV file into associative array of source => target
     * @return array<string, string>
     */
    protected function parseCsv(string $file): array
    {
        $entries = [];
        $fp = fopen($file, 'r');
        while (($row = fgetcsv($fp)) !== false) {
            if (count($row) >= 2 && trim($row[0]) !== '' && trim($row[1]) !== '') {
                $entries[trim($row[0])] = trim($row[1]);
            }
        }
        fclose($fp);
        return $entries;
    }

    /**
     * Sync local CSV glossaries to DeepL using V3 Multilingual Glossary API
     *
     * Strategy: Maintain ONE glossary with multiple dictionaries (one per language pair).
     * Uses replaceMultilingualGlossaryDictionary to update individual pairs without
     * affecting other dictionaries in the same glossary.
     */
    protected function sync(string $module, string $sourceLang, ?string $targetLangFilter = null): void
    {
        $client = $this->getClient();
        $glossaryPath = $this->getGlossaryPath($module);
        $files = glob($glossaryPath . '/*.csv');

        if (empty($files)) {
            $this->message("No CSV files found in $glossaryPath. Run action=generate first.");
            return;
        }

        $mapFile = $glossaryPath . '/map.json';
        $map = [];
        if (file_exists($mapFile)) {
            $map = json_decode(file_get_contents($mapFile), true) ?: [];
        }

        // Find or create our master glossary
        $glossaryId = $map['glossary_id'] ?? null;
        $glossary = null;

        if ($glossaryId) {
            try {
                $glossary = $client->getMultilingualGlossary($glossaryId);
                $this->message("Using existing glossary: " . $glossary->name . " ($glossaryId)");
            } catch (Exception $e) {
                $this->message("Stored glossary ID invalid, will create new one.");
                $glossary = null;
            }
        }

        // Build all dictionaries from CSV files
        $dictionaries = [];
        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME); // e.g. en-fr
            $parts = explode('-', $name);
            if (count($parts) !== 2) {
                continue;
            }
            [$sLang, $tLang] = $parts;

            if ($targetLangFilter && $tLang !== $targetLangFilter) {
                continue;
            }

            $entries = $this->parseCsv($file);
            if (empty($entries)) {
                $this->message("Skipping empty file: $name.csv");
                continue;
            }

            $dictionaries[] = new MultilingualGlossaryDictionaryEntries($sLang, $tLang, $entries);
            $this->message("Prepared $name with " . count($entries) . " entries");
        }

        if (empty($dictionaries)) {
            $this->message("No valid dictionaries to sync.");
            return;
        }

        try {
            if (!$glossary) {
                // Create new multilingual glossary with all dictionaries
                $glossary = $client->createMultilingualGlossary(self::GLOSSARY_NAME, $dictionaries);
                $this->message("Created glossary: " . $glossary->glossaryId);
            } else {
                // Replace each dictionary individually
                foreach ($dictionaries as $dict) {
                    $client->replaceMultilingualGlossaryDictionary($glossary, $dict);
                    $this->message("Replaced dictionary: {$dict->sourceLang}-{$dict->targetLang}");
                }
            }

            // Save map
            $map['glossary_id'] = $glossary->glossaryId;
            $map['name'] = $glossary->name ?? self::GLOSSARY_NAME;
            $map['synced_at'] = date('c');

            file_put_contents($mapFile, json_encode($map, JSON_PRETTY_PRINT));
            $this->message("Saved map to $mapFile");
        } catch (Exception $e) {
            $this->message("Sync failed: " . $e->getMessage(), "error");
        }
    }

    /**
     * List all glossaries on the DeepL account
     */
    protected function listGlossaries(): void
    {
        $client = $this->getClient();
        try {
            $list = $client->listMultilingualGlossaries();
            if (empty($list)) {
                $this->message("No glossaries found.");
                return;
            }
            foreach ($list as $g) {
                $dicts = [];
                foreach ($g->dictionaries as $d) {
                    $dicts[] = "{$d->sourceLang}->{$d->targetLang} ({$d->entryCount})";
                }
                $this->message("• {$g->name} (ID: {$g->glossaryId}) — " . implode(', ', $dicts));
            }
        } catch (Exception $e) {
            $this->message("Error: " . $e->getMessage(), "error");
        }
    }

    public function isEnabled(): bool
    {
        return Director::isDev();
    }
}
