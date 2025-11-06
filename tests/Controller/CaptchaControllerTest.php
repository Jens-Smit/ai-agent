<?php

namespace App\Tests\Controller;

use App\Service\CaptchaGeneratorService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class CaptchaControllerTest extends WebTestCase
{
    public function testGenerateCaptcha(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/captcha/generate');

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $responseContent = $client->getResponse()->getContent();
        $this->assertJson($responseContent);

        $data = json_decode($responseContent, true);
        $this->assertArrayHasKey('captchaId', $data);
        $this->assertArrayHasKey('imageParts', $data);
        $this->assertArrayHasKey('initialRotations', $data);

        $this->assertCount(4, $data['imageParts']);
        $this->assertCount(4, $data['initialRotations']);
    }

    public function testVerifyCaptchaSuccess(): void
    {
        $client = static::createClient();

        // 1) CAPTCHA über die API anfordern - das setzt die Session + Cookie im Client
        $client->request('GET', '/api/captcha/generate');
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $generateData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('captchaId', $generateData);
        $this->assertArrayHasKey('initialRotations', $generateData);

        $captchaId = $generateData['captchaId'];
        $initialRotations = $generateData['initialRotations'];

        // 2) userClicks so berechnen, dass die finale Rotation 0° ergibt
        // clicks = (initialRotation / ROTATION_STEP) mod (360 / ROTATION_STEP)
        $userClicks = array_map(function($rotation) {
            return (int) (($rotation / CaptchaGeneratorService::ROTATION_STEP) % (360 / CaptchaGeneratorService::ROTATION_STEP));
        }, $initialRotations);

        // 3) Mit demselben Client das Verify-Endpoint aufrufen
        $client->request(
            'POST',
            '/api/captcha/verify',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'captchaId' => $captchaId,
                'userClicks' => $userClicks,
            ])
        );

        // 4) Assertions: Antwort muss erfolgreich sein
        $this->assertResponseIsSuccessful();
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $responseData);
        $this->assertTrue($responseData['success']);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertSame('CAPTCHA erfolgreich gelöst.', $responseData['message']);
    }

    public function testVerifyCaptchaFailsWithIncorrectSolution(): void
    {
        $client = static::createClient();

        // CAPTCHA generieren (Session & Cookie werden vom Client verwaltet)
        $client->request('GET', '/api/captcha/generate');
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $generateData = json_decode($client->getResponse()->getContent(), true);
        $captchaId = $generateData['captchaId'];

        // Absichtlich falsche Lösung senden
        $client->request(
            'POST',
            '/api/captcha/verify',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'captchaId' => $captchaId,
                'userClicks' => [1, 1, 1, 1], // falsch
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $responseData);
        $this->assertFalse($responseData['success']);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertSame('Falsche CAPTCHA-Lösung. Bitte versuchen Sie es erneut.', $responseData['message']);
    }
}
