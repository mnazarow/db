<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\AuditEvent;

/**
 * Выгрузка журнала аудита во внешние системы: JSON Lines (по записи в строке) и CEF —
 * формат, который принимают почти все SIEM (ArcSight, KUMA, MaxPatrol, Splunk через коннектор).
 */
final class AuditExporter
{
    public const FORMAT_JSONL = 'jsonl';
    public const FORMAT_CEF = 'cef';

    public const FORMATS = [self::FORMAT_JSONL, self::FORMAT_CEF];

    public function __construct(
        private readonly string $appName = 'docportal',
        private readonly string $version = '1.0.0',
    ) {
    }

    public function line(AuditEvent $event, string $format = self::FORMAT_JSONL): string
    {
        return self::FORMAT_CEF === $format ? $this->cef($event) : $this->jsonLine($event);
    }

    private function jsonLine(AuditEvent $event): string
    {
        return (string) json_encode([
            'time' => $event->getOccurredAt()->format(\DATE_ATOM),
            'level' => $event->getLevel(),
            'action' => $event->getAction(),
            'user' => $event->getActorName(),
            'ip' => $event->getIp(),
            'details' => $event->getDetails(),
            'product' => $this->appName,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /** CEF:0|Вендор|Продукт|Версия|Идентификатор события|Название|Важность|Дополнительные поля. */
    private function cef(AuditEvent $event): string
    {
        $severity = match ($event->getLevel()) {
            AuditEvent::LEVEL_ERROR => 8,
            AuditEvent::LEVEL_WARNING => 5,
            default => 2,
        };
        $extension = [
            'rt' => (string) ($event->getOccurredAt()->getTimestamp() * 1000),
            'suser' => (string) $event->getActorName(),
            'src' => (string) $event->getIp(),
            'msg' => $event->detailsLine(),
        ];
        $parts = [];
        foreach ($extension as $key => $value) {
            if ('' !== $value) {
                $parts[] = $key.'='.self::escapeExtension($value);
            }
        }

        return \sprintf(
            'CEF:0|%s|%s|%s|%s|%s|%d|%s',
            self::escapeHeader('Docportal'),
            self::escapeHeader($this->appName),
            self::escapeHeader($this->version),
            self::escapeHeader(substr(hash('crc32b', $event->getAction()), 0, 8)),
            self::escapeHeader($event->getAction()),
            $severity,
            implode(' ', $parts)
        );
    }

    private static function escapeHeader(string $value): string
    {
        return str_replace(['\\', '|', "\n", "\r"], ['\\\\', '\\|', ' ', ' '], $value);
    }

    private static function escapeExtension(string $value): string
    {
        return str_replace(['\\', '=', "\n", "\r"], ['\\\\', '\\=', ' ', ' '], $value);
    }
}
