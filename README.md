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

If you use `lekoala/silverstripe-excel-import-export`, this file can be exported in xlsx.

This is available from a convenient interface.

## Glossary Support

This module provides a mechanism to manage translation glossaries, ensuring consistent translation of specific terms. For a detailed technical overview of how this integrates with the DeepL API, see [Glossary Architecture](docs/glossary.md).

### Usage

1. **Generate glossary candidates**:

    ```bash
    php vendor/bin/sake dev/tasks/GlossaryTask action=generate module=app
    ```

    This scans your YML files for single-word translations (e.g., "Word" -> "Mot") and creates CSV files in `app/lang/glossaries/`. These files can be reviewed and edited manually.

2. **Sync with DeepL (Optional)**:

    ```bash
    php vendor/bin/sake dev/tasks/GlossaryTask action=sync module=app
    ```

    This uploads the CSV files to DeepL as a single multilingual glossary. The resulting glossary ID is stored in `app/lang/glossaries/map.json`.

3. **List remote glossaries**:

    ```bash
    php vendor/bin/sake dev/tasks/GlossaryTask action=list
    ```

When using the `DeeplTranslator`, it will automatically detect and apply the glossary if a valid `map.json` file is found in the module's `lang/glossaries` directory.

## Supported translators

We support a few drivers out of the box. You can configure the default translator using SilverStripe's dependency injection system in your `app/_config/config.yml`:

### For DeepL

```yaml
SilverStripe\Core\Injector\Injector:
  LeKoala\Multilingual\TranslatorInterface:
    class: LeKoala\Multilingual\DeeplTranslator
    constructor:
      apiKey: 'your-api-key'
```

### For Ollama

```yaml
SilverStripe\Core\Injector\Injector:
  LeKoala\Multilingual\TranslatorInterface:
    class: LeKoala\Multilingual\OllamaTranslator
    constructor:
      model: 'mistral'
```

Tasks will use this configuration unless you explicitly override options (like `driver`, `model`, or `key`) via CLI.

## DeepL Integration

You can use DeepL as an alternative to Ollama. First, install the SDK:

```bash
composer require deeplcom/deepl-php
```

Then configure the tasks to use the `deepl` driver and provide your API key.

```bash
sake dev/tasks/TranslationsImportExportTask module=yourmodule export=1 export_auto_translate=1 source_lang=en driver=deepl key=your-api-key
```

DeepL support includes:

- **Batch Translation**: Grouping multiple strings to reduce API calls (grouped by context).
- **Context Support**: Passing context to DeepL to improve translation quality.
- **Variable Preservation**: Automatic restoration of variables (e.g. `{name}`) if the API messes them up.

## Ollama Integration

This module uses `OllamaTranslator` to leverage local LLMs for translation.

By default, it uses the `translategemma:4b` model:

```bash
ollama pull translategemma:4b
```

You can select a different model from the task UI via the **model** option, or pass it as a parameter.

### Source Language

Use the `source_lang` option (available in both tasks) to specify the source language for translation. This is used both for loading reference `.yml` files and as the LLM's input language.

```bash
sake dev/tasks/TranslationsImportExportTask module=yourmodule export=1 export_auto_translate=1 source_lang=en
```

### Batch Translation

The translator supports **batch mode**, sending multiple strings in a single LLM call for significantly faster throughput (10–20× vs single-key). Strings without a reference translation are batched in groups of 15; strings with a reference are translated individually for quality.

```php
$translator = new OllamaTranslator();
$results = $translator->translateBatch([
    ['key' => 'TITLE', 'value' => 'Title', 'context' => null],
    ['key' => 'SAVE', 'value' => 'Save', 'context' => 'Button label'],
], 'fr', 'en');
```

### Translation Review & Audit

Enable the **review_translations** option to automatically audit existing translations via the LLM. Invalid translations are corrected in-place before writing.

Review also supports batch mode via `reviewBatch()`:

```php
$results = $translator->reviewBatch([
    ['key' => 'TITLE', 'source' => 'Title', 'translation' => 'Nom', 'context' => null],
], 'fr', 'en');
// Returns: ['TITLE' => ['valid' => false, 'correction' => 'Titre']]
```

See `bin/test-audit.php` for a standalone audit example.

### Context Support

Context is provided to the LLM automatically in two ways:

1. **Explicit**: From `_t()` calls with a `context` key (e.g. `<%t String context="My Context" %>`)
2. **Implicit**: Derived from the entity key (e.g. `Member.FIRSTNAME` → `"Field 'FIRSTNAME' in 'Member'"`)

### Progress Reporting

Both translation and review operations emit progress messages during execution (e.g. `Translating batch 15/142...`, `Review complete: 50 reviewed, 3 corrected`).

## Todo

- Make BuildTaskTools into a specific module

## Compatibility

Tested with ^5

## Maintainer

LeKoala - <thomas@lekoala.be>
