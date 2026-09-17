<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentTemplate;
use App\Entity\Section;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentTemplate>
 */
final class DocumentTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentTemplate::class);
    }

    /** @return list<DocumentTemplate> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')->orderBy('t.active', 'DESC')->addOrderBy('t.name', 'ASC')->getQuery()->getResult();
    }

    /** @return list<DocumentTemplate> */
    public function findActive(): array
    {
        return $this->createQueryBuilder('t')->andWhere('t.active = true')->orderBy('t.name', 'ASC')->getQuery()->getResult();
    }

    /**
     * Шаблоны, предложенные для раздела: свои (закреплённые за ним или за разделом выше) и общие.
     *
     * @return list<DocumentTemplate>
     */
    public function findForSection(?Section $section): array
    {
        $pathIds = null !== $section ? $section->getPathIds() : [];
        $templates = $this->findActive();
        if ([] === $pathIds) {
            return $templates;
        }
        $own = $general = [];
        foreach ($templates as $template) {
            $sectionId = $template->getSection()?->getId();
            if (null === $sectionId) {
                $general[] = $template;
            } elseif (\in_array((int) $sectionId, $pathIds, true)) {
                $own[] = $template;
            }
        }

        return array_merge($own, $general);
    }
}
