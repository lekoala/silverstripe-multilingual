<?php

namespace LeKoala\Multilingual;

use Exception;
use SilverStripe\Dev\Debug;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\Director;
use SilverStripe\i18n\Messages\Writer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\i18n\Messages\YamlReader;
use LeKoala\ExcelImportExport\ExcelImportExport;
use SilverStripe\Core\Manifest\ModuleResourceLoader;

use SilverStripe\i18n\i18n;

/**
 * Helps exporting and importing labels from a csv or xls
 */
class TranslationsImportExportTask extends BuildTask
{
    use BuildTaskTools;

    /**
     * @var string
     */
    private static $segment = 'TranslationsImportExportTask';
    /**
     * @var string
     */
    protected $title = "Translations import export task";
    /**
     * @var string
     */
    protected $description = "Easily import and export translations";

    /**
     * @var bool
     */
    public $debug;

    /**
     * @param \SilverStripe\Control\HTTPRequest $request
     * @return void
     */
    public function run($request)
    {
        $this->request = $request;
        $modules = $this->getModulesAndThemes();
        $this->addOption("import", "Import translations", false);
        $this->addOption("export", "Export translations", false);
        $this->addOption("export_untranslated", "Export untranslated", false);
        $this->addOption("export_only", "Export only these lang (comma separated)");
        $this->addOption("debug", "Show debug output and do not write files", false);
        $this->addOption("excel", "Use excel if possible (require excel-import-export module)", true);
        $this->addOption("module", "Module", null, $modules);
        $options = $this->askOptions();

        $module = $options['module'];
        $import = $options['import'];
        $excel = $options['excel'];
        $export = $options['export'];
        $export_only = $options['export_only'];
        $export_untranslated = $options['export_untranslated'];

        $this->debug = $options['debug'];

        if ($module) {
            if ($import) {
                $this->importTranslations($module);
            }
            if ($export) {
                $onlyLang = [];
                if ($export_only) {
                    $onlyLang = explode(",", $export_only);
                }
                $this->exportTranslations($module, $excel, $onlyLang, $export_untranslated);
            }
        } else {
            $this->message("Please select a module");
        }
    }

    protected function getLangPath(string $module): string
    {
        $langPath = ModuleResourceLoader::resourcePath($module . ':lang');
        return Director::baseFolder() . '/' . str_replace([':', '\\'], '/', $langPath);
    }

    /**
     * @param string $module
     * @return void
     */
    protected function importTranslations($module)
    {
        $fullLangPath = $this->getLangPath($module);
        $modulePath = dirname($fullLangPath);

        $excelFile = $modulePath . "/lang.xlsx";
        $csvFile = $modulePath . "/lang.csv";

        $data = null;
        if (is_file($excelFile)) {
            $this->message("Importing $excelFile");
            $data = $this->importFromExcel($excelFile);
        } elseif (is_file($csvFile)) {
            $this->message("Importing $csvFile");
            $data = $this->importFromCsv($csvFile);
        }

        if (!$data) {
            $this->message("No data to import");
            return;
        }

        if ($this->debug) {
            Debug::dump($data);
        }

        $header = array_keys($data[0]);
        $count = count($header);
        $writer = Injector::inst()->create(Writer::class);
        $langs = array_slice($header, 1, $count);
        $new = 0;
        foreach ($langs as $lang) {
            // keep original
            $reader = new YamlReader;
            $entities = $reader->read($lang, $fullLangPath . '/' . $lang . '.yml');
            foreach ($data as $row) {
                $key = trim($row['\ufeffkey'] ?? $row['key'] ?? '');
                if (!$key) {
                    $this->message("invalid row " . json_encode($row));
                    continue;
                }
                $value = $row[$lang];
                if (is_string($value)) {
                    $value = trim($value);
                }
                $new++;
                // Only write non empty values
                if ($value !== null) {
                    $entities[$key] = $value;
                }
            }
            if (!$this->debug) {
                $writer->write(
                    $entities,
                    $lang,
                    dirname($fullLangPath)
                );
                $this->message("Imported $new messages in $lang");
            } else {
                Debug::show($lang);
                Debug::dump($entities);
            }
        }
    }

    /**
     * @param string $file
     * @return array<int,array<mixed>>
     */
    public function importFromExcel($file)
    {
        $spreadsheet = IOFactory::load($file);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = [];
        foreach ($worksheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            // $cellIterator->setIterateOnlyExistingCells(true);
            $cells = [];
            foreach ($cellIterator as $cell) {
                $cells[] = $cell->getValue();
            }
            if (empty($cells)) {
                break;
            }
            $rows[] = $cells;
        }
        return $this->getDataFromRows($rows);
    }

    /**
     * @param array<array<mixed>> $rows
     * @return array<int,array<mixed>>
     */
    protected function getDataFromRows($rows)
    {
        $header = array_shift($rows);
        $firstKey = $header[0];
        if ($firstKey == 'key') {
            $header[0] = 'key'; // Fix some weird stuff
        }
        $count = count($header);
        $data = [];
        foreach ($rows as $row) {
            while (count($row) < $count) {
                $row[] = '';
            }
            $row = array_slice($row, 0, $count);
            $row = $this->normalizeRow($row);
            $data[] = array_combine($header, $row);
        }
        return $data;
    }

    /**
     * @return array<int,array<mixed>>
     */
    protected function importFromCsv(string $file)
    {
        $arr = file($file);
        if (!$arr) {
            return [];
        }
        $rows = array_map('str_getcsv', $arr);
        return $this->getDataFromRows($rows);
    }

    /**
     * @param array<int,mixed> $row
     * @return array<int,mixed>
     */
    protected function normalizeRow($row)
    {
        foreach ($row as $idx => $value) {
            if ($idx == 0 || $value === null) {
                continue;
            }
            if (strpos($value, '{"') === 0) {
                $row[$idx] = json_decode($value, true);
            }
        }
        return $row;
    }

    /**
     * @param string $module
     * @param boolean $excel
     * @param array<string> $onlyLang
     * @param bool $untranslated
     * @param bool $translate
     * @return void
     */
    public function exportTranslations($module, $excel = true, $onlyLang = [], $untranslated = false)
    {
        $fullLangPath = $this->getLangPath($module);

        $translationFiles = glob($fullLangPath . '/*.yml');
        if ($translationFiles === false) {
            $this->message("No yml");
            return;
        }

        // Collect messages in all lang
        $allMessages = [];
        $headers = ['key'];
        $default = [];
        $defaultFile = null;
        $defaultLang = substr(i18n::get_locale(), 0, 2);
        foreach ($translationFiles as $translationFile) {
            $lang = pathinfo($translationFile, PATHINFO_FILENAME);
            if ($lang == $defaultLang) {
                $defaultFile = $translationFile;
            }
            if (!empty($onlyLang) && !in_array($lang, $onlyLang)) {
                continue;
            }
            $headers[] = $lang;
            $default[] = '';
        }


        $masterMessages = [];
        if ($untranslated) {
            $reader = new YamlReader;
            $masterMessages = $reader->read($defaultLang, $defaultFile);
        }


        $i = 1;
        foreach ($translationFiles as $translationFile) {
            $lang = pathinfo($translationFile, PATHINFO_FILENAME);
            if (!empty($onlyLang) && !in_array($lang, $onlyLang)) {
                continue;
            }
            $reader = new YamlReader;
            $messages = $reader->read($lang, $translationFile);

            foreach ($messages as $entityKey => $v) {
                if ($untranslated) {
                    if (isset($masterMessages[$entityKey]) && $masterMessages[$entityKey] != $v) {
                        continue;
                    }
                }

                if (!isset($allMessages[$entityKey])) {
                    $allMessages[$entityKey] = $default;
                }
                // Plurals can be arrays and need to be converted
                if (is_array($v)) {
                    $v = json_encode($v);
                }



                $allMessages[$entityKey][$i] = $v;
            }
            $i++;
        }
        // don't sort this will be mess up git when merging later
        // ksort($allMessages);
        if ($this->debug) {
            Debug::show($allMessages);
        }

        // Write them to a file
        if ($excel && class_exists(ExcelImportExport::class)) {
            $ext = 'xlsx';
            $destinationFilename = str_replace('/lang', '/lang.' . $ext, $fullLangPath);
            if ($this->debug) {
                Debug::show("Debug mode enabled : no output will be sent to $destinationFilename");
                return;
            }
            if (is_file($destinationFilename)) {
                unlink($destinationFilename);
            }
            // First row contains headers
            $data = [$headers];
            // Add a row per lang
            foreach ($allMessages as $key => $translations) {
                array_unshift($translations, $key);
                $data[] = $translations;
            }
            ExcelImportExport::arrayToFile($data, $destinationFilename);
        } else {
            $ext = 'csv';
            $destinationFilename = str_replace('/lang', '/lang.' . $ext, $fullLangPath);
            if ($this->debug) {
                Debug::show("Debug mode enabled : no output will be sent to $destinationFilename");
                return;
            }
            if (is_file($destinationFilename)) {
                unlink($destinationFilename);
            }
            $fp = fopen($destinationFilename, 'w');
            if ($fp === false) {
                throw new Exception("Failed to open stream");
            }
            // UTF 8 fix
            fprintf($fp, "\xEF\xBB\xBF");
            fputcsv($fp, $headers);
            foreach ($allMessages as $key => $translations) {
                array_unshift($translations, $key);
                fputcsv($fp, $translations);
            }
            fclose($fp);
        }

        $this->message("Translations written to $destinationFilename");
    }

    public function isEnabled(): bool
    {
        return Director::isDev();
    }
}
