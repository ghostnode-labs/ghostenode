<?php
/**
 * GhostNode-Plugin-Marktplatz für VRC Nexus UND D2 Nexus: Plugins (.nxp) einreichen, prüfen, freigeben und als signierten Katalog ausliefern.
 *  - Ein Format für beide Apps: plugin.json "apps": ["vrc","d2"] (fehlt es: nur VRC Nexus). Jede App lädt nur, was zu ihr passt.
 *  - Tags (plugin.json "tags" + beim Einreichen) und Kategorien zum Filtern.
 *
 *  - Speicher: DATA_DIR/market/plugins.json (Katalog), DATA_DIR/market/pkg/<id>-<version>.nxp (Pakete)
 *  - Signatur: Ed25519 (libsodium). Der Schlüssel wird beim ersten Aufruf erzeugt und liegt nur in DATA_DIR/market/sign.key
 *    (nie im Repo). Die App merkt sich den öffentlichen Schlüssel und prüft Katalog + SHA-256 jedes Pakets.
 *  - Rechte: market.submit (einreichen, eigene Plugins aktualisieren), market.manage (prüfen, freigeben, löschen)
 *  - Öffentlich sichtbar und herunterladbar sind nur freigegebene Versionen.
 */
require_once __DIR__ . '/../core/common.php';
require_once __DIR__ . '/../core/auth.php';

const MK_PERMS = ['chatbox' => ['💬', 'Chatbox schreiben'], 'osc' => ['🎛', 'Avatar-Parameter (OSC)'], 'notify' => ['🔔', 'Benachrichtigungen'],
    'game' => ['🎮', 'Spiel-Ereignisse'], 'net' => ['🌐', 'Internet-Zugriff'], 'vrchat' => ['🟦', 'VRChat-Gruppen verwalten'],
    'destiny' => ['💠', 'Destiny-2-Daten lesen'], 'ghostnode' => ['◈', 'GhostNode-Konto (Web-API mit deiner Anmeldung)']];
/** Apps („Spiele“), für die ein Plugin gebaut ist */
const MK_APPS = ['vrc' => ['🥽', 'VRChat', 'VRC Nexus'], 'd2' => ['💠', 'Destiny 2', 'D2 Nexus']];
/** Rechte, die nur eine App kennt (die andere ignoriert sie) */
const MK_PERM_APPS = ['chatbox' => ['vrc'], 'osc' => ['vrc'], 'game' => ['vrc'], 'vrchat' => ['vrc'], 'destiny' => ['d2']];
const MK_CATS = ['tools' => '🛠 Werkzeuge', 'chatbox' => '💬 Chatbox', 'osc' => '🎛 OSC & Avatar', 'social' => '👥 Freunde, Clan & Gruppen', 'stream' => '📺 Stream',
    'inventory' => '🎒 Inventar & Loadouts', 'stats' => '📊 Statistik', 'admin' => '🛡 Admin & Moderation', 'fun' => '🎉 Spaß', 'other' => '🧩 Sonstiges'];
const MK_TAG = '/^[a-z0-9äöüß][a-z0-9äöüß-]{1,19}$/u';
const MK_MAX_PACK = 6 * 1024 * 1024;
const MK_MAX_UNPACKED = 8 * 1024 * 1024;
const MK_ID = '/^[a-z0-9][a-z0-9-]{1,39}$/';
const MK_VER = '/^\d{1,3}\.\d{1,3}\.\d{1,4}$/';

function mk_dir(): string { $d = DATA_DIR . '/market'; if (!is_dir($d . '/pkg')) @mkdir($d . '/pkg', 0775, true); return $d; }

/** Katalog lesen/schreiben (mit Sperre) */
function mk_load(): array { $f = mk_dir() . '/plugins.json'; $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : []; return is_array($j) ? $j : []; }
function mk_update(callable $fn) {
    $fp = fopen(mk_dir() . '/plugins.json', 'c+');
    if (!$fp) throw new RuntimeException('Katalog nicht beschreibbar.');
    flock($fp, LOCK_EX);
    $all = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($all)) $all = [];
    try { $ret = $fn($all); } catch (Throwable $e) { flock($fp, LOCK_UN); fclose($fp); throw $e; }
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return $ret;
}

/** Signaturschlüssel (Ed25519) – wird einmalig erzeugt, bleibt auf dem Server */
function mk_keypair(): string {
    $f = mk_dir() . '/sign.key';
    if (is_file($f)) { $k = base64_decode((string)file_get_contents($f), true); if ($k !== false && strlen($k) === SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) return $k; }
    $k = sodium_crypto_sign_keypair();
    file_put_contents($f, base64_encode($k), LOCK_EX);
    @chmod($f, 0600);
    return $k;
}
function mk_public_key(): string { return base64_encode(sodium_crypto_sign_publickey(mk_keypair())); }

/** Zip prüfen und plugin.json lesen (gleiche Regeln wie die App) */
function mk_inspect(string $file): array {
    if (filesize($file) > MK_MAX_PACK) throw new InvalidArgumentException('Paket zu groß (max. 6 MB).');
    $z = new ZipArchive();
    if ($z->open($file) !== true) throw new InvalidArgumentException('Keine gültige .nxp-Datei (ZIP erwartet).');
    if ($z->numFiles > 200) { $z->close(); throw new InvalidArgumentException('Zu viele Dateien (max. 200).'); }
    $total = 0; $manifest = null;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $st = $z->statIndex($i); $n = (string)$st['name'];
        if ($n === '' || $n[0] === '/' || strpos($n, '\\') !== false || preg_match('#(^|/)\.\.(/|$)#', $n) || preg_match('/^[a-zA-Z]:/', $n)) { $z->close(); throw new InvalidArgumentException('Unerlaubter Pfad im Paket: ' . $n); }
        $total += (int)$st['size'];
        if ((int)$st['size'] > 3 * 1024 * 1024) { $z->close(); throw new InvalidArgumentException('Datei zu groß (max. 3 MB): ' . $n); }
        if ($n === 'plugin.json') $manifest = $z->getFromIndex($i);
    }
    $z->close();
    if ($total > MK_MAX_UNPACKED) throw new InvalidArgumentException('Entpackt zu groß (max. 8 MB).');
    $m = json_decode((string)$manifest, true);
    if (!is_array($m)) throw new InvalidArgumentException('plugin.json fehlt oder ist kein gültiges JSON.');
    if ((int)($m['format'] ?? 0) !== 1) throw new InvalidArgumentException('plugin.json: "format" muss 1 sein.');
    $id = (string)($m['id'] ?? ''); $ver = (string)($m['version'] ?? '');
    if (!preg_match(MK_ID, $id)) throw new InvalidArgumentException('plugin.json: ungültige "id" (a–z, 0–9, -, 2–40 Zeichen).');
    if (!preg_match(MK_VER, $ver)) throw new InvalidArgumentException('plugin.json: "version" im Format 1.2.3 angeben.');
    $perms = array_values(array_unique(array_map('strval', (array)($m['permissions'] ?? []))));
    foreach ($perms as $p) if (!isset(MK_PERMS[$p])) throw new InvalidArgumentException('plugin.json: unbekanntes Recht „' . $p . '“.');
    $groups = array_values(array_filter(array_map('strval', (array)($m['groups'] ?? [])), fn($g) => preg_match('/^grp_[0-9a-f-]{36}$/i', $g)));
    if (in_array('vrchat', $perms, true) && !$groups) throw new InvalidArgumentException('Recht „vrchat“ braucht "groups".');
    $apps = array_values(array_intersect(array_keys(MK_APPS), array_map('strval', (array)($m['apps'] ?? ['vrc']))));
    if (!$apps) throw new InvalidArgumentException('plugin.json: "apps" muss "vrc" und/oder "d2" enthalten.');
    foreach ($perms as $p) if (isset(MK_PERM_APPS[$p]) && !array_intersect(MK_PERM_APPS[$p], $apps)) throw new InvalidArgumentException('Recht „' . $p . '“ passt zu keiner der angegebenen Apps.');
    $cut = fn($v, $n) => mb_substr(trim(preg_replace('/\s+/', ' ', (string)$v)), 0, $n);
    $home = (string)($m['homepage'] ?? '');
    return ['id' => $id, 'version' => $ver, 'name' => $cut($m['name'] ?? $id, 60) ?: $id, 'author' => $cut($m['author'] ?? '', 60), 'description' => $cut($m['description'] ?? '', 500),
        'icon' => mb_substr((string)($m['icon'] ?? '🧩'), 0, 4) ?: '🧩', 'nexus' => preg_replace('/[^0-9.<>=]/', '', (string)($m['nexus'] ?? '')), 'permissions' => $perms, 'groups' => array_slice($groups, 0, 5),
        'homepage' => preg_match('#^https://[^\s"<>]{3,300}$#', $home) ? $home : '', 'apps' => $apps, 'tags' => mk_tags((array)($m['tags'] ?? []))];
}
/** Tags säubern: klein, 2–20 Zeichen, höchstens 8 */
function mk_tags($raw): array {
    $list = is_array($raw) ? $raw : preg_split('/[\s,;#]+/u', (string)$raw);
    $out = [];
    foreach ($list as $t) { $t = mb_strtolower(trim((string)$t)); if (preg_match(MK_TAG, $t) && !in_array($t, $out, true)) $out[] = $t; }
    return array_slice($out, 0, 8);
}

/** Einreichen: neue Version (Status „wartet auf Prüfung“, außer Prüfer reichen selbst ein) */
function mk_submit(array $file, string $category, string $changelog, string $tags = ''): array {
    $u = current_user();
    if (!$u || !can('market.submit') && !can('market.manage')) throw new InvalidArgumentException('Keine Berechtigung zum Einreichen.');
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new InvalidArgumentException('Hochladen fehlgeschlagen (max. ' . ini_get('upload_max_filesize') . ').');
    $m = mk_inspect($file['tmp_name']);
    $sha = hash_file('sha256', $file['tmp_name']);
    $dest = mk_dir() . '/pkg/' . $m['id'] . '-' . $m['version'] . '.nxp';
    $auto = can('market.manage');
    $m['tags'] = array_slice(array_values(array_unique(array_merge($m['tags'], mk_tags($tags)))), 0, 8);
    $r = mk_update(function (array &$all) use ($m, $u, $sha, $dest, $file, $category, $changelog, $auto) {
        $p = $all[$m['id']] ?? null;
        if ($p && (int)$p['owner'] !== (int)$u['id'] && !$auto) throw new InvalidArgumentException('Diese Plugin-ID gehört schon jemand anderem.');
        foreach (($p['versions'] ?? []) as $v) if ($v['version'] === $m['version']) throw new InvalidArgumentException('Version ' . $m['version'] . ' gibt es schon – bitte die Versionsnummer erhöhen.');
        if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('Paket konnte nicht gespeichert werden.');
        $p = $p ?: ['id' => $m['id'], 'owner' => (int)$u['id'], 'created' => time(), 'downloads' => 0, 'versions' => []];
        $p['category'] = isset(MK_CATS[$category]) ? $category : ($p['category'] ?? 'other');
        $p['versions'][] = $m + ['sha256' => $sha, 'size' => filesize($dest), 'uploaded' => time(), 'by' => (int)$u['id'], 'status' => $auto ? 'approved' : 'pending',
            'changelog' => mb_substr(trim($changelog), 0, 1000), 'note' => ''];
        $all[$m['id']] = $p;
        return $p;
    });
    audit('Plugin eingereicht', $m['id'] . ' ' . $m['version'], 'info');
    return ['plugin' => $m, 'status' => $auto ? 'approved' : 'pending'];
}

function mk_review(string $id, string $version, string $decision, string $note, ?array $as = null): void {
    if (!($as ? in_array('market.manage', (array)($as['permissions'] ?? []), true) : can('market.manage'))) throw new InvalidArgumentException('Keine Berechtigung.');
    if (!in_array($decision, ['approved', 'rejected', 'pending'], true)) throw new InvalidArgumentException('Ungültige Entscheidung.');
    mk_update(function (array &$all) use ($id, $version, $decision, $note) {
        if (!isset($all[$id])) throw new InvalidArgumentException('Plugin nicht gefunden.');
        foreach ($all[$id]['versions'] as &$v) if ($v['version'] === $version) { $v['status'] = $decision; $v['note'] = mb_substr(trim($note), 0, 500); $v['reviewed'] = time(); return; }
        throw new InvalidArgumentException('Version nicht gefunden.');
    });
    audit('Plugin geprüft', $id . ' ' . $version . ' → ' . $decision, 'info');
}

function mk_delete(string $id, string $version = ''): void {
    $u = current_user();
    mk_update(function (array &$all) use ($id, $version, $u) {
        if (!isset($all[$id])) throw new InvalidArgumentException('Plugin nicht gefunden.');
        if (!can('market.manage') && (!$u || (int)$all[$id]['owner'] !== (int)$u['id'])) throw new InvalidArgumentException('Keine Berechtigung.');
        foreach ($all[$id]['versions'] as $i => $v) if ($version === '' || $v['version'] === $version) { @unlink(mk_dir() . '/pkg/' . $id . '-' . $v['version'] . '.nxp'); unset($all[$id]['versions'][$i]); }
        $all[$id]['versions'] = array_values($all[$id]['versions']);
        if (!$all[$id]['versions']) unset($all[$id]);
    });
    audit('Plugin gelöscht', $id . ($version ? ' ' . $version : ''), 'info');
}

/** neueste freigegebene Version */
function mk_latest(array $p): ?array {
    $ok = array_values(array_filter($p['versions'] ?? [], fn($v) => $v['status'] === 'approved'));
    if (!$ok) return null;
    usort($ok, fn($a, $b) => version_compare($b['version'], $a['version']));
    return $ok[0];
}
function mk_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

/** Öffentlicher, signierter Katalog für die App */
function mk_index(): array {
    $out = [];
    foreach (mk_load() as $p) {
        $v = mk_latest($p);
        if (!$v) continue;
        $out[] = ['id' => $p['id'], 'name' => $v['name'], 'version' => $v['version'], 'author' => $v['author'], 'description' => $v['description'], 'icon' => $v['icon'],
            'category' => $p['category'] ?? 'other', 'apps' => $v['apps'] ?? ['vrc'], 'tags' => $v['tags'] ?? [], 'permissions' => $v['permissions'], 'groups' => $v['groups'], 'nexus' => $v['nexus'], 'homepage' => $v['homepage'],
            'size' => $v['size'], 'sha256' => $v['sha256'], 'updated' => $v['uploaded'], 'downloads' => (int)($p['downloads'] ?? 0), 'changelog' => $v['changelog'] ?? '',
            'url' => mk_base_url() . '/market.php?dl=' . rawurlencode($p['id']) . '&v=' . rawurlencode($v['version'])];
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    $index = json_encode(['format' => 1, 'generated' => time(), 'apps' => array_keys(MK_APPS), 'plugins' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $kp = mk_keypair();
    return ['index' => $index, 'sig' => base64_encode(sodium_crypto_sign_detached($index, sodium_crypto_sign_secretkey($kp))), 'key' => base64_encode(sodium_crypto_sign_publickey($kp))];
}

/** Paket ausliefern (nur freigegeben; Prüfer dürfen auch wartende laden) */
function mk_download(string $id, string $version): void {
    $all = mk_load(); $p = $all[$id] ?? null; $v = null;
    foreach (($p['versions'] ?? []) as $x) if ($x['version'] === $version) $v = $x;
    if (!$v || ($v['status'] !== 'approved' && !can('market.manage'))) { http_response_code(404); exit('Nicht gefunden.'); }
    $f = mk_dir() . '/pkg/' . $id . '-' . $version . '.nxp';
    if (!is_file($f)) { http_response_code(404); exit('Nicht gefunden.'); }
    if ($v['status'] === 'approved') mk_update(function (array &$all) use ($id) { if (isset($all[$id])) $all[$id]['downloads'] = (int)($all[$id]['downloads'] ?? 0) + 1; });
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $id . '-' . $version . '.nxp"');
    header('Content-Length: ' . filesize($f));
    header('X-Content-Type-Options: nosniff');
    readfile($f);
    exit;
}

/** wartende Versionen (für Prüfer, auch über die Admin-API) */
function mk_pending(): array {
    $out = [];
    foreach (mk_load() as $p) foreach ($p['versions'] as $v) if ($v['status'] === 'pending')
        $out[] = ['id' => $p['id'], 'version' => $v['version'], 'name' => $v['name'], 'icon' => $v['icon'], 'author' => $v['author'], 'description' => $v['description'],
            'permissions' => $v['permissions'], 'apps' => $v['apps'] ?? ['vrc'], 'tags' => $v['tags'] ?? [], 'size' => $v['size'], 'sha256' => $v['sha256'], 'uploaded' => $v['uploaded'], 'changelog' => $v['changelog'] ?? ''];
    return $out;
}
