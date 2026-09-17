<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.5.0: двухфакторная аутентификация (секрет TOTP и резервные коды в таблице пользователей).
 */
final class Version20260917000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Двухфакторная аутентификация: totp_secret, totp_confirmed_at, recovery_codes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD totp_secret VARCHAR(64) DEFAULT NULL, ADD totp_confirmed_at DATETIME DEFAULT NULL, ADD recovery_codes JSON NOT NULL');
        // У существующих пользователей колонка заполняется пустым списком: NOT NULL без значения
        // по умолчанию оставил бы пустую строку, которую Doctrine не сможет разобрать как JSON.
        $this->addSql("UPDATE `user` SET recovery_codes = '[]' WHERE recovery_codes IS NULL OR recovery_codes = ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP totp_secret, DROP totp_confirmed_at, DROP recovery_codes');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
