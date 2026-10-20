<?php

declare(strict_types=1);

namespace App\Twig;

use App\Controller\AssistantController;
use App\Entity\User;
use App\Service\Assistant\ChatAssistant;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Кнопка «Помощник» в шаблонах: показывать ли её и настройки окна чата (адреса, ключи форм).
 */
final class AssistantExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly ChatAssistant $assistant,
        private readonly UrlGeneratorInterface $urls,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('assistant_available', [$this, 'available']),
            new TwigFunction('assistant_setup_needed', [$this, 'setupNeeded']),
            new TwigFunction('assistant_config', [$this, 'config']),
        ];
    }

    public function available(): bool
    {
        try {
            return $this->security->getUser() instanceof User && $this->assistant->isAvailable();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Модель помощнику не подключена — администратор видит кнопку «Помощник» с подсказкой, как её подключить
     * (сотрудники кнопку не видят, пока помощнику нечем отвечать).
     */
    public function setupNeeded(): bool
    {
        try {
            $user = $this->security->getUser();

            return $user instanceof User && $user->isAdmin() && $user->isActive() && !$this->assistant->isAvailable();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return [
            'ask' => $this->urls->generate('app_assistant_ask'),
            'list' => $this->urls->generate('app_assistant_conversations'),
            'conversation' => $this->urls->generate('app_assistant_conversation', ['id' => 0]),
            'delete' => $this->urls->generate('app_assistant_delete', ['id' => 0]),
            'page' => $this->urls->generate('app_assistant'),
            'token' => $this->csrf->getToken(AssistantController::CSRF)->getValue(),
            'rateToken' => $this->csrf->getToken('search_answer')->getValue(),
            'model' => $this->assistant->hasModel(),
        ];
    }
}
