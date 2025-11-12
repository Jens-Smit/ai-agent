<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add googleId, name, and avatarUrl fields to the User entity for Google OAuth2.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user` ADD google_id VARCHAR(255) DEFAULT NULL UNIQUE, ADD name VARCHAR(255) DEFAULT NULL, ADD avatar_url VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user` DROP google_id, DROP name, DROP avatar_url');
    }
}
