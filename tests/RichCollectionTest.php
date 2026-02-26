<?php

namespace LeKoala\Multilingual\Test;

use PHPUnit\Framework\TestCase;
use LeKoala\Multilingual\MultilingualTextCollector;
use SilverStripe\Core\Manifest\Module;

class RichCollectionTest extends TestCase
{
    public function testCollectionCapturesContext()
    {
        $collector = new class extends MultilingualTextCollector {
            public function __construct()
            {
            }
        };

        $content = '<?php _t("MyClass.MY_KEY", "Original String")';
        $module = new class('/tmp/app', '/tmp') extends Module {
        };

        $entities = $collector->collectFromCode($content, 'src/MyClass.php', $module);

        $this->assertArrayHasKey('MyClass.MY_KEY', $entities);
        $spec = $entities['MyClass.MY_KEY'];
        $this->assertIsArray($spec);
        $this->assertEquals('Original String', $spec['default']);
        $this->assertEquals('src/MyClass.php', $spec['file']);
    }

    public function testWriteFlattensSpec()
    {
        $collector = new class extends MultilingualTextCollector {
            public function __construct()
            {
            }
        };

        $richSpec = [
            'default' => 'Original String',
            'file' => 'src/MyClass.php'
        ];

        $clean = $collector->flattenSpec($richSpec);
        $this->assertEquals('Original String', $clean);

        // Plural specs should be preserved
        $pluralSpec = [
            'one' => 'One item',
            'other' => '{count} items',
        ];
        $cleanPlural = $collector->flattenSpec($pluralSpec);
        $this->assertEquals($pluralSpec, $cleanPlural);
    }
}
