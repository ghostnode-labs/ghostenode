<?php
declare(strict_types=1);
/**
 * 6.6 Globale Suche: Seiten, Changelog, Termine, Marktplatz – nur, was die Person ohnehin sehen darf.
 * <title>Suche</title>
 * ?q=…  (HTML)   ·   ?q=…&json=1  (für das Suchfeld im Menü)
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
auth_require_setup();

$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$u = current_user();
$hits = [];
$has = function (string ...$t) use ($q): bool { foreach ($t as $x) if ($x !== '' && mb_stripos($x, $q) !== false) return true; return false; };
$snip = function (string $t) use ($q): string {
    $t = trim(preg_replace('/\s+/', ' ', strip_tags($t)));
    $i = mb_stripos($t, $q);
    if ($i === false) return mb_substr($t, 0, 140);
    $s = max(0, $i - 50);
    return ($s ? '…' : '') . mb_substr($t, $s, 150) . (mb_strlen($t) > $s + 150 ? '…' : '');
};
if (mb_strlen($q) >= 2) {
    try {   // Seiten (nur sichtbare)
        foreach (site_db()->query('SELECT * FROM pages ORDER BY sort, title')->fetchAll() as $p) {
            if (!page_visible_in_index($p, false) || !page_access_ok($p)) continue;
            if ($has((string)$p['title'], (string)($p['description'] ?? ''))) $hits[] = ['k' => '🗂 Seite', 'icon' => (string)($p['icon'] ?? '📄'), 't' => (string)$p['title'], 's' => (string)($p['description'] ?? ''), 'h' => page_href($p)];
        }
    } catch (Throwable $e) { /* egal */ }
    // 9.0: Links aus allen Link-Hubs (nur aus Seiten, die die Person öffnen darf; NSFW nur wie im Dashboard)
    try {
        require_once __DIR__ . '/inc/modules/hub.php';
        $seenUrl = [];
        foreach (site_db()->query('SELECT * FROM pages ORDER BY sort, title')->fetchAll() as $p) {
            if (!page_visible_in_index($p, false) || !page_access_ok($p)) continue;
            $hub = null;
            if ($p['kind'] === 'custom' && $p['template'] === 'links') $hub = hub_load('page_' . $p['slug'], []);
            elseif ($p['kind'] === 'file' && preg_match('/^[\w\-]+\.php$/', (string)$p['slug']) && is_file(__DIR__ . '/' . $p['slug'])
                    && strpos((string)file_get_contents(__DIR__ . '/' . $p['slug']), "if (defined('LINKS_ONLY')) return \$categories;") !== false) {
                if (!defined('LINKS_ONLY')) define('LINKS_ONLY', true);
                $hub = (function ($f) { return include $f; })(__DIR__ . '/' . $p['slug']);
            }
            foreach ((array)$hub as $cat => $links) foreach ((array)$links as $l) {
                if (!is_array($l) || empty($l['url']) || !nsfw_visible($l, (string)$cat)) continue;
                $k = strtolower(rtrim((string)$l['url'], '/'));
                if (isset($seenUrl[$k]) || !$has((string)($l['title'] ?? ''), (string)($l['desc'] ?? ''), (string)$cat)) continue;
                $seenUrl[$k] = 1;
                if (!preg_match('#^https?://#i', (string)$l['url'])) continue;
                $hits[] = ['k' => '🔗 Link · ' . $p['title'], 'icon' => (string)($l['icon'] ?? '🔗'), 't' => (string)($l['title'] ?? $l['url']), 's' => $cat . ((string)($l['desc'] ?? '') !== '' ? ' — ' . $snip((string)$l['desc']) : ''), 'h' => (string)$l['url'], 'ext' => true];
                if (count($seenUrl) >= 30) break 3;
            }
        }
    } catch (Throwable $e) { /* Hubs optional */ }
    // 9.0: Admin-Bereiche (nur mit passendem Recht)
    if ($u) foreach ([['admin.php?tab=users', '👥', 'Benutzer verwalten', 'users.view', 'Konten Rollen sperren'], ['admin.php?tab=roles', '🛡️', 'Rollen & Rechte', 'roles.manage', 'Berechtigungen'],
              ['admin.php?tab=invites', '🎟️', 'Einladungen', 'invites.manage', 'Registrierung Code'], ['admin.php?tab=sessions', '🖥️', 'Sitzungen & Geräte', 'sessions.manage', 'abmelden'],
              ['admin.php?tab=security', '🔐', 'Sicherheit', 'security.manage', 'Passwort IP Sperre 2FA'], ['admin.php?tab=audit', '📜', 'Protokoll', 'audit.view', 'Log Audit'],
              ['admin.php?tab=database', '🗄️', 'Datenbank', 'database.manage', 'MySQL SQLite'], ['admin.php?tab=system', '🛰️', 'System & Wartung', 'system.manage', 'Wartungsmodus Wartungsfenster Aufgaben Backup'],
              ['admin.php?tab=server', '🖥', 'Server & Prozesse', 'system.manage', 'htop CPU RAM Dienste'], ['admin.php?tab=account', '👤', 'Mein Konto', null, 'Passwort Zwei-Faktor Discord'],
              ['me.php', '👤', 'Mein Bereich', null, 'Darstellung Benachrichtigungen Profil Webhooks Datenschutz']] as [$h, $i, $t, $perm, $kw])
        if (($perm === null || can($perm)) && $has($t, $kw)) $hits[] = ['k' => '⚙ Verwaltung', 'icon' => $i, 't' => $t, 's' => '', 'h' => $h];
    // 9.0: Dashboard-Widgets
    try {
        require_once __DIR__ . '/inc/core/widgets.php';
        foreach (widgets_available() as $type => $m) if (empty($m['error']) && widget_allowed($m) && $has((string)$m['name'], (string)$m['description']))
            $hits[] = ['k' => '🧩 Widget', 'icon' => (string)$m['icon'], 't' => (string)$m['name'], 's' => $snip((string)$m['description']), 'h' => 'dashboard.php'];
    } catch (Throwable $e) { /* Widgets optional */ }
    foreach ([['changelog.php', '📜', 'Changelog'], ['downloads.php', '⬇', 'Downloads'], ['Roadmap.php', '🗺', 'Roadmap'], ['api.php', '🔌', 'API-Dokumentation'], ['market.php', '🧩', 'Marktplatz'], ['nexus.php', '🥽', 'VRC Nexus']] as [$h, $i, $t])
        if ($has($t, $h)) $hits[] = ['k' => '🗂 Seite', 'icon' => $i, 't' => $t, 's' => '', 'h' => $h];
    try {   // Changelog
        require_once __DIR__ . '/inc/modules/changelog.php';
        foreach (cl_fetch() as $r) { $r['body'] = preg_replace('/\n\| File \|.*$/s', '', (string)$r['body']); if (!$has('Version ' . $r['v'] . ' ' . cl_ver($r['v']), $r['body'])) continue; $hits[] = ['k' => '📜 Changelog', 'icon' => '📦', 't' => 'VRC Nexus ' . $r['v'], 's' => $snip(str_replace('🔞 ***', '', $r['body'])), 'h' => 'changelog.php#v' . $r['v']]; if (count($hits) > 60) break; }
    } catch (Throwable $e) { /* GitHub nicht erreichbar */ }
    try {   // Termine (±1 Jahr)
        require_once __DIR__ . '/inc/modules/calendar.php';
        $now = new DateTimeImmutable('now');
        $seen = [];
        foreach (cal_items($now->modify('-60 days'), $now->modify('+365 days'), $u ? can('calendar.manage') : false, ['events']) as $it) {
            $t = (string)($it['title'] ?? ''); if (isset($seen[$t]) || !$has($t, (string)($it['description'] ?? ''))) continue; $seen[$t] = 1;
            $ts = strtotime((string)($it['start'] ?? $it['start_at'] ?? '')) ?: 0;
            $hits[] = ['k' => '📅 Termin', 'icon' => '📅', 't' => $t, 's' => $ts ? date('d.m.Y H:i', $ts) : '', 'h' => 'calendar.php'];
        }
    } catch (Throwable $e) { /* Kalender aus */ }
    try {   // Marktplatz (nur freigegebene Plugins)
        require_once __DIR__ . '/inc/modules/market.php';
        foreach (mk_load() as $id => $pl) {
            $v = is_array($pl) ? $pl : []; $ok = array_values(array_filter((array)($v['versions'] ?? []), fn($x) => ($x['status'] ?? '') === 'approved'));
            if (!$ok) continue;   // nur Plugins mit freigegebener Version
            $top = end($ok); $name = (string)($top['name'] ?? $v['name'] ?? $id); $desc = (string)($top['description'] ?? $v['description'] ?? '');
            if ($has($name, $desc)) $hits[] = ['k' => '🧩 Plugin', 'icon' => '🧩', 't' => $name, 's' => $snip($desc), 'h' => 'market.php#' . rawurlencode((string)$id)];
        }
    } catch (Throwable $e) { /* Marktplatz aus */ }
}
$hits = array_slice($hits, 0, 80);
if (!empty($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode(['q' => $q, 'items' => array_slice($hits, 0, 12)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Suche · GhostNode</title>
<meta name="robots" content="noindex"><link rel="stylesheet" href="assets/css/hud.css">
<style>body.sr{padding:0;display:block}.sr-wrap{box-sizing:border-box;max-width:860px;margin:0 auto;padding:20px 20px 60px}
.sr-hit{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.06);text-decoration:none;color:inherit}.sr-hit b{display:block}.sr-hit small{color:var(--text-muted)}
.sr-k{font-size:.7rem;letter-spacing:1px;color:var(--accent-cyan);text-transform:uppercase}.muted{color:var(--text-muted)}</style></head><body class="sr"><div class="sr-wrap">
<h1 style="margin:0 0 12px">🔍 Suche</h1>
<form method="GET" style="display:flex;gap:8px"><input type="search" name="q" value="<?php echo e($q); ?>" placeholder="Seiten, Changelog, Termine, Plugins …" autofocus><button class="btn">Suchen</button></form>
<?php if ($q !== '' && mb_strlen($q) < 2): ?><p class="muted">Mindestens 2 Zeichen.</p><?php endif; ?>
<?php if (mb_strlen($q) >= 2): ?><p class="muted"><?php echo count($hits); ?> Treffer für „<?php echo e($q); ?>“</p>
<?php foreach ($hits as $h): ?><a class="sr-hit" href="<?php echo e($h['h']); ?>"<?php echo !empty($h['ext']) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><span style="font-size:1.3rem"><?php echo e($h['icon']); ?></span><span><span class="sr-k"><?php echo e($h['k']); ?></span><b><?php echo e($h['t']); ?></b><?php if ($h['s'] !== ''): ?><small><?php echo e($h['s']); ?></small><?php endif; ?></span></a><?php endforeach; ?>
<?php endif; ?>
</div></body></html>
