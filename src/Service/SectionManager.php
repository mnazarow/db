<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Section;
use App\Entity\SectionModerator;
use App\Entity\User;
use App\Repository\SectionModeratorRepository;
use App\Repository\SectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Операции над деревом разделов: создание, изменение, перемещение, удаление, назначение модераторов.
 */
final class SectionManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SectionRepository $sections,
        private readonly SectionModeratorRepository $moderators,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    /**
     * Создаёт раздел (родитель null — верхний уровень).
     *
     * @throws \DomainException при превышении глубины вложенности
     */
    public function create(string $name, ?Section $parent, ?string $description = null, ?User $actor = null): Section
    {
        if (null !== $parent && $parent->getDepth() + 1 >= Section::MAX_DEPTH) {
            throw new \DomainException(\sprintf('Достигнута максимальная глубина вложенности (%d уровней).', Section::MAX_DEPTH));
        }
        $section = (new Section())
            ->setName($name)
            ->setParent($parent)
            ->setDescription($description)
            ->setCreatedBy($actor)
            ->setPosition($this->sections->nextPosition($parent));
        $section->setSlug($this->uniqueSlug($section->getName()));
        $this->em->persist($section);
        $this->em->flush();
        $section->refreshPath();
        $this->em->flush();

        $this->auditLogger->info('Создан раздел', ['section' => $section->getFullName(), 'by' => $actor?->getUsername()]);

        return $section;
    }

    /**
     * Сохраняет изменения раздела; при смене родителя пересчитывает пути всего поддерева.
     *
     * @throws \DomainException если раздел переносится внутрь самого себя
     */
    public function update(Section $section, ?Section $newParent, ?User $actor = null): void
    {
        $oldParentId = $section->getParent()?->getId();
        if ($newParent?->getId() !== $oldParentId) {
            $this->assertCanMove($section, $newParent);
            $section->setParent($newParent);
            $section->setPosition($this->sections->nextPosition($newParent));
        }
        if ('' === $section->getSlug() || !$this->slugMatches($section)) {
            $section->setSlug($this->uniqueSlug($section->getName(), $section->getId()));
        }
        $this->em->flush();
        if ($newParent?->getId() !== $oldParentId) {
            $this->refreshSubtreePaths($section);
        }
        $this->auditLogger->info('Изменён раздел', ['section' => $section->getFullName(), 'by' => $actor?->getUsername()]);
    }

    /** Проверяет, что раздел можно перенести под нового родителя (не в себя и не в своих потомков). */
    public function assertCanMove(Section $section, ?Section $newParent): void
    {
        if (null === $newParent) {
            return;
        }
        if ($newParent->getId() === $section->getId() || $newParent->isDescendantOf($section)) {
            throw new \DomainException('Нельзя переместить раздел внутрь самого себя или своего подраздела.');
        }
        $subtreeDepth = 0;
        foreach ($this->sections->findSubtree($section) as $s) {
            $subtreeDepth = max($subtreeDepth, $s->getDepth() - $section->getDepth());
        }
        if ($newParent->getDepth() + 1 + $subtreeDepth >= Section::MAX_DEPTH) {
            throw new \DomainException(\sprintf('После перемещения глубина вложенности превысит %d уровней.', Section::MAX_DEPTH));
        }
    }

    /** Пересчитывает материализованные пути раздела и всех его потомков. */
    public function refreshSubtreePaths(Section $root): void
    {
        $subtree = $this->sections->findSubtree($root);
        $root->refreshPath();
        // Потомки отсортированы по глубине — родители обрабатываются раньше детей.
        $byId = [$root->getId() => $root];
        foreach ($subtree as $s) {
            if ($s->getId() === $root->getId()) {
                continue;
            }
            $s->refreshPath();
            $byId[$s->getId()] = $s;
        }
        $this->em->flush();
    }

    /**
     * Удаляет раздел. Разрешено только для пустых разделов (без подразделов и документов).
     *
     * @throws \DomainException
     */
    public function delete(Section $section, ?User $actor = null): void
    {
        if ($section->hasChildren()) {
            throw new \DomainException('Сначала удалите или перенесите подразделы.');
        }
        if (!$section->getDocuments()->isEmpty()) {
            throw new \DomainException('В разделе есть документы. Перенесите или удалите их, затем удалите раздел.');
        }
        $name = $section->getFullName();
        $this->em->remove($section);
        $this->em->flush();
        $this->auditLogger->info('Удалён раздел', ['section' => $name, 'by' => $actor?->getUsername()]);
    }

    /** Меняет порядок раздела среди соседей: направление -1 (вверх) или +1 (вниз). */
    public function move(Section $section, int $direction): void
    {
        $siblings = null === $section->getParent() ? $this->sections->findRoots() : array_values($section->getParent()->getChildren()->toArray());
        usort($siblings, static fn (Section $a, Section $b) => [$a->getPosition(), mb_strtolower($a->getName())] <=> [$b->getPosition(), mb_strtolower($b->getName())]);
        $index = null;
        foreach ($siblings as $i => $s) {
            if ($s->getId() === $section->getId()) {
                $index = $i;
                break;
            }
        }
        if (null === $index) {
            return;
        }
        $target = $index + ($direction < 0 ? -1 : 1);
        if ($target < 0 || $target >= \count($siblings)) {
            return;
        }
        [$siblings[$index], $siblings[$target]] = [$siblings[$target], $siblings[$index]];
        foreach ($siblings as $i => $s) {
            $s->setPosition(($i + 1) * 10);
        }
        $this->em->flush();
    }

    /**
     * Назначает модератора раздела.
     *
     * @throws \DomainException если пользователь уже модератор этого раздела
     */
    public function addModerator(Section $section, User $user, ?User $actor = null): SectionModerator
    {
        if (null !== $this->moderators->findOneBySectionAndUser($section, $user)) {
            throw new \DomainException(\sprintf('%s уже является модератором раздела «%s».', $user->getDisplayName(), $section->getName()));
        }
        $m = new SectionModerator($section, $user, $actor);
        $section->getModerators()->add($m);
        $this->em->persist($m);
        $this->em->flush();
        $this->auditLogger->info('Назначен модератор', ['section' => $section->getFullName(), 'user' => $user->getUsername(), 'by' => $actor?->getUsername()]);

        return $m;
    }

    public function removeModerator(SectionModerator $moderator, ?User $actor = null): void
    {
        $section = $moderator->getSection();
        $user = $moderator->getUser();
        $section->getModerators()->removeElement($moderator);
        $this->em->remove($moderator);
        $this->em->flush();
        $this->auditLogger->info('Снят модератор', ['section' => $section->getFullName(), 'user' => $user->getUsername(), 'by' => $actor?->getUsername()]);
    }

    private function slugMatches(Section $section): bool
    {
        $base = $this->slugify($section->getName());

        return $section->getSlug() === $base || str_starts_with($section->getSlug(), $base.'-');
    }

    public function uniqueSlug(string $name, ?int $exceptId = null): string
    {
        $base = $this->slugify($name);
        $slug = $base;
        $i = 2;
        while ($this->sections->slugExists($slug, $exceptId)) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function slugify(string $name): string
    {
        $slug = (new AsciiSlugger('ru'))->slug($name)->lower()->toString();
        $slug = mb_substr($slug, 0, 100);

        return '' !== $slug ? $slug : 'razdel';
    }
}
