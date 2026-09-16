<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use App\Security\Access;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Проверка прав в контроллерах и шаблонах: is_granted('SECTION_MANAGE', section), is_granted('DOCUMENT_VIEW', document) и т.д.
 *
 * @extends Voter<string, Section|Document|null>
 */
final class PortalVoter extends Voter
{
    public const SECTION_VIEW = 'SECTION_VIEW';
    public const SECTION_MANAGE = 'SECTION_MANAGE';      // изменять раздел, создавать подразделы и документы
    public const SECTION_MODERATORS = 'SECTION_MODERATORS'; // назначать модераторов (только администратор)
    public const DOCUMENT_VIEW = 'DOCUMENT_VIEW';
    public const DOCUMENT_EDIT = 'DOCUMENT_EDIT';        // изменять, загружать версии, публиковать, архивировать
    public const DOCUMENT_DELETE = 'DOCUMENT_DELETE';
    public const DOCUMENT_STATS = 'DOCUMENT_STATS';
    public const MODERATOR = 'MODERATOR';                // модерирует хотя бы один раздел

    private const ATTRIBUTES = [
        self::SECTION_VIEW, self::SECTION_MANAGE, self::SECTION_MODERATORS,
        self::DOCUMENT_VIEW, self::DOCUMENT_EDIT, self::DOCUMENT_DELETE, self::DOCUMENT_STATS, self::MODERATOR,
    ];

    public function __construct(private readonly Access $access)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\in_array($attribute, self::ATTRIBUTES, true)) {
            return false;
        }

        return match ($attribute) {
            self::SECTION_VIEW, self::SECTION_MANAGE, self::SECTION_MODERATORS => $subject instanceof Section,
            self::DOCUMENT_VIEW, self::DOCUMENT_EDIT, self::DOCUMENT_DELETE, self::DOCUMENT_STATS => $subject instanceof Document,
            self::MODERATOR => true,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            // Гость: только просмотр разделов и открытых опубликованных документов.
            return match ($attribute) {
                self::SECTION_VIEW => true,
                self::DOCUMENT_VIEW => $this->access->canViewDocument(null, $subject),
                default => false,
            };
        }

        return match ($attribute) {
            self::SECTION_VIEW => true,
            self::SECTION_MANAGE => $this->access->canManageSection($user, $subject),
            self::SECTION_MODERATORS => $user->isAdmin(),
            self::DOCUMENT_VIEW => $this->access->canViewDocument($user, $subject),
            self::DOCUMENT_EDIT, self::DOCUMENT_DELETE, self::DOCUMENT_STATS => $this->access->canEditDocument($user, $subject),
            self::MODERATOR => $this->access->isModerator($user),
            default => false,
        };
    }
}
