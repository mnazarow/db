<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Форма пользователя в панели администратора.
 */
final class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, ['label' => 'Логин', 'attr' => ['maxlength' => 64, 'autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false'], 'help' => 'Для доменной учётной записи — логин в домене (sAMAccountName), без префикса домена.'])
            ->add('displayName', TextType::class, ['label' => 'Имя', 'attr' => ['maxlength' => 128, 'placeholder' => 'Фамилия Имя Отчество']])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false, 'attr' => ['maxlength' => 180], 'help' => 'Нужен для уведомлений о сроках актуальности документов.'])
            ->add('department', TextType::class, ['label' => 'Подразделение', 'required' => false, 'attr' => ['maxlength' => 128]]);

        if ($options['ldap_enabled']) {
            $builder->add('authSource', ChoiceType::class, [
                'label' => 'Источник учётной записи',
                'choices' => ['Локальная (пароль хранится в портале)' => User::SOURCE_LOCAL, 'Доменная (Active Directory / LDAP)' => User::SOURCE_LDAP],
                'expanded' => true,
                'multiple' => false,
            ]);
        }

        $builder
            ->add('role', ChoiceType::class, [
                'label' => 'Роль',
                'mapped' => false,
                'choices' => ['Пользователь' => 'user', 'Администратор' => 'admin'],
                'expanded' => true,
                'multiple' => false,
                'data' => $options['is_admin'] ? 'admin' : 'user',
            ])
            ->add('active', CheckboxType::class, ['label' => 'Активна (пользователь может входить)', 'required' => false])
            ->add('plainPassword', PasswordType::class, [
                'label' => $options['is_new'] ? 'Пароль' : 'Новый пароль',
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'help' => $options['is_new'] ? 'Только для локальных учётных записей. Пусто — пароль будет сгенерирован.' : 'Оставьте пустым, чтобы не менять. Для доменных учётных записей не используется.',
            ])
            ->add('mustChangePassword', CheckboxType::class, ['label' => 'Потребовать смену пароля при следующем входе', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => true,
            'is_admin' => false,
            'ldap_enabled' => false,
        ]);
        $resolver->setAllowedTypes('is_new', 'bool');
        $resolver->setAllowedTypes('is_admin', 'bool');
        $resolver->setAllowedTypes('ldap_enabled', 'bool');
    }
}
