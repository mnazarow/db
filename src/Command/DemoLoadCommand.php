<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Document;
use App\Entity\DocumentComment;
use App\Entity\DocumentLink;
use App\Entity\DocumentTemplate;
use App\Entity\DocumentSubscription;
use App\Entity\DocumentEvent;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use App\Service\AcknowledgementService;
use App\Service\DocumentManager;
use App\Service\SectionManager;
use App\Service\UserManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Загрузка демонстрационных данных: разделы, пользователи, документы с версиями и историей событий.
 * Нужна для знакомства с порталом, скриншотов и тестов. Не запускайте на боевом сервере.
 */
#[AsCommand(name: 'app:demo:load', description: 'Загрузить демонстрационные данные (разделы, пользователи, документы, статистика)')]
final class DemoLoadCommand extends Command
{
    private const PASSWORD = 'Demo12345';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly SectionRepository $sections,
        private readonly UserRepository $users,
        private readonly UserManager $userManager,
        private readonly SectionManager $sectionManager,
        private readonly DocumentManager $documentManager,
        private readonly AcknowledgementService $acknowledgements,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Удалить существующие разделы и документы перед загрузкой')
            ->addOption('no-events', null, InputOption::VALUE_NONE, 'Не генерировать историю просмотров и скачиваний');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->sections->countAll() > 0) {
            if (!$input->getOption('force')) {
                $io->error('В базе уже есть разделы. Запустите с --force, чтобы удалить существующие разделы и документы (пользователи сохранятся).');

                return Command::FAILURE;
            }
            $this->wipe();
            $io->note('Существующие разделы, документы и события удалены.');
        }

        // --- Пользователи ---------------------------------------------------------------
        $admin = $this->ensureUser('admin', 'Администратор портала', true, 'admin@example.ru', 'ИТ-отдел');
        $ivanov = $this->ensureUser('ivanov', 'Иванов Игорь Петрович', false, 'ivanov@example.ru', 'Служба качества');
        $petrova = $this->ensureUser('petrova', 'Петрова Мария Сергеевна', false, 'petrova@example.ru', 'ИТ-отдел');
        $sidorov = $this->ensureUser('sidorov', 'Сидоров Алексей Николаевич', false, 'sidorov@example.ru', 'Производство');
        $kuznetsova = $this->ensureUser('kuznetsova', 'Кузнецова Елена Викторовна', false, 'kuznetsova@example.ru', 'Отдел кадров');
        $smirnov = $this->ensureUser('smirnov', 'Смирнов Дмитрий Олегович', false, 'smirnov@example.ru', 'Отдел продаж');
        $readers = [$smirnov, $sidorov, $kuznetsova, $ivanov, $petrova, $smirnov];

        // --- Разделы ----------------------------------------------------------------------
        $common = $this->sectionManager->create('Общие документы', null, 'Регламенты, политики и инструкции, обязательные для всех сотрудников.', $admin);
        $policies = $this->sectionManager->create('Регламенты и политики', $common, 'Внутренние нормативные документы компании.', $admin);
        $security = $this->sectionManager->create('Информационная безопасность', $policies, 'Политики ИБ, правила работы с паролями и данными.', $admin);
        $instructions = $this->sectionManager->create('Инструкции', $common, 'Пошаговые инструкции для сотрудников.', $admin);
        $it = $this->sectionManager->create('ИТ', $instructions, 'Рабочее место, почта, телефония, VPN.', $admin);
        $workplace = $this->sectionManager->create('Рабочее место', $it, null, $admin);
        $mail = $this->sectionManager->create('Почта и телефония', $it, null, $admin);
        $safety = $this->sectionManager->create('Охрана труда', $instructions, 'Инструктажи и правила безопасности.', $admin);
        $production = $this->sectionManager->create('Производство', null, 'Технологическая документация производственных участков.', $admin);
        $techcards = $this->sectionManager->create('Технологические карты', $production, null, $admin);
        $drawings = $this->sectionManager->create('Чертежи и схемы', $production, null, $admin);
        $hr = $this->sectionManager->create('Кадры', null, 'Кадровые документы и шаблоны.', $admin);
        $orders = $this->sectionManager->create('Приказы', $hr, null, $admin);
        $templates = $this->sectionManager->create('Шаблоны заявлений', $hr, null, $admin);

        $this->sectionManager->addModerator($common, $ivanov, $admin);
        $this->sectionManager->addModerator($it, $petrova, $admin);
        $this->sectionManager->addModerator($production, $sidorov, $admin);
        $this->sectionManager->addModerator($hr, $kuznetsova, $admin);
        $io->writeln('Разделы и модераторы созданы.');

        // --- Документы -------------------------------------------------------------------
        $tmp = sys_get_temp_dir().'/docportal-demo-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0700, true);
        $today = new \DateTimeImmutable('today');
        $docs = [];

        $docs[] = $this->fileDoc($policies, $ivanov, 'Положение о документообороте', 'ПОЛ-001', 'Порядок создания, согласования, хранения и актуализации внутренних документов компании.', ['документооборот', 'регламент'], $today->modify('+14 months'), $this->pdf($tmp, 'POL-001', 'Polozhenie o dokumentooborote'), true, [
            ['Актуализированы сроки согласования', $this->pdf($tmp, 'POL-001 v2', 'Polozhenie o dokumentooborote (rev. 2)')],
        ]);
        $docs[] = $this->fileDoc($policies, $ivanov, 'Политика управления качеством', 'ПОЛ-002', 'Цели и принципы системы менеджмента качества.', ['качество', 'смк'], $today->modify('+40 days'), $this->pdf($tmp, 'POL-002', 'Politika kachestva'), true);
        $docs[] = $this->fileDoc($policies, $ivanov, 'Правила внутреннего трудового распорядка', 'ПОЛ-003', null, ['птр', 'кадры'], $today->modify('-12 days'), $this->docx($tmp, 'PVTR', [
            'Правила внутреннего трудового распорядка',
            '1. Рабочее время: начало в 9:00, окончание в 18:00, перерыв на обед — один час в промежутке с 12:00 до 15:00.',
            '2. Пропускной режим: вход в здание по электронному пропуску, гостей сопровождает принимающий сотрудник.',
            '3. Для вновь принятых работников устанавливается испытательный срок продолжительностью до трёх месяцев.',
            '4. Работник обязан сообщить непосредственному руководителю о невыходе на работу не позднее первого часа рабочего дня.',
            '5. Дисциплинарные взыскания применяются в порядке, предусмотренном Трудовым кодексом Российской Федерации.',
        ]), true);
        $docs[] = $this->pageDoc($security, $petrova, 'Политика паролей', 'ИБ-001', 'Требования к паролям учётных записей сотрудников.', ['пароли', 'безопасность'], $today->modify('+9 months'), <<<'HTML'
<h2>Требования к паролям</h2>
<p>Пароль учётной записи должен содержать <strong>не менее 12 символов</strong> и включать буквы разного регистра, цифры и специальные символы.</p>
<ul><li>Пароль меняется не реже одного раза в 180 дней.</li><li>Запрещено использовать пароль от рабочей учётной записи во внешних сервисах.</li><li>Запрещено передавать пароль коллегам, записывать его на бумаге и в незащищённых файлах.</li></ul>
<h2>Хранение паролей</h2>
<p>Для хранения служебных паролей используется корпоративный менеджер паролей. Доступ выдаёт ИТ-отдел по заявке руководителя.</p>
<h2>Компрометация</h2>
<p>При подозрении на компрометацию пароля сотрудник обязан немедленно сменить пароль и сообщить в ИТ-отдел по телефону 1234.</p>
HTML, true, [
            ['Добавлен раздел о менеджере паролей', null],
            ['Уточнён срок смены пароля: 180 дней', null],
        ]);
        // Внутренние документы (только после входа): правила конфиденциальности и приказ по отпускам.
        $docs[] = $this->internal($this->pageDoc($security, $petrova, 'Правила работы с конфиденциальной информацией', 'ИБ-002', null, ['конфиденциальность'], $today->modify('+5 days'), '<h2>Общие положения</h2><p>Конфиденциальная информация передаётся только по защищённым каналам. Печать документов с грифом «Конфиденциально» разрешена только на принтерах с авторизацией.</p><h2>Ответственность</h2><p>Нарушение правил влечёт дисциплинарную ответственность в соответствии с трудовым законодательством.</p>', true));
        $docs[] = $this->fileDoc($security, $petrova, 'Регламент резервного копирования', 'ИБ-003', 'Периодичность, глубина хранения и порядок восстановления резервных копий.', ['резервное копирование', 'ит'], $today->modify('+11 months'), $this->txt($tmp, 'backup-reglament.txt', "Регламент резервного копирования\n\n1. Полная копия — еженедельно, инкрементальная — ежедневно.\n2. Глубина хранения — 90 дней.\n3. Проверка восстановления — ежеквартально."), false);

        $docs[] = $this->pageDoc($workplace, $petrova, 'Как настроить рабочее место нового сотрудника', 'ИТ-РМ-001', 'Чек-лист подготовки компьютера, учётных записей и доступов.', ['онбординг', 'рабочее место'], null, '<h2>Чек-лист</h2><ol><li>Создать доменную учётную запись и почтовый ящик.</li><li>Установить корпоративный образ ОС, антивирус и VPN-клиент.</li><li>Выдать доступ к файловому серверу и 1С по заявке руководителя.</li><li>Провести инструктаж по политике паролей.</li></ol><p>Срок выполнения — не позднее первого рабочего дня сотрудника.</p>', true);
        $docs[] = $this->fileDoc($workplace, $petrova, 'Инструкция по подключению к VPN', 'ИТ-РМ-002', 'Пошаговая настройка VPN-клиента на Windows и macOS.', ['vpn', 'удалённая работа'], $today->modify('+7 months'), $this->pdf($tmp, 'IT-RM-002', 'VPN connection guide'), true, [
            ['Добавлен раздел для macOS', $this->pdf($tmp, 'IT-RM-002 v2', 'VPN connection guide (macOS added)')],
            ['Обновлены адреса серверов', $this->pdf($tmp, 'IT-RM-002 v3', 'VPN connection guide (servers updated)')],
        ]);
        $docs[] = $this->fileDoc($workplace, $petrova, 'Схема сетевых розеток офиса', 'ИТ-РМ-003', null, ['сеть', 'офис'], $today->modify('+3 years'), $this->png($tmp, 'office-network.png'), true);
        $docs[] = $this->fileDoc($mail, $petrova, 'Настройка корпоративной почты в Outlook', 'ИТ-ПТ-001', 'Параметры серверов, настройка подписи и автоответа.', ['outlook', 'почта'], $today->modify('+2 months'), $this->docx($tmp, 'Outlook-setup', [
            'Настройка корпоративной почты в Outlook',
            'Сервер входящей почты: imap.vodokomfort.local, порт 993, шифрование SSL/TLS.',
            'Сервер исходящей почты: smtp.vodokomfort.local, порт 587, обязательная проверка подлинности.',
            'Логин — доменная учётная запись, пароль тот же, что и при входе в компьютер. Двухфакторная проверка включается в личном кабинете.',
            'Подпись в письме оформляется по образцу: фамилия и имя, должность, подразделение, рабочий телефон.',
            'Автоответ на время отпуска включается в меню «Файл» → «Автоответы» с указанием даты возвращения и контактов замещающего сотрудника.',
        ]), true);
        $docs[] = $this->pageDoc($mail, $petrova, 'Телефонный справочник: короткие номера', 'ИТ-ПТ-002', null, ['телефония'], $today->modify('-3 days'), '<h2>Короткие номера служб</h2><table><tr><th>Служба</th><th>Номер</th></tr><tr><td>ИТ-поддержка</td><td>1234</td></tr><tr><td>Приёмная</td><td>1000</td></tr><tr><td>Охрана</td><td>1111</td></tr><tr><td>Склад</td><td>1500</td></tr></table>', true);

        $docs[] = $this->fileDoc($safety, $ivanov, 'Инструкция по охране труда для офисных работников', 'ОТ-001', 'Вводный инструктаж, требования безопасности на рабочем месте.', ['охрана труда', 'инструктаж'], $today->modify('+20 days'), $this->pdf($tmp, 'OT-001', 'Okhrana truda - ofis'), true);
        $docs[] = $this->fileDoc($safety, $ivanov, 'Инструкция по пожарной безопасности', 'ОТ-002', null, ['пожарная безопасность'], $today->modify('-45 days'), $this->pdf($tmp, 'OT-002', 'Pozharnaya bezopasnost'), true);
        $docs[] = $this->fileDoc($safety, $ivanov, 'План эвакуации (2-й этаж)', 'ОТ-003', null, ['эвакуация'], null, $this->png($tmp, 'evacuation-plan.png'), true);

        $docs[] = $this->fileDoc($techcards, $sidorov, 'Технологическая карта сборки насосной станции НС-40', 'ТК-040', 'Последовательность операций, нормы времени, контрольные точки.', ['насосная станция', 'сборка'], $today->modify('+10 months'), $this->xlsx($tmp, 'TK-040'), true, [
            ['Изменены нормы времени операций 4–6', $this->xlsx($tmp, 'TK-040 v2')],
        ]);
        $docs[] = $this->fileDoc($techcards, $sidorov, 'Технологическая карта испытаний НС-40', 'ТК-041', null, ['испытания'], $today->modify('+25 days'), $this->pdf($tmp, 'TK-041', 'Ispytaniya NS-40'), true);
        $docs[] = $this->fileDoc($drawings, $sidorov, 'Сборочный чертёж НС-40.00.000 СБ', 'НС-40.00.000', 'Сборочный чертёж, формат DWG.', ['чертёж', 'нс-40'], $today->modify('+2 years'), $this->txt($tmp, 'NS-40.00.000-SB.dwg', "AC1027 demo dwg placeholder"), true);
        $docs[] = $this->fileDoc($drawings, $sidorov, 'Гидравлическая схема НС-40', 'НС-40.00.000 Г3', null, ['схема'], $today->modify('+2 years'), $this->png($tmp, 'hydraulic-scheme.png'), false);

        $docs[] = $this->internal($this->fileDoc($orders, $kuznetsova, 'Приказ о графике отпусков на 2026 год', 'ПР-2026-01', null, ['отпуска', '2026'], $today->modify('+3 months'), $this->pdf($tmp, 'PR-2026-01', 'Prikaz o grafike otpuskov 2026'), true));
        $archivedDoc = $this->fileDoc($orders, $kuznetsova, 'Приказ о графике отпусков на 2025 год', 'ПР-2025-01', null, ['отпуска', '2025'], $today->modify('-8 months'), $this->pdf($tmp, 'PR-2025-01', 'Prikaz o grafike otpuskov 2025'), true);
        $this->documentManager->archive($archivedDoc, $kuznetsova);
        $docs[] = $archivedDoc;
        $docs[] = $this->fileDoc($templates, $kuznetsova, 'Заявление на отпуск (шаблон)', 'ШБ-001', 'Шаблон заявления на ежегодный оплачиваемый отпуск.', ['шаблон', 'отпуск'], null, $this->docx($tmp, 'Zayavlenie-otpusk', [
            'Заявление на ежегодный оплачиваемый отпуск',
            'Руководителю ООО «Водокомфорт» от _____________________ (должность, подразделение, фамилия и инициалы).',
            'Прошу предоставить мне ежегодный оплачиваемый отпуск продолжительностью ____ календарных дней с «___» __________ 20__ г.',
            'Заявление подаётся не позднее чем за две недели до начала отпуска и согласовывается с непосредственным руководителем.',
            'Дата _______________   Подпись _______________',
        ]), true);
        $docs[] = $this->fileDoc($templates, $kuznetsova, 'Заявление на удалённую работу (шаблон)', 'ШБ-002', null, ['шаблон', 'удалённая работа'], null, $this->docx($tmp, 'Zayavlenie-udalenka', [
            'Заявление о переводе на дистанционную (удалённую) работу',
            'Прошу перевести меня на дистанционную работу с «___» __________ 20__ г. на срок ____ месяцев.',
            'Рабочее место по адресу проживания оборудовано персональным компьютером и каналом связи; для доступа к корпоративным ресурсам используется VPN-клиент.',
            'Обязуюсь соблюдать требования по защите персональных данных и коммерческой тайны, быть на связи в рабочее время и участвовать в совещаниях по видеосвязи.',
            'Дата _______________   Подпись _______________',
        ]), true);
        $docs[] = $this->pageDoc($templates, $kuznetsova, 'Памятка новому сотруднику', null, 'Черновик памятки — на согласовании.', ['онбординг'], null, '<h2>Добро пожаловать!</h2><p>Эта памятка поможет освоиться в первые дни. Черновик, на согласовании у руководителя отдела кадров.</p>', false);

        $io->writeln(\sprintf('Документов создано: %d.', \count($docs)));

        // --- Ознакомление под подпись ----------------------------------------------------
        // Инструкция по охране труда: назначена всем, часть сотрудников уже ознакомилась.
        $safetyDoc = null;
        foreach ($docs as $doc) {
            if ('ОТ-001' === $doc->getCode()) {
                $safetyDoc = $doc;
                break;
            }
        }
        if (null !== $safetyDoc) {
            $everyone = [$smirnov, $sidorov, $kuznetsova, $petrova, $ivanov];
            $this->acknowledgements->assign($safetyDoc, $everyone, $today->modify('+10 days'), $admin, false);
            foreach ([$sidorov, $kuznetsova, $ivanov] as $reader) {
                $this->acknowledgements->confirm($safetyDoc, $reader, '10.10.0.'.mt_rand(2, 250));
            }
            $io->writeln('Назначено ознакомление с инструкцией по охране труда (часть сотрудников уже подтвердила).');
        }
        // --- Обсуждение и подписки --------------------------------------------------------
        $discussed = null;
        foreach ($docs as $doc) {
            if ('ПОЛ-001' === $doc->getCode()) {
                $discussed = $doc;
                break;
            }
        }
        if (null !== $discussed) {
            $question = new DocumentComment($discussed, 'Подскажите, с какого дня действует новый порядок согласования — с даты утверждения или со следующего месяца?', $smirnov);
            $this->em->persist($question);
            $this->em->persist(new DocumentComment($discussed, 'С даты утверждения. Переходных положений нет, старые бланки принимаем только до конца недели.', $ivanov, $question));
            $this->em->persist(new DocumentComment($discussed, 'Добавили в раздел «Шаблоны и бланки» актуальную форму заявки — пользуйтесь ей.', $kuznetsova));
            foreach ([$smirnov, $petrova] as $subscriber) {
                $this->em->persist(new DocumentSubscription($subscriber, $discussed));
            }
            $this->em->persist(new DocumentSubscription($smirnov, null, $safety));
            $this->em->flush();
            $io->writeln('Добавлено обсуждение документа ПОЛ-001 и подписки сотрудников.');
        }

        // --- Связи документов и шаблоны ---------------------------------------------------
        $byCode = [];
        foreach ($docs as $doc) {
            if (null !== $doc->getCode()) {
                $byCode[$doc->getCode()] = $doc;
            }
        }
        if (isset($byCode['ПОЛ-001'], $byCode['ПОЛ-003'])) {
            $this->em->persist(new DocumentLink($byCode['ПОЛ-001'], $byCode['ПОЛ-003'], DocumentLink::RELATED, $admin, 'Связанные правила внутреннего распорядка'));
        }
        if (isset($byCode['ОТ-001'], $byCode['ОТ-002'])) {
            $this->em->persist(new DocumentLink($byCode['ОТ-002'], $byCode['ОТ-001'], DocumentLink::RELATED, $admin));
        }
        if (isset($byCode['ПР-2026-01'], $byCode['ПР-2025-01'])) {
            $this->em->persist(new DocumentLink($byCode['ПР-2026-01'], $byCode['ПР-2025-01'], DocumentLink::REPLACES, $admin, 'График отпусков на новый год'));
        }
        $order = (new DocumentTemplate('Приказ по основной деятельности'))
            ->setDescription('Для приказов директора: раздел «Приказы», сквозная нумерация по годам.')
            ->setSection($orders)
            ->setType(Document::TYPE_FILE)
            ->setTitlePattern('Приказ от {ДАТА} № ')
            ->setCodePattern('ПР-{ГОД}-{NNN}')
            ->setTagsString('приказ')
            ->setValidityMonths(36)
            ->setCreatedBy($admin);
        $regulation = (new DocumentTemplate('Регламент процесса'))
            ->setDescription('Страница портала с типовой структурой регламента.')
            ->setType(Document::TYPE_PAGE)
            ->setTitlePattern('Регламент ')
            ->setTagsString('регламент')
            ->setValidityMonths(24)
            ->setBody('<h2>Назначение</h2><p>Для чего нужен процесс и на кого распространяется.</p><h2>Термины</h2><p>Пояснения к сокращениям.</p><h2>Порядок выполнения</h2><ol><li>Шаг 1.</li><li>Шаг 2.</li></ol><h2>Ответственность</h2><p>Кто за что отвечает.</p>')
            ->setCreatedBy($admin);
        $this->em->persist($order);
        $this->em->persist($regulation);
        $this->em->flush();
        $io->writeln('Добавлены связи документов и два шаблона (приказ с автонумерацией, регламент-страница).');

        $this->rrmdir($tmp);

        // --- История событий -------------------------------------------------------------
        if (!$input->getOption('no-events')) {
            $this->generateEvents($docs, $readers);
            $io->writeln('Сгенерирована история просмотров и скачиваний за 60 дней.');
        }

        $io->success('Демонстрационные данные загружены.');
        $io->table(['Логин', 'Роль', 'Пароль'], [
            ['admin', 'администратор', self::PASSWORD],
            ['ivanov', 'модератор раздела «Общие документы»', self::PASSWORD],
            ['petrova', 'модератор раздела «ИТ»', self::PASSWORD],
            ['sidorov', 'модератор раздела «Производство»', self::PASSWORD],
            ['kuznetsova', 'модератор раздела «Кадры»', self::PASSWORD],
            ['smirnov', 'обычный пользователь (только чтение)', self::PASSWORD],
        ]);

        return Command::SUCCESS;
    }

    private function ensureUser(string $username, string $name, bool $admin, string $email, string $department): User
    {
        $user = $this->users->findOneByUsername($username);
        if (null === $user) {
            $user = $this->userManager->create($username, $name, self::PASSWORD, $admin, $email);
        } else {
            $this->userManager->setPassword($user, self::PASSWORD, false);
            $user->setDisplayName($name)->setEmail($email)->setActive(true);
            if ($admin) {
                $user->setAdmin(true);
            }
        }
        $user->setDepartment($department);
        // Демо-данные должны быть предсказуемыми: второй фактор, привязка Telegram и каналы
        // уведомлений сбрасываются, иначе прежние проверки мешают повторному прогону.
        $user->setTotpSecret(null)->unlinkTelegram()->setNotifyEmail(true)->setNotifyTelegram(true);
        $this->em->flush();

        return $user;
    }

    /**
     * @param list<string>                           $tags
     * @param list<array{0: string, 1: ?string}>     $versions дополнительные версии: [комментарий, путь к файлу]
     */
    private function fileDoc(Section $section, User $owner, string $title, ?string $code, ?string $description, array $tags, ?\DateTimeImmutable $validUntil, string $path, bool $publish, array $versions = []): Document
    {
        $doc = (new Document($section))->setTitle($title)->setCode($code)->setDescription($description)->setTags($tags)->setValidUntil($validUntil)->setType(Document::TYPE_FILE);
        $this->documentManager->create($doc, $this->upload($path), null, 'Первая версия', $owner, $publish);
        foreach ($versions as [$note, $file]) {
            $this->documentManager->addFileVersion($doc, $this->upload($file ?? $path), $note, $owner);
        }

        return $doc;
    }

    /**
     * @param list<string>                       $tags
     * @param list<array{0: string, 1: ?string}> $versions дополнительные версии: [комментарий, null] (текст дополняется абзацем)
     */
    private function pageDoc(Section $section, User $owner, string $title, ?string $code, ?string $description, array $tags, ?\DateTimeImmutable $validUntil, string $html, bool $publish, array $versions = []): Document
    {
        $doc = (new Document($section))->setTitle($title)->setCode($code)->setDescription($description)->setTags($tags)->setValidUntil($validUntil)->setType(Document::TYPE_PAGE);
        $this->documentManager->create($doc, null, $html, 'Первая версия', $owner, $publish);
        $current = $html;
        foreach ($versions as [$note, $extra]) {
            $current .= '<p>'.htmlspecialchars($extra ?? $note).'.</p>';
            $this->documentManager->addPageVersion($doc, $current, $note, $owner);
        }

        return $doc;
    }

    /** Делает документ внутренним (виден только после входа). */
    private function internal(Document $document): Document
    {
        $document->setPublic(false);
        $this->em->flush();

        return $document;
    }

    private function upload(string $path): UploadedFile
    {
        // Файл копируется, чтобы один и тот же образец можно было использовать для нескольких версий.
        $copy = \dirname($path).'/'.bin2hex(random_bytes(4)).'-'.basename($path);
        copy($path, $copy);

        return new UploadedFile($copy, basename($path), null, null, true);
    }

    /** Минимальный корректный PDF с текстом (латиница — стандартный шрифт без встраивания). */
    private function pdf(string $dir, string $code, string $text): string
    {
        $content = "BT /F1 20 Tf 60 740 Td ({$text}) Tj ET\nBT /F1 12 Tf 60 710 Td (Document {$code}. Demo file generated by docportal.) Tj ET\nBT /F1 12 Tf 60 690 Td (VODOKOMFORT - internal documentation portal) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.\strlen($content)." >>\nstream\n".$content."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[] = \strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$obj."\nendobj\n";
        }
        $xref = \strlen($pdf);
        $pdf .= "xref\n0 ".(\count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= \sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= "trailer\n<< /Size ".(\count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
        $name = preg_replace('/[^A-Za-z0-9-]+/', '-', $code).'.pdf';
        file_put_contents($dir.'/'.$name, $pdf);

        return $dir.'/'.$name;
    }

    /**
     * Минимальный DOCX (zip с обязательными частями).
     * $paragraphs — текст документа: он попадает в индекс и позволяет показать полнотекстовый поиск.
     *
     * @param list<string> $paragraphs
     */
    private function docx(string $dir, string $name, array $paragraphs = []): string
    {
        $path = $dir.'/'.$name.'.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $body = '';
        foreach ([] === $paragraphs ? ['Демонстрационный документ '.$name.' (портал документации).'] : $paragraphs as $paragraph) {
            $body .= '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($paragraph, \ENT_XML1 | \ENT_QUOTES, 'UTF-8').'</w:t></w:r></w:p>';
        }
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();

        return $path;
    }

    /** Минимальный XLSX. */
    private function xlsx(string $dir, string $name): string
    {
        $path = $dir.'/'.$name.'.xlsx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="ТК" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Операция</t></is></c><c r="B1" t="inlineStr"><is><t>Норма, мин</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Сборка рамы</t></is></c><c r="B2"><v>45</v></c></row><row r="3"><c r="A3" t="inlineStr"><is><t>Монтаж насоса</t></is></c><c r="B3"><v>60</v></c></row></sheetData></worksheet>');
        $zip->close();

        return $path;
    }

    private function txt(string $dir, string $name, string $content): string
    {
        file_put_contents($dir.'/'.$name, $content);

        return $dir.'/'.$name;
    }

    /** Простая PNG-схема (сгенерирована GD, если доступен; иначе минимальный PNG). */
    private function png(string $dir, string $name): string
    {
        $path = $dir.'/'.$name;
        if (\function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor(640, 400);
            $bg = imagecolorallocate($im, 244, 248, 251);
            $blue = imagecolorallocate($im, 15, 67, 130);
            $light = imagecolorallocate($im, 121, 167, 198);
            imagefill($im, 0, 0, $bg);
            for ($i = 0; $i < 6; ++$i) {
                imagerectangle($im, 40 + $i * 95, 120, 110 + $i * 95, 200, $blue);
                imageline($im, 110 + $i * 95, 160, 135 + $i * 95, 160, $light);
            }
            imagestring($im, 5, 40, 40, 'DEMO SCHEME - '.strtoupper(pathinfo($name, \PATHINFO_FILENAME)), $blue);
            imagepng($im, $path);
            imagedestroy($im);
        } else {
            file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        }

        return $path;
    }

    /**
     * Генерирует просмотры и скачивания за последние 60 дней (для графиков и рейтингов).
     *
     * @param list<Document> $docs
     * @param list<User>     $readers
     */
    private function generateEvents(array $docs, array $readers): void
    {
        mt_srand(42);
        $now = new \DateTimeImmutable();
        $rows = [];
        foreach ($docs as $i => $doc) {
            if (!$doc->isPublished()) {
                continue;
            }
            $popularity = 3 + (($i * 7) % 11); // разная популярность документов
            for ($day = 60; $day >= 0; --$day) {
                $count = mt_rand(0, $popularity) - ($day % 7 >= 5 ? 2 : 0); // выходные тише
                for ($k = 0; $k < $count; ++$k) {
                    $user = $readers[mt_rand(0, \count($readers) - 1)];
                    $at = $now->modify('-'.$day.' days')->setTime(mt_rand(8, 18), mt_rand(0, 59), mt_rand(0, 59));
                    $rows[] = [$doc->getId(), $doc->getCurrentVersion()?->getId(), $user->getId(), $user->getDisplayName(), DocumentEvent::VIEW, $at->format('Y-m-d H:i:s')];
                    $doc->incrementViewCount();
                    if (mt_rand(0, 2) === 0) {
                        $rows[] = [$doc->getId(), $doc->getCurrentVersion()?->getId(), $user->getId(), $user->getDisplayName(), DocumentEvent::DOWNLOAD, $at->modify('+1 minute')->format('Y-m-d H:i:s')];
                        $doc->incrementDownloadCount();
                    }
                }
            }
        }
        $this->em->flush();
        $this->connection->beginTransaction();
        foreach (array_chunk($rows, 500) as $chunk) {
            $sql = 'INSERT INTO document_event (document_id, version_id, user_id, actor_name, type, created_at, ip, details) VALUES ';
            $params = [];
            $values = [];
            foreach ($chunk as $r) {
                $values[] = '(?, ?, ?, ?, ?, ?, NULL, NULL)';
                array_push($params, ...$r);
            }
            $this->connection->executeStatement($sql.implode(', ', $values), $params);
        }
        $this->connection->commit();
    }

    private function wipe(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        // document_deletion, document_text и document_acknowledgement тоже очищаем: иначе записи остались бы
        // от прежних демо-данных и относились бы к идентификаторам, которые после перезагрузки
        // принадлежат другим документам (TRUNCATE сбрасывает счётчик, и номера выдаются заново).
        foreach (['document_event', 'document_version', 'document_text', 'document_acknowledgement', 'document_approval', 'document_question', 'document_comment', 'document_subscription', 'document_link', 'document', 'document_deletion', 'document_template', 'section_moderator', 'section'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $storage = $this->documentManager->getStorage()->getStorageDir();
        if (is_dir($storage)) {
            foreach (scandir($storage) ?: [] as $entry) {
                if (ctype_digit($entry)) {
                    $this->documentManager->getStorage()->deleteDocumentDir((int) $entry);
                }
            }
        }
        $this->em->clear();
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $f) {
            if ('.' !== $f && '..' !== $f) {
                @unlink($dir.'/'.$f);
            }
        }
        @rmdir($dir);
    }
}
