<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\PortalAuthenticator;
use App\Service\PortalSettings;
use App\Service\Sso\SamlService;
use App\Service\Sso\SsoException;
use App\Service\Sso\SsoUserProvisioner;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Вход через SAML 2.0: переход к провайдеру, приём ответа (ACS) и метаданные портала для регистрации у
 * провайдера.
 *
 * Номер запроса входа хранится в сеансе портала. Ответ провайдер присылает POST-запросом со своего сайта, а с
 * таким запросом браузер не отправляет cookie сеанса (SameSite=Lax), поэтому ACS отдаёт страницу, которая сразу
 * пересылает ответ на этот же портал (/login/saml/acs/finish) — уже со своего сайта, вместе с cookie сеанса.
 * Там ответ сверяется с номером запроса: чужой ответ (полученный злоумышленником для себя) в сеанс коллеги не
 * подставить. Работает и по http, и по https.
 */
final class SamlController extends AbstractController
{
    /** Ключ сеанса: номера запросов входа (последние несколько — на случай нескольких вкладок). */
    private const SESSION_KEY = 'saml_requests';
    private const MAX_PENDING = 5;
    private const REQUEST_TTL = 900;

    public function __construct(
        private readonly SamlService $saml,
        private readonly PortalSettings $settings,
        private readonly SsoUserProvisioner $provisioner,
        private readonly Security $security,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/login/saml', name: 'app_login_saml', methods: ['GET'])]
    public function start(Request $request): Response
    {
        if (!$this->saml->isEnabled()) {
            throw $this->createNotFoundException('Вход через SAML не настроен.');
        }
        try {
            $login = $this->saml->loginRequest();
        } catch (SsoException $e) {
            return $this->fail($request, $e);
        }
        $session = $request->getSession();
        $pending = array_filter((array) $session->get(self::SESSION_KEY, []), static fn ($at): bool => \is_int($at) && $at > time() - self::REQUEST_TTL);
        $pending[$login['request_id']] = time();
        $session->set(self::SESSION_KEY, \array_slice($pending, -self::MAX_PENDING, null, true));

        return $this->redirect($login['url']);
    }

    /** Ответ провайдера: пересылается на этот же портал — так браузер отправит с ним cookie сеанса. */
    #[Route('/login/saml/acs', name: 'app_saml_acs', methods: ['POST'])]
    public function acs(Request $request): Response
    {
        if (!$this->saml->isEnabled()) {
            throw $this->createNotFoundException('Вход через SAML не настроен.');
        }
        $response = $this->render('security/saml_post.html.twig', [
            'action' => $this->generateUrl('app_saml_acs_finish'),
            'saml_response' => (string) $request->request->get('SAMLResponse'),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    #[Route('/login/saml/acs/finish', name: 'app_saml_acs_finish', methods: ['POST'])]
    public function finish(Request $request): Response
    {
        if (!$this->saml->isEnabled()) {
            throw $this->createNotFoundException('Вход через SAML не настроен.');
        }
        $session = $request->getSession();
        $pending = (array) $session->get(self::SESSION_KEY, []);
        $requestId = null;
        try {
            // Номер запроса, на который отвечает провайдер, — из ответа; принимается, только если такой запрос
            // начат в этом сеансе и не устарел.
            $inResponseTo = self::inResponseTo((string) $request->request->get('SAMLResponse'));
            if (null !== $inResponseTo && isset($pending[$inResponseTo]) && $pending[$inResponseTo] > time() - self::REQUEST_TTL) {
                $requestId = $inResponseTo;
            } elseif (null !== $inResponseTo) {
                throw new SsoException('ответ провайдера относится к другому входу — нажмите кнопку входа ещё раз.');
            }
            $data = $this->saml->processResponse($request, $requestId);
            $user = $this->provisioner->provision($data['subject'], $data, $this->settings->saml(), 'SAML');
        } catch (SsoException $e) {
            return $this->fail($request, $e);
        } finally {
            if (null !== $requestId) {
                unset($pending[$requestId]);
                $session->set(self::SESSION_KEY, $pending);
            }
        }
        if (!$user->isActive()) {
            $this->addFlash('danger', 'Учётная запись заблокирована. Обратитесь к администратору портала.');

            return $this->redirectToRoute('app_login');
        }
        // Программный вход: события входа (журнал аудита, второй фактор) — как при обычном входе.
        $this->security->login($user, PortalAuthenticator::class, 'main');

        return $this->redirectToRoute('app_home');
    }

    /** Метаданные портала (SP): провайдеру — для регистрации портала. */
    #[Route('/saml/metadata', name: 'app_saml_metadata', methods: ['GET'])]
    public function metadata(): Response
    {
        try {
            $xml = $this->saml->metadataXml();
        } catch (\Throwable $e) {
            $this->auditLogger->warning('SAML: метаданные портала не сформированы', ['error' => $e->getMessage()]);

            return new Response('Метаданные недоступны: вход через SAML не настроен полностью (подробности — в журнале аудита).', 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new Response($xml, 200, ['Content-Type' => 'application/samlmetadata+xml; charset=UTF-8']);
    }

    /** Номер запроса (InResponseTo) из ответа провайдера — без проверки подписи, только чтобы найти запрос. */
    private static function inResponseTo(string $encoded): ?string
    {
        $xml = base64_decode($encoded, true);
        if (false === $xml || '' === $xml || \strlen($xml) > 2_000_000) {
            return null;
        }

        return preg_match('/<(?:[\w-]+:)?Response\b[^>]*\sInResponseTo\s*=\s*(["\'])([^"\'<>]{1,200})\1/', $xml, $m) ? html_entity_decode($m[2], \ENT_QUOTES | \ENT_XML1) : null;
    }

    private function fail(Request $request, SsoException $e): Response
    {
        $this->auditLogger->warning('SAML: вход не выполнен', ['error' => $e->getMessage(), 'ip' => $request->getClientIp()]);
        // Подробности проверки ответа (адресат, подпись, сроки) — в журнале аудита, сотруднику — общее сообщение.
        $this->addFlash('danger', $e->isTechnical()
            ? 'Вход через SAML не удался: провайдер прислал ответ, который портал не принял. Попробуйте ещё раз; если ошибка повторится, сообщите администратору портала (подробности — в журнале аудита).'
            : 'Вход через SAML не удался: '.$e->getMessage());

        return $this->redirectToRoute('app_login');
    }
}
