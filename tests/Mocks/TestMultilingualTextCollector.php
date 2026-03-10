<?php

namespace LeKoala\Multilingual\Test\Mocks;

use LeKoala\Multilingual\MultilingualTextCollector;

class TestMultilingualTextCollector extends MultilingualTextCollector
{
    public $mockModule;

    public function __construct($reader, $mockModule)
    {
        $this->reader = $reader;
        $this->mockModule = $mockModule;
        $this->setClearUnused(true);
    }

    protected function getModulesAndThemes()
    {
        return ['mymodule' => $this->mockModule];
    }

    protected function getModuleName($name, $module)
    {
        return $name;
    }
}
