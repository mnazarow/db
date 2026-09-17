<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentTemplate;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\DocumentTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Шаблоны документов: заготовка карточки и текста, автонумерация обозначений.
 */
final class DocumentTemplateService
{
    /** Сколько раз пробуем подобрать свободный номер, если обозначение уже занято. */
    private const MAX_ATTEMPTS = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentTemplateRepository $templates,
        private readonly DocumentRepository $documents,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    /** @return list<DocumentTemplate> */
    public function active(): array
    {
        return $this->templates->findActive();
    }

    /**
     * Заполняет новый документ по шаблону: раздел, вид, название, обозначение, теги, срок, доступ.
     * Номер выдаётся сразу — модератор видит его в форме и может заменить.
     * Возвращает заготовку текста страницы (для шаблонов вида «страница»).
     */
    public function prepare(DocumentTemplate $template, Document $document, ?\DateTimeImmutable $today = null): ?string
    {
        $today ??= new \DateTimeImmutable();
        if (null !== $template->getSection()) {
            $document->setSection($template->getSection());
        }
        $document->setType($template->getType())
            ->setTags($template->getTags())
            ->setPublic($template->isPublic());
        if (null !== $template->getTitlePattern()) {
            $document->setTitle($this->render($template->getTitlePattern(), $today, null));
        }
        if (null !== $template->getCodePattern()) {
            $document->setCode($this->nextCode($template, $today));
        }
        if (null !== $template->getValidityMonths()) {
            $document->setValidUntil($today->modify(\sprintf('+%d months', $template->getValidityMonths()))->setTime(0, 0));
        }
        return $template->isPage() ? $template->getBody() : null;
    }

    /**
     * Выдаёт следующее свободное обозначение по образцу шаблона.
     * Счётчик сохраняется в шаблоне, поэтому номера не повторяются даже после удаления документов.
     */
    public function nextCode(DocumentTemplate $template, ?\DateTimeImmutable $today = null): ?string
    {
        $pattern = $template->getCodePattern();
        if (null === $pattern) {
            return null;
        }
        $today ??= new \DateTimeImmutable();
        $year = (int) $today->format('Y');
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $code = $this->render($pattern, $today, $template->takeNumber($year));
            if (null === $this->documents->findOneBy(['code' => $code])) {
                $this->em->flush();

                return $code;
            }
        }
        $this->em->flush();

        return null;
    }

    /** Подставляет год, месяц и номер в образец. */
    public function render(string $pattern, \DateTimeImmutable $today, ?int $number): string
    {
        $replacements = [
            '{ГОД}' => $today->format('Y'),
            '{YYYY}' => $today->format('Y'),
            '{ГГ}' => $today->format('y'),
            '{YY}' => $today->format('y'),
            '{МЕСЯЦ}' => $today->format('m'),
            '{MM}' => $today->format('m'),
            '{ДАТА}' => $today->format('d.m.Y'),
        ];
        if (null !== $number) {
            $replacements += [
                '{NNNN}' => str_pad((string) $number, 4, '0', \STR_PAD_LEFT),
                '{NNN}' => str_pad((string) $number, 3, '0', \STR_PAD_LEFT),
                '{NN}' => str_pad((string) $number, 2, '0', \STR_PAD_LEFT),
                '{N}' => (string) $number,
            ];
        }

        return strtr($pattern, $replacements);
    }

    /** Пример обозначения для страницы настроек — без расхода счётчика. */
    public function preview(DocumentTemplate $template, ?\DateTimeImmutable $today = null): ?string
    {
        $pattern = $template->getCodePattern();
        if (null === $pattern) {
            return null;
        }
        $today ??= new \DateTimeImmutable();
        $year = (int) $today->format('Y');
        $next = $template->getCounterYear() === $year ? $template->getCounter() + 1 : 1;

        return $this->render($pattern, $today, $next);
    }

    public function save(DocumentTemplate $template, User $by): void
    {
        $isNew = null === $template->getId();
        if ($isNew) {
            $template->setCreatedBy($by);
            $this->em->persist($template);
        }
        $this->em->flush();
        $this->auditLogger->info($isNew ? 'Создан шаблон документа' : 'Изменён шаблон документа', ['template' => $template->getName(), 'user' => $by->getUsername()]);
    }

    public function delete(DocumentTemplate $template, User $by): void
    {
        $this->auditLogger->info('Удалён шаблон документа', ['template' => $template->getName(), 'user' => $by->getUsername()]);
        $this->em->remove($template);
        $this->em->flush();
    }

    public function markUsed(DocumentTemplate $template): void
    {
        $template->markUsed();
        $this->em->flush();
    }
}
