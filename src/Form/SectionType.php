<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Section;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Форма раздела. Список возможных родителей передаётся в параметре parent_choices
 * (уже без самого раздела и его потомков); allow_root — можно ли сделать раздел верхнего уровня.
 */
final class SectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Название', 'attr' => ['maxlength' => 128, 'autofocus' => true]])
            ->add('description', TextareaType::class, ['label' => 'Описание', 'required' => false, 'attr' => ['rows' => 4], 'help' => 'Коротко о том, какие документы хранятся в разделе.']);

        if ($options['show_parent']) {
            $builder->add('parent', EntityType::class, [
                'label' => 'Родительский раздел',
                'class' => Section::class,
                'choices' => $options['parent_choices'],
                'choice_label' => static fn (Section $s) => str_repeat('— ', $s->getDepth()).$s->getName(),
                'required' => !$options['allow_root'],
                'placeholder' => $options['allow_root'] ? '(верхний уровень)' : 'Выберите раздел',
                'help' => 'Раздел можно вложить в любой другой раздел любой глубины.',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Section::class,
            'parent_choices' => [],
            'allow_root' => false,
            'show_parent' => true,
        ]);
        $resolver->setAllowedTypes('parent_choices', 'array');
        $resolver->setAllowedTypes('allow_root', 'bool');
        $resolver->setAllowedTypes('show_parent', 'bool');
    }
}
