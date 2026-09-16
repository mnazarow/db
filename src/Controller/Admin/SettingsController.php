<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\Ldap\LdapClient;
use App\Service\ExpiryNotifier;
use App\Service\FileStorage;
use App\Service\PortalSettings;
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

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('settings', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }
}
