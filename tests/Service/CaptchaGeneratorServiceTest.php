<?php
// tests/Service/CaptchaGeneratorServiceTest.php

namespace App\Tests\Service;

use App\Service\CaptchaGeneratorService;
use PHPUnit\Framework\TestCase;

class CaptchaGeneratorServiceTest extends TestCase
{
    private CaptchaGeneratorService $service;

    protected function setUp(): void
    {
        $this->service = new CaptchaGeneratorService();
    }

    public function testGenerateCaptchaImagesReturnsCorrectStructure()
    {
        $captchaData = $this->service->generateCaptchaImages();

        $this->assertIsArray($captchaData);
        $this->assertArrayHasKey('imageParts', $captchaData);
        $this->assertArrayHasKey('initialRotations', $captchaData);

        $this->assertIsArray($captchaData['imageParts']);
        $this->assertCount(4, $captchaData['imageParts']);

        $this->assertIsArray($captchaData['initialRotations']);
        $this->assertCount(4, $captchaData['initialRotations']);

        foreach ($captchaData['imageParts'] as $imagePart) {
            $this->assertIsString($imagePart);
            // Optional: Überprüfen, ob es sich um eine gültige Base64-kodierte Zeichenkette handelt
            $this->assertStringStartsWith('data:image/png;base64,', $imagePart);
        }

        foreach ($captchaData['initialRotations'] as $rotation) {
            $this->assertIsInt($rotation);
            $this->assertContains($rotation, [0, 45, 90, 135, 180, 225, 270, 315]);
        }
    }
}