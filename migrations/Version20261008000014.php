<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.7.0: листы ознакомления в PDF с кодом проверки (страница /verify/<код>).
 */
final class Version20261008000014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Листы ознакомления с кодом проверки подлинности';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE signature_sheet (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(32) NOT NULL, document_title VARCHAR(255) NOT NULL, document_code VARCHAR(100) DEFAULT NULL, version_number SMALLINT NOT NULL, file_checksum VARCHAR(64) DEFAULT NULL, content_hash VARCHAR(64) NOT NULL, acknowledged INT NOT NULL, assigned INT NOT NULL, approvals INT NOT NULL, created_by_name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, document_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX idx_signature_sheet_document (document_id, version_number), UNIQUE INDEX uniq_signature_sheet_code (code), INDEX IDX_F6B63B9C33F7837 (document_id), INDEX IDX_F6B63B9B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE signature_sheet ADD CONSTRAINT FK_F6B63B9C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE signature_sheet ADD CONSTRAINT FK_F6B63B9B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signature_sheet DROP FOREIGN KEY FK_F6B63B9C33F7837');
        $this->addSql('ALTER TABLE signature_sheet DROP FOREIGN KEY FK_F6B63B9B03A8386');
        $this->addSql('DROP TABLE signature_sheet');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
