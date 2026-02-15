<?php

require __DIR__ . '/../vendor/autoload.php';

use LeKoala\Multilingual\OllamaTranslator;

$translator = new OllamaTranslator();

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

test("Variable Preservation", function () use ($translator) {
    $text = 'Hello {name}, how are you?';
    $from = 'en';
    $to = 'fr';
    echo "Original: $text\n";
    $translation = $translator->translate($text, $to, $from);
    echo "Translation: $translation\n";
    if (strpos($translation, '{name}') !== false) {
        echo "PASS: {name} preserved\n";
    } else {
        echo "FAIL: {name} lost\n";
    }
});

test("Reference Translation", function () use ($translator) {
    $text = 'Save';
    $from = 'en';
    $to = 'nl';
    $ref = 'Sauvegarder';
    $refLang = 'fr';

    echo "Original: $text\n";
    echo "Reference ($refLang): $ref\n";
    $translation = $translator->translateWithReference($text, $to, $from, $ref, $refLang);
    echo "Translation: $translation\n";
});

test("Variable Preservation with Reference", function () use ($translator) {
    $text = 'Save {item}';
    $from = 'en';
    $to = 'nl';
    $ref = 'Sauvegarder {item}';
    $refLang = 'fr';

    echo "Original: $text\n";
    echo "Reference ($refLang): $ref\n";
    $translation = $translator->translateWithReference($text, $to, $from, $ref, $refLang);
    echo "Translation: $translation\n";
    if (strpos($translation, '{item}') !== false) {
        echo "PASS: {item} preserved\n";
    } else {
        echo "FAIL: {item} lost\n";
    }
});