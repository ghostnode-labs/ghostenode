<?php
/**
 * VRChat-Poster: feste Bild-Adressen („Slots“) für Welten – das Bild dahinter lässt sich jederzeit tauschen.
 *
 *  - Beliebig viele Slots, je Slot beliebig viele Bilder (Verlauf, Rotation, Zeitplan).
 *  - Hochladen: im Browser (Drag & Drop, Einfügen, mehrere Dateien), per Bildlink (einmalig oder „live“ neu laden),
 *    per FTP (Eingangsordner je Slot) und per Schnittstelle (Token, z. B. ShareX/curl).
 *  - Öffentliche Adresse: p.php/<slug>.png – liefert das aktuelle Bild (für VRChat-Bildlader, Videoplayer-Poster …).
 *  - Rechte: global über Rollen (posters.view/upload/edit/manage) und je Slot bzw. je Bild für einzelne Benutzer.
 *    Gäste sehen nur öffentliche Slots/Bilder (nur ansehen).
 */
require_once __DIR__ . '/../core/common.php';
require_once __DIR__ . '/../core/auth.php';

const PS_LEVELS = ['none' => 0, 'view' => 1, 'upload' => 2, 'edit' => 3, 'manage' => 4];
const PS_LEVEL_LABEL = ['view' => '👁 Ansehen', 'upload' => '⬆ Hochladen', 'edit' => '✎ Bearbeiten', 'manage' => '👑 Verwalten'];
const PS_VIS = ['public' => '🌍 Öffentlich (auch Gäste)', 'users' => '🔑 Angemeldete mit Poster-Recht', 'private' => '🔒 Nur freigegebene Benutzer'];
const PS_MODES = ['fixed' => '📌 Festes Bild', 'rotate' => '🔁 Rotation', 'schedule' => '🗓 Nach Zeitplan'];
const PS_FITS = ['none' => 'Original (max. Größe)', 'contain' => 'Einpassen (Rand in Hintergrundfarbe)', 'cover' => 'Füllen (zuschneiden)', 'stretch' => 'Strecken'];
const PS_TYPES = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'];

function ps_db(): Db { return db_get('posters'); }

function ps_migrate(PDO $db): void {
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 1) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS slots (
                id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL UNIQUE, title TEXT NOT NULL DEFAULT '', folder TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '',
                owner INTEGER NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, visibility TEXT NOT NULL DEFAULT 'users', mode TEXT NOT NULL DEFAULT 'fixed', interval_min INTEGER NOT NULL DEFAULT 60,
                fit TEXT NOT NULL DEFAULT 'none', tw INTEGER NOT NULL DEFAULT 0, th INTEGER NOT NULL DEFAULT 0, bg TEXT NOT NULL DEFAULT '#000000', format TEXT NOT NULL DEFAULT 'png',
                active_image INTEGER NOT NULL DEFAULT 0, fallback_image INTEGER NOT NULL DEFAULT 0, token_hash TEXT NOT NULL DEFAULT '', cache_s INTEGER NOT NULL DEFAULT 60,
                hits INTEGER NOT NULL DEFAULT 0, last_hit INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL, updated INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS images (
                id INTEGER PRIMARY KEY AUTOINCREMENT, slot_id INTEGER NOT NULL, file TEXT NOT NULL, mime TEXT NOT NULL, w INTEGER NOT NULL, h INTEGER NOT NULL, size INTEGER NOT NULL,
                sha1 TEXT NOT NULL, source TEXT NOT NULL DEFAULT 'upload', source_url TEXT NOT NULL DEFAULT '', live INTEGER NOT NULL DEFAULT 0, title TEXT NOT NULL DEFAULT '',
                owner INTEGER NOT NULL DEFAULT 0, visibility TEXT NOT NULL DEFAULT 'inherit', enabled INTEGER NOT NULL DEFAULT 1, sort INTEGER NOT NULL DEFAULT 0,
                start_at INTEGER NOT NULL DEFAULT 0, end_at INTEGER NOT NULL DEFAULT 0, fetched INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS idx_ps_images_slot ON images(slot_id);
            CREATE TABLE IF NOT EXISTS slot_acl (slot_id INTEGER NOT NULL, user_id INTEGER NOT NULL, level TEXT NOT NULL, PRIMARY KEY (slot_id, user_id));
            CREATE TABLE IF NOT EXISTS image_acl (image_id INTEGER NOT NULL, user_id INTEGER NOT NULL, level TEXT NOT NULL, PRIMARY KEY (image_id, user_id));
            CREATE TABLE IF NOT EXISTS hits (slot_id INTEGER NOT NULL, day TEXT NOT NULL, n INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (slot_id, day));
            CREATE TABLE IF NOT EXISTS log (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, user_id INTEGER NOT NULL DEFAULT 0, slot_id INTEGER NOT NULL DEFAULT 0, action TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '');
            CREATE INDEX IF NOT EXISTS idx_ps_log_slot ON log(slot_id);
            PRAGMA user_version = 1;
        ");
    }
}

// ---------- Einstellungen (Admin → 🖼 Poster) ----------
function ps_cfg(): array {
    return [
        'max_mb'     => max(1, min(100, (int)setting('ps_max_mb', '15'))),        // je Datei
        'max_dim'    => max(256, min(8192, (int)setting('ps_max_dim', '2048'))),  // VRChat lädt höchstens 2048 × 2048
        'quota_mb'   => max(0, (int)setting('ps_quota_mb', '500')),               // je Benutzer, 0 = unbegrenzt
        'ftp'        => setting('ps_ftp', '1') === '1',
        'links'      => setting('ps_links', '1') === '1',
        'live_min'   => max(5, (int)setting('ps_live_min', '30')),                // Bildlinks „live“ neu laden
        'keep'       => max(0, (int)setting('ps_keep', '0')),                     // alte Bilder je Slot behalten (0 = alle)
        'public_url' => rtrim(setting('ps_public_url', ''), '/'),                 // z. B. https://example.org (leer = automatisch)
    ];
}

// ---------- Pfade ----------
function ps_dir(string $sub = ''): string {
    $d = DATA_DIR . '/uploads/posters' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}
/** FTP-Eingang: Ordner je Slot. Liegt im (gesperrten) data-Ordner – per FTP erreichbar, aus dem Web nicht. */
function ps_inbox(string $slug): string { return ps_dir('inbox') . '/' . $slug; }
function ps_inbox_rel(string $slug): string { return ltrim(str_replace(APP_ROOT, '', ps_inbox($slug)), '/'); }

function ps_base_url(): string {
    $c = ps_cfg();
    if ($c['public_url'] !== '') return $c['public_url'];
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}
function ps_public_url(array $slot, ?int $idx = null): string {
    return ps_base_url() . '/p.php/' . rawurlencode($slot['slug']) . '.' . ($slot['format'] === 'jpg' ? 'jpg' : 'png') . ($idx !== null ? '?i=' . $idx : '');
}

// ---------- Rechte ----------
function ps_uid(): int { $u = current_user(); return $u ? (int)$u['id'] : 0; }

/** Wirksame Stufe eines Benutzers für einen Slot: none|view|upload|edit|manage */
function ps_slot_level(array $slot, ?array $user = null): string {
    $user = $user ?? current_user();
    $vis = $slot['visibility'];
    if (!$user) return $vis === 'public' ? 'view' : 'none';
    $perms = $user['permissions'] ?? [];
    if (in_array('posters.manage', $perms, true)) return 'manage';
    $uid = (int)$user['id'];
    $lvl = 'none';
    if ((int)$slot['owner'] === $uid) $lvl = 'manage';
    else {
        $st = ps_db()->prepare('SELECT level FROM slot_acl WHERE slot_id = ? AND user_id = ?');
        $st->execute([(int)$slot['id'], $uid]);
        $a = (string)$st->fetchColumn();
        if (isset(PS_LEVELS[$a])) $lvl = $a;
        if (PS_LEVELS[$lvl] < 1 && ($vis === 'public' || ($vis === 'users' && in_array('posters.view', $perms, true)))) $lvl = 'view';
    }
    // Rollenrechte begrenzen: ohne „Hochladen“ höchstens ansehen, ohne „Bearbeiten“ höchstens hochladen
    $cap = in_array('posters.edit', $perms, true) ? 4 : (in_array('posters.upload', $perms, true) ? 2 : (in_array('posters.view', $perms, true) || $vis === 'public' ? 1 : 0));
    if ($lvl === 'manage' && $cap >= 3) return 'manage';
    $n = min(PS_LEVELS[$lvl], $cap);
    return (string)array_search($n, PS_LEVELS, true);
}
function ps_can(array $slot, string $need, ?array $user = null): bool { return PS_LEVELS[ps_slot_level($slot, $user)] >= PS_LEVELS[$need]; }

/** Darf der Benutzer dieses Bild sehen / bearbeiten? (Bild-Sichtbarkeit + Freigaben je Bild) */
function ps_image_level(array $img, array $slot, ?array $user = null): string {
    $user = $user ?? current_user();
    $sl = ps_slot_level($slot, $user);
    $vis = $img['visibility'] === 'inherit' ? $slot['visibility'] : $img['visibility'];
    $acl = '';
    if ($user) {
        $st = ps_db()->prepare('SELECT level FROM image_acl WHERE image_id = ? AND user_id = ?');
        $st->execute([(int)$img['id'], (int)$user['id']]);
        $acl = (string)$st->fetchColumn();
    }
    if (PS_LEVELS[$sl] >= PS_LEVELS['edit']) return 'edit';
    if ($acl === 'edit' && $user && in_array('posters.upload', $user['permissions'], true)) return 'edit';
    if ($user && (int)$img['owner'] === (int)$user['id'] && PS_LEVELS[$sl] >= PS_LEVELS['upload']) return 'edit';   // eigene Uploads
    $see = $vis === 'public' || ($vis === 'users' && $user && in_array('posters.view', $user['permissions'], true)) || $acl !== '' || (PS_LEVELS[$sl] >= 2 && $vis !== 'private');
    if ($vis === 'private' && PS_LEVELS[$sl] < 3 && $acl === '' && !($user && (int)$img['owner'] === (int)$user['id'])) $see = false;
    if (!$user && $vis !== 'public') $see = false;
    return $see ? 'view' : 'none';
}

function ps_log(int $slot, string $action, string $detail = ''): void {
    ps_db()->prepare('INSERT INTO log (ts, user_id, slot_id, action, detail) VALUES (?, ?, ?, ?, ?)')->execute([time(), ps_uid(), $slot, $action, mb_substr($detail, 0, 300)]);
}

// ---------- Slots ----------
function ps_slug_ok(string $s): bool { return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{2,47}$/', $s); }
function ps_new_slug(): string { return substr(strtolower(rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'ab'), '=')), 0, 10); }

function ps_slot(int $id): ?array {
    $st = ps_db()->prepare('SELECT * FROM slots WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function ps_slot_by_slug(string $slug): ?array {
    $st = ps_db()->prepare('SELECT * FROM slots WHERE slug = ?');
    $st->execute([strtolower($slug)]);
    return $st->fetch() ?: null;
}
function ps_need_slot(int $id, string $need): array {
    $s = ps_slot($id);
    if (!$s || !ps_can($s, 'view')) throw new InvalidArgumentException('Slot nicht gefunden.');
    if (!ps_can($s, $need)) throw new InvalidArgumentException('Dafür fehlt dir das Recht in diesem Slot (' . (PS_LEVEL_LABEL[$need] ?? $need) . ').');
    return $s;
}

function ps_slot_create(array $in): array {
    if (!can('posters.upload')) throw new InvalidArgumentException('Neue Slots brauchen das Recht „Poster hochladen“.');
    $slug = strtolower(trim((string)($in['slug'] ?? '')));
    if ($slug === '') $slug = ps_new_slug();
    if (!ps_slug_ok($slug)) throw new InvalidArgumentException('Adresse: 3–48 Zeichen, nur a–z, 0–9, - und _.');
    if (ps_slot_by_slug($slug)) throw new InvalidArgumentException('Diese Adresse ist schon vergeben.');
    $t = time();
    ps_db()->prepare('INSERT INTO slots (slug, title, folder, owner, visibility, created, updated, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$slug, mb_substr(trim((string)($in['title'] ?? '')) ?: 'Poster ' . $slug, 0, 80), mb_substr(trim((string)($in['folder'] ?? '')), 0, 40), ps_uid(),
                   isset(PS_VIS[$in['visibility'] ?? '']) ? $in['visibility'] : 'users', $t, $t, $t]);
    $s = ps_slot_by_slug($slug);
    if (ps_cfg()['ftp']) @mkdir(ps_inbox($slug), 0755, true);
    ps_log((int)$s['id'], 'Slot angelegt', $slug);
    audit('Poster-Slot angelegt', $slug);
    return $s;
}

function ps_slot_save(int $id, array $in): array {
    $s = ps_need_slot($id, 'edit');
    $upd = [];
    if (isset($in['title'])) $upd['title'] = mb_substr(trim((string)$in['title']), 0, 80) ?: $s['title'];
    if (isset($in['folder'])) $upd['folder'] = mb_substr(trim((string)$in['folder']), 0, 40);
    if (isset($in['note'])) $upd['note'] = mb_substr(trim((string)$in['note']), 0, 500);
    if (isset($in['enabled'])) $upd['enabled'] = $in['enabled'] === '1' || $in['enabled'] === true ? 1 : 0;
    if (isset($in['visibility']) && isset(PS_VIS[$in['visibility']])) $upd['visibility'] = $in['visibility'];
    if (isset($in['mode']) && isset(PS_MODES[$in['mode']])) $upd['mode'] = $in['mode'];
    if (isset($in['interval_min'])) $upd['interval_min'] = max(1, min(10080, (int)$in['interval_min']));
    if (isset($in['fit']) && isset(PS_FITS[$in['fit']])) $upd['fit'] = $in['fit'];
    $max = ps_cfg()['max_dim'];
    if (isset($in['tw'])) $upd['tw'] = max(0, min($max, (int)$in['tw']));
    if (isset($in['th'])) $upd['th'] = max(0, min($max, (int)$in['th']));
    if (isset($in['bg']) && preg_match('/^#[0-9a-f]{6}$/i', (string)$in['bg'])) $upd['bg'] = strtolower($in['bg']);
    if (isset($in['format'])) $upd['format'] = $in['format'] === 'jpg' ? 'jpg' : 'png';
    if (isset($in['cache_s'])) $upd['cache_s'] = max(0, min(86400, (int)$in['cache_s']));
    if (isset($in['fallback_image'])) $upd['fallback_image'] = ps_own_image($id, (int)$in['fallback_image']);
    if (isset($in['slug']) && strtolower(trim((string)$in['slug'])) !== $s['slug']) {   // „Poster-URL ändern“
        $new = strtolower(trim((string)$in['slug']));
        if (!ps_slug_ok($new)) throw new InvalidArgumentException('Adresse: 3–48 Zeichen, nur a–z, 0–9, - und _.');
        if (ps_slot_by_slug($new)) throw new InvalidArgumentException('Diese Adresse ist schon vergeben.');
        if (is_dir(ps_inbox($s['slug']))) @rename(ps_inbox($s['slug']), ps_inbox($new));
        $upd['slug'] = $new;
        ps_log($id, 'Adresse geändert', $s['slug'] . ' → ' . $new);
    }
    if (!$upd) return $s;
    $upd['updated'] = time();
    $sql = implode(', ', array_map(fn($k) => $k . ' = ?', array_keys($upd)));
    ps_db()->prepare('UPDATE slots SET ' . $sql . ' WHERE id = ?')->execute(array_merge(array_values($upd), [$id]));
    ps_cache_clear($id);
    ps_log($id, 'Einstellungen gespeichert', implode(', ', array_diff(array_keys($upd), ['updated'])));
    return ps_slot($id);
}
function ps_own_image(int $slot, int $img): int {
    if ($img <= 0) return 0;
    $st = ps_db()->prepare('SELECT id FROM images WHERE id = ? AND slot_id = ?');
    $st->execute([$img, $slot]);
    return (int)$st->fetchColumn();
}

function ps_slot_delete(int $id): void {
    $s = ps_need_slot($id, 'manage');
    $st = ps_db()->prepare('SELECT * FROM images WHERE slot_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $img) ps_image_unlink($img);
    foreach (['images WHERE slot_id', 'slot_acl WHERE slot_id', 'hits WHERE slot_id', 'slots WHERE id'] as $t) ps_db()->prepare('DELETE FROM ' . $t . ' = ?')->execute([$id]);
    ps_db()->prepare('DELETE FROM image_acl WHERE image_id NOT IN (SELECT id FROM images)')->execute();
    if (is_dir(ps_inbox($s['slug']))) { foreach (glob(ps_inbox($s['slug']) . '/*') ?: [] as $f) @unlink($f); @rmdir(ps_inbox($s['slug'])); }
    ps_cache_clear($id);
    ps_log($id, 'Slot gelöscht', $s['slug']);
    audit('Poster-Slot gelöscht', $s['slug']);
}

/** Neuer Token für die Upload-Schnittstelle – wird nur einmal angezeigt, gespeichert ist nur der Hash. */
function ps_token_new(int $id): string {
    ps_need_slot($id, 'edit');
    $t = 'ps_' . bin2hex(random_bytes(20));
    ps_db()->prepare('UPDATE slots SET token_hash = ? WHERE id = ?')->execute([hash('sha256', $t), $id]);
    ps_log($id, 'Upload-Token erneuert');
    return $t;
}
function ps_token_slot(string $slug, string $token): ?array {
    $s = ps_slot_by_slug($slug);
    if (!$s || $s['token_hash'] === '' || !hash_equals($s['token_hash'], hash('sha256', $token))) return null;
    return $s;
}

// ---------- Bilder speichern ----------
function ps_used_bytes(int $uid): int {
    $st = ps_db()->prepare('SELECT COALESCE(SUM(size), 0) FROM images WHERE owner = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

/** Bilddaten prüfen, neu kodieren (entfernt Metadaten/versteckte Inhalte) und als Bild des Slots speichern. */
function ps_image_add(array $slot, string $bytes, string $source, array $meta = []): array {
    $c = ps_cfg();
    if (strlen($bytes) > $c['max_mb'] * 1048576) throw new InvalidArgumentException('Datei zu groß (max. ' . $c['max_mb'] . ' MB).');
    $info = @getimagesizefromstring($bytes);
    if (!$info || !isset(PS_TYPES[$info[2]])) throw new InvalidArgumentException('Nur PNG, JPG, WebP oder GIF.');
    if ($info[0] < 8 || $info[1] < 8 || $info[0] > 16384 || $info[1] > 16384) throw new InvalidArgumentException('Ungültige Bildgröße.');
    $owner = (int)($meta['owner'] ?? ps_uid());
    if ($c['quota_mb'] > 0 && $owner && ps_used_bytes($owner) + strlen($bytes) > $c['quota_mb'] * 1048576) throw new InvalidArgumentException('Dein Speicher ist voll (' . $c['quota_mb'] . ' MB) – lösche alte Bilder.');
    [$data, $mime, $w, $h] = ps_reencode($bytes, $info, $c['max_dim']);
    $sha = sha1($data);
    if (empty($meta['replace'])) {   // gleiche Datei im selben Slot nicht doppelt
        $st = ps_db()->prepare('SELECT * FROM images WHERE slot_id = ? AND sha1 = ?');
        $st->execute([(int)$slot['id'], $sha]);
        if ($dup = $st->fetch()) return $dup + ['duplicate' => true];
    }
    $file = bin2hex(random_bytes(12));
    if (file_put_contents(ps_dir('img') . '/' . $file, $data) === false) throw new RuntimeException('Bild konnte nicht gespeichert werden.');
    if (!empty($meta['replace'])) {   // „live“-Bildlink: Datei ersetzen, Eintrag bleibt
        $old = $meta['replace'];
        @unlink(ps_dir('img') . '/' . $old['file']);
        ps_db()->prepare('UPDATE images SET file = ?, mime = ?, w = ?, h = ?, size = ?, sha1 = ?, fetched = ? WHERE id = ?')->execute([$file, $mime, $w, $h, strlen($data), $sha, time(), (int)$old['id']]);
        ps_cache_clear((int)$slot['id']);
        return ps_image((int)$old['id']);
    }
    $sort = (int)ps_db()->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM images WHERE slot_id = ' . (int)$slot['id'])->fetchColumn();
    ps_db()->prepare('INSERT INTO images (slot_id, file, mime, w, h, size, sha1, source, source_url, live, title, owner, sort, fetched, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int)$slot['id'], $file, $mime, $w, $h, strlen($data), $sha, $source, (string)($meta['url'] ?? ''), !empty($meta['live']) ? 1 : 0,
                   mb_substr((string)($meta['title'] ?? ''), 0, 120), $owner, $sort, $source === 'url' ? time() : 0, time()]);
    $id = (int)ps_db()->lastInsertId();
    $img = ps_image($id);
    if (!empty($meta['activate']) || (int)$slot['active_image'] === 0) ps_db()->prepare('UPDATE slots SET active_image = ?, updated = ? WHERE id = ?')->execute([$id, time(), (int)$slot['id']]);
    ps_cache_clear((int)$slot['id']);
    ps_log((int)$slot['id'], 'Bild hinzugefügt', $source . ' · ' . $w . '×' . $h . ($img['title'] !== '' ? ' · ' . $img['title'] : ''));
    ps_trim((int)$slot['id']);
    return $img;
}

function ps_reencode(string $bytes, array $info, int $max): array {
    $mime = PS_TYPES[$info[2]]; $w = $info[0]; $h = $info[1];
    if (!function_exists('imagecreatefromstring') || $mime === 'image/gif' && $w <= $max && $h <= $max) return [$bytes, $mime, $w, $h];   // animierte GIFs unverändert
    $im = @imagecreatefromstring($bytes);
    if (!$im) throw new InvalidArgumentException('Bild ist beschädigt.');
    if ($w > $max || $h > $max) {
        $f = $max / max($w, $h);
        $nw = max(1, (int)round($w * $f)); $nh = max(1, (int)round($h * $f));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im); $im = $dst; $w = $nw; $h = $nh;
    } else { imagealphablending($im, false); imagesavealpha($im, true); }
    ob_start();
    if ($mime === 'image/jpeg') { imagejpeg($im, null, 92); } else { imagepng($im, null, 6); $mime = 'image/png'; }
    $data = (string)ob_get_clean();
    imagedestroy($im);
    return [$data, $mime, $w, $h];
}

/** Alte Bilder über der eingestellten Anzahl entfernen (aktive, Ersatz- und geplante Bilder bleiben). */
function ps_trim(int $slot): void {
    $keep = ps_cfg()['keep'];
    if ($keep <= 0) return;
    $s = ps_slot($slot);
    $st = ps_db()->prepare('SELECT * FROM images WHERE slot_id = ? ORDER BY created DESC');
    $st->execute([$slot]);
    $n = 0;
    foreach ($st->fetchAll() as $img) {
        $n++;
        if ($n <= $keep || (int)$img['id'] === (int)$s['active_image'] || (int)$img['id'] === (int)$s['fallback_image'] || (int)$img['end_at'] > time()) continue;
        ps_image_unlink($img);
        ps_db()->prepare('DELETE FROM images WHERE id = ?')->execute([(int)$img['id']]);
    }
}

function ps_image(int $id): ?array {
    $st = ps_db()->prepare('SELECT * FROM images WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function ps_image_unlink(array $img): void { @unlink(ps_dir('img') . '/' . $img['file']); }

/** Hochgeladene Dateien (Browser): mehrere auf einmal */
function ps_upload_files(array $slot, array $files, array $meta = []): array {
    $out = []; $err = [];
    $list = [];
    if (isset($files['name']) && is_array($files['name'])) { foreach ($files['name'] as $i => $n) $list[] = ['name' => $n, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]]; }
    elseif (isset($files['name'])) $list[] = $files;
    foreach ($list as $f) {
        try {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new InvalidArgumentException('Upload fehlgeschlagen (Code ' . (int)$f['error'] . ').');
            $out[] = ps_image_add($slot, (string)file_get_contents($f['tmp_name']), $meta['source'] ?? 'upload', $meta + ['title' => pathinfo((string)$f['name'], PATHINFO_FILENAME)]);
        } catch (Throwable $e) { $err[] = $f['name'] . ': ' . $e->getMessage(); }
    }
    return ['images' => $out, 'errors' => $err];
}

// ---------- Bildlink ----------
function ps_url_allowed(string $url): bool {
    if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return false;
    if (getenv('PS_ALLOW_LOCAL') === '1') return true;   // nur für Tests
    $host = (string)parse_url($url, PHP_URL_HOST);
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return false;
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    return true;
}
function ps_fetch(string $url): string {
    if (!function_exists('curl_init')) throw new RuntimeException('Die PHP-Erweiterung cURL fehlt.');
    if (!ps_url_allowed($url)) throw new InvalidArgumentException('Diese Adresse ist nicht erlaubt (nur öffentliche http(s)-Adressen).');
    $max = ps_cfg()['max_mb'] * 1048576;
    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 40, CURLOPT_USERAGENT => 'GhostNode-Poster/1.0', CURLOPT_HTTPHEADER => ['Accept: image/*'],
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $max) { $body .= $chunk; return strlen($body) > $max ? 0 : strlen($chunk); }]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    if ($code >= 300 && $code < 400 && $loc !== '') {   // Weiterleitungen einzeln prüfen (keine internen Ziele)
        static $hops = 0;
        if (++$hops > 4) { $hops = 0; throw new RuntimeException('Zu viele Weiterleitungen.'); }
        try { return ps_fetch($loc); } finally { $hops = 0; }
    }
    if (strlen($body) > $max) throw new InvalidArgumentException('Bild zu groß (max. ' . ps_cfg()['max_mb'] . ' MB).');
    if (!$ok || $code !== 200 || $body === '') throw new RuntimeException('Bild konnte nicht geladen werden (HTTP ' . $code . ').');
    return $body;
}
function ps_add_url(array $slot, string $url, bool $live, bool $activate): array {
    if (!ps_cfg()['links']) throw new InvalidArgumentException('Bildlinks sind in den Admin-Einstellungen ausgeschaltet.');
    $url = trim($url);
    return ps_image_add($slot, ps_fetch($url), 'url', ['url' => $url, 'live' => $live, 'activate' => $activate, 'title' => basename((string)parse_url($url, PHP_URL_PATH))]);
}

/** „Live“-Bildlinks neu laden (Aufgabe) – nur wenn sich das Bild geändert hat */
function ps_refresh_live(int $limit = 20): int {
    $c = ps_cfg();
    $st = ps_db()->prepare('SELECT * FROM images WHERE live = 1 AND fetched < ? ORDER BY fetched LIMIT ' . (int)$limit);
    $st->execute([time() - $c['live_min'] * 60]);
    $n = 0;
    foreach ($st->fetchAll() as $img) {
        $s = ps_slot((int)$img['slot_id']);
        if (!$s) continue;
        try {
            $bytes = ps_fetch($img['source_url']);
            $info = @getimagesizefromstring($bytes);
            if (!$info || !isset(PS_TYPES[$info[2]])) throw new RuntimeException('kein Bild');
            [$data] = ps_reencode($bytes, $info, $c['max_dim']);
            if (sha1($data) !== $img['sha1']) { ps_image_add($s, $bytes, 'url', ['replace' => $img, 'owner' => (int)$img['owner']]); ps_log((int)$s['id'], 'Live-Bild aktualisiert', $img['source_url']); $n++; }
            else ps_db()->prepare('UPDATE images SET fetched = ? WHERE id = ?')->execute([time(), (int)$img['id']]);
        } catch (Throwable $e) {
            ps_db()->prepare('UPDATE images SET fetched = ? WHERE id = ?')->execute([time(), (int)$img['id']]);
            ps_log((int)$s['id'], 'Live-Bild fehlgeschlagen', $e->getMessage());
        }
    }
    return $n;
}

// ---------- FTP-Eingang ----------
/** Neue Dateien aus den Eingangsordnern übernehmen (erst wenn sie 20 s nicht mehr verändert wurden – Upload fertig). */
function ps_ftp_import(?int $only_slot = null): array {
    if (!ps_cfg()['ftp']) return ['n' => 0, 'errors' => []];
    $n = 0; $err = [];
    $slots = $only_slot ? [ps_slot($only_slot)] : ps_db()->query('SELECT * FROM slots')->fetchAll();
    foreach (array_filter($slots) as $s) {
        $dir = ps_inbox($s['slug']);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); continue; }
        $files = glob($dir . '/*.{png,PNG,jpg,JPG,jpeg,JPEG,webp,WEBP,gif,GIF}', GLOB_BRACE) ?: [];
        usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
        foreach ($files as $f) {
            if (time() - filemtime($f) < 20 && !getenv('PS_FTP_NOW')) continue;
            try {
                ps_image_add($s, (string)file_get_contents($f), 'ftp', ['owner' => (int)$s['owner'], 'activate' => true, 'title' => pathinfo($f, PATHINFO_FILENAME)]);
                $n++;
            } catch (Throwable $e) { $err[] = $s['slug'] . '/' . basename($f) . ': ' . $e->getMessage(); }
            @unlink($f);
        }
    }
    return ['n' => $n, 'errors' => $err];
}

// ---------- Auswahl & Auslieferung ----------
/** Bilder, die gerade gezeigt werden dürfen (aktiv und im Zeitfenster), nach Reihenfolge */
function ps_live_images(int $slot): array {
    $t = time();
    $st = ps_db()->prepare('SELECT * FROM images WHERE slot_id = ? AND enabled = 1 AND (start_at = 0 OR start_at <= ?) AND (end_at = 0 OR end_at > ?) ORDER BY sort, id');
    $st->execute([$slot, $t, $t]);
    return $st->fetchAll();
}
/** Welches Bild liefert der Slot jetzt? ($idx = festes Bild Nr. idx der Liste, z. B. für mehrere Poster aus einem Slot) */
function ps_pick(array $s, ?int $idx = null): ?array {
    $list = ps_live_images((int)$s['id']);
    if ($idx !== null) return $list[$idx] ?? null;
    $pick = null;
    if ($s['mode'] === 'rotate' && $list) $pick = $list[intdiv(time(), max(60, (int)$s['interval_min'] * 60)) % count($list)];
    elseif ($s['mode'] === 'schedule') { foreach ($list as $i) if ((int)$i['start_at'] > 0 && (!$pick || (int)$i['start_at'] > (int)$pick['start_at'])) $pick = $i; }
    if (!$pick) foreach ($list as $i) if ((int)$i['id'] === (int)$s['active_image']) $pick = $i;
    if (!$pick && $s['mode'] !== 'fixed' && $list) $pick = $list[0];
    if (!$pick && (int)$s['fallback_image']) $pick = ps_image((int)$s['fallback_image']);
    if (!$pick && $list) $pick = $list[0];
    return $pick;
}

function ps_cache_clear(int $slot): void { foreach (glob(ps_dir('cache') . '/' . $slot . '_*') ?: [] as $f) @unlink($f); }

/** Bild für den Slot aufbereiten (Zielgröße, Einpassen/Füllen, Format) – Ergebnis wird zwischengespeichert. */
function ps_render(array $s, array $img): array {
    $fmt = $s['format'] === 'jpg' ? 'jpg' : 'png';
    $src = ps_dir('img') . '/' . $img['file'];
    $plain = ((int)$s['tw'] === 0 && (int)$s['th'] === 0) || $s['fit'] === 'none';
    if ($plain && (($fmt === 'png' && $img['mime'] === 'image/png') || ($fmt === 'jpg' && $img['mime'] === 'image/jpeg') || !function_exists('imagecreatefromstring'))) return [$src, $img['mime']];
    $key = $s['id'] . '_' . $img['id'] . '_' . substr(sha1($img['sha1'] . '|' . $s['fit'] . '|' . $s['tw'] . '|' . $s['th'] . '|' . $s['bg'] . '|' . $fmt), 0, 16) . '.' . $fmt;
    $out = ps_dir('cache') . '/' . $key;
    $mime = $fmt === 'jpg' ? 'image/jpeg' : 'image/png';
    if (is_file($out)) return [$out, $mime];
    $im = @imagecreatefromstring((string)file_get_contents($src));
    if (!$im) return [$src, $img['mime']];
    $w = imagesx($im); $h = imagesy($im);
    $tw = (int)$s['tw'] ?: (int)round($w * ((int)$s['th'] / max(1, $h)));
    $th = (int)$s['th'] ?: (int)round($h * ((int)$s['tw'] / max(1, $w)));
    if ($plain) { $tw = $w; $th = $h; }
    $dst = imagecreatetruecolor(max(1, $tw), max(1, $th));
    [$r, $g, $b] = sscanf($s['bg'], '#%02x%02x%02x');
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagefill($dst, 0, 0, $fmt === 'png' && $s['fit'] === 'contain' && strtolower($s['bg']) === '#000000' ? imagecolorallocatealpha($dst, 0, 0, 0, 127) : imagecolorallocate($dst, $r, $g, $b));
    imagealphablending($dst, true);
    if ($plain || $s['fit'] === 'stretch') imagecopyresampled($dst, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    elseif ($s['fit'] === 'contain') { $f = min($tw / $w, $th / $h); $nw = (int)round($w * $f); $nh = (int)round($h * $f); imagecopyresampled($dst, $im, intdiv($tw - $nw, 2), intdiv($th - $nh, 2), 0, 0, $nw, $nh, $w, $h); }
    else { $f = max($tw / $w, $th / $h); $cw = (int)round($tw / $f); $ch = (int)round($th / $f); imagecopyresampled($dst, $im, 0, 0, intdiv($w - $cw, 2), intdiv($h - $ch, 2), $tw, $th, $cw, $ch); }
    if ($fmt === 'jpg') imagejpeg($dst, $out, 90); else imagepng($dst, $out, 6);
    imagedestroy($im); imagedestroy($dst);
    return [$out, $mime];
}

/** Öffentliche Auslieferung (p.php) – für VRChat, Browser & Co. */
function ps_serve(string $slug, ?int $idx = null): void {
    $s = ps_slot_by_slug($slug);
    if (!$s || !(int)$s['enabled']) { http_response_code(404); header('Cache-Control: no-store'); exit('Poster nicht gefunden.'); }
    $img = ps_pick($s, $idx);
    if (!$img || !is_file(ps_dir('img') . '/' . $img['file'])) { http_response_code(404); header('Cache-Control: max-age=30'); exit('Noch kein Bild.'); }
    [$file, $mime] = ps_render($s, $img);
    $etag = '"' . substr(sha1($file . filemtime($file)), 0, 20) . '"';
    // Abrufe zählen (je Tag) – billig, ohne Benutzerdaten
    try {
        $day = date('Y-m-d');
        ps_db()->prepare('UPDATE slots SET hits = hits + 1, last_hit = ? WHERE id = ?')->execute([time(), (int)$s['id']]);
        $u = ps_db()->prepare('UPDATE hits SET n = n + 1 WHERE slot_id = ? AND day = ?'); $u->execute([(int)$s['id'], $day]);
        if (!$u->rowCount()) ps_db()->prepare('INSERT INTO hits (slot_id, day, n) VALUES (?, ?, 1)')->execute([(int)$s['id'], $day]);
    } catch (Throwable $e) { /* Zählen ist unwichtig */ }
    header('Content-Type: ' . $mime);
    header('Access-Control-Allow-Origin: *');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'");
    header('Cache-Control: public, max-age=' . (int)$s['cache_s']);
    header('ETag: ' . $etag);
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
    header('Content-Length: ' . filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
    exit;
}

/** Vorschau im Dashboard (Rechte je Bild beachten) */
function ps_thumb(int $id): void {
    $img = ps_image($id);
    $s = $img ? ps_slot((int)$img['slot_id']) : null;
    if (!$img || !$s || ps_image_level($img, $s) === 'none') { http_response_code(404); exit; }
    $f = ps_dir('img') . '/' . $img['file'];
    if (!is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: ' . $img['mime']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}

// ---------- Listen fürs Dashboard ----------
function ps_slot_public(array $s, ?array $user = null): array {
    $lvl = ps_slot_level($s, $user);
    $cur = ps_pick($s);
    $o = ['id' => (int)$s['id'], 'slug' => $s['slug'], 'title' => $s['title'], 'folder' => $s['folder'], 'note' => $s['note'], 'enabled' => (int)$s['enabled'], 'visibility' => $s['visibility'],
          'mode' => $s['mode'], 'interval_min' => (int)$s['interval_min'], 'fit' => $s['fit'], 'tw' => (int)$s['tw'], 'th' => (int)$s['th'], 'bg' => $s['bg'], 'format' => $s['format'],
          'cache_s' => (int)$s['cache_s'], 'active_image' => (int)$s['active_image'], 'fallback_image' => (int)$s['fallback_image'], 'hits' => (int)$s['hits'], 'last_hit' => (int)$s['last_hit'],
          'updated' => (int)$s['updated'], 'url' => ps_public_url($s), 'level' => $lvl, 'current' => $cur && ps_image_level($cur, $s, $user) !== 'none' ? (int)$cur['id'] : 0,
          'has_token' => $s['token_hash'] !== '', 'own' => $user && (int)$s['owner'] === (int)$user['id']];
    if ($user) { $o['owner_name'] = ps_user_name((int)$s['owner']); }
    if (PS_LEVELS[$lvl] >= PS_LEVELS['upload'] && ps_cfg()['ftp']) $o['ftp'] = ps_inbox_rel($s['slug']);
    return $o;
}
function ps_user_name(int $id): string {
    static $c = [];
    if (!isset($c[$id])) { $st = site_db()->prepare("SELECT CASE WHEN display_name <> '' THEN display_name ELSE username END FROM users WHERE id = ?"); $st->execute([$id]); $c[$id] = (string)($st->fetchColumn() ?: '–'); }
    return $c[$id];
}
function ps_slots_visible(): array {
    $u = current_user();
    $out = [];
    foreach (ps_db()->query('SELECT * FROM slots ORDER BY folder, sort, id')->fetchAll() as $s) if (ps_can($s, 'view', $u)) $out[] = ps_slot_public($s, $u);
    return $out;
}
function ps_images_visible(array $s): array {
    $u = current_user();
    $st = ps_db()->prepare('SELECT * FROM images WHERE slot_id = ? ORDER BY sort, id');
    $st->execute([(int)$s['id']]);
    $out = [];
    $t = time();
    foreach ($st->fetchAll() as $i) {
        $lv = ps_image_level($i, $s, $u);
        if ($lv === 'none') continue;
        $o = ['id' => (int)$i['id'], 'title' => $i['title'], 'w' => (int)$i['w'], 'h' => (int)$i['h'], 'size' => (int)$i['size'], 'mime' => $i['mime'], 'enabled' => (int)$i['enabled'],
              'visibility' => $i['visibility'], 'start_at' => (int)$i['start_at'], 'end_at' => (int)$i['end_at'], 'created' => (int)$i['created'], 'level' => $lv,
              'live_now' => (int)$i['enabled'] && (!$i['start_at'] || $i['start_at'] <= $t) && (!$i['end_at'] || $i['end_at'] > $t)];
        if ($u) { $o += ['source' => $i['source'], 'live' => (int)$i['live'], 'owner_name' => ps_user_name((int)$i['owner'])]; if ($lv === 'edit') $o['source_url'] = $i['source_url']; }
        $out[] = $o;
    }
    return $out;
}

/** Bild bearbeiten (Titel, aktiv, Sichtbarkeit, Zeitfenster) */
function ps_image_save(int $id, array $in): array {
    $img = ps_image($id);
    $s = $img ? ps_slot((int)$img['slot_id']) : null;
    if (!$img || !$s || ps_image_level($img, $s) !== 'edit') throw new InvalidArgumentException('Dieses Bild darfst du nicht bearbeiten.');
    $upd = [];
    if (isset($in['title'])) $upd['title'] = mb_substr(trim((string)$in['title']), 0, 120);
    if (isset($in['enabled'])) $upd['enabled'] = $in['enabled'] === '1' ? 1 : 0;
    if (isset($in['visibility']) && ($in['visibility'] === 'inherit' || isset(PS_VIS[$in['visibility']]))) $upd['visibility'] = $in['visibility'];
    if (isset($in['start_at'])) $upd['start_at'] = $in['start_at'] === '' ? 0 : max(0, (int)strtotime((string)$in['start_at']));
    if (isset($in['end_at'])) $upd['end_at'] = $in['end_at'] === '' ? 0 : max(0, (int)strtotime((string)$in['end_at']));
    if (isset($in['live'])) $upd['live'] = $in['live'] === '1' && $img['source_url'] !== '' ? 1 : 0;
    if ($upd) {
        ps_db()->prepare('UPDATE images SET ' . implode(', ', array_map(fn($k) => $k . ' = ?', array_keys($upd))) . ' WHERE id = ?')->execute(array_merge(array_values($upd), [$id]));
        ps_cache_clear((int)$s['id']);
        ps_log((int)$s['id'], 'Bild bearbeitet', '#' . $id . ' ' . implode(', ', array_keys($upd)));
    }
    return ps_image($id);
}
function ps_image_delete(int $id): void {
    $img = ps_image($id);
    $s = $img ? ps_slot((int)$img['slot_id']) : null;
    if (!$img || !$s || ps_image_level($img, $s) !== 'edit') throw new InvalidArgumentException('Dieses Bild darfst du nicht löschen.');
    ps_image_unlink($img);
    ps_db()->prepare('DELETE FROM images WHERE id = ?')->execute([$id]);
    ps_db()->prepare('DELETE FROM image_acl WHERE image_id = ?')->execute([$id]);
    foreach (['active_image', 'fallback_image'] as $k) if ((int)$s[$k] === $id) ps_db()->prepare('UPDATE slots SET ' . $k . ' = 0 WHERE id = ?')->execute([(int)$s['id']]);
    ps_cache_clear((int)$s['id']);
    ps_log((int)$s['id'], 'Bild gelöscht', '#' . $id . ' ' . $img['title']);
}
function ps_activate(int $slot, int $img): void {
    $s = ps_need_slot($slot, 'edit');
    if (!ps_own_image($slot, $img)) throw new InvalidArgumentException('Bild gehört nicht zu diesem Slot.');
    ps_db()->prepare('UPDATE slots SET active_image = ?, updated = ? WHERE id = ?')->execute([$img, time(), $slot]);
    ps_cache_clear($slot);
    ps_log($slot, 'Aktives Bild gewechselt', '#' . $img);
}
function ps_reorder(int $slot, array $ids): void {
    ps_need_slot($slot, 'edit');
    $q = ps_db()->prepare('UPDATE images SET sort = ? WHERE id = ? AND slot_id = ?');
    foreach (array_values($ids) as $i => $id) $q->execute([$i + 1, (int)$id, $slot]);
    ps_log($slot, 'Reihenfolge geändert');
}
function ps_move_image(int $id, int $to): void {
    $img = ps_image($id);
    $s = $img ? ps_slot((int)$img['slot_id']) : null;
    if (!$img || !$s || ps_image_level($img, $s) !== 'edit') throw new InvalidArgumentException('Dieses Bild darfst du nicht verschieben.');
    ps_need_slot($to, 'upload');
    ps_db()->prepare('UPDATE images SET slot_id = ? WHERE id = ?')->execute([$to, $id]);
    foreach (['active_image', 'fallback_image'] as $k) if ((int)$s[$k] === $id) ps_db()->prepare('UPDATE slots SET ' . $k . ' = 0 WHERE id = ?')->execute([(int)$s['id']]);
    ps_cache_clear((int)$s['id']); ps_cache_clear($to);
    ps_log((int)$s['id'], 'Bild verschoben', '#' . $id . ' → Slot ' . $to);
}

// ---------- Freigaben ----------
function ps_users_list(): array { return site_db()->query("SELECT id, username, display_name FROM users WHERE active = 1 ORDER BY username")->fetchAll(); }
function ps_acl(int $slot): array {
    ps_need_slot($slot, 'manage');
    $st = ps_db()->prepare('SELECT user_id, level FROM slot_acl WHERE slot_id = ?');
    $st->execute([$slot]);
    return array_map(fn($r) => ['user_id' => (int)$r['user_id'], 'name' => ps_user_name((int)$r['user_id']), 'level' => $r['level']], $st->fetchAll());
}
function ps_acl_set(int $slot, int $user, string $level): void {
    $s = ps_need_slot($slot, 'manage');
    if ($user === (int)$s['owner']) throw new InvalidArgumentException('Der Besitzer hat immer alle Rechte.');
    if (!user_load($user)) throw new InvalidArgumentException('Benutzer nicht gefunden.');
    if ($level === 'none') ps_db()->prepare('DELETE FROM slot_acl WHERE slot_id = ? AND user_id = ?')->execute([$slot, $user]);
    elseif (isset(PS_LEVEL_LABEL[$level])) {
        ps_db()->prepare('DELETE FROM slot_acl WHERE slot_id = ? AND user_id = ?')->execute([$slot, $user]);
        ps_db()->prepare('INSERT INTO slot_acl (slot_id, user_id, level) VALUES (?, ?, ?)')->execute([$slot, $user, $level]);
    } else throw new InvalidArgumentException('Unbekannte Stufe.');
    ps_log($slot, 'Freigabe', ps_user_name($user) . ': ' . $level);
}
function ps_owner_set(int $slot, int $user): void {
    ps_need_slot($slot, 'manage');
    if (!user_load($user)) throw new InvalidArgumentException('Benutzer nicht gefunden.');
    ps_db()->prepare('UPDATE slots SET owner = ? WHERE id = ?')->execute([$user, $slot]);
    ps_db()->prepare('DELETE FROM slot_acl WHERE slot_id = ? AND user_id = ?')->execute([$slot, $user]);
    ps_log($slot, 'Besitzer geändert', ps_user_name($user));
}
function ps_image_acl(int $img): array {
    $i = ps_image($img); $s = $i ? ps_slot((int)$i['slot_id']) : null;
    if (!$i || !$s || !ps_can($s, 'edit')) throw new InvalidArgumentException('Keine Berechtigung.');
    $st = ps_db()->prepare('SELECT user_id, level FROM image_acl WHERE image_id = ?');
    $st->execute([$img]);
    return array_map(fn($r) => ['user_id' => (int)$r['user_id'], 'name' => ps_user_name((int)$r['user_id']), 'level' => $r['level']], $st->fetchAll());
}
function ps_image_acl_set(int $img, int $user, string $level): void {
    $i = ps_image($img); $s = $i ? ps_slot((int)$i['slot_id']) : null;
    if (!$i || !$s || !ps_can($s, 'edit')) throw new InvalidArgumentException('Keine Berechtigung.');
    if (!user_load($user)) throw new InvalidArgumentException('Benutzer nicht gefunden.');
    ps_db()->prepare('DELETE FROM image_acl WHERE image_id = ? AND user_id = ?')->execute([$img, $user]);
    if (in_array($level, ['view', 'edit'], true)) ps_db()->prepare('INSERT INTO image_acl (image_id, user_id, level) VALUES (?, ?, ?)')->execute([$img, $user, $level]);
    ps_log((int)$s['id'], 'Bild-Freigabe', '#' . $img . ' ' . ps_user_name($user) . ': ' . $level);
}

function ps_hits(int $slot, int $days = 30): array {
    $st = ps_db()->prepare('SELECT day, n FROM hits WHERE slot_id = ? AND day >= ? ORDER BY day');
    $st->execute([$slot, date('Y-m-d', time() - $days * 86400)]);
    return $st->fetchAll();
}
function ps_log_list(int $slot): array {
    $st = ps_db()->prepare('SELECT ts, user_id, action, detail FROM log WHERE slot_id = ? ORDER BY id DESC LIMIT 100');
    $st->execute([$slot]);
    return array_map(fn($r) => ['ts' => (int)$r['ts'], 'user' => $r['user_id'] ? ps_user_name((int)$r['user_id']) : 'System', 'action' => $r['action'], 'detail' => $r['detail']], $st->fetchAll());
}

/** Aufgabe (cron.php / Aufgabenplaner): FTP-Eingang übernehmen + Live-Bildlinks neu laden */
function ps_task(): string {
    $f = ps_ftp_import();
    $l = ps_refresh_live(30);
    return $f['n'] . ' per FTP übernommen' . ($f['errors'] ? ' (' . count($f['errors']) . ' Fehler)' : '') . ' · ' . $l . ' Live-Bilder aktualisiert';
}

/** Übersicht für die Admin-Zentrale */
function ps_stats(): array {
    $db = ps_db();
    return ['slots' => (int)$db->query('SELECT COUNT(*) FROM slots')->fetchColumn(), 'images' => (int)$db->query('SELECT COUNT(*) FROM images')->fetchColumn(),
            'bytes' => (int)$db->query('SELECT COALESCE(SUM(size), 0) FROM images')->fetchColumn(), 'hits' => (int)$db->query('SELECT COALESCE(SUM(hits), 0) FROM slots')->fetchColumn(),
            'live' => (int)$db->query('SELECT COUNT(*) FROM images WHERE live = 1')->fetchColumn()];
}
