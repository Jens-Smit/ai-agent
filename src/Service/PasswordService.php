<?php
namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class PasswordService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MailerInterface $mailer,
        private readonly string $frontendUrl = 'http://localhost:3000',
        private readonly int $resetTtlSeconds = 3600
    ) {}

    /**
     * Generiert einen Reset-Token, speichert Hash+Expiry und sendet Mail (falls User existiert).
     * Return: plain token (useful for tests or further processing) or null if user not found.
     */
    public function requestPasswordReset(string $email): ?string
    {
        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user) {
            // Keine Info zu Existenz zurückgeben
            return null;
        }

        // Sicherer, URL-freundlicher Token
        $token = bin2hex(random_bytes(32));

        $user->setResetTokenPlain($token, $this->resetTtlSeconds);

        $this->em->persist($user);
        $this->em->flush();

        // Send mail (swallow exceptions if you prefer)
        $this->sendResetEmail($user, $token);

        return $token;
    }

    /**
     * Setzt das Passwort mit einem gültigen Reset-Token zurück
     */
    public function resetPassword(string $token, string $newPassword): void
    {
        // Suche per Hash (sichere Suche)
        $tokenHash = hash('sha256', $token);
        $user = $this->userRepository->findOneBy(['resetTokenHash' => $tokenHash]);

        if (!$user) {
            throw new BadRequestHttpException('Ungültiger Reset-Token.');
        }

        // Prüfen ob Token noch gültig
        if (!$user->isResetTokenValid()) {
            throw new BadRequestHttpException('Reset-Token ist abgelaufen.');
        }

        // Passwortvalidierung
        if (mb_strlen($newPassword) < 8) {
            throw new BadRequestHttpException('Passwort muss mindestens 8 Zeichen lang sein.');
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);

        // Token löschen
        $user->clearResetToken();

        $this->em->persist($user);
        $this->em->flush();
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new BadRequestHttpException('Das aktuelle Passwort ist ungültig.');
        }
        if ($currentPassword === $newPassword) {
            throw new BadRequestHttpException('Neues Passwort muss sich vom alten unterscheiden.');
        }

        if (mb_strlen($newPassword) < 8) {
            throw new BadRequestHttpException('Neues Passwort muss mindestens 8 Zeichen lang sein.');
        }

        

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->em->flush();
    }

    private function sendResetEmail(User $user, string $token): void
    {
        $resetUrl = sprintf('%s/reset-password?token=%s', rtrim($this->frontendUrl, '/'), $token);

        $email = (new Email())
            ->from('webmaster@jenssmit.de')
            ->to($user->getEmail())
            ->subject('Passwort zurücksetzen')
            ->html(sprintf(
                '<p>Hallo,</p>
                <p>Sie haben eine Anfrage zum Zurücksetzen Ihres Passworts gestellt.</p>
                <p>Klicken Sie auf folgenden Link, um Ihr Passwort zurückzusetzen:</p>
                <p><a href="%s">Passwort zurücksetzen</a></p>
                <p>Dieser Link ist %d Minuten gültig.</p>',
                htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                (int) ($this->resetTtlSeconds / 60)
            ));

        $this->mailer->send($email);
    }
}
