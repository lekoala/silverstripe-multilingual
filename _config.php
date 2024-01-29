<?php

// Add global translation helper
if (!function_exists('_g')) {
    function _g(string $entity): string
    {
        return \LeKoala\Multilingual\LangHelper::globalTranslation($entity);
    }
}
