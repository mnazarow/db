<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Entity\Section;
use App\Entity\User;
use App\Security\Access;
use App\Service\FileStorage;
use App\Service\Validity;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Вспомогательные фильтры и функции шаблонов.
 */
final class AppExtension extends AbstractExtension
{
    public const STATUS_LABELS = [
        Document::STATUS_DRAFT => 'Черновик',
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
    ) {
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
