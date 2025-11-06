<?php
// tests/Service/AuthServiceTest.php

namespace App\Tests\Service;

use App\DTO\RegisterRequestDTO;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuthService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuthServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;
    private AuthService $authService;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->authService = new AuthService($this->em, $this->passwordHasher);
    }

    public function testRegisterSuccessful()
    {
        $dto = new RegisterRequestDTO('test@example.com', 'password123');
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $this->em->method('getRepository')->willReturn($userRepo);
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(User::class));
        $this->em->expects($this->once())->method('flush');

        $this->passwordHasher->expects($this->once())->method('hashPassword');

        $this->authService->register($dto);
    }

    public function testRegisterFailsForExistingUser()
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('E-Mail ist bereits registriert.');

        $dto = new RegisterRequestDTO('existing@example.com', 'password123');
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn(new User());

        $this->em->method('getRepository')->willReturn($userRepo);

        $this->authService->register($dto);
    }
}