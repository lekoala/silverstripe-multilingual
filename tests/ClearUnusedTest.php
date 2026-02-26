<?php

namespace LeKoala\Multilingual\Test;

use PHPUnit\Framework\TestCase;
use LeKoala\Multilingual\MultilingualTextCollector;
use SilverStripe\Core\Manifest\Module;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Manifest\ModuleManifest;
use SilverStripe\i18n\Messages\YamlReader;
use ReflectionClass;

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

class ClearUnusedTest extends TestCase
{
    public function testClearUnusedFiltersKeys(): void
    {
        // 1. Setup dummy module and lang file
        $dummyPath = sys_get_temp_dir() . '/' . uniqid('clearunused_test_');
        $langDir = $dummyPath . '/lang';
        if (!mkdir($langDir, 0777, true) && !is_dir($langDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $langDir));
        }

        file_put_contents($langDir . '/en.yml', "en:\n  Entity.STILL_THERE: 'Value'\n  Entity.REMOVED: 'Old Value'");

        $mockModule = $this->getMockBuilder(Module::class)
            ->disableOriginalConstructor()
            ->setMethods(['getPath', 'getName'])
            ->getMock();
        $mockModule->method('getPath')->willReturn($dummyPath);
        $mockModule->method('getName')->willReturn('mymodule');

        $mockManifest = $this->getMockBuilder(ModuleManifest::class)
            ->disableOriginalConstructor()
            ->setMethods(['getModules', 'getModule'])
            ->getMock();
        $mockManifest->method('getModules')->willReturn(['mymodule' => $mockModule]);
        $mockManifest->method('getModule')->willReturn($mockModule);

        // We MUST push a manifest
        ModuleLoader::inst()->pushManifest($mockManifest);

        // 2. Setup collector
        $reader = new YamlReader();
        $collector = new TestMultilingualTextCollector($reader, $mockModule);

        // 3. Current entities (only ONE remains in code)
        $entitiesByModule = [
            'mymodule' => [
                'Entity.STILL_THERE' => 'Value',
            ]
        ];

        // 4. Test mergeWithExisting
        $reflection = new ReflectionClass(MultilingualTextCollector::class);
        $refMethod = $reflection->getMethod('mergeWithExisting');
        $refMethod->setAccessible(true);

        $result = $refMethod->invoke($collector, $entitiesByModule);

        // 5. Assertions
        $this->assertArrayHasKey('mymodule', $result);
        $this->assertArrayHasKey('Entity.STILL_THERE', $result['mymodule']);
        // This is the key we want to see removed
        $this->assertArrayNotHasKey('Entity.REMOVED', $result['mymodule']);

        // 6. Cleanup
        ModuleLoader::inst()->popManifest();
        $this->rmdirRecursive($dummyPath);
    }

    protected function rmdirRecursive(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }

        rmdir($dir);
    }
}
