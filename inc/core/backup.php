<?php
/**
 * Backups: alle persönlichen Daten als ZIP (Download über backup.php, automatisch über den Aufgabenplaner).
 *
 * Enthalten: Datenbanken (SQLite-Kopien bzw. MySQL-SQL-Dump), JSON-Daten & Archive, hochgeladene Bilder.
 * Nicht enthalten: Zwischenspeicher (entsteht neu) und die Schlüssel in data/keys (bewusst getrennt aufbewahren).
 * Automatische Backups liegen in data/backups/ (per .htaccess gesperrt) – es werden nur die neuesten N behalten.
 */
require_once __DIR__ . '/common.php';

const BACKUP_DIR = DATA_DIR . '/backups';

/**
 * Backup-ZIP schreiben. $with_uploads = false lässt Bilder weg (kleiner, für automatische Backups).
 * Gibt ['ok' => bool, 'files' => n, 'size' => bytes] zurück.
 */
function backup_create(string $zip_path, bool $with_uploads = true): array {
    $files = [];   // Name im ZIP => Pfad
    $temp = [];    // danach löschen
    foreach (array_merge(json_glob(DATA_DIR . '/json/*.json'), json_glob(DATA_DIR . '/archive/*.json'), json_glob(DATA_DIR . '/archive/budget/*.json')) as $path) {
        if (($key = doc_key($path)) !== null) {   // MySQL-Modus: Dokument aus der Datenbank als Datei
            $t = tempnam(sys_get_temp_dir(), 'doc');
            file_put_contents($t, (string)doc_read($key));
            $temp[] = $t;
            $files['data/' . $key] = $t;
            continue;
        }
        $files[substr($path, strlen(APP_ROOT) + 1)] = $path;
    }
    if ($with_uploads) {
        foreach (glob(APP_ROOT . '/uploads/*.{jpg,png,gif,webp}', GLOB_BRACE) ?: [] as $path) $files['uploads/' . basename($path)] = $path;
        foreach (glob(DATA_DIR . '/uploads/furhub/*') ?: [] as $path) if (preg_match('/^[0-9a-f]{24}$/', basename($path))) $files['data/uploads/furhub/' . basename($path)] = $path;
        foreach (glob(DATA_DIR . '/uploads/posters/img/*') ?: [] as $path) if (preg_match('/^[0-9a-f]{24}$/', basename($path))) $files['data/uploads/posters/img/' . basename($path)] = $path;
    }
    if (db_is_mysql()) {
        $dump = sys_get_temp_dir() . '/db_backup_' . bin2hex(random_bytes(4)) . '.sql';
        if (db_mysql_dump($dump)) { $files['data/mysql-dump.sql'] = $dump; $temp[] = $dump; }
    } else {
        // SQLite: konsistente Kopie, auch wenn gerade geschrieben wird
        foreach (glob(DATA_DIR . '/db/*.sqlite') ?: [] as $dbf) {
            $copy = sys_get_temp_dir() . '/db_backup_' . bin2hex(random_bytes(4)) . '.sqlite';
            try {
                $pdo = new PDO('sqlite:' . $dbf);
                $pdo->exec('VACUUM INTO ' . $pdo->quote($copy));
            } catch (Throwable $e) {
                @copy($dbf, $copy);
            }
            if (is_file($copy)) { $files['data/db/' . basename($dbf)] = $copy; $temp[] = $copy; }
        }
    }
    $ok = false;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($files as $name => $path) $zip->addFile($path, $name);
            $zip->addFromString('LIESMICH.txt', "Backup vom " . date('d.m.Y H:i') . "\nZum Wiederherstellen den Ordner data/ zurück in den Ordner der Webseite kopieren.\n"
                . (db_is_mysql() ? "MySQL: data/mysql-dump.sql einspielen (mysql -u BENUTZER -p DATENBANK < mysql-dump.sql).\n" : '')
                . "Hinweis: Schlüssel (data/keys) sind absichtlich nicht enthalten – bitte getrennt sichern, sonst müssen VRChat/Discord neu verbunden werden.\n");
            $ok = $zip->close();
        }
    }
    foreach ($temp as $t) @unlink($t);
    return ['ok' => $ok, 'files' => count($files), 'size' => $ok ? (int)filesize($zip_path) : 0];
}

/** Automatisches Backup anlegen und alte entfernen */
function backup_auto(int $keep): array {
    if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0755, true);
    if (!is_file(BACKUP_DIR . '/.htaccess')) @file_put_contents(BACKUP_DIR . '/.htaccess', "Require all denied\n");
    $name = 'auto_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(3)) . '.zip';
    $r = backup_create(BACKUP_DIR . '/' . $name, false);
    $removed = 0;
    $list = backup_list();
    foreach (array_slice($list, max(1, $keep)) as $old) { if (@unlink(BACKUP_DIR . '/' . $old['name'])) $removed++; }
    return $r + ['name' => $name, 'removed' => $removed];
}

/** Vorhandene automatische Backups, neueste zuerst */
function backup_list(): array {
    $out = [];
    foreach (glob(BACKUP_DIR . '/auto_*.zip') ?: [] as $f) $out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'time' => (int)filemtime($f)];
    usort($out, function ($a, $b) { return $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']); });
    return $out;
}

function backup_file(string $name): ?string {
    if (!preg_match('/^auto_[0-9_\-]+_[0-9a-f]{6}\.zip$/', $name)) return null;
    $p = BACKUP_DIR . '/' . $name;
    return is_file($p) ? $p : null;
}

// =====================================================================================
// 8.1: Wiederherstellung (Admin → System → Backups) – erst prüfen, dann mit Sicherheits-Backup einspielen
// =====================================================================================

/** erlaubte Einträge im ZIP → Art */
function backup_entry_kind(string $n): ?string {
    if (strpos($n, '..') !== false || strpos($n, '\\') !== false || $n === '' || $n[0] === '/') return null;
    if (preg_match('#^data/db/[a-z0-9_]{1,40}\.sqlite$#', $n)) return 'sqlite';
    if ($n === 'data/mysql-dump.sql') return 'mysql';
    if (preg_match('#^data/(json|archive)/[A-Za-z0-9_\-/]{1,120}\.json$#', $n) && substr_count($n, '/') <= 4) return 'json';
    if (preg_match('#^uploads/[A-Za-z0-9_\-]{1,80}\.(jpg|png|gif|webp)$#', $n)) return 'upload';
    if (preg_match('#^data/uploads/(furhub|posters/img)/[0-9a-f]{24}$#', $n)) return 'upload';
    if ($n === 'LIESMICH.txt') return 'readme';
    return null;
}
/** ZIP prüfen → ['ok', 'parts' => [art => anzahl], 'unknown' => [...], 'created' => text] */
function backup_inspect(string $zipPath): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Die ZIP-Erweiterung fehlt.');
    $z = new ZipArchive();
    if ($z->open($zipPath) !== true) throw new InvalidArgumentException('Keine gültige ZIP-Datei.');
    $parts = []; $unknown = []; $created = '';
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = (string)$z->getNameIndex($i);
        if (substr($n, -1) === '/') continue;
        $k = backup_entry_kind($n);
        if ($k === null) { $unknown[] = $n; continue; }
        if ($k === 'readme') { $created = trim(strtok((string)$z->getFromIndex($i), "\n")); continue; }
        $parts[$k] = ($parts[$k] ?? 0) + 1;
    }
    $z->close();
    return ['ok' => !empty($parts), 'parts' => $parts, 'unknown' => array_slice($unknown, 0, 20), 'created' => $created];
}
/** MySQL-Dump in einzelne Anweisungen zerlegen (Anführungszeichen und Backslash-Escapes beachten) */
function backup_sql_split(string $sql): array {
    $out = []; $buf = ''; $q = ''; $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($q !== '') {
            $buf .= $c;
            if ($c === '\\' && $q === "'") { if ($i + 1 < $len) $buf .= $sql[++$i]; continue; }
            if ($c === $q) $q = '';
            continue;
        }
        if ($c === '-' && substr($sql, $i, 3) === '-- ' && trim($buf) === '') { $e = strpos($sql, "\n", $i); $i = $e === false ? $len : $e; continue; }
        if ($c === "'" || $c === '`' || $c === '"') { $q = $c; $buf .= $c; continue; }
        if ($c === ';') { if (trim($buf) !== '') $out[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}
/**
 * Backup einspielen. $parts: welche Teile (db, json, uploads). Vorher wird automatisch ein Sicherheits-Backup angelegt.
 * Gibt ['safety' => name, 'db' => n, 'json' => n, 'uploads' => n] zurück.
 */
function backup_restore(string $zipPath, array $parts = ['db', 'json'], bool $safety = true): array {
    $info = backup_inspect($zipPath);
    if (!$info['ok']) throw new InvalidArgumentException('Im ZIP ist nichts Wiederherstellbares.');
    $res = ['safety' => '', 'db' => 0, 'json' => 0, 'uploads' => 0];
    if ($safety) { $s = backup_auto(max(2, (int)setting('backup_keep', '7') + 1)); if (!$s['ok']) throw new RuntimeException('Sicherheits-Backup fehlgeschlagen – Wiederherstellung abgebrochen.'); $res['safety'] = $s['name']; }
    $z = new ZipArchive(); $z->open($zipPath);
    $mysql = db_is_mysql();
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = (string)$z->getNameIndex($i); $k = backup_entry_kind($n);
        if ($k === 'sqlite' && in_array('db', $parts, true) && !$mysql) {
            $body = (string)$z->getFromIndex($i);
            if (strncmp($body, "SQLite format 3\0", 16) !== 0) throw new InvalidArgumentException(basename($n) . ' ist keine SQLite-Datenbank.');
            $tmp = DATA_DIR . '/db/.restore_' . bin2hex(random_bytes(4)) . '.sqlite';
            file_put_contents($tmp, $body);
            try { $chk = (new PDO('sqlite:' . $tmp))->query('PRAGMA integrity_check')->fetchColumn(); } catch (Throwable $e) { $chk = $e->getMessage(); }
            if ($chk !== 'ok') { @unlink($tmp); throw new InvalidArgumentException(basename($n) . ' ist beschädigt (' . mb_substr((string)$chk, 0, 60) . ').'); }
            $dst = DATA_DIR . '/db/' . basename($n);
            try { if (is_file($dst)) backup_sqlite_into($dst, $tmp); else rename($tmp, $dst); }   // laufende DB: Inhalt übernehmen statt Datei tauschen (WAL-sicher)
            finally { @unlink($tmp); }
            $res['db']++;
        } elseif ($k === 'mysql' && in_array('db', $parts, true) && $mysql) {
            $db = doc_db(); $pre = $db->globalPrefix;
            foreach (backup_sql_split((string)$z->getFromIndex($i)) as $st) {
                // nur, was der eigene Dump schreibt – und nur Tabellen mit dem eigenen Präfix
                if (preg_match('/^SET (NAMES utf8mb4|SESSION sql_mode = \'[A-Z_,]*\'|FOREIGN_KEY_CHECKS = [01])$/', $st)) { if (strpos($st, 'sql_mode') === false) $db->rawExec($st); continue; }
                if (!preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([A-Za-z0-9_]+)`/', $st, $m) || strpos($m[2], $pre) !== 0) throw new InvalidArgumentException('Unerwartete Anweisung im Dump – abgebrochen.');
                $db->rawExec($st);
            }
            $res['db']++;
        } elseif ($k === 'json' && in_array('json', $parts, true)) {
            $body = (string)$z->getFromIndex($i);
            if (json_decode($body) === null && trim($body) !== 'null') continue;   // kaputte Datei überspringen
            $rel = substr($n, 5);   // ohne „data/“
            if ($mysql) doc_write($rel, $body);
            else { $dst = DATA_DIR . '/' . $rel; if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0755, true); file_put_contents($dst . '.tmp', $body, LOCK_EX); rename($dst . '.tmp', $dst); }
            $res['json']++;
        } elseif ($k === 'upload' && in_array('uploads', $parts, true)) {
            $dst = (strpos($n, 'uploads/') === 0 ? APP_ROOT . '/' : DATA_DIR . '/') . (strpos($n, 'uploads/') === 0 ? $n : substr($n, 5));
            if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0755, true);
            file_put_contents($dst, (string)$z->getFromIndex($i));
            $res['uploads']++;
        }
    }
    $z->close();
    return $res;
}

/** Inhalt einer SQLite-Datei in die laufende Datenbank übernehmen – in einer Transaktion, WAL-sicher.
 *  Tabellen, die es nur im Backup gibt, werden angelegt; Spalten, die es nur in einer Seite gibt, bleiben leer/Standard. */
function backup_sqlite_into(string $live, string $from): void {
    $pdo = new PDO('sqlite:' . $live, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 10000');
    $pdo->exec('ATTACH DATABASE ' . $pdo->quote($from) . ' AS r');
    $q = fn($x) => '"' . str_replace('"', '""', $x) . '"';
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        foreach ($pdo->query("SELECT name, sql FROM r.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $name = (string)$t['name'];
            $has = $pdo->prepare("SELECT 1 FROM main.sqlite_master WHERE type = 'table' AND name = ?"); $has->execute([$name]);
            if (!$has->fetchColumn()) { $pdo->exec((string)$t['sql']); }
            $cols = fn($db) => array_column($pdo->query('PRAGMA ' . $db . '.table_info(' . $q($name) . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
            $common = array_values(array_intersect($cols('main'), $cols('r')));
            if (!$common) continue;
            $list = implode(', ', array_map($q, $common));
            $pdo->exec('DELETE FROM main.' . $q($name));
            $pdo->exec('INSERT INTO main.' . $q($name) . ' (' . $list . ') SELECT ' . $list . ' FROM r.' . $q($name));
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) { $pdo->exec('ROLLBACK'); throw $e; }
    finally { try { $pdo->exec('DETACH DATABASE r'); } catch (Throwable $e) { /* egal */ } }
}
