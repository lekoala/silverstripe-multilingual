<?php

// Not installed locally
if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    return;
}

require __DIR__ . '/../vendor/autoload.php';

use LeKoala\Multilingual\OllamaTranslator;
use Symfony\Component\Yaml\Yaml;

$translator = new OllamaTranslator();

$enFile = __DIR__ . '/test/lang/en.yml';
$frFile = __DIR__ . '/test/lang/fr.yml';

// Check if files exist and are not empty
if (!file_exists($enFile) || filesize($enFile) === 0) {
    echo "Creating en.yml...\n";
    file_put_contents($enFile, "en:
  EntityTest:
    title: \"Title\"
    content: \"Content\"
    author: \"Author\"");
}
if (!file_exists($frFile) || filesize($frFile) === 0) {
    echo "Creating fr.yml...\n";
    file_put_contents($frFile, "fr:
  EntityTest:
    title: \"Nom\"
    content: \"Content\"
    author: \"Hauteur\"");
}

$en = Yaml::parseFile($enFile);
$fr = Yaml::parseFile($frFile);

$sourceMessages = $en['en'] ?? [];
$targetMessages = $fr['fr'] ?? [];

function flatten($array, $prefix = '')
{
    $result = [];
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $result = $result + flatten($value, $prefix . $key . '.');
        } else {
            $result[$prefix . $key] = $value;
        }
    }
    return $result;
}

$flatSource = flatten($sourceMessages);
$flatTarget = flatten($targetMessages);

echo "--- Starting Audit ---\n";

foreach ($flatTarget as $key => $targetText) {
    $sourceText = $flatSource[$key] ?? null;

    echo "\nChecking: $key\n";
    echo "Source (en): $sourceText\n";
    echo "Target (fr): $targetText\n";

    if (!$sourceText) {
        echo "WARNING: Source text not found for key $key\n";
        continue;
    }

    $result = $translator->review($sourceText, $targetText, 'fr', 'en');

    if ($result['valid']) {
        echo "Status: VALID\n";
        if ($result['comment']) {
            echo "Comment: " . $result['comment'] . "\n";
        }
    } else {
        echo "Status: INVALID\n";
        echo "Correction: " . $result['correction'] . "\n";
    }
}
