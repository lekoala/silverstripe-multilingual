<?php

namespace LeKoala\Multilingual;

use DeepL\DeepLClient;
use DeepL\TranslatorOptions;
use Exception;
use SilverStripe\Core\Environment;
use SilverStripe\Control\Director;

/**
 * @link https://developers.deepl.com/api-reference/multilingual-glossaries
 */
class DeeplTranslator implements TranslatorInterface
{
    use TranslatorUtils;

    protected DeepLClient $client;

    public function __construct(?string $apiKey = null)
    {
        if (!$apiKey) {
            $apiKey = Environment::getEnv('DEEPL_API_KEY');
        }
        if (!$apiKey) {
            throw new Exception("DeepL API key is missing. Please set DEEPL_API_KEY env var or pass it in constructor.");
        }

        $options = [];
        $verify = true;

        // Check if we need to bypass SSL
        if (Environment::getEnv('DEEPL_DISABLE_SSL')) {
            $verify = false;
        } else {
            // Check if we need to fix SSL using composer ca-bundle
            $cainfo = ini_get('curl.cainfo');
            if (empty($cainfo) && class_exists('Composer\CaBundle\CaBundle')) {
                $verify = \Composer\CaBundle\CaBundle::getBundledCaBundlePath();
            }
        }

        // Apply custom client if we need special verification settings
        if ($verify !== true) {
            if (class_exists('GuzzleHttp\Client')) {
                // @phpstan-ignore-next-line
                $options[TranslatorOptions::HTTP_CLIENT] = new \GuzzleHttp\Client(['verify' => $verify]);
            } else {
                if ($verify === false) {
                    throw new Exception("To disable SSL, GuzzleHttp\Client must be available.");
                }
                // If we cannot verify using Guzzle, we fall back to default behavior
            }
        }

        $this->client = new DeepLClient($apiKey, $options);
    }

    protected function getGlossaryMapPath(): string
    {
        return Director::baseFolder() . '/app/lang/glossaries/map.json';
    }

    protected ?string $glossaryId = null;
    protected bool $glossaryIdChecked = false;

    /**
     * Get the glossary ID for translation requests.
     * V3 API uses one multilingual glossary — DeepL resolves the correct dictionary.
     *
     * @return string|null
     */
    protected function getGlossaryId(): ?string
    {
        if ($this->glossaryIdChecked) {
            return $this->glossaryId;
        }
        $this->glossaryId = null;
        $path = $this->getGlossaryMapPath();
        if (file_exists($path)) {
            $map = json_decode(file_get_contents($path), true);
            $this->glossaryId = $map['glossary_id'] ?? null;
        }
        $this->glossaryIdChecked = true;
        return $this->glossaryId;
    }

    public function translate(?string $string, string $to, string $from, ?string $context = null): string
    {
        if (!$string) {
            return '';
        }
        $options = [];
        if ($context) {
            $options['context'] = $context;
        }

        $glossaryId = $this->getGlossaryId();
        if ($glossaryId) {
            $options['glossary_id'] = $glossaryId;
        }

        $to = $this->normalizeToCode($to);
        $from = $this->normalizeFromCode($from);

        $result = $this->client->translateText($string, $from, $to, $options);
        $translation = $result->text;

        return $this->fixVariables($string, $translation);
    }

    public function translateWithReference(?string $string, string $to, string $from, string $refString, string $refLang, ?string $context = null): string
    {
        // DeepL doesn't support reference translation directly.
        // We fallback to standard translation.
        return $this->translate($string, $to, $from, $context);
    }

    public function translateBatch(array $entries, string $to, string $from): array
    {
        // Group by context as DeepL options apply to the whole batch request
        $groups = [];
        foreach ($entries as $entry) {
            $ctx = $entry['context'] ?? '';
            $groups[$ctx][] = $entry;
        }

        $to = $this->normalizeToCode($to);
        $from = $this->normalizeFromCode($from);

        $results = [];
        foreach ($groups as $ctx => $groupEntries) {
            $groupTexts = array_column($groupEntries, 'value');
            $groupKeys = array_column($groupEntries, 'key');
            $options = [];
            if ($ctx) {
                $options['context'] = $ctx;
            }

            $glossaryId = $this->getGlossaryId();
            if ($glossaryId) {
                $options['glossary_id'] = $glossaryId;
            }

            try {
                $translations = $this->client->translateText($groupTexts, $from, $to, $options);
                // Ensure array result
                if (!is_array($translations)) {
                    $translations = [$translations];
                }

                foreach ($translations as $idx => $tResult) {
                    $key = $groupKeys[$idx];
                    $results[$key] = $this->fixVariables($groupEntries[$idx]['value'], $tResult->text);
                }
            } catch (Exception $e) {
                // Return empty or partial results? For now let it bubble or log?
                // The interface expects array return. If failure, keys will be missing.
            }
        }

        return $results;
    }

    public function review(string $string, ?string $translation, string $to, string $from, ?string $context = null): array
    {
        // Simulate review by re-translating and comparing
        $newTranslation = $this->translate($string, $to, $from, $context);

        if ($newTranslation !== $translation) {
            return [
                'valid' => false,
                'correction' => $newTranslation,
                'comment' => 'DeepL suggestion'
            ];
        }

        return ['valid' => true, 'correction' => null, 'comment' => null];
    }

    public function reviewBatch(array $entries, string $to, string $from): array
    {
        // Re-implement review logic using translateBatch for efficiency
        $translateEntries = array_map(function ($e) {
            return ['key' => $e['key'], 'value' => $e['source'], 'context' => $e['context'] ?? null];
        }, $entries);

        $translations = $this->translateBatch($translateEntries, $to, $from);

        $results = [];
        foreach ($entries as $entry) {
            $key = $entry['key'];
            $newTrans = $translations[$key] ?? null;
            $oldTrans = $entry['translation'];

            if ($newTrans && $newTrans !== $oldTrans) {
                $results[$key] = [
                    'valid' => false,
                    'correction' => $newTrans,
                    'comment' => 'DeepL suggestion'
                ];
            } else {
                $results[$key] = ['valid' => true, 'correction' => null];
            }
        }
        return $results;
    }

    /**
     * Normalize target language code.
     * DeepL requires regional variants for EN and PT.
     *
     * @param string $code
     * @return string
     */
    protected function normalizeToCode(string $code): string
    {
        $code = strtoupper(str_replace('_', '-', $code));
        if ($code === 'EN') {
            return 'EN-US';
        }
        if ($code === 'PT') {
            return 'PT-PT';
        }
        return $code;
    }

    /**
     * Normalize source language code.
     *
     * @param string $code
     * @return string
     */
    protected function normalizeFromCode(string $code): string
    {
        return strtoupper(str_replace('_', '-', $code));
    }
}
