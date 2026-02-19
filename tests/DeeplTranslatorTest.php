<?php

namespace LeKoala\Multilingual\Test;

use SilverStripe\Dev\SapphireTest;
use LeKoala\Multilingual\DeeplTranslator;
use DeepL\DeepLClient;
use ReflectionClass;

class DeeplTranslatorTest extends SapphireTest
{
    public function testNormalization()
    {
        // We need a dummy API key to initialize the translator
        $translator = new DeeplTranslator('dummy-key');

        $reflection = new ReflectionClass(DeeplTranslator::class);

        $normalizeToCode = $reflection->getMethod('normalizeToCode');
        $normalizeToCode->setAccessible(true);

        $normalizeFromCode = $reflection->getMethod('normalizeFromCode');
        $normalizeFromCode->setAccessible(true);

        // Test Target Normalization
        $this->assertEquals('EN-US', $normalizeToCode->invoke($translator, 'en'));
        $this->assertEquals('EN-US', $normalizeToCode->invoke($translator, 'en_US'));
        $this->assertEquals('EN-GB', $normalizeToCode->invoke($translator, 'en-GB'));
        $this->assertEquals('PT-PT', $normalizeToCode->invoke($translator, 'pt'));
        $this->assertEquals('PT-BR', $normalizeToCode->invoke($translator, 'pt-BR'));
        $this->assertEquals('FR', $normalizeToCode->invoke($translator, 'fr'));

        // Test Source Normalization
        $this->assertEquals('EN', $normalizeFromCode->invoke($translator, 'en'));
        $this->assertEquals('EN-US', $normalizeFromCode->invoke($translator, 'en_US'));
    }

    public function testTranslateCallsClientWithNormalizedCodes()
    {
        // Mock the DeepL client
        $mockClient = $this->getMockBuilder(DeepLClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Expect translateText to be called with normalized codes
        $mockClient->expects($this->once())
            ->method('translateText')
            ->with(
                $this->equalTo('Hello'),
                $this->equalTo('EN'), // From 'en'
                $this->equalTo('EN-US'), // To 'en'
                $this->isType('array')
            )
            ->willReturn((object)['text' => 'Bonjour']);

        $translator = new DeeplTranslator('dummy-key');

        // Inject mock client
        $reflection = new ReflectionClass(DeeplTranslator::class);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($translator, $mockClient);

        $result = $translator->translate('Hello', 'en', 'en');
        $this->assertEquals('Bonjour', $result);
    }
}
