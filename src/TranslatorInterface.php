<?php

namespace LeKoala\Multilingual;

use Exception;

/**
 * Interface for translation services
 */
interface TranslatorInterface
{
    /**
     * Translate a single string
     *
     * @param string|null $string The string to translate
     * @param string $to Target language code
     * @param string $from Source language code
     * @param string|null $context Context for the translation
     * @return string
     * @throws Exception
     */
    public function translate(?string $string, string $to, string $from, ?string $context = null): string;

    /**
     * Translate a string using a reference translation
     *
     * @param string|null $string The string to translate
     * @param string $to Target language code
     * @param string $from Source language code
     * @param string $refString The reference translation string
     * @param string $refLang The reference language code
     * @param string|null $context Context for the translation
     * @return string
     * @throws Exception
     */
    public function translateWithReference(?string $string, string $to, string $from, string $refString, string $refLang, ?string $context = null): string;

    /**
     * Translate multiple strings in a single call if supported
     *
     * @param array<int,array{key:string,value:string,context:?string}> $entries
     * @param string $to Target language code
     * @param string $from Source language code
     * @return array<string,string> key => translated value
     * @throws Exception
     */
    public function translateBatch(array $entries, string $to, string $from): array;

    /**
     * Review a translation
     *
     * @param string $string Source string
     * @param string|null $translation Target string
     * @param string $to Target language
     * @param string $from Source language
     * @param string|null $context Context
     * @return array{valid:bool,correction:?string,comment:?string}
     * @throws Exception
     */
    public function review(string $string, ?string $translation, string $to, string $from, ?string $context = null): array;

    /**
     * Review multiple translations in a single call if supported
     *
     * @param array<int,array{key:string,source:string,translation:string,context:?string}> $entries
     * @param string $to Target language code
     * @param string $from Source language code
     * @return array<string,array{valid:bool,correction:?string}> key => result
     * @throws Exception
     */
    public function reviewBatch(array $entries, string $to, string $from): array;
}
