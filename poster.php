<?php
/**
 * 🖼 VRChat-Poster – Dashboard für feste Bild-Adressen (Logik: inc/modules/posters.php, Auslieferung: p.php).
 * Gäste sehen nur öffentliche Slots/Bilder; alles andere hängt an den Rollenrechten posters.* und den Freigaben je Slot/Bild.
 */
require_once __DIR__ . '/inc/modules/posters.php';
require_once __DIR__ . '/inc/core/format.php';

auth_require_setup();

// ---------- Vorschaubilder ----------
if (isset($_GET['thumb'])) ps_thumb((int)$_GET['thumb']);

// ---------- JSON-Schnittstelle ----------
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $act = (string)$_GET['api'];
    $in = $_POST;
    $out = function ($d) { echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; };
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!is_logged_in()) throw new InvalidArgumentException('Bitte anmelden.');
            if (!same_origin_request()) { audit('Anfrage abgelehnt', 'Poster: fremde Herkunft', 'security'); throw new InvalidArgumentException('Anfrage abgelehnt (fremde Herkunft).'); }
        } elseif (!in_array($act, ['list', 'slot'], true)) throw new InvalidArgumentException('Nur per POST.');
        $id = (int)($in['id'] ?? $_GET['id'] ?? 0);
        $u = current_user();
        switch ($act) {
            case 'list':
                $c = ps_cfg();
                $res = ['slots' => ps_slots_visible(), 'me' => $u ? ['id' => (int)$u['id'], 'name' => user_label($u), 'perms' => array_values(array_filter($u['permissions'], fn($p) => strpos($p, 'posters.') === 0)),
                        'used' => ps_used_bytes((int)$u['id'])] : null,
                        'cfg' => ['max_mb' => $c['max_mb'], 'max_dim' => $c['max_dim'], 'quota_mb' => $c['quota_mb'], 'ftp' => $c['ftp'], 'links' => $c['links'], 'live_min' => $c['live_min']],
                        'levels' => PS_LEVEL_LABEL, 'vis' => PS_VIS, 'modes' => PS_MODES, 'fits' => PS_FITS];
                if ($u && array_intersect(['posters.edit', 'posters.manage'], $u['permissions'])) $res['users'] = array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['display_name'] !== '' ? $x['display_name'] . ' (@' . $x['username'] . ')' : $x['username']], ps_users_list());
                $out($res);
            case 'slot':
                $s = ps_need_slot($id, 'view');
                $lvl = ps_slot_level($s);
                $r = ['slot' => ps_slot_public($s, $u), 'images' => ps_images_visible($s)];
                if (PS_LEVELS[$lvl] >= PS_LEVELS['edit']) { $r['hits'] = ps_hits($id); $r['log'] = ps_log_list($id); }
                if ($lvl === 'manage') $r['acl'] = ps_acl($id);
                $out($r);
            case 'create': $s = ps_slot_create($in); $out(['slot' => ps_slot_public($s, $u)]);
            case 'save': $s = ps_slot_save($id, $in); $out(['slot' => ps_slot_public($s, $u), 'msg' => 'Gespeichert.']);
            case 'delete': ps_slot_delete($id); $out(['msg' => 'Slot gelöscht.']);
            case 'token': $t = ps_token_new($id); $s = ps_slot($id); $out(['token' => $t, 'endpoint' => ps_base_url() . '/p.php/' . $s['slug'], 'url' => ps_public_url($s)]);
            case 'upload':
                $s = ps_need_slot($id, 'upload');
                if (empty($_FILES['files'])) throw new InvalidArgumentException('Keine Datei – evtl. größer als upload_max_filesize (' . ini_get('upload_max_filesize') . ').');
                $r = ps_upload_files($s, $_FILES['files'], ['activate' => ($in['activate'] ?? '') === '1' && ps_can($s, 'edit')]);
                $out(['added' => count(array_filter($r['images'], fn($i) => empty($i['duplicate']))), 'dupes' => count(array_filter($r['images'], fn($i) => !empty($i['duplicate']))), 'errors' => $r['errors']]);
            case 'url':
                $s = ps_need_slot($id, 'upload');
                $img = ps_add_url($s, (string)($in['url'] ?? ''), ($in['live'] ?? '') === '1', ($in['activate'] ?? '') === '1' && ps_can($s, 'edit'));
                $out(['msg' => !empty($img['duplicate']) ? 'Dieses Bild ist schon im Slot.' : 'Bild übernommen.']);
            case 'ftp':
                ps_need_slot($id, 'upload');
                $r = ps_ftp_import($id);
                $out(['msg' => $r['n'] . ' Bild(er) aus dem FTP-Ordner übernommen.', 'errors' => $r['errors']]);
            case 'activate': ps_activate($id, (int)($in['img'] ?? 0)); $out(['msg' => 'Ist jetzt das aktive Bild.']);
            case 'reorder': ps_reorder($id, json_decode((string)($in['ids'] ?? '[]'), true) ?: []); $out(['msg' => 'Reihenfolge gespeichert.']);
            case 'image_save': ps_image_save((int)($in['img'] ?? 0), $in); $out(['msg' => 'Bild gespeichert.']);
            case 'image_delete': ps_image_delete((int)($in['img'] ?? 0)); $out(['msg' => 'Bild gelöscht.']);
            case 'image_move': ps_move_image((int)($in['img'] ?? 0), (int)($in['to'] ?? 0)); $out(['msg' => 'Bild verschoben.']);
            case 'acl_set': ps_acl_set($id, (int)($in['user'] ?? 0), (string)($in['level'] ?? '')); $out(['acl' => ps_acl($id)]);
            case 'owner_set': ps_owner_set($id, (int)($in['user'] ?? 0)); $out(['msg' => 'Besitzer geändert.']);
            case 'image_acl': $out(['acl' => ps_image_acl((int)($in['img'] ?? 0))]);
            case 'image_acl_set': ps_image_acl_set((int)($in['img'] ?? 0), (int)($in['user'] ?? 0), (string)($in['level'] ?? '')); $out(['acl' => ps_image_acl((int)($in['img'] ?? 0))]);
        }
        throw new InvalidArgumentException('Unbekannte Aktion.');
    } catch (Throwable $e) {
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
        if (!($e instanceof InvalidArgumentException)) error_log('Poster: ' . $e->getMessage());
        $out(['error' => $e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : 'Interner Fehler.']);
    }
}
$me = current_user();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VRCHAT-POSTER · GHOST NODE</title>
    <?php echo emoji_favicon('🖼️'); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <link rel="stylesheet" href="assets/css/poster.css">
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper ps-wrap">
    <div class="nav-bar">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
        <div class="nav-tabs">
            <a href="nexus.php" class="nav-tab">🥽 VRC Nexus</a>
            <?php if (can('posters.manage') && can('admin.access')): ?><a href="admin.php?tab=posters" class="nav-tab">⚙ Poster-Admin</a><?php endif; ?>
        </div>
    </div>
    <header class="ps-head">
        <div>
            <div class="ps-eyebrow">VRCHAT // POSTER // FESTE BILD-ADRESSEN</div>
            <h1>🖼️ VRChat-Poster</h1>
            <p class="muted">Eine Adresse pro Poster – das Bild dahinter tauschst du jederzeit: hochladen (Drag &amp; Drop, Einfügen), per Bildlink oder per FTP. Mit Rotation, Zeitplan, Verlauf und Rechten je Benutzer.</p>
        </div>
        <div class="ps-me" id="psMe"></div>
    </header>
    <div class="ps-bar">
        <input type="search" id="psSearch" placeholder="Slots suchen (Titel, Adresse, Ordner) …" aria-label="Slots suchen">
        <select id="psFolder" aria-label="Ordner"><option value="">Alle Ordner</option></select>
        <select id="psSort" aria-label="Sortierung"><option value="folder">Ordner</option><option value="updated">Zuletzt geändert</option><option value="hits">Meiste Abrufe</option><option value="title">Titel A–Z</option></select>
        <span class="ps-sp"></span>
        <button type="button" class="btn btn-gold" id="psNew" style="display:none">＋ Neuer Slot</button>
    </div>
    <div id="psGrid" class="ps-grid"><div class="muted">Lade …</div></div>
    <p class="muted small ps-foot">Tipp: Dateien direkt auf eine Slot-Kachel ziehen lädt sie in diesen Slot hoch. In VRChat lädt der Bildlader nur Adressen von vertrauenswürdigen Seiten – für eigene Domains muss in VRChat „Allow Untrusted URLs“ aktiv sein, oder die Welt nutzt einen eigenen Lader.</p>
</div>

<div class="ps-modal" id="psModal" hidden><div class="ps-box" role="dialog" aria-modal="true" aria-labelledby="psTitle"><button type="button" class="ps-x" data-close aria-label="Schließen">✕</button><div id="psBody"></div></div></div>
<div class="ps-toast" id="psToast" hidden></div>
<script>window.PS_LOGGED_IN = <?php echo $me ? 'true' : 'false'; ?>;</script>
<script src="assets/js/qr.js"></script>
<script src="assets/js/poster.js"></script>
</body>
</html>
