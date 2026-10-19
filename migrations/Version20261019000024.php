<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.8.0: разделы «Подготовка презентаций» и «Подготовка пятничной презентации» — презентации и темы
 * собраний (слайды в JSON, рабочая и одобренная редакции, модерация), пятничные собрания, история версий,
 * изображения слайдов и замечания к слайдам.
 */
final class Version20261019000024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Презентации и пятничные собрания';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE meeting (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, meeting_date DATE NOT NULL, title VARCHAR(255) DEFAULT NULL, deadline DATETIME DEFAULT NULL, status VARCHAR(16) NOT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, created_by_id INT DEFAULT NULL, INDEX idx_meeting_date (meeting_date), UNIQUE INDEX uniq_meeting_number (number), INDEX IDX_F515E139B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE presentation (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(16) NOT NULL, title VARCHAR(255) NOT NULL, responsible VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, position INT NOT NULL, status VARCHAR(16) NOT NULL, content LONGTEXT NOT NULL, revision INT NOT NULL, preview MEDIUMTEXT DEFAULT NULL, slide_count SMALLINT NOT NULL, approved_content LONGTEXT DEFAULT NULL, approved_preview MEDIUMTEXT DEFAULT NULL, approved_slide_count SMALLINT NOT NULL, approved_revision INT DEFAULT NULL, approved_title VARCHAR(255) DEFAULT NULL, approved_responsible VARCHAR(255) DEFAULT NULL, approved_at DATETIME DEFAULT NULL, submitted_at DATETIME DEFAULT NULL, submit_note LONGTEXT DEFAULT NULL, decision_note LONGTEXT DEFAULT NULL, decided_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, author_id INT DEFAULT NULL, meeting_id INT DEFAULT NULL, approved_by_id INT DEFAULT NULL, decided_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, INDEX idx_presentation_kind_status (kind, status), INDEX idx_presentation_meeting (meeting_id, position), INDEX IDX_9B66E893F675F31B (author_id), INDEX IDX_9B66E89367433D9C (meeting_id), INDEX IDX_9B66E8932D234F6A (approved_by_id), INDEX IDX_9B66E893E26B496B (decided_by_id), INDEX IDX_9B66E893896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE presentation_version (id INT AUTO_INCREMENT NOT NULL, revision INT NOT NULL, kind VARCHAR(16) NOT NULL, content LONGTEXT NOT NULL, slide_count SMALLINT NOT NULL, created_at DATETIME NOT NULL, presentation_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX idx_presentation_version (presentation_id, created_at), INDEX IDX_226F939AAB627E8B (presentation_id), INDEX IDX_226F939AB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE presentation_asset (id INT AUTO_INCREMENT NOT NULL, token VARCHAR(32) NOT NULL, mime VARCHAR(32) NOT NULL, size INT NOT NULL, width INT NOT NULL, height INT NOT NULL, sha256 VARCHAR(64) NOT NULL, original_name VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, presentation_id INT DEFAULT NULL, uploaded_by_id INT DEFAULT NULL, UNIQUE INDEX uniq_presentation_asset_token (token), INDEX IDX_E4CAA1FDAB627E8B (presentation_id), INDEX IDX_E4CAA1FDA2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE presentation_comment (id INT AUTO_INCREMENT NOT NULL, slide_id VARCHAR(64) DEFAULT NULL, element_id VARCHAR(64) DEFAULT NULL, author_name VARCHAR(255) NOT NULL, text LONGTEXT NOT NULL, created_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, presentation_id INT NOT NULL, parent_id INT DEFAULT NULL, author_id INT DEFAULT NULL, resolved_by_id INT DEFAULT NULL, INDEX idx_presentation_comment (presentation_id, created_at), INDEX IDX_9071235AB627E8B (presentation_id), INDEX IDX_9071235727ACA70 (parent_id), INDEX IDX_9071235F675F31B (author_id), INDEX IDX_90712356713A32B (resolved_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE presentation_asset ADD CONSTRAINT FK_E4CAA1FDAB627E8B FOREIGN KEY (presentation_id) REFERENCES presentation (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE presentation_asset ADD CONSTRAINT FK_E4CAA1FDA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE presentation ADD CONSTRAINT FK_9B66E893F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE presentation ADD CONSTRAINT FK_9B66E89367433D9C FOREIGN KEY (meeting_id) REFERENCES meeting (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE presentation ADD CONSTRAINT FK_9B66E8932D234F6A FOREIGN KEY (approved_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE presentation ADD CONSTRAINT FK_9B66E893E26B496B FOREIGN KEY (decided_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE presentation ADD CONSTRAINT FK_9B66E893896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE presentation_version ADD CONSTRAINT FK_226F939AAB627E8B FOREIGN KEY (presentation_id) REFERENCES presentation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE presentation_version ADD CONSTRAINT FK_226F939AB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT FK_F515E139B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE presentation_comment ADD CONSTRAINT FK_9071235AB627E8B FOREIGN KEY (presentation_id) REFERENCES presentation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE presentation_comment ADD CONSTRAINT FK_9071235727ACA70 FOREIGN KEY (parent_id) REFERENCES presentation_comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE presentation_comment ADD CONSTRAINT FK_9071235F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE presentation_comment ADD CONSTRAINT FK_90712356713A32B FOREIGN KEY (resolved_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE presentation_comment');
        $this->addSql('DROP TABLE presentation_asset');
        $this->addSql('DROP TABLE presentation_version');
        $this->addSql('DROP TABLE presentation');
        $this->addSql('DROP TABLE meeting');
        $this->addSql("DELETE FROM setting WHERE name LIKE 'presentations.%'");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
