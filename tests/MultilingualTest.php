<?php

namespace LeKoala\Multilingual\Test;

use SilverStripe\Dev\SapphireTest;
use LeKoala\Multilingual\LangHelper;
use LeKoala\Multilingual\GoogleTranslateHelper;
use LeKoala\Multilingual\MultilingualTextCollector;
use LeKoala\Multilingual\TranslationsImportExportTask;
use LeKoala\Multilingual\TranslationService;
use LeKoala\Multilingual\OllamaTranslator;
use LeKoala\Multilingual\TranslatorInterface;
use SilverStripe\i18n\i18n;

class MultilingualTest extends SapphireTest
{
    public static function tearDownAfterClass(): void
    {
        // Call state helpers
        // static::$state->tearDownOnce(static::class);
    }

    public function testHelper(): void
    {
        $lang = LangHelper::get_lang();
        $this->assertTrue(strlen($lang) == 2, "It is $lang");

        $locale = LangHelper::get_locale();
        $this->assertTrue(strlen($locale) > 2, "It is $locale");
    }

    public function testCollector(): void
    {
        // it says locale but it's a lang => looking for {lang}.yml
        $locale = LangHelper::get_lang();
        $collector = new MultilingualTextCollector($locale);
        $result = $collector->collect([
            'silverstripe/framework'
        ], true);

        $this->assertNotEmpty($result);
    }

    public function testImportExport(): void
    {
        $task = new TranslationsImportExportTask();

        ob_start();
        $task->debug = true;
        $task->exportTranslations('silverstripe/framework', false, ['en', 'fr']);
        $res = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString("Éditer ce fichier", $res);
    }

    public function testWithLocale(): void
    {
        LangHelper::withLocale('fr_FR', function () {
            self::assertEquals("fr_FR", i18n::get_locale());
        });
        LangHelper::withLocale('en_US', function () {
            self::assertEquals("en_US", i18n::get_locale());
        });
    }

    public function testOllamaTranslator(): void
    {
        $translator = $this->getMockBuilder(\LeKoala\Multilingual\OllamaTranslator::class)
            ->setMethods(['generate'])
            ->getMock();

        $translator->method('generate')->will($this->returnCallback(function ($prompt) {
            $response = "Mocked response";
            if (strpos($prompt, 'reference:') !== false) {
                $response = "Mocked response with reference";
            }
            return [
                'response' => $response,
            ];
        }));

        // Test basic translation
        $result = $translator->translate("Hello", "fr", "en");
        $this->assertEquals("Mocked response", $result);

        // Test reference translation
        $result = $translator->translateWithReference("Hello", "fr", "en", "Bonjour", "fr");
        $this->assertEquals("Mocked response with reference", $result);

        // Test expansion
        $this->assertEquals('English', $translator->expandLang('en'));
        $this->assertEquals('en', $translator->expandLang('English', true));
        $this->assertEquals('fr', $translator->expandLang('French', true));
        // Case insensitive
        $this->assertEquals('en', $translator->expandLang('english', true));
    }

    public function testTranslationServiceAutoTranslate()
    {
        // Mock the translator
        $translator = $this->getMockBuilder(OllamaTranslator::class)
            ->setMethods(['translate', 'translateBatch'])
            ->getMock();
        $translator->method('translate')
            ->will($this->returnValue("Translated"));
        $translator->method('translateBatch')
            ->will($this->returnCallback(function ($batch) {
                $results = [];
                foreach ($batch as $entry) {
                    $results[$entry['key']] = "Translated";
                }
                return $results;
            }));

        $service = new TranslationService($translator);

        // Mock module data
        $dummyPath = sys_get_temp_dir() . '/' . uniqid('multilingual_test_service_');
        $langDir = $dummyPath . '/lang';
        if (!mkdir($langDir, 0777, true) && !is_dir($langDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $langDir));
        }

        // Create source lang file
        $sourceFile = $langDir . '/en.yml';
        file_put_contents($sourceFile, "en:\n  MyEntity: 'Existing'\n  NewEntity: 'New String'");

        // Create target lang file
        $targetFile = $langDir . '/fr.yml';
        file_put_contents($targetFile, "fr:\n  MyEntity: 'Existant'");

        // We need to mock ModuleLoader to return our dummy module
        $mockModule = $this->getMockBuilder(\SilverStripe\Core\Manifest\Module::class)
            ->disableOriginalConstructor()
            ->setMethods(['getPath'])
            ->getMock();
        $mockModule->method('getPath')->willReturn($dummyPath);

        $mockManifest = $this->getMockBuilder(\SilverStripe\Core\Manifest\ModuleManifest::class)
            ->disableOriginalConstructor()
            ->setMethods(['getModules'])
            ->getMock();
        $mockManifest->method('getModules')->willReturn(['mymodule' => $mockModule]);

        \SilverStripe\Core\Manifest\ModuleLoader::inst()->pushManifest($mockManifest);

        // Run translation
        $result = $service->translateModule('mymodule', 'en', 'fr', [
            'auto_translate' => true,
            'enrich' => false // Don't use collector for this test to avoid complexity
        ]);

        $this->assertEquals("Translated", $result['messages']['NewEntity']);
        $this->assertEquals("Existant", $result['messages']['MyEntity']);

        // Cleanup
        \SilverStripe\Core\Manifest\ModuleLoader::inst()->popManifest();

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dummyPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($dummyPath);
    }
}
