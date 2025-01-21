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

    public function translate(?string $string, string $to, string $from)
    {
        /*
        messages = [
            {"role": "user", "content": "Translate the following text from Portuguese into English.\nPortuguese: Um grupo de investigadores lançou um novo modelo para tarefas relacionadas com tradução.\nEnglish:"},
        ]
        */

        $prompt = "Translate the following text from $from into $to and keep variables between {} as is.\n$from: $string\n$to:";

        $result = $this->generate($prompt);

        $response = $result['response'] ?? '';

        return trim($response);
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
