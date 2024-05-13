<?php

namespace LeKoala\Multilingual\Helpers;

use Exception;

/**
 * A simple helper to help translating strings
 */
class GoogleTranslateHelper
{
    /**
     * @var string
     */
    public static $provider = 'google_translate';

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
        $func = self::$provider;
        return self::$func($sourceText, $targetLang, $sourceLang);
    }

    /**
     * Translate from google public api
     *
     * @param string $sourceText
     * @param string $targetLang
     * @param string $sourceLang
     * @return string
     */
    public static function google_translate($sourceText, $targetLang = null, $sourceLang = null)
    {
        if (!$sourceLang) {
            $sourceLang = 'auto';
        }
        if (!$targetLang) {
            $targetLang = 'en';
        }

        $targetLang = substr($targetLang, 0, 2);

        $url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl="
            . $sourceLang . "&tl=" . $targetLang . "&dt=t&q=" . urlencode($sourceText);

        $result = file_get_contents($url);
        if (!$result) {
            throw new Exception("Failed to fetch content from $url");
        }
        $data = json_decode($result, true);
        if (!$data) {
            throw new Exception("Failed to decode json : " . json_last_error_msg());
        }

        // array:9 [▼
        // 0 => array:1 [▼
        //   0 => array:5 [▼
        //     0 => "TargetTextHere"
        //     1 => "SourceTextHere"
        //     2 => null
        //     3 => null
        //     4 => 1
        //   ]
        // ]
        // 1 => null
        // 2 => "en"
        // 3 => null
        // 4 => null
        // 5 => null
        // 6 => 1.0
        // 7 => []
        // 8 => array:4 [▼
        //   0 => array:1 [▼
        //     0 => "en"
        //   ]
        //   1 => null
        //   2 => array:1 [▼
        //     0 => 1.0
        //   ]
        //   3 => array:1 [▼
        //     0 => "en"
        //   ]
        // ]

        $translatedText = $data[0][0][0];

        return $translatedText;
    }

    /**
     * Translate from proxy api
     *
     * @param string $sourceText
     * @param string $targetLang
     * @param string $sourceLang
     * @return string
     */
    public static function proxy_translate($sourceText, $targetLang = null, $sourceLang = null)
    {
        if (!$sourceLang) {
            $sourceLang = 'auto';
        }
        if (!$targetLang) {
            $targetLang = 'en';
        }

        $targetLang = substr($targetLang, 0, 2);

        //translate/{to}[/{from}]?text=your_url_encoded_text
        $url = 'https://vercel-translate-api.vercel.app/translate/' . $targetLang . '/' . $sourceLang . '?text=' . urlencode($sourceText);

        // {
        //     "data": {
        //     "from": "auto",
        //     "to": "fr",
        //     "text": "hello",
        //     "translation": "bonjour"
        //     }
        //     }

        $result = file_get_contents($url);
        if (!$result) {
            throw new Exception("Failed to fetch content from $url");
        }
        $data = json_decode($result, true);
        if (!$data) {
            throw new Exception("Failed to decode json : " . json_last_error_msg());
        }

        if (!empty($data['error'])) {
            throw new Exception($data['error']);
        }

        return $data['data']['translation'];
    }
}
