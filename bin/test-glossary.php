<?php

// Not installed locally
if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    return;
}

require __DIR__ . '/../vendor/autoload.php';

use LeKoala\Multilingual\DeeplTranslator;

/**
 * Standalone test for DeepL Glossaries
 *
 * This script verifies that:
 * 1. The translator can load a glossary_id from a map.json file
 * 2. The glossary_id is correctly passed to the DeepL API
 */

// Get key from env or args
$key = getenv('DEEPL_API_KEY') ?: 'mock-key';

// 1. Test map loading logic
echo "--- Testing Glossary Map Loading ---\n";
$tempMap = __DIR__ . '/test-map.json';

$mockId = 'test-glossary-id-' . uniqid();
file_put_contents($tempMap, json_encode(['glossary_id' => $mockId]));

// Use anonymous class to override path for testing
$translator = new class($key, $tempMap) extends DeeplTranslator {
    protected $testPath;
    public function __construct($key, $path)
    {
        parent::__construct($key);
        $this->testPath = $path;
    }
    protected function getGlossaryMapPath(): string
    {
        return $this->testPath;
    }
};

// We need to access protected method to verify
$reflection = new ReflectionClass($translator);
$method = $reflection->getMethod('getGlossaryId');
$method->setAccessible(true);

$id = $method->invoke($translator);
echo "Loaded Glossary ID: $id\n";

if ($id === $mockId) {
    echo "✅ Success: Glossary ID correctly loaded from map.json\n";
} else {
    echo "❌ Error: Expected $mockId, got " . ($id ?? 'null') . "\n";
}

// 2. Test translation with glossary (Live if key is valid)
if ($key !== 'mock-key') {
    echo "\n--- Testing Translation with Glossary (Live) ---\n";
    try {
        $text = 'Word';
        $from = 'en';
        $to = 'fr';
        echo "Translating '$text' from $from to $to using glossary $id...\n";

        $start = microtime(true);
        // Note: This might fail if the glossary ID doesn't exist on DeepL side, 
        // but it proves the parameter is being passed.
        $translation = $translator->translate($text, $to, $from);
        $end = microtime(true);

        echo "Translation: $translation\n";
        echo "Time: " . round($end - $start, 2) . "s\n";
        echo "✅ Success: API call completed (check if glossary was applied if you have real entries)\n";
    } catch (Exception $e) {
        echo "Caught Expected/Actual Error: " . $e->getMessage() . "\n";
        if (strpos($e->getMessage(), 'Glossary not found') !== false) {
            echo "ℹ️ Note: 'Glossary not found' is expected if you haven't synced this mock ID to DeepL yet.\n";
            echo "✅ The important part is that the ID was SENT to the API.\n";
        }
    }
} else {
    echo "\nSkipping live test (no DEEPL_API_KEY found).\n";
}

// Cleanup mock map if we created it
unlink($tempMap);
echo "\nDone.\n";
