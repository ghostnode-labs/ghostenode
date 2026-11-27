<?php
/**
 * 🛰 Föderation (8.1) – mehrere GhostNode-/Nexus-Server tauschen öffentliche Clubs und Profile aus.
 *   fed.php?r=info     Server-Name, Adresse, öffentlicher Schlüssel (Ed25519)
 *   fed.php?r=export   signierte Liste: öffentliche, gelistete Clubs (nie 18+) + Profile, die „Föderation“ ausdrücklich erlauben
 *   fed.php            Übersicht „Von Partner-Servern“
 * Partner trägt nur der Inhaber ein (Admin → System). Beim Eintragen wird der Schlüssel des Partners angeheftet (TOFU);
 * ändert er sich später, wird nichts mehr übernommen, bis der Partner neu bestätigt wird. Abgleich stündlich (Aufgabe „federation“).
 * Übertragen wird nur, was ohnehin öffentlich ist – keine VRChat-IDs, keine E-Mails, keine Mitgliederlisten.
 */
const FED_VERSION = 1;
const FED_MAX_BYTES = 2000000;
const FED_MAX_ITEMS = 500;
const FED_MAX_AGE = 86400;

function fed_on(): bool { return setting('fed_on', '0') === '1'; }

function fed_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('fed_schema', '0') === '1') return;
    site_db()->exec('CREATE TABLE IF NOT EXISTS fed_items (id INTEGER PRIMARY KEY AUTOINCREMENT, peer TEXT NOT NULL, kind TEXT NOT NULL, ref TEXT NOT NULL, data TEXT NOT NULL, seen INTEGER NOT NULL)');
    set_setting('fed_schema', '1');
}

/** Eigenes Schlüsselpaar (liegt in data/keys, nie in der Datenbank-Sicherung für Fremde). */
function fed_keypair(): array {
    $f = DATA_DIR . '/keys/federation.key';
    if (!is_file($f)) {
        if (!is_dir(dirname($f))) @mkdir(dirname($f), 0700, true);
        $kp = sodium_crypto_sign_keypair();
        file_put_contents($f, base64_encode($kp), LOCK_EX);
        @chmod($f, 0600);
    }
    $kp = base64_decode((string)file_get_contents($f), true);
    if ($kp === false || strlen($kp) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) throw new RuntimeException('Föderations-Schlüssel beschädigt.');
    return ['sk' => sodium_crypto_sign_secretkey($kp), 'pk' => sodium_crypto_sign_publickey($kp)];
}
function fed_pubkey_b64(): string { return base64_encode(fed_keypair()['pk']); }
function fed_sign(string $body): string { return base64_encode(sodium_crypto_sign_detached($body, fed_keypair()['sk'])); }
function fed_verify(string $body, string $sigB64, string $pkB64): bool {
    $sig = base64_decode($sigB64, true); $pk = base64_decode($pkB64, true);
    if ($sig === false || $pk === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return false;
    return sodium_crypto_sign_verify_detached($sig, $body, $pk);
}

function fed_base(): string { require_once __DIR__ . '/market.php'; return mk_base_url(); }
function fed_info(): array {
    return ['software' => 'ghostnode', 'federation' => FED_VERSION, 'name' => setting('site_title', 'GhostNode') ?: 'GhostNode', 'url' => fed_base(), 'key' => fed_pubkey_b64()];
}

/** Was dieser Server teilt: gelistete, aktive Clubs ohne 18+ und Profile mit public + federate. */
function fed_local_items(): array {
    $items = [];
    try {
        require_once __DIR__ . '/nexuscomm.php';
        $db = nc_db();
        $rows = $db->query("SELECT * FROM clubs WHERE status = 'active' AND listed = 1 ORDER BY id DESC LIMIT " . FED_MAX_ITEMS)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $c) {
            if (!empty($c['adult'])) continue;
            $n = $db->prepare("SELECT COUNT(*) FROM club_members WHERE club_id = ? AND status = 'active'"); $n->execute([(int)$c['id']]);
            $items[] = ['kind' => 'club', 'ref' => (string)$c['slug'], 'name' => mb_substr((string)$c['name'], 0, 80), 'text' => mb_substr((string)$c['descr'], 0, 300),
                'lang' => (string)$c['lang'], 'topic' => (string)$c['topic'], 'members' => (int)$n->fetchColumn(), 'url' => fed_base() . '/nexus.php?club=' . rawurlencode((string)$c['slug'])];
        }
    } catch (Throwable $e) { /* Community-Datenbank fehlt → nur Profile */ }
    try {
        require_once __DIR__ . '/me.php';
        me_schema();
        $st = site_db()->query("SELECT p.user_id, p.slug, p.data, u.display_name, u.username FROM user_prefs p JOIN users u ON u.id = p.user_id WHERE p.public = 1 AND p.slug <> '' ORDER BY p.user_id LIMIT " . FED_MAX_ITEMS);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string)$r['data'], true) ?: [];
            if (empty($d['federate'])) continue;
            $items[] = ['kind' => 'profile', 'ref' => (string)$r['slug'], 'name' => mb_substr((string)($r['display_name'] ?: $r['slug']), 0, 60), 'text' => mb_substr((string)($d['bio'] ?? ''), 0, 300), 'url' => fed_base() . '/u.php?n=' . rawurlencode((string)$r['slug'])];
        }
    } catch (Throwable $e) { /* egal */ }
    return array_slice($items, 0, FED_MAX_ITEMS);
}
/** Signierter Export → [Body, Signatur] */
function fed_export(): array {
    $body = json_encode(['federation' => FED_VERSION, 'server' => fed_base(), 'ts' => time(), 'items' => fed_local_items()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [$body, fed_sign($body)];
}

// ---------------------------------------------------------------- Partner
function fed_peers(): array { $p = json_decode(setting('fed_peers', '[]'), true); return is_array($p) ? $p : []; }
function fed_peers_save(array $p): void { set_setting('fed_peers', json_encode(array_values($p), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
function fed_norm_url(string $u): string {
    $u = rtrim(trim($u), '/');
    $p = parse_url($u);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['query']) || isset($p['fragment']) || isset($p['user'])) throw new InvalidArgumentException('Partner-Adresse muss mit https:// beginnen (ohne ? und #).');
    if (filter_var($p['host'], FILTER_VALIDATE_IP) || !preg_match('/\.[a-z]{2,}$/i', $p['host']) || preg_match('/(^|\.)(localhost|local|internal|lan)$/i', $p['host'])) throw new InvalidArgumentException('Nur öffentliche Domains als Partner.');
    return strtolower($p['scheme'] . '://' . $p['host']) . (isset($p['port']) ? ':' . (int)$p['port'] : '') . ($p['path'] ?? '');
}
/** Standard-Abruf (https, kurz, begrenzt). In Tests ersetzbar. → [Status, Body, Signatur-Header] */
function fed_http_get(string $url): array {
    $ch = curl_init($url);
    $sig = '';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'GhostNode-Federation/' . FED_VERSION, CURLOPT_MAXFILESIZE => FED_MAX_BYTES,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$sig) { if (stripos($h, 'x-ghostnode-fed-sig:') === 0) $sig = trim(substr($h, 20)); return strlen($h); }]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, is_string($body) ? substr($body, 0, FED_MAX_BYTES) : '', $sig];
}
/** Partner eintragen: Info holen, Schlüssel anheften. */
function fed_peer_add(string $url, ?callable $get = null): array {
    $get = $get ?? 'fed_http_get';
    $url = fed_norm_url($url);
    if ($url === rtrim(fed_base(), '/')) throw new InvalidArgumentException('Das ist dieser Server selbst.');
    $peers = fed_peers();
    foreach ($peers as $p) if ($p['url'] === $url) throw new InvalidArgumentException('Partner gibt es schon.');
    if (count($peers) >= 50) throw new InvalidArgumentException('Höchstens 50 Partner.');
    [$code, $body] = $get($url . '/fed.php?r=info');
    $info = json_decode((string)$body, true);
    if ($code !== 200 || !is_array($info) || ($info['software'] ?? '') !== 'ghostnode' || !is_string($info['key'] ?? null) || strlen((string)base64_decode($info['key'], true)) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)
        throw new InvalidArgumentException('Unter dieser Adresse antwortet kein GhostNode-Server mit Föderation.');
    $peer = ['url' => $url, 'name' => mb_substr(strip_tags((string)($info['name'] ?? $url)), 0, 60), 'key' => $info['key'], 'added' => time(), 'last' => 0, 'ok' => true, 'msg' => '', 'count' => 0];
    $peers[] = $peer;
    fed_peers_save($peers);
    audit('Föderation: Partner eingetragen', $url);
    return $peer;
}
function fed_peer_remove(string $url): void {
    fed_peers_save(array_filter(fed_peers(), fn($p) => $p['url'] !== $url));
    fed_schema();
    site_db()->prepare('DELETE FROM fed_items WHERE peer = ?')->execute([$url]);
    audit('Föderation: Partner entfernt', $url);
}

/** Eintrag eines Partners säubern (nur bekannte Felder, Links nur auf den Partner selbst). */
function fed_clean_item(array $it, string $peer): ?array {
    $kind = (string)($it['kind'] ?? '');
    if (!in_array($kind, ['club', 'profile'], true)) return null;
    $ref = (string)($it['ref'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $ref)) return null;
    $url = (string)($it['url'] ?? '');
    if (strpos($url, $peer . '/') !== 0) $url = $peer;
    $s = fn($v, $n) => mb_substr(trim(strip_tags((string)$v)), 0, $n);
    return ['kind' => $kind, 'ref' => $ref, 'name' => $s($it['name'] ?? $ref, 80), 'text' => $s($it['text'] ?? '', 300), 'lang' => $s($it['lang'] ?? '', 8), 'topic' => $s($it['topic'] ?? '', 30),
        'members' => max(0, (int)($it['members'] ?? 0)), 'url' => $url];
}

/** Einen Partner abgleichen → Anzahl übernommener Einträge (wirft bei Fehler; Partner-Status wird gespeichert). */
function fed_pull(string $url, ?callable $get = null): int {
    $get = $get ?? 'fed_http_get';
    fed_schema();
    $peers = fed_peers();
    $i = null; foreach ($peers as $k => $p) if ($p['url'] === $url) $i = $k;
    if ($i === null) throw new InvalidArgumentException('Unbekannter Partner.');
    $peer = $peers[$i];
    try {
        [$code, $body, $sig] = $get($url . '/fed.php?r=export');
        if ($code !== 200) throw new RuntimeException('HTTP ' . $code);
        if (!fed_verify((string)$body, (string)$sig, $peer['key'])) throw new RuntimeException('Signatur ungültig – Schlüssel geändert? Partner neu eintragen, wenn das gewollt ist.');
        $j = json_decode((string)$body, true);
        if (!is_array($j) || (int)($j['federation'] ?? 0) !== FED_VERSION) throw new RuntimeException('Unbekanntes Format.');
        if (abs(time() - (int)($j['ts'] ?? 0)) > FED_MAX_AGE) throw new RuntimeException('Antwort zu alt.');
        if (rtrim((string)($j['server'] ?? ''), '/') !== $url) throw new RuntimeException('Antwort gehört zu einem anderen Server.');
        $items = [];
        foreach (array_slice((array)($j['items'] ?? []), 0, FED_MAX_ITEMS) as $it) { $c = is_array($it) ? fed_clean_item($it, $url) : null; if ($c) $items[$c['kind'] . ':' . $c['ref']] = $c; }
        $db = site_db();
        $db->beginTransaction();
        $db->prepare('DELETE FROM fed_items WHERE peer = ?')->execute([$url]);
        $ins = $db->prepare('INSERT INTO fed_items (peer, kind, ref, data, seen) VALUES (?, ?, ?, ?, ?)');
        foreach ($items as $c) $ins->execute([$url, $c['kind'], $c['ref'], json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time()]);
        $db->commit();
        $peers[$i] = array_merge($peer, ['last' => time(), 'ok' => true, 'msg' => '', 'count' => count($items)]);
        fed_peers_save($peers);
        return count($items);
    } catch (Throwable $e) {
        if (site_db()->inTransaction()) site_db()->rollBack();
        $peers[$i] = array_merge($peer, ['last' => time(), 'ok' => false, 'msg' => mb_substr($e->getMessage(), 0, 200)]);
        fed_peers_save($peers);
        throw $e;
    }
}
/** Aufgabe „federation“ */
function fed_task(?callable $get = null): string {
    if (!fed_on()) return 'aus';
    $ok = 0; $bad = 0; $n = 0;
    foreach (fed_peers() as $p) { try { $n += fed_pull($p['url'], $get); $ok++; } catch (Throwable $e) { $bad++; } }
    return $ok . ' Partner abgeglichen, ' . $n . ' Einträge' . ($bad ? ', ' . $bad . ' Fehler' : '');
}
function fed_items(string $kind = '', string $q = ''): array {
    fed_schema();
    $sql = 'SELECT peer, data FROM fed_items' . ($kind !== '' ? ' WHERE kind = ?' : '') . ' ORDER BY peer, id LIMIT 2000';
    $st = site_db()->prepare($sql); $st->execute($kind !== '' ? [$kind] : []);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = json_decode((string)$r['data'], true) ?: [];
        if ($q !== '' && mb_stripos(($d['name'] ?? '') . ' ' . ($d['text'] ?? ''), $q) === false) continue;
        $d['peer'] = $r['peer'];
        $out[] = $d;
    }
    return $out;
}

// ---------------------------------------------------------------- 8.2: 18+-Prüfung für die Spielzeug-Fernsteuerung über Partner-Server
/** Darf dieser Server Partner nach 18+-Freigaben fragen bzw. ihnen antworten? (Inhaber-Schalter, ab Werk aus) */
function fed_remote_on(): bool { return fed_on() && setting('fed_remote', '0') === '1'; }
/** HTTPS-POST (kurz, begrenzt) → [Status, Body, Signatur-Header]. In Tests ersetzbar. */
function fed_http_post(string $url, string $body, array $headers): array {
    $ch = curl_init($url);
    $sig = '';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'GhostNode-Federation/' . FED_VERSION, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$sig) { if (stripos($h, 'x-ghostnode-fed-sig:') === 0) $sig = trim(substr($h, 20)); return strlen($h); }]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, is_string($res) ? substr($res, 0, 4096) : '', $sig];
}
/** Fragen: Hat diese VRChat-ID bei einem Partner-Server eine gültige 18+-Freigabe? → true/false (nie Details). */
function fed_peer18(string $uid, ?callable $post = null): bool {
    $post = $post ?? 'fed_http_post';
    $uid = strtolower(trim($uid));
    if (!fed_remote_on() || !preg_match('/^usr_[0-9a-f-]{36}$/', $uid)) return false;
    static $cache = [];
    if (isset($cache[$uid]) && $cache[$uid][1] > time() - 300) return $cache[$uid][0];
    $ok = false;
    foreach (fed_peers() as $p) {
        $body = json_encode(['federation' => FED_VERSION, 'from' => rtrim(fed_base(), '/'), 'to' => $p['url'], 'uid' => $uid, 'ts' => time(), 'n' => bin2hex(random_bytes(8))], JSON_UNESCAPED_SLASHES);
        try {
            [$code, $res, $sig] = $post($p['url'] . '/fed.php?r=peer18', $body, ['X-GhostNode-Fed-Sig: ' . fed_sign($body)]);
            if ($code !== 200 || !fed_verify((string)$res, (string)$sig, $p['key'])) continue;
            $j = json_decode((string)$res, true);
            if (!is_array($j) || ($j['uid'] ?? '') !== $uid || abs(time() - (int)($j['ts'] ?? 0)) > 120 || rtrim((string)($j['server'] ?? ''), '/') !== $p['url']) continue;
            if (!empty($j['nsfw'])) { $ok = true; break; }
        } catch (Throwable $e) { /* nächster Partner */ }
    }
    $cache[$uid] = [$ok, time()];
    return $ok;
}
/** Antworten (fed.php?r=peer18): nur signierten Anfragen eingetragener Partner, nur ja/nein. → [Body, Signatur] */
function fed_peer18_answer(string $body, string $sig, callable $lookup): array {
    if (!fed_remote_on()) throw new RuntimeException('Auf diesem Server aus.');
    $j = json_decode($body, true);
    if (!is_array($j) || (int)($j['federation'] ?? 0) !== FED_VERSION) throw new RuntimeException('Unbekanntes Format.');
    $from = rtrim((string)($j['from'] ?? ''), '/');
    $peer = null; foreach (fed_peers() as $p) if ($p['url'] === $from) $peer = $p;
    if (!$peer) throw new RuntimeException('Kein eingetragener Partner.');
    if (!fed_verify($body, $sig, $peer['key'])) throw new RuntimeException('Signatur ungültig.');
    if (abs(time() - (int)($j['ts'] ?? 0)) > 120) throw new RuntimeException('Anfrage zu alt.');
    if (rtrim((string)($j['to'] ?? ''), '/') !== rtrim(fed_base(), '/')) throw new RuntimeException('Anfrage für einen anderen Server.');
    $uid = strtolower((string)($j['uid'] ?? ''));
    if (!preg_match('/^usr_[0-9a-f-]{36}$/', $uid)) throw new RuntimeException('Ungültige ID.');
    $out = json_encode(['federation' => FED_VERSION, 'server' => rtrim(fed_base(), '/'), 'uid' => $uid, 'nsfw' => (bool)$lookup($uid), 'ts' => time()], JSON_UNESCAPED_SLASHES);
    return [$out, fed_sign($out)];
}
