<?php
/**
 * 8.0.5: 🎨 Themes-Marktplatz für VRC Nexus.
 * Ein Theme ist reine Farbdaten (nur #rrggbb-Werte, kein CSS, kein Code) – gleiche Prüfung wie in der App (backend/themes.js).
 * Katalog: DATA_DIR/market/themes.json (mit Sperre), signiert mit dem Schlüssel des Plugin-Marktplatzes (mk_keypair).
 */
require_once __DIR__ . '/market.php';

const TM_KEYS = ['bg', 'panel', 'panel2', 'accent', 'gold', 'green', 'purple', 'red', 'text', 'muted', 'strong', 'surface', 'line'];
const TM_REQUIRED = ['bg', 'panel', 'accent', 'text', 'muted'];
const TM_MAX = 500;

function tm_file(): string { return mk_dir() . '/themes.json'; }
function tm_load(): array { $f = tm_file(); $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : []; return is_array($j) ? $j : []; }
function tm_update(callable $fn) {
    $fp = fopen(tm_file(), 'c+');
    if (!$fp) throw new RuntimeException('Theme-Katalog nicht beschreibbar.');
    flock($fp, LOCK_EX);
    $all = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($all)) $all = [];
    try { $ret = $fn($all); } catch (Throwable $e) { flock($fp, LOCK_UN); fclose($fp); throw $e; }
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return $ret;
}

/** Theme prüfen und säubern → ['id','name','author','scheme','colors'] */
function tm_validate($t): array {
    if (is_string($t)) { $t = json_decode($t, true); }
    if (!is_array($t)) throw new InvalidArgumentException('Kein gültiges Theme (JSON erwartet).');
    $id = strtolower(trim((string)($t['id'] ?? '')));
    if (!preg_match('/^[a-z0-9][a-z0-9-]{2,39}$/', $id)) throw new InvalidArgumentException('Theme-ID: 3–40 Zeichen a–z, 0–9 und Bindestrich.');
    $name = trim(preg_replace('/[\x00-\x1f<>]/u', '', (string)($t['name'] ?? '')));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 40) throw new InvalidArgumentException('Name: 2–40 Zeichen.');
    $scheme = ($t['scheme'] ?? 'dark') === 'light' ? 'light' : 'dark';
    $c = is_array($t['colors'] ?? null) ? $t['colors'] : [];
    $out = [];
    foreach (TM_KEYS as $k) {
        if (!isset($c[$k]) || $c[$k] === '') continue;
        $v = strtolower(trim((string)$c[$k]));
        if (!preg_match('/^#[0-9a-f]{6}$/', $v)) throw new InvalidArgumentException('Farbe „' . $k . '“ muss #rrggbb sein.');
        $out[$k] = $v;
    }
    foreach (TM_REQUIRED as $k) if (!isset($out[$k])) throw new InvalidArgumentException('Farbe „' . $k . '“ fehlt.');
    foreach (array_keys($c) as $k) if (!in_array($k, TM_KEYS, true)) throw new InvalidArgumentException('Unbekannter Farbwert „' . mb_substr((string)$k, 0, 20) . '“.');
    // Lesbarkeit: Text muss sich vom Hintergrund abheben (Kontrast ≥ 4,5 wie WCAG AA)
    if (tm_contrast($out['text'], $out['bg']) < 4.5) throw new InvalidArgumentException('Text und Hintergrund haben zu wenig Kontrast (mind. 4,5 : 1).');
    $author = trim(preg_replace('/[\x00-\x1f<>]/u', '', (string)($t['author'] ?? '')));
    return ['id' => $id, 'name' => $name, 'author' => mb_substr($author, 0, 40), 'scheme' => $scheme, 'colors' => $out];
}
function tm_lum(string $hex): float {
    $c = array_map(fn($i) => hexdec(substr($hex, $i, 2)) / 255, [1, 3, 5]);
    $c = array_map(fn($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
function tm_contrast(string $a, string $b): float { $x = tm_lum($a); $y = tm_lum($b); return (max($x, $y) + 0.05) / (min($x, $y) + 0.05); }

/** Einreichen (angemeldet). Prüfer/Admins: sofort frei, sonst wartet es auf Prüfung. Eigene Themes dürfen überschrieben werden. */
function tm_submit(array $user, $json): array {
    $t = tm_validate($json);
    $auto = can('market.manage');
    $status = tm_update(function (array &$all) use ($t, $user, $auto) {
        if (isset($all[$t['id']]) && (int)$all[$t['id']]['owner'] !== (int)$user['id'] && !can('market.manage')) throw new InvalidArgumentException('Diese Theme-ID gehört schon jemand anderem.');
        if (!isset($all[$t['id']]) && count($all) >= TM_MAX) throw new InvalidArgumentException('Der Katalog ist voll.');
        $old = $all[$t['id']] ?? [];
        $all[$t['id']] = $t + ['owner' => (int)($old['owner'] ?? $user['id']), 'status' => $auto ? 'approved' : 'pending', 'note' => '', 'uploaded' => time(), 'installs' => (int)($old['installs'] ?? 0)];
        return $all[$t['id']]['status'];
    });
    audit('Theme eingereicht', $t['id'], 'info');
    return ['theme' => $t, 'status' => $status];
}
function tm_review(string $id, string $decision, string $note): void {
    if (!can('market.manage')) throw new InvalidArgumentException('Keine Berechtigung.');
    if (!in_array($decision, ['approved', 'rejected', 'pending'], true)) throw new InvalidArgumentException('Ungültige Entscheidung.');
    tm_update(function (array &$all) use ($id, $decision, $note) {
        if (!isset($all[$id])) throw new InvalidArgumentException('Theme nicht gefunden.');
        $all[$id]['status'] = $decision; $all[$id]['note'] = mb_substr(trim($note), 0, 300); $all[$id]['reviewed'] = time();
    });
    audit('Theme geprüft', $id . ' → ' . $decision, 'info');
}
function tm_delete(array $user, string $id): void {
    tm_update(function (array &$all) use ($id, $user) {
        if (!isset($all[$id])) throw new InvalidArgumentException('Theme nicht gefunden.');
        if (!can('market.manage') && (int)$all[$id]['owner'] !== (int)$user['id']) throw new InvalidArgumentException('Keine Berechtigung.');
        unset($all[$id]);
    });
    audit('Theme gelöscht', $id, 'info');
}
/** Installation zählen (die App meldet sich nach dem Übernehmen – ohne Konto, ohne Daten) */
function tm_count(string $id): void {
    tm_update(function (array &$all) use ($id) { if (isset($all[$id]) && $all[$id]['status'] === 'approved') $all[$id]['installs'] = (int)($all[$id]['installs'] ?? 0) + 1; });
}
/** Öffentlicher, signierter Katalog (nur freigegebene Themes, Farben direkt drin) */
function tm_index(): array {
    $out = [];
    foreach (tm_load() as $t) {
        if (($t['status'] ?? '') !== 'approved') continue;
        $out[] = ['id' => $t['id'], 'name' => $t['name'], 'author' => $t['author'], 'scheme' => $t['scheme'], 'colors' => $t['colors'], 'updated' => (int)$t['uploaded'], 'installs' => (int)($t['installs'] ?? 0)];
    }
    usort($out, fn($a, $b) => $b['installs'] <=> $a['installs'] ?: strcasecmp($a['name'], $b['name']));
    $index = json_encode(['format' => 1, 'kind' => 'themes', 'generated' => time(), 'themes' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $kp = mk_keypair();
    return ['index' => $index, 'sig' => base64_encode(sodium_crypto_sign_detached($index, sodium_crypto_sign_secretkey($kp))), 'key' => base64_encode(sodium_crypto_sign_publickey($kp))];
}
