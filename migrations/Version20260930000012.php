<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Html\PageHtml;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.6.1.
 *
 * - Текст страниц переносится из document_version.content в отдельную таблицу document_version_content:
 *   списки версий и документов больше не читают из базы мегабайты HTML со вставленными картинками.
 *   Неразрывные пробелы, которыми редактор заменял обычные, превращаются обратно в обычные (иначе абзацы
 *   не переносились по строкам).
 * - user.sso_subject — привязка учётной записи к субъекту поставщика SSO (issuer|sub): вход через SSO
 *   больше не находит учётную запись только по совпадению логина.
 * - События ознакомления и согласования, которые прежние версии записывали как «изменение» (update),
 *   получают собственные типы — они больше не выглядят как правки документа в истории и уведомлениях.
 */
final class Version20260930000012 extends AbstractMigration
{

    public function getDescription(): string
    {
        return 'Текст страниц в отдельной таблице, привязка SSO к субъекту, типы событий ознакомления и согласования';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document_version_content (id INT AUTO_INCREMENT NOT NULL, html MEDIUMTEXT NOT NULL, version_id INT NOT NULL, UNIQUE INDEX uniq_document_version_content (version_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE document_version_content ADD CONSTRAINT FK_BC0D4234BBC2705 FOREIGN KEY (version_id) REFERENCES document_version (id) ON DELETE CASCADE');
        $this->addSql("INSERT INTO document_version_content (version_id, html) SELECT id, content FROM document_version WHERE content IS NOT NULL AND content <> '' ORDER BY id");
        // Обновление идёт без остановки портала: страницы, сохранённые прежней версией во время копирования,
        // дописываются перед удалением столбца (иначе их текст потерялся бы).
        $this->addSql("INSERT INTO document_version_content (version_id, html) SELECT v.id, v.content FROM document_version v LEFT JOIN document_version_content c ON c.version_id = v.id WHERE c.id IS NULL AND v.content IS NOT NULL AND v.content <> '' ORDER BY v.id");
        $this->addSql('ALTER TABLE document_version DROP content');

        $this->addSql('ALTER TABLE user ADD sso_subject VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_sso_subject ON user (sso_subject)');

        $this->addSql("UPDATE document_event SET type = 'acknowledge' WHERE type = 'update' AND details IS NOT NULL AND JSON_CONTAINS_PATH(details, 'one', '$.acknowledgement')");
        $this->addSql("UPDATE document_event SET type = 'approval' WHERE type = 'update' AND details IS NOT NULL AND JSON_CONTAINS_PATH(details, 'one', '$.approval')");
    }

    /**
     * Обычные пробелы вместо неразрывных. Обрабатываются только страницы, где неразрывные пробелы есть, и по
     * одной (в тексте бывают вставленные картинки на мегабайты). Это косметическое исправление: его ошибка
     * не должна срывать миграцию — схема к этому моменту уже изменена, и повторный запуск был бы невозможен.
     * Тот же текст исправляется и при следующем сохранении страницы.
     */
    public function postUp(Schema $schema): void
    {
        try {
            if (!$this->connection->createSchemaManager()->tablesExist(['document_version_content'])) {
                return; // --dry-run: таблица ещё не создана
            }
            $ids = $this->connection->fetchFirstColumn("SELECT id FROM document_version_content WHERE LOCATE(UNHEX('C2A0'), CAST(html AS BINARY)) > 0 ORDER BY id");
            $fixed = 0;
            foreach ($ids as $id) {
                $html = (string) $this->connection->fetchOne('SELECT html FROM document_version_content WHERE id = ?', [$id]);
                $normalized = PageHtml::breakableSpaces($html);
                if ($normalized !== $html) {
                    $this->connection->update('document_version_content', ['html' => $normalized], ['id' => $id]);
                    ++$fixed;
                }
            }
            if ($fixed > 0) {
                $this->write(\sprintf('Страниц с исправленными пробелами: %d', $fixed));
            }
        } catch (\Throwable $e) {
            $this->write('Пробелы в тексте страниц не исправлены (это не влияет на работу, текст исправится при сохранении страницы): '.$e->getMessage());
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_version ADD content MEDIUMTEXT DEFAULT NULL');
        $this->addSql('UPDATE document_version v JOIN document_version_content c ON c.version_id = v.id SET v.content = c.html');
        $this->addSql('ALTER TABLE document_version_content DROP FOREIGN KEY FK_BC0D4234BBC2705');
        $this->addSql('DROP TABLE document_version_content');
        $this->addSql('DROP INDEX uniq_user_sso_subject ON user');
        $this->addSql('ALTER TABLE user DROP sso_subject');
        $this->addSql("UPDATE document_event SET type = 'update' WHERE type IN ('acknowledge', 'approval')");
        $this->addSql("UPDATE document_event SET type = 'new_version' WHERE type = 'draft_version'");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
