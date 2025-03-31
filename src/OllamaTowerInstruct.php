<?php

namespace LeKoala\Multilingual;

use Exception;

/**
 * A simple ollama client to query towerinstruct model
 * @link https://ollama.com/thinkverse/towerinstruct
 * @link https://huggingface.co/Unbabel/TowerInstruct-7B-v0.2
 */
class OllamaTowerInstruct
{
    final public const BASE_URL = 'http://localhost:11434';
    final public const BASE_MODEL = 'thinkverse/towerinstruct';

    protected ?string $model;
    protected ?string $url;

    public function __construct(?string $model = null, ?string $url = null)
    {
        $this->model = $model ?? self::BASE_MODEL;
        $this->url = $url ?? self::BASE_URL;
    }

    public function expandLang(string $lang): string
    {
        $lc = strtolower($lang);
        // English, Portuguese, Spanish, French, German, Dutch, Italian, Korean, Chinese, Russian
        return match ($lc) {
            'en' => 'English',
            'fr' => 'French',
            'nl' => 'Dutch',
            'it' => 'Italian',
            'de' => 'German',
            'pt' => 'Portuguese',
            'ko' => 'Korean',
            'zh' => 'Chinese',
            'ru' => 'Russian',
            default => $lang
        };
    }

    public function translate(?string $string, string $to, string $from)
    {
        /*
        messages = [
            {"role": "user", "content": "Translate the following text from Portuguese into English.\nPortuguese: Um grupo de investigadores lançou um novo modelo para tarefas relacionadas com tradução.\nEnglish:"},
        ]
        */

        $string = $string ?? '';
        $from = $this->expandLang($from);
        $to = $this->expandLang($to);

        $prompt = "Translate the following text from $from into $to and keep variables between {} as is.\n$from: $string\n$to:";

        $result = $this->generate($prompt);

        $response = $result['response'] ?? '';

        // Avoid extra space
        $response = trim($response);

        // Make sure we don't get any extra ending dot
        $endsWithDot = str_ends_with($string, '.');
        $translationEndsWithDot = str_ends_with($response, '.');
        if (!$endsWithDot && $translationEndsWithDot) {
            $response = rtrim($response, '.');
        }

        // No crazy {}
        $includesParen = str_contains($string, '{}');
        $translationIncludesParen = str_contains($string, '{}');
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

        //@phpstan-ignore-next-line
        return $decoded;
    }
}
