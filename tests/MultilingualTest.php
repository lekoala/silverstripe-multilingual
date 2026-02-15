<?php

namespace LeKoala\Multilingual\Test;

use SilverStripe\Dev\SapphireTest;
use LeKoala\Multilingual\LangHelper;
use LeKoala\Multilingual\GoogleTranslateHelper;
use LeKoala\Multilingual\MultilingualTextCollector;
use LeKoala\Multilingual\TranslationsImportExportTask;
use SilverStripe\i18n\i18n;

class MultilingualTest extends SapphireTest
{
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

    /*
    Deprecated, not tested anymore
    public function testTranslator(): void
    {
        $sourceText = 'Hello';

        $translation = GoogleTranslateHelper::google_translate($sourceText, 'fr', 'en');
        $this->assertEquals('Bonjour', $translation);

        $translation = GoogleTranslateHelper::proxy_translate($sourceText, 'fr', 'en');
        $this->assertEquals('Bonjour', $translation);
    }
    */

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

    public function testCollectorAutoTranslate()
    {
        // Mock the translator
        $translator = $this->getMockBuilder(\LeKoala\Multilingual\OllamaTranslator::class)
            ->setMethods(['translate'])
            ->getMock();
        $translator->expects($this->once())
            ->method('translate')
            ->will($this->returnValue("Translated"));

        // Mock the collector to expose protected methods and control data
        $collector = $this->getMockBuilder(MultilingualTextCollector::class)
            ->setMethods(['getModulesAndThemesExposed', 'getReader', 'write'])
            ->getMock();

        // Inject our mock translator
        $collector->setTranslator($translator);
        $collector->setAutoTranslate(true, 'en', 'new');

        // Mock module data
        $dummyPath = sys_get_temp_dir() . '/' . uniqid('multilingual_test_');
        // Create a dummy lang file
        $langDir = $dummyPath . '/lang';
        if (!mkdir($langDir, 0777, true) && !is_dir($langDir)) {
             throw new \RuntimeException(sprintf('Directory "%s" was not created', $langDir));
        }
        $langFile = $langDir . '/en.yml';
        file_put_contents($langFile, "en:\n  MyEntity: 'Existing'");

        $mockModule = $this->getMockBuilder(\SilverStripe\Core\Manifest\Module::class)
            ->disableOriginalConstructor()
            ->setMethods(['getPath'])
            ->getMock();
        $mockModule->method('getPath')->willReturn($dummyPath);

        $collector->method('getModulesAndThemesExposed')->willReturn([
            'mymodule' => $mockModule
        ]);

        // Mock reader to return existing messages
        $mockReader = $this->getMockBuilder(\SilverStripe\i18n\Messages\Reader::class)
            ->setMethods(['read'])
            ->getMock();
        $mockReader->method('read')->willReturn([
            'MyEntity' => 'Existing',
        ]);
        $collector->method('getReader')->willReturn($mockReader);

        // We want to trigger mergeWithExisting
        // It requires entitiesByModule
        $entitiesByModule = [
            'mymodule' => [
                'MyEntity' => 'Existing', // Same value
                'NewEntity' => 'New String' // New value -> should trigger translation
            ]
        ];

        // Access protected method
        $reflection = new \ReflectionClass(MultilingualTextCollector::class);
        $method = $reflection->getMethod('mergeWithExisting');
        $method->setAccessible(true);

        // Run
        $result = $method->invoke($collector, $entitiesByModule);

        // Check that translation happened and was merged
        $this->assertEquals("Translated", $result['mymodule']['NewEntity']);

        // Cleanup
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
