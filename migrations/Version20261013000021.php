<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.8.0: вход через SAML — использованные утверждения (защита от повторной отправки ответа провайдера).
 */
final class Version20261013000021 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SAML: использованные утверждения';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE saml_assertion (id VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, INDEX idx_saml_assertion_expires (expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE saml_assertion');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
