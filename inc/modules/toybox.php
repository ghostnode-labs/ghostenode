<?php
/**
 * Gemeinsamer Briefkasten-Speicher der Spielzeug-Fernsteuerung (toyrelay.php und – seit 8.2 – Web-Nexus im Browser).
 * Ein Briefkasten = Datei data/toyrelay/<sha256("vrcnx-toy|" + VRChat-ID)>.php; Inhalt JSON hinter einer PHP-Sperrzeile,
 * damit die Datei auch ohne .htaccess (z. B. nginx) beim direkten Aufruf nichts verrät. Nachrichten verfallen nach 90 s.
 */

const TB_TTL = 90;
const TB_MAX_MSGS = 50;
const TB_MAX_LEN = 6000;
const TB_GUARD = "<?php http_response_code(404); exit; ?>\n";

function tb_dir(): string {
    $d = dirname(__DIR__, 2) . '/data/toyrelay';   // fest wie toyrelay.php seit jeher (unabhängig von DATA_DIR)
    if (!is_dir($d)) { @mkdir($d, 0700, true); @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); }
    return $d;
}
/** Briefkasten-Kennung zu einer VRChat-ID (gleiche Formel wie in der App: uidHash) */
function tb_hash(string $vrc_id): string { return hash('sha256', 'vrcnx-toy|' . strtolower(trim($vrc_id))); }
function tb_file(string $hash): string { return tb_dir() . '/' . $hash . '.php'; }
/** Gibt es diesen Briefkasten? (= Empfänger hat „Fernsteuerung über das Internet“ an und schon einmal abgeholt) */
function tb_exists(string $hash): bool { return (bool)preg_match('/^[0-9a-f]{64}$/', $hash) && is_file(tb_file($hash)); }

/** Datei gesperrt öffnen, $fn darf den Inhalt ändern; null, wenn der Speicher nicht beschreibbar ist */
function tb_with(string $file, callable $fn, bool &$ok = null) {
    $h = @fopen($file, 'c+');
    if (!$h) { $ok = false; return null; }
    $ok = true;
    flock($h, LOCK_EX);
    $raw = (string)stream_get_contents($h);
    if (strpos($raw, TB_GUARD) === 0) $raw = substr($raw, strlen(TB_GUARD));
    $d = $raw ? (json_decode($raw, true) ?: []) : [];
    $res = $fn($d);
    ftruncate($h, 0); rewind($h); fwrite($h, TB_GUARD . json_encode($d)); fflush($h);
    flock($h, LOCK_UN); fclose($h);
    return $res;
}

/** Nachricht in einen vorhandenen Briefkasten legen → true, wenn abgelegt */
function tb_push(string $hash, string $msg): bool {
    if (!tb_exists($hash) || $msg === '' || strlen($msg) > TB_MAX_LEN) return false;
    $ok = false;
    tb_with(tb_file($hash), function (array &$d) use ($msg) {
        $d['msgs'] = array_values(array_filter($d['msgs'] ?? [], fn($x) => ($x['t'] ?? 0) > time() - TB_TTL));
        $d['msgs'][] = ['t' => time(), 'm' => $msg];
        $d['msgs'] = array_slice($d['msgs'], -TB_MAX_MSGS);
    }, $ok);
    return $ok;
}
