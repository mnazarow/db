<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\Ldap\LdapClient;
use App\Service\ExpiryNotifier;
use App\Service\FileStorage;
use App\Repository\DocumentTextRepository;
use App\Service\PortalSettings;
use App\Service\Preview\DocumentPreviewer;
use App\Service\Text\OcrReader;
use App\Service\Text\TextExtractor;
use App\Service\Text\TextIndexer;
use App\Service\Validity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Панель администратора: текущие настройки портала (только просмотр — значения задаются в .env.local),
 * проверка подключения к домену и отправки почты, ручной запуск проверки сроков.
 */
#[Route('/admin/settings')]
final class SettingsController extends AbstractController
{
    /** Сколько документов индексируется за одно нажатие кнопки в панели (остальное — командой или по cron). */
    private const REINDEX_LIMIT = 200;

    public function __construct(
        private readonly LdapClient $ldap,
        private readonly FileStorage $storage,
        private readonly Validity $validity,
        private readonly ExpiryNotifier $notifier,
        private readonly string $mailFrom,
        private readonly bool $notifyAdmins,
        private readonly int $defaultValidityMonths,
        private readonly string $timezone,
        private readonly string $importDir,
        private readonly PortalSettings $settings,
        private readonly DocumentTextRepository $texts,
        private readonly TextIndexer $indexer,
        private readonly TextExtractor $extractor,
        private readonly OcrReader $ocr,
        private readonly DocumentPreviewer $previewer,
    ) {
    }

    #[Route('', name: 'admin_settings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $storageDir = $this->storage->getStorageDir();
        $clientIp = (string) $request->getClientIp();

        return $this->render('admin/settings/index.html.twig', [
            'guest' => [
                'mode' => $this->settings->guestMode(),
                'networks' => $this->settings->guestNetworks(),
                'modes' => PortalSettings::GUEST_MODE_LABELS,
                'client_ip' => $clientIp,
                'client_allowed' => $this->settings->isGuestAllowed($clientIp),
            ],
            'ldap' => $this->ldap->getSettings()->summary(),
            'storage' => [
                'dir' => $storageDir,
                'writable' => is_dir($storageDir) && is_writable($storageDir),
                'max_mb' => $this->storage->getUploadMaxMb(),
                'extensions' => $this->storage->getAllowedExtensions(),
                'php_upload_max' => \ini_get('upload_max_filesize'),
                'php_post_max' => \ini_get('post_max_size'),
                'import_dir' => $this->importDir,
                'import_writable' => is_dir($this->importDir) && is_writable($this->importDir),
            ],
            'validity' => ['soon_days' => $this->validity->getSoonDays(), 'default_months' => $this->defaultValidityMonths, 'timezone' => $this->timezone],
            'mail' => ['from' => $this->mailFrom, 'notify_admins' => $this->notifyAdmins, 'dsn_set' => 'null://null' !== ($_SERVER['MAILER_DSN'] ?? $_ENV['MAILER_DSN'] ?? 'null://null')],
            'php' => ['version' => \PHP_VERSION, 'ldap_ext' => \extension_loaded('ldap'), 'memory_limit' => \ini_get('memory_limit')],
            'search' => $this->texts->summary() + [
                'pending' => \count($this->texts->findOutdatedDocumentIds(100000)),
                'pdf' => $this->extractor->hasPdftotext(),
                'extensions' => $this->extractor->supportedExtensions(),
            ],
            'approval' => $this->settings->approval(),
            'ocr' => $this->settings->ocr() + [
                'available' => $this->ocr->isAvailable(),
                'installed' => $this->ocr->isAvailable() ? $this->ocr->installedLanguages() : [],
            ],
            'preview' => [
                'available' => $this->previewer->isAvailable(),
                'binary' => $this->previewer->isAvailable() ? $this->previewer->binary() : '',
                'extensions' => DocumentPreviewer::CONVERTIBLE,
            ] + $this->previewer->usage(),
        ]);
    }

    #[Route('/ldap-test', name: 'admin_settings_ldap_test', methods: ['POST'])]
    public function ldapTest(Request $request): Response
    {
        $this->checkToken($request);
        $result = $this->ldap->testConnection();
        $this->addFlash($result['ok'] ? 'success' : 'danger', 'LDAP: '.$result['message']);

        return $this->redirectToRoute('admin_settings');
    }

    #[Route('/mail-test', name: 'admin_settings_mail_test', methods: ['POST'])]
    public function mailTest(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        try {
            $this->notifier->sendTest($user);
            $this->addFlash('success', \sprintf('Тестовое письмо отправлено на %s (если MAILER_DSN=null://null, письмо только записано в журнал).', $user->getEmail()));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Не удалось отправить письмо: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_settings');
    }

    #[Route('/expiry-run', name: 'admin_settings_expiry_run', methods: ['POST'])]
    public function expiryRun(Request $request): Response
    {
        $this->checkToken($request);
        try {
            $stats = $this->notifier->run((bool) $request->request->get('force', false));
            $this->addFlash('success', \sprintf('Проверка сроков выполнена: проверено %d, истекает %d, просрочено %d, отправлено писем %d (получатели: %s).', $stats['checked'], $stats['soon'], $stats['expired'], $stats['emails'], [] !== $stats['recipients'] ? implode(', ', $stats['recipients']) : 'нет'));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Ошибка проверки сроков: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_settings');
    }

    /** Сохраняет режим просмотра без входа и список разрешённых IP-адресов и подсетей. */
    #[Route('/guest-access', name: 'admin_settings_guest_access', methods: ['POST'])]
    public function guestAccess(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $mode = (string) $request->request->get('mode', PortalSettings::GUEST_ALL);
        $networks = PortalSettings::parseNetworkList((string) $request->request->get('networks', ''));
        try {
            $this->settings->setGuestAccess($mode, $networks, $user);
            $this->addFlash('success', 'Настройки гостевого доступа сохранены: '.(PortalSettings::GUEST_MODE_LABELS[$this->settings->guestMode()] ?? $mode).'.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_settings');
    }

    /** Переиндексация содержимого документов для полнотекстового поиска (порция за один запрос). */
    #[Route('/reindex', name: 'admin_settings_reindex', methods: ['POST'])]
    public function reindex(Request $request): Response
    {
        $this->checkToken($request);
        $stats = $this->indexer->reindex(self::REINDEX_LIMIT, (bool) $request->request->get('all', false));
        $left = \count($this->texts->findOutdatedDocumentIds(100000));
        $this->addFlash('success', \sprintf('Проиндексировано документов: %d (с текстом %d, без текста %d). Осталось: %d%s.',
            $stats['processed'], $stats['indexed'], $stats['empty'], $left,
            $left > 0 ? ' — нажмите ещё раз или запустите app:search:reindex' : ''));

        return $this->redirectToRoute('admin_settings');
    }

    /** Согласование документов перед публикацией. */
    #[Route('/approval', name: 'admin_settings_approval', methods: ['POST'])]
    public function approval(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $this->settings->setApproval($request->request->has('required'), $request->request->has('auto_publish'), $user);
        $this->addFlash('success', $request->request->has('required')
            ? 'Публикация только после согласования включена.'
            : 'Согласование перед публикацией выключено: модератор публикует документы сам.');

        return $this->redirectToRoute('admin_settings');
    }

    /** Распознавание сканов (OCR): включение и параметры. */
    #[Route('/ocr', name: 'admin_settings_ocr', methods: ['POST'])]
    public function ocr(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $languages = PortalSettings::normalizeLanguages((string) $request->request->get('languages', ''));
        $enabled = $request->request->has('enabled');
        if ($enabled && !$this->ocr->isAvailable()) {
            $this->addFlash('danger', 'На сервере нет tesseract или pdftoppm — установите пакеты tesseract-ocr, tesseract-ocr-rus и poppler-utils.');

            return $this->redirectToRoute('admin_settings');
        }
        $installed = $this->ocr->isAvailable() ? $this->ocr->installedLanguages() : [];
        $missing = array_values(array_diff(array_filter(explode('+', $languages)), $installed));
        if ($enabled && [] !== $installed && [] !== $missing) {
            $this->addFlash('danger', \sprintf('В tesseract нет языков: %s. Установлены: %s. Нужный язык ставится пакетом tesseract-ocr-<язык>.', implode(', ', $missing), implode(', ', $installed)));

            return $this->redirectToRoute('admin_settings');
        }
        $this->settings->setOcr([
            'enabled' => $enabled,
            'languages' => $languages,
            'max_pages' => (int) $request->request->get('max_pages', PortalSettings::OCR_DEFAULT_MAX_PAGES),
            'dpi' => (int) $request->request->get('dpi', PortalSettings::OCR_DEFAULT_DPI),
        ], $user);
        $this->addFlash('success', $enabled
            ? 'Распознавание сканов включено. Уже загруженные сканы попадут в поиск после переиндексации («Переиндексировать всё»).'
            : 'Распознавание сканов выключено.');

        return $this->redirectToRoute('admin_settings');
    }

    /** Очистка готовых предпросмотров (файлы соберутся заново при обращении). */
    #[Route('/preview-clear', name: 'admin_settings_preview_clear', methods: ['POST'])]
    public function previewClear(Request $request): Response
    {
        $this->checkToken($request);
        $before = $this->previewer->usage();
        $this->previewer->clear();
        $this->addFlash('success', \sprintf('Кэш предпросмотра очищен: удалено файлов %d.', $before['files']));

        return $this->redirectToRoute('admin_settings');
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('settings', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }
}
