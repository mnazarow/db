<?php

declare(strict_types=1);

namespace App\Service\Presentation;

use App\Entity\SlideTemplate;
use App\Entity\User;
use App\Repository\SlideTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Библиотека фирменных слайдов: маркетологи и помощники маркетолога (модераторы презентаций) сохраняют удачные
 * слайды как общие шаблоны по категориям, сотрудники вставляют их копии из галереи «Новый слайд» редактора.
 * Слайд проверяется тем же DeckValidator, что и презентации; изображения слайдов библиотеки не удаляются
 * вместе с презентацией, из которой слайд сохранили (AssetStore::cleanupFor).
 */
final class SlideLibrary
{
    public const DEFAULT_CATEGORY = 'Общие';

    /** Предел библиотеки — чтобы галерея открывалась быстро. */
    public const MAX_TEMPLATES = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SlideTemplateRepository $templates,
        private readonly DeckValidator $validator,
        private readonly PresentationAccess $access,
        private readonly AssetStore $assets,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    public function canManage(?User $user): bool
    {
        return $this->access->isModerator($user);
    }

    /** @return list<SlideTemplate> */
    public function all(): array
    {
        return $this->templates->findOrdered();
    }

    /** @return list<string> */
    public function categories(): array
    {
        return $this->templates->categories();
    }

    /**
     * @throws \DomainException          нет прав или библиотека заполнена
     * @throws \InvalidArgumentException слайд не того формата, пустое название
     */
    public function create(User $by, string $title, string $category, mixed $slide, ?string $description = null): SlideTemplate
    {
        $this->checkManage($by);
        if (\count($this->templates->findAll()) >= self::MAX_TEMPLATES) {
            throw new \DomainException(\sprintf('В библиотеке уже %d слайдов — удалите ненужные.', self::MAX_TEMPLATES));
        }
        $category = self::category($category);
        $template = (new SlideTemplate(self::title($title), $category, $this->normalizeSlide($slide), $by))
            ->setDescription(self::description($description))
            ->setPosition($this->templates->nextPosition($category));
        $this->em->persist($template);
        $this->em->flush();
        $this->auditLogger->info('Слайд сохранён в библиотеку', ['template' => $template->getId(), 'title' => $template->getTitle(), 'category' => $category, 'by' => $by->getUsername()]);

        return $template;
    }

    public function update(User $by, SlideTemplate $template, string $title, string $category, ?string $description, mixed $slide = null): void
    {
        $this->checkManage($by);
        $category = self::category($category);
        if ($category !== $template->getCategory()) {
            $template->setPosition($this->templates->nextPosition($category));
        }
        $template->setTitle(self::title($title))->setCategory($category)->setDescription(self::description($description));
        if (null !== $slide) {
            $old = DeckValidator::assetTokens(['slides' => [$template->getSlide()]]);
            $template->setSlide($this->normalizeSlide($slide));
            $this->em->flush();
            $this->assets->cleanupTokens(array_values(array_diff($old, DeckValidator::assetTokens(['slides' => [$template->getSlide()]]))));
        }
        $this->em->flush();
        $this->auditLogger->info('Слайд библиотеки изменён', ['template' => $template->getId(), 'title' => $template->getTitle(), 'by' => $by->getUsername()]);
    }

    public function move(User $by, SlideTemplate $template, int $direction): void
    {
        $this->checkManage($by);
        $same = array_values(array_filter($this->templates->findOrdered(), static fn (SlideTemplate $t): bool => $t->getCategory() === $template->getCategory()));
        $index = array_search($template, $same, true);
        $target = false === $index ? -1 : $index + ($direction < 0 ? -1 : 1);
        if ($target < 0 || $target >= \count($same)) {
            return;
        }
        [$same[$index], $same[$target]] = [$same[$target], $same[$index]];
        foreach ($same as $i => $t) {
            $t->setPosition($i + 1);
        }
        $this->em->flush();
    }

    public function delete(User $by, SlideTemplate $template): void
    {
        $this->checkManage($by);
        $id = $template->getId();
        $title = $template->getTitle();
        $tokens = DeckValidator::assetTokens(['slides' => [$template->getSlide()]]);
        $this->em->remove($template);
        $this->em->flush();
        $this->assets->cleanupTokens($tokens);
        $this->em->flush();
        $this->auditLogger->info('Слайд удалён из библиотеки', ['template' => $id, 'title' => $title, 'by' => $by->getUsername()]);
    }

    /** Слайд вставили в презентацию — для порядка «часто используемые». */
    public function used(SlideTemplate $template): void
    {
        $template->countUse();
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    public function serialize(SlideTemplate $template): array
    {
        return [
            'id' => $template->getId(),
            'title' => $template->getTitle(),
            'category' => $template->getCategory(),
            'description' => $template->getDescription(),
            'slide' => $template->getSlide(),
            'uses' => $template->getUseCount(),
            'author' => $template->getCreatedBy()?->getDisplayName(),
            'updatedAt' => $template->getUpdatedAt()->format('d.m.Y'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function forEditor(): array
    {
        return array_map($this->serialize(...), $this->all());
    }

    /**
     * Один слайд в формате колоды: проверяется как презентация из одного слайда; ссылки на другие слайды
     * отбрасываются (их в библиотеке нет).
     *
     * @return array<string, mixed>
     */
    public function normalizeSlide(mixed $slide): array
    {
        if (!\is_array($slide)) {
            throw new \InvalidArgumentException('Слайд не прочитан.');
        }
        $deck = $this->validator->normalize(['format' => DeckValidator::FORMAT, 'slides' => [$slide]]);
        if ([] === $deck['slides']) {
            throw new \InvalidArgumentException('Слайд не прочитан.');
        }
        $s = $deck['slides'][0];
        $s['notes'] = '';
        $s['hidden'] = false;

        return $s;
    }

    private function checkManage(User $by): void
    {
        if (!$this->canManage($by)) {
            throw new \DomainException('Библиотеку слайдов ведут маркетологи и помощники маркетолога.');
        }
    }

    private static function title(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ('' === $title) {
            throw new \InvalidArgumentException('Укажите название слайда.');
        }

        return mb_substr($title, 0, 200);
    }

    private static function category(string $category): string
    {
        $category = trim(preg_replace('/\s+/u', ' ', $category) ?? '');

        return '' === $category ? self::DEFAULT_CATEGORY : mb_substr($category, 0, 100);
    }

    private static function description(?string $description): ?string
    {
        return null === $description ? null : mb_substr(trim($description), 0, 1000);
    }
}
