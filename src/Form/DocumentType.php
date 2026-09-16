<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Document;
use App\Entity\Section;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Форма документа: карточка + содержимое (файл или текст страницы).
 */
final class DocumentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = $options['is_new'];
        $builder
            ->add('title', TextType::class, ['label' => 'Название', 'attr' => ['maxlength' => 255, 'autofocus' => $isNew]])
            ->add('code', TextType::class, ['label' => 'Обозначение / номер', 'required' => false, 'attr' => ['maxlength' => 64, 'placeholder' => 'например, ИТ-РГ-003'], 'help' => 'Регистрационный номер или шифр документа (необязательно).'])
            ->add('section', EntityType::class, [
                'label' => 'Раздел',
                'class' => Section::class,
                'choices' => $options['section_choices'],
                'choice_label' => static fn (Section $s) => str_repeat('— ', $s->getDepth()).$s->getName(),
                'placeholder' => 'Выберите раздел',
            ])
            ->add('description', TextareaType::class, ['label' => 'Описание', 'required' => false, 'attr' => ['rows' => 3], 'help' => 'Кратко: о чём документ, для кого предназначен.'])
            ->add('tagsString', TextType::class, ['label' => 'Теги', 'required' => false, 'attr' => ['placeholder' => 'инструкция, охрана труда, 2026'], 'help' => 'Через запятую. Помогают в поиске.'])
            ->add('validUntil', DateType::class, [
                'label' => 'Актуален до',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Дата, до которой документ считается актуальным. Оставьте пустым для бессрочных документов.',
            ])
            ->add('public', CheckboxType::class, [
                'label' => 'Открытый документ — опубликованную версию можно читать без входа в портал',
                'required' => false,
                'help' => 'Снимите флажок для внутренних документов: они видны только сотрудникам после входа. Просмотр без входа регулируется в настройках панели администратора.',
            ]);

        if ($isNew) {
            $builder->add('type', ChoiceType::class, [
                'label' => 'Вид документа',
                'choices' => ['Файл (PDF, Word, Excel и др.)' => Document::TYPE_FILE, 'Страница (текст в редакторе)' => Document::TYPE_PAGE],
                'expanded' => true,
                'multiple' => false,
            ]);
        }

        $builder
            ->add('file', FileType::class, [
                'label' => $isNew ? 'Файл' : 'Новая версия файла',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => $options['accept']],
            ])
            ->add('content', HiddenType::class, ['mapped' => false, 'required' => false, 'attr' => ['data-page-content' => '1']])
            ->add('changeNote', TextType::class, [
                'label' => $isNew ? 'Комментарий к первой версии' : 'Что изменилось в новой версии',
                'mapped' => false,
                'required' => false,
                'attr' => ['maxlength' => 500, 'placeholder' => $isNew ? 'необязательно' : 'например: обновлены реквизиты, добавлен раздел 4'],
            ]);

        if ($isNew) {
            $builder->add('publish', CheckboxType::class, ['label' => 'Опубликовать сразу (иначе документ сохранится как черновик)', 'mapped' => false, 'required' => false, 'data' => true]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
            'section_choices' => [],
            'is_new' => true,
            'accept' => '',
        ]);
        $resolver->setAllowedTypes('section_choices', 'array');
        $resolver->setAllowedTypes('is_new', 'bool');
        $resolver->setAllowedTypes('accept', 'string');
    }
}
