<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\SystemJob;
use App\Repository\SystemJobRepository;
use App\Service\Monitoring\HealthReport;
use App\Service\PortalSettings;
use App\Tests\PortalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Мониторинг (1.7.0): /health/details и /metrics с токеном, учёт запусков регулярных заданий (команды
 * cron и отметки скриптов сервера), страница «Мониторинг» панели администратора, команда токена.
 */
final class MonitoringTest extends PortalTestCase
{
    /**
     * Демо-данные общие для всех тестов процесса: каждый тест идёт в транзакции, которая в конце откатывается
     * (кэш настроек портала сбрасывается вместе с ней).
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->em()->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em()->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->em()->clear();
        static::getContainer()->get('cache.app')->clear();
        parent::tearDown();
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }

    /** @param array<string, mixed> $args */
    private function console(array $args): array
    {
        $app = new Application(static::$kernel);
        $app->setAutoExit(false);
        $out = new BufferedOutput();
        // SHELL_VERBOSITY=-1 (phpunit.xml) глушит вывод команд — его читают проверки.
        $code = $app->run(new ArrayInput($args + ['--no-interaction' => true, '--verbose' => true]), $out);
        static::getContainer()->get('doctrine')->getManager()->clear();

        return [$code, $out->fetch()];
    }

    private function job(string $name): ?SystemJob
    {
        $this->em()->clear();

        return static::getContainer()->get(SystemJobRepository::class)->find($name);
    }

    /** @return array<string, mixed> */
    private function details(string $token): array
    {
        $this->client->request('GET', '/health/details', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testDetailsAndMetricsRequireToken(): void
    {
        $this->settings()->set(PortalSettings::MONITORING_TOKEN, '');
        $this->client->request('GET', '/health/details');
        self::assertResponseStatusCodeSame(404, 'без токена в настройках подробный мониторинг выключен');
        $this->client->request('GET', '/metrics');
        self::assertResponseStatusCodeSame(404);
        // Простая проверка работает всегда.
        $this->client->request('GET', '/health');
        self::assertResponseIsSuccessful();

        $token = $this->settings()->regenerateMonitoringToken();
        self::assertMatchesRegularExpression('/^dpm_[0-9a-f]{40}$/', $token);
        $this->client->request('GET', '/health/details');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/health/details', [], [], ['HTTP_AUTHORIZATION' => 'Bearer dpm_wrong']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/metrics?token=dpm_wrong');
        self::assertResponseStatusCodeSame(403);

        $report = $this->details($token);
        self::assertContains($report['status'], ['ok', 'warning', 'critical']);
        self::assertSame(static::getContainer()->getParameter('app.version'), $report['version']);
        $keys = array_column($report['checks'], 'key');
        foreach (['database', 'storage', 'job:expiry', 'job:backup', 'search_index', 'mail'] as $key) {
            self::assertContains($key, $keys, 'проверка '.$key);
        }
        self::assertSame('ok', $report['checks'][0]['status'], 'база данных доступна');
        self::assertArrayHasKey('documents_published', $report['metrics']);
        self::assertGreaterThan(0, $report['metrics']['documents_published']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        // Необязательные задания выключенных функций не ожидаются.
        $describe = array_values(array_filter($report['jobs'], static fn (array $j): bool => 'describe' === $j['name']))[0];
        self::assertFalse($describe['expected']);
        self::assertNotContains('job:describe', $keys);

        // Токен в параметре — для систем, где заголовок не задать.
        $this->client->request('GET', '/metrics?token='.$token);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/plain; version=0.0.4', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $text = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("docportal_up 1\n", $text);
        self::assertMatchesRegularExpression('/^docportal_info\{version="[^"]+"\} 1$/m', $text);
        self::assertMatchesRegularExpression('/^docportal_check_status\{check="database"\} 0$/m', $text);
        self::assertMatchesRegularExpression('/^docportal_documents_published \d+$/m', $text);

        // strict=1: код 503 только при неисправности.
        $this->client->request('GET', '/health/details?strict=1', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $strict = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertResponseStatusCodeSame(HealthReport::CRITICAL === $strict['status'] ? 503 : 200);

        // Гостевой доступ по IP закрыт — проверки мониторинга всё равно отвечают (они не страницы портала).
        $this->settings()->set(PortalSettings::GUEST_MODE, PortalSettings::GUEST_OFF);
        $this->client->request('GET', '/metrics', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
    }

    public function testJobsRecordedByCommandsAndScripts(): void
    {
        $token = $this->settings()->regenerateMonitoringToken();

        // Отметка скрипта сервера (backup.sh): начало и успешное завершение.
        [$code] = $this->console(['command' => 'app:system:job', 'name' => 'backup', '--status' => 'running']);
        self::assertSame(Command::SUCCESS, $code);
        self::assertSame(SystemJob::STATUS_RUNNING, $this->job('backup')?->getLastStatus());
        [$code] = $this->console(['command' => 'app:system:job', 'name' => 'backup', '--status' => 'ok', '--message' => "docportal-backup-1.tar.gz,\n 2 МБ", '--duration' => '12.5']);
        self::assertSame(Command::SUCCESS, $code);
        $job = $this->job('backup');
        self::assertSame(SystemJob::STATUS_OK, $job?->getLastStatus());
        self::assertSame('docportal-backup-1.tar.gz, 2 МБ', $job->getLastMessage(), 'перевод строки и лишние пробелы убраны');
        self::assertSame(12500, $job->getLastDurationMs());
        self::assertSame(1, $job->getRuns());
        self::assertNotNull($job->getLastSuccessAt());
        $check = array_values(array_filter($this->details($token)['checks'], static fn (array $c): bool => 'job:backup' === $c['key']))[0];
        self::assertSame('ok', $check['status']);

        // Ошибки подряд: предупреждение, с третьей — неисправность; успех сбрасывает счётчик.
        $this->console(['command' => 'app:system:job', 'name' => 'backup', '--status' => 'error', '--message' => 'Недостаточно места']);
        $check = array_values(array_filter($this->details($token)['checks'], static fn (array $c): bool => 'job:backup' === $c['key']))[0];
        self::assertSame('warning', $check['status']);
        self::assertStringContainsString('Недостаточно места', $check['message']);
        $this->console(['command' => 'app:system:job', 'name' => 'backup', '--status' => 'error']);
        $this->console(['command' => 'app:system:job', 'name' => 'backup', '--status' => 'error']);
        self::assertSame(3, $this->job('backup')?->getFailures());
        $report = $this->details($token);
        $check = array_values(array_filter($report['checks'], static fn (array $c): bool => 'job:backup' === $c['key']))[0];
        self::assertSame('critical', $check['status']);
        self::assertSame('critical', $report['status']);
        self::assertNotNull($this->job('backup')?->getLastSuccessAt(), 'время прошлого успеха сохраняется');
        $this->console(['command' => 'app:system:job', 'name' => 'backup', '--status' => 'ok']);
        self::assertSame(0, $this->job('backup')?->getFailures());

        // Неверные имя и статус.
        [$code, $out] = $this->console(['command' => 'app:system:job', 'name' => 'Плохое имя', '--status' => 'ok']);
        self::assertSame(Command::INVALID, $code, $out);
        [$code] = $this->console(['command' => 'app:system:job', 'name' => 'backup', '--status' => 'done']);
        self::assertSame(Command::INVALID, $code);

        // Внешнее хранилище и проверка восстановления ожидаются, только если уже запускались.
        self::assertNotContains('job:backup-verify', array_column($this->details($token)['checks'], 'key'));
        $this->console(['command' => 'app:system:job', 'name' => 'backup-verify', '--status' => 'warning', '--message' => 'мало места для пробной загрузки']);
        $check = array_values(array_filter($this->details($token)['checks'], static fn (array $c): bool => 'job:backup-verify' === $c['key']))[0];
        self::assertSame('warning', $check['status']);
        self::assertStringContainsString('мало места', $check['message']);

        // Команды cron отмечаются сами; пробный запуск (--dry-run) — нет.
        self::assertNull($this->job('expiry'));
        $this->console(['command' => 'app:documents:expiry', '--dry-run' => true]);
        self::assertNull($this->job('expiry'), 'пробный запуск не учитывается');
        [$code] = $this->console(['command' => 'app:documents:expiry']);
        self::assertSame(Command::SUCCESS, $code);
        $expiry = $this->job('expiry');
        self::assertSame(SystemJob::STATUS_OK, $expiry?->getLastStatus());
        self::assertNotNull($expiry->getLastDurationMs());
        self::assertNotNull($expiry->getLastStartedAt());

        // Prometheus: время успеха и число ошибок по заданиям.
        $this->client->request('GET', '/metrics', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $text = (string) $this->client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/^docportal_job_last_success_timestamp_seconds\{job="expiry"\} \d{10}$/m', $text);
        self::assertMatchesRegularExpression('/^docportal_job_failures\{job="backup"\} 0$/m', $text);
    }

    public function testAdminPageAndToken(): void
    {
        $this->loginAs('ivanov');
        $this->client->request('GET', '/admin/monitoring');
        self::assertResponseStatusCodeSame(403);

        $this->settings()->set(PortalSettings::MONITORING_TOKEN, '');
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/monitoring');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Регулярные задания', $crawler->text());
        self::assertStringContainsString('Резервная копия', $crawler->text());
        self::assertStringContainsString('Выключен', $crawler->filter('#token')->text());
        $csrf = $crawler->filter('#token input[name=_token]')->first()->attr('value');

        $this->client->request('POST', '/admin/monitoring/token', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/monitoring/token', ['_token' => $csrf]);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        $shown = (string) $crawler->filter('#new-monitoring-token')->attr('value');
        self::assertSame($this->settings()->monitoringToken(), $shown);
        self::assertStringContainsString('/health/details', $crawler->text());
        $crawler = $this->client->request('GET', '/admin/monitoring');
        self::assertSame(0, $crawler->filter('#new-monitoring-token')->count(), 'токен показывается один раз');
        self::assertStringNotContainsString($shown, $crawler->html());

        $this->client->request('POST', '/admin/monitoring/token/delete', ['_token' => $csrf]);
        self::assertResponseRedirects();
        self::assertSame('', $this->settings()->monitoringToken());
        $this->client->request('GET', '/health/details', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$shown]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTokenCommand(): void
    {
        $this->settings()->set(PortalSettings::MONITORING_TOKEN, '');
        [$code, $out] = $this->console(['command' => 'app:monitoring:token']);
        self::assertSame(Command::SUCCESS, $code);
        $first = trim($out);
        self::assertSame($this->settings()->monitoringToken(), $first, 'токен создан при первом запросе');
        [, $out] = $this->console(['command' => 'app:monitoring:token']);
        self::assertSame($first, trim($out), 'повторный вызов показывает тот же токен');
        [, $out] = $this->console(['command' => 'app:monitoring:token', '--regenerate' => true]);
        self::assertNotSame($first, trim($out));
        $this->console(['command' => 'app:monitoring:token', '--delete' => true]);
        self::assertSame('', $this->settings()->monitoringToken());
    }

    public function testPrometheusFormat(): void
    {
        $text = HealthReport::prometheus([
            'status' => 'warning',
            'version' => '1.7.0 "test"',
            'time' => 'x',
            'checks' => [
                ['key' => 'database', 'label' => 'База', 'status' => 'ok', 'message' => '', 'value' => null],
                ['key' => 'job:backup', 'label' => 'Копия', 'status' => 'warning', 'message' => '', 'value' => null],
            ],
            'jobs' => [
                ['name' => 'backup', 'last_success_at' => '2026-10-07T03:15:00+03:00', 'duration_ms' => 1500, 'failures' => 0],
                ['name' => 'telegram', 'last_success_at' => null, 'duration_ms' => null, 'failures' => 2],
            ],
            'metrics' => ['storage_free_bytes' => 1234, 'documents_published' => 5],
        ]);
        self::assertStringContainsString("docportal_up 1\n", $text);
        self::assertStringContainsString('docportal_info{version="1.7.0 \"test\""} 1', $text, 'кавычки в метке экранированы');
        self::assertStringContainsString("docportal_status 1\n", $text);
        self::assertStringContainsString('docportal_check_status{check="job:backup"} 1', $text);
        self::assertStringContainsString('docportal_job_last_success_timestamp_seconds{job="backup"} '.strtotime('2026-10-07T03:15:00+03:00'), $text);
        self::assertStringNotContainsString('timestamp_seconds{job="telegram"}', $text, 'без успешных запусков времени нет');
        self::assertStringContainsString('docportal_job_last_duration_seconds{job="backup"} 1.5', $text);
        self::assertStringContainsString('docportal_job_failures{job="telegram"} 2', $text);
        self::assertStringContainsString("# TYPE docportal_storage_free_bytes gauge\ndocportal_storage_free_bytes 1234\n", $text);
        self::assertStringEndsWith("\n", $text);
    }
}
