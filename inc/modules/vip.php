<?php
/**
 * VIP-Listen für VRChat-Welten (VIP-Tafel „NexusVipBoard“ aus dem App-Repo, apps/unity-tools).
 *
 *  - Beliebig viele Listen (je Welt z. B. „VIP“ und „Ko-fi“), Einträge mit Gruppe, Notiz und optionalem Ablaufdatum.
 *  - Öffentlicher Text-Endpunkt vip.php?l=<slug> im Format „GRUPPE|Anzeigename“ (eine Zeile je Eintrag, # = Kommentar);
 *    vip.php?l=<slug>&fmt=names = nur Anzeigenamen (eine Zeile je Name, z. B. für die „Url“ einer Rolle im Security Keypad).
 *    Es werden NUR Anzeigename + Gruppe ausgeliefert – Notizen, Quelle, Ablaufdatum bleiben intern; abgelaufene/ungeprüfte fehlen.
 *  - Ko-fi-Webhook (kofi.php): POST mit Formularfeld „data“ (JSON), verification_token wird mit dem gespeicherten Token verglichen.
 *    Gespeichert werden nur Anzeigename (aus „vrc: Name“ in der Nachricht, sonst from_name), Art/Stufe und Datum –
 *    nie E-Mail, Betrag, Nachricht oder Transaktions-ID. Doppelte Zustellungen erkennt ein Hash der message_id.
 *  - Gist-Spiegel: schreibt die Listen in ein (geheimes) GitHub-Gist, weil gist.githubusercontent.com in VRChat zu den
 *    vertrauenswürdigen Adressen fürs String Loading gehört. Token nur als Server-Secret (GN_GIST_TOKEN) oder verschlüsselt im Admin.
 *
 * Tabellen liegen in der Site-Datenbank (SQLite oder MySQL über die Db-Übersetzung); Einrichtung/Migration über vip_schema().
 */
require_once __DIR__ . '/../core/crypto.php';
require_once __DIR__ . '/nexuswarn.php';   // nw_rate_over (Bremse je IP)

const VIP_SCHEMA = '1';
const VIP_RATE = 60;          // Abrufe von vip.php je Minute und IP
const VIP_KOFI_RATE = 30;     // Webhook-Zustellungen je Minute und IP
const VIP_MAX_ENTRIES = 2000; // je Liste in der Ausgabe
const VIP_GIST_MIN_GAP = 60;  // Sekunden zwischen zwei Gist-Schreibvorgängen (Drosselung)
const VIP_KOFI_TYPES = ['Donation', 'Subscription', 'Shop Order', 'Commission'];

function vip_db(): PDO { return site_db(); }

/** Tabellen anlegen / migrieren (Version in settings.vip_schema) */
function vip_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = vip_db();
    if (setting('vip_schema', '0') === VIP_SCHEMA && in_array('vip_entries', db_tables($db), true)) return;
    // Migration 1
    $db->exec("CREATE TABLE IF NOT EXISTS vip_lists (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL UNIQUE, title TEXT NOT NULL DEFAULT '',
        world TEXT NOT NULL DEFAULT '', grp TEXT NOT NULL DEFAULT 'VIP', kofi INTEGER NOT NULL DEFAULT 0, gist INTEGER NOT NULL DEFAULT 1,
        created INTEGER NOT NULL DEFAULT 0, updated INTEGER NOT NULL DEFAULT 0)");
    $db->exec("CREATE TABLE IF NOT EXISTS vip_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, list_id INTEGER NOT NULL, name TEXT NOT NULL, nkey TEXT NOT NULL,
        grp TEXT NOT NULL DEFAULT 'VIP', note TEXT NOT NULL DEFAULT '', expires INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'ok',
        source TEXT NOT NULL DEFAULT 'admin', created INTEGER NOT NULL DEFAULT 0, updated INTEGER NOT NULL DEFAULT 0, UNIQUE (list_id, nkey))");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_vip_entries_list ON vip_entries (list_id, status)');
    $db->exec("CREATE TABLE IF NOT EXISTS vip_kofi_seen (mid TEXT PRIMARY KEY, ts INTEGER NOT NULL DEFAULT 0)");
    set_setting('vip_schema', VIP_SCHEMA);
}

// ---------- Hilfen ----------

/** Anzeigename säubern: keine Steuerzeichen/Zeilenumbrüche, kein „|“, kein führendes „#“, max. 64 Zeichen */
function vip_clean_name(string $s): string {
    $s = preg_replace('/[\x00-\x1F\x7F|]+/u', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    $s = ltrim($s, '#');
    return trim(mb_substr($s, 0, 64));
}
/** Vergleichsschlüssel: Groß/Klein und Leerzeichen am Rand egal (wie in der Welt) */
function vip_nkey(string $name): string { return mb_strtolower(vip_clean_name($name)); }
/** Gruppe: A–Z, 0–9, _ und - (max. 20), Großbuchstaben */
function vip_clean_grp(string $g, string $default = 'VIP'): string {
    $g = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $g) ?? '');
    return substr($g !== '' ? $g : $default, 0, 20);
}
function vip_clean_slug(string $s): string { return substr(trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($s)) ?? '', '-'), 0, 40); }
/** Datum „JJJJ-MM-TT“ (Ende des Tages) → Zeitstempel; leer = 0 (läuft nicht ab) */
function vip_parse_date(string $s): int {
    $s = trim($s);
    if ($s === '' || $s === '0') return 0;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw new InvalidArgumentException('Ablaufdatum bitte als JJJJ-MM-TT.');
    return (int)strtotime($s . ' 23:59:59');
}
function vip_base_url(): string {
    $u = rtrim(setting('vip_public_url', ''), '/');
    if ($u !== '') return $u;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

// ---------- Listen ----------

function vip_lists(): array {
    vip_schema();
    return vip_db()->query("SELECT l.*, (SELECT COUNT(*) FROM vip_entries e WHERE e.list_id = l.id AND e.status = 'ok') AS n,
        (SELECT COUNT(*) FROM vip_entries e WHERE e.list_id = l.id AND e.status = 'pending') AS pending FROM vip_lists l ORDER BY l.world, l.title, l.id")->fetchAll(PDO::FETCH_ASSOC);
}
function vip_list(int $id): ?array {
    vip_schema();
    $st = vip_db()->prepare('SELECT * FROM vip_lists WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function vip_list_by_slug(string $slug): ?array {
    vip_schema();
    $st = vip_db()->prepare('SELECT * FROM vip_lists WHERE slug = ?');
    $st->execute([vip_clean_slug($slug)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
/** Liste anlegen/ändern → ID. $in: id, slug, title, world, grp, kofi, gist */
function vip_list_save(array $in): int {
    vip_schema();
    $db = vip_db();
    $id = (int)($in['id'] ?? 0);
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 80);
    $slug = vip_clean_slug((string)($in['slug'] ?? '') ?: $title);
    if ($slug === '') throw new InvalidArgumentException('Bitte einen Namen bzw. eine Kurzadresse (a–z, 0–9, -) angeben.');
    $st = $db->prepare('SELECT id FROM vip_lists WHERE slug = ? AND id <> ?');
    $st->execute([$slug, $id]);
    if ($st->fetchColumn()) throw new InvalidArgumentException('Die Kurzadresse „' . $slug . '“ gibt es schon.');
    $row = [$slug, $title !== '' ? $title : $slug, mb_substr(trim((string)($in['world'] ?? '')), 0, 80), vip_clean_grp((string)($in['grp'] ?? '')),
            !empty($in['kofi']) ? 1 : 0, !empty($in['gist']) ? 1 : 0, time()];
    if ($id > 0) {
        if (!vip_list($id)) throw new InvalidArgumentException('Liste nicht gefunden.');
        $db->prepare('UPDATE vip_lists SET slug = ?, title = ?, world = ?, grp = ?, kofi = ?, gist = ?, updated = ? WHERE id = ?')->execute(array_merge($row, [$id]));
    } else {
        $db->prepare('INSERT INTO vip_lists (slug, title, world, grp, kofi, gist, updated, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_merge($row, [time()]));
        $id = (int)$db->lastInsertId();
    }
    if (!empty($in['kofi'])) {   // nur eine Liste empfängt Ko-fi
        set_setting('vip_kofi_list', (string)$id);
        $db->prepare('UPDATE vip_lists SET kofi = 0 WHERE id <> ?')->execute([$id]);
    } elseif (setting('vip_kofi_list', '') === (string)$id) set_setting('vip_kofi_list', '');
    vip_changed();
    return $id;
}
function vip_list_delete(int $id): void {
    vip_schema();
    vip_db()->prepare('DELETE FROM vip_entries WHERE list_id = ?')->execute([$id]);
    vip_db()->prepare('DELETE FROM vip_lists WHERE id = ?')->execute([$id]);
    if (setting('vip_kofi_list', '') === (string)$id) set_setting('vip_kofi_list', '');
    vip_changed();
}

// ---------- Einträge ----------

function vip_entries(int $list_id): array {
    vip_schema();
    $st = vip_db()->prepare("SELECT * FROM vip_entries WHERE list_id = ? ORDER BY CASE status WHEN 'pending' THEN 0 ELSE 1 END, grp, nkey");
    $st->execute([$list_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function vip_entry(int $id): ?array {
    vip_schema();
    $st = vip_db()->prepare('SELECT * FROM vip_entries WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
/**
 * Eintrag anlegen oder ändern (gleicher Name in derselben Liste = derselbe Eintrag) → ID.
 * $in: id?, name, grp, note, expires (Zeitstempel), status ok|pending, source admin|kofi
 */
function vip_entry_save(int $list_id, array $in): int {
    vip_schema();
    $list = vip_list($list_id);
    if (!$list) throw new InvalidArgumentException('Liste nicht gefunden.');
    $name = vip_clean_name((string)($in['name'] ?? ''));
    if (mb_strlen($name) < 1) throw new InvalidArgumentException('Bitte einen VRChat-Anzeigenamen angeben.');
    $key = vip_nkey($name);
    $grp = vip_clean_grp((string)($in['grp'] ?? ''), (string)$list['grp']);
    $note = mb_substr(trim(str_replace(["\r", "\n"], ' ', (string)($in['note'] ?? ''))), 0, 200);
    $exp = max(0, (int)($in['expires'] ?? 0));
    $status = ($in['status'] ?? 'ok') === 'pending' ? 'pending' : 'ok';
    $source = ($in['source'] ?? 'admin') === 'kofi' ? 'kofi' : 'admin';
    $db = vip_db();
    $id = (int)($in['id'] ?? 0);
    $st = $db->prepare('SELECT id FROM vip_entries WHERE list_id = ? AND nkey = ?');
    $st->execute([$list_id, $key]);
    $same = (int)$st->fetchColumn();
    if ($id > 0 && $same > 0 && $same !== $id) throw new InvalidArgumentException('„' . $name . '“ steht schon auf dieser Liste.');
    if ($id <= 0) $id = $same;
    if ($id > 0) {
        $db->prepare('UPDATE vip_entries SET name = ?, nkey = ?, grp = ?, note = ?, expires = ?, status = ?, updated = ? WHERE id = ? AND list_id = ?')
           ->execute([$name, $key, $grp, $note, $exp, $status, time(), $id, $list_id]);
    } else {
        $db->prepare('INSERT INTO vip_entries (list_id, name, nkey, grp, note, expires, status, source, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$list_id, $name, $key, $grp, $note, $exp, $status, $source, time(), time()]);
        $id = (int)$db->lastInsertId();
    }
    vip_changed();
    return $id;
}
function vip_entry_delete(int $id): void {
    vip_schema();
    vip_db()->prepare('DELETE FROM vip_entries WHERE id = ?')->execute([$id]);
    vip_changed();
}
function vip_entry_approve(int $id): void {
    vip_schema();
    vip_db()->prepare("UPDATE vip_entries SET status = 'ok', updated = ? WHERE id = ?")->execute([time(), $id]);
    vip_changed();
}

// ---------- Ausgabe (Textformat für die Welt) ----------

/** aktive Einträge (freigegeben, nicht abgelaufen) → [[name, grp], …] */
function vip_active(int $list_id, string $grp = ''): array {
    vip_schema();
    $sql = "SELECT name, grp FROM vip_entries WHERE list_id = ? AND status = 'ok' AND (expires = 0 OR expires > ?)";
    $args = [$list_id, time()];
    if ($grp !== '') { $sql .= ' AND grp = ?'; $args[] = vip_clean_grp($grp); }
    $st = vip_db()->prepare($sql . ' ORDER BY grp, nkey LIMIT ' . VIP_MAX_ENTRIES);
    $st->execute($args);
    return array_map(fn($r) => [(string)$r['name'], (string)$r['grp']], $st->fetchAll(PDO::FETCH_ASSOC));
}
/**
 * Text für die Welt. $fmt: '' = „GRUPPE|Name“ mit Kopfzeilen-Kommentar, 'names' = nur Namen (ohne Kommentare).
 * Absichtlich ohne Uhrzeit im Text, damit sich der Inhalt (und das Gist) nur ändert, wenn sich die Liste ändert.
 */
function vip_text(array $list, string $fmt = '', string $grp = ''): string {
    $rows = vip_active((int)$list['id'], $grp);
    if ($fmt === 'names') return implode("\n", array_map(fn($r) => $r[0], $rows)) . ($rows ? "\n" : '');
    $out = '# GhostNode VIP-Liste: ' . vip_clean_name((string)$list['title']) . "\n# Format: GRUPPE|Anzeigename\n";
    foreach ($rows as $r) $out .= $r[1] . '|' . $r[0] . "\n";
    return $out;
}

// ---------- Geheimnisse (Ko-fi-Token, Gist-Token) ----------

/** Server-Secret (Umgebung) vor verschlüsselter Admin-Einstellung; nie im Code */
function vip_secret(string $which): string {
    $env = $which === 'kofi' ? 'GN_KOFI_TOKEN' : 'GN_GIST_TOKEN';
    $v = trim((string)getenv($env));
    if ($v !== '') return $v;
    $enc = setting('vip_' . $which . '_token_enc', '');
    if ($enc === '') return '';
    $d = crypto_decrypt('vip', $enc);
    return is_string($d) ? $d : '';
}
function vip_secret_source(string $which): string {
    if (trim((string)getenv($which === 'kofi' ? 'GN_KOFI_TOKEN' : 'GN_GIST_TOKEN')) !== '') return 'env';
    return setting('vip_' . $which . '_token_enc', '') !== '' ? 'admin' : '';
}
function vip_secret_set(string $which, string $value): void {
    $value = trim($value);
    set_setting('vip_' . $which . '_token_enc', $value === '' ? '' : crypto_encrypt('vip', $value));
}

// ---------- Ko-fi-Webhook ----------

/** Name aus der Nachricht („vrc: Name“, „VRChat = Name“) – sonst null */
function vip_kofi_vrc_name(string $msg): ?string {
    if (preg_match('/(?:^|[\s(\[])(?:vrc|vrchat)\s*(?:name)?\s*[:=]\s*([^\r\n,;|()\[\]]{1,64})/iu', $msg, $m)) {
        $n = vip_clean_name($m[1]);
        if ($n !== '') return $n;
    }
    return null;
}
function vip_kofi_cfg(): array {
    return ['list' => (int)setting('vip_kofi_list', '0'), 'review' => setting('vip_kofi_review', '0') === '1', 'grp' => vip_clean_grp(setting('vip_kofi_group', 'KOFI'), 'KOFI'),
            'sub_days' => max(1, (int)setting('vip_kofi_sub_days', '35')), 'don_days' => max(0, (int)setting('vip_kofi_don_days', '0')),
            'types' => array_values(array_intersect(VIP_KOFI_TYPES, explode(',', setting('vip_kofi_types', 'Donation,Subscription,Shop Order,Commission'))))];
}
/**
 * Ko-fi-Zustellung verarbeiten. $data = Inhalt des Formularfelds „data“ (JSON) → [HTTP-Code, Text].
 * 200 auch bei Duplikat/ignorierter Art (sonst stellt Ko-fi erneut zu); 401 bei falschem Token; 503 ohne eingerichteten Token.
 */
function vip_kofi_handle(string $data): array {
    vip_schema();
    $token = vip_secret('kofi');
    if ($token === '') return [503, 'Ko-fi ist nicht eingerichtet.'];
    $j = json_decode($data, true);
    if (!is_array($j)) return [400, 'Ungültige Daten.'];
    if (!hash_equals($token, (string)($j['verification_token'] ?? ''))) return [401, 'Falsches Token.'];
    $mid = (string)($j['message_id'] ?? '');
    if ($mid === '' || strlen($mid) > 100) return [400, 'message_id fehlt.'];
    $h = hash('sha256', 'kofi|' . $mid);
    $db = vip_db();
    $ins = $db->prepare('INSERT OR IGNORE INTO vip_kofi_seen (mid, ts) VALUES (?, ?)');
    $ins->execute([$h, time()]);
    if ($ins->rowCount() !== 1) return [200, 'Schon verarbeitet.'];
    if (mt_rand(1, 50) === 1) $db->prepare('DELETE FROM vip_kofi_seen WHERE ts < ?')->execute([time() - 180 * 86400]);
    try { return vip_kofi_apply($j); }
    catch (Throwable $e) { $db->prepare('DELETE FROM vip_kofi_seen WHERE mid = ?')->execute([$h]); throw $e; }   // Fehler → Ko-fi darf erneut zustellen
}
/** Zustellung (Token geprüft, nicht doppelt) in die Ko-fi-Liste übernehmen */
function vip_kofi_apply(array $j): array {
    $db = vip_db();
    $c = vip_kofi_cfg();
    $type = (string)($j['type'] ?? '');
    if (!in_array($type, $c['types'], true)) return [200, 'Art ignoriert.'];
    $list = $c['list'] > 0 ? vip_list($c['list']) : null;
    if (!$list) return [200, 'Keine Ko-fi-Liste gewählt.'];
    $vrc = vip_kofi_vrc_name((string)($j['message'] ?? ''));
    $name = $vrc ?? vip_clean_name((string)($j['from_name'] ?? ''));
    if ($name === '' || vip_nkey($name) === 'ko-fi supporter') return [200, 'Kein Name.'];
    $sub = $type === 'Subscription' || !empty($j['is_subscription_payment']);
    $st = $db->prepare('SELECT * FROM vip_entries WHERE list_id = ? AND nkey = ?');
    $st->execute([(int)$list['id'], vip_nkey($name)]);
    $old = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    // Ablauf: Abo = ab heute bzw. ab bisherigem Ablauf (der spätere Wert) + X Tage; Einzelzahlung = X Tage oder dauerhaft (0)
    if ($old && (int)$old['expires'] === 0) $exp = 0;
    elseif ($sub) $exp = max(time(), (int)($old['expires'] ?? 0)) + $c['sub_days'] * 86400;
    elseif ($c['don_days'] === 0) $exp = 0;
    else $exp = max(time(), (int)($old['expires'] ?? 0)) + $c['don_days'] * 86400;
    $tier = mb_substr(vip_clean_name((string)($j['tier_name'] ?? '')), 0, 40);
    $private = array_key_exists('is_public', $j) && empty($j['is_public']);
    $status = $old ? (string)$old['status'] : (($c['review'] || $private) ? 'pending' : 'ok');
    $note = 'Ko-fi ' . ($sub ? 'Abo' : $type) . ($tier !== '' ? ' · ' . $tier : '') . ' · ' . date('d.m.Y') . ($vrc !== null ? ' · Name aus Nachricht' : '') . ($private && !$old ? ' · privat – bitte prüfen' : '');
    vip_entry_save((int)$list['id'], ['id' => $old ? (int)$old['id'] : 0, 'name' => $name, 'grp' => $old ? (string)$old['grp'] : $c['grp'], 'note' => $note,
        'expires' => $exp, 'status' => $status, 'source' => 'kofi']);
    audit('VIP-Liste: Ko-fi', $name . ' → ' . $list['slug'] . ($status === 'pending' ? ' (wartet auf Prüfung)' : ''), 'info', ['id' => null, 'username' => 'ko-fi']);
    return [200, 'OK'];
}

// ---------- Gist-Spiegel ----------

/** HTTP-Aufruf (für Tests austauschbar: $GLOBALS['__vip_http'] = fn($method, $url, $headers, $body) → [code, body]) */
function vip_http(string $method, string $url, array $headers, ?string $body): array {
    if (isset($GLOBALS['__vip_http']) && is_callable($GLOBALS['__vip_http'])) return ($GLOBALS['__vip_http'])($method, $url, $headers, $body);
    if (!function_exists('curl_init')) return [0, ''];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($raw) ? $raw : ''];
}
/** Dateien des Gists: <slug>.txt (GRUPPE|Name) und <slug>.names.txt (nur Namen) für alle Listen mit „Gist“ */
function vip_gist_files(): array {
    $files = [];
    foreach (vip_lists() as $l) {
        if (!(int)$l['gist']) continue;
        $files[$l['slug'] . '.txt'] = vip_text($l);
        $files[$l['slug'] . '.names.txt'] = vip_text($l, 'names') !== '' ? vip_text($l, 'names') : "\n";   // GitHub verweigert leere Dateien
    }
    if (!$files) $files['vip-liste.txt'] = "# GhostNode VIP-Liste (noch leer)\n";
    return $files;
}
function vip_gist_raw_url(string $file): string {
    $id = setting('vip_gist_id', ''); $owner = setting('vip_gist_owner', '');
    return $id !== '' && $owner !== '' ? 'https://gist.githubusercontent.com/' . rawurlencode($owner) . '/' . rawurlencode($id) . '/raw/' . rawurlencode($file) : '';
}
/** Nach jeder Änderung: Gist als „geändert“ markieren und (gedrosselt) gleich abgleichen */
function vip_changed(): void {
    set_setting('vip_gist_dirty', '1');
    if (setting('vip_gist_on', '0') === '1' && vip_secret('gist') !== '' && time() - (int)setting('vip_gist_last', '0') >= VIP_GIST_MIN_GAP && empty($GLOBALS['__vip_no_autosync'])) {
        try { vip_gist_sync(); } catch (Throwable $e) { set_setting('vip_gist_result', 'Fehler: ' . mb_substr($e->getMessage(), 0, 200)); }
    }
}
/**
 * Listen ins Gist schreiben → Ergebnistext. Schreibt nur, wenn sich der Inhalt geändert hat (Hash) oder $force.
 * Legt das Gist beim ersten Mal an (geheim, nicht öffentlich gelistet – die Raw-Adresse funktioniert trotzdem).
 */
function vip_gist_sync(bool $force = false): string {
    vip_schema();
    if (setting('vip_gist_on', '0') !== '1') return 'Gist-Spiegel ist aus.';
    $token = vip_secret('gist');
    if ($token === '') return 'Kein Gist-Token (GN_GIST_TOKEN) – Listen bitte manuell ins Gist kopieren.';
    $files = vip_gist_files();
    $hash = hash('sha256', json_encode($files));
    if (!$force && $hash === setting('vip_gist_hash', '') && setting('vip_gist_id', '') !== '') { set_setting('vip_gist_dirty', '0'); return 'Gist ist aktuell.'; }
    $prev = json_decode(setting('vip_gist_files', '[]'), true) ?: [];
    $payload = [];
    foreach ($files as $n => $content) $payload[$n] = ['content' => $content];
    foreach ($prev as $n) if (is_string($n) && !isset($files[$n])) $payload[$n] = null;   // entfernte Listen löschen
    $headers = ['Accept: application/vnd.github+json', 'Authorization: Bearer ' . $token, 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: GhostNode-VIP', 'Content-Type: application/json'];
    $id = setting('vip_gist_id', '');
    set_setting('vip_gist_last', (string)time());
    if ($id === '') {
        foreach ($payload as $n => $v) if ($v === null) unset($payload[$n]);
        [$code, $raw] = vip_http('POST', 'https://api.github.com/gists', $headers, json_encode(['description' => 'GhostNode VIP-Listen (automatisch)', 'public' => false, 'files' => $payload]));
    } else {
        [$code, $raw] = vip_http('PATCH', 'https://api.github.com/gists/' . rawurlencode($id), $headers, json_encode(['files' => (object)$payload]));
    }
    $j = json_decode((string)$raw, true);
    if (($code !== 200 && $code !== 201) || !is_array($j) || !preg_match('/^[0-9a-f]{20,40}$/i', (string)($j['id'] ?? ''))) {
        $msg = 'GitHub antwortete ' . (int)$code . ($code === 401 ? ' (Token ungültig)' : ($code === 404 ? ' (Gist nicht gefunden oder Token ohne Gist-Recht)' : ''));
        set_setting('vip_gist_result', $msg);
        throw new RuntimeException($msg);
    }
    set_setting('vip_gist_id', (string)$j['id']);
    $owner = (string)($j['owner']['login'] ?? '');
    if (preg_match('/^[A-Za-z0-9-]{1,39}$/', $owner)) set_setting('vip_gist_owner', $owner);
    set_setting('vip_gist_hash', $hash);
    set_setting('vip_gist_files', json_encode(array_keys($files)));
    set_setting('vip_gist_dirty', '0');
    $r = 'Gist ' . ($id === '' ? 'angelegt' : 'aktualisiert') . ' (' . count($files) . ' Dateien) · ' . date('d.m. H:i');
    set_setting('vip_gist_result', $r);
    return $r;
}
/** Aufgabenplaner: abgleichen, wenn sich etwas geändert hat – auch durch abgelaufene Einträge (Hash-Vergleich) */
function vip_task(): string {
    if (setting('vip_gist_on', '0') !== '1') return 'Gist-Spiegel aus';
    return vip_gist_sync();
}
