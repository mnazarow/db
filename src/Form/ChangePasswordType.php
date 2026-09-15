<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Текущий пароль',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new Assert\NotBlank(message: 'Введите текущий пароль.')],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Пароли не совпадают.',
                'first_options' => ['label' => 'Новый пароль', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Новый пароль ещё раз', 'attr' => ['autocomplete' => 'new-password']],
                'constraints' => [
                    new Assert\NotBlank(message: 'Введите новый пароль.'),
                    new Assert\Length(min: $options['min_length'], minMessage: 'Пароль должен содержать не менее {{ limit }} символов.', max: 4096),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['min_length' => 8]);
        $resolver->setAllowedTypes('min_length', 'int');
    }
}
