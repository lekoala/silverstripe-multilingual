<?php

use SilverStripe\Core\Injector\Injector;
use LeKoala\Multilingual\MultilingualTextCollector;
use LeKoala\Multilingual\OllamaTranslator;
use SilverStripe\Core\Manifest\ModuleLoader;

require __DIR__ . '/../vendor/autoload.php';

// Mock Module
$modulePath = __DIR__ . '/test';

// Extend and override methods to avoid framework calls in constructor
class TestCollector extends MultilingualTextCollector
{
    public function __construct()
    {
        // Skip parent constructor to avoid Injector calls
    }

    public function testLoadGlossary($module, $source, $target)
    {
        return $this->loadGlossary($module, $source, $target);
    }
    public function testAppend($context, $glossary, $sourceContent = null)
    {
        return $this->appendGlossaryToContext($context, $glossary, $sourceContent);
    }
}

$collector = new TestCollector();

// Mock a Module object-like structure
$mockModule = new class($modulePath) {
    private $path;
    public function __construct($path)
    {
        $this->path = $path;
    }
    public function getPath()
    {
        return $this->path;
    }
};

echo "--- Testing Glossary Loading ---\n";
// The file bin/test/glossaries/en-nl.csv was created earlier
$glossary = $collector->testLoadGlossary($mockModule, 'en', 'nl');
print_r($glossary);

if (isset($glossary['My Software']) && $glossary['My Software'] === 'My Software') {
    echo "PASS: Glossary loaded successfully\n";
} else {
    echo "FAIL: Glossary loading failed\n";
}

echo "\n--- Testing Context Injection with Filtering ---\n";
$baseContext = "Review required";

// Test 1: Source contains strict match
$source1 = "I use My Software everyday.";
$result1 = $collector->testAppend($baseContext, $glossary, $source1);
echo "Source: $source1\n";
echo "Result: $result1\n";

if (strpos($result1, '"My Software":"My Software"') !== false) {
    echo "PASS: 'My Software' included\n";
} else {
    echo "FAIL: 'My Software' missing\n";
}
if (strpos($result1, '"Surname":"benoeming"') === false) {
    echo "PASS: 'Surname' correctly excluded\n";
} else {
    echo "FAIL: 'Surname' should be excluded\n";
}

// Test 2: Source contains partial match (case insensitive)
$source2 = "What is your SUrnAmE?";
$result2 = $collector->testAppend($baseContext, $glossary, $source2);
echo "\nSource: $source2\n";
echo "Result: $result2\n";

if (strpos($result2, '"Surname":"achter_naam"') !== false) {
    echo "PASS: 'Surname' included (case-insensitive match)\n";
} else {
    echo "FAIL: 'Surname' missing\n";
}

// Test 3: Multiple terms
$source3 = "Firstname and Surname please.";
$result3 = $collector->testAppend($baseContext, $glossary, $source3);
echo "\nSource: $source3\n";
echo "Result: $result3\n";

if (strpos($result3, '"Firstname":"Voornaam"') !== false && strpos($result3, '"Surname":"achter_naam"') !== false) {
    echo "PASS: Multiple terms included\n";
} else {
    echo "FAIL: Multiple terms check failed\n";
}

echo "\n--- Testing Full Sentence Translation (Ollama) ---\n";

try {
    $translator = new OllamaTranslator();

    // Test 1: Previous sentence
    $text1 = "I work at My Software and my firstname is John and my surname is Doe";
    echo "Original 1: $text1\n";
    $context1 = $collector->testAppend("Review required", $glossary, $text1);
    echo "Context 1: $context1\n";

    $trans1 = $translator->translate($text1, 'nl', 'en', $context1);
    echo "Translation 1: $trans1\n";

    // Test 2: Ambiguous term (Appointment) - should use glossary 'Benoeming'
    $text2 = "I have an important appointment today.";
    echo "\nOriginal 2: $text2\n";
    $context2 = $collector->testAppend("Review required", $glossary, $text2);
    echo "Context 2: $context2\n";

    $trans2 = $translator->translate($text2, 'nl', 'en', $context2);
    echo "Translation 2: $trans2\n";

    if (stripos($trans2, 'benoeming') !== false) {
        echo "PASS: 'Appointment' translated to 'Benoeming'\n";
    } else {
        echo "FAIL: 'Appointment' should be 'Benoeming', got something else (likely 'afspraak')\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
