<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Document;
use App\Entity\User;
use App\Service\Onlyoffice\OnlyofficeService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Кнопки редактора ONLYOFFICE / Р7-Офис в карточке документа: office_edit(document) — можно править онлайн,
 * office_view(document) — можно открыть в редакторе для просмотра.
 */
final class OnlyofficeExtension extends AbstractExtension
{
    public function __construct(
        private readonly OnlyofficeService $onlyoffice,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('office_edit', fn (Document $document): bool => $this->onlyoffice->isEnabled() && $this->onlyoffice->canEdit($this->user(), $document)),
            new TwigFunction('office_edit_blocked', fn (Document $document): ?string => $this->blocked($document)),
            new TwigFunction('office_view', fn (Document $document): bool => $this->onlyoffice->isEnabled() && null !== $this->user() && null !== $this->onlyoffice->viewableVersion($this->user(), $document)),
        ];
    }

    /** Причина, по которой редактирование онлайн сейчас недоступно (для подсказки модератору). */
    private function blocked(Document $document): ?string
    {
        if (!$this->onlyoffice->isEnabled() || null === $this->onlyoffice->editableVersion($document)) {
            return null;
        }
        $reason = $this->onlyoffice->editBlockedReason($this->user(), $document);

        return 'недоступно' === $reason ? null : $reason;
    }

    private function user(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
