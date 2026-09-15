<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Service\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ProfileController extends AbstractController
{
    #[Route('/profile/password', name: 'app_password_change', methods: ['GET', 'POST'])]
    public function password(Request $request, #[CurrentUser] User $user, UserManager $userManager): Response
    {
        if ($user->isLdap()) {
            $this->addFlash('info', 'Пароль доменной учётной записи меняется средствами домена (Active Directory), а не в портале.');

            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(ChangePasswordType::class, null, ['min_length' => $userManager->getPasswordMinLength()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if (!$userManager->isPasswordValid($user, (string) $data['currentPassword'])) {
                $form->get('currentPassword')->addError(new \Symfony\Component\Form\FormError('Текущий пароль указан неверно.'));
            } elseif ((string) $data['currentPassword'] === (string) $data['newPassword']) {
                $form->get('newPassword')->addError(new \Symfony\Component\Form\FormError('Новый пароль совпадает с текущим.'));
            } else {
                try {
                    $userManager->setPassword($user, (string) $data['newPassword'], false);
                    $userManager->save($user, $user);
                    $this->addFlash('success', 'Пароль изменён.');

                    return $this->redirectToRoute('app_home');
                } catch (\InvalidArgumentException $e) {
                    $form->get('newPassword')->addError(new \Symfony\Component\Form\FormError($e->getMessage()));
                }
            }
        }

        return $this->render('profile/password.html.twig', [
            'form' => $form,
            'must_change' => $user->isMustChangePassword(),
            'min_length' => $userManager->getPasswordMinLength(),
        ]);
    }
}
