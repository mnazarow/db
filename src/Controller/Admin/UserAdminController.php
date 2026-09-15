<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\SectionModeratorRepository;
use App\Repository\UserRepository;
use App\Security\Ldap\LdapClient;
use App\Security\Ldap\LdapException;
use App\Service\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
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
            $isAdmin = 'admin' === $form->get('role')->getData();
            $plain = (string) $form->get('plainPassword')->getData();
            try {
                if ($user->isLdap()) {
                    $created = $this->userManager->createLdapUser($user->getUsername(), $user->getDisplayName(), $isAdmin, $user->getEmail(), $actor);
                    $created->setDepartment($user->getDepartment())->setActive($user->isActive());
                    $this->userManager->save($created, $actor);
                    $this->addFlash('success', \sprintf('Доменная учётная запись «%s» добавлена. Пароль проверяется в домене при входе.', $created->getUsername()));
                } else {
                    $generated = false;
                    if ('' === $plain) {
                        $plain = self::generatePassword();
                        $generated = true;
                    }
                    $created = $this->userManager->create($user->getUsername(), $user->getDisplayName(), $plain, $isAdmin, $user->getEmail(), (bool) $form->get('mustChangePassword')->getData() || $generated, $actor);
                    $created->setDepartment($user->getDepartment())->setActive($user->isActive());
                    $this->userManager->save($created, $actor);
                    $this->addFlash('success', \sprintf('Пользователь «%s» создан.', $created->getUsername()));
                    if ($generated) {
                        $this->addFlash('warning', \sprintf('Сгенерированный пароль: %s — передайте его пользователю, повторно он не показывается.', $plain));
                    }
                }

                return $this->redirectToRoute('admin_users');
            } catch (\InvalidArgumentException $e) {
                $form->get('plainPassword')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('admin/users/form.html.twig', [
            'form' => $form,
            'user' => $user,
            'is_new' => true,
            'ldap_enabled' => $this->ldap->isEnabled(),
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request, #[CurrentUser] User $actor): Response
    {
        $form = $this->createForm(UserType::class, $user, ['is_new' => false, 'is_admin' => $user->isAdmin(), 'ldap_enabled' => $this->ldap->isEnabled()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $willBeAdmin = 'admin' === $form->get('role')->getData();
            $plain = (string) $form->get('plainPassword')->getData();
            try {
                if ($actor->getId() === $user->getId() && (!$willBeAdmin || !$user->isActive())) {
                    throw new \DomainException('Нельзя снять права администратора или заблокировать собственную учётную запись.');
                }
                $this->userManager->assertNotLastAdmin($user, $willBeAdmin, $user->isActive());
                $user->setAdmin($willBeAdmin);
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
