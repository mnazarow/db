<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\SectionModeratorRepository;
use App\Repository\UserRepository;
use App\Service\Notification\TelegramNotifier;
use App\Service\PortalUrl;
use App\Service\Video\VideoApi;
use App\Service\Video\VideoIntegration;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Внутренние адреса для видеопортала (раздел «Видео»), кроме проверки сессии (VideoSessionController):
 *
 *  - GET  /internal/video/users  — справочник сотрудников: видеопортал заводит учётные записи всем
 *    сотрудникам (а не только заходившим в раздел), блокирует уволенных и собирает группы по
 *    подразделениям — так обязательные просмотры и доступ к видео можно назначать кому угодно;
 *  - POST /internal/video/notify — уведомления видеопортала (видео на модерации, решения модератора,
 *    обязательные просмотры…) уходят сотрудникам письмом и в Telegram портала: почта и бот
 *    настраиваются один раз — в портале.
 *
 * Как и проверка сессии, адреса доступны только по внутреннему адресу (nginx снаружи отвечает 404) и
 * только с общим секретом VIDEO_SHARED_SECRET; без него отвечают 404.
 */
final class VideoInternalController extends AbstractController
{
    /** Больше стольких адресатов в одном уведомлении не принимается (видеопортал делит рассылку на части). */
    public const NOTIFY_MAX_USERS = 500;

    /** Уведомления о работе, которую нужно сделать: письмо уходит, даже если сотрудник отключил письма об изменениях. */
    private const TASK_TYPES = ['moderation', 'report', 'comment_held', 'assignment', 'assignment_reminder', 'review_requested', 'webinar_cohost'];

    public function __construct(
        private readonly VideoIntegration $video,
        private readonly UserRepository $users,
        private readonly SectionModeratorRepository $moderators,
        private readonly MailerInterface $mailer,
        private readonly PortalUrl $portalUrl,
        private readonly LoggerInterface $auditLogger,
        private readonly string $mailFrom,
        private readonly string $appName,
        private readonly ?TelegramNotifier $telegram = null,
        private readonly ?VideoApi $videoApi = null,
    ) {
    }

    #[Route('/internal/video/users', name: 'video_users', methods: ['GET'])]
    public function users(Request $request): JsonResponse
    {
        if (!$this->video->verifySecret($request->headers->get('X-Video-Secret'))) {
            return new JsonResponse(['error' => 'not found'], 404);
        }
        $moderatorIds = array_flip($this->moderators->findAllModeratorUserIds());
        $out = [];
        foreach ($this->users->findAllOrdered() as $user) {
            // Роль для видео — как при входе (VideoSessionController): модератор раздела — модератор видео.
            $position = $user->getPosition();
            if (User::POSITION_USER === $position && isset($moderatorIds[(int) $user->getId()])) {
                $position = User::POSITION_MARKETER;
            }
            $out[] = [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'displayName' => $user->getDisplayName(),
                'email' => $user->getEmail(),
                'department' => $user->getDepartment(),
                'position' => $position,
                'active' => $user->isActive(),
            ];
        }
        $response = new JsonResponse(['users' => $out, 'generatedAt' => date('c')]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/internal/video/notify', name: 'video_notify', methods: ['POST'])]
    public function notify(Request $request): JsonResponse
    {
        if (!$this->video->verifySecret($request->headers->get('X-Video-Secret'))) {
            return new JsonResponse(['error' => 'not found'], 404);
        }
        // Письма отправляются сразу: на часть рассылки (до 50 адресатов) нужно больше обычных 30 секунд.
        @set_time_limit(140);
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'ожидается JSON'], 400);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', \is_array($data['users'] ?? null) ? $data['users'] : []), static fn (int $id): bool => $id > 0)));
        $title = self::clean($data['title'] ?? '', 200);
        if ([] === $ids || '' === $title) {
            return new JsonResponse(['error' => 'нужны адресаты (users) и заголовок (title)'], 400);
        }
        if (\count($ids) > self::NOTIFY_MAX_USERS) {
            return new JsonResponse(['error' => \sprintf('не больше %d адресатов за раз', self::NOTIFY_MAX_USERS)], 400);
        }
        $body = self::clean($data['body'] ?? '', 1000);
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($data['type'] ?? ''))) ?? '';
        $url = $this->absoluteVideoUrl((string) ($data['link'] ?? '/'));
        $task = \in_array($type, self::TASK_TYPES, true);
        if ('moderation' === $type) {
            // Новое видео на модерации или решение по нему — число в шапке должно обновиться сразу.
            $this->videoApi?->forgetPending();
        }

        $mailed = 0;
        $telegrams = 0;
        $subject = \sprintf('%s: %s', $this->appName, $title);
        foreach ($this->users->findBy(['id' => $ids]) as $user) {
            if (!$user->isActive()) {
                continue;
            }
            if (null !== $user->getEmail() && ($task || $user->isNotifyEmail())) {
                try {
                    $this->mailer->send((new TemplatedEmail())
                        ->from(Address::create($this->mailFrom))
                        ->to(new Address($user->getEmail(), $user->getDisplayName()))
                        ->subject($subject)
                        ->htmlTemplate('emails/video_notification.html.twig')
                        ->textTemplate('emails/video_notification.txt.twig')
                        ->context(['user' => $user, 'title' => $title, 'body' => $body, 'url' => $url, 'app_name' => $this->appName]));
                    ++$mailed;
                } catch (\Throwable $e) {
                    $this->auditLogger->warning('Не удалось отправить письмо раздела «Видео»', ['user' => $user->getUsername(), 'error' => $e->getMessage()]);
                }
            }
            if (null !== $this->telegram) {
                try {
                    $sent = $this->telegram->notify($user, \sprintf("<b>%s</b>\n%s<a href=\"%s\">%s</a>",
                        htmlspecialchars('Видео: '.$title, \ENT_QUOTES),
                        '' !== $body ? htmlspecialchars($body, \ENT_QUOTES)."\n" : '',
                        htmlspecialchars($url, \ENT_QUOTES),
                        htmlspecialchars($url, \ENT_QUOTES),
                    ));
                    $telegrams += $sent ? 1 : 0;
                } catch (\Throwable $e) {
                    $this->auditLogger->warning('Не удалось отправить уведомление раздела «Видео» в Telegram', ['user' => $user->getUsername(), 'error' => $e->getMessage()]);
                }
            }
        }

        return new JsonResponse(['ok' => true, 'mailed' => $mailed, 'telegram' => $telegrams]);
    }

    /** Ссылка из уведомления видеопортала (/watch/abc) → полный адрес на сайте портала (https://портал/video/watch/abc). */
    private function absoluteVideoUrl(string $link): string
    {
        $link = trim($link);
        if ('' === $link || !str_starts_with($link, '/') || str_starts_with($link, '//')) {
            $link = '/';
        }
        $base = rtrim($this->portalUrl->absolute('app_home'), '/');

        return $base.$this->video->url($link);
    }

    private static function clean(mixed $value, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags(\is_scalar($value) ? (string) $value : '')) ?? '');

        return mb_substr($text, 0, $max);
    }
}
