<?php

namespace LeKoala\Multilingual;

use Exception;
use SilverStripe\Core\Injector\Injector;

/**
 * Factory for creating translator instances
 */
class TranslatorFactory
{
    /**
     * @param string|null $driver 'deepl' or 'ollama'
     * @param string|null $model Model for ollama
     * @param string|null $key API Key for DeepL (if not using env var)
     * @return TranslatorInterface
     * @throws Exception
     */
    public static function create(?string $driver = null, ?string $model = null, ?string $key = null): TranslatorInterface
    {
        if ($driver === 'deepl' || $key) {
            if (!class_exists('DeepL\DeepLClient')) {
                throw new Exception("DeepL SDK not installed. Please run 'composer require --dev deeplcom/deepl-php'");
            }
            // Key defaults to null if not provided, DeeplTranslator handles Env check
            return new DeeplTranslator($key);
        }

        if ($driver === 'ollama' || $model) {
            return new OllamaTranslator($model);
        }

        return Injector::inst()->get(TranslatorInterface::class);
    }
    /**
     * Get the default driver name from the configured Injector service
     *
     * @return string
     */
    public static function getDefaultDriver(): string
    {
        try {
            $translator = Injector::inst()->get(TranslatorInterface::class);
            $class = get_class($translator);
            $parts = explode('\\', $class);
            $base = array_pop($parts);
            return strtolower(str_replace('Translator', '', $base));
        } catch (Exception $e) {
            return 'none';
        }
    }
}
