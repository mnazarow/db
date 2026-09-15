<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Document;
use App\Entity\Section;
use App\Service\Validity;
use PHPUnit\Framework\TestCase;

final class ValidityTest extends TestCase
{
    private Validity $validity;

    protected function setUp(): void
    {
        $this->validity = new Validity(30, 'Europe/Moscow');
    }

    public function testStates(): void
    {
        $today = $this->validity->today();
        self::assertSame(Validity::NONE, $this->validity->stateForDate(null));
        self::assertSame(Validity::EXPIRED, $this->validity->stateForDate($today->modify('-1 day')));
        self::assertSame(Validity::SOON, $this->validity->stateForDate($today));
        self::assertSame(Validity::SOON, $this->validity->stateForDate($today->modify('+30 days')));
        self::assertSame(Validity::VALID, $this->validity->stateForDate($today->modify('+31 days')));
    }

    public function testDescribe(): void
    {
        $today = $this->validity->today();
        $doc = new Document(new Section());
        self::assertSame('срок актуальности не ограничен', $this->validity->describe($doc));
        $doc->setValidUntil($today->modify('-3 days'));
        self::assertStringStartsWith('просрочен на 3 дня', $this->validity->describe($doc));
        $doc->setValidUntil($today);
        self::assertStringStartsWith('истекает сегодня', $this->validity->describe($doc));
        $doc->setValidUntil($today->modify('+5 days'));
        self::assertStringStartsWith('истекает через 5 дней', $this->validity->describe($doc));
        $doc->setValidUntil($today->modify('+2 years'));
        self::assertStringStartsWith('актуален до', $this->validity->describe($doc));
    }

    public function testPlural(): void
    {
        self::assertSame('1 документ', Validity::plural(1, ['документ', 'документа', 'документов']));
        self::assertSame('2 документа', Validity::plural(2, ['документ', 'документа', 'документов']));
        self::assertSame('5 документов', Validity::plural(5, ['документ', 'документа', 'документов']));
        self::assertSame('11 документов', Validity::plural(11, ['документ', 'документа', 'документов']));
        self::assertSame('21 документ', Validity::plural(21, ['документ', 'документа', 'документов']));
    }

    public function testExpiryStageResetsWhenDateChanges(): void
    {
        $doc = (new Document(new Section()))->setValidUntil(new \DateTimeImmutable('2030-01-01'));
        $doc->setExpiryNoticeStage(2);
        $doc->setValidUntil(new \DateTimeImmutable('2030-01-01'));
        self::assertSame(2, $doc->getExpiryNoticeStage(), 'та же дата — стадия сохраняется');
        $doc->setValidUntil(new \DateTimeImmutable('2031-01-01'));
        self::assertSame(0, $doc->getExpiryNoticeStage(), 'новая дата — стадия сбрасывается');
    }
}
