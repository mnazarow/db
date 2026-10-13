<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.8.0: уведомления в браузере (Web Push) — подписки устройств сотрудников.
 */
final class Version20261013000020 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Уведомления в браузере: подписки устройств';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE push_subscription (id INT AUTO_INCREMENT NOT NULL, endpoint LONGTEXT NOT NULL, endpoint_hash VARCHAR(64) NOT NULL, p256dh VARCHAR(128) NOT NULL, auth_secret VARCHAR(64) NOT NULL, device VARCHAR(128) DEFAULT NULL, created_at DATETIME NOT NULL, last_sent_at DATETIME DEFAULT NULL, failures INT DEFAULT 0 NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_push_endpoint (endpoint_hash), INDEX IDX_562830F3A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_562830F3A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE push_subscription');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
