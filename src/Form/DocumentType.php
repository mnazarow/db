<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Document;
use App\Entity\DocumentKind;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentKindRepository;
use App\Repository\UserRepository;
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
        // Помощник маркетолога у опубликованного документа меняет только содержимое (новую редакцию):
        // карточку, которую видят сотрудники, правит маркетолог.
        $locked = $options['card_locked'];
        $builder
            ->add('title', TextType::class, ['disabled' => $locked, 'label' => 'Название', 'attr' => ['maxlength' => 255, 'autofocus' => $isNew]])
            ->add('code', TextType::class, ['disabled' => $locked, 'label' => 'Обозначение / номер', 'required' => false, 'attr' => ['maxlength' => 64, 'placeholder' => 'например, ИТ-РГ-003'], 'help' => 'Регистрационный номер или шифр документа (необязательно).'])
            ->add('kind', EntityType::class, ['disabled' => $locked,
                'label' => 'Тип файла',
                'class' => DocumentKind::class,
                'choice_label' => 'name',
                'query_builder' => static fn (DocumentKindRepository $repository) => $repository->createQueryBuilder('k')
                    ->orderBy('k.position', 'ASC')->addOrderBy('k.name', 'ASC'),
                'required' => false,
                'placeholder' => 'Определить автоматически',
                'help' => 'Каталоги и брошюры, паспорта и руководства, опросные листы и ТКП, фотографии… Если не выбрать, портал определит тип по названию и расширению файла.',
            ])
            ->add('section', EntityType::class, ['disabled' => $locked,
                'label' => 'Раздел',
                'class' => Section::class,
                'choices' => $options['section_choices'],
                'choice_label' => static fn (Section $s) => str_repeat('— ', $s->getDepth()).$s->getName(),
                'placeholder' => 'Выберите раздел',
            ])
            ->add('description', TextareaType::class, ['disabled' => $locked, 'label' => 'Описание', 'required' => false, 'attr' => ['rows' => 3], 'help' => 'Кратко: о чём документ, для кого предназначен.'])
            ->add('tagsString', TextType::class, ['disabled' => $locked, 'label' => 'Теги', 'required' => false, 'attr' => ['placeholder' => 'инструкция, охрана труда, 2026'], 'help' => 'Через запятую. Помогают в поиске.'])
            ->add('validUntil', DateType::class, ['disabled' => $locked,
                'label' => 'Актуален до',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Дата, до которой документ считается актуальным. Оставьте пустым для бессрочных документов.',
            ])
            ->add('public', CheckboxType::class, ['disabled' => $locked,
                'label' => 'Открытый документ — опубликованную версию можно читать без входа в портал',
                'required' => false,
                'help' => 'Снимите флажок для внутренних документов: они видны только сотрудникам после входа. Просмотр без входа регулируется в настройках панели администратора.',
            ])
            ->add('restricted', CheckboxType::class, ['disabled' => $locked,
                'label' => 'Ограниченный доступ — документ виден только выбранным сотрудникам и подразделениям',
                'required' => false,
                'help' => 'Для кадровых, финансовых и других документов, которые не должны видеть все сотрудники. Модераторы раздела и администраторы видят документ всегда.',
            ])
            ->add('allowedUsers', EntityType::class, ['disabled' => $locked,
                'label' => 'Кому открыт документ',
                'class' => User::class,
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choice_label' => static fn (User $user): string => $user->getDisplayName().($user->getDepartment() ? ' — '.$user->getDepartment() : ''),
                'query_builder' => static fn (UserRepository $repository) => $repository->createQueryBuilder('u')
                    ->andWhere('u.active = true')->orderBy('u.displayName', 'ASC'),
                'attr' => ['size' => 8],
                'help' => 'Держите Ctrl (⌘), чтобы выбрать несколько.',
            ])
            ->add('allowedDepartmentsString', TextType::class, ['disabled' => $locked,
                'label' => 'Каким подразделениям открыт документ',
                'required' => false,
                'attr' => ['placeholder' => 'Отдел кадров, Бухгалтерия'],
                'help' => 'Через запятую, ровно как в карточках сотрудников.',
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
            $builder->add('publish', CheckboxType::class, $options['moderation']
                ? ['label' => 'Сразу отправить на модерацию (иначе — черновик, отправить можно позже из карточки)', 'mapped' => false, 'required' => false, 'data' => true, 'help' => 'Материал станет виден сотрудникам после одобрения маркетологом.']
                : ['label' => 'Опубликовать сразу (иначе документ сохранится как черновик)', 'mapped' => false, 'required' => false, 'data' => true]);
        } elseif ($options['moderation']) {
            $builder->add('submitModeration', CheckboxType::class, [
                'label' => 'Отправить на модерацию после сохранения',
                'mapped' => false,
                'required' => false,
                'data' => true,
                'help' => 'Новая редакция вступит в силу, когда её одобрит маркетолог; до этого сотрудники видят прежнюю.',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
            'section_choices' => [],
            'is_new' => true,
            'accept' => '',
            'moderation' => false,
            'card_locked' => false,
        ]);
        $resolver->setAllowedTypes('moderation', 'bool');
        $resolver->setAllowedTypes('card_locked', 'bool');
        $resolver->setAllowedTypes('section_choices', 'array');
        $resolver->setAllowedTypes('is_new', 'bool');
        $resolver->setAllowedTypes('accept', 'string');
    }
}
