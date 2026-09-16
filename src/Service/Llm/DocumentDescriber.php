<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Entity\User;
use App\Service\Text\TextExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Формирует краткие описания документов через внешнюю LLM по названию, реквизитам и тексту текущей версии.
 * Описание, написанное человеком, по умолчанию не трогается (только $force); отредактированное вручную
 * сгенерированное описание становится «ручным» (см. Document::setDescription).
 */
final class DocumentDescriber
{
    public const SKIP_DISABLED = 'disabled';
    public const SKIP_MANUAL = 'manual';
    public const SKIP_EXISTS = 'exists';
    public const SKIP_NO_VERSION = 'no_version';

    /** Максимальная длина описания (символов). */
    private const MAX_DESCRIPTION = 2000;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly TextExtractor $extractor,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->llm->isEnabled();
    }

    /** Включено ли автоматическое описание новых документов (при создании и импорте). */
    public function isAutoEnabled(): bool
    {
        $cfg = $this->llm->config();

        return $cfg['enabled'] && $cfg['auto_describe'];
    }

    /**
     * Нужно ли формировать описание для документа при заданном режиме.
     * $regenerate — переформировать уже сгенерированные; $force — переписать и ручные описания.
     * Возвращает null, если формировать нужно, иначе причину пропуска (SKIP_*).
     */
    public function skipReason(Document $document, bool $regenerate = false, bool $force = false): ?string
    {
        if (!$this->isEnabled()) {
            return self::SKIP_DISABLED;
        }
        if (null === $document->getCurrentVersion()) {
            return self::SKIP_NO_VERSION;
        }
        $description = trim((string) $document->getDescription());
        if ('' === $description || $force) {
            return null;
        }
        if ($document->isDescriptionGenerated()) {
            return $regenerate ? null : self::SKIP_EXISTS;
        }

        return self::SKIP_MANUAL;
    }

    /**
     * Формирует и сохраняет описание документа.
     *
     * @throws LlmException
     */
    public function describe(Document $document, ?User $actor = null, ?string $ip = null): string
    {
        if (!$this->isEnabled()) {
            throw new LlmException('Формирование описаний через LLM выключено в настройках интеграций.');
        }
        $cfg = $this->llm->config();
        $prompt = $this->buildPrompt($document, $cfg['max_input_chars']);
        $answer = $this->llm->complete($cfg['prompt'], $prompt, 600);
        $description = self::cleanAnswer($answer);
        if ('' === $description) {
            throw new LlmException('Ошибка LLM: модель вернула пустое описание.');
        }
        $document->setGeneratedDescription($description)->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist(new DocumentEvent($document, DocumentEvent::UPDATE, $actor, null, $ip, ['description' => 'llm', 'model' => $cfg['model']]));
        $this->em->flush();
        $this->auditLogger->info('Сформировано описание документа через LLM', ['document' => $document->getId(), 'model' => $cfg['model'], 'by' => $actor?->getUsername() ?? 'system', 'chars' => mb_strlen($description)]);

        return $description;
    }

    /** Текст запроса к модели: реквизиты документа и фрагмент содержимого. */
    public function buildPrompt(Document $document, int $maxChars): string
    {
        $lines = ['Название: '.$document->getTitle()];
        if (null !== $document->getCode() && '' !== $document->getCode()) {
            $lines[] = 'Обозначение/номер: '.$document->getCode();
        }
        $lines[] = 'Раздел: '.$document->getSection()->getFullName();
        if ([] !== $document->getTags()) {
            $lines[] = 'Теги: '.implode(', ', $document->getTags());
        }
        $version = $document->getCurrentVersion();
        if (null !== $version && $version->isFile()) {
            $lines[] = 'Файл: '.(string) $version->getOriginalName();
        }
        if (null !== $document->getValidUntil()) {
            $lines[] = 'Актуален до: '.$document->getValidUntil()->format('d.m.Y');
        }
        $text = '';
        $status = TextExtractor::STATUS_MISSING;
        if (null !== $version) {
            $extracted = $this->extractor->extract($version);
            $status = $extracted['status'];
            $text = (string) $extracted['text'];
        }
        if ('' !== $text) {
            $excerpt = mb_substr($text, 0, max(500, $maxChars));
            $lines[] = '';
            $lines[] = \sprintf('Текст документа%s:', mb_strlen($text) > mb_strlen($excerpt) ? ' (начало, всего '.mb_strlen($text).' символов)' : '');
            $lines[] = $excerpt;
        } else {
            $lines[] = '';
            $lines[] = match ($status) {
                TextExtractor::STATUS_UNSUPPORTED => 'Текст документа недоступен (формат файла не поддерживает извлечение текста) — опиши документ по названию, разделу и имени файла, кратко и без домыслов.',
                default => 'Текст документа недоступен — опиши документ по названию, разделу и имени файла, кратко и без домыслов.',
            };
        }

        return implode("\n", $lines);
    }

    /** Убирает кавычки, префиксы вроде «Описание:» и лишние переносы из ответа модели. */
    public static function cleanAnswer(string $answer): string
    {
        $text = trim($answer);
        $text = preg_replace('/^(описание|краткое описание|аннотация)\s*[:—-]\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/^```[a-z]*\s*|\s*```$/u', '', $text) ?? $text;
        $text = trim($text);
        // Кавычки убираем только парные — обрамляющие весь ответ.
        if (preg_match('/^["«“\'](.*)["»”\']$/su', $text, $m)) {
            $text = trim($m[1]);
        }
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
        if (mb_strlen($text) > self::MAX_DESCRIPTION) {
            $cut = mb_substr($text, 0, self::MAX_DESCRIPTION);
            $dot = mb_strrpos($cut, '.');
            $text = false !== $dot && $dot > self::MAX_DESCRIPTION / 2 ? mb_substr($cut, 0, $dot + 1) : rtrim($cut).'…';
        }

        return $text;
    }
}
