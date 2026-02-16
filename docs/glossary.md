# Glossary Implementation Architecture

This module implements support for glossaries focusing on the **DeepL V3 Multilingual Glossary API**. This approach allows for a streamlined management of terms across multiple language pairs.

## Directory Structure

Glossary files are stored in a dedicated subfolder within the module's `lang` directory:
`<module>/lang/glossaries/`

- **`*.csv` Files**: Human-editable files containing `source,target` pairs. Filenames follow the `{sourceLange}-{targetLang}.csv` convention (e.g., `en-fr.csv`).
- **`map.json`**: State file that maps the local files to a remote DeepL glossary ID.

## Implementation Strategy: V3 Multilingual API

Unlike the legacy V2 API which required a separate ID for every language pair, we use the **V3 Multilingual API**.

### 1. The "Master Glossary" Container

We maintain **one single Glossary ID** for the entire module. This glossary acts as a container for multiple **Dictionaries**.

### 2. Dictionary Mapping

Each local CSV file maps to a dictionary entry in the DeepL glossary:

- **`source_lang`**: Parsed from the filename.
- **`target_lang`**: Parsed from the filename.
- **`entries`**: Converted from our local CSV to the DeepL TSV format (`source\ttarget`).

### 3. Atomic Synchronization

The `GlossaryTask::sync` action uses the `replaceMultilingualGlossaryDictionary` method from the DeepL SDK. When you update a local CSV for one language, only that specific dictionary is updated on DeepL. Other language pairs in the same glossary remain untouched.

## Synchronization Workflow

1. **Generate (`action=generate`)**: Scans YML files for valid single-word 1:1 translations and populates CSV files.
2. **Review**: Developers or translators can manually refine the CSV files.
3. **Sync (`action=sync`)**:
    - Checks `map.json` for an existing `glossary_id`.
    - If found, it updates/replaces the dictionaries.
    - If not found, it creates a new multilingual glossary on DeepL and saves the ID to `map.json`.

## Automatic Application

The `DeeplTranslator` is designed to be "zero-config" regarding glossaries. During a translation request:

1. It checks for a `map.json` file in the relevant module.
2. If found, it extracts the `glossary_id`.
3. It passes this ID to the DeepL API.
4. DeepL's engine automatically resolves the correct dictionary to use based on the `source` and `target` languages provided in the translation call.
