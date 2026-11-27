<?php
/**
 * 8.1: 🩺 Status-Seite für alle Dienste – Website, Datenbank, Nexus-Schnittstelle, HypeRate-Weiterleitung, Discord-Bot.
 * Geprüft von der Aufgabe „status“ (alle 15 min, Admin → System → Aufgaben). Verlauf und Störungen liegen in DATA_DIR/status.json.
 * Bei einem Wechsel (gestört ↔ wieder da) geht eine Meldung an den Status-Webhook (Einstellung „status_webhook“, z. B. Discord) – signiert wie die Mitglieder-Webhooks.
 * Externe Adressen sind einstellbar (Einstellung „status_targets“, JSON); ohne Einstellung die bekannten GhostNode-Dienste.
 */
require_once APP_ROOT . '/inc/core/common.php';

const ST_KEEP = 672;   // 7 Tage à 15 min
const ST_DEFAULT_TARGETS = [
    'hr'  => ['💓', 'HypeRate-Weiterleitung', 'https://hr.ghostnode-labs.dev/health'],
    'bot' => ['🤖', 'Discord-Bot & Dashboard', 'https://discord.ghostnode-labs.dev/health'],
];

function st_file(): string { return DATA_DIR . '/status.json'; }
function st_load(): array { $f = st_file(); $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; return is_array($j) ? $j + ['hist' => [], 'last' => [], 'incidents' => [], 'at' => 0] : ['hist' => [], 'last' => [], 'incidents' => [], 'at' => 0]; }
function st_save(array $s): void { $tmp = st_file() . '.tmp'; file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); rename($tmp, st_file()); }

/** Ziele: [id => [icon, name, url]] – nur https */
function st_targets(): array {
    $t = json_decode(setting('status_targets', ''), true);
    $out = [];
    foreach ((is_array($t) ? $t : ST_DEFAULT_TARGETS) as $id => $v) {
        if (!preg_match('/^[a-z0-9_-]{1,20}$/', (string)$id) || !is_array($v) || count($v) < 3 || !preg_match('#^https://[^\s]+$#', (string)$v[2])) continue;
        $out[$id] = [mb_substr((string)$v[0], 0, 4), mb_substr((string)$v[1], 0, 40), (string)$v[2]];
    }
    return $out;
}
/** einen externen Dienst abfragen → [ok, ms, msg]. $fetch(url) → [httpCode, body] (für Tests austauschbar) */
function st_probe(string $url, ?callable $fetch = null): array {
    $t0 = microtime(true);
    try {
        if ($fetch) [$code, $body] = $fetch($url);
        else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'GhostNode-Status']);
            $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
            if ($code === 0) return [false, (int)round((microtime(true) - $t0) * 1000), $err !== '' ? mb_substr($err, 0, 80) : 'keine Antwort'];
        }
    } catch (Throwable $e) { return [false, (int)round((microtime(true) - $t0) * 1000), mb_substr($e->getMessage(), 0, 80)]; }
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if ($code < 200 || $code >= 300) return [false, $ms, 'HTTP ' . $code];
    $j = json_decode((string)$body, true);
    if (is_array($j) && array_key_exists('ok', $j) && !$j['ok']) return [false, $ms, 'meldet Störung'];
    return [true, $ms, $ms > 3000 ? 'langsam' : 'ok'];
}
/** alle Prüfungen → [id => [icon, name, ok, ms, msg]] */
function st_check(?callable $fetch = null): array {
    $r = [];
    $t0 = microtime(true);
    try { site_db()->query('SELECT 1')->fetchColumn(); $r['db'] = ['🗄', 'Datenbank', true, (int)round((microtime(true) - $t0) * 1000), 'ok']; }
    catch (Throwable $e) { $r['db'] = ['🗄', 'Datenbank', false, 0, 'nicht erreichbar']; }
    $w = is_writable(DATA_DIR);
    $r['site'] = ['🌐', 'Website', $w, 0, $w ? 'ok' : 'Datenordner nicht beschreibbar'];
    $r['api'] = ['🥽', 'Nexus-Schnittstelle (App ↔ Website)', $r['db'][2] && is_file(APP_ROOT . '/client.php'), 0, $r['db'][2] ? 'ok' : 'Datenbank fehlt'];
    foreach (st_targets() as $id => [$ic, $name, $url]) { [$ok, $ms, $msg] = st_probe($url, $fetch); $r[$id] = [$ic, $name, $ok, $ms, $msg]; }
    return $r;
}
/** Ergebnis speichern, Störungen öffnen/schließen → Liste der Wechsel [[id, name, ok]] */
function st_record(array $res, int $now = 0): array {
    $now = $now ?: time();
    $s = st_load(); $changes = [];
    foreach ($res as $id => [$ic, $name, $ok, $ms, $msg]) {
        $h = $s['hist'][$id] ?? [];
        $h[] = [$now, $ok ? 1 : 0, $ms];
        $s['hist'][$id] = array_slice($h, -ST_KEEP);
        $was = $s['last'][$id]['ok'] ?? null;
        if ($was !== null && (bool)$was !== (bool)$ok) {
            $changes[] = [$id, $name, $ok, $msg];
            if (!$ok) array_unshift($s['incidents'], ['id' => $id, 'name' => $name, 'start' => $now, 'end' => 0, 'msg' => $msg]);
            else foreach ($s['incidents'] as &$inc) if ($inc['id'] === $id && !$inc['end']) { $inc['end'] = $now; break; }
            unset($inc);
        }
        $s['last'][$id] = ['icon' => $ic, 'name' => $name, 'ok' => $ok, 'ms' => $ms, 'msg' => $msg, 'at' => $now];
    }
    $s['incidents'] = array_slice($s['incidents'], 0, 30);
    foreach (array_keys($s['last']) as $id) if (!isset($res[$id])) { unset($s['last'][$id], $s['hist'][$id]); }   // entfernte Dienste
    $s['at'] = $now;
    st_save($s);
    return $changes;
}
/** Verfügbarkeit in % über die letzten $sec Sekunden */
function st_uptime(array $hist, int $sec, int $now = 0): ?float {
    $now = $now ?: time(); $n = 0; $ok = 0;
    foreach ($hist as [$t, $v]) if ($t >= $now - $sec) { $n++; $ok += $v; }
    return $n ? round($ok / $n * 100, 2) : null;
}
/** öffentliche Zusammenfassung (Seite + JSON) */
function st_summary(int $now = 0): array {
    $now = $now ?: time(); $s = st_load(); $svc = [];
    foreach ($s['last'] as $id => $l) {
        $h = $s['hist'][$id] ?? [];
        $svc[] = ['id' => $id, 'icon' => $l['icon'], 'name' => $l['name'], 'ok' => (bool)$l['ok'], 'ms' => (int)$l['ms'], 'msg' => $l['msg'], 'at' => (int)$l['at'],
            'up24' => st_uptime($h, 86400, $now), 'up7' => st_uptime($h, 604800, $now), 'bars' => array_map(fn($x) => (int)$x[1], array_slice($h, -96))];
    }
    $down = count(array_filter($svc, fn($x) => !$x['ok']));
    return ['ok' => $down === 0, 'down' => $down, 'checked' => (int)$s['at'], 'stale' => $s['at'] && $now - $s['at'] > 3600, 'services' => $svc, 'incidents' => array_slice($s['incidents'], 0, 15)];
}
/** 8.1: Störungsmeldung per E-Mail (PHP mail() – unter Plesk ohne weitere Einrichtung). Adresse aus Einstellung „status_email“. */
function st_mail(string $to, string $text, ?callable $send = null): bool {
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) return false;
    $host = preg_replace('/[^a-z0-9.\-]/', '', strtolower(parse_url(me_site(), PHP_URL_HOST) ?: 'ghostnode-labs.dev'));
    $subj = '=?UTF-8?B?' . base64_encode((strpos($text, '⚠') !== false ? '⚠ Störung' : '✅ Wieder da') . ' – GhostNode-Status') . '?=';
    $body = str_replace(['**'], '', $text) . "\n\n– Diese Nachricht kommt von der Status-Seite (Admin → System → Aufgaben → Status-Webhook/E-Mail).\n";
    $hdr = "From: GhostNode Status <noreply@" . $host . ">\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nAuto-Submitted: auto-generated";
    return $send ? (bool)$send($to, $subj, $body, $hdr) : @mail($to, $subj, $body, $hdr);
}
/** Aufgabe „status“ */
function st_task(?callable $fetch = null, ?callable $notify = null, ?callable $send = null): string {
    $res = st_check($fetch);
    $changes = st_record($res);
    $hook = trim(setting('status_webhook', '')) ?: (string)(getenv('STATUS_WEBHOOK') ?: '');
    $mail = trim(setting('status_email', ''));
    if ($changes && ($hook !== '' || $notify || $mail !== '')) {
        require_once APP_ROOT . '/inc/modules/me.php';
        $txt = implode("\n", array_map(fn($c) => ($c[2] ? '✅ **' . $c[1] . '** läuft wieder.' : '⚠ **' . $c[1] . '** ist gestört (' . $c[3] . ').'), $changes)) . "\nStatus: " . me_site() . '/status.php';
        if ($mail !== '') st_mail($mail, $txt, $send);
        if ($notify) $notify($txt);
        elseif ($hook !== '') { me_hook_send($hook, $txt, [], setting('status_webhook_secret', '')); }
    }
    $down = count(array_filter($res, fn($x) => !$x[2]));
    return count($res) . ' Dienste geprüft' . ($down ? ' · ⚠ ' . $down . ' gestört' : ' · alle ok') . ($changes ? ' · ' . count($changes) . ' Wechsel gemeldet' : '');
}
