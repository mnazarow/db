<?php

declare(strict_types=1);

namespace App\Service\Presentation;

use App\Entity\Presentation;
use App\Entity\PresentationAsset;
use App\Entity\User;
use App\Repository\PresentationAssetRepository;
use App\Repository\PresentationRepository;
use App\Repository\PresentationVersionRepository;
use App\Repository\SlideTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Изображения слайдов в хранилище портала: загрузка (PNG, JPEG, GIF — редактор заранее уменьшает большие
 * фото и переводит WebP/HEIC в JPEG), путь к файлу для выдачи и выгрузки в PowerPoint, удаление
 * неиспользуемых после удаления презентации.
 */
final class AssetStore
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    public const MAX_PIXELS = 60_000_000;

    public function __construct(
        private readonly string $storageDir,
        private readonly EntityManagerInterface $em,
        private readonly PresentationAssetRepository $assets,
        private readonly PresentationRepository $presentations,
        private readonly PresentationVersionRepository $versions,
        private readonly ?SlideTemplateRepository $templates = null,
    ) {
    }

    /**
     * @throws \InvalidArgumentException не изображение, слишком большое или повреждённое
     */
    public function store(string $tmpPath, ?string $originalName, Presentation $presentation, ?User $by): PresentationAsset
    {
        $size = @filesize($tmpPath);
        if (false === $size || $size <= 0) {
            throw new \InvalidArgumentException('Файл не получен.');
        }
        if ($size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Изображение больше 15 МБ — уменьшите его.');
        }
        $info = @getimagesize($tmpPath);
        $mime = \is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!isset(PresentationAsset::MIME_EXT[$mime])) {
            throw new \InvalidArgumentException('Поддерживаются изображения PNG, JPEG и GIF.');
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('Слишком большое изображение (больше 60 мегапикселей).');
        }
        $sha = hash_file('sha256', $tmpPath) ?: '';
        $existing = $this->assets->findDuplicate($presentation, $sha);
        if (null !== $existing && is_file($this->path($existing))) {
            return $existing;
        }
        $asset = new PresentationAsset($presentation, $mime, $size, $width, $height, $sha, $originalName, $by);
        $target = $this->path($asset);
        $dir = \dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог для изображений презентаций.');
        }
        if (!@copy($tmpPath, $target)) {
            throw new \RuntimeException('Не удалось сохранить изображение.');
        }
        @chmod($target, 0o660);
        $this->em->persist($asset);
        $this->em->flush();

        return $asset;
    }

    public function path(PresentationAsset $asset): string
    {
        return rtrim($this->storageDir, '/').'/'.$asset->getRelativePath();
    }

    /**
     * Удалить изображения презентации, на которые больше никто не ссылается (вызывается перед её удалением).
     */
    public function cleanupFor(Presentation $presentation): int
    {
        $removed = 0;
        foreach ($this->assets->findByPresentation($presentation) as $asset) {
            if ($this->presentations->isTokenUsedElsewhere($asset->getToken(), $presentation->getId())
                || $this->versions->isTokenUsedElsewhere($asset->getToken(), $presentation->getId())
                || true === $this->templates?->isTokenUsed($asset->getToken())) {
                continue;
            }
            @unlink($this->path($asset));
            $this->em->remove($asset);
            ++$removed;
        }

        return $removed;
    }

    /**
     * Удалить изображения слайда библиотеки, которые больше ни к чему не относятся: презентации, где их
     * загрузили, уже нет, и ни одна презентация, версия или другой слайд библиотеки на них не ссылается.
     *
     * @param list<string> $tokens
     */
    public function cleanupTokens(array $tokens, ?int $exceptTemplate = null): int
    {
        $removed = 0;
        foreach (array_unique($tokens) as $token) {
            $asset = $this->assets->findOneByToken($token);
            if (null === $asset || null !== $asset->getPresentation()
                || $this->presentations->isTokenUsedElsewhere($token, null)
                || $this->versions->isTokenUsedElsewhere($token, null)
                || true === $this->templates?->isTokenUsed($token, $exceptTemplate)) {
                continue;
            }
            @unlink($this->path($asset));
            $this->em->remove($asset);
            ++$removed;
        }

        return $removed;
    }
}
