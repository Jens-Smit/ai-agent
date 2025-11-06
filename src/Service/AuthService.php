<?php
namespace App\Service;

use App\DTO\RegisterRequestDTO;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class AuthService
{
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher)
    {
        $this->em = $em;
        $this->passwordHasher = $passwordHasher;
    }

    public function register(RegisterRequestDTO $dto): void
    {
       
        $userRepo = $this->em->getRepository(User::class);
        $existingUser = $userRepo->findOneBy(['email' => $dto->email]);

        if ($existingUser) {
            throw new BadRequestHttpException('E-Mail ist bereits registriert.');
        }

        $user = new User();
        $user->setEmail($dto->email);
        $hashedPassword = $this->passwordHasher->hashPassword($user, $dto->password);
        $user->setPassword($hashedPassword);

        $this->em->persist($user);
        $this->em->flush();
    }
}
