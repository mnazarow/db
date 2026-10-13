<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.8.0: индексы для больших порталов (десятки тысяч документов) — состояние индекса поиска по
 * содержимому считается без чтения извлечённого текста («Мониторинг», /metrics, задание индексации).
 */
final class Version20261013000018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Индексы состояния document_text';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_document_text_state ON document_text (document_id, version_number, status)');
        $this->addSql('CREATE INDEX idx_document_text_status ON document_text (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_document_text_state ON document_text');
        $this->addSql('DROP INDEX idx_document_text_status ON document_text');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
