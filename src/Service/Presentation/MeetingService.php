<?php

declare(strict_types=1);

namespace App\Service\Presentation;

use App\Entity\Meeting;
use App\Entity\Presentation;
use App\Entity\User;
use App\Repository\MeetingRepository;
use App\Repository\PresentationRepository;
use App\Service\PortalSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Пятничные собрания: ближайшее собрание создаётся само (на ближайшую пятницу, номер — следующий за
 * последним), маркетолог меняет номер, дату, срок подачи тем и порядок тем, публикует собрание.
 * Общая презентация собирается из одобренных тем: титульный слайд «Собрание №N» с датой, «Содержание»
 * со списком тем, слайды тем по порядку и финальный слайд — как в образце.
 */
final class MeetingService
{
    /** Номер первого собрания, если в портале их ещё не было (продолжает нумерацию компании). */
    public const SETTING_FIRST_NUMBER = 'presentations.first_meeting_number';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MeetingRepository $meetings,
        private readonly PresentationRepository $presentations,
        private readonly DeckValidator $validator,
        private readonly PortalSettings $settings,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    /** Ближайшая пятница (сегодня, если сегодня пятница). */
    public static function nextFriday(?\DateTimeImmutable $today = null): \DateTimeImmutable
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $dow = (int) $today->format('N');

        return 5 === $dow ? $today : $today->modify('next friday');
    }

    /**
     * Ближайшее собрание (создаётся, если его ещё нет): к нему сотрудники готовят темы.
     */
    public function upcoming(?User $by = null, ?\DateTimeImmutable $today = null): Meeting
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $existing = $this->meetings->findUpcoming($today);
        if (null !== $existing) {
            return $existing;
        }
        $date = self::nextFriday($today);
        $number = $this->nextNumber();
        $meeting = new Meeting($number, $date, $by);
        // Темы принимаются до 18:00 четверга.
        $meeting->setDeadline($date->modify('-1 day')->setTime(18, 0));
        $this->em->persist($meeting);
        $this->em->flush();
        $this->auditLogger->info('Создано собрание', ['meeting' => $meeting->getId(), 'number' => $number, 'date' => $date->format('Y-m-d')]);

        return $meeting;
    }

    public function nextNumber(): int
    {
        $max = $this->meetings->maxNumber();
        if ($max > 0) {
            return $max + 1;
        }
        $first = (int) ($this->settings->get(self::SETTING_FIRST_NUMBER) ?? 0);

        return $first > 0 ? $first : 1;
    }

    /**
     * Следующее собрание после указанного (для «Готовить следующее собрание»).
     */
    public function createNext(Meeting $after, User $by): Meeting
    {
        $date = $after->getDate()->modify('+7 days');
        $existing = $this->meetings->findOneByDate($date);
        if (null !== $existing) {
            return $existing;
        }
        $meeting = new Meeting(max($after->getNumber() + 1, $this->meetings->maxNumber() + 1), $date, $by);
        $meeting->setDeadline($date->modify('-1 day')->setTime(18, 0));
        $this->em->persist($meeting);
        $this->em->flush();

        return $meeting;
    }

    /**
     * @throws \InvalidArgumentException номер или дата заняты другим собранием
     */
    public function update(Meeting $meeting, int $number, \DateTimeImmutable $date, ?string $title, ?\DateTimeImmutable $deadline): void
    {
        $other = $this->meetings->findOneByNumber($number);
        if (null !== $other && $other->getId() !== $meeting->getId()) {
            throw new \InvalidArgumentException(\sprintf('Номер %d уже у собрания %s.', $number, $other->getDateLabel()));
        }
        $other = $this->meetings->findOneByDate($date);
        if (null !== $other && $other->getId() !== $meeting->getId()) {
            throw new \InvalidArgumentException(\sprintf('На %s уже есть собрание №%d.', $other->getDateLabel(), $other->getNumber()));
        }
        $meeting->setNumber($number)->setDate($date)->setTitle($title)->setDeadline($deadline);
        $this->em->flush();
    }

    /**
     * Порядок тем: список id в нужном порядке (темы не из списка — в конце, как были).
     *
     * @param list<int> $ids
     */
    public function reorder(Meeting $meeting, array $ids): void
    {
        $topics = $this->presentations->findTopics($meeting);
        $byId = [];
        foreach ($topics as $t) {
            $byId[(int) $t->getId()] = $t;
        }
        $pos = 10;
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $byId[$id]->setPosition($pos);
                $pos += 10;
                unset($byId[$id]);
            }
        }
        foreach ($byId as $t) {
            $t->setPosition($pos);
            $pos += 10;
        }
        $this->em->flush();
    }

    public function publish(Meeting $meeting, User $by): void
    {
        $meeting->publish();
        $this->em->flush();
        $this->auditLogger->info('Собрание опубликовано', ['meeting' => $meeting->getId(), 'by' => $by->getUsername()]);
    }

    public function unpublish(Meeting $meeting, User $by): void
    {
        $meeting->unpublish();
        $this->em->flush();
        $this->auditLogger->info('Публикация собрания снята', ['meeting' => $meeting->getId(), 'by' => $by->getUsername()]);
    }

    /**
     * Общая презентация собрания из одобренных тем.
     *
     * @param list<Presentation>|null $topics темы (по умолчанию — одобренные темы собрания по порядку)
     *
     * @return array<string, mixed>
     */
    public function assemble(Meeting $meeting, ?array $topics = null): array
    {
        $topics ??= $this->presentations->findApprovedTopics($meeting);
        $titles = [];
        $topicSlides = [];
        foreach ($topics as $topic) {
            $deck = DeckValidator::decode($topic->getApprovedContent());
            if (null === $deck) {
                continue;
            }
            $titles[] = $topic->getApprovedTitle();
            $prefix = 't'.$topic->getId().'-';
            foreach ($deck['slides'] ?? [] as $slide) {
                if (!\is_array($slide)) {
                    continue;
                }
                $slide['id'] = substr($prefix.($slide['id'] ?? DeckValidator::newId('s')), 0, 40);
                $slide['topic'] = (int) $topic->getId();
                foreach ($slide['elements'] ?? [] as $i => $el) {
                    // Шапка темы остаётся текстом темы: привязка к названию собрания ей не нужна.
                    if (\in_array($el['bind'] ?? null, ['title', 'responsible'], true)) {
                        $slide['elements'][$i]['bind'] = null;
                    }
                    if (isset($el['link']['slide'])) {
                        $slide['elements'][$i]['link'] = ['slide' => substr($prefix.$el['link']['slide'], 0, 40)];
                    }
                }
                $topicSlides[] = $slide;
            }
        }
        $slides = [SlideLayouts::slide('cover', [
            'title' => $meeting->getDisplayTitle(),
            'date' => $meeting->getDateLabel(),
            'bindTitle' => 'meetingTitle',
            'bindDate' => 'meetingDate',
        ])];
        if ([] !== $titles) {
            $slides[] = SlideLayouts::slide('contents', ['items' => $titles]);
        }
        array_push($slides, ...$topicSlides);
        $slides[] = SlideLayouts::slide('closing');
        $deck = $this->validator->normalize([
            'format' => DeckValidator::FORMAT,
            'meta' => ['title' => $meeting->getDisplayTitle(), 'responsible' => ''],
            'slides' => $slides,
        ]);
        // Привязанные поля титульного слайда — название и дата собрания.
        foreach ($deck['slides'][0]['elements'] as &$el) {
            if ('meetingTitle' === $el['bind']) {
                $el['html'] = '<p>'.DeckValidator::esc($meeting->getDisplayTitle()).'</p>';
            } elseif ('meetingDate' === $el['bind']) {
                $el['html'] = '<p>'.DeckValidator::esc($meeting->getDateLabel()).'</p>';
            }
        }
        unset($el);

        return $deck;
    }
}
