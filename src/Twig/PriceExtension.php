<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\PriceRevisionRepository;
use App\Service\Price\PriceAccess;
use App\Service\Price\PriceFormat;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Шаблоны раздела «Прайс-листы»: вывод цены («1 234,50»), редакции на модерации (значок в шапке).
 */
final class PriceExtension extends AbstractExtension implements ResetInterface
{
    private ?int $pending = null;

    public function __construct(
        private readonly Security $security,
        private readonly PriceAccess $access,
        private readonly PriceRevisionRepository $revisions,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('price_moderation_pending', [$this, 'pending']),
            new TwigFunction('can_upload_prices', [$this, 'canUpload']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('price_value', [self::class, 'format']),
            new TwigFilter('price_delta', [self::class, 'delta']),
        ];
    }

    /** «1234.5» → «1 234,50» (с $sign — «1 234,50 ₽»); текст — как есть; пусто — «—». */
    public static function format(?string $value, ?string $sign = null): string
    {
        return PriceFormat::money($value, $sign);
    }

    /** Изменение цены в процентах: «+5,2 %», «−3 %». */
    public static function delta(?float $percent): string
    {
        if (null === $percent) {
            return '';
        }

        return ($percent > 0 ? '+' : ($percent < 0 ? '−' : '')).str_replace('.', ',', (string) abs($percent))."\u{00A0}%";
    }

    public function canUpload(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->access->canUpload($user);
    }

    /** Редакции помощников, ждущие решения, — для маркетологов и администраторов. */
    public function pending(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->access->canManage($user)) {
            return 0;
        }
        if (null === $this->pending) {
            try {
                $this->pending = $this->revisions->countPending();
            } catch (\Throwable) {
                $this->pending = 0; // таблиц ещё нет (миграция не применена)
            }
        }

        return $this->pending;
    }

    public function reset(): void
    {
        $this->pending = null;
    }
}
