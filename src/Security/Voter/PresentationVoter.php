<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Presentation;
use App\Entity\User;
use App\Service\Presentation\PresentationAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Права на презентации: is_granted('PRESENTATION_EDIT', presentation), is_granted('PRESENTATION_MODERATE') и т.д.
 *
 * @extends Voter<string, Presentation|null>
 */
final class PresentationVoter extends Voter
{
    public const VIEW = 'PRESENTATION_VIEW';
    public const EDIT = 'PRESENTATION_EDIT';
    public const SUBMIT = 'PRESENTATION_SUBMIT';
    public const WITHDRAW = 'PRESENTATION_WITHDRAW';
    public const DECIDE = 'PRESENTATION_DECIDE';
    public const DELETE = 'PRESENTATION_DELETE';
    public const COMMENT = 'PRESENTATION_COMMENT';
    public const WORKING = 'PRESENTATION_WORKING';
    /** Модератор презентаций: маркетолог, помощник маркетолога, администратор (ведёт и собрания). */
    public const MODERATE = 'PRESENTATION_MODERATE';

    private const SUBJECT_ATTRIBUTES = [self::VIEW, self::EDIT, self::SUBMIT, self::WITHDRAW, self::DECIDE, self::DELETE, self::COMMENT, self::WORKING];

    public function __construct(private readonly PresentationAccess $access)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (self::MODERATE === $attribute) {
            return true;
        }

        return \in_array($attribute, self::SUBJECT_ATTRIBUTES, true) && $subject instanceof Presentation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::MODERATE => $this->access->isModerator($user),
            self::VIEW => $this->access->canView($user, $subject),
            self::EDIT => $this->access->canEdit($user, $subject),
            self::SUBMIT => $this->access->canSubmit($user, $subject),
            self::WITHDRAW => $this->access->canWithdraw($user, $subject),
            self::DECIDE => $this->access->canDecide($user, $subject),
            self::DELETE => $this->access->canDelete($user, $subject),
            self::COMMENT => $this->access->canComment($user, $subject),
            self::WORKING => $this->access->canSeeWorking($user, $subject),
            default => false,
        };
    }
}
