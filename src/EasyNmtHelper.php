<?php

namespace LeKoala\Multilingual;

/**
 * A simple helper to help translating strings
 * @link https://github.com/UKPLab/EasyNMT/tree/main/docker
 */
class EasyNmtHelper
{
    public static $baseUrl = 'http://localhost:24080/translate';
    public static $defaultLanguage = 'en';

    /**
     * Translate from provider
     *
     * @param string $sourceText
     * @param string $targetLang
     * @param string $sourceLang
     * @return string
     */
    public static function translate($sourceText, $targetLang = null, $sourceLang = null)
    {
        $src_lang = self::$defaultLanguage;
        if ($targetLang == $src_lang) {
            return $sourceText;
        }

        $orgText = $sourceText;
        $containsVar = str_contains($sourceText, '{');

        // use en as intermediate language
        if ($targetLang != 'en') {
            $params2 = [
                'target_lang' => 'en',
                'text' => $sourceText
            ];
            $url = self::$baseUrl . '?' . http_build_query($params2);
            $result = @file_get_contents($url);
            if ($result) {
                $jsonData = json_decode($result, 1);
                if ($jsonData) {
                    $sourceText = $jsonData['translated'][0] ?? $sourceText;
                }
            }
            $src_lang = 'en';
        }

        $params = [
            'target_lang' => $targetLang,
            'text' => $sourceText,
            'source_lang' => $src_lang,
        ];

        $url = 'http://localhost:24080/translate?' . http_build_query($params);
        $result = @file_get_contents($url);
        if ($result) {
            $jsonData = json_decode($result, 1);
            if ($jsonData) {
                $sourceText = $jsonData['translated'][0] ?? $sourceText;
            }
        }

        if ($containsVar && !str_contains($sourceText, '{')) {
            return $orgText;
        }

        return $sourceText;
    }
}