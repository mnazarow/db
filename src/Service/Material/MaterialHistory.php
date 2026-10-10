<?php

declare(strict_types=1);

namespace App\Service\Material;

use App\Entity\MaterialRow;
use App\Entity\MaterialRowChange;
use App\Entity\User;
use App\Repository\MaterialRowChangeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Журнал изменений таблиц материалов: кто, когда и что поменял в строке. Запись сохраняется вместе
 * с самим изменением (при flush вызывающего кода) — отменённая правка не оставляет следа в журнале.
 */
final class MaterialHistory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialRowChangeRepository $changes,
    ) {
    }

    public function record(MaterialRow $row, string $action, ?User $by, ?string $field = null, ?string $fieldLabel = null, ?string $old = null, ?string $new = null): MaterialRowChange
    {
        $change = new MaterialRowChange($row, $action, $by, $field, $fieldLabel, $old, $new);
        if (MaterialRowChange::DELETE === $action) {
            // Строка удаляется в том же сохранении: запись остаётся в журнале раздела без ссылки на неё.
            $change->detachRow();
        }
        $this->em->persist($change);

        return $change;
    }

    /**
     * Строка удаляется: записи журнала в памяти (уже сохранённые и ждущие сохранения) больше не ссылаются
     * на неё. В базе ссылку снимает внешний ключ (ON DELETE SET NULL), а без этого Doctrine при следующем
     * сохранении нашёл бы ссылку на удалённую строку и остановился с ошибкой.
     */
    public function forgetRow(MaterialRow $row): void
    {
        $uow = $this->em->getUnitOfWork();
        $managed = $uow->getIdentityMap()[MaterialRowChange::class] ?? [];
        foreach (array_merge(array_values($managed), array_values($uow->getScheduledEntityInsertions())) as $change) {
            if ($change instanceof MaterialRowChange && $change->getRow() === $row) {
                $change->detachRow();
            }
        }
    }

    /** @return list<array<string, mixed>> история строки для диалога «История изменений» */
    public function forRow(MaterialRow $row, int $limit = 200): array
    {
        return array_map(self::serialize(...), $this->changes->findForRow($row, $limit));
    }

    /** @return array<string, mixed> */
    public static function serialize(MaterialRowChange $change): array
    {
        return [
            'id' => $change->getId(),
            'action' => $change->getAction(),
            'actionLabel' => $change->getActionLabel(),
            'field' => $change->getField(),
            'fieldLabel' => $change->getFieldLabel(),
            'old' => $change->getOldValue(),
            'new' => $change->getNewValue(),
            'user' => $change->getUserName() ?? 'система',
            'row' => $change->getRow()?->getId(),
            'rowTitle' => $change->getRowTitle(),
            'at' => $change->getCreatedAt()->format('d.m.Y H:i'),
            'atIso' => $change->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
