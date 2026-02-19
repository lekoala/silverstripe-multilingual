<?php

// Not installed locally
if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    return;
}

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

test("Context Translation", function () use ($translator) {
    $text = 'Spring';
    $from = 'en';
    $to = 'fr';

    echo "Original: $text\n";

    $context1 = "Seasons of the year";
    echo "Context 1: $context1\n";
    $t1 = $translator->translate($text, $to, $from, $context1);
    echo "Translation 1: $t1\n";

    $context2 = "Mechanical parts";
    echo "Context 2: $context2\n";
    $t2 = $translator->translate($text, $to, $from, $context2);
    echo "Translation 2: $t2\n";

    if ($t1 !== $t2) {
        echo "PASS: Translations differ based on context\n";
    } else {
        echo "FAIL: Translations are identical despite context\n";
    }
});

test("Glossary Usage (Context)", function () use ($translator) {
    $text = 'My Software group';
    $from = 'en';
    $to = 'nl';

    echo "Original: $text\n";

    // Without glossary/context (likely to translate literal)
    $t1 = $translator->translate($text, $to, $from);
    echo "Without context: $t1\n";

    // With context acting as glossary
    // Prompting the model to keep specific terms
    $context = "Glossary: 'My Software' is a product name and must stay in English.";
    echo "Context: $context\n";
    $t2 = $translator->translate($text, $to, $from, $context);
    echo "With context: $t2\n";

    if (strpos($t2, 'My Software') !== false) {
        echo "PASS: 'My Software' preserved with context\n";
    } else {
        echo "FAIL: 'My Software' translated even with context\n";
    }
});

test("Variable Correction", function () use ($translator) {
    // This mocks the condition where the model translates the variable name
    $original = "My {speciality}";
    $brokenTranslation = "Mijn {specialiteit}";

    // We can't really force the model to fail, so let's unit test the logic if possible
    // Or we subclass just for testing?
    // Let's rely on reflection to test the protective method
    $reflection = new ReflectionClass($translator);
    $method = $reflection->getMethod('fixVariables');
    $method->setAccessible(true);

    $fixed = $method->invoke($translator, $original, $brokenTranslation);

    echo "Original: $original\n";
    echo "Broken: $brokenTranslation\n";
    echo "Fixed: $fixed\n";

    if ($fixed === "Mijn {speciality}") {
        echo "PASS: Variable restored\n";
    } else {
        echo "FAIL: Variable not restored\n";
    }
});

test("Batch Translation (en -> fr)", function () use ($translator) {
    $entries = [
        ['key' => 'TITLE', 'value' => 'Title', 'context' => null],
        ['key' => 'SAVE', 'value' => 'Save', 'context' => 'Button label'],
        ['key' => 'DELETE', 'value' => 'Delete', 'context' => 'Button label'],
        ['key' => 'GREETING', 'value' => 'Hello {name}', 'context' => 'User greeting'],
        ['key' => 'AUTHOR', 'value' => 'Author', 'context' => 'Content metadata'],
    ];

    echo "Sending " . count($entries) . " strings in a single batch...\n";
    $start = microtime(true);
    $results = $translator->translateBatch($entries, 'fr', 'en');
    $end = microtime(true);
    echo "Time: " . round($end - $start, 2) . "s\n\n";

    $passed = 0;
    foreach ($entries as $entry) {
        $key = $entry['key'];
        $translation = $results[$key] ?? '(MISSING)';
        echo "$key: {$entry['value']} => $translation\n";
        if ($translation !== '(MISSING)') {
            $passed++;
        }
    }

    echo "\n" . $passed . "/" . count($entries) . " keys translated\n";
    if ($passed === count($entries)) {
        echo "PASS: All keys returned\n";
    } else {
        echo "FAIL: Some keys missing\n";
    }

    // Check variable preservation in batch
    if (isset($results['GREETING']) && strpos($results['GREETING'], '{name}') !== false) {
        echo "PASS: {name} preserved in batch\n";
    } else {
        echo "FAIL: {name} lost in batch\n";
    }
});

test("Batch Review (en -> fr)", function () use ($translator) {
    $entries = [
        ['key' => 'TITLE', 'source' => 'Title', 'translation' => 'Titre', 'context' => null],
        ['key' => 'AUTHOR', 'source' => 'Author', 'translation' => 'Hauteur', 'context' => 'Content metadata'],
        ['key' => 'CONTENT', 'source' => 'Content', 'translation' => 'Contenu', 'context' => null],
    ];

    echo "Reviewing " . count($entries) . " translations in a single batch...\n";
    $start = microtime(true);
    $results = $translator->reviewBatch($entries, 'fr', 'en');
    $end = microtime(true);
    echo "Time: " . round($end - $start, 2) . "s\n\n";

    foreach ($entries as $entry) {
        $key = $entry['key'];
        $result = $results[$key] ?? null;
        if (!$result) {
            echo "$key: (MISSING from response)\n";
            continue;
        }
        $status = $result['valid'] ? 'VALID' : 'INVALID';
        echo "$key: {$entry['translation']} => $status";
        if (!$result['valid'] && $result['correction']) {
            echo " (correction: {$result['correction']})";
        }
        echo "\n";
    }

    // AUTHOR should be flagged as invalid (Hauteur != Auteur)
    if (isset($results['AUTHOR']) && !$results['AUTHOR']['valid']) {
        echo "\nPASS: AUTHOR correctly flagged as invalid\n";
    } else {
        echo "\nFAIL: AUTHOR should be invalid\n";
    }
});
