<?php

declare(strict_types=1);

namespace App\Service\Import\Confluence;

use App\Entity\ConfluencePage;
use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\ConfluencePageRepository;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use App\Service\DocumentManager;
use App\Service\FileStorage;
use App\Service\Http\HttpTransportInterface;
use App\Service\PortalSettings;
use App\Service\SectionManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Импорт пространства Confluence в раздел портала.
 *
 *  - страница → документ-страница (текст переводится StorageConverter); страница с дочерними → раздел с её
 *    названием, в нём — её документ и дочерние страницы (или всё в одном разделе — «без разделов»);
 *  - вложения (кроме изображений, они встроены в страницы) → документы-файлы рядом со страницей;
 *  - повторный импорт: новая версия страницы или вложения в Confluence — новая версия документа, без изменений —
 *    пропуск; удалённые и перенесённые в портале документы не возвращаются на место.
 *
 * Соответствие страниц документам хранится в confluence_page; ссылки между страницами ведут на /confluence/page —
 * оттуда на импортированный документ (или на страницу в Confluence, если её ещё не импортировали).
 */
final class ConfluenceImporter
{
    /** Изображения больше этого в страницу не встраиваются (остаются ссылкой на вложение). */
    private const MAX_EMBED_BYTES = 3 * 1024 * 1024;
    /** Сколько изображений (в байтах) встраивается в одну страницу — страница портала не больше 12 МБ. */
    private const MAX_EMBED_TOTAL = 8 * 1024 * 1024;

    private readonly string $dir;

    public function __construct(
        private readonly HttpTransportInterface $http,
        private readonly PortalSettings $settings,
        private readonly EntityManagerInterface $em,
        private readonly ConfluencePageRepository $links,
        private readonly SectionRepository $sections,
        private readonly SectionManager $sectionManager,
        private readonly UserRepository $users,
        private readonly DocumentManager $documents,
        private readonly FileStorage $storage,
        private readonly StorageConverter $converter,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $auditLogger,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ) {
        $this->dir = rtrim($projectDir, '/').'/var/import-jobs';
    }

    /** @param array{url: string, auth: string, user: string, token: string}|null $config */
    public function api(?array $config = null): ConfluenceApi
    {
        $config ??= $this->settings->confluence();
        if ('' === $config['url']) {
            throw new ConfluenceException('Не задан адрес Confluence.');
        }

        return new ConfluenceApi($this->http, $config);
    }

    public function isConfigured(): bool
    {
        $c = $this->settings->confluence();

        return '' !== $c['url'] && '' !== $c['token'];
    }

    /**
     * Новое задание: пространство, его страницы и раздел, куда они попадут.
     *
     * @param array{space: string, root?: ?string, section?: ?int, attachments?: bool, publish?: bool, flat?: bool} $options section null — новый раздел верхнего уровня с названием пространства
     *
     * @throws ConfluenceException
     */
    public function start(array $options, User $actor): ConfluenceJob
    {
        $api = $this->api();
        $space = $api->space($options['space']);
        $root = trim((string) ($options['root'] ?? ''));
        $pages = $api->pages($space['key'], '' !== $root ? $root : null);
        if ([] === $pages) {
            throw new ConfluenceException(\sprintf('В пространстве %s нет страниц, доступных учётной записи.', $space['key']));
        }
        $target = null;
        if (null !== ($options['section'] ?? null) && 0 !== $options['section']) {
            $target = $this->sections->find((int) $options['section']);
            if (null === $target) {
                throw new ConfluenceException('Раздел портала не найден.');
            }
        } else {
            $name = mb_substr($space['name'], 0, 255);
            $target = $this->sections->findChildByName(null, $name) ?? $this->sectionManager->create($name, null, 'Импортировано из Confluence (пространство '.$space['key'].').', $actor);
        }
        $childCount = [];
        foreach ($pages as $p) {
            if (null !== $p['parentId']) {
                $childCount[$p['parentId']] = ($childCount[$p['parentId']] ?? 0) + 1;
            }
        }
        $this->cleanup();
        $job = new ConfluenceJob('confluence-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)), $api->base(), $space['key'], $space['name'],
            '' !== $root ? $root : null, (int) $target->getId(), (bool) ($options['attachments'] ?? true), (bool) ($options['publish'] ?? true),
            (bool) ($options['flat'] ?? false), $pages, $childCount, (int) $actor->getId(), $actor->getDisplayName(), new \DateTimeImmutable());
        $this->save($job);
        $this->auditLogger->info('Начат импорт из Confluence', ['space' => $space['key'], 'pages' => \count($pages), 'section' => $target->getFullName(), 'by' => $actor->getUsername()]);

        return $job;
    }

    public function load(string $id): ?ConfluenceJob
    {
        if (!preg_match('/^confluence-[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id) || !is_file($this->path($id))) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->path($id)), true);

        return \is_array($data) ? ConfluenceJob::fromArray($data) : null;
    }

    public function save(ConfluenceJob $job): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Не удалось создать каталог заданий импорта: '.$this->dir);
        }
        $job->updatedAt = new \DateTimeImmutable();
        $path = $this->path($job->id);
        if (false === @file_put_contents($path.'.tmp', json_encode($job->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), \LOCK_EX) || !@rename($path.'.tmp', $path)) {
            throw new \RuntimeException('Не удалось сохранить задание импорта: '.$path);
        }
    }

    /**
     * Порция задания: страницы по очереди, пока не истекло время (хотя бы одна). false — задание уже выполняется
     * другим запросом.
     */
    public function runBatch(ConfluenceJob $job, float $maxSeconds = 8.0): bool
    {
        if ($job->isFinished()) {
            return true;
        }
        $lock = @fopen($this->path($job->id).'.lock', 'c');
        if (false === $lock || !flock($lock, \LOCK_EX | \LOCK_NB)) {
            if (false !== $lock) {
                fclose($lock);
            }

            return false;
        }
        try {
            $fresh = $this->load($job->id) ?? $job; // состояние могло продвинуться в другом запросе
            foreach (get_object_vars($fresh) as $k => $v) {
                if (!(new \ReflectionProperty($job, $k))->isReadOnly()) {
                    $job->{$k} = $v;
                }
            }
            $started = microtime(true);
            try {
                $api = $this->api();
                if ($api->base() !== $job->base) {
                    throw new ConfluenceException('Адрес Confluence в настройках изменился после начала импорта — начните импорт заново.');
                }
                $actor = $this->users->find($job->actorId) ?? throw new ConfluenceException('Учётная запись, начавшая импорт, удалена.');
                while ($job->position < $job->total()) {
                    $this->page($job, $job->pages[$job->position], $api, $actor);
                    ++$job->position;
                    $this->em->flush();
                    if (microtime(true) - $started >= $maxSeconds) {
                        break;
                    }
                }
                if ($job->position >= $job->total()) {
                    $job->finished = true;
                    $job->finishedAt = new \DateTimeImmutable();
                    $this->auditLogger->info('Импорт из Confluence завершён', ['space' => $job->spaceKey] + $job->counters);
                }
            } catch (ConfluenceException $e) {
                $job->error = $e->getMessage();
            }
            $this->em->clear();
            $this->save($job);

            return true;
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /** Всё задание целиком (консоль). */
    public function runAll(ConfluenceJob $job, ?callable $progress = null): void
    {
        while (!$job->isFinished()) {
            $this->runBatch($job, 30.0);
            if (null !== $progress) {
                $progress($job);
            }
        }
    }

    /** @param array{id: string, parentId: ?string, title: string, version: int, position: int, url: ?string} $page */
    private function page(ConfluenceJob $job, array $page, ConfluenceApi $api, User $actor): void
    {
        $hash = sha1($job->base);
        $title = '' !== trim($page['title']) ? trim($page['title']) : 'Страница '.$page['id'];
        $parentPath = null !== $page['parentId'] ? ($job->paths[$page['parentId']] ?? '') : '';
        $path = ('' !== $parentPath ? $parentPath.' / ' : '').$title;
        $job->paths[$page['id']] = $path;
        try {
            $container = $this->container($job, $page);
            $mapping = $this->links->findOne($hash, ConfluencePage::KIND_PAGE, $page['id']);
            if (null === $mapping) {
                $mapping = new ConfluencePage($hash, ConfluencePage::KIND_PAGE, $page['id'], $job->spaceKey, $title);
                $this->em->persist($mapping);
            }
            $mapping->setTitle($title)->setParentId($page['parentId'])->setWebUrl($page['url']);
            $section = $container;
            $hasChildren = ($job->childCount[$page['id']] ?? 0) > 0;
            if ($hasChildren && !$job->flat) {
                $section = $this->subsection($job, $mapping, $container, $title, $actor, $path);
                $job->sections[$page['id']] = (int) $section->getId();
            }

            $document = $mapping->getDocument();
            // Документ уже импортировали, но в портале его удалили (или страница — только раздел): не возвращается.
            $gone = null === $document && null !== $mapping->getId() && $mapping->getVersion() > 0;
            $changed = !$gone && (null === $document || $page['version'] > $mapping->getVersion());
            $attachments = null;
            if ($gone) {
                $job->count('unchanged');
                $job->addLog('file', $path, 'unchanged', null !== $mapping->getSection() ? 'только раздел (или документ удалён в портале)' : 'документ удалён в портале — не восстанавливается');
            } elseif ($changed) {
                $data = $api->page($page['id']);
                $attachments = $job->attachments || str_contains($data['body'], 'ri:attachment') ? $api->attachments($page['id']) : [];
                $html = $this->html($job, $page, $data['body'], $attachments, $api);
                if (DocumentManager::isBlankPage($html)) {
                    if ($hasChildren && !$job->flat && null === $document) {
                        $job->count('skipped');
                        $job->addLog('file', $path, 'skipped', 'страница без текста — только раздел');
                        $mapping->setVersion($page['version']);
                        $html = null;
                    } else {
                        $html = '<p>Страница в Confluence пустая.</p>';
                    }
                }
                if (null !== $html) {
                    $html .= $this->sourceNote($job, $page);
                    if (null === $document) {
                        $document = (new Document($section))->setTitle(mb_substr($title, 0, 255))->setType(Document::TYPE_PAGE)
                            ->setDescription('Страница Confluence, пространство '.$job->spaceName.'.');
                        $this->documents->create($document, null, $html, 'Импорт из Confluence (версия '.$page['version'].')', $actor, $job->publish && !$this->documents->approvalRequired());
                        $mapping->setDocument($document);
                        $job->count('docsNew');
                        $job->addLog('file', $path, 'doc_new', 'версия '.$page['version']);
                    } else {
                        if ($document->getTitle() !== mb_substr($title, 0, 255)) {
                            $document->setTitle(mb_substr($title, 0, 255));
                            $this->documents->updateMetadata($document, null, $actor);
                        }
                        $version = $this->documents->addPageVersion($document, $html, 'Обновлено из Confluence (версия '.$page['version'].')', $actor);
                        $job->count(null !== $version ? 'versionsNew' : 'unchanged');
                        $job->addLog('file', $path, null !== $version ? 'version_new' : 'unchanged', null !== $version ? 'версия '.$page['version'].' в Confluence' : 'текст не изменился');
                    }
                    $mapping->setVersion($page['version']);
                }
            } else {
                $job->count('unchanged');
                $job->addLog('file', $path, 'unchanged', 'версия '.$page['version'].' уже импортирована');
            }
            if ($job->attachments) {
                $this->attachments($job, $page, $attachments ?? $api->attachments($page['id']), $section, $actor, $path, $hash, $api);
            }
        } catch (ConfluenceException $e) {
            if (str_contains($e->getMessage(), '401') || str_contains($e->getMessage(), 'нет связи')) {
                throw $e; // вход или связь — дальше не продолжить
            }
            $job->count('errors');
            $job->addLog('file', $path, 'error', $e->getMessage());
        } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
            $job->count('errors');
            $job->addLog('file', $path, 'error', $e->getMessage());
            $this->auditLogger->warning('Импорт из Confluence: страница не импортирована', ['page' => $page['id'], 'error' => $e->getMessage()]);
        }
    }

    /** Раздел, куда попадает страница: раздел родителя (если у него есть дочерние) или раздел импорта. */
    private function container(ConfluenceJob $job, array $page): Section
    {
        $id = null !== $page['parentId'] && isset($job->sections[$page['parentId']]) ? $job->sections[$page['parentId']] : $job->rootSectionId;
        $section = $this->sections->find($id) ?? $this->sections->find($job->rootSectionId);
        if (null === $section) {
            throw new ConfluenceException('Раздел импорта удалён — начните импорт заново.');
        }

        return $section;
    }

    private function subsection(ConfluenceJob $job, ConfluencePage $mapping, Section $container, string $title, User $actor, string $path): Section
    {
        $existing = $mapping->getSection();
        if (null !== $existing) {
            $job->count('sectionsExisting');

            return $existing;
        }
        $name = mb_substr($title, 0, 255);
        $section = $this->sections->findChildByName($container, $name);
        if (null !== $section) {
            $job->count('sectionsExisting');
            $job->addLog('dir', $path, 'section_exists', '');
        } else {
            try {
                $section = $this->sectionManager->create($name, $container, null, $actor);
            } catch (\DomainException $e) {
                // Глубже нельзя — дочерние страницы ложатся в раздел родителя.
                $job->addLog('dir', $path, 'skipped', $e->getMessage());

                return $container;
            }
            $job->count('sectionsNew');
            $job->addLog('dir', $path, 'section_new', '');
        }
        $mapping->setSection($section);

        return $section;
    }

    /**
     * @param array{id: string, parentId: ?string, title: string, version: int, position: int, url: ?string}                $page
     * @param list<array{id: string, title: string, mediaType: string, size: int, version: int, download: string}> $attachments
     */
    private function html(ConfluenceJob $job, array $page, string $body, array $attachments, ConfluenceApi $api): string
    {
        $byName = [];
        foreach ($attachments as $a) {
            $byName[$a['title']] = $a;
        }
        $budget = self::MAX_EMBED_TOTAL;
        $image = function (string $file) use (&$budget, $byName, $api): ?string {
            $a = $byName[$file] ?? null;
            if (null === $a || !str_starts_with($a['mediaType'], 'image/') || !\in_array($a['mediaType'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'], true)
                || 'image/svg+xml' === $a['mediaType'] || ($a['size'] > 0 && $a['size'] > min(self::MAX_EMBED_BYTES, $budget))) {
                return null;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'cfimg');
            try {
                $api->download($a['download'], (string) $tmp, min(self::MAX_EMBED_BYTES, $budget));
                $data = (string) file_get_contents((string) $tmp);
                $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($data) ?: '';
                if ('' === $data || !\in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                    return null;
                }
                $budget -= \strlen($data);

                return 'data:'.$mime.';base64,'.base64_encode($data);
            } catch (ConfluenceException) {
                return null;
            } finally {
                @unlink((string) $tmp);
            }
        };
        $pageLink = fn (string $space, string $title): string => $this->urls->generate('app_confluence_page', ['space' => $space, 'title' => $title]);
        $attachmentLink = fn (string $file): string => $this->urls->generate('app_confluence_attachment', ['page' => $page['id'], 'file' => $file]);

        return $this->converter->convert($body, $job->spaceKey, $image, $pageLink, $attachmentLink);
    }

    /** @param array{id: string, version: int, url: ?string} $page */
    private function sourceNote(ConfluenceJob $job, array $page): string
    {
        $link = null !== $page['url'] ? '<a href="'.htmlspecialchars($page['url'], \ENT_QUOTES).'">страница в Confluence</a>' : 'Confluence';

        return '<p><em>Источник: '.$link.' (пространство '.htmlspecialchars($job->spaceName, \ENT_QUOTES).', версия '.$page['version'].').</em></p>';
    }

    /**
     * @param array{id: string, title: string}                                                                     $page
     * @param list<array{id: string, title: string, mediaType: string, size: int, version: int, download: string}> $attachments
     */
    private function attachments(ConfluenceJob $job, array $page, array $attachments, Section $section, User $actor, string $path, string $hash, ConfluenceApi $api): void
    {
        $max = $this->storage->getUploadMaxBytes();
        foreach ($attachments as $a) {
            if (str_starts_with($a['mediaType'], 'image/')) {
                continue; // изображения встроены в страницы
            }
            $label = $path.' / '.$a['title'];
            $mapping = $this->links->findOne($hash, ConfluencePage::KIND_ATTACHMENT, $a['id']);
            $document = $mapping?->getDocument();
            if (null !== $document && $mapping->getVersion() >= $a['version']) {
                continue;
            }
            if ($a['size'] > $max) {
                $job->count('skipped');
                $job->addLog('file', $label, 'skipped', \sprintf('больше %s', FileStorage::humanSize($max)));
                continue;
            }
            $ext = strtolower(pathinfo($a['title'], \PATHINFO_EXTENSION));
            if (!\in_array($ext, $this->storage->getAllowedExtensions(), true)) {
                $job->count('skipped');
                $job->addLog('file', $label, 'skipped', 'тип файла не разрешён в портале');
                continue;
            }
            $tmp = (string) tempnam(sys_get_temp_dir(), 'cfatt');
            try {
                $api->download($a['download'], $tmp, $max);
                if (null === $document) {
                    $document = (new Document($section))->setTitle(mb_substr(pathinfo($a['title'], \PATHINFO_FILENAME) ?: $a['title'], 0, 255))->setType(Document::TYPE_FILE)
                        ->setDescription('Вложение страницы Confluence «'.$page['title'].'».');
                    $this->documents->createFromPath($document, $tmp, $a['title'], 'Импорт из Confluence', $actor, $job->publish && !$this->documents->approvalRequired(), true);
                    $mapping ??= new ConfluencePage($hash, ConfluencePage::KIND_ATTACHMENT, $a['id'], $job->spaceKey, $a['title']);
                    $this->em->persist($mapping);
                    $job->addLog('file', $label, 'doc_new', 'вложение');
                } else {
                    $this->documents->addFileVersionFromPath($document, $tmp, $a['title'], 'Обновлено из Confluence (версия '.$a['version'].')', $actor, true);
                    $job->addLog('file', $label, 'version_new', 'вложение, версия '.$a['version']);
                }
                $mapping->setTitle($a['title'])->setParentId($page['id'])->setVersion($a['version'])->setDocument($document);
                $job->count('attachments');
            } catch (ConfluenceException|\InvalidArgumentException|\RuntimeException $e) {
                $job->count('errors');
                $job->addLog('file', $label, 'error', $e->getMessage());
            } finally {
                @unlink($tmp);
            }
        }
    }

    private function path(string $id): string
    {
        return $this->dir.'/'.$id.'.json';
    }

    private function cleanup(): void
    {
        foreach (glob($this->dir.'/confluence-*.json') ?: [] as $file) {
            if (filemtime($file) < time() - 14 * 86400) {
                @unlink($file);
                @unlink($file.'.lock');
            }
        }
    }
}
