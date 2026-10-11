<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\SectionModeratorRepository;
use App\Repository\UserRepository;
use App\Security\Ldap\LdapClient;
use App\Security\Ldap\LdapException;
use App\Service\Org\OrgImporter;
use App\Service\UserManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Панель администратора: пользователи (локальные и доменные).
 */
#[Route('/admin/users')]
final class UserAdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserManager $userManager,
        private readonly SectionModeratorRepository $moderators,
        private readonly LdapClient $ldap,
        private readonly LoggerInterface $auditLogger,
        private readonly OrgImporter $orgImporter,
        #[Autowire('%kernel.project_dir%/var/org-import')]
        private readonly string $orgImportDir,
    ) {
    }

    #[Route('', name: 'admin_users', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q = mb_strtolower(trim((string) $request->query->get('q', '')));
        $users = $this->users->findAllOrdered();
        if ('' !== $q) {
            $users = array_values(array_filter($users, static fn (User $u) => str_contains(mb_strtolower($u->getUsername()), $q) || str_contains(mb_strtolower($u->getDisplayName()), $q) || str_contains(mb_strtolower((string) $u->getEmail()), $q)));
        }
        $moderated = [];
        foreach ($this->moderators->findAllWithRelations() as $m) {
            $moderated[$m->getUser()->getId()][] = $m->getSection();
        }

        return $this->render('admin/users/index.html.twig', [
            'users' => $users,
            'q' => $q,
            'moderated' => $moderated,
            'ldap_enabled' => $this->ldap->isEnabled(),
        ]);
    }

    #[Route('/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $actor): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user, ['is_new' => true, 'ldap_enabled' => $this->ldap->isEnabled()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $position = (string) $form->get('role')->getData();
            $isAdmin = User::POSITION_ADMIN === $position;
            $plain = (string) $form->get('plainPassword')->getData();
            try {
                // Руководитель проверяется до создания учётной записи: ошибка в логине не должна оставить полусозданного пользователя.
                $manager = $this->resolveManager($form);
                if ($user->isLdap()) {
                    $created = $this->userManager->createLdapUser($user->getUsername(), $user->getDisplayName(), $isAdmin, $user->getEmail(), $actor);
                    $created->setDepartment($user->getDepartment())->setActive($user->isActive())->setPosition($position);
                    $created->setJobTitle($user->getJobTitle())->setEmployeeNumber($user->getEmployeeNumber())->setManager($manager);
                    $this->userManager->save($created, $actor);
                    $this->addFlash('success', \sprintf('Доменная учётная запись «%s» добавлена. Пароль проверяется в домене при входе.', $created->getUsername()));
                } else {
                    $generated = false;
                    if ('' === $plain) {
                        $plain = self::generatePassword();
                        $generated = true;
                    }
                    $created = $this->userManager->create($user->getUsername(), $user->getDisplayName(), $plain, $isAdmin, $user->getEmail(), (bool) $form->get('mustChangePassword')->getData() || $generated, $actor);
                    $created->setDepartment($user->getDepartment())->setActive($user->isActive())->setPosition($position);
                    $created->setJobTitle($user->getJobTitle())->setEmployeeNumber($user->getEmployeeNumber())->setManager($manager);
                    $this->userManager->save($created, $actor);
                    $this->addFlash('success', \sprintf('Пользователь «%s» создан.', $created->getUsername()));
                    if ($generated) {
                        $this->addFlash('warning', \sprintf('Сгенерированный пароль: %s — передайте его пользователю, повторно он не показывается.', $plain));
                    }
                }

                return $this->redirectToRoute('admin_users');
            } catch (\InvalidArgumentException $e) {
                $form->get('plainPassword')->addError(new FormError($e->getMessage()));
            } catch (\DomainException $e) {
                $form->get('managerLogin')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('admin/users/form.html.twig', [
            'form' => $form,
            'user' => $user,
            'logins' => $this->logins(),
            'is_new' => true,
            'ldap_enabled' => $this->ldap->isEnabled(),
        ]);
    }

    /** Наибольший размер файла оргструктуры. */
    private const ORG_MAX_BYTES = 20 * 1024 * 1024;

    /**
     * Оргструктура из 1С:ЗУП (CSV или Excel): сначала пробная загрузка с отчётом «что изменится», затем — по кнопке —
     * настоящая. Файл между шагами лежит в var/org-import (не дольше суток).
     */
    #[Route('/org-import', name: 'admin_users_org_import', methods: ['GET', 'POST'])]
    public function orgImport(Request $request, #[CurrentUser] User $actor): Response
    {
        $options = ['create' => false, 'deactivate' => true, 'names' => false];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('org_import', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Неверный CSRF-токен.');
            }
            $options = self::orgOptions($request);
            $file = $request->files->get('file');
            try {
                if (!$file instanceof UploadedFile || !$file->isValid()) {
                    throw new \RuntimeException($file instanceof UploadedFile ? 'Файл не загрузился: '.$file->getErrorMessage() : 'Выберите файл.');
                }
                if ($file->getSize() > self::ORG_MAX_BYTES) {
                    throw new \RuntimeException('Файл больше 20 МБ.');
                }
                $extension = strtolower($file->getClientOriginalExtension());
                if (!\in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
                    throw new \RuntimeException('Нужен файл CSV или Excel (.xlsx).');
                }
                $this->cleanOrgImports();
                if (!is_dir($this->orgImportDir) && !@mkdir($this->orgImportDir, 0770, true) && !is_dir($this->orgImportDir)) {
                    throw new \RuntimeException('Не удалось создать каталог '.$this->orgImportDir.'.');
                }
                $token = bin2hex(random_bytes(12));
                $file->move($this->orgImportDir, $token.'.'.$extension);
                $path = $this->orgImportDir.'/'.$token.'.'.$extension;
                $report = $this->orgImporter->import($this->orgImporter->read($path, 'file.'.$extension), $options, $actor, true);

                return $this->render('admin/users/org_import.html.twig', ['options' => $options, 'report' => $report, 'token' => $token.'.'.$extension, 'file_name' => $file->getClientOriginalName()]);
            } catch (\RuntimeException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('admin/users/org_import.html.twig', ['options' => $options, 'report' => null, 'token' => null, 'file_name' => null]);
    }

    #[Route('/org-import/apply', name: 'admin_users_org_import_apply', methods: ['POST'])]
    public function orgImportApply(Request $request, #[CurrentUser] User $actor): Response
    {
        if (!$this->isCsrfTokenValid('org_import', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        $token = (string) $request->request->get('file_token');
        $path = $this->orgImportDir.'/'.$token;
        if (!preg_match('/^[a-f0-9]{24}\.(csv|txt|xlsx)$/', $token) || !is_file($path)) {
            $this->addFlash('warning', 'Файл пробной загрузки не найден (прошло больше суток?). Загрузите его ещё раз.');

            return $this->redirectToRoute('admin_users_org_import');
        }
        $options = self::orgOptions($request);
        try {
            $report = $this->orgImporter->import($this->orgImporter->read($path, $token), $options, $actor, false);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Оргструктура не загружена, ничего не изменено: '.mb_substr($e->getMessage(), 0, 300));

            return $this->redirectToRoute('admin_users_org_import');
        } finally {
            @unlink($path);
        }
        $this->addFlash([] === $report['problems'] ? 'success' : 'warning', \sprintf('Оргструктура загружена: изменено сотрудников — %d, создано — %d, заблокировано уволенных — %d, назначено руководителей — %d%s.',
            $report['updated'], $report['created'], $report['deactivated'], $report['managers'], [] !== $report['problems'] ? '; строк с проблемами — '.\count($report['problems']) : ''));

        return $this->render('admin/users/org_import.html.twig', ['options' => $options, 'report' => $report, 'token' => null, 'file_name' => (string) $request->request->get('file_name')]);
    }

    /**
     * Руководитель из поля формы (логин); пусто — без руководителя.
     *
     * @param FormInterface<User> $form
     *
     * @throws \DomainException руководитель не найден
     */
    private function resolveManager(FormInterface $form): ?User
    {
        $login = trim((string) $form->get('managerLogin')->getData());
        if ('' === $login) {
            return null;
        }

        return $this->users->findOneByUsername($login) ?? throw new \DomainException(\sprintf('Руководитель не найден: пользователя с логином «%s» нет.', $login));
    }

    /** @return list<array{login: string, name: string}> подсказки для поля «Руководитель» */
    private function logins(): array
    {
        return array_map(static fn (User $u): array => ['login' => $u->getUsername(), 'name' => $u->getDisplayName()], $this->users->findActiveOrdered());
    }

    /** @return array{create: bool, deactivate: bool, names: bool} */
    private static function orgOptions(Request $request): array
    {
        return ['create' => $request->request->has('create'), 'deactivate' => $request->request->has('deactivate'), 'names' => $request->request->has('names')];
    }

    /** Файлы пробных загрузок старше суток удаляются. */
    private function cleanOrgImports(): void
    {
        foreach (glob($this->orgImportDir.'/*') ?: [] as $old) {
            if (is_file($old) && filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
    }

    #[Route('/{id}/edit', name: 'admin_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request, #[CurrentUser] User $actor): Response
    {
        $form = $this->createForm(UserType::class, $user, ['is_new' => false, 'is_admin' => $user->isAdmin(), 'position' => $user->getPosition(), 'ldap_enabled' => $this->ldap->isEnabled(), 'manager_login' => $user->getManager()?->getUsername()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $position = (string) $form->get('role')->getData();
            $willBeAdmin = User::POSITION_ADMIN === $position;
            $plain = (string) $form->get('plainPassword')->getData();
            try {
                if ($actor->getId() === $user->getId() && (!$willBeAdmin || !$user->isActive())) {
                    throw new \DomainException('Нельзя снять права администратора или заблокировать собственную учётную запись.');
                }
                $this->userManager->assertNotLastAdmin($user, $willBeAdmin, $user->isActive());
                if ($user->getPosition() !== $position) {
                    $this->auditLogger->info('Изменена роль пользователя', ['user' => $user->getUsername(), 'from' => $user->getPosition(), 'to' => $position, 'by' => $actor->getUsername()]);
                }
                $user->setPosition($position);
                $user->setManager($this->resolveManager($form));
                if ($user->isLdap()) {
                    $user->setPassword('')->setMustChangePassword(false);
                } elseif ('' !== $plain) {
                    $this->userManager->setPassword($user, $plain, (bool) $form->get('mustChangePassword')->getData());
                } elseif ('' === $user->getPassword()) {
                    // Учётная запись переведена из доменной в локальную — без пароля войти нельзя.
                    $plain = self::generatePassword();
                    $this->userManager->setPassword($user, $plain, true);
                    $this->addFlash('warning', \sprintf('Учётная запись стала локальной. Сгенерированный пароль: %s', $plain));
                }
                $this->userManager->save($user, $actor);
                $this->addFlash('success', 'Изменения сохранены.');

                return $this->redirectToRoute('admin_users');
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            } catch (\InvalidArgumentException $e) {
                $form->get('plainPassword')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('admin/users/form.html.twig', [
            'form' => $form,
            'user' => $user,
            'logins' => $this->logins(),
            'is_new' => false,
            'ldap_enabled' => $this->ldap->isEnabled(),
            'moderated' => $this->moderators->findForUser($user),
        ]);
    }

    #[Route('/{id}/toggle', name: 'admin_user_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(User $user, Request $request, #[CurrentUser] User $actor): Response
    {
        $this->checkToken($request, $user);
        try {
            if ($actor->getId() === $user->getId()) {
                throw new \DomainException('Нельзя заблокировать собственную учётную запись.');
            }
            $this->userManager->assertNotLastAdmin($user, $user->isAdmin(), !$user->isActive());
            $user->setActive(!$user->isActive());
            $this->userManager->save($user, $actor);
            $this->addFlash('success', $user->isActive() ? 'Учётная запись разблокирована.' : 'Учётная запись заблокирована.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_users');
    }

    /**
     * Снимает привязку учётной записи к субъекту SSO. Нужна, когда у провайдера сменился издатель или
     * идентификатор пользователя (перерегистрация приложения, пересоздание учётной записи): при следующем
     * входе через SSO запись привяжется к новому идентификатору.
     */
    #[Route('/{id}/sso-unbind', name: 'admin_user_sso_unbind', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ssoUnbind(User $user, Request $request, #[CurrentUser] User $actor): Response
    {
        $this->checkToken($request, $user);
        if (null !== $user->getSsoSubject()) {
            $old = $user->getSsoSubject();
            $user->setSsoSubject(null);
            $this->userManager->save($user, $actor);
            $this->auditLogger->warning('Снята привязка учётной записи к SSO', ['user' => $user->getUsername(), 'subject' => $old, 'by' => $actor->getUsername()]);
            $this->addFlash('success', 'Привязка к SSO снята: при следующем входе через SSO учётная запись привяжется к новому идентификатору пользователя у провайдера.');
        }

        return $this->redirectToRoute('admin_user_edit', ['id' => $user->getId()]);
    }

    #[Route('/{id}/delete', name: 'admin_user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(User $user, Request $request, #[CurrentUser] User $actor): Response
    {
        $this->checkToken($request, $user);
        try {
            $this->userManager->delete($user, $actor);
            $this->addFlash('success', 'Пользователь удалён.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/ldap-lookup', name: 'admin_user_ldap_lookup', methods: ['POST'])]
    public function ldapLookup(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ldap_lookup', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        $username = User::normalizeUsername((string) $request->request->get('username', ''));
        try {
            $info = '' === $username ? null : $this->ldap->findUser($username);
            if (null === $info) {
                $this->addFlash('warning', \sprintf('Пользователь «%s» в каталоге не найден.', $username));

                return $this->redirectToRoute('admin_user_new');
            }
            $this->addFlash('success', \sprintf('Найден в домене: %s (%s). Проверьте данные и сохраните.', $info->displayName, $info->email ?? 'без e-mail'));

            return $this->redirectToRoute('admin_user_new', ['ldap_username' => $info->username, 'ldap_name' => $info->displayName, 'ldap_email' => $info->email, 'ldap_dept' => $info->department]);
        } catch (LdapException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('admin_user_new');
        }
    }

    private function checkToken(Request $request, User $user): void
    {
        if (!$this->isCsrfTokenValid('user_'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }

    public static function generatePassword(int $length = 12): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $out .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }

        return $out;
    }
}
