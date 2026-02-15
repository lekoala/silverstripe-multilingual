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
     * @return string
     */
    protected function buildPrompt(string $from, string $to, string $string, ?string $refString = null, ?string $refLang = null): string
    {
        $fromCode = $this->expandLang($from, true);
        $fromName = $this->expandLang($from);
        $toCode = $this->expandLang($to, true);
        $toName = $this->expandLang($to);

        $prompt = "You are a professional $fromName ($fromCode) to $toName ($toCode) translator. Your goal is to accurately convey the meaning and nuances of the original $fromName text while adhering to $toName grammar, vocabulary, and cultural sensitivities.";
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
     * @return string
     */
    public function translateWithReference(?string $string, string $to, string $from, string $refString, string $refLang)
    {
        $string = $string ?? '';
        $prompt = $this->buildPrompt($from, $to, $string, $refString, $refLang);

        return $this->processResponse($this->generate($prompt), $string);
    }

    public function translate(?string $string, string $to, string $from)
    {
        $string = $string ?? '';
        $prompt = $this->buildPrompt($from, $to, $string);

        return $this->processResponse($this->generate($prompt), $string);
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

        return $response;
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

        //@phpstan-ignore-next-line
        return $decoded;
    }
}
