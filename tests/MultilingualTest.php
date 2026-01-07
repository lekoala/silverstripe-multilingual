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
    public function testHelper()
    {
        $lang = LangHelper::get_lang();
        $this->assertTrue(strlen($lang) == 2, "It is $lang");

        $locale = LangHelper::get_locale();
        $this->assertTrue(strlen($locale) > 2, "It is $locale");
    }

    public function testCollector()
    {
        // it says locale but it's a lang => looking for {lang}.yml
        $locale = LangHelper::get_lang();
        $collector = new MultilingualTextCollector($locale);
        $result = $collector->collect([
            'silverstripe/framework'
        ], true);

        $this->assertNotEmpty($result);
    }

    public function testImportExport()
    {
        $task = new TranslationsImportExportTask();

        ob_start();
        $task->debug = true;
        $task->exportTranslations('silverstripe/framework', false, ['en', 'fr']);
        $res = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString("Éditer ce fichier", $res);
    }

    public function testTranslator()
    {
        $sourceText = 'Hello';

        $translation = GoogleTranslateHelper::google_translate($sourceText, 'fr', 'en');
        $this->assertEquals('Bonjour', $translation);

        $translation = GoogleTranslateHelper::proxy_translate($sourceText, 'fr', 'en');
        $this->assertEquals('Bonjour', $translation);
    }

    public function testWithLocale()
    {
        LangHelper::withLocale('fr_FR', function () {
            self::assertEquals("fr_FR", i18n::get_locale());
        });
        LangHelper::withLocale('en_US', function () {
            self::assertEquals("en_US", i18n::get_locale());
        });
    }
}
