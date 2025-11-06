<?php
// tests/DatabaseTestCase.php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class DatabaseTestCase extends WebTestCase
{
    protected static $client;
    protected static $entityManager;
    protected static $schemaCreated = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        
        self::$client = static::createClient();
        self::$entityManager = self::$client->getContainer()
            ->get('doctrine')
            ->getManager();

        // Schema nur einmal für alle Tests erstellen
        if (!self::$schemaCreated) {
            self::createSchema();
            self::$schemaCreated = true;
        }
    }

    protected static function createSchema(): void
    {
        $schemaTool = new SchemaTool(self::$entityManager);
        $metadata = self::$entityManager->getMetadataFactory()->getAllMetadata();
        
        try {
            // Versuche Schema zu droppen
            $schemaTool->dropSchema($metadata);
        } catch (\Exception $e) {
            // Ignoriere Fehler
        }
        
        // Erstelle Schema neu
        $schemaTool->createSchema($metadata);
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        // Nur cleanen wenn Schema erstellt wurde
        if (self::$schemaCreated) {
            $this->cleanDatabase();
        }
    }

    protected function cleanDatabase(): void
    {
        $connection = self::$entityManager->getConnection();
        
        try {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
            
            // Alle Tabellen leeren
            $tables = ['post', 'category', 'user'];
            foreach ($tables as $table) {
                try {
                    $connection->executeStatement("TRUNCATE TABLE $table");
                } catch (\Exception $e) {
                    // Ignoriere Fehler wenn Tabelle nicht existiert
                }
            }
            
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        } catch (\Exception $e) {
            // Ignoriere alle DB-Fehler beim Cleanup
        }
        
        // EntityManager clearen
        if (self::$entityManager && self::$entityManager->isOpen()) {
            self::$entityManager->clear();
        }
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
        self::$schemaCreated = false;
        parent::tearDownAfterClass();
    }
}