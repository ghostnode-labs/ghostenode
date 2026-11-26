<?php
/**
 * Nexus Community – Schnittstelle für die VRC-Nexus-App (JSON, ohne Sitzung/Cookies).
 * <title>Nexus Community</title>
 *
 * Jede Anfrage: {"token": Konto-Token (nexusacct), "key": optional NXK1 mit Recht „mod“}
 *   feed {rating:"sfw"|"nsfw", before, author} · thread {id} · post {text, rating, media[], parent} · edit {id,text} · delete {id}
 *   react {id, emoji} · report {id, reason} · upload {data(base64), rating} · media {id} → Bild (binär)
 *   profile {id} · profile_save {…} · mod_queue · mod {do, id, note, hours, vrc_id, media, report}
 *   5.3: feed {q, tag, mode:"following"|"saved"} · save {id,on} · follow {id,on} · follows · trends {rating} · inbox {seen}
 * NSFW wird bei jeder Anfrage serverseitig geprüft (Verified 18+ + NSFW an) – auch für Bilder und Antworten.
 */
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/nexuscomm.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$out = function (array $o, int $code = 200) { header('Content-Type: application/json; charset=utf-8'); http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['error' => 'Nur POST.'], 405);
$a = (string)($_GET['a'] ?? '');
if (nw_rate_over('c|' . client_ip(), $a === 'media' ? 600 : 120)) $out(['error' => 'Zu viele Anfragen – kurz warten.'], 429);
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 8 * 1048576), true);
if (!is_array($in)) $out(['error' => 'JSON erwartet.'], 400);
try {
    $who = nc_who($in);
    switch ($a) {
        case 'me': $out(['vrc_id' => $who['vrc_id'], 'mod' => $who['_mod'], 'nsfw' => $who['_nsfw'], 'reactions' => NC_REACTIONS, 'sanction' => nc_sanction($who['vrc_id'])]);
        case 'feed': $out(['items' => nc_feed($who, (string)($in['rating'] ?? 'sfw'), (int)($in['before'] ?? 0), (string)($in['author'] ?? ''), ['q' => (string)($in['q'] ?? ''), 'tag' => (string)($in['tag'] ?? ''), 'mode' => (string)($in['mode'] ?? '')])]);
        case 'save': $out(nc_save($who, (int)($in['id'] ?? 0), !empty($in['on'])));
        case 'follow': $out(nc_follow($who, (string)($in['id'] ?? ''), !empty($in['on'])));
        case 'follows': $out(nc_follows($who));
        case 'trends': $out(nc_trends($who, (string)($in['rating'] ?? 'sfw')));
        case 'inbox': $out(nc_inbox($who, !empty($in['seen'])));
        // 6.0 (18+): Anfragen, Verbindungen, Kompatibilität, Consent-Check
        case 'req_list': $out(nc_req_list($who));
        case 'req_send': $out(nc_req_send($who, (string)($in['id'] ?? ''), (string)($in['message'] ?? '')));
        case 'req_answer': $out(nc_req_answer($who, (int)($in['id'] ?? 0), !empty($in['accept'])));
        case 'req_withdraw': $out(nc_req_withdraw($who, (int)($in['id'] ?? 0)));
        case 'compat': $out(nc_compat($who, (string)($in['id'] ?? '')));
        case 'sess_start': $out(nc_sess_start($who, (string)($in['id'] ?? ''), (array)($in['terms'] ?? [])));
        case 'sess_answer': $out(nc_sess_answer($who, (int)($in['id'] ?? 0), !empty($in['agree']), (array)($in['terms'] ?? [])));
        case 'sess_end': $out(nc_sess_end($who, (int)($in['id'] ?? 0), (string)($in['reason'] ?? 'done')));
        case 'thread': $out(nc_thread($who, (int)($in['id'] ?? 0)));
        case 'post': $out(nc_create($who, $in));
        case 'edit': $out(nc_edit($who, (int)($in['id'] ?? 0), (string)($in['text'] ?? '')));
        case 'delete': $out(nc_delete($who, (int)($in['id'] ?? 0)));
        case 'react': $out(nc_react($who, (int)($in['id'] ?? 0), (string)($in['emoji'] ?? '')));
        case 'report': $out(nc_report($who, (int)($in['id'] ?? 0), (string)($in['reason'] ?? '')));
        case 'upload': $out(nc_upload($who, $in));
        case 'media': { [$mime, $bin] = nc_media_get($who, (string)($in['id'] ?? '')); header('Content-Type: ' . $mime); header('Content-Length: ' . strlen($bin)); echo $bin; exit; }
        case 'profile': $out(['profile' => nc_profile($who, trim((string)($in['id'] ?? '')) !== '' ? (string)$in['id'] : (string)$who['vrc_id'])]);
        case 'world': $out(nc_world($who, (string)($in['world'] ?? '')));
        case 'world_note': $out(nc_world_note($who, (string)($in['world'] ?? ''), (string)($in['text'] ?? '')) + ['msg' => 'Beschreibung gespeichert.']);
        case 'profile_save': $out(['profile' => nc_profile_save($who, $in), 'msg' => 'Profil gespeichert.']);
        case 'profile_items': $out(nc_profile_items($who, $in) + ['msg' => '18+-Profil aktualisiert.']);
        case 'mod_queue': $out(nc_mod_queue($who));
        case 'mod': $out(nc_mod($who, (string)($in['do'] ?? ''), $in));
    }
    $out(['error' => 'Unbekannte Aktion.'], 400);
} catch (NxError | NcError $e) {
    $out(['error' => $e->getMessage()], $e->getMessage() === 'Nicht angemeldet.' ? 401 : 400);
} catch (Throwable $e) {
    error_log('nexuscomm: ' . $e->getMessage());
    $out(['error' => 'Serverfehler.'], 500);
}
