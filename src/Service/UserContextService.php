<?php
// src/Service/UserContextService.php

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Speichert User-Kontext für asynchrone Jobs
 */
class UserContextService
{
    private ?int $currentUserId = null;
    
    public function __construct(
        private LoggerInterface $logger
    ) {}
    
    public function setCurrentUser(?User $user): void
    {
        $this->currentUserId = $user?->getId();
        $this->logger->info('User context set', [
            'userId' => $this->currentUserId
        ]);
    }
    
    public function getCurrentUserId(): ?int
    {
        return $this->currentUserId;
    }
    
    public function clear(): void
    {
        $this->currentUserId = null;
    }
}