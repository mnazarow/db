<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\AiQuestionRepository;
use App\Repository\PushSubscriptionRepository;
use App\Service\Notification\PushNotifier;
use App\Service\Notification\WebPush;
use App\Repository\ApiKeyRepository;
use App\Repository\DocumentRepository;
use App\Service\Integration\WebhookNotifier;
use App\Service\Notification\TelegramNotifier;
use App\Repository\UserRepository;
use App\Repository\DocumentSubscriptionRepository;
use App\Service\Llm\DocumentDescriber;
use App\Service\Llm\LlmClient;
use App\Service\Llm\LlmException;
use App\Service\Llm\SemanticIndex;
use App\Service\Onlyoffice\OnlyofficeService;
use App\Service\PortalSettings;
use App\Service\Text\TextExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Панель администратора → Интеграции: ключи REST API (выгрузка документов в RAG), webhook об изменениях
 * и описание документов через внешнюю LLM.
 */
#[Route('/admin/integrations')]
final class IntegrationsController extends AbstractController
{
    private const DESCRIBE_NOW_LIMIT = 10;

    /** Сколько секунд «Описать документы без описания» работает в одном запросе (дальше — повтор или команда). */
    private const DESCRIBE_NOW_SECONDS = 25.0;

    public function __construct(
        private readonly ApiKeyRepository $keys,
        private readonly DocumentRepository $documents,
        private readonly EntityManagerInterface $em,
        private readonly PortalSettings $settings,
        private readonly WebhookNotifier $webhook,
        private readonly LlmClient $llm,
        private readonly DocumentDescriber $describer,
        private readonly TextExtractor $extractor,
        private readonly TelegramNotifier $telegram,
        private readonly UserRepository $users,
        private readonly DocumentSubscriptionRepository $subscriptions,
        private readonly LoggerInterface $auditLogger,
        private readonly OnlyofficeService $onlyoffice,
        private readonly SemanticIndex $semantic,
        private readonly AiQuestionRepository $aiQuestions,
        private readonly PushSubscriptionRepository $pushSubscriptions,
    ) {
    }

    /** Сколько секунд строить смысловой индекс по кнопке (остальное — командой app:ai:index по cron). */
    private const SEMANTIC_NOW_SECONDS = 25.0;

    #[Route('', name: 'admin_integrations', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $llm = $this->settings->llm();
        $webhook = $this->settings->webhook();

        return $this->render('admin/integrations/index.html.twig', [
            'keys' => $this->keys->findAllOrdered(),
            'new_token' => $request->getSession()->getFlashBag()->get('api_token')[0] ?? null,
            'api_base' => $this->generateUrl('api_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'extraction' => ['pdf' => $this->extractor->hasPdftotext(), 'extensions' => $this->extractor->supportedExtensions()],
            'webhook' => $webhook + ['secret_masked' => PortalSettings::mask($webhook['secret'])],
            'llm' => $llm + ['api_key_masked' => PortalSettings::mask($llm['api_key']), 'default_prompt' => PortalSettings::LLM_DEFAULT_PROMPT, 'is_default_prompt' => $llm['prompt'] === PortalSettings::LLM_DEFAULT_PROMPT],
            'assistant' => $this->settings->assistant() + ['api_key_masked' => PortalSettings::mask($this->settings->assistant()['own_api_key'])],
            'descriptions' => $this->documents->countDescriptions(),
            'embeddings' => $this->settings->embeddings() + ['api_key_masked' => PortalSettings::mask($this->settings->embeddings()['own_api_key'])],
            'semantic_status' => $this->semanticStatus(),
            'ai_stats' => $this->aiQuestions->stats(new \DateTimeImmutable('-30 days')),
            'describe_limit' => self::DESCRIBE_NOW_LIMIT,
            // Обсуждение, подписки и Telegram.
            'comments_enabled' => $this->settings->commentsEnabled(),
            'subscriptions_enabled' => $this->settings->subscriptionsEnabled(),
            'subscription_summary' => $this->subscriptions->summary(),
            'telegram' => $this->settings->telegram() + ['token_masked' => PortalSettings::mask($this->settings->telegram()['token']), 'linked' => \count($this->users->findWithTelegram()), 'default_api' => PortalSettings::TELEGRAM_DEFAULT_API_URL],
            'push' => $this->settings->push() + ['devices' => $this->pushSubscriptions->countAll(), 'users' => $this->pushSubscriptions->countUsers(), 'services' => PushNotifier::PUSH_SERVICE_HOSTS, 'https' => $request->isSecure() || \in_array($request->getHost(), ['localhost', '127.0.0.1'], true)],
            'onlyoffice' => $this->settings->onlyoffice() + [
                'secret_masked' => PortalSettings::mask($this->settings->onlyoffice()['secret']),
                'editable' => OnlyofficeService::EDITABLE,
                'view_only' => OnlyofficeService::VIEW_ONLY,
                'callback_example' => $this->generateUrl('onlyoffice_callback', ['token' => 'x.y.z'], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
        ]);
    }

    // ---- Ключи API ------------------------------------------------------------------------------------

    #[Route('/keys', name: 'admin_integrations_key_create', methods: ['POST'])]
    public function createKey(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $name = trim((string) $request->request->get('name', ''));
        if ('' === $name) {
            $this->addFlash('danger', 'Укажите название ключа (например, «Индексатор RAG»).');

            return $this->redirectToRoute('admin_integrations');
        }
        $token = ApiKey::generateToken();
        $key = new ApiKey($name, $token, $user);
        $key->setIncludeInternal((bool) $request->request->get('include_internal', false));
        $this->em->persist($key);
        $this->em->flush();
        $this->auditLogger->info('Создан ключ API', ['key' => $key->getId(), 'name' => $key->getName(), 'internal' => $key->isIncludeInternal(), 'by' => $user->getUsername()]);
        $this->addFlash('api_token', $token);
        $this->addFlash('success', \sprintf('Ключ «%s» создан. Скопируйте его сейчас — повторно он не показывается.', $key->getName()));

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'api-keys']);
    }

    #[Route('/keys/{id}/toggle', name: 'admin_integrations_key_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleKey(ApiKey $key, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $key->setEnabled(!$key->isEnabled());
        $this->em->flush();
        $this->auditLogger->info($key->isEnabled() ? 'Ключ API включён' : 'Ключ API отключён', ['key' => $key->getId(), 'name' => $key->getName(), 'by' => $user->getUsername()]);
        $this->addFlash('success', \sprintf('Ключ «%s» %s.', $key->getName(), $key->isEnabled() ? 'включён' : 'отключён'));

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'api-keys']);
    }

    #[Route('/keys/{id}/delete', name: 'admin_integrations_key_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteKey(ApiKey $key, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $name = $key->getName();
        $this->em->remove($key);
        $this->em->flush();
        $this->auditLogger->info('Удалён ключ API', ['name' => $name, 'by' => $user->getUsername()]);
        $this->addFlash('success', \sprintf('Ключ «%s» удалён.', $name));

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'api-keys']);
    }

    // ---- Обсуждение, подписки и Telegram ----------------------------------------------------------------

    #[Route('/discussion', name: 'admin_integrations_discussion', methods: ['POST'])]
    public function saveDiscussion(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $this->settings->setDiscussion($request->request->getBoolean('comments'), $request->request->getBoolean('subscriptions'), $user);
        $this->addFlash('success', 'Настройки обсуждения и подписок сохранены.');

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'discussion']);
    }

    #[Route('/telegram', name: 'admin_integrations_telegram', methods: ['POST'])]
    public function saveTelegram(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $token = trim((string) $request->request->get('token', ''));
        $this->settings->setTelegram([
            'enabled' => $request->request->getBoolean('enabled'),
            'token' => '' !== $token ? $token : null,
            'bot_name' => (string) $request->request->get('bot_name', ''),
            'api_url' => (string) $request->request->get('api_url', ''),
            'admin_chat' => (string) $request->request->get('admin_chat', ''),
        ], $user);
        if ($request->request->getBoolean('clear_token')) {
            $this->settings->set(PortalSettings::TELEGRAM_TOKEN, '', $user);
        }
        $this->addFlash('success', 'Настройки Telegram сохранены.');

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'telegram']);
    }

    // ---- Уведомления в браузере (Web Push) -----------------------------------------------------------------

    #[Route('/push', name: 'admin_integrations_push', methods: ['POST'])]
    public function savePush(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $enabled = $request->request->getBoolean('enabled');
        $subject = trim((string) $request->request->get('subject', ''));
        if ('' !== $subject && !preg_match('~^(mailto:)?[^@\s]+@[^@\s]+\.[^@\s]+$|^https://\S+$~u', $subject)) {
            $this->addFlash('error', 'Контакт администратора — адрес электронной почты или https-адрес.');

            return $this->redirectToRoute('admin_integrations', ['_fragment' => 'push']);
        }
        if ($enabled && '' === $this->settings->push()['public_key']) {
            $this->generatePushKeys($user);
        }
        $this->settings->set(PortalSettings::PUSH_SUBJECT, $subject, $user);
        $this->settings->set(PortalSettings::PUSH_HIDE_TEXT, $request->request->getBoolean('hide_text'), $user);
        $this->settings->set(PortalSettings::PUSH_ENABLED, $enabled, $user);
        $this->addFlash('success', $enabled ? 'Уведомления в браузере включены: сотрудники включают их на своих устройствах в «Подписки и уведомления».' : 'Уведомления в браузере выключены.');

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'push']);
    }

    /** Новые ключи VAPID: прежние подписки браузеров перестают действовать и удаляются. */
    #[Route('/push/reset', name: 'admin_integrations_push_reset', methods: ['POST'])]
    public function resetPush(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $this->generatePushKeys($user);
        $removed = $this->pushSubscriptions->deleteAll();
        $this->auditLogger->info('Ключи уведомлений в браузере заменены', ['subscriptions_removed' => $removed, 'by' => $user->getUsername()]);
        $this->addFlash('success', \sprintf('Ключи заменены, подписки устройств удалены (%d) — сотрудники включат уведомления заново.', $removed));

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'push']);
    }

    private function generatePushKeys(User $user): void
    {
        $keys = WebPush::generateKeys();
        $this->settings->set(PortalSettings::PUSH_PRIVATE_KEY, $keys['private'], $user);
        $this->settings->set(PortalSettings::PUSH_PUBLIC_KEY, $keys['public'], $user);
    }

    #[Route('/telegram/test', name: 'admin_integrations_telegram_test', methods: ['POST'])]
    public function testTelegram(Request $request): Response
    {
        $this->checkToken($request);
        $result = $this->telegram->check();
        $this->addFlash($result['ok'] ? 'success' : 'danger', $result['message']);

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'telegram']);
    }

    #[Route('/telegram/poll', name: 'admin_integrations_telegram_poll', methods: ['POST'])]
    public function pollTelegram(Request $request): Response
    {
        $this->checkToken($request);
        $stats = $this->telegram->poll();
        $type = match (true) {
            'busy' === $stats['skipped'] => 'info',
            null !== $stats['skipped'] => 'warning',
            [] !== $stats['messages'] => 'danger',
            default => 'success',
        };
        $this->addFlash($type, [] !== $stats['messages']
            ? implode(' ', $stats['messages'])
            : \sprintf('Разобрано сообщений: %d, привязано чатов: %d.', $stats['updates'], $stats['linked']));

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'telegram']);
    }

    // ---- Webhook --------------------------------------------------------------------------------------

    #[Route('/webhook', name: 'admin_integrations_webhook', methods: ['POST'])]
    public function saveWebhook(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $secret = (string) $request->request->get('secret', '');
        try {
            $this->settings->setWebhook((bool) $request->request->get('enabled', false), (string) $request->request->get('url', ''), '' !== $secret ? $secret : null, $user);
            if ((bool) $request->request->get('clear_secret', false)) {
                $this->settings->set(PortalSettings::WEBHOOK_SECRET, '', $user);
            }
            $this->addFlash('success', 'Настройки webhook сохранены.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'webhook']);
    }

    #[Route('/webhook/test', name: 'admin_integrations_webhook_test', methods: ['POST'])]
    public function testWebhook(Request $request): Response
    {
        $this->checkToken($request);
        $result = $this->webhook->sendTest();
        $this->addFlash($result['ok'] ? 'success' : 'danger', $result['message']);

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'webhook']);
    }

    // ---- LLM ------------------------------------------------------------------------------------------

    #[Route('/llm', name: 'admin_integrations_llm', methods: ['POST'])]
    public function saveLlm(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $apiKey = (string) $request->request->get('api_key', '');
        $prompt = trim((string) $request->request->get('prompt', ''));
        try {
            $this->settings->setLlm([
                'enabled' => (bool) $request->request->get('enabled', false),
                'base_url' => (string) $request->request->get('base_url', ''),
                'api_key' => '' !== $apiKey ? $apiKey : null, // пустое поле — оставить прежний ключ
                'model' => (string) $request->request->get('model', ''),
                'prompt' => $prompt === PortalSettings::LLM_DEFAULT_PROMPT ? '' : $prompt,
                'max_input_chars' => (int) $request->request->get('max_input_chars', PortalSettings::LLM_DEFAULT_MAX_INPUT_CHARS),
                'timeout' => (int) $request->request->get('timeout', PortalSettings::LLM_DEFAULT_TIMEOUT),
                'auto_describe' => (bool) $request->request->get('auto_describe', false),
                'temperature' => (string) $request->request->get('temperature', (string) PortalSettings::LLM_DEFAULT_TEMPERATURE),
            ], $user);
            if ((bool) $request->request->get('clear_api_key', false)) {
                $this->settings->set(PortalSettings::LLM_API_KEY, '', $user);
            }
            $this->addFlash('success', 'Настройки LLM сохранены.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'llm']);
    }

    #[Route('/llm/test', name: 'admin_integrations_llm_test', methods: ['POST'])]
    public function testLlm(Request $request): Response
    {
        $this->checkToken($request);
        if (!$this->llm->isEnabled()) {
            $this->addFlash('warning', 'Сначала включите описание через LLM и сохраните настройки.');
        } else {
            $result = $this->llm->test();
            $this->addFlash($result['ok'] ? 'success' : 'danger', $result['message']);
        }

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'llm']);
    }

    // ---- Помощник: модель для ответов (та же или на другом сервере) -----------------------------------------

    #[Route('/assistant', name: 'admin_integrations_assistant', methods: ['POST'])]
    public function saveAssistant(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $apiKey = (string) $request->request->get('api_key', '');
        try {
            $this->settings->setAssistant([
                'enabled' => (bool) $request->request->get('enabled', false),
                'own' => PortalSettings::ASSISTANT_OWN === $request->request->get('source'),
                'base_url' => (string) $request->request->get('base_url', ''),
                'model' => (string) $request->request->get('model', ''),
                'timeout' => (int) $request->request->get('timeout', PortalSettings::ASSISTANT_DEFAULT_TIMEOUT),
                'api_key' => '' !== $apiKey ? $apiKey : null, // пустое поле — оставить прежний ключ
            ], $user);
            if ((bool) $request->request->get('clear_api_key', false)) {
                $this->settings->set(PortalSettings::ASSISTANT_API_KEY, '', $user);
            }
            $this->addFlash('success', 'Настройки помощника сохранены.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'assistant']);
    }

    #[Route('/assistant/test', name: 'admin_integrations_assistant_test', methods: ['POST'])]
    public function testAssistant(Request $request): Response
    {
        $this->checkToken($request);
        $cfg = $this->settings->assistant();
        if (!$cfg['own'] && !$this->llm->isEnabled()) {
            $this->addFlash('warning', 'Помощник отвечает моделью описаний, а она выключена: включите LLM выше или укажите отдельный сервер модели.');
        } else {
            $result = $this->llm->test(true);
            $this->addFlash($result['ok'] ? 'success' : 'danger', $result['message']);
        }

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'assistant']);
    }

    /** Формирует описания для документов без описания — сразу, небольшой порцией (остальное — командой app:documents:describe). */
    #[Route('/llm/describe', name: 'admin_integrations_llm_describe', methods: ['POST'])]
    public function describeNow(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        if (!$this->describer->isEnabled()) {
            $this->addFlash('warning', 'Описание через LLM выключено.');

            return $this->redirectToRoute('admin_integrations', ['_fragment' => 'llm']);
        }
        $done = 0;
        $errors = [];
        $started = microtime(true);
        foreach ($this->documents->findForDescribing('missing', self::DESCRIBE_NOW_LIMIT) as $document) {
            try {
                $this->describer->describe($document, $user, $request->getClientIp());
                ++$done;
            } catch (LlmException $e) {
                $errors[] = $document->getTitle().': '.$e->getMessage();
                if (\count($errors) >= 3) {
                    break;
                }
            }
            if (microtime(true) - $started > self::DESCRIBE_NOW_SECONDS) {
                break; // не держим запрос дольше разумного — остальное по кнопке ещё раз или командой
            }
        }
        $left = $this->documents->countDescriptions()['without'];
        if ($done > 0) {
            $this->addFlash('success', \sprintf('Сформировано описаний: %d. Без описания осталось: %d%s.', $done, $left, $left > 0 ? ' — нажмите ещё раз или запустите app:documents:describe' : ''));
        } elseif ([] === $errors) {
            $this->addFlash('info', 'Документов без описания нет.');
        }
        foreach ($errors as $error) {
            $this->addFlash('danger', $error);
        }

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'llm']);
    }

    // ---- ONLYOFFICE / Р7-Офис --------------------------------------------------------------------------

    // ---- Поиск по смыслу (эмбеддинги) и журнал вопросов к ИИ --------------------------------------------

    #[Route('/semantic', name: 'admin_integrations_semantic', methods: ['POST'])]
    public function saveSemantic(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $apiKey = (string) $request->request->get('api_key', '');
        $before = $this->semantic->signature();
        try {
            $this->settings->setEmbeddings([
                'enabled' => (bool) $request->request->get('enabled', false),
                'model' => (string) $request->request->get('model', ''),
                'base_url' => (string) $request->request->get('base_url', ''),
                'api_key' => '' !== $apiKey ? $apiKey : null,
                'dimensions' => (int) $request->request->get('dimensions', 0),
                'min_score' => (string) $request->request->get('min_score', (string) PortalSettings::EMBED_DEFAULT_MIN_SCORE),
                'verified_min' => (string) $request->request->get('verified_min', (string) PortalSettings::EMBED_DEFAULT_VERIFIED_MIN),
                'log_days' => (int) $request->request->get('log_days', PortalSettings::AI_LOG_DEFAULT_DAYS),
            ], $user);
            if ((bool) $request->request->get('clear_api_key', false)) {
                $this->settings->set(PortalSettings::EMBED_API_KEY, '', $user);
            }
            $changed = $before !== $this->semantic->signature();
            $this->addFlash('success', 'Настройки поиска по смыслу сохранены.'.($changed && $this->settings->embeddings()['enabled']
                ? ' Модель изменилась — индекс построится заново (кнопка «Построить индекс сейчас» или команда app:ai:index раз в час).' : ''));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'semantic']);
    }

    #[Route('/semantic/test', name: 'admin_integrations_semantic_test', methods: ['POST'])]
    public function testSemantic(Request $request): Response
    {
        $this->checkToken($request);
        $result = $this->llm->testEmbeddings();
        $this->addFlash($result['ok'] ? 'success' : 'danger', $result['message']);

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'semantic']);
    }

    /** Строит смысловой индекс сразу — сколько успеет за SEMANTIC_NOW_SECONDS (остальное — cron). */
    #[Route('/semantic/build', name: 'admin_integrations_semantic_build', methods: ['POST'])]
    public function buildSemantic(Request $request): Response
    {
        $this->checkToken($request);
        if (!$this->semantic->isEnabled()) {
            $this->addFlash('warning', 'Сначала включите поиск по смыслу и сохраните настройки.');

            return $this->redirectToRoute('admin_integrations', ['_fragment' => 'semantic']);
        }
        $started = microtime(true);
        $done = $errors = 0;
        $lastError = null;
        foreach ($this->semantic->outdatedIds(200) as $id) {
            $document = $this->documents->find($id);
            if (null === $document) {
                continue;
            }
            $status = $this->semantic->index($document);
            'error' === $status ? ++$errors : ++$done;
            if ('error' === $status) {
                $lastError = $this->semantic->status()['last_error'];
                if ($errors >= 2 && 0 === $done) {
                    break;
                }
            }
            if (microtime(true) - $started > self::SEMANTIC_NOW_SECONDS) {
                break;
            }
        }
        $left = \count($this->semantic->outdatedIds(100000));
        if ($done > 0 || 0 === $errors) {
            $this->addFlash('success', \sprintf('Обработано документов: %d. Ждут индексации: %d%s.', $done, $left, $left > 0 ? ' — нажмите ещё раз или дождитесь app:ai:index (раз в час)' : ''));
        }
        if ($errors > 0) {
            $this->addFlash('danger', \sprintf('Не обработано документов: %d. %s', $errors, (string) $lastError));
        }

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'semantic']);
    }

    /** @return array<string, mixed>|null */
    private function semanticStatus(): ?array
    {
        try {
            return $this->semantic->status();
        } catch (\Throwable) {
            return null;
        }
    }

    #[Route('/onlyoffice', name: 'admin_integrations_onlyoffice', methods: ['POST'])]
    public function saveOnlyoffice(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $secret = (string) $request->request->get('secret', '');
        try {
            $this->settings->setOnlyoffice([
                'enabled' => (bool) $request->request->get('enabled', false),
                'url' => (string) $request->request->get('url', ''),
                'internal_url' => (string) $request->request->get('internal_url', ''),
                'portal_url' => (string) $request->request->get('portal_url', ''),
                'header' => (string) $request->request->get('header', ''),
                'view' => (bool) $request->request->get('view', false),
                'secret' => '' !== $secret ? $secret : null, // пустое поле — оставить прежний секрет
            ], $user);
            if ((bool) $request->request->get('clear_secret', false)) {
                $this->settings->set(PortalSettings::ONLYOFFICE_SECRET, '', $user);
            }
            $this->auditLogger->info('Изменены настройки редактора документов', ['by' => $user->getUsername(), 'enabled' => (bool) $request->request->get('enabled', false)]);
            $this->addFlash('success', 'Настройки редактора документов сохранены.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'onlyoffice']);
    }

    #[Route('/onlyoffice/test', name: 'admin_integrations_onlyoffice_test', methods: ['POST'])]
    public function testOnlyoffice(Request $request): Response
    {
        $this->checkToken($request);
        $result = $this->onlyoffice->test();
        $this->addFlash($result['ok'] ? 'success' : 'danger', $result['message']);

        return $this->redirectToRoute('admin_integrations', ['_fragment' => 'onlyoffice']);
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('integrations', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }
}
