<?php
declare(strict_types=1);
/**
 * D2 Nexus Chat-API (für die App). Kopf „Authorization: Bearer <Bungie-Token>“, JSON.
 *   GET  d2chat.php?a=me                       → wer bin ich (Name, Clan)
 *   GET  d2chat.php?a=chans                    → meine Kanäle mit Ungelesen
 *   GET  d2chat.php?a=msgs&chan=…&after=ID     → Nachrichten (neueste 80 bzw. alle nach ID)
 *   POST d2chat.php?a=send  {chan|to, text}    → senden (to = Bungie-ID für eine Direktnachricht)
 *   Admin (nur Bungie-IDs aus „d2_admins“): a=admin&op=stats|users|msgs (GET), op=delete|block|announce (POST)
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/auth.php';
require_once __DIR__ . '/inc/modules/nexuswarn.php';
require_once __DIR__ . '/inc/modules/d2chat.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$out = function (array $o, int $code = 200) { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
try {
    if (nw_rate_over('d2chat|' . client_ip(), 120)) throw new D2cError('Zu viele Anfragen.', 429);
    if (!preg_match('/^Bearer\s+(\S+)$/', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $m)) throw new D2cError('Nicht angemeldet.', 401);
    $who = d2c_who($m[1]);
    $a = (string)($_GET['a'] ?? '');
    if ($a === 'me') $out(['id' => $who['id'], 'name' => $who['name'], 'code' => $who['code'], 'clan' => $who['clan'], 'clanName' => $who['clanName'], 'admin' => d2c_is_admin($who)]);
    if ($a === 'admin') {
        $in = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? (json_decode((string)file_get_contents('php://input', false, null, 0, 16384), true) ?: []) : $_GET;
        $op = (string)($in['op'] ?? $_GET['op'] ?? '');
        if (in_array($op, ['delete', 'block', 'announce'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new D2cError('Nur POST.', 405);
        $r = d2c_admin($who, $op, $in);
        if (in_array($op, ['delete', 'block', 'announce'], true)) audit('D2-Chat-Admin: ' . $op, $who['name'] . ' · ' . json_encode(array_intersect_key($in, array_flip(['id', 'blocked'])), JSON_UNESCAPED_UNICODE), 'security');
        $out($r);
    }
    if ($a === 'chans') $out(['chans' => d2c_chans($who)]);
    if ($a === 'msgs') $out(['msgs' => d2c_messages($who, (string)($_GET['chan'] ?? ''), max(0, (int)($_GET['after'] ?? 0)))]);
    if ($a === 'send' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $in = json_decode((string)file_get_contents('php://input', false, null, 0, 16384), true) ?: [];
        if (nw_rate_over('d2send|' . $who['id'], 20)) throw new D2cError('Langsam – höchstens 20 Nachrichten pro Minute.', 429);
        $chan = (string)($in['chan'] ?? '');
        if ($chan === '' && preg_match('/^\d{1,20}$/', (string)($in['to'] ?? ''))) {
            if ((string)$in['to'] === $who['id']) throw new D2cError('Das bist du selbst.');
            $chan = d2c_dm($who['id'], (string)$in['to']);
        }
        $out(d2c_send($who, $chan, (string)($in['text'] ?? '')));
    }
    throw new D2cError('Unbekannte Anfrage.', 404);
} catch (D2cError $e) { $out(['error' => $e->getMessage()], $e->status); }
catch (Throwable $e) { error_log('d2chat: ' . $e->getMessage()); $out(['error' => 'Serverfehler.'], 500); }
