# SilverStripe i18n tools module

![Build Status](https://github.com/lekoala/silverstripe-multilingual/actions/workflows/ci.yml/badge.svg)
[![scrutinizer](https://scrutinizer-ci.com/g/lekoala/silverstripe-multilingual/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/lekoala/silverstripe-multilingual/)
[![Code coverage](https://codecov.io/gh/lekoala/silverstripe-multilingual/branch/master/graph/badge.svg)](https://codecov.io/gh/lekoala/silverstripe-multilingual)

## Intro

Provide some helper tools when working with Fluent and multilingual websites

## LangHelper

The `LangHelper` class provide a consistent i18n function regardless of Fluent being installed or not.

You can call global translation under the "Global" entity. These are accessible with LangHelper::globalTranslation or g() shortcut.

## FluentLocale only if cookies are enabled

This module disable by default `persist_cookie` for Fluent.

You need to make sure to call `LangHelper::persistLocaleIfCookiesAreAllowed` (works with two types of cookie consent modules)
or call with your own logic `LangHelper::persistLocale`.

## Improved text collector task

![ConfigurableI18nTextCollectorTask](docs/ConfigurableI18nTextCollectorTask.png "ConfigurableI18nTextCollectorTask")

This improved text collector helps you to collect translation from specific modules.

It supports merge (even of older version of SilverStripe, that now does that as well), auto translating new string from google api,
clear unused strings...

This is available from a convenient interface.

## Translations import/export task

![TranslationsImportExportTask](docs/TranslationsImportExportTask.png "TranslationsImportExportTask")

Collecting translations for customers is not always easy. In order to provide a simple mean to collect label,
everything can be exported to a csv file. This will create as many columns as the number of .yml files in your `lang` folder.

It can then be imported back from the file to your yml files.

If you use lekoala/silverstripe-excel-import-export, this file can be exported in xlsx.

This is available from a convenient interface.

## Ollama Integration

This module uses `OllamaTranslator` to leverage local LLMs for translation.

By default, it uses the `translategemma` model, which you need to pull:

```bash
ollama pull translategemma
```

### Reference Translation

You can improve translation quality by providing a reference translation. This is supported in the `TranslationsImportExportTask` via the `--ref_lang` option.

For example, to export translations and translate empty strings using `fr` as a reference for `nl`:

```bash
sake dev/tasks/TranslationsImportExportTask module=yourmodule export=1 export_auto_translate=1 ref_lang=fr
```

## Todo

- Make BuildTaskTools into a specific module

## Compatibility

Tested with ^5

## Maintainer

LeKoala - <thomas@lekoala.be>
