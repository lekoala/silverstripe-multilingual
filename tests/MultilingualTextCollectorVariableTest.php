<?php

namespace LeKoala\Multilingual\Test;

use PHPUnit\Framework\TestCase;
use LeKoala\Multilingual\MultilingualTextCollector;

class MultilingualTextCollectorVariableTest extends TestCase
{
    public function testCheckVariables()
    {
        // Use anonymous class to bypass parent constructor
        $collector = new class extends MultilingualTextCollector {
            public function __construct()
            {
                // No-op to avoid framework bootstrap
            }
        };

        $scenarios = [
            ['Hello {name}', 'Bonjour {name}', true],
            ['Hello {name}', 'Bonjour {nom}', false],
            ['Page {current} of {total}', 'Page {current} sur {total}', true],
            ['Page {current} of {total}', 'Page {current}', false],
            ['No vars', 'Pas de vars', true],
            ['No vars', 'Pas de {vars}', false],
            ['Order {one} {two}', 'Ordre {two} {one}', true], // Order doesn't matter
        ];

        foreach ($scenarios as [$source, $target, $expected]) {
            $result = $collector->checkVariables($source, $target);
            $this->assertEquals(
                $expected,
                $result,
                "Failed asserting that checking '$source' against '$target' is " . ($expected ? 'true' : 'false')
            );
        }
    }
}
