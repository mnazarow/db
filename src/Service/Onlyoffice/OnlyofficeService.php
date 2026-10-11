<?php

declare(strict_types=1);

namespace App\Service\Onlyoffice;

use App\Entity\Document;
use App\Entity\DocumentVersion;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\UserRepository;
use App\Security\Access;
use App\Service\DocumentManager;
use App\Service\FileStorage;
use App\Service\Http\HttpTransportInterface;
use App\Service\PortalSettings;
use App\Service\PortalUrl;
use App\Service\Security\Jwt;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Редактирование и просмотр офисных файлов в ONLYOFFICE Docs / «Р7-Офис. Сервер документов» (API у них общий).
 *
 * Браузер загружает редактор с сервера документов; сервер документов скачивает файл по подписанной ссылке
 * портала (onlyoffice_file), а когда все закрыли редактор — присылает на onlyoffice_callback адрес
 * изменённого файла. Портал скачивает его только с сервера документов и сохраняет как новую версию
 * документа (с учётом согласования: при обязательном согласовании новая редакция ждёт решения).
 *
 * Если на сервере документов включён JWT (по умолчанию в ONLYOFFICE Docs 7.2+), в настройках портала
 * указывается тот же секрет: им подписывается конфигурация редактора и проверяются уведомления.
 */
final class OnlyofficeService
{
    /** Форматы, которые редактируются (остальные открываются только для просмотра). */
    public const EDITABLE = ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv'];

    /** Только просмотр: старые и шаблонные форматы. */
    public const VIEW_ONLY = ['doc', 'xls', 'ppt', 'docm', 'xlsm', 'pptm', 'dotx', 'xltx', 'potx', 'ppsx'];

    private const CELL = ['xls', 'xlsx', 'xlsm', 'xltx', 'ods', 'csv'];
    private const SLIDE = ['ppt', 'pptx', 'pptm', 'potx', 'ppsx', 'odp'];

    /** Ссылка на файл нужна серверу документов при открытии редактора — действует сутки. */
    private const FILE_LINK_TTL = 86400;

    /** Ссылка для уведомлений: сессия правки может длиться долго, итог приходит после её закрытия. */
    private const CALLBACK_LINK_TTL = 7 * 86400;

    /** Сколько помнить, что правка с этим ключом уже сохранена (повторное уведомление не создаст версию). */
    private const SAVED_TTL = 7 * 86400;

    public function __construct(
        private readonly PortalSettings $settings,
        private readonly PortalUrl $portalUrl,
        private readonly UrlGeneratorInterface $urls,
        private readonly DocumentManager $manager,
        private readonly DocumentRepository $documents,
        private readonly UserRepository $users,
        private readonly Access $access,
        private readonly HttpTransportInterface $http,
        private readonly FileStorage $storage,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->isOnlyofficeEnabled();
    }

    /** Открывать ли офисные файлы в редакторе для просмотра всем, кто видит документ. */
    public function viewEnabled(): bool
    {
        return $this->isEnabled() && $this->settings->onlyoffice()['view'];
    }

    /** Заголовок, в котором сервер документов присылает токен JWT (по умолчанию Authorization). */
    public function tokenHeader(): string
    {
        return $this->settings->onlyoffice()['header'];
    }

    public function apiUrl(): string
    {
        return $this->settings->onlyoffice()['url'].'/web-apps/apps/api/documents/api.js';
    }

    public static function isEditableFormat(string $extension): bool
    {
        return \in_array(mb_strtolower($extension), self::EDITABLE, true);
    }

    public static function isViewableFormat(string $extension): bool
    {
        return self::isEditableFormat($extension) || \in_array(mb_strtolower($extension), self::VIEW_ONLY, true);
    }

    public static function documentType(string $extension): string
    {
        $extension = mb_strtolower($extension);

        return match (true) {
            \in_array($extension, self::CELL, true) => 'cell',
            \in_array($extension, self::SLIDE, true) => 'slide',
            default => 'word',
        };
    }

    /**
     * Ключ документа для сервера документов: один на редакцию (все, кто открыл одну редакцию, правят её
     * вместе), новая версия — новый ключ. Допустимые символы — латиница, цифры, «.», «=», «_», «-».
     */
    public static function documentKey(DocumentVersion $version): string
    {
        $fingerprint = $version->getChecksum() ?? md5((string) $version->getStoredPath().'|'.$version->getCreatedAt()->format('U'));

        return \sprintf('dp%dv%d-%s', (int) $version->getDocument()->getId(), $version->getNumber(), substr($fingerprint, 0, 20));
    }

    /** Редакция, которую открывает редактор: последняя (в том числе ждущая согласования). */
    public function editableVersion(Document $document): ?DocumentVersion
    {
        $version = $document->getLatestVersion();

        return null !== $version && $version->isFile() && self::isEditableFormat($version->getExtension()) ? $version : null;
    }

    /** Почему нельзя редактировать онлайн (null — можно). */
    public function editBlockedReason(?User $user, Document $document): ?string
    {
        if (!$this->isEnabled() || null === $user || !$document->isFile() || !$this->access->canEditDocument($user, $document)) {
            return 'недоступно';
        }
        if (null === $this->editableVersion($document)) {
            return 'Этот формат файла в редакторе не правится — скачайте файл и загрузите новую версию.';
        }
        if ($document->isArchived()) {
            return 'Документ в архиве.';
        }
        try {
            $this->manager->assertVersionsEditable($document);
        } catch (\DomainException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function canEdit(?User $user, Document $document): bool
    {
        return null === $this->editBlockedReason($user, $document);
    }

    /** Версия для просмотра в редакторе (действующая редакция) или null. */
    public function viewableVersion(?User $user, Document $document): ?DocumentVersion
    {
        $version = $document->getCurrentVersion();
        // Гостям редактор не открывается: ссылки для сервера документов выдаются только сотрудникам.
        if (null === $user || null === $version || !$version->isFile() || !self::isViewableFormat($version->getExtension())) {
            return null;
        }
        $allowed = $this->viewEnabled() || ($this->isEnabled() && $this->access->canEditDocument($user, $document));

        return $allowed && $this->access->canViewDocument($user, $document) ? $version : null;
    }

    /**
     * Конфигурация DocsAPI.DocEditor (с подписью, если задан секрет JWT).
     *
     * @return array<string, mixed>
     */
    public function editorConfig(Document $document, DocumentVersion $version, User $user, bool $edit): array
    {
        $extension = $version->getExtension();
        $config = [
            'documentType' => self::documentType($extension),
            'document' => [
                'fileType' => $extension,
                'key' => self::documentKey($version),
                'title' => (string) $version->getOriginalName(),
                'url' => $this->serverUrl('onlyoffice_file', ['token' => $this->linkToken('file', $version, $user)]),
                'info' => [
                    'owner' => $document->getOwner()?->getDisplayName() ?? '',
                    'uploaded' => $version->getCreatedAt()->format('d.m.Y H:i'),
                    'folder' => $document->getSection()->getFullName(),
                ],
                'permissions' => [
                    'edit' => $edit,
                    'review' => $edit,
                    'comment' => $edit,
                    'fillForms' => $edit,
                    'modifyFilter' => $edit,
                    'modifyContentControl' => $edit,
                    'download' => true,
                    'print' => true,
                    'copy' => true,
                ],
            ],
            'editorConfig' => [
                'mode' => $edit ? 'edit' : 'view',
                'lang' => 'ru',
                'region' => 'ru-RU',
                'user' => ['id' => (string) $user->getId(), 'name' => $user->getDisplayName()],
                'customization' => [
                    'forcesave' => false,
                    'feedback' => false,
                    'goback' => ['url' => $this->portalUrl->absolute('app_document_show', ['id' => $document->getId()]), 'text' => 'К документу'],
                ],
            ],
            'width' => '100%',
            'height' => '100%',
        ];
        if ($edit) {
            $config['editorConfig']['callbackUrl'] = $this->serverUrl('onlyoffice_callback', ['token' => $this->linkToken('callback', $version, $user)]);
        }
        $config['token'] = Jwt::encode($config, $this->settings->onlyoffice()['secret']);

        return $config;
    }

    /**
     * Файл по ссылке для сервера документов.
     *
     * @return array{document: Document, version: DocumentVersion, path: string}|null
     */
    public function resolveFile(string $token): ?array
    {
        if (!$this->isEnabled()) {
            return null; // выключенная интеграция не отдаёт файлы и по выданным ранее ссылкам
        }
        try {
            $link = $this->parseLinkToken($token, 'file');
        } catch (\InvalidArgumentException) {
            return null;
        }
        $document = $this->documents->find($link['d']);
        $version = $document?->findVersion($link['v']);
        $user = $link['u'] > 0 ? $this->users->find($link['u']) : null;
        if (null === $document || null === $version || !$version->isFile() || null === $user || !$user->isActive()
            || !$this->access->canViewDocument($user, $document) || !$this->access->canViewVersion($user, $document, $version)) {
            return null;
        }
        $path = $this->storage->absolutePath($version);

        return null !== $path && is_file($path) ? ['document' => $document, 'version' => $version, 'path' => $path] : null;
    }

    /**
     * Уведомление сервера документов о ходе редактирования. Ответ {"error": 0} — принято; иначе сервер
     * документов сообщит пользователю, что сохранить не удалось.
     *
     * @return array{error: int}
     */
    public function handleCallback(string $token, string $rawBody, ?string $authorization): array
    {
        if (!$this->isEnabled()) {
            $this->logger->warning('ONLYOFFICE: уведомление при выключенной интеграции отклонено');

            return ['error' => 1];
        }
        try {
            $link = $this->parseLinkToken($token, 'callback');
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('ONLYOFFICE: уведомление с недействительной ссылкой', ['error' => $e->getMessage()]);

            return ['error' => 1];
        }
        $body = json_decode($rawBody, true);
        if (!\is_array($body)) {
            return ['error' => 1];
        }
        // Уведомлению верим только с подписью сервера документов (секрет обязателен для включения).
        $jwt = \is_string($body['token'] ?? null) ? $body['token'] : null;
        if (null === $jwt && null !== $authorization && 1 === preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $m)) {
            $jwt = $m[1];
        }
        if (null === $jwt) {
            $this->logger->warning('ONLYOFFICE: уведомление без токена JWT отклонено');

            return ['error' => 1];
        }
        try {
            $payload = Jwt::decode($jwt, $this->settings->onlyoffice()['secret']);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('ONLYOFFICE: токен уведомления не прошёл проверку', ['error' => $e->getMessage()]);

            return ['error' => 1];
        }
        // В заголовке сервер документов присылает {"payload": {...}}, в теле — само уведомление.
        $body = isset($payload['payload']) && \is_array($payload['payload']) ? $payload['payload'] : $payload;

        $status = (int) ($body['status'] ?? 0);
        $document = $this->documents->find($link['d']);
        $base = $document?->findVersion($link['v']);
        if (null === $document || null === $base) {
            $this->logger->warning('ONLYOFFICE: документ или версия удалены, правка не сохранена', ['document' => $link['d'], 'version' => $link['v'], 'status' => $status]);

            return ['error' => 2 === $status ? 1 : 0];
        }
        if (($body['key'] ?? null) !== self::documentKey($base)) {
            $this->logger->warning('ONLYOFFICE: ключ документа в уведомлении не совпадает', ['document' => $document->getId(), 'key' => $body['key'] ?? null]);

            return ['error' => 1];
        }
        if (\in_array($status, [3, 7], true)) {
            $this->logger->error('ONLYOFFICE: сервер документов не смог сохранить файл', ['document' => $document->getId(), 'status' => $status]);
        }
        // 1 — редактируют, 4 — закрыли без изменений, 6 — принудительное сохранение (итог придёт со статусом 2).
        if (2 !== $status) {
            return ['error' => 0];
        }

        return $this->save($document, $base, $body, $link['u']);
    }

    /** Проверка связи с сервером документов (/healthcheck) с сервера портала. */
    public function test(): array
    {
        $config = $this->settings->onlyoffice();
        $base = '' !== $config['internal_url'] ? $config['internal_url'] : $config['url'];
        if ('' === $base) {
            return ['ok' => false, 'message' => 'Укажите адрес сервера документов.'];
        }
        $response = $this->http->request('GET', $base.'/healthcheck', null, [], 10);
        if ($response->ok() && 'true' === trim($response->body)) {
            return ['ok' => true, 'message' => 'Сервер документов отвечает ('.$base.'/healthcheck → true, '.round($response->seconds * 1000).' мс). Откройте любой файл DOCX кнопкой «Редактировать онлайн», чтобы проверить редактор в браузере.'];
        }

        return ['ok' => false, 'message' => 'Сервер документов не ответил: '.$base.'/healthcheck — '.$response->describeError()];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{error: int}
     */
    private function save(Document $document, DocumentVersion $base, array $body, int $tokenUserId): array
    {
        $key = self::documentKey($base);
        $marker = $this->cache->getItem('onlyoffice_saved_'.sha1($key));
        if ($marker->isHit()) {
            return ['error' => 0]; // повторное уведомление о той же правке
        }
        $url = $this->trustedDownloadUrl((string) ($body['url'] ?? ''));
        if (null === $url) {
            $this->logger->warning('ONLYOFFICE: адрес файла не на сервере документов — не скачиваем', ['document' => $document->getId(), 'url' => (string) ($body['url'] ?? '')]);

            return ['error' => 1];
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'onlyoffice');
        $response = $this->http->download($url, $tmp, [], 120, $this->storage->getUploadMaxBytes());
        if (!$response->ok() || !is_file($tmp) || 0 === (int) filesize($tmp)) {
            @unlink($tmp);
            $this->logger->error('ONLYOFFICE: не удалось скачать изменённый файл', ['document' => $document->getId(), 'error' => $response->describeError()]);

            return ['error' => 1];
        }
        $latest = $document->getLatestVersion();
        if (null !== $latest && hash_file('sha256', $tmp) === $latest->getChecksum()) {
            @unlink($tmp); // файл не изменился
            $this->remember($marker);

            return ['error' => 0];
        }
        $actor = $this->actor($body, $tokenUserId, $document);
        if (null === $actor) {
            @unlink($tmp);
            $this->logger->warning('ONLYOFFICE: нет пользователя с правом изменять документ — правка не сохранена', ['document' => $document->getId()]);

            return ['error' => 1];
        }
        $name = (string) $base->getOriginalName();
        $fileType = mb_strtolower((string) ($body['filetype'] ?? ''));
        if ('' !== $fileType && $fileType !== $base->getExtension() && 1 === preg_match('/^[a-z0-9]{2,5}$/', $fileType)) {
            $name = pathinfo($name, \PATHINFO_FILENAME).'.'.$fileType;
        }
        $editors = $this->editorNames($body);
        $note = 'Изменено в редакторе онлайн'.(\count($editors) > 1 ? ' (совместно: '.implode(', ', $editors).')' : '').'.';
        if (null !== $latest && $latest->getNumber() !== $base->getNumber()) {
            $note .= \sprintf(' Правка сделана в версии %d; пока шло редактирование, загружена версия %d.', $base->getNumber(), $latest->getNumber());
        }
        try {
            $version = $this->manager->addFileVersionFromPath($document, $tmp, $name, $note, $actor, true);
        } catch (\DomainException|\InvalidArgumentException|\RuntimeException $e) {
            @unlink($tmp);
            $this->logger->error('ONLYOFFICE: правка не сохранена', ['document' => $document->getId(), 'error' => $e->getMessage()]);

            return ['error' => 1];
        }
        $this->remember($marker);
        $this->auditLogger->info('Сохранена правка из редактора онлайн', ['document' => $document->getId(), 'version' => $version->getNumber(), 'by' => $actor->getUsername(), 'editors' => $editors]);

        return ['error' => 0];
    }

    private function remember(\Psr\Cache\CacheItemInterface $marker): void
    {
        $marker->set(true);
        $marker->expiresAfter(self::SAVED_TTL);
        $this->cache->save($marker);
    }

    /**
     * Автор новой версии: первый из редактировавших, у кого есть право изменять документ, иначе тот,
     * кто открыл редактор.
     *
     * @param array<string, mixed> $body
     */
    private function actor(array $body, int $tokenUserId, Document $document): ?User
    {
        $ids = [];
        foreach ((array) ($body['actions'] ?? []) as $action) {
            if (\is_array($action) && isset($action['userid']) && ctype_digit((string) $action['userid'])) {
                $ids[] = (int) $action['userid'];
            }
        }
        foreach ((array) ($body['users'] ?? []) as $id) {
            if (ctype_digit((string) $id)) {
                $ids[] = (int) $id;
            }
        }
        $ids[] = $tokenUserId;
        foreach (array_unique($ids) as $id) {
            $user = $id > 0 ? $this->users->find($id) : null;
            if (null !== $user && $user->isActive() && $this->access->canEditDocument($user, $document)) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function editorNames(array $body): array
    {
        $names = [];
        foreach ((array) ($body['users'] ?? []) as $id) {
            $user = ctype_digit((string) $id) ? $this->users->find((int) $id) : null;
            if (null !== $user) {
                $names[] = $user->getDisplayName();
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Скачиваем только с сервера документов (его внешнего или внутреннего адреса); внешний адрес при
     * заданном внутреннем заменяется внутренним. Адреса сравниваются по разобранным частям: схема, имя,
     * порт (с учётом порта по умолчанию) и путь — целыми сегментами; итоговый адрес проверяется ещё раз.
     */
    private function trustedDownloadUrl(string $url): ?string
    {
        $config = $this->settings->onlyoffice();
        $target = self::urlParts($url);
        if (null === $target) {
            return null;
        }
        $internal = '' !== $config['internal_url'] ? self::urlParts($config['internal_url']) : null;
        $public = self::urlParts($config['url']);
        if (null !== $internal && self::underBase($target, $internal)) {
            return $url;
        }
        if (null === $public || !self::underBase($target, $public)) {
            return null;
        }
        if (null === $internal) {
            return $url;
        }
        // Внешний адрес → внутренний: путь после пути внешнего адреса, запрос — как есть.
        $rest = substr($target['path'], \strlen($public['path']));
        $rebuilt = $internal['scheme'].'://'.$internal['host'].(isset($internal['port']) ? ':'.$internal['port'] : '')
            .rtrim($internal['path'], '/').'/'.ltrim($rest, '/').(null !== $target['query'] ? '?'.$target['query'] : '');
        $check = self::urlParts($rebuilt);

        return null !== $check && self::underBase($check, $internal) ? $rebuilt : null;
    }

    /**
     * Части адреса http(s) без имени пользователя; путь нормализован (без «.» и «..», с «/» в начале).
     *
     * @return array{scheme: string, host: string, port: ?int, effective_port: int, path: string, query: ?string}|null
     */
    private static function urlParts(string $url): ?array
    {
        if ('' === $url || 1 === preg_match('/[\s\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $segments = [];
        foreach (explode('/', (string) ($parts['path'] ?? '')) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment || '..' === rawurldecode($segment) || '.' === rawurldecode($segment)) {
                return null;
            }
            $segments[] = $segment;
        }

        return [
            'scheme' => $scheme,
            'host' => strtolower($parts['host']),
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'effective_port' => (int) ($parts['port'] ?? ('https' === $scheme ? 443 : 80)),
            'path' => '/'.implode('/', $segments),
            'query' => isset($parts['query']) ? (string) $parts['query'] : null,
        ];
    }

    /**
     * Адрес на том же сервере (схема, имя, порт) и внутри пути базового адреса (целыми сегментами).
     *
     * @param array{scheme: string, host: string, port: ?int, effective_port: int, path: string, query: ?string} $target
     * @param array{scheme: string, host: string, port: ?int, effective_port: int, path: string, query: ?string} $base
     */
    private static function underBase(array $target, array $base): bool
    {
        if ($target['scheme'] !== $base['scheme'] || $target['host'] !== $base['host'] || $target['effective_port'] !== $base['effective_port']) {
            return false;
        }
        $prefix = rtrim($base['path'], '/');

        return '' === $prefix || $target['path'] === $prefix || str_starts_with($target['path'], $prefix.'/');
    }

    /** Адрес портала для сервера документов: «Адрес портала для сервера документов» или обычный. */
    private function serverUrl(string $route, array $parameters): string
    {
        $base = $this->settings->onlyoffice()['portal_url'];
        if ('' === $base) {
            return $this->portalUrl->absolute($route, $parameters);
        }

        return $base.$this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    private function linkToken(string $purpose, DocumentVersion $version, User $user): string
    {
        return Jwt::encode([
            'p' => $purpose,
            'd' => (int) $version->getDocument()->getId(),
            'v' => $version->getNumber(),
            'u' => (int) $user->getId(),
            'exp' => time() + ('file' === $purpose ? self::FILE_LINK_TTL : self::CALLBACK_LINK_TTL),
        ], $this->settings->onlyofficeLinkKey());
    }

    /**
     * Ссылки подписаны своим ключом портала (не секретом приложения): он меняется при выключении
     * интеграции, смене сервера или секрета — выданные ранее ссылки перестают действовать.
     *
     * @return array{p: string, d: int, v: int, u: int}
     */
    private function parseLinkToken(string $token, string $purpose): array
    {
        $payload = Jwt::decode($token, $this->settings->onlyofficeLinkKey(), 0, true);
        if (($payload['p'] ?? null) !== $purpose || !isset($payload['d'], $payload['v'], $payload['u'])
            || !\is_int($payload['d']) || !\is_int($payload['v']) || !\is_int($payload['u'])) {
            throw new \InvalidArgumentException('Ссылка не для этого действия.');
        }

        return ['p' => $purpose, 'd' => $payload['d'], 'v' => $payload['v'], 'u' => $payload['u']];
    }
}
