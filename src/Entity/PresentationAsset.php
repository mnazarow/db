<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PresentationAssetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Изображение на слайдах презентаций. Слайды ссылаются на него по случайному ключу (asset:КЛЮЧ): так слайд
 * можно скопировать в другую презентацию, а собрание — собрать из тем разных авторов без копирования файлов.
 * Файл лежит в хранилище портала (presentations/КЛ/КЛЮЧ.РАСШ) и попадает в резервные копии вместе с ним.
 */
#[ORM\Entity(repositoryClass: PresentationAssetRepository::class)]
#[ORM\Table(name: 'presentation_asset')]
#[ORM\UniqueConstraint(name: 'uniq_presentation_asset_token', columns: ['token'])]
class PresentationAsset
{
    public const MIME_EXT = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $token;

    /** Презентация, в которую загружено (для очистки при её удалении). */
    #[ORM\ManyToOne(targetEntity: Presentation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Presentation $presentation;

    #[ORM\Column(length: 32)]
    private string $mime;

    #[ORM\Column(type: Types::INTEGER)]
    private int $size;

    #[ORM\Column(type: Types::INTEGER)]
    private int $width;

    #[ORM\Column(type: Types::INTEGER)]
    private int $height;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(name: 'original_name', length: 255, nullable: true)]
    private ?string $originalName;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(?Presentation $presentation, string $mime, int $size, int $width, int $height, string $sha256, ?string $originalName, ?User $uploadedBy)
    {
        $this->token = bin2hex(random_bytes(12));
        $this->presentation = $presentation;
        $this->mime = $mime;
        $this->size = $size;
        $this->width = $width;
        $this->height = $height;
        $this->sha256 = $sha256;
        $this->originalName = null !== $originalName ? mb_substr($originalName, 0, 255) : null;
        $this->uploadedBy = $uploadedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getPresentation(): ?Presentation
    {
        return $this->presentation;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function getExtension(): string
    {
        return self::MIME_EXT[$this->mime] ?? 'bin';
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Путь файла относительно хранилища портала. */
    public function getRelativePath(): string
    {
        return 'presentations/'.substr($this->token, 0, 2).'/'.$this->token.'.'.$this->getExtension();
    }
}
