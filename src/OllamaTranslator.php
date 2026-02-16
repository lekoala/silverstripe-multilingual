<?php

namespace LeKoala\Multilingual;

use Exception;

/**
 * A simple ollama client to query models
 * @link https://ollama.com/thinkverse/towerinstruct
 * @link https://ollama.com/library/translategemma
 */
class OllamaTranslator
{
    final public const BASE_URL = 'http://localhost:11434';
    final public const BASE_MODEL = 'translategemma:4b';

    protected ?string $model;
    protected ?string $url;

    public function __construct(?string $model = null, ?string $url = null)
    {
        $this->model = $model ?? self::BASE_MODEL;
        $this->url = $url ?? self::BASE_URL;
    }

    public function expandLang(string $lang, bool $code = false): string
    {
        $lc = strtolower($lang);
        // English, Portuguese, Spanish, French, German, Dutch, Italian, Korean, Chinese, Russian
        $map = [
            'en' => 'English',
            'fr' => 'French',
            'nl' => 'Dutch',
            'it' => 'Italian',
            'de' => 'German',
            'pt' => 'Portuguese',
            'ko' => 'Korean',
            'zh' => 'Chinese',
            'ru' => 'Russian',
        ];

        if ($code) {
             // In case we passed a full name, try to find the code
             $sub = array_search($lang, $map);
            if ($sub) {
                return $sub;
            }
            foreach ($map as $k => $v) {
                if (strtolower($v) === $lc) {
                    return $k;
                }
            }
             return $lang;
        }

        return $map[$lc] ?? $lang;
    }

    /**
     * @param string $from
     * @param string $to
     * @param string $string
     * @param string|null $refString
     * @param string|null $refLang
     * @param string|null $context
     * @return string
     */
    protected function buildPrompt(string $from, string $to, string $string, ?string $refString = null, ?string $refLang = null, ?string $context = null): string
    {
        $fromCode = $this->expandLang($from, true);
        $fromName = $this->expandLang($from);
        $toCode = $this->expandLang($to, true);
        $toName = $this->expandLang($to);

        $prompt = "You are a professional $fromName ($fromCode) to $toName ($toCode) translator. Your goal is to accurately convey the meaning and nuances of the original $fromName text while adhering to $toName grammar, vocabulary, and cultural sensitivities.";
        if ($context) {
            // Limit context length to avoid token limit issues
            $context = mb_strimwidth($context, 0, 200, '...');
            $prompt .= "\nContext: $context";
        }
        if ($refString && $refLang) {
            $refLangName = $this->expandLang($refLang);
            $refLangCode = $this->expandLang($refLang, true);
            $prompt .= "\nUse the existing $refLangName ($refLangCode) translation as a reference: \"$refString\".";
        }
        $prompt .= "\nProduce only the $toName translation, without any additional explanations or commentary. Please translate the following $fromName text into $toName:\n\n\n$string";
        return $prompt;
    }

    /**
     * Translate a string using a reference translation
     *
     * @param string|null $string The string to translate
     * @param string $to Target language code
     * @param string $from Source language code
     * @param string $refString The reference translation string
     * @param string $refLang The reference language code
     * @param string|null $context Context for the translation
     * @return string
     */
    public function translateWithReference(?string $string, string $to, string $from, string $refString, string $refLang, ?string $context = null)
    {
        $string = $string ?? '';
        $prompt = $this->buildPrompt($from, $to, $string, $refString, $refLang, $context);

        return $this->processResponse($this->generate($prompt), $string);
    }

    public function translate(?string $string, string $to, string $from, ?string $context = null)
    {
        $string = $string ?? '';
        $prompt = $this->buildPrompt($from, $to, $string, null, null, $context);

        return $this->processResponse($this->generate($prompt), $string);
    }

    /**
     * Translate multiple strings in a single LLM call
     *
     * @param array<int,array{key:string,value:string,context:?string}> $entries
     * @param string $to Target language code
     * @param string $from Source language code
     * @return array<string,string> key => translated value
     */
    public function translateBatch(array $entries, string $to, string $from): array
    {
        if (empty($entries)) {
            return [];
        }

        $fromName = $this->expandLang($from);
        $fromCode = $this->expandLang($from, true);
        $toName = $this->expandLang($to);
        $toCode = $this->expandLang($to, true);

        $prompt = "You are a professional $fromName ($fromCode) to $toName ($toCode) translator.\n";
        $prompt .= "Translate each numbered line below. Return ONLY the translations, one per line, prefixed with the same number. ";
        $prompt .= "Do not add any explanations.\n\n";

        $keys = [];
        $originals = [];
        $i = 1;
        foreach ($entries as $entry) {
            $keys[$i] = $entry['key'];
            $originals[$i] = $entry['value'];
            $line = "$i. {$entry['value']}";
            if (!empty($entry['context'])) {
                $ctx = mb_strimwidth($entry['context'], 0, 100, '...');
                $line .= " [context: $ctx]";
            }
            $prompt .= "$line\n";
            $i++;
        }

        $result = $this->generate($prompt);
        $response = trim($result['response'] ?? '');
        $lines = preg_split('/\r?\n/', $response);

        $translations = [];
        foreach ($lines as $line) {
            $line = trim($line);
            // Match "1. Translation" or "1: Translation"
            if (preg_match('/^(\d+)[.\:)]\s*(.+)$/', $line, $m)) {
                $num = (int)$m[1];
                $translated = trim($m[2]);
                if (isset($keys[$num])) {
                    $original = $originals[$num];
                    $translated = $this->processResponse(['response' => $translated], $original);
                    $translations[$keys[$num]] = $translated;
                }
            }
        }

        return $translations;
    }

    /**
     * Review multiple translations in a single LLM call
     *
     * @param array<int,array{key:string,source:string,translation:string,context:?string}> $entries
     * @param string $to Target language code
     * @param string $from Source language code
     * @return array<string,array{valid:bool,correction:?string}> key => result
     */
    public function reviewBatch(array $entries, string $to, string $from): array
    {
        if (empty($entries)) {
            return [];
        }

        $fromName = $this->expandLang($from);
        $toName = $this->expandLang($to);

        $prompt = "You are a professional translation reviewer.\n";
        $prompt .= "For each numbered entry, respond with the same number followed by VALID or INVALID: correction.\n\n";
        $prompt .= "Example responses:\n";
        $prompt .= "1. VALID\n";
        $prompt .= "2. INVALID: Au revoir\n\n";
        $prompt .= "Entries to review:\n";

        $keys = [];
        $i = 1;
        foreach ($entries as $entry) {
            $keys[$i] = $entry['key'];
            $line = "$i. Source ($fromName): {$entry['source']} | Translation ($toName): {$entry['translation']}";
            if (!empty($entry['context'])) {
                $ctx = mb_strimwidth($entry['context'], 0, 100, '...');
                $line .= " [context: $ctx]";
            }
            $prompt .= "$line\n";
            $i++;
        }

        $result = $this->generate($prompt);
        $response = trim($result['response'] ?? '');
        $lines = preg_split('/\r?\n/', $response);

        $results = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^(\d+)[.\:)]\s*(.+)$/', $line, $m)) {
                $num = (int)$m[1];
                $verdict = trim($m[2]);
                if (isset($keys[$num])) {
                    if (str_starts_with(strtoupper($verdict), 'VALID')) {
                        $results[$keys[$num]] = ['valid' => true, 'correction' => null];
                    } else {
                        $correction = $verdict;
                        if (str_starts_with(strtoupper($verdict), 'INVALID:')) {
                            $correction = trim(substr($verdict, 8));
                        }
                        $results[$keys[$num]] = ['valid' => false, 'correction' => $correction];
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Review a translation
     *
     * @param string $string Source string
     * @param string $translation Target string
     * @param string $to Target language
     * @param string $from Source language
     * @param string|null $context Context
     * @return array{valid:bool,correction:?string,comment:?string}
     */
    public function review(string $string, string $translation, string $to, string $from, ?string $context = null): array
    {
        $fromName = $this->expandLang($from);
        $toName = $this->expandLang($to);

        $prompt = "You are a professional translation reviewer.\n";
        $prompt .= "Task: Check if the translation is correct.\n\n";

        $prompt .= "Example 1:\n";
        $prompt .= "Source (English): Hello\n";
        $prompt .= "Translation (French): Bonjour\n";
        $prompt .= "Response: VALID\n\n";

        $prompt .= "Example 2:\n";
        $prompt .= "Source (English): Goodbye\n";
        $prompt .= "Translation (French): Bonjour\n";
        $prompt .= "Response: INVALID: Au revoir\n\n";

        $prompt .= "Example 3:\n";
        $prompt .= "Source (English): Title\n";
        $prompt .= "Translation (French): Nom\n";
        $prompt .= "Response: INVALID: Titre\n\n";

        $prompt .= "Now review the following:\n";
        if ($context) {
            $prompt .= "Context: $context\n";
        }
        $prompt .= "Source ($fromName): $string\n";
        $prompt .= "Translation ($toName): $translation\n";
        $prompt .= "Response:";

        $response = $this->generate($prompt)['response'];
        $response = trim($response);

        $valid = str_starts_with(strtoupper($response), 'VALID');
        $correction = null;
        $comment = null;

        if ($valid) {
             $comment = trim(substr($response, 5));
            if (empty($comment)) {
                $comment = null;
            }
        } else {
             // Try to extract correction
            if (str_starts_with(strtoupper($response), 'INVALID:')) {
                $correction = trim(substr($response, 8));
            } else {
                // Fallback if model didn't follow instruction perfectly
                $correction = $response;
            }
        }

        return [
            'valid' => $valid,
            'correction' => $correction,
            'comment' => $comment
        ];
    }

    protected function processResponse(array $result, string $originalString): string
    {
        $response = $result['response'] ?? '';

        // Avoid extra space
        $response = trim($response);

        // Make sure we don't get any extra ending dot
        $endsWithDot = str_ends_with($originalString, '.');
        $translationEndsWithDot = str_ends_with($response, '.');
        if (!$endsWithDot && $translationEndsWithDot) {
            $response = rtrim($response, '.');
        }

        // No crazy {}
        $includesParen = str_contains($originalString, '{}');
        $translationIncludesParen = str_contains($response, '{}');
        if (!$includesParen && $translationIncludesParen) {
            $response = trim(str_replace('{}', '', $response));
        }

        // No spaces in { }
        $response = str_replace('{ ', '{', $response);
        $response = str_replace(' }', '}', $response);

        // Verify variables
        $response = $this->fixVariables($originalString, $response);

        return $response;
    }

    /**
     * Ensure that variables in the original string are present in the translation
     * and restore them if they are translated or missing
     *
     * @param string $originalString
     * @param string $translation
     * @return string
     */
    protected function fixVariables(string $originalString, string $translation): string
    {
        // Extract variables from original string like {name}
        preg_match_all('/\{[a-zA-Z0-9_]+\}/', $originalString, $matches);
        $vars = $matches[0] ?? [];

        if (empty($vars)) {
            return $translation;
        }

        // Extract variables from translation
        preg_match_all('/\{[a-zA-Z0-9_]+\}/', $translation, $matchesTranslation);
        $varsTranslation = $matchesTranslation[0] ?? [];

        // If names match, all good
        if ($vars === $varsTranslation) {
            return $translation;
        }

        // If counts match, we assume order is preserved
        if (count($vars) === count($varsTranslation)) {
            foreach ($vars as $i => $var) {
                if ($varsTranslation[$i] !== $var) {
                     // Replace the translated variable with the original one
                     // We use preg_replace to replace only the first occurrence found
                     $translation = preg_replace('/' . preg_quote($varsTranslation[$i], '/') . '/', $var, $translation, 1);
                }
            }
        } else {
             // If counts don't match, it gets tricky.
             // Strategy: find variables in translation that are NOT in original, and try to map them to variables in original that are MISSING in translation
             // This is heuristic and might fail if there are multiple variables
             $missingVars = array_diff($vars, $varsTranslation);
             $extraVars = array_diff($varsTranslation, $vars);

             // If we have 1 missing and 1 extra, we can swap them
            if (count($missingVars) === 1 && count($extraVars) === 1) {
                $missingVar = reset($missingVars);
                $extraVar = reset($extraVars);
                $translation = str_replace($extraVar, $missingVar, $translation);
            }
        }

        return $translation;
    }

    /**
     * @param null|array<int> $context
     * @return array{model:string,created_at:string,response:string,done:bool,done_reason:string,context:array<int>,total_duration:int,load_duration:int,prompt_eval_count:int,prompt_eval_duration:int,eval_count:int,eval_duration:int}
     */
    public function generate(string $prompt, ?array $context = null)
    {
        $data = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
        ];
        if (!empty($context)) {
            $data['context'] = $context;
        }

        $url = $this->url . '/api/generate';
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $output = curl_exec($ch);

        curl_close($ch);

        $decoded = json_decode($output, true);

        if (!$decoded) {
            throw new Exception("Failed to decode json: " . json_last_error_msg());
        }

        if (isset($decoded['error'])) {
            throw new Exception("Ollama Error: " . $decoded['error']);
        }

        return $decoded;
    }
}
