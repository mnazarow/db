<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Presentation;
use App\Entity\User;
use App\Entity\Meeting;
use App\Repository\MeetingRepository;
use App\Repository\PresentationRepository;
use App\Service\Presentation\BrandAssets;
use App\Service\Presentation\PresentationAccess;
use App\Service\Presentation\SlideLayouts;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Шаблоны разделов презентаций: модерирует ли сотрудник презентации, сколько ждёт решения (значок в
 * шапке), подписи статусов.
 */
final class PresentationExtension extends AbstractExtension implements ResetInterface
{
    private ?int $pending = null;

    public function __construct(
        private readonly Security $security,
        private readonly PresentationAccess $access,
        private readonly PresentationRepository $presentations,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?MeetingRepository $meetings = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_presentation_moderator', [$this, 'isModerator']),
            new TwigFunction('presentation_moderation_pending', [$this, 'pending']),
            new TwigFunction('deck_render_config', [$this, 'renderConfig']),
            new TwigFunction('upcoming_meeting', [$this, 'upcomingMeeting']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('presentation_status_class', [self::class, 'statusClass']),
        ];
    }

    /**
     * Настройки отрисовки слайдов (public/js/deck-render.js): адреса изображений, фирменные картинки, QR-коды.
     *
     * @return array<string, mixed>
     */
    public function renderConfig(): array
    {
        return [
            'brand' => BrandAssets::forEditor(),
            'assetBase' => $this->urls->generate('app_presentation_asset', ['token' => '_T_', 'ext' => 'img']),
            'qr' => $this->urls->generate('app_presentation_qr'),
            'chartColors' => SlideLayouts::CHART_COLORS,
        ];
    }

    /** Ближайшее собрание (для напоминания на главной), если оно уже заведено. */
    public function upcomingMeeting(): ?Meeting
    {
        try {
            return $this->meetings?->findUpcoming(new \DateTimeImmutable('today'));
        } catch (\Throwable) {
            return null;
        }
    }

    public function isModerator(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->access->isModerator($user);
    }

    /** Презентации и темы собраний, которые ждут решения маркетолога (кроме своих — у помощника). */
    public function pending(): int
    {
        if (!$this->isModerator()) {
            return 0;
        }
        if (null === $this->pending) {
            try {
                $this->pending = $this->presentations->countModerationQueue();
            } catch (\Throwable) {
                $this->pending = 0; // таблиц ещё нет (миграция не применена)
            }
        }

        return $this->pending;
    }

    public static function statusClass(Presentation $p): string
    {
        return match (true) {
            $p->isSubmitted() => 'soon',
            $p->isRejected() => 'overdue',
            $p->isApproved() => 'done',
            $p->hasApprovedVersion() => 'shifted',
            default => 'none',
        };
    }

    public function reset(): void
    {
        $this->pending = null;
    }
}
