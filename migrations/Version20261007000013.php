<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.7.0.
 *
 * - system_job — последний запуск регулярных заданий (проверка сроков, индексация, резервная копия, проверка
 *   восстановления…) для мониторинга (/health/details, /metrics) и панели администратора.
 * - material_row_change — журнал изменений таблиц материалов: кто, когда и что поменял в строке.
 */
final class Version20261007000013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Учёт запусков регулярных заданий и журнал изменений таблиц материалов';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE material_row_change (id INT AUTO_INCREMENT NOT NULL, row_title VARCHAR(255) NOT NULL, action VARCHAR(16) NOT NULL, field VARCHAR(64) DEFAULT NULL, field_label VARCHAR(255) DEFAULT NULL, old_value LONGTEXT DEFAULT NULL, new_value LONGTEXT DEFAULT NULL, user_name VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, section_id INT NOT NULL, row_id INT DEFAULT NULL, user_id INT DEFAULT NULL, INDEX idx_material_change_section (section_id, created_at), INDEX idx_material_change_row (row_id, created_at), INDEX IDX_B0435190D823E37A (section_id), INDEX IDX_B043519083A269F2 (row_id), INDEX IDX_B0435190A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE system_job (name VARCHAR(64) NOT NULL, last_started_at DATETIME DEFAULT NULL, last_finished_at DATETIME DEFAULT NULL, last_success_at DATETIME DEFAULT NULL, last_status VARCHAR(16) DEFAULT NULL, last_message VARCHAR(500) DEFAULT NULL, last_duration_ms INT DEFAULT NULL, runs INT DEFAULT 0 NOT NULL, failures INT DEFAULT 0 NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE material_row_change ADD CONSTRAINT FK_B0435190D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE material_row_change ADD CONSTRAINT FK_B043519083A269F2 FOREIGN KEY (row_id) REFERENCES material_row (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE material_row_change ADD CONSTRAINT FK_B0435190A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE material_row_change DROP FOREIGN KEY FK_B0435190D823E37A');
        $this->addSql('ALTER TABLE material_row_change DROP FOREIGN KEY FK_B043519083A269F2');
        $this->addSql('ALTER TABLE material_row_change DROP FOREIGN KEY FK_B0435190A76ED395');
        $this->addSql('DROP TABLE material_row_change');
        $this->addSql('DROP TABLE system_job');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
