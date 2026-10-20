<?php

declare(strict_types=1);

namespace App\Service\Price;

use App\Entity\PriceList;
use App\Entity\PriceRevision;
use App\Entity\User;
use App\Repository\PriceItemRepository;
use App\Repository\PriceRevisionRepository;
use App\Repository\UserRepository;
use App\Service\Notification\TelegramNotifier;
use App\Service\PortalUrl;
use App\Service\Xlsx\XlsxWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Прайс-листы: загрузка файла Excel (редакция-черновик), сопоставление столбцов, разбор на позиции,
 * публикация (маркетолог, администратор) или модерация (помощник маркетолога), сравнение с прошлой
 * редакцией, выгрузка. Файлы — в STORAGE_DIR/prices/<прайс-лист>/<токен>.<расширение>; .xls и .ods
 * при загрузке переводятся LibreOffice в рабочую копию .xlsx рядом с оригиналом.
 */
final class PriceListService
{
    public const MAX_FILE_MB = 50;

    /** Черновик, у которого так и не сопоставили столбцы, удаляется через столько дней. */
    public const DRAFT_TTL_DAYS = 7;

    private const INSERT_CHUNK = 100;

    /** @var array<int, list<array{name: string, hidden: bool, rows: array<int, array<int, string>>}>> */
    private array $sheetCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PriceRevisionRepository $revisions,
        private readonly PriceItemRepository $items,
        private readonly PriceSheetReader $reader,
        private readonly PriceMapper $mapper,
        private readonly PriceAccess $access,
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $auditLogger,
        private readonly string $storageDir,
        private readonly string $mailFrom,
        private readonly string $appName,
        private readonly PortalUrl $portalUrl,
        private readonly ?TelegramNotifier $telegram = null,
    ) {
    }

    public function create(User $by, string $title, ?string $description, string $currency, bool $public, bool $originalDownload = false): PriceList
    {
        if (!$this->access->canUpload($by)) {
            throw new \DomainException('Прайс-листы ведут маркетологи и их помощники.');
        }
        $list = new PriceList(self::title($title), $by);
        $list->setDescription($description)->setCurrency($currency)->setPublic($public)->setOriginalDownload($originalDownload);
        $list->setPosition(1 + (int) $this->em->createQuery('SELECT MAX(l.position) FROM '.PriceList::class.' l')->getSingleScalarResult());
        $this->em->persist($list);
        $this->em->flush();
        $this->auditLogger->info('Создан прайс-лист', ['price_list' => $list->getId(), 'title' => $list->getTitle(), 'by' => $by->getUsername()]);

        return $list;
    }

    public function update(PriceList $list, User $by, string $title, ?string $description, string $currency, bool $public, ?int $position = null, ?bool $originalDownload = null): void
    {
        $this->assertManage($by);
        $list->setTitle(self::title($title))->setDescription($description)->setCurrency($currency)->setPublic($public);
        if (null !== $originalDownload) {
            $list->setOriginalDownload($originalDownload);
        }
        if (null !== $position) {
            $list->setPosition(max(0, $position));
        }
        $this->em->flush();
        $this->auditLogger->info('Изменены настройки прайс-листа', ['price_list' => $list->getId(), 'by' => $by->getUsername()]);
    }

    public function setArchived(PriceList $list, User $by, bool $archived): void
    {
        $this->assertManage($by);
        $list->setArchived($archived);
        $this->em->flush();
        $this->auditLogger->info($archived ? 'Прайс-лист перенесён в архив' : 'Прайс-лист возвращён из архива', ['price_list' => $list->getId(), 'by' => $by->getUsername()]);
    }

    /**
     * Убрать прайс-лист, у которого не осталось ни одной редакции (не прочитался первый файл, отменили первую
     * загрузку), — может и помощник, создавший его.
     */
    public function discardEmpty(PriceList $list, User $by): bool
    {
        if (null !== $list->getCurrentRevision() || [] !== $this->revisions->findBy(['priceList' => $list], null, 1)
            || ($list->getCreatedBy()?->getId() !== $by->getId() && !$this->access->canManage($by))) {
            return false;
        }
        $this->em->remove($list);
        $this->em->flush();
        @rmdir($this->dir($list));

        return true;
    }

    public function delete(PriceList $list, User $by): void
    {
        $this->assertManage($by);
        $id = $list->getId();
        $title = $list->getTitle();
        $list->setCurrentRevision(null);
        $this->em->flush();
        foreach ($this->revisions->findBy(['priceList' => $list]) as $revision) {
            $this->removeFiles($revision);
        }
        $this->em->getConnection()->executeStatement('DELETE FROM price_item WHERE revision_id IN (SELECT id FROM price_revision WHERE price_list_id = ?)', [$id]);
        $this->em->getConnection()->executeStatement('DELETE FROM price_revision WHERE price_list_id = ?', [$id]);
        $this->em->remove($list);
        $this->em->flush();
        @rmdir($this->dir($list));
        $this->auditLogger->info('Прайс-лист удалён', ['price_list' => $id, 'title' => $title, 'by' => $by->getUsername()]);
    }

    /**
     * Новый файл прайс-листа — редакция-черновик (дальше — сопоставление столбцов).
     *
     * @throws \InvalidArgumentException файл не подходит
     */
    public function upload(PriceList $list, UploadedFile $file, User $by): PriceRevision
    {
        if (!$this->access->canUpload($by)) {
            throw new \DomainException('Загружать прайс-листы могут маркетологи и их помощники.');
        }
        if (!$file->isValid()) {
            throw new \InvalidArgumentException(\UPLOAD_ERR_INI_SIZE === $file->getError() || \UPLOAD_ERR_FORM_SIZE === $file->getError()
                ? 'Файл больше, чем разрешено на сервере.' : 'Файл не загрузился — повторите.');
        }

        return $this->store($list, $file->getPathname(), $file->getClientOriginalName(), $by);
    }

    /** То же для файла на диске (команды, тесты). */
    public function store(PriceList $list, string $path, string $originalName, User $by): PriceRevision
    {
        $ext = PriceSheetReader::detect($path, $originalName);
        if (null === $ext) {
            throw new \InvalidArgumentException('Прайс-лист загружается из файла Excel (.xlsx, .xls), OpenDocument (.ods) или CSV.');
        }
        if (strtolower(pathinfo($originalName, \PATHINFO_EXTENSION)) !== $ext && !\in_array(strtolower(pathinfo($originalName, \PATHINFO_EXTENSION)), PriceSheetReader::EXTENSIONS, true)) {
            $originalName .= '.'.$ext; // имя без расширения — формат определён по содержимому
        }
        $size = (int) filesize($path);
        if ($size <= 0) {
            throw new \InvalidArgumentException('Файл пустой.');
        }
        if ($size > self::MAX_FILE_MB * 1024 * 1024) {
            throw new \InvalidArgumentException(\sprintf('Файл больше %d МБ.', self::MAX_FILE_MB));
        }
        $dir = $this->dir($list);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог для прайс-листа в хранилище.');
        }
        $token = bin2hex(random_bytes(12));
        $relative = 'prices/'.$list->getId().'/'.$token.'.'.$ext;
        $target = rtrim($this->storageDir, '/').'/'.$relative;
        if (!@copy($path, $target)) {
            throw new \RuntimeException('Не удалось сохранить файл в хранилище.');
        }
        $revision = new PriceRevision($list, $this->revisions->nextNumber($list), $relative, $originalName, $size, (string) hash_file('sha256', $target), $by);
        try {
            if (\in_array($ext, ['xls', 'ods'], true)) {
                // Рабочая копия .xlsx — чтобы не запускать LibreOffice при каждом просмотре сопоставления.
                $sheets = $this->reader->read($target, $ext);
                $this->writeWorkCopy($sheets, $this->workPath($revision));
                $this->sheetCache = [];
            } else {
                $sheets = $this->reader->read($target, $ext);
            }
        } catch (\RuntimeException $e) {
            @unlink($target);
            @unlink($this->workPath($revision));
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
        if ([] === array_filter($sheets, static fn (array $s): bool => [] !== $s['rows'])) {
            @unlink($target);
            @unlink($this->workPath($revision));
            throw new \InvalidArgumentException('В файле нет данных.');
        }
        $this->em->persist($revision);
        $this->em->flush();
        $this->auditLogger->info('Загружен файл прайс-листа', ['price_list' => $list->getId(), 'revision' => $revision->getNumber(), 'file' => $originalName, 'by' => $by->getUsername()]);

        return $revision;
    }

    /** @return list<array{name: string, hidden: bool, rows: array<int, array<int, string>>}> */
    public function sheets(PriceRevision $revision): array
    {
        $id = (int) $revision->getId();
        if (!isset($this->sheetCache[$id])) {
            $work = $this->workPath($revision);
            $this->sheetCache = [$id => is_file($work) && $work !== $this->path($revision)
                ? $this->reader->read($work, 'xlsx')
                : $this->reader->read($this->path($revision), $revision->getExtension())];
        }

        return $this->sheetCache[$id];
    }

    /**
     * Страница сопоставления: листы, выбранный лист, сопоставление (из формы, из прошлой загрузки или
     * определённое по содержимому), первые строки листа и результат разбора — число позиций, групп,
     * предупреждения, первые позиции и отличия от действующей редакции.
     *
     * @param array<int|string, string>|null $roles
     *
     * @return array<string, mixed>
     */
    public function preview(PriceRevision $revision, ?string $sheetName, ?int $header, ?array $roles): array
    {
        $sheets = $this->sheets($revision);
        $sheet = self::pickSheet($sheets, $sheetName ?? $revision->getSheetName());
        $rows = $sheet['rows'];
        $previous = $revision->getMapping() ?? $revision->getPriceList()->getLastMapping();
        $mapping = $this->mapper->guess($rows, $previous, $header);
        if (null !== $roles) {
            foreach ($mapping['columns'] as $c => $col) {
                $role = (string) ($roles[$c] ?? $roles[(string) $c] ?? 'skip');
                $mapping['columns'][$c]['role'] = isset(PriceMapper::ROLES[$role]) ? $role : 'skip';
            }
        }
        $result = null;
        $error = null;
        $diff = null;
        try {
            $result = $this->mapper->apply($rows, $mapping);
            $current = $revision->getPriceList()->getCurrentRevision();
            if (null !== $current && $current->getId() !== $revision->getId()) {
                $diff = self::compare($this->items->rows($current), self::itemRows($result['items']));
            }
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
        $preview = [];
        $count = 0;
        foreach ($rows as $r => $cells) {
            if ($r < max(1, $mapping['header'])) {
                continue;
            }
            $preview[$r] = $cells;
            if (++$count >= 30) {
                break;
            }
        }

        return [
            'sheets' => array_map(static fn (array $s): array => ['name' => $s['name'], 'hidden' => $s['hidden'], 'rows' => \count($s['rows'])], $sheets),
            'sheet' => $sheet['name'],
            'mapping' => $mapping,
            'rows' => $preview,
            'total_rows' => [] === $rows ? 0 : max(array_keys($rows)),
            'result' => null === $result ? null : [
                'count' => \count($result['items']),
                'groups' => $result['groups'],
                'warnings' => $result['warnings'],
                'priceColumns' => $result['priceColumns'],
                'extraColumns' => $result['extraColumns'],
                'items' => \array_slice($result['items'], 0, 15),
            ],
            'error' => $error,
            'diff' => null === $diff ? null : $diff['stats'],
        ];
    }

    /**
     * Разбор листа по сопоставлению и сохранение позиций; маркетолог и администратор публикуют сразу,
     * помощник маркетолога — отправляет на модерацию.
     *
     * @param array{header: int, columns: array<int, array{role: string, label: string}>} $mapping
     *
     * @throws \InvalidArgumentException сопоставление не даёт позиций
     */
    public function import(PriceRevision $revision, string $sheetName, array $mapping, ?\DateTimeImmutable $effectiveFrom, ?string $note, User $by): void
    {
        if (!$this->canEdit($by, $revision)) {
            throw new \DomainException('Эту редакцию прайс-листа менять нельзя.');
        }
        $sheet = self::pickSheet($this->sheets($revision), $sheetName);
        $result = $this->mapper->apply($sheet['rows'], $mapping);
        $list = $revision->getPriceList();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $connection->executeStatement('DELETE FROM price_item WHERE revision_id = ?', [$revision->getId()]);
            $this->insertItems((int) $revision->getId(), $result['items']);
            $revision->setImported(['sheet' => $sheet['name'], 'header' => (int) $mapping['header'], 'columns' => $mapping['columns']], $sheet['name'], $result['priceColumns'], $result['extraColumns'], \count($result['items']));
            $revision->setEffectiveFrom($effectiveFrom)->setNote($note);
            $current = $list->getCurrentRevision();
            $revision->setStats(null !== $current && $current->getId() !== $revision->getId()
                ? self::compare($this->items->rows($current), self::itemRows($result['items']))['stats'] + ['base' => $current->getNumber()]
                : null);
            $list->setLastMapping(['header' => (int) $mapping['header'], 'columns' => $mapping['columns']]);
            $revision->clearDecision();
            if ($this->access->needsModeration($by)) {
                $revision->setStatus(PriceRevision::STATUS_PENDING);
            } else {
                $revision->decide($by, null);
                $this->publishRevision($revision);
            }
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        $this->auditLogger->info($revision->isPending() ? 'Прайс-лист отправлен на модерацию' : 'Опубликована редакция прайс-листа', [
            'price_list' => $list->getId(), 'revision' => $revision->getNumber(), 'items' => $revision->getItemCount(), 'by' => $by->getUsername(),
        ]);
        if ($revision->isPending()) {
            $this->notify($revision, 'submitted', $by);
        }
    }

    public function approve(PriceRevision $revision, User $by, ?string $note): void
    {
        $this->assertManage($by);
        if (!$revision->isPending()) {
            throw new \DomainException('Редакция не ждёт решения.');
        }
        $list = $revision->getPriceList();
        $current = $list->getCurrentRevision();
        $revision->setStats(null !== $current && $current->getId() !== $revision->getId()
            ? self::compare($this->items->rows($current), $this->items->rows($revision))['stats'] + ['base' => $current->getNumber()]
            : null);
        $revision->decide($by, $note);
        $this->publishRevision($revision);
        $this->em->flush();
        $this->auditLogger->info('Редакция прайс-листа одобрена', ['price_list' => $list->getId(), 'revision' => $revision->getNumber(), 'by' => $by->getUsername()]);
        $this->notify($revision, 'approved', $by);
    }

    public function reject(PriceRevision $revision, User $by, ?string $note): void
    {
        $this->assertManage($by);
        if (!$revision->isPending()) {
            throw new \DomainException('Редакция не ждёт решения.');
        }
        if (null === $note || '' === trim($note)) {
            throw new \InvalidArgumentException('Напишите, что исправить, — комментарий увидит загрузивший.');
        }
        $revision->decide($by, $note)->setStatus(PriceRevision::STATUS_REJECTED);
        $this->em->flush();
        $this->auditLogger->info('Редакция прайс-листа отклонена', ['price_list' => $revision->getPriceList()->getId(), 'revision' => $revision->getNumber(), 'by' => $by->getUsername()]);
        $this->notify($revision, 'rejected', $by);
    }

    /** Вернуть действующей одну из прежних опубликованных редакций. */
    public function makeCurrent(PriceRevision $revision, User $by): void
    {
        $this->assertManage($by);
        if (!$revision->isPublished()) {
            throw new \DomainException('Действующей может стать только опубликованная редакция.');
        }
        $revision->getPriceList()->setCurrentRevision($revision);
        $this->em->flush();
        $this->auditLogger->info('Действующей сделана прежняя редакция прайс-листа', ['price_list' => $revision->getPriceList()->getId(), 'revision' => $revision->getNumber(), 'by' => $by->getUsername()]);
    }

    public function deleteRevision(PriceRevision $revision, User $by): void
    {
        if ($revision->isCurrent()) {
            throw new \DomainException('Действующую редакцию удалить нельзя — сначала опубликуйте другую.');
        }
        $own = $revision->getUploadedBy()?->getId() === $by->getId() && !$revision->isPublished();
        if (!$own && !$this->access->canManage($by)) {
            throw new \DomainException('Удалить эту редакцию может маркетолог или администратор.');
        }
        $this->removeFiles($revision);
        $this->em->getConnection()->executeStatement('DELETE FROM price_item WHERE revision_id = ?', [$revision->getId()]);
        $this->em->remove($revision);
        $this->em->flush();
        $this->auditLogger->info('Удалена редакция прайс-листа', ['price_list' => $revision->getPriceList()->getId(), 'revision' => $revision->getNumber(), 'by' => $by->getUsername()]);
    }

    /** Черновик или отклонённую редакцию правят загрузивший и те, кто ведёт прайс-листы; на модерации — только последние. */
    public function canEdit(User $user, PriceRevision $revision): bool
    {
        if ($revision->isPublished()) {
            return false;
        }
        if ($this->access->canManage($user)) {
            return true;
        }

        return !$revision->isPending() && $this->access->canUpload($user) && $revision->getUploadedBy()?->getId() === $user->getId();
    }

    /**
     * Отличия редакции от предыдущей опубликованной (для опубликованной) или от действующей (для остальных).
     *
     * @return array{base: ?PriceRevision, added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>, stats: array<string, int>}
     */
    public function diff(PriceRevision $revision): array
    {
        $base = $revision->isPublished() ? $this->revisions->previousPublished($revision) : $revision->getPriceList()->getCurrentRevision();
        if (null !== $base && $base->getId() === $revision->getId()) {
            $base = null;
        }

        return ['base' => $base] + self::compare(null !== $base ? $this->items->rows($base) : [], $this->items->rows($revision));
    }

    /**
     * Сравнение позиций: ключ — артикул, без артикула — наименование.
     *
     * @param list<array<string, mixed>> $old
     * @param list<array<string, mixed>> $new
     *
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>, stats: array<string, int>}
     */
    public static function compare(array $old, array $new): array
    {
        $index = [];
        foreach ($old as $row) {
            $index[self::key($row)] = $row;
        }
        $added = [];
        $changed = [];
        $up = 0;
        $down = 0;
        $seen = [];
        foreach ($new as $row) {
            $key = self::key($row);
            $seen[$key] = true;
            if (!isset($index[$key])) {
                $added[] = $row;
                continue;
            }
            $before = $index[$key];
            $oldPrices = array_values((array) $before['prices']);
            $newPrices = array_values((array) $row['prices']);
            if ($oldPrices === $newPrices) {
                continue;
            }
            $o = null !== $before['price'] ? (float) $before['price'] : null;
            $n = null !== $row['price'] ? (float) $row['price'] : null;
            $delta = null !== $o && null !== $n && $o > 0 ? round(($n - $o) / $o * 100, 1) : null;
            if (null !== $o && null !== $n) {
                $n > $o ? ++$up : ($n < $o ? ++$down : null);
            }
            $changed[] = $row + ['old_price' => $before['price'], 'old_prices' => $oldPrices, 'delta' => $delta];
        }
        $removed = [];
        foreach ($index as $key => $row) {
            if (!isset($seen[$key])) {
                $removed[] = $row;
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'stats' => ['added' => \count($added), 'removed' => \count($removed), 'changed' => \count($changed), 'up' => $up, 'down' => $down],
        ];
    }

    /** Выгрузка позиций редакции в Excel (временный файл; удаляется после отправки). */
    public function export(PriceRevision $revision): string
    {
        $list = $revision->getPriceList();
        $writer = new XlsxWriter();
        $sheet = $writer->addSheet(mb_substr($list->getTitle(), 0, 31));
        $headers = ['Группа', 'Артикул', 'Наименование', 'Ед.'];
        foreach ($revision->getPriceColumns() as $label) {
            $headers[] = $label;
        }
        foreach ($revision->getExtraColumns() as $label) {
            $headers[] = $label;
        }
        foreach ($headers as $i => $label) {
            $sheet->set(1, $i + 1, $label, XlsxWriter::STYLE_HEADER)->width($i + 1, match ($i) { 0 => 28, 1 => 16, 2 => 60, 3 => 8, default => 14 });
        }
        $sheet->freezeTopRow();
        $line = 1;
        $prices = \count($revision->getPriceColumns());
        foreach ($this->items->rows($revision) as $row) {
            ++$line;
            $sheet->set($line, 1, (string) $row['groupName'])->set($line, 2, (string) $row['sku'])->set($line, 3, $row['name'], XlsxWriter::STYLE_WRAP)->set($line, 4, (string) $row['unit']);
            foreach (array_values((array) $row['prices']) as $i => $value) {
                $sheet->set($line, 5 + $i, is_numeric($value) ? $value + 0 : (string) $value);
            }
            foreach (array_values((array) $row['extra']) as $i => $value) {
                $sheet->set($line, 5 + $prices + $i, (string) $value);
            }
        }
        $sheet->autoFilter('A1:'.XlsxWriter::column(\count($headers)).max(1, $line));
        $base = (string) tempnam(sys_get_temp_dir(), 'price-export-');
        @unlink($base);
        $path = $base.'.xlsx';
        $writer->save($path);

        return $path;
    }

    public function path(PriceRevision $revision): string
    {
        return rtrim($this->storageDir, '/').'/'.$revision->getFilePath();
    }

    /** Удалить черновики, у которых за неделю так и не сопоставили столбцы. */
    public function cleanupDrafts(): int
    {
        $n = 0;
        foreach ($this->revisions->findStaleDrafts(new \DateTimeImmutable('-'.self::DRAFT_TTL_DAYS.' days')) as $revision) {
            $this->removeFiles($revision);
            $this->em->getConnection()->executeStatement('DELETE FROM price_item WHERE revision_id = ?', [$revision->getId()]);
            $this->em->remove($revision);
            ++$n;
        }
        $this->em->flush();

        return $n;
    }

    public function fileName(PriceRevision $revision, string $ext): string
    {
        $title = trim((string) preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', $revision->getPriceList()->getTitle()));
        $date = ($revision->getEffectiveFrom() ?? $revision->getPublishedAt() ?? $revision->getUploadedAt())->format('Y-m-d');

        return \sprintf('%s %s.%s', '' !== $title ? $title : 'Прайс-лист', $date, $ext);
    }

    private function publishRevision(PriceRevision $revision): void
    {
        $revision->setStatus(PriceRevision::STATUS_PUBLISHED);
        $revision->getPriceList()->setCurrentRevision($revision);
    }

    /**
     * @param list<array{sku: ?string, name: string, group: ?string, unit: ?string, prices: list<string>, price: ?string, extra: list<string>}> $items
     */
    private function insertItems(int $revisionId, array $items): void
    {
        $connection = $this->em->getConnection();
        foreach (array_chunk($items, self::INSERT_CHUNK) as $chunkIndex => $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $i => $item) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $search = PriceItemRepository::normalize(implode(' ', array_filter([$item['sku'], $item['name'], $item['group'], implode(' ', $item['extra'])])));
                array_push($params, $revisionId, $chunkIndex * self::INSERT_CHUNK + $i + 1, $item['sku'], $item['name'], $item['group'], $item['unit'], $item['price'],
                    json_encode($item['prices'], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), json_encode($item['extra'], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), mb_substr($search, 0, 1500));
            }
            $connection->executeStatement('INSERT INTO price_item (revision_id, position, sku, name, group_name, unit, price, prices, extra, search_text) VALUES '.implode(', ', $values), $params);
        }
    }

    /**
     * @param list<array{sku: ?string, name: string, group: ?string, unit: ?string, prices: list<string>, price: ?string, extra: list<string>}> $items
     *
     * @return list<array<string, mixed>>
     */
    private static function itemRows(array $items): array
    {
        return array_map(static fn (array $i): array => ['sku' => $i['sku'], 'name' => $i['name'], 'groupName' => $i['group'], 'unit' => $i['unit'], 'price' => $i['price'], 'prices' => $i['prices'], 'extra' => $i['extra']], $items);
    }

    /** @param array<string, mixed> $row */
    private static function key(array $row): string
    {
        $sku = trim((string) ($row['sku'] ?? ''));

        return '' !== $sku ? 'sku:'.mb_strtolower($sku) : 'name:'.PriceItemRepository::normalize((string) $row['name']);
    }

    /**
     * @param list<array{name: string, hidden: bool, rows: array<int, array<int, string>>}> $sheets
     *
     * @return array{name: string, hidden: bool, rows: array<int, array<int, string>>}
     */
    private static function pickSheet(array $sheets, ?string $name): array
    {
        foreach ($sheets as $sheet) {
            if (null !== $name && $sheet['name'] === $name) {
                return $sheet;
            }
        }
        // По умолчанию — первый видимый лист с данными.
        foreach ($sheets as $sheet) {
            if (!$sheet['hidden'] && [] !== $sheet['rows']) {
                return $sheet;
            }
        }

        return $sheets[0];
    }

    /** @param list<array{name: string, hidden: bool, rows: array<int, array<int, string>>}> $sheets */
    private function writeWorkCopy(array $sheets, string $path): void
    {
        $writer = new XlsxWriter();
        foreach ($sheets as $s) {
            $sheet = $writer->addSheet($s['name']);
            if ($s['hidden']) {
                $sheet->hide();
            }
            foreach ($s['rows'] as $r => $cells) {
                foreach ($cells as $c => $value) {
                    $sheet->set($r, $c, $value);
                }
            }
        }
        $writer->save($path);
    }

    private function workPath(PriceRevision $revision): string
    {
        $path = $this->path($revision);
        if (!\in_array($revision->getExtension(), ['xls', 'ods'], true)) {
            return $path;
        }

        return (string) preg_replace('/\.[a-z]+$/', '.work.xlsx', $path);
    }

    private function removeFiles(PriceRevision $revision): void
    {
        @unlink($this->path($revision));
        $work = $this->workPath($revision);
        if ($work !== $this->path($revision)) {
            @unlink($work);
        }
    }

    private function dir(PriceList $list): string
    {
        return rtrim($this->storageDir, '/').'/prices/'.$list->getId();
    }

    private function assertManage(User $by): void
    {
        if (!$this->access->canManage($by)) {
            throw new \DomainException('Это могут маркетологи и администраторы.');
        }
    }

    private static function title(string $title): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ('' === $title) {
            throw new \InvalidArgumentException('Укажите название прайс-листа.');
        }

        return mb_substr($title, 0, 255);
    }

    /** Помощник отправил редакцию — маркетологам; решение — загрузившему. */
    private function notify(PriceRevision $revision, string $kind, User $by): void
    {
        $list = $revision->getPriceList();
        if ('submitted' === $kind) {
            $recipients = $this->users->findActiveMarketers() ?: $this->users->findActiveAdmins();
        } else {
            $recipients = array_filter([$revision->getUploadedBy()]);
        }
        $subject = match ($kind) {
            'submitted' => \sprintf('%s: на модерации прайс-лист «%s»', $this->appName, $list->getTitle()),
            'approved' => \sprintf('%s: прайс-лист «%s» опубликован', $this->appName, $list->getTitle()),
            default => \sprintf('%s: прайс-лист «%s» возвращён на доработку', $this->appName, $list->getTitle()),
        };
        $url = 'approved' === $kind
            ? $this->portalUrl->absolute('app_price_show', ['id' => $list->getId()])
            : $this->portalUrl->absolute('app_price_revision', ['id' => $list->getId(), 'number' => $revision->getNumber()]);
        foreach ($recipients as $recipient) {
            if (!$recipient instanceof User || !$recipient->isActive() || $recipient->getId() === $by->getId()) {
                continue;
            }
            if (null !== $recipient->getEmail() && '' !== $recipient->getEmail()) {
                try {
                    $this->mailer->send((new TemplatedEmail())
                        ->from(Address::create($this->mailFrom))
                        ->to(new Address($recipient->getEmail(), $recipient->getDisplayName()))
                        ->subject($subject)
                        ->htmlTemplate('emails/price.html.twig')
                        ->textTemplate('emails/price.txt.twig')
                        ->context(['user' => $recipient, 'revision' => $revision, 'list' => $list, 'kind' => $kind, 'by' => $by, 'url' => $url, 'app_name' => $this->appName]));
                } catch (\Throwable $e) {
                    $this->auditLogger->warning('Не удалось отправить письмо о прайс-листе', ['price_list' => $list->getId(), 'error' => $e->getMessage()]);
                }
            }
            if (null !== $this->telegram) {
                try {
                    $extra = 'rejected' === $kind && null !== $revision->getDecisionNote() ? "\n".htmlspecialchars($revision->getDecisionNote(), \ENT_QUOTES)."\n" : "\n";
                    $this->telegram->notify($recipient, \sprintf('<b>%s</b>%s<a href="%s">%s</a>', htmlspecialchars($subject, \ENT_QUOTES), $extra, htmlspecialchars($url, \ENT_QUOTES), htmlspecialchars($url, \ENT_QUOTES)));
                } catch (\Throwable $e) {
                    $this->auditLogger->warning('Не удалось отправить сообщение Telegram о прайс-листе', ['price_list' => $list->getId(), 'error' => $e->getMessage()]);
                }
            }
        }
    }
}
