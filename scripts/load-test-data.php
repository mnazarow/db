<?php

declare(strict_types=1);

/*
 * Данные для нагрузочной проверки: 2000 сотрудников (40 подразделений с руководителями), 120 разделов,
 * 50 000 документов с версиями и извлечённым текстом, 240 000 назначений ознакомления, 300 000 событий
 * документов, 200 000 записей аудита и 20 000 вопросов к ИИ — в ОТДЕЛЬНУЮ базу, где уже применены
 * миграции и загружены демонстрационные данные (php bin/console doctrine:migrations:migrate; app:demo:load).
 *
 *   php scripts/load-test-data.php 'mysql:host=127.0.0.1;dbname=docportal_load;charset=utf8mb4' ПОЛЬЗОВАТЕЛЬ ПАРОЛЬ
 *
 * Имя базы должно оканчиваться на _load — защита от запуска на рабочей базе. Пароль всех сотрудников —
 * как у ivanov из демонстрационных данных (Demo12345). Результаты проверки 1.8.0 — в руководстве (8.4).
 */
if ($argc < 4) {
    fwrite(STDERR, "Использование: php scripts/load-test-data.php DSN ПОЛЬЗОВАТЕЛЬ ПАРОЛЬ\n");
    exit(2);
}
[, $dsn, $user, $pass] = $argv;
if (!preg_match('/dbname=[^;]*_load(;|$)/', $dsn)) {
    fwrite(STDERR, "Имя базы в DSN должно оканчиваться на _load (отдельная база для проверки, не рабочая).\n");
    exit(2);
}
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// Ещё раз — по базе, к которой действительно подключились (DSN мог указать базу по умолчанию иначе).
if (!str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_load')) {
    fwrite(STDERR, "Подключение не к базе *_load — данные не создаются.\n");
    exit(2);
}
mt_srand(42);
$guide = file_get_contents(__DIR__.'/../docs/РУКОВОДСТВО.md');
preg_match_all('/\p{Cyrillic}{4,}/u', mb_strtolower($guide), $m);
$freq = array_count_values($m[0]); arsort($freq); $vocab = array_keys($freq); $V = count($vocab);
echo "vocab $V\n";
function word(array $vocab, int $V): string { $r = (int) floor($V ** mt_rand(0, 1000000) / 1000000) - 1; return $vocab[max(0, min($V - 1, $r))]; }
function words(array $vocab, int $V, int $n): string { $o = []; for ($i = 0; $i < $n; $i++) $o[] = word($vocab, $V); return implode(' ', $o); }
function ins(PDO $pdo, string $table, array $cols, array $rows): void {
    if (!$rows) return;
    $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    foreach (array_chunk($rows, 500) as $chunk) {
        $sql = "INSERT INTO $table (" . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($chunk), $ph));
        $pdo->prepare($sql)->execute(array_merge(...$chunk));
    }
}
$now = time(); $dt = fn (int $ago) => date('Y-m-d H:i:s', $now - $ago);
$hash = $pdo->query("SELECT password FROM user WHERE username='ivanov'")->fetchColumn();
$NU = 2000; $ND = 50000;
// Сотрудники: 40 подразделений, у каждого руководитель.
$deps = []; for ($i = 1; $i <= 40; $i++) $deps[] = 'Подразделение ' . ucfirst(word($vocab, $V)) . " $i";
$first = ['Иван','Пётр','Анна','Мария','Сергей','Ольга','Алексей','Елена','Дмитрий','Наталья','Андрей','Татьяна','Михаил','Ирина','Николай','Светлана'];
$last = ['Иванов','Петров','Сидоров','Смирнов','Кузнецов','Попов','Васильев','Соколов','Михайлов','Новиков','Фёдоров','Морозов','Волков','Алексеев','Лебедев','Семёнов'];
$pdo->beginTransaction();
$rows = [];
for ($i = 1; $i <= $NU; $i++) {
    $rows[] = [sprintf('load%04d', $i), $first[mt_rand(0, 15)] . ' ' . $last[mt_rand(0, 15)] . " $i", sprintf('load%04d@example.ru', $i), $deps[($i - 1) % 40], '[]', $hash, 'local', 1, 0, $dt(mt_rand(86400, 86400 * 900)), '[]', 1, 0, 'Специалист'];
}
ins($pdo, 'user', ['username','display_name','email','department','roles','password','auth_source','active','must_change_password','created_at','recovery_codes','notify_email','notify_telegram','job_title'], $rows);
$uids = $pdo->query("SELECT id FROM user WHERE username LIKE 'load%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
// руководители — первые 40
for ($i = 40; $i < $NU; $i++) { $pdo->exec('UPDATE user SET manager_id=' . $uids[$i % 40] . ' WHERE id=' . $uids[$i]); }
$pdo->commit();
$allUsers = $pdo->query('SELECT id FROM user')->fetchAll(PDO::FETCH_COLUMN);
echo "users ok\n";
// Разделы: 120 новых, вложенные.
$sections = $pdo->query('SELECT id, path, depth FROM section')->fetchAll(PDO::FETCH_ASSOC);
for ($i = 1; $i <= 120; $i++) {
    $p = $sections[mt_rand(0, count($sections) - 1)];
    if ($p['depth'] >= 3) { $p = $sections[0]; }
    $name = 'Раздел ' . ucfirst(word($vocab, $V)) . " $i";
    $pdo->prepare('INSERT INTO section (name, slug, description, position, path, depth, created_at, updated_at, parent_id, materials_enabled) VALUES (?,?,?,?,?,?,?,?,?,0)')
        ->execute([$name, "load-section-$i", null, $i * 10, 'tmp', $p['depth'] + 1, $dt(86400 * 400), $dt(86400 * 30), $p['id']]);
    $id = (int) $pdo->lastInsertId(); $path = $p['path'] . $id . '/';
    $pdo->exec("UPDATE section SET path='$path' WHERE id=$id");
    $sections[] = ['id' => $id, 'path' => $path, 'depth' => $p['depth'] + 1];
}
$sids = array_column($sections, 'id');
echo "sections ok\n";
// Документы с версиями и текстом.
$kinds = $pdo->query('SELECT id FROM document_kind')->fetchAll(PDO::FETCH_COLUMN);
$statuses = array_merge(array_fill(0, 85, 'published'), array_fill(0, 7, 'draft'), array_fill(0, 3, 'review'), array_fill(0, 5, 'archived'));
for ($b = 0; $b < $ND / 1000; $b++) {
    $pdo->beginTransaction();
    $docs = [];
    for ($i = 0; $i < 1000; $i++) {
        $n = $b * 1000 + $i + 1;
        $type = mt_rand(0, 4) ? 'file' : 'page';
        $status = $statuses[mt_rand(0, 99)];
        $created = mt_rand(86400, 86400 * 1500);
        $docs[] = [ucfirst(words($vocab, $V, mt_rand(3, 8))), sprintf('НГ-%05d', $n), words($vocab, $V, mt_rand(10, 40)), $type, $status, json_encode([word($vocab, $V), word($vocab, $V)]),
            mt_rand(0, 3) ? null : date('Y-m-d', $now + mt_rand(-86400 * 60, 86400 * 400)), 0, $dt($created), $dt(mt_rand(0, $created)), $status === 'published' || $status === 'archived' ? $dt($created - 3600) : null,
            $status === 'archived' ? $dt(mt_rand(0, 86400 * 30)) : null, mt_rand(0, 3000), mt_rand(0, 500), $sids[mt_rand(0, count($sids) - 1)], $allUsers[mt_rand(0, count($allUsers) - 1)], mt_rand(0, 9) ? 0 : 1, 0, '', $kinds ? $kinds[mt_rand(0, count($kinds) - 1)] : null];
    }
    ins($pdo, 'document', ['title','code','description','type','status','tags','valid_until','expiry_notice_stage','created_at','updated_at','published_at','archived_at','view_count','download_count','section_id','owner_id','is_public','restricted','allowed_departments','kind_id'], $docs);
    $ids = $pdo->query("SELECT id, type FROM document WHERE code LIKE 'НГ-%' ORDER BY id DESC LIMIT 1000")->fetchAll(PDO::FETCH_KEY_PAIR);
    $vers = []; $texts = []; $pages = [];
    foreach ($ids as $id => $type) {
        $nv = mt_rand(1, 3);
        for ($v = 1; $v <= $nv; $v++) {
            $vers[] = [$v, $type, $type === 'file' ? "load/$id-v$v.pdf" : null, $type === 'file' ? "doc-$id-v$v.pdf" : null, $type === 'file' ? 'application/pdf' : null, mt_rand(10000, 5000000), null, $dt(mt_rand(0, 86400 * 900)), 0, $id, $allUsers[mt_rand(0, 50)]];
        }
        $content = words($vocab, $V, mt_rand(80, 400));
        $texts[] = [$id, $nv, 'ok', $content, mb_strlen($content), $dt(mt_rand(0, 86400 * 100))];
    }
    ins($pdo, 'document_version', ['number','kind','stored_path','original_name','mime_type','size','checksum','created_at','download_count','document_id','created_by_id'], $vers);
    // текущая версия — последняя
    $pdo->exec("UPDATE document d JOIN (SELECT document_id, MAX(id) vid FROM document_version WHERE document_id IN (" . implode(',', array_keys($ids)) . ") GROUP BY document_id) x ON x.document_id=d.id SET d.current_version_id=x.vid");
    foreach ($pdo->query("SELECT v.id FROM document_version v JOIN document d ON d.id=v.document_id WHERE d.type='page' AND v.document_id IN (" . implode(',', array_keys($ids)) . ')')->fetchAll(PDO::FETCH_COLUMN) as $vid) {
        $pages[] = [('<h2>' . ucfirst(words($vocab, $V, 4)) . '</h2><p>' . words($vocab, $V, mt_rand(60, 200)) . '</p>'), $vid];
    }
    ins($pdo, 'document_version_content', ['html','version_id'], $pages);
    ins($pdo, 'document_text', ['document_id','version_number','status','content','chars','indexed_at'], $texts);
    $pdo->commit();
    if ($b % 10 === 9) echo 'docs ' . (($b + 1) * 1000) . "\n";
}
$docIds = $pdo->query("SELECT id FROM document WHERE status='published' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
// Ознакомления: 120 документов на всех 2000 сотрудников, 70 % подтвердили.
$pdo->beginTransaction();
$rows = [];
for ($k = 0; $k < 120; $k++) {
    $d = $docIds[mt_rand(0, count($docIds) - 1)];
    foreach ($uids as $u) {
        $conf = mt_rand(0, 9) < 7;
        $rows[] = [$d, $u, $allUsers[0], 1, $dt(86400 * mt_rand(10, 200)), mt_rand(0, 1) ? date('Y-m-d', $now + mt_rand(-86400 * 30, 86400 * 30)) : null, $conf ? $dt(86400 * mt_rand(0, 9)) : null, $conf ? '10.0.0.' . mt_rand(1, 250) : null, 0];
    }
    if (count($rows) >= 20000) { ins($pdo, 'document_acknowledgement', ['document_id','user_id','assigned_by_id','version_number','assigned_at','due_at','confirmed_at','confirmed_ip','quiz_attempts'], $rows); $rows = []; }
}
ins($pdo, 'document_acknowledgement', ['document_id','user_id','assigned_by_id','version_number','assigned_at','due_at','confirmed_at','confirmed_ip','quiz_attempts'], $rows);
$pdo->commit();
echo "acks ok\n";
// События документов и аудит.
$pdo->beginTransaction();
$types = ['view','view','view','view','download','download','update','new_version','publish','acknowledge'];
$rows = [];
for ($i = 0; $i < 300000; $i++) {
    $rows[] = ['Сотрудник', $types[mt_rand(0, 9)], $dt(mt_rand(0, 86400 * 365)), '10.0.0.1', null, $docIds[mt_rand(0, count($docIds) - 1)], null, $uids[mt_rand(0, $NU - 1)]];
    if (count($rows) >= 20000) { ins($pdo, 'document_event', ['actor_name','type','created_at','ip','details','document_id','version_id','user_id'], $rows); $rows = []; }
}
$rows2 = [];
for ($i = 0; $i < 200000; $i++) {
    $rows2[] = [$dt(mt_rand(0, 86400 * 365)), mt_rand(0, 20) ? 'info' : 'warning', mt_rand(0, 1) ? 'Вход пользователя' : 'Просмотр документа', sprintf('load%04d', mt_rand(1, $NU)), '10.0.0.' . mt_rand(1, 250), '{}'];
    if (count($rows2) >= 20000) { ins($pdo, 'audit_event', ['occurred_at','level','action','actor_name','ip','details'], $rows2); $rows2 = []; }
}
$rows3 = [];
$st = ['answered','answered','answered','not_found','no_sources'];
for ($i = 0; $i < 20000; $i++) {
    $q = ucfirst(words($vocab, $V, mt_rand(3, 7))) . '?';
    $rows3[] = [$uids[mt_rand(0, $NU - 1)], $q, mb_substr(implode(' ', array_slice(explode(' ', mb_strtolower($q)), 0, 3)), 0, 190), $st[mt_rand(0, 4)], 'ответ', '[]', mt_rand(0, 1), mt_rand(0, 5) ? null : (mt_rand(0, 1) ? 1 : -1), mt_rand(500, 9000), $dt(mt_rand(0, 86400 * 170))];
}
ins($pdo, 'ai_question', ['user_id','question','topic','status','answer','sources','semantic','rating','duration_ms','created_at'], $rows3);
$pdo->commit();
echo "events ok\n";
foreach (['user','section','document','document_version','document_text','document_acknowledgement','document_event','audit_event','ai_question'] as $t) echo $t, ': ', $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(), "\n";
