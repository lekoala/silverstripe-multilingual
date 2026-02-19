<?php

// Not installed locally
if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    return;
}

require __DIR__ . '/../vendor/autoload.php';

use LeKoala\Multilingual\DeeplTranslator;

// Get key from env or args
$key = getenv('DEEPL_API_KEY');
if (!$key) {
    if (isset($argv[1])) {
        $key = $argv[1];
    } else {
        echo "Please provide API key as argument or DEEPL_API_KEY env var\n";
        echo "Usage: php bin/test-deepl.php YOUR_API_KEY\n";
        exit(1);
    }
}

$translator = new DeeplTranslator($key);

function test($label, $cb)
{
    echo "\n--- $label ---\n";
    try {
        $cb();
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

test("Basic Translation (en -> fr)", function () use ($translator) {
    $text = 'Hello world';
    $from = 'en';
    $to = 'fr';
    echo "Original: $text\n";
    $start = microtime(true);
    $translation = $translator->translate($text, $to, $from);
    $end = microtime(true);
    echo "Translation: $translation\n";
    echo "Time: " . round($end - $start, 2) . "s\n";
});

test("Batch Translation (en -> fr)", function () use ($translator) {
    $entries = [
        ['key' => 'TITLE', 'value' => 'Title', 'context' => null],
        ['key' => 'SAVE', 'value' => 'Save', 'context' => 'Button label'],
        ['key' => 'GREETING', 'value' => 'Hello {name}', 'context' => 'User greeting'],
    ];

    echo "Sending " . count($entries) . " strings in a single batch...\n";
    $start = microtime(true);
    $results = $translator->translateBatch($entries, 'fr', 'en');
    $end = microtime(true);
    echo "Time: " . round($end - $start, 2) . "s\n\n";

    foreach ($entries as $entry) {
        $key = $entry['key'];
        $translation = $results[$key] ?? '(MISSING)';
        echo "$key: {$entry['value']} => $translation\n";
    }
});
