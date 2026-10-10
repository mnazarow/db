<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentAcknowledgementRepository;
use App\Repository\DocumentApprovalRepository;
use App\Repository\UserRepository;
use App\Security\Access;
use App\Service\FileStorage;
use App\Service\Validity;
use App\Service\Video\VideoApi;
use App\Service\Video\VideoIntegration;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Вспомогательные фильтры и функции шаблонов.
 */
final class AppExtension extends AbstractExtension implements ResetInterface
{
    public const STATUS_LABELS = [
        Document::STATUS_DRAFT => 'Черновик',
        Document::STATUS_REVIEW => 'На согласовании',
        Document::STATUS_PUBLISHED => 'Опубликован',
        Document::STATUS_ARCHIVED => 'В архиве',
    ];

    public const TYPE_LABELS = [
        Document::TYPE_FILE => 'Файл',
        Document::TYPE_PAGE => 'Страница',
    ];

    public function __construct(
        private readonly Validity $validity,
        private readonly Access $access,
        private readonly Security $security,
        private readonly DocumentAcknowledgementRepository $acknowledgements,
        private readonly DocumentApprovalRepository $approvals,
        private readonly VideoIntegration $video,
        private readonly ?VideoApi $videoApi = null,
        private readonly ?UserRepository $users = null,
    ) {
    }

    /** @var array<int, bool> сотрудник → есть ли подчинённые (на время запроса) */
    private array $hasTeam = [];

    /** Есть ли у вошедшего сотрудника подчинённые (ссылка «Мои сотрудники»); один запрос к базе на страницу. */
    public function hasTeam(): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || null === $this->users) {
            return false;
        }
        $id = (int) $user->getId();
        if (!isset($this->hasTeam[$id])) {
            try {
                $this->hasTeam[$id] = $this->users->hasSubordinates($user);
            } catch (\Throwable) {
                $this->hasTeam[$id] = false;
            }
        }

        return $this->hasTeam[$id];
    }

    public function reset(): void
    {
        $this->hasTeam = [];
    }

    /**
     * Сколько видео ждут модерации в разделе «Видео» (для маркетологов, модераторов разделов и
     * администраторов — они модераторы видео). Число общее для всех и кэшируется на минуту.
     */
    public function pendingVideoModeration(): int
    {
        $user = $this->security->getUser();
        if (null === $this->videoApi || !$user instanceof User || !$this->video->isEnabled() || !$this->access->isModerator($user)) {
            return 0;
        }

        return $this->videoApi->pendingModeration();
    }

    /** Сколько материалов ждут модерации в разделах, которыми управляет текущий пользователь (значок в шапке). */
    public function pendingModeration(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->access->isModerator($user)) {
            return 0;
        }

        return $this->approvals->countModerationQueue($this->access->managedSectionIds($user));
    }

    /** Роль текущего пользователя для подписи в шапке. */
    public function roleLabel(): string
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 'гость';
        }
        if (User::POSITION_USER === $user->getPosition() && $this->access->isModerator($user)) {
            return 'модератор';
        }

        return mb_strtolower($user->getPositionLabel());
    }

    /** Сколько документов ждут ознакомления у текущего пользователя (значок в шапке). */
    public function pendingAcknowledgements(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->acknowledgements->countPendingForUser($user, $this->access->viewer($user)) : 0;
    }

    /** Сколько документов ждут согласования текущего пользователя (значок в шапке). */
    public function pendingApprovals(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->approvals->countPendingForUser($user) : 0;
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('filesize', [FileStorage::class, 'humanSize']),
            new TwigFilter('plural', [Validity::class, 'plural']),
            new TwigFilter('validity_state', fn (Document $d): string => $this->validity->stateOf($d)),
            new TwigFilter('validity_text', fn (Document $d): string => $this->validity->describe($d)),
            new TwigFilter('validity_label', static fn (string $state): string => Validity::LABELS[$state] ?? $state),
            new TwigFilter('status_label', static fn (string $status): string => self::STATUS_LABELS[$status] ?? $status),
            new TwigFilter('type_label', static fn (string $type): string => self::TYPE_LABELS[$type] ?? $type),
            new TwigFilter('event_label', static fn (string $type): string => DocumentEvent::LABELS[$type] ?? $type),
            new TwigFilter('ext_icon', [self::class, 'extIcon']),
            new TwigFilter('days_left', fn (Document $d): ?int => $this->validity->daysLeft($d)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can_manage', [$this, 'canManage']),
            new TwigFunction('is_moderator', [$this, 'isModerator']),
            new TwigFunction('validity_today', fn (): \DateTimeImmutable => $this->validity->today()),
            new TwigFunction('acknowledgements_pending', [$this, 'pendingAcknowledgements']),
            new TwigFunction('approvals_pending', [$this, 'pendingApprovals']),
            new TwigFunction('moderation_pending', [$this, 'pendingModeration']),
            new TwigFunction('video_moderation_pending', [$this, 'pendingVideoModeration']),
            new TwigFunction('is_contributor', [$this, 'isContributor']),
            new TwigFunction('has_team', [$this, 'hasTeam']),
            new TwigFunction('role_label', [$this, 'roleLabel']),
            new TwigFunction('video_enabled', fn (): bool => $this->video->isEnabled()),
            new TwigFunction('video_url', fn (string $path = '/'): string => $this->video->url($path)),
        ];
    }

    public function canManage(Section|Document $subject): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $section = $subject instanceof Document ? $subject->getSection() : $subject;

        return $this->access->canManageSection($user, $section);
    }

    public function isModerator(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->access->isModerator($user);
    }

    /** Добавляет ли текущий пользователь материалы (модератор, маркетолог или помощник маркетолога). */
    public function isContributor(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->access->isContributor($user);
    }

    /** Условное обозначение формата файла для значка. */
    public static function extIcon(?string $ext): string
    {
        return match (mb_strtolower((string) $ext)) {
            'pdf' => 'pdf',
            'doc', 'docx', 'odt', 'rtf' => 'doc',
            'xls', 'xlsx', 'ods', 'csv' => 'xls',
            'ppt', 'pptx', 'odp' => 'ppt',
            'png', 'jpg', 'jpeg', 'gif', 'svg' => 'img',
            'zip', '7z', 'rar' => 'zip',
            'dwg', 'dxf' => 'cad',
            'txt', 'md', 'xml', 'json' => 'txt',
            default => 'file',
        };
    }
}
