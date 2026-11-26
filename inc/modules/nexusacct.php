<?php
/**
 * Nexus-Konto & Altersfreigabe („Verified 18+“) – zentrales Konto der VRC-Nexus-App auf GhostNode.
 *
 *  - Konto = VRChat-User-ID. Nachweis über einen Bio-Code: die Webseite liest die VRChat-Bio über ihr Dienst-Konto.
 *    Kein Passwort; je Gerät ein Konto-Token (nur als SHA-256 gespeichert, widerrufbar).
 *  - Altersfreigabe (seit 3.0.1, Entscheidung der Besitzerin „nur App prüft direkt“): die App fragt mit der EIGENEN
 *    VRChat-Sitzung GET /ageVerification/status ab und meldet das Ergebnis (a=age). Der Server vertraut dieser Meldung
 *    bewusst. Gültig 7 Tage; die App prüft beim Start, alle 6 h und 1 Tag vor Ablauf neu. Kein „18+“ → Rolle entzogen, NSFW aus.
 *  - Verbinden (seit 3.0.2): direkt mit der VRChat-ID aus der App (a=register), ohne Code und ohne Prüfung (Entscheidung der Besitzerin).
 *    Der alte Bio-Code-Weg (a=code/verify) bleibt nur für ältere App-Versionen.
 *  - NSFW ist standardmäßig aus und nur mit gültiger Freigabe einschaltbar. NXK1 schaltet NSFW NICHT frei
 *    (NXK1 bleibt für Supporter, Moderatoren und Admin).
 * Tabellen im Modul „vrchat“ (Migration 10 in inc/modules/vrchat.php).
 */
require_once __DIR__ . '/vrchat.php';
require_once __DIR__ . '/nexuswarn.php';

const NX_AGE_VALID = 7 * 86400;      // Entscheidung der Besitzerin: alle 7 Tage neu prüfen
const NX_CODE_TTL = 600;             // Bio-Code 10 Minuten gültig
const NX_RECHECK_MIN = 3600;         // manuelle Neuprüfung höchstens 1×/Stunde

class NxError extends RuntimeException {}

function nx_db(): PDO { return db_get('vrchat'); }
function nx_now(): int { return time(); }
function nx_uid_ok(string $id): bool { return (bool)preg_match(NW_UID, $id); }

// ---------- Dienst-Konto (VRChat-Konto eines Dashboard-Benutzers) ----------
function nx_service_uid(): int { return (int)setting('nexus_service_uid', '0'); }
function nx_service(): array {
    $uid = nx_service_uid();
    $acc = $uid ? vrc_account($uid) : null;
    if (!$acc || !$acc['cookie_jar']) {
        // ohne Auswahl: erstes verbundenes VRChat-Konto eines Administrators (Systemrolle), sonst irgendeines
        $ids = [];
        try { $ids = site_db()->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id ORDER BY r.is_system DESC, u.id')->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $ids = []; }
        $ids = array_values(array_unique(array_merge($ids, site_db()->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
            vrc_db()->query('SELECT user_id FROM accounts ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN))));
        foreach ($ids as $id) { $x = vrc_account((int)$id); if ($x && $x['cookie_jar']) { $acc = $x; break; } }
    }
    if (!$acc || !$acc['cookie_jar']) throw new NxError('Die Webseite hat noch kein VRChat-Konto zum Prüfen des Bio-Codes – im Dashboard unter VRC Nexus ein VRChat-Konto verbinden.');
    return $acc;
}
/** VRChat-Profil über das Dienst-Konto (nur die nötigen Felder) */
function nx_vrc_user(string $vrc_id): array {
    $acc = nx_service();
    try { $u = vrc_call($acc, 'GET', '/users/' . rawurlencode($vrc_id)); }
    catch (VrcAuthError $e) { throw new NxError('Die VRChat-Sitzung des Dienst-Kontos ist abgelaufen – bitte im Dashboard neu verbinden.'); }
    catch (Throwable $e) { throw new NxError('VRChat-Profil nicht abrufbar: ' . $e->getMessage()); }
    return ['id' => (string)($u['id'] ?? ''), 'name' => (string)($u['displayName'] ?? ''), 'bio' => (string)($u['bio'] ?? ''),
            'age' => (string)($u['ageVerificationStatus'] ?? ''), 'age_verified' => !empty($u['ageVerified'])];
}

// ---------- Konten ----------
function nx_account(string $vrc_id): ?array {
    $st = nx_db()->prepare('SELECT * FROM nexus_accounts WHERE vrc_id = ?');
    $st->execute([$vrc_id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function nx_code(string $vrc_id): array {
    if (!nx_uid_ok($vrc_id)) throw new NxError('Ungültige VRChat-User-ID.');
    $code = 'NX-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    $db = nx_db();
    $db->prepare('DELETE FROM nexus_codes WHERE vrc_id = ? OR expires < ?')->execute([$vrc_id, nx_now()]);
    $db->prepare('INSERT INTO nexus_codes (vrc_id, code, expires) VALUES (?, ?, ?)')->execute([$vrc_id, $code, nx_now() + NX_CODE_TTL]);
    return ['code' => $code, 'expires' => nx_now() + NX_CODE_TTL];
}
/** Bio prüfen → Konto anlegen/holen → Geräte-Token */
function nx_verify(string $vrc_id, string $device): array {
    if (!nx_uid_ok($vrc_id)) throw new NxError('Ungültige VRChat-User-ID.');
    $st = nx_db()->prepare('SELECT code FROM nexus_codes WHERE vrc_id = ? AND expires >= ?');
    $st->execute([$vrc_id, nx_now()]);
    $code = (string)$st->fetchColumn();
    if ($code === '') throw new NxError('Kein gültiger Code – bitte neu anfordern.');
    $u = nx_vrc_user($vrc_id);
    if (strtolower($u['id']) !== $vrc_id) throw new NxError('VRChat-Profil passt nicht.');
    if (stripos($u['bio'], $code) === false) throw new NxError('Der Code steht (noch) nicht in deiner VRChat-Bio. VRChat braucht manchmal ein paar Sekunden – bitte gleich noch einmal.');
    $db = nx_db();
    $db->prepare('DELETE FROM nexus_codes WHERE vrc_id = ?')->execute([$vrc_id]);
    if (!nx_account($vrc_id)) {
        $db->prepare('INSERT INTO nexus_accounts (vrc_id, display_name, created, last_seen, age_status, age_checked, age_until, nsfw_on, banned) VALUES (?, ?, ?, ?, \'\', 0, 0, 0, 0)')
            ->execute([$vrc_id, mb_substr($u['name'], 0, 60), nx_now(), nx_now()]);
        nw_log($vrc_id, 'Nexus-Konto angelegt', $vrc_id);
    }
    $a = nx_account($vrc_id);
    if (!empty($a['banned'])) throw new NxError('Dieses Konto ist gesperrt.');
    nx_db()->prepare('UPDATE nexus_accounts SET display_name = ? WHERE vrc_id = ?')->execute([mb_substr($u['name'], 0, 60), $vrc_id]);
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO nexus_devices (token_hash, vrc_id, name, created, last_seen) VALUES (?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $vrc_id, mb_substr(trim($device) ?: 'Gerät', 0, 60), nx_now(), nx_now()]);
    return ['token' => $token, 'account' => nx_public(nx_account($vrc_id))];
}
/**
 * Direkt verbinden (seit 3.0.2, Entscheidung der Besitzerin: „direkt über Nexus, VRChat-ID ohne Code, ganz ohne Prüfung“):
 * die App meldet die VRChat-ID des angemeldeten Kontos, das Gerät wird sofort angenommen. Bewusst ohne Nachweis –
 * eine veränderte App könnte eine fremde ID melden (akzeptiertes Risiko). Gesperrte Konten bleiben gesperrt.
 */
function nx_register(string $vrc_id, string $name, string $device): array {
    if (!nx_uid_ok($vrc_id)) throw new NxError('Ungültige VRChat-User-ID.');
    $db = nx_db();
    if (!nx_account($vrc_id)) {
        $db->prepare('INSERT INTO nexus_accounts (vrc_id, display_name, created, last_seen, age_status, age_checked, age_until, nsfw_on, banned) VALUES (?, ?, ?, ?, \'\', 0, 0, 0, 0)')
            ->execute([$vrc_id, mb_substr(trim($name), 0, 60), nx_now(), nx_now()]);
        nw_log($vrc_id, 'Nexus-Konto angelegt', $vrc_id, 'direkt');
    } elseif (trim($name) !== '') $db->prepare('UPDATE nexus_accounts SET display_name = ? WHERE vrc_id = ?')->execute([mb_substr(trim($name), 0, 60), $vrc_id]);
    $a = nx_account($vrc_id);
    if (!empty($a['banned'])) throw new NxError('Dieses Konto ist gesperrt.');
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO nexus_devices (token_hash, vrc_id, name, created, last_seen) VALUES (?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $vrc_id, mb_substr(trim($device) ?: 'Gerät', 0, 60), nx_now(), nx_now()]);
    return ['token' => $token, 'account' => nx_public($a)];
}
/** Konto-Token → Konto (sonst Fehler) */
function nx_auth(string $token): array {
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) throw new NxError('Nicht angemeldet.');
    $h = hash('sha256', $token);
    $st = nx_db()->prepare('SELECT vrc_id FROM nexus_devices WHERE token_hash = ?');
    $st->execute([$h]);
    $id = (string)$st->fetchColumn();
    if ($id === '') throw new NxError('Nicht angemeldet.');
    $a = nx_account($id);
    if (!$a) throw new NxError('Nicht angemeldet.');
    if (!empty($a['banned'])) throw new NxError('Dieses Konto ist gesperrt.');
    nx_db()->prepare('UPDATE nexus_devices SET last_seen = ? WHERE token_hash = ?')->execute([nx_now(), $h]);
    nx_db()->prepare('UPDATE nexus_accounts SET last_seen = ? WHERE vrc_id = ?')->execute([nx_now(), $id]);
    $a['_token_hash'] = $h;
    return $a;
}

// ---------- Altersfreigabe ----------
function nx_manual_until(?array $a): int { return $a ? max(0, (int)($a['age_manual_until'] ?? 0)) : 0; }
function nx_apply_age(string $vrc_id, array $u): bool {
    $ok = $u['age'] === '18+';
    $db = nx_db();
    $prev = nx_account($vrc_id);
    $man = nx_manual_until($prev) > nx_now() ? nx_manual_until($prev) : 0;
    if ($ok) $db->prepare('UPDATE nexus_accounts SET age_status = ?, age_checked = ?, age_until = ?, display_name = ? WHERE vrc_id = ?')->execute(['18+', nx_now(), max(nx_now() + NX_AGE_VALID, $man), mb_substr($u['name'], 0, 60), $vrc_id]);
    elseif ($man) {
        // 5.1: manuelle Freigabe durch einen Admin bleibt, auch wenn VRChat (noch) nichts meldet
        $db->prepare("UPDATE nexus_accounts SET age_status = 'manuell', age_checked = ?, age_until = ? WHERE vrc_id = ?")->execute([nx_now(), $man, $vrc_id]);
        return true;
    } else {
        // Status fehlt/unsichtbar → Rolle entziehen UND NSFW ausschalten (muss nach erneuter Freigabe bewusst wieder an)
        $db->prepare('UPDATE nexus_accounts SET age_status = ?, age_checked = ?, age_until = 0, nsfw_on = 0 WHERE vrc_id = ?')->execute([$u['age'] === '' ? 'hidden' : mb_substr($u['age'], 0, 20), nx_now(), $vrc_id]);
        if ($prev && (int)$prev['age_until'] > 0) nw_log($vrc_id, 'Verified 18+ entzogen', $vrc_id, 'Status: ' . ($u['age'] ?: 'nicht sichtbar'));
    }
    return $ok;
}
/** VRChat-Altersstatus aus der App (GET /ageVerification/status mit der eigenen Sitzung) → Freigabe */
/** VRChat meldet bestätigte Konten als „18+“ (Abzeichen sichtbar) oder „verified“ (bestätigt, Abzeichen aus) – die Altersprüfung von VRChat gibt es nur ab 18 */
function nx_age_status_ok(string $s): bool { $s = strtolower(trim($s)); return in_array($s, ['18+', 'plus18', '18plus', 'verified', 'true'], true) || (bool)preg_match('/^verified[_ -]?18\+?$/', $s); }
function nx_report_age(array &$a, string $status): array {
    $u = ['age' => nx_age_status_ok($status) ? '18+' : mb_substr(trim($status), 0, 20), 'name' => $a['display_name']];
    nx_apply_age($a['vrc_id'], $u);
    $a = nx_account($a['vrc_id']);
    return nx_public($a);
}
/** gültige Freigabe? (abgelaufen → keine; die App meldet neu) */
function nx_verified18(array &$a): bool { return (int)$a['age_until'] > nx_now(); }
/** Darf dieses Konto NSFW-Inhalte bekommen? – von JEDEM NSFW-Endpunkt aufzurufen */
function nx_nsfw_allowed(array &$a): bool {
    if (empty($a['nsfw_on'])) return false;
    if (!nx_verified18($a)) return false;
    return !empty($a['nsfw_on']);
}
function nx_require_nsfw(array &$a): void {
    if (!nx_nsfw_allowed($a)) throw new NxError('NSFW ist für dieses Konto nicht freigegeben (Verified 18+ und NSFW-Schalter nötig).');
}
function nx_set_nsfw(array &$a, bool $on): array {
    if ($on) {
        if (!nx_verified18($a)) throw new NxError('NSFW lässt sich nur mit „Verified 18+“ einschalten: In VRChat muss die Altersverifizierung (18+) abgeschlossen und im Profil sichtbar sein.');
        nx_db()->prepare('UPDATE nexus_accounts SET nsfw_on = 1 WHERE vrc_id = ?')->execute([$a['vrc_id']]);
    } else nx_db()->prepare('UPDATE nexus_accounts SET nsfw_on = 0 WHERE vrc_id = ?')->execute([$a['vrc_id']]);
    nw_log($a['vrc_id'], $on ? 'NSFW eingeschaltet' : 'NSFW ausgeschaltet', $a['vrc_id']);
    $a = nx_account($a['vrc_id']);
    return nx_public($a);
}
function nx_recheck(array &$a): array { return nx_public($a); }   // seit 3.0.1 prüft die App selbst (a=age)
/** Kontoinfo für die App (ohne Interna) */
function nx_public(array $a): array {
    $v = (int)$a['age_until'] > nx_now();
    return ['vrc_id' => $a['vrc_id'], 'name' => $a['display_name'], 'created' => (int)$a['created'],
        'verified18' => $v, 'age_source' => $v && nx_manual_until($a) > nx_now() && nx_manual_until($a) >= (int)$a['age_until'] ? 'manual' : 'vrchat', 'age_status' => $a['age_status'], 'age_checked' => (int)$a['age_checked'], 'age_until' => (int)$a['age_until'],
        'nsfw_on' => $v && !empty($a['nsfw_on']), 'roles' => array_values(array_filter([$v ? 'Verified 18+' : '', nw_is_owner($a['vrc_id']) ? 'Admin' : '']))];
}
function nx_devices(array $a): array {
    $st = nx_db()->prepare('SELECT substr(token_hash, 1, 12) AS id, name, created, last_seen FROM nexus_devices WHERE vrc_id = ? ORDER BY last_seen DESC');
    $st->execute([$a['vrc_id']]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function nx_logout(array $a, string $device = ''): void {
    if ($device !== '') nx_db()->prepare('DELETE FROM nexus_devices WHERE vrc_id = ? AND substr(token_hash, 1, 12) = ?')->execute([$a['vrc_id'], $device]);
    else nx_db()->prepare('DELETE FROM nexus_devices WHERE token_hash = ?')->execute([$a['_token_hash']]);
}
/** Hintergrund (cron): abgelaufene Freigaben aufräumen (NSFW aus) – neu geprüft wird in der App */
function nx_cron(int $max = 10): int {
    $st = nx_db()->prepare('UPDATE nexus_accounts SET nsfw_on = 0 WHERE age_until > 0 AND age_until < ? AND nsfw_on = 1');
    $st->execute([nx_now()]);
    return $st->rowCount();
}
