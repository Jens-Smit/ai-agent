<?php
// src/Repository/UserLlmSettingsRepository.php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserLlmSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserLlmSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserLlmSettings::class);
    }
}