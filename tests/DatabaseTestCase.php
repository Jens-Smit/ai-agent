<?php
// tests/DatabaseTestCase.php

namespace App\Tests;

use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class DatabaseTestCase extends WebTestCase
{
    protected static $client;
    protected static $entityManager;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$client = static::createClient();
        self::$entityManager = self::$client->getContainer()
            ->get('doctrine')
            ->getManager();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->truncateAllTables();
    }

    /**
     * Leert alle Tabellen der bekannten Entities
     */
    protected function truncateAllTables(): void
    {
        $connection = self::$entityManager->getConnection();
        $platform   = $connection->getDatabasePlatform();

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');

        foreach (self::$entityManager->getMetadataFactory()->getAllMetadata() as $meta) {
            $tableName = $meta->getTableName();
            $sql       = $platform->getTruncateTableSQL($tableName);
            $connection->executeStatement($sql);
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        self::$entityManager->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (self::$entityManager && self::$entityManager->isOpen()) {
            self::$entityManager->clear();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$entityManager) {
            self::$entityManager->close();
            self::$entityManager = null;
        }

        self::$client = null;

        parent::tearDownAfterClass();
    }
}
