<?php
/**
 * Tests für das Webseiten-Update 9.0 (Dashboard, neue Widgets, Prozessanzeige, Releases) – nur mit Mocks, ohne Netz.
 *   php tools/tests/site9_test.php                 (SQLite in einem Wegwerf-Ordner)
 *   php my.php tools/tests/site9_test.php          (dieselben Tests gegen ein lokales Test-MySQL: $GLOBALS['__db_cfg'] vorher setzen)
 * NIE gegen die echte Datenbank laufen lassen.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gn9test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
if (!defined('DATA_DIR')) define('DATA_DIR', $tmp . '/data');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../../inc/core/pages.php';
require __DIR__ . '/../../inc/core/widgets.php';
require __DIR__ . '/../../inc/modules/procs.php';
require __DIR__ . '/../../inc/modules/releases.php';

$fails = 0; $n = 0;
function t(bool $ok, string $name): void { global $fails, $n; $n++; echo ($ok ? "ok   " : "FAIL ") . $name . "\n"; if (!$ok) $fails++; }
function rmrf(string $d): void { foreach (glob($d . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $f) is_dir($f) && !is_link($f) ? rmrf($f) : @unlink($f); @rmdir($d); }

// ---------- /proc-Parser mit Mock-Verzeichnis ----------
$proc = $tmp . '/proc';
$mk = function (array $stat, array $pids, float $uptime) use ($proc) {
    @mkdir($proc, 0755, true);
    file_put_contents($proc . '/stat', "cpu  {$stat[0]}\ncpu0 {$stat[1]}\ncpu1 {$stat[2]}\nintr 1 2 3\nprocs_running 3\n");
    file_put_contents($proc . '/meminfo', "MemTotal:       8000000 kB\nMemFree:        1000000 kB\nMemAvailable:   6000000 kB\nSwapTotal:      2000000 kB\nSwapFree:       1500000 kB\n");
    file_put_contents($proc . '/loadavg', "0.50 0.40 0.30 2/300 12345\n");
    file_put_contents($proc . '/uptime', "$uptime 1000.00\n");
    foreach ($pids as $pid => [$comm, $uid, $ticks, $threads, $start, $rss, $cmd]) {
        @mkdir("$proc/$pid", 0755, true);
        // Felder ab state: state ppid pgrp session tty tpgid flags minflt cminflt majflt cmajflt utime stime cutime cstime prio nice threads itreal starttime vsize rss
        file_put_contents("$proc/$pid/stat", "$pid ($comm) S 1 1 1 0 -1 0 0 0 0 0 " . intdiv($ticks, 2) . ' ' . ($ticks - intdiv($ticks, 2)) . " 0 0 20 0 $threads 0 $start 1000 $rss 0\n");
        file_put_contents("$proc/$pid/status", "Name:\t$comm\nUid:\t$uid\t$uid\t$uid\t$uid\n");
        file_put_contents("$proc/$pid/cmdline", str_replace(' ', "\0", $cmd) . "\0");
    }
};
$passwd = $tmp . '/passwd';
file_put_contents($passwd, "root:x:0:0:root:/root:/bin/bash\nwww-data:x:33:33::/var/www:/usr/sbin/nologin\n");
// Prozess 2 heißt „php (worker) x“ – Klammern und Leerzeichen im Namen
$mk(['100 0 100 800 0 0 0 0 0 0', '50 0 50 400 0 0 0 0', '50 0 50 400 0 0 0 0'],
    [1 => ['systemd', 0, 100, 1, 10, 1000, '/sbin/init'], 22 => ['php (worker) x', 33, 1000, 4, 500, 25000, 'php-fpm: pool www --password=geheim123']], 1000.0);
$a = pr_sample($proc);
t($a !== null && count($a['procs']) === 2, 'proc: zwei Prozesse gelesen');
t($a['procs'][22]['comm'] === 'php (worker) x' && $a['procs'][22]['threads'] === 4 && $a['procs'][22]['ticks'] === 1000 && $a['procs'][22]['start'] === 500, 'proc: Name mit Klammern, Felder richtig');
t(strpos($a['procs'][22]['cmd'], 'geheim123') === false && strpos($a['procs'][22]['cmd'], '--password=•••') !== false, 'proc: Passwort in Befehlszeile verborgen');
t($a['mem']['total'] === 8000000 * 1024 && $a['mem']['used'] === 2000000 * 1024 && $a['mem']['swap_used'] === 500000 * 1024, 'proc: meminfo');
t($a['load'] === [0.5, 0.4, 0.3] && count($a['stat']['cores']) === 2, 'proc: loadavg + 2 Kerne');
// zweiter Abruf: Gesamt +200 Ticks (je Kern +100), Prozess 22 +50 Ticks → 50 % eines Kerns; Kern0 70 % belegt
$mk(['200 0 170 830 0 0 0 0 0 0', '120 0 50 430 0 0 0 0', '80 0 120 400 0 0 0 0'],
    [1 => ['systemd', 0, 100, 1, 10, 1000, '/sbin/init'], 22 => ['php (worker) x', 33, 1050, 4, 500, 25000, 'php-fpm: pool www'], 99 => ['neu', 0, 10, 1, 99000, 10, '']], 1001.0);
$b = pr_sample($proc);
$v = pr_view($b, $a, pr_users($passwd));
$rows = array_column($v['rows'], null, 'pid');
t(abs($rows[22]['cpu'] - 50.0) < 0.01, 'proc: CPU-% aus Differenz (50 %) = ' . $rows[22]['cpu']);
t($rows[22]['user'] === 'www-data' && $rows[1]['user'] === 'root', 'proc: Benutzernamen aus passwd');
t(abs($v['cores'][0] - 70.0) < 0.01 && abs($v['cores'][1] - 100.0) < 0.01, 'proc: Kern-Auslastung ' . json_encode($v['cores']));
t($rows[99]['cmd'] === '[neu]', 'proc: leere cmdline → [name]');
t(abs($rows[22]['mem'] - round(25000 * 4096 / (8000000 * 1024) * 100, 1)) < 0.01 && $rows[22]['rss'] === 25000 * 4096, 'proc: RAM-% und RSS');
t($rows[22]['time'] === 996, 'proc: Laufzeit = uptime − start/100');
t($v['tasks'] === 3 && $v['threads'] === 6, 'proc: Prozesse/Threads');
$first = pr_view($a, null, []);
t(abs(array_column($first['rows'], null, 'pid')[22]['cpu'] - 1000 / 100 / (1000 - 5) * 100) < 0.1, 'proc: ohne Vorabruf → Durchschnitt seit Start');
t(array_column(pr_pick($v['rows'], 'mem'), 'pid')[0] === 22 && array_column(pr_pick($v['rows'], 'pid'), 'pid') === [1, 22, 99], 'proc: sortieren');
t(count(pr_pick($v['rows'], 'cpu', 'php')) === 1 && count(pr_pick($v['rows'], 'cpu', 'www-data')) === 1, 'proc: filtern');
t(pr_sample($tmp . '/gibtsnicht') === null, 'proc: nicht lesbar → null');
$snap = ['time' => 1700000000, 'load' => [1, 2, 3], 'mem' => ['total' => 100, 'avail' => 40], 'uptime' => 50,
         'procs' => ['time' => 1700000000, 'cores' => [12.5], 'tasks' => 1, 'threads' => 2, 'list' => [['pid' => 7, 'user' => 'root', 'cpu' => 3.5, 'mem' => 1, 'rss' => 10, 'time' => 5, 'cmd' => 'x']]]];
$r = pr_report(1, 'cpu', '', $snap, $tmp . '/gibtsnicht');
t($r['live'] === false && $r['at'] === 1700000000 && $r['rows'][0]['pid'] === 7 && $r['mem']['used'] === 60, 'proc: Rückfall auf Schnappschuss des Agents');
t(isset(pr_report(1, 'cpu', '', null, $tmp . '/gibtsnicht')['error']), 'proc: ohne alles → Fehlermeldung');
$r1 = pr_report(5, 'cpu', '', null, $proc, $passwd); $r2 = pr_report(5, 'bogus', '', null, $proc, $passwd);
t($r1['live'] && $r2['live'] && count($r2['rows']) === 3, 'proc: Bericht live, zweiter Abruf mit gespeichertem Vorabruf');

// ---------- Releases ----------
$gh = [['tag_name' => 'v1.2.0', 'published_at' => '2026-11-20T10:00:00Z', 'html_url' => 'https://github.com/x/r/releases/v1.2.0', 'body' => '', 'prerelease' => false,
        'assets' => [['name' => 'D2-Nexus-Setup.exe', 'browser_download_url' => 'https://github.com/x/a.exe', 'size' => 5, 'download_count' => 7, 'digest' => 'sha256:' . str_repeat('a', 64)],
                     ['name' => 'evil', 'browser_download_url' => 'https://evil.example/x', 'size' => 1, 'download_count' => 1]]],
       ['tag_name' => 'v1.3.0-beta', 'published_at' => '2026-11-25T10:00:00Z', 'prerelease' => true],
       ['tag_name' => 'v1.10.0', 'published_at' => '2026-11-26T10:00:00Z', 'prerelease' => true, 'draft' => false, 'html_url' => '', 'assets' => []],
       ['tag_name' => 'v0.9.0', 'published_at' => '2026-01-01T10:00:00Z', 'draft' => true]];
$list = rel_parse($gh, '/^v(\d+\.\d+\.\d+)$/');
t(count($list) === 2 && $list[0]['v'] === '1.10.0' && count($list[1]['assets']) === 1 && $list[1]['assets'][0]['sha256'] === str_repeat('a', 64), 'releases: parsen, sortieren, nur GitHub-Dateien');
$st = rel_status(['d2'], fn($u) => [200, json_encode($gh)], strtotime('2026-11-28T10:00:00Z'));
t($st['d2']['v'] === '1.2.0' && $st['d2']['age'] === 8 && $st['d2']['downloads'] === 7, 'releases: neueste stabile Version, Alter, Downloads');
$st = rel_status(['d2'], fn($u) => [500, '']);
t($st['d2']['v'] === '1.2.0', 'releases: GitHub weg → alter Stand bleibt');
t(rel_ago(0) === 'heute' && rel_ago(3) === 'vor 3 Tagen' && rel_ago(400) === 'vor 1 J.', 'releases: rel_ago');

// ---------- Widget-Helfer ----------
$wa = widgets_available();
foreach (['releases', 'services', 'notebook', 'bookmarks', 'habits', 'snippets', 'notify', 'accountcheck', 'adminq', 'online', 'auditlive', 'dlstats', 'market', 'sunmoon'] as $w)
    t(isset($wa[$w]) && empty($wa[$w]['error']) && isset(WIDGET_GROUP_DEFAULT[$w]), 'widget geladen + Gruppe: ' . $w);
$bm = bm_import_html('<DL><p><DT><H3>Bookmarks bar</H3><DL><p><DT><A HREF="https://a.example/?x=1&amp;y=2" ADD_DATE="1">A &amp; B</A><DT><A HREF="javascript:alert(1)">x</A></DL><p>'
                   . '<DT><H3>Dev</H3><DL><p><DT><A HREF="http://b.example">B</A></DL><DT><A HREF="https://c.example">C</A></DL>');
t($bm === [['Leiste', 'A & B', 'https://a.example/?x=1&y=2'], ['Dev', 'B', 'http://b.example'], ['Importiert', 'C', 'https://c.example']], 'bookmarks: Browser-Export importieren ' . json_encode($bm, JSON_UNESCAPED_UNICODE));
t(hb_streak(['2026-11-26', '2026-11-27', '2026-11-28'], '2026-11-28') === 3 && hb_streak(['2026-11-26', '2026-11-27'], '2026-11-28') === 2 && hb_streak(['2026-11-25'], '2026-11-28') === 0, 'habits: Serie');
$c = ac_checks(['totp_secret' => '', 'password_changed' => date('Y-m-d H:i:s', time() - 400 * 86400), 'pw_set' => 1, 'discord_id' => '1', 'discord_name' => 'x', 'display_name' => 'A'], 5, ['max_age' => 0]);
t(!$c[0][0] && !$c[1][0] && !$c[2][0] && $c[3][0] && $c[4][0] && $c[5][0], 'accountcheck: Prüfpunkte');
t(abs(sm_moon(strtotime('2000-01-06 18:14:00 UTC'))) < 0.001 && sm_moon_name(0.5)[1] === 'Vollmond' && sm_moon_name(0.0)[1] === 'Neumond', 'sunmoon: Mondphase');
t(abs(sm_moon(strtotime('2026-11-24 14:53:00 UTC')) - 0.5) < 0.03, 'sunmoon: Vollmond 24.11.2026');
$ds = dls_summary([['v' => '2.0.0', 'pre' => false, 'assets' => [['name' => 'VRC-Nexus-2.0.0-Setup.exe', 'downloads' => 10], ['name' => 'VRC-Nexus-2.0.0-android.apk', 'downloads' => 5], ['name' => 'latest.yml', 'downloads' => 99]]],
                   ['v' => '1.9.0', 'pre' => false, 'assets' => [['name' => 'VRC-Nexus-1.9.0-Setup.exe', 'downloads' => 3]]], ['v' => '2.1.0', 'pre' => true, 'assets' => [['name' => 'x-Setup.exe', 'downloads' => 50]]]]);
t($ds['total'] === 18 && $ds['latest']['v'] === '2.0.0' && $ds['latest']['kinds'] === ['🪟 Windows' => 10, '📱 Android' => 5], 'dlstats: Summen ohne Vorabversionen und Hilfsdateien');

// ---------- persönliche Ansicht ----------
$pc = widgets_prefs_clean(['order' => ['a1', 'b<2>', 'a1', ''], 'hidden' => 'x', 'group' => 'nope']);
t($pc['order'] === ['a1', 'b2'] && $pc['hidden'] === ['x'] && $pc['group'] === '', 'prefs: bereinigen');
$lay = [['uid' => 'a', 'type' => 't'], ['uid' => 'b', 'type' => 't'], ['uid' => 'c', 'type' => 't'], ['uid' => 'n', 'type' => 't']];
t(array_column(widgets_apply_prefs($lay, ['order' => ['c', 'a', 'b'], 'hidden' => [], 'min' => [], 'group' => '']), 'uid') === ['c', 'n', 'a', 'b'], 'prefs: Reihenfolge, neues Widget hinter seinem Vorgänger');
t(array_column(widgets_apply_prefs($lay, ['order' => [], 'hidden' => [], 'min' => [], 'group' => '']), 'uid') === ['a', 'b', 'c', 'n'], 'prefs: ohne Reihenfolge unverändert');

// ---------- Datenbank: user_kv (Migration 3) ----------
$db = site_db();
t((int)$db->query('PRAGMA user_version')->fetchColumn() >= 3, 'db: Schema-Version 3 (' . db_driver() . ')');
$name = 'kvtest_' . bin2hex(random_bytes(3));
$uid = auth_create($name, 'Test-Passwort-123!', 1, 'KV');
user_pref_set($uid, 'dash', '{"order":["a"]}');
user_pref_set($uid, 'dash', '{"order":["b"]}');
t(user_pref($uid, 'dash') === '{"order":["b"]}' && user_pref($uid, 'nix', 'd') === 'd', 'db: user_pref setzen/überschreiben/Standard');
t(user_kv_all($uid)['dash']['order'] === ['b'], 'db: user_kv_all');
$thrown = false; try { user_pref_set($uid, 'big', str_repeat('x', 700000)); } catch (RuntimeException $e) { $thrown = true; }
t($thrown, 'db: Größenlimit');
user_pref_delete($uid, 'dash');
t(user_pref($uid, 'dash', '-') === '-', 'db: löschen');
user_pref_set($uid, 'x', '1');
$db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
$cnt = $db->prepare('SELECT COUNT(*) FROM user_kv WHERE user_id = ?'); $cnt->execute([$uid]);
if ((int)$cnt->fetchColumn() > 0) $db->prepare('DELETE FROM user_kv WHERE user_id = ?')->execute([$uid]);   // MySQL ohne FK-Prüfung: aufräumen
t(true, 'db: Konto gelöscht');

// ---------- Wartungs-Vorankündigung ----------
set_setting('maintenance', '1'); set_setting('maintenance_notice_h', '24');
set_setting('maintenance_from', date('Y-m-d H:i:s', time() + 3 * 3600)); set_setting('maintenance_until', date('Y-m-d H:i:s', time() + 4 * 3600));
$mn = maintenance_notice();
t($mn !== null && strpos($mn['text'], 'in 2 Std.') !== false || strpos((string)($mn['text'] ?? ''), 'in 3 Std.') !== false, 'wartung: Vorankündigung ' . ($mn['text'] ?? '-'));
set_setting('maintenance_from', date('Y-m-d H:i:s', time() + 48 * 3600));
t(maintenance_notice() === null, 'wartung: zu weit weg → kein Hinweis');
set_setting('maintenance_notice_h', '0'); set_setting('maintenance_from', date('Y-m-d H:i:s', time() + 3600));
t(maintenance_notice() === null, 'wartung: Vorankündigung aus');
set_setting('maintenance', '0'); set_setting('maintenance_from', ''); set_setting('maintenance_until', ''); set_setting('maintenance_notice_h', '24');

rmrf($tmp);
echo $fails ? "$fails von $n FEHLGESCHLAGEN\n" : "alle $n Tests ok\n";
exit($fails ? 1 : 0);
