<?php

namespace LeKoala\Multilingual\Test;

use PHPUnit\Framework\TestCase;
use LeKoala\Multilingual\GlossaryTask;
use LeKoala\Multilingual\DeeplTranslator;
use SilverStripe\Control\Director;
use ReflectionClass;

class GlossaryTest extends TestCase
{
    protected $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir() . '/' . uniqid('glossary_test_');
        if (!mkdir($this->tempPath, 0777, true) && !is_dir($this->tempPath)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->tempPath));
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tempPath)) {
            $this->rmdirRecursive($this->tempPath);
        }
    }

    protected function rmdirRecursive($dir)
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }

        rmdir($dir);
    }

    public function testGenerate()
    {
        $langDir = $this->tempPath . '/lang';
        mkdir($langDir, 0777, true);

        // Create en.yml
        file_put_contents($langDir . '/en.yml', "en:\n  Word: 'Word'\n  Phrase: 'Longer phrase'\n  Short: 'S'\n  Conflict: 'Conflict'");
        // Create fr.yml
        file_put_contents($langDir . '/fr.yml', "fr:\n  Word: 'Mot'\n  Phrase: 'Une phrase plus longue'\n  Short: 'Petit'\n  Conflict: 'Conflit'");

        $task = new class extends GlossaryTask {
            public $tp;
            protected function getLangPath(string $module): string
            {
                return $this->tp . '/lang';
            }
            public function testGenerate($module, $sourceLang, $targetLangFilter = null, $minLength = 3)
            {
                $this->generate($module, $sourceLang, $targetLangFilter, $minLength);
            }
            public function message($text, $status = 'info')
            {
                // do nothing or capture for assertion
            }
        };
        $task->tp = $this->tempPath;

        $task->testGenerate('test', 'en', 'fr', 3);

        $csvFile = $this->tempPath . '/lang/glossaries/en-fr.csv';
        $this->assertFileExists($csvFile);

        $content = file_get_contents($csvFile);
        // "Word" should be there (normalized to lowercase)
        $this->assertStringContainsString("word,Mot", $content);
        // "Phrase" should NOT be there (spaces)
        $this->assertStringNotContainsString("Phrase", $content);
        // "Short" should NOT be there (too short by default)
        $this->assertStringNotContainsString("Short", $content);
    }

    public function testParseCsv()
    {
        $csvFile = $this->tempPath . '/test.csv';
        file_put_contents($csvFile, "Word,Mot\nPhrase,\"Long, phrase\"");

        $task = new class extends GlossaryTask {
            public function testParseCsv($file)
            {
                return $this->parseCsv($file);
            }
        };

        $result = $task->testParseCsv($csvFile);
        $this->assertEquals('Mot', $result['Word']);
        $this->assertEquals('Long, phrase', $result['Phrase']);
    }

    public function testDeeplTranslatorGlossaryId()
    {
        $glossaryDir = $this->tempPath . '/glossaries';
        mkdir($glossaryDir, 0777, true);
        
        $mapFile = $glossaryDir . '/map.json';
        file_put_contents($mapFile, json_encode(['glossary_id' => 'abc-123']));

        $translator = new class extends DeeplTranslator {
            public $tp;
            public function __construct()
            {
                // bypass parent constructor but it requires client
            }
            protected function getGlossaryMapPath(): string
            {
                return $this->tp . '/glossaries/map.json';
            }
            public function testGetGlossaryId()
            {
                return $this->getGlossaryId();
            }
        };
        $translator->tp = $this->tempPath;

        $id = $translator->testGetGlossaryId();
        $this->assertEquals('abc-123', $id);
    }
}
