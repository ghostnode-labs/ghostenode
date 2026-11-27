<?php
/**
 * Nexus Web – Community, Pulse und 18+-Bereich im Browser.
 * Die Webseite ist schon über VRChat angemeldet (nexus.php), daher steht die VRChat-ID fest: daraus wird das Nexus-Konto
 * (gleiches Konto wie in der App). Moderator = Recht „nexus.community“ auf der Webseite. NSFW wird bei JEDER Anfrage
 * serverseitig geprüft (Verified 18+ und NSFW-Schalter), genau wie in nexuscomm.php.
 */
require_once __DIR__ . '/nexusacct.php';
require_once __DIR__ . '/nexuscomm.php';
require_once __DIR__ . '/nexuspulse.php';

/** Nexus-Konto zur VRChat-Anmeldung dieses Webseiten-Benutzers (legt es bei Bedarf an) */
function nxweb_who(int $uid): array {
    $acc = vrc_account($uid);
    if (!$acc) throw new NcError('Bitte zuerst mit VRChat verbinden.');
    $vid = strtolower((string)($acc['vrc_id'] ?? ''));
    if (!nx_uid_ok($vid)) throw new NcError('VRChat-Konto unbekannt.');
    $name = (string)($acc['me']['displayName'] ?? $acc['display_name'] ?? '');
    if (!nx_account($vid)) {
        nx_db()->prepare('INSERT INTO nexus_accounts (vrc_id, display_name, created, last_seen, age_status, age_checked, age_until, nsfw_on, banned) VALUES (?, ?, ?, ?, \'\', 0, 0, 0, 0)')
            ->execute([$vid, mb_substr(trim($name), 0, 60), nx_now(), nx_now()]);
        nw_log($vid, 'Nexus-Konto angelegt', $vid, 'Webseite');
    }
    $a = nx_account($vid);
    if (!empty($a['banned'])) throw new NcError('Dieses Konto ist gesperrt.');
    // Altersstatus aus dem VRChat-Profil der Sitzung übernehmen (höchstens stündlich)
    if ((int)$a['age_checked'] < nx_now() - 3600) {
        try {
            $u = (array)vrc_call($acc, 'GET', '/auth/user');
            $age = (string)($u['ageVerificationStatus'] ?? '');
            if (strtolower((string)($u['id'] ?? '')) === $vid) { nx_report_age($a, !empty($u['ageVerified']) || nx_age_status_ok($age) ? '18+' : $age); $a = nx_account($vid); }
        } catch (Throwable $e) { /* VRChat gerade nicht erreichbar → alten Stand behalten */ }
    }
    nx_db()->prepare('UPDATE nexus_accounts SET last_seen = ? WHERE vrc_id = ?')->execute([nx_now(), $vid]);
    $a['_mod'] = function_exists('can') && can('nexus.community');
    $a['_nsfw'] = nx_nsfw_allowed($a);
    return $a;
}

/** Alle nw_*-Aktionen aus nexus.php */
function nxweb_action(int $uid, string $act, array $in): array {
    $who = nxweb_who($uid);
    $J = function (string $k) use ($in) { $v = json_decode((string)($in[$k] ?? ''), true); return is_array($v) ? $v : []; };
    // 7.0 Clubs & Events: gleiche Aktionen wie nexusclubs.php (App), Daten als JSON im Feld „data“
    if (strpos($act, 'nw_club_') === 0) { require_once __DIR__ . '/clubs.php'; return cl_action($who, substr($act, 8), $J('data')); }
    switch ($act) {
        case 'nw_me': {
            $a = nx_public($who);
            return ['account' => $a, 'mod' => $who['_mod'], 'nsfw' => $who['_nsfw'], 'reactions' => NC_REACTIONS, 'sanction' => nc_sanction($who['vrc_id']),
                    'pulse' => np_cfg()['id'] !== ''];
        }
        case 'nw_nsfw': return ['account' => nx_set_nsfw($who, !empty($in['on']) && $in['on'] !== '0'), 'msg' => !empty($in['on']) && $in['on'] !== '0' ? '🔞 NSFW eingeschaltet.' : 'NSFW ausgeschaltet.'];
        // ---------- Community ----------
        case 'nw_feed': return ['items' => nc_feed($who, (string)($in['rating'] ?? 'sfw'), (int)($in['before'] ?? 0), (string)($in['author'] ?? ''), ['q' => (string)($in['q'] ?? ''), 'tag' => (string)($in['tag'] ?? ''), 'mode' => (string)($in['mode'] ?? '')])];
        case 'nw_save': return nc_save($who, (int)($in['id'] ?? 0), ($in['on'] ?? '') === '1');
        case 'nw_follow': return nc_follow($who, (string)($in['id'] ?? ''), ($in['on'] ?? '') === '1');
        case 'nw_follows': return nc_follows($who);
        case 'nw_trends': return nc_trends($who, (string)($in['rating'] ?? 'sfw'));
        case 'nw_inbox': return nc_inbox($who, ($in['seen'] ?? '') === '1');
        case 'nw_req_list': return nc_req_list($who);
        case 'nw_req_send': return nc_req_send($who, (string)($in['id'] ?? ''), (string)($in['message'] ?? ''));
        case 'nw_req_answer': return nc_req_answer($who, (int)($in['id'] ?? 0), ($in['accept'] ?? '') === '1');
        case 'nw_req_withdraw': return nc_req_withdraw($who, (int)($in['id'] ?? 0));
        case 'nw_compat': return nc_compat($who, (string)($in['id'] ?? ''));
        case 'nw_sess_start': return nc_sess_start($who, (string)($in['id'] ?? ''), $J('terms'));
        case 'nw_sess_answer': return nc_sess_answer($who, (int)($in['id'] ?? 0), ($in['agree'] ?? '') === '1', $J('terms'));
        case 'nw_sess_end': return nc_sess_end($who, (int)($in['id'] ?? 0), (string)($in['reason'] ?? 'done'));
        case 'nw_thread': return nc_thread($who, (int)($in['id'] ?? 0));
        case 'nw_post': return nc_create($who, ['text' => (string)($in['text'] ?? ''), 'rating' => (string)($in['rating'] ?? 'sfw'), 'parent' => (int)($in['parent'] ?? 0), 'media' => $J('media')]);
        case 'nw_edit': return nc_edit($who, (int)($in['id'] ?? 0), (string)($in['text'] ?? ''));
        case 'nw_delete': return nc_delete($who, (int)($in['id'] ?? 0));
        case 'nw_react': return nc_react($who, (int)($in['id'] ?? 0), (string)($in['emoji'] ?? ''));
        case 'nw_report': return nc_report($who, (int)($in['id'] ?? 0), (string)($in['reason'] ?? ''));
        case 'nw_upload': return nc_upload($who, ['data' => (string)($in['data'] ?? ''), 'rating' => (string)($in['rating'] ?? 'sfw')]);
        case 'nw_profile': return ['profile' => nc_profile($who, trim((string)($in['id'] ?? '')) !== '' ? (string)$in['id'] : (string)$who['vrc_id'])];
        case 'nw_profile_save': return ['profile' => nc_profile_save($who, $J('profile')), 'msg' => 'Profil gespeichert.'];
        case 'nw_mod_queue': return nc_mod_queue($who);
        case 'nw_mod': return nc_mod($who, (string)($in['do'] ?? ''), $J('data'));
        // ---------- Pulse ----------
        case 'nw_pulse': {
            $src = (string)($in['src'] ?? 'vrchat');
            if ($src === 'vrchat') return ['items' => np_steam()];
            if ($src === 'erp') { nc_need_nsfw($who); return ['items' => np_feed('vrchaterp', (string)($in['sort'] ?? 'new'))]; }
            return ['items' => np_feed('vrchat', (string)($in['sort'] ?? 'new'))];
        }
        case 'nw_pulse_comments': {
            if (!preg_match('#^https://(?:www\.|old\.)?reddit\.com/r/(VRChat|VRchatERP)/comments/([a-z0-9]{2,12})/#i', (string)($in['url'] ?? ''), $m)) throw new NcError('Kein Reddit-Beitrag.');
            $erp = stripos($m[1], 'erp') !== false;
            if ($erp) nc_need_nsfw($who);
            return ['items' => np_comments($erp ? 'vrchaterp' : 'vrchat', $m[2])];
        }
    }
    throw new NcError('Unbekannte Aktion.');
}
