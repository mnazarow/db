<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use App\Security\Access;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
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
    public const SECTION_CONTRIBUTE = 'SECTION_CONTRIBUTE';   // добавлять документы (модератор раздела или помощник маркетолога)
    public const DOCUMENT_CONTRIBUTE = 'DOCUMENT_CONTRIBUTE'; // новая редакция, правка черновика, отправка на модерацию
    public const CONTRIBUTOR = 'CONTRIBUTOR';            // добавляет документы хоть куда-нибудь

    private const ATTRIBUTES = [
        self::SECTION_VIEW, self::SECTION_MANAGE, self::SECTION_MODERATORS, self::SECTION_CONTRIBUTE,
        self::DOCUMENT_VIEW, self::DOCUMENT_EDIT, self::DOCUMENT_DELETE, self::DOCUMENT_STATS, self::DOCUMENT_CONTRIBUTE,
        self::MODERATOR, self::CONTRIBUTOR,
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
            self::SECTION_VIEW, self::SECTION_MANAGE, self::SECTION_MODERATORS, self::SECTION_CONTRIBUTE => $subject instanceof Section,
            self::DOCUMENT_VIEW, self::DOCUMENT_EDIT, self::DOCUMENT_DELETE, self::DOCUMENT_STATS, self::DOCUMENT_CONTRIBUTE => $subject instanceof Document,
            self::MODERATOR, self::CONTRIBUTOR => true,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
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
            self::SECTION_CONTRIBUTE => $this->access->canContribute($user, $subject),
            self::DOCUMENT_CONTRIBUTE => $this->access->canContributeToDocument($user, $subject),
            self::CONTRIBUTOR => $this->access->isContributor($user),
            default => false,
        };
    }
}
