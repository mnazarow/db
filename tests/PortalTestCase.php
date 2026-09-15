<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Общая база функциональных тестов: пересоздаёт схему тестовой БД и загружает демо-данные один раз на класс.
 */
abstract class PortalTestCase extends WebTestCase
{
    protected static bool $schemaReady = false;
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        if (!static::$schemaReady) {
            $this->resetDatabase();
            static::$schemaReady = true;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // FrameworkBundle регистрирует обработчик исключений при каждой загрузке ядра; PHPUnit 11 считает
        // «лишний» обработчик признаком рискованного теста — снимаем его вручную.
        while (true) {
            $handler = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (\is_array($handler) && $handler[0] instanceof \Symfony\Component\ErrorHandler\ErrorHandler) {
                restore_exception_handler();
                continue;
            }
            break;
        }
    }

    protected function resetDatabase(): void
    {
        $em = $this->em();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $storage = static::getContainer()->get('App\Service\FileStorage')->getStorageDir();
        if (is_dir($storage)) {
            foreach (scandir($storage) ?: [] as $entry) {
                if (ctype_digit($entry)) {
                    static::getContainer()->get('App\Service\FileStorage')->deleteDocumentDir((int) $entry);
                }
            }
        }
        $app = new Application(static::$kernel);
        $app->setAutoExit(false);
        $out = new BufferedOutput();
        $code = $app->run(new ArrayInput(['command' => 'app:demo:load', '--no-events' => true, '--force' => true]), $out);
        self::assertSame(0, $code, $out->fetch());
        $em->clear();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function user(string $username): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByUsername($username);
        self::assertNotNull($user, 'Пользователь не найден: '.$username);

        return $user;
    }

    protected function section(string $name): Section
    {
        $section = static::getContainer()->get(SectionRepository::class)->findOneBy(['name' => $name]);
        self::assertNotNull($section, 'Раздел не найден: '.$name);

        return $section;
    }

    protected function document(string $code): Document
    {
        $doc = static::getContainer()->get(DocumentRepository::class)->findOneBy(['code' => $code]);
        self::assertNotNull($doc, 'Документ не найден: '.$code);

        return $doc;
    }

    protected function loginAs(string $username): void
    {
        $this->client->loginUser($this->user($username));
    }
}
