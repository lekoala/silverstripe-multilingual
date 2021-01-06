<?php

// Add global translation helper

use LeKoala\Multilingual\LangHelper;

if (!function_exists('_g')) {
    function _g($entity)
    {
        return LangHelper::globalTranslation($entity);
    }
}
