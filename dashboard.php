<?php
/**
 * Dashboard (früher die Startseite dashboard.php – die ist jetzt eine Landing Page): alle Seiten nach Kategorien, Suche (Web + alle Links aller Hubs),
 * Bearbeiten-Modus (Seiten anlegen aus Vorlagen, sortieren, Kategorien, Zugriffsschutz).
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/countdown.php';
require_once __DIR__ . '/inc/modules/linkcheck.php';
require_once __DIR__ . '/inc/modules/calendar.php';
require_once __DIR__ . '/inc/modules/hub.php';
require_once __DIR__ . '/inc/core/templates.php';
require_once __DIR__ . '/inc/core/widgets.php';

auth_require_setup();
pages_sync();
$db = site_db();

// ---------- Änderungen am Index (Recht "pages.manage") ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    guard_post('pages.manage');
    $act = $_POST['action'] ?? '';
    $msg = '';
    $err = '';
    try {
        switch ($act) {
            case 'reorder':   // per fetch() aus dem Bearbeiten-Modus
                $order = json_decode((string)($_POST['order'] ?? ''), true);
                if (!is_array($order)) throw new InvalidArgumentException('Ungültige Reihenfolge.');
                $db->beginTransaction();
                $up = $db->prepare('UPDATE pages SET category_id = ?, sort = ? WHERE id = ?');
                foreach ($order as $group) {
                    $cat = (int)($group['cat'] ?? 0);
                    foreach (array_values((array)($group['pages'] ?? [])) as $i => $pid) $up->execute([$cat ?: null, ($i + 1) * 10, (int)$pid]);
                }
                $cup = $db->prepare('UPDATE page_categories SET sort = ? WHERE id = ?');
                foreach (array_values(array_filter(array_column($order, 'cat'))) as $i => $cid) $cup->execute([$i, (int)$cid]);
                $db->commit();
                audit('Index sortiert');
                header('Content-Type: application/json');
                exit(json_encode(['ok' => true]));

            case 'page_save':
                $id = (int)($_POST['id'] ?? 0);
                $existing = $id ? page_get_by_id($id) : null;
                if ($id && !$existing) throw new InvalidArgumentException('Seite nicht gefunden.');
                if ($existing) {
                    $f = page_fields_from_post($_POST, $existing);
                    $set = implode(', ', array_map(function ($k) { return "$k = :$k"; }, array_keys($f)));
                    $db->prepare("UPDATE pages SET $set, updated_at = datetime('now','localtime') WHERE id = :id")->execute($f + ['id' => $id]);
                    $msg = 'Seite „' . $f['title'] . '“ gespeichert.';
                    audit('Seite geändert', $f['title'] . ' · ' . PAGE_ACCESS[$f['access']]);
                } else {
                    $template = (string)($_POST['template'] ?? '');
                    if (!isset(PAGE_TEMPLATES[$template])) throw new InvalidArgumentException('Bitte eine Vorlage auswählen.');
                    $f = page_fields_from_post($_POST + ['template' => $template]);
                    $f['icon'] = $f['icon'] ?: PAGE_TEMPLATES[$template][0];
                    if (!$f['category_id']) $f['category_id'] = page_category_id('Eigene Seiten');   // nicht unter "Ohne Kategorie" verstecken
                    $f += ['slug' => page_slugify($f['title']), 'kind' => 'custom', 'template' => $template,
                           'content' => json_encode(page_template_start($template), JSON_UNESCAPED_UNICODE), 'created_by' => current_user()['id'],
                           'sort' => 1000];
                    $cols = array_keys($f);
                    $db->prepare('INSERT INTO pages (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')')->execute($f);
                    audit('Seite angelegt', $f['title'] . ' (' . PAGE_TEMPLATES[$template][1] . ')');
                    if ($template !== 'external') { header('Location: page.php?p=' . rawurlencode($f['slug']) . '&msg=' . urlencode('Seite angelegt – viel Spaß beim Befüllen!')); exit; }
                    $msg = 'Link-Kachel „' . $f['title'] . '“ angelegt.';
                }
                break;

            case 'page_delete':
                $p = page_get_by_id((int)($_POST['id'] ?? 0));
                if ($p && $p['kind'] === 'custom') {
                    $db->prepare('DELETE FROM pages WHERE id = ?')->execute([$p['id']]);
                    if ($p['template'] === 'links') @rename(hub_data_file('page_' . $p['slug']), data_path('archive/') . basename(hub_data_file('page_' . $p['slug']), '.json') . '_geloescht_' . date('Ymd_His') . '.json');
                    audit('Seite gelöscht', $p['title'], 'warn');
                    $msg = 'Seite „' . $p['title'] . '“ gelöscht.';
                }
                break;

            case 'category_save':
                $name = mb_substr(trim($_POST['name'] ?? ''), 0, 60);
                if ($name === '') throw new InvalidArgumentException('Bitte einen Namen eingeben.');
                $icon = mb_substr(trim($_POST['icon'] ?? ''), 0, 4);
                $cid = (int)($_POST['id'] ?? 0);
                $mo = !empty($_POST['members_only']) ? 1 : 0;
                page_cat_schema();
                if ($cid) $db->prepare('UPDATE page_categories SET name = ?, icon = ?, members_only = ? WHERE id = ?')->execute([$name, $icon, $mo, $cid]);
                else $db->prepare('INSERT INTO page_categories (name, icon, sort, members_only) VALUES (?, ?, ?, ?)')->execute([$name, $icon, (int)$db->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM page_categories')->fetchColumn(), $mo]);
                audit($cid ? 'Kategorie umbenannt' : 'Kategorie angelegt', $name);
                $msg = 'Kategorie „' . $name . '“ gespeichert.';
                break;

            case 'category_delete':
                $cid = (int)($_POST['id'] ?? 0);
                $db->prepare('DELETE FROM page_categories WHERE id = ?')->execute([$cid]);
                audit('Kategorie gelöscht', '#' . $cid, 'warn');
                $msg = 'Kategorie gelöscht – ihre Seiten stehen jetzt unter „Ohne Kategorie“.';
                break;
        }
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    }
    header('Location: dashboard.php?edit=1' . ($err ? '&error=' . urlencode($err) : ($msg ? '&msg=' . urlencode($msg) : '')));
    exit;
}

$can_manage = can('pages.manage');
$edit = $can_manage && !empty($_GET['edit']);
// Widgets haben ein eigenes Recht – wer nur das hat, bekommt einen reinen Widget-Bearbeiten-Modus (?widgets=1)
$wg_edit = widgets_can_arrange() && ($edit || !empty($_GET['widgets']));
$user = current_user();
$settings = site_settings();
$show_nsfw = $settings['show_nsfw'];
$roles = $db->query('SELECT id, name, color FROM roles ORDER BY id')->fetchAll();

// ---------- Live-Infos ----------

function live_info(array $p): string {
    try {
        switch ($p['slug']) {
            case 'con.php':
                if (!can('con.access')) return '';
                require_once __DIR__ . '/inc/modules/con.php';
                con_load();   // übernimmt ggf. das alte Format
                return con_live_info();
            case 'budget.php':
                $b = load_json(DATA_DIR . '/json/budget_data.json', null);
                if (!$b) return '';
                require_once __DIR__ . '/inc/modules/budget.php';
                $sm = budget_summary($b['entries'] ?? []);
                $over = 0;
                foreach (budget_settings()['limits'] as $c => $l) if (($sm['cats'][$c] ?? 0) > $l) $over++;
                return ($b['month_name'] ?? '') . ' · Netto ' . eur($sm['net']) . ' €' . ($sm['open'] > 0 ? ' · ' . eur($sm['open']) . ' € offen' : '') . ($over ? ' · ⚠ ' . $over . ' Limit' . ($over > 1 ? 's' : '') . ' überschritten' : '');
            case 'feed.php':
                $items = load_json(DATA_DIR . '/cache/feed_cache.json', []);
                return $items ? count($items) . ' Meldungen · Stand ' . date('H:i', filemtime(DATA_DIR . '/cache/feed_cache.json')) . ' Uhr' : '';
            case 'countdown.php':
                [$up] = sorted_countdowns(load_countdowns()['events']);
                return $up ? $up[0]['icon'] . ' ' . $up[0]['title'] . ' ' . relative_days($up[0]['ts']) : '';
            case 'linkcheck.php':
                $r = load_linkcheck();
                if (!$r['checked_at']) return 'noch nicht geprüft';
                $bad = count(array_filter($r['results'], function ($x) { return in_array($x['status'], ['dead', 'broken'], true); }));
                return ($bad ? "⚠ $bad kaputte Links" : '✓ alle Links erreichbar') . ' · ' . date('d.m.', (int)$r['checked_at']);
            case 'calendar.php':
                return cal_preview(can('calendar.private') || can('calendar.edit'));
            case 'admin.php':
                return is_logged_in() ? 'Angemeldet als ' . user_label() : '';
            case 'discord.php':
                if (!can('discord.manage')) return '';
                require_once __DIR__ . '/inc/modules/discord.php';
                if (!discord_configured()) return 'Bot noch nicht eingerichtet';
                $n = count(discord_scheduled());
                return '🤖 Bot verbunden' . ($n ? ' · ' . $n . ' geplante Nachricht(en)' : '');
            case 'nexus.php':
                if (!is_logged_in() || !can('vrcx.access')) return '';
                require_once __DIR__ . '/inc/modules/vrchat.php';
                return vrc_live_info((int)current_user()['id']);
        }
        if ($p['kind'] === 'custom') {
            if ($p['template'] === 'links') { $n = 0; foreach (hub_load('page_' . $p['slug'], []) as $l) $n += count($l); return $n . ' Links'; }
            if ($p['template'] === 'external') return parse_url($p['url'], PHP_URL_HOST) ?: '';
            return (string)tpl_call($p['template'], 'live', page_content_normalized($p));
        }
    } catch (Throwable $e) { /* Live-Info ist optional */ }
    return '';
}

/** Linkdaten einer Hub-Datei (liefert bei LINKS_ONLY nur ihr Array) */
function load_hub_links(string $path): ?array {
    if (strpos((string)file_get_contents($path), "if (defined('LINKS_ONLY')) return \$categories;") === false) return null;
    if (!defined('LINKS_ONLY')) define('LINKS_ONLY', true);
    $result = (function () use ($path) { return include $path; })();
    return is_array($result) ? $result : null;
}

// ---------- Seiten laden & gruppieren ----------
page_cat_schema();
$categories = page_categories();
$all_pages = $db->query('SELECT * FROM pages ORDER BY sort, title')->fetchAll();
$cat_members = [];
foreach ($categories as $c) if (!empty($c['members_only'])) $cat_members[(int)$c['id']] = true;
$groups = [];
foreach ($categories as $c) $groups[(int)$c['id']] = ['cat' => $c, 'pages' => []];
$groups[0] = ['cat' => ['id' => 0, 'name' => 'Ohne Kategorie', 'icon' => '📁'], 'pages' => []];
$global_links = [];
$seen = [];
$visible_count = 0;

foreach ($all_pages as $p) {
    $p['_cat_members'] = isset($cat_members[(int)$p['category_id']]);
    if (!page_visible_in_index($p, $edit)) continue;
    $accessible = page_access_ok($p);
    $p['locked'] = !$accessible;
    $p['href'] = $accessible ? page_href($p) : 'admin.php?next=' . urlencode(page_href($p));
    $p['live'] = ($p['show_live'] && $accessible) ? live_info($p) : '';
    $groups[(int)$p['category_id'] ?: 0]['pages'][] = $p;
    $visible_count++;

    // Links der Hubs für die Suche einsammeln (nur aus zugänglichen Seiten)
    if (!$accessible) continue;
    $hub = null;
    if ($p['kind'] === 'file' && substr($p['slug'], -4) === '.php') $hub = load_hub_links(__DIR__ . '/' . $p['slug']);
    if ($p['kind'] === 'custom' && $p['template'] === 'links') $hub = hub_load('page_' . $p['slug'], []);
    foreach ((array)$hub as $category => $links) {
        foreach ($links as $l) {
            if (!nsfw_visible($l, $category)) continue;
            $key = rtrim(strtolower($l['url']), '/');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $global_links[] = ['title' => $l['title'], 'url' => $l['url'], 'desc' => $l['desc'] ?? '', 'icon' => $l['icon'] ?? '🔗',
                               'category' => $category, 'hub' => $p['title'], 'nsfw' => is_nsfw_link($l, $category)];
        }
    }
}
if (!$groups[0]['pages']) unset($groups[0]);

/** Termine der nächsten 24 Stunden (für die "Bald"-Leiste) */
function soon_items(): array {
    try {
        $now = new DateTimeImmutable();
        $priv = can('calendar.private') || can('calendar.edit');
        return array_values(array_filter(cal_items($now->setTime(0, 0), $now->modify('+1 day'), $priv, ['events', 'countdowns']),
            function ($it) use ($now) {
                return empty($it['cancelled']) && $it['end'] >= $now && $it['start'] <= $now->modify('+24 hours') && (!$it['all_day'] || $it['start']->format('Y-m-d') === $now->format('Y-m-d'));
            }));
    } catch (Throwable $e) {
        return [];
    }
}
$cal_page = page_get('calendar.php');
$soon = ($cal_page && page_access_ok($cal_page)) ? soon_items() : [];

$access_icon = ['public' => '', 'users' => '🔑', 'members' => '🔒', 'roles' => '🛡️'];

// ---------- Widgets ----------
$wg_avail = widgets_available();
$wg_prefs = (!$wg_edit && $user) ? widgets_prefs() : ['order' => [], 'hidden' => [], 'min' => [], 'group' => ''];
$wg_layout = array_values(array_filter(widgets_layout(), function ($w) use ($wg_avail, $wg_edit) {
    $m = $wg_avail[$w['type']] ?? null;
    return $m ? widget_allowed($m) && ($wg_edit || widget_visible($w)) : $wg_edit;
}));
if (!$wg_edit) $wg_layout = widgets_apply_prefs($wg_layout, $wg_prefs);   // 9.0: persönliche Reihenfolge je Konto
$wg_assets = [];
foreach ($wg_layout as $w) if (isset($wg_avail[$w['type']]) && !in_array($w['uid'], $wg_prefs['hidden'], true)) $wg_assets[$w['type']] = $wg_avail[$w['type']];
$wg_groups = [];   // Gruppen, die tatsächlich vorkommen (Filter-Leiste)
foreach ($wg_layout as $w) { $g = widget_group($w['type'], $wg_avail[$w['type']] ?? null); $wg_groups[$g] = ($wg_groups[$g] ?? 0) + 1; }
$wg_groups = array_intersect_key(WIDGET_GROUPS, $wg_groups);

// ---------- 9.0: Kopfzeile (Begrüßung & Status) ----------
$hour = (int)date('G');
$greet = $hour < 5 ? 'Gute Nacht' : ($hour < 11 ? 'Guten Morgen' : ($hour < 17 ? 'Guten Tag' : ($hour < 22 ? 'Guten Abend' : 'Gute Nacht')));
$wd = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'][(int)date('w')];
$mo = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'][(int)date('n')];
$svc = null;
try { require_once __DIR__ . '/inc/modules/status.php'; $svc = st_summary(); if (!$svc['services']) $svc = null; } catch (Throwable $e) { $svc = null; }
$hero_pfp = '';
if ($user) { try { require_once __DIR__ . '/inc/modules/furhub.php'; $hero_pfp = (string)fh_avatar_img((int)$user['id'], 'ub-pfp'); } catch (Throwable $e) { $hero_pfp = ''; } }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($settings['site_title']); ?> // GHOST NODE</title>
    <?php echo emoji_favicon('🛰️'); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <link rel="stylesheet" href="assets/css/index.css">
    <link rel="stylesheet" href="assets/css/format.css">
    <link rel="stylesheet" href="assets/css/widgets.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <?php foreach ($wg_assets as $t => $m): if ($m['has_css']): ?><link rel="stylesheet" href="widgets/<?php echo e($t); ?>/widget.css?v=<?php echo (int)$m['mtime']; ?>">
    <?php endif; endforeach; ?>
</head>
<body class="dash <?php echo $edit || $wg_edit ? 'editing' : ''; ?>">
<?php site_banners(); ?>
<div class="wrapper idx dash-shell">
    <header class="dash-hero">
        <div class="dh-top">
            <div class="dh-id">
                <?php if ($user): ?>
                    <a href="me.php" class="dh-avatar<?php echo $hero_pfp ? ' has-pfp' : ''; ?>" style="--rc: <?php echo e($user['role_color']); ?>;" title="Mein Bereich"><?php echo $hero_pfp ?: e(mb_strtoupper(mb_substr(user_label($user), 0, 1))); ?></a>
                <?php else: ?><span class="dh-avatar">👋</span><?php endif; ?>
                <div class="dh-text">
                    <div class="hud-tag"><?php echo e($settings['site_title']); ?> // Dashboard</div>
                    <h1 id="dashGreet"><?php echo e($greet . ($user ? ', ' . user_label($user) : '')); ?></h1>
                    <p class="dh-sub"><?php echo e($wd . ', ' . date('j') . '. ' . $mo . ' ' . date('Y')); ?> · KW <?php echo (int)date('W'); ?> · <span id="liveClock">--:--:--</span>
                        <?php if ($user): ?> · <span class="dh-role" style="--rc: <?php echo e($user['role_color']); ?>;"><?php echo e($user['role_name']); ?></span><?php endif; ?></p>
                </div>
            </div>
            <div class="dh-actions">
                <?php if ($user): ?>
                    <?php if (!$wg_edit): ?><button type="button" class="dh-btn" id="wgCustomize" title="Widgets für dich sortieren und ausblenden (Taste A)">🎛 Anpassen</button><?php endif; ?>
                    <?php if ($can_manage): ?><a href="dashboard.php<?php echo $edit ? '' : '?edit=1'; ?>" class="dh-btn <?php echo $edit ? 'on' : ''; ?>" title="Index bearbeiten (Taste E)"><?php echo $edit ? '✓ Fertig' : '✎ Bearbeiten'; ?></a>
                    <?php elseif (widgets_can_arrange()): ?><a href="dashboard.php<?php echo $wg_edit ? '' : '?widgets=1'; ?>" class="dh-btn <?php echo $wg_edit ? 'on' : ''; ?>" title="Widgets anordnen"><?php echo $wg_edit ? '✓ Fertig' : '🧩 Widgets'; ?></a><?php endif; ?>
                    <button type="button" class="dh-btn <?php echo widgets_mine() ? 'on' : ''; ?>" id="wgMine" data-on="<?php echo widgets_mine() ? '1' : '0'; ?>" title="<?php echo widgets_mine() ? 'Eigenes Dashboard aus – wieder die gemeinsame Anordnung zeigen' : 'Eigenes Dashboard: Widgets nur für dich zusammenstellen (Baukasten)'; ?>">🧱 <?php echo widgets_mine() ? 'Mein Dashboard' : 'Baukasten'; ?></button>
                    <button type="button" class="dh-btn" id="cmdOpen" title="Befehlspalette (Strg+K)">⌘ <kbd>Strg K</kbd></button>
                    <?php if (can('settings.nsfw')): ?>
                        <form method="POST" action="admin.php" class="nsfw-form dh-nsfw">
                            <input type="hidden" name="action" value="toggle_nsfw"><input type="hidden" name="back" value="dashboard.php">
                            <span class="nsfw-label">NSFW</span>
                            <button type="submit" class="mini-switch <?php echo $show_nsfw ? 'on' : ''; ?>" role="switch" aria-checked="<?php echo $show_nsfw ? 'true' : 'false'; ?>" title="NSFW-Links für Besucher <?php echo $show_nsfw ? 'ausblenden' : 'anzeigen'; ?>"></button>
                        </form>
                    <?php endif; ?>
                    <?php if (can('admin.access')): ?><a href="admin.php" class="dh-btn ic-only" title="Admin-Zentrale">⚙</a><?php endif; ?>
                    <form method="POST" action="admin.php" class="dh-form"><input type="hidden" name="action" value="logout"><button type="submit" class="dh-btn ic-only danger" title="Abmelden">⏻</button></form>
                <?php else: ?>
                    <button type="button" class="dh-btn" id="cmdOpen" title="Befehlspalette (Strg+K)">⌘ <kbd>Strg K</kbd></button>
                    <a href="admin.php?next=dashboard.php" class="dh-btn gold">🔒 Anmelden</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="dh-status">
            <span class="dh-chip <?php echo $settings['maintenance'] ? 'warn' : 'ok'; ?>"><i></i><?php echo $settings['maintenance'] ? 'Wartung' : 'Online'; ?></span>
            <span class="dh-chip"><b><?php echo $visible_count; ?></b> Seiten</span>
            <span class="dh-chip"><b><?php echo count($global_links); ?></b> Links</span>
            <?php if ($svc): ?><a class="dh-chip <?php echo $svc['ok'] ? 'ok' : 'bad'; ?>" href="status.php" title="Status aller Dienste"><i></i><?php echo $svc['ok'] ? 'Alle Dienste ok' : $svc['down'] . ' Dienst' . ($svc['down'] > 1 ? 'e' : '') . ' gestört'; ?></a><?php endif; ?>
            <?php if ($soon): ?>
                <a href="calendar.php?view=agenda" class="dh-chip soon" title="Termine der nächsten 24 Stunden">📅
                    <?php foreach (array_slice($soon, 0, 3) as $i => $it): ?><?php echo $i ? ' · ' : ''; ?><b><?php echo $it['all_day'] ? 'heute' : ($it['start'] <= new DateTimeImmutable() ? 'jetzt' : e($it['start']->format('H:i'))); ?></b> <?php echo e(($it['icon'] ?? '') . ' ' . $it['title']); ?><?php endforeach; ?>
                    <?php if (count($soon) > 3): ?> <em>+<?php echo count($soon) - 3; ?></em><?php endif; ?>
                </a>
            <?php endif; ?>
        </div>

        <form id="searchForm" action="https://www.google.com/search" method="GET" class="search-form dh-search" role="search">
            <div class="search-controls">
                <select id="search-engine" aria-label="Suchmaschine">
                    <option value="google" selected>Google</option>
                    <option value="duckduckgo">DuckDuckGo</option>
                    <option value="ecosia">Ecosia</option>
                    <option value="bing">Bing</option>
                    <option value="internal">// Lokal</option>
                </select>
                <input type="search" id="searchInput" name="q" class="search-input" placeholder="Suchbegriff eingeben..." autocomplete="off" aria-label="Suchbegriff">
                <button type="submit" id="searchBtn" class="search-btn">Suchen</button>
            </div>
            <div class="search-hint" id="searchHint"></div>
        </form>
    </header>

    <?php if ($settings['maintenance']): ?><div class="maint-bar">🛠️ Wartungsmodus ist aktiv – Besucher sehen nur den Wartungshinweis.</div><?php endif; ?>
    <?php if (!empty($_GET['msg'])): ?><div class="idx-flash ok"><?php echo e($_GET['msg']); ?></div><?php endif; ?>
    <?php if (!empty($_GET['error'])): ?><div class="idx-flash err"><?php echo e($_GET['error']); ?></div><?php endif; ?>

    <?php if ($edit): ?>
        <div class="edit-bar">
            <span>✎ Bearbeiten-Modus · Kacheln ziehen zum Sortieren · <span id="saveState">alles gespeichert</span></span>
            <span>
                <button type="button" class="btn btn-sm btn-gold" data-open="newPage">＋ Neue Seite</button>
                <button type="button" class="btn btn-sm" data-cat-edit="new">＋ Kategorie</button>
                <a href="dashboard.php" class="btn btn-sm">✓ Fertig</a>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($wg_layout || $wg_edit): ?>
        <section class="wg-area" id="wgArea" data-group="<?php echo e($wg_prefs['group']); ?>">
            <div class="wg-toolbar">
                <h2>🧩 Widgets<?php if (widgets_mine()): ?> <small class="wg-mine-tag">Mein Dashboard</small><?php endif; ?></h2>
                <?php if (count($wg_groups) > 1 && !$wg_edit): ?>
                    <nav class="wg-groups" id="wgGroups" aria-label="Widget-Gruppen">
                        <button type="button" data-g="" class="<?php echo $wg_prefs['group'] === '' ? 'on' : ''; ?>">Alle</button>
                        <?php foreach ($wg_groups as $g => [$gi, $gl]): ?><button type="button" data-g="<?php echo e($g); ?>" class="<?php echo $wg_prefs['group'] === $g ? 'on' : ''; ?>"><?php echo $gi . ' ' . e($gl); ?></button><?php endforeach; ?>
                    </nav>
                <?php endif; ?>
                <span class="wg-tb-right">
                    <?php if ($wg_edit): ?><button type="button" class="btn btn-sm btn-gold" id="wgAdd">＋ Widget</button>
                    <?php else: ?><button type="button" class="wg-link" id="wgToggle">▾ ausblenden</button><?php endif; ?>
                </span>
            </div>
            <?php if (!$wg_edit && $user): ?>
                <div class="wg-tray" id="wgTray" hidden>
                    <div class="wg-tray-head"><b>🎛 Anpassen</b> <span>Widgets am Griff ⠿ ziehen, mit 👁 ausblenden. Wird in deinem Konto gespeichert – auf jedem Gerät.</span></div>
                    <div class="wg-tray-list" id="wgTrayList"></div>
                    <div class="wg-tray-actions">
                        <a class="btn btn-sm" href="widgets.php?act=prefs_export">⬇ Exportieren</a>
                        <label class="btn btn-sm">⬆ Importieren<input type="file" id="wgImport" accept="application/json,.json" hidden></label>
                        <button type="button" class="btn btn-sm" id="wgReset">↺ Zurücksetzen</button>
                        <button type="button" class="btn btn-sm btn-gold" id="wgDone">✓ Fertig</button>
                    </div>
                </div>
            <?php endif; ?>
            <div class="wg-grid" id="wgGrid">
                <?php foreach ($wg_layout as $w) echo widget_card($w, $wg_edit, ['hidden' => in_array($w['uid'], $wg_prefs['hidden'], true), 'min' => in_array($w['uid'], $wg_prefs['min'], true)]); ?>
                <?php if (!$wg_layout): ?><div class="wg-empty-grid">Noch keine Widgets – „＋ Widget“ klicken.</div><?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="idx-quick" id="idxQuick" hidden></div>
    <div id="idxMine"></div>

    <div id="noResults" class="empty-msg" hidden>Keine lokalen Treffer – <kbd>Enter</kbd> sucht im Web.</div>

    <div id="sections">
        <?php foreach ($groups as $cid => $g): if (!$g['pages'] && !$edit) continue; ?>
            <section class="cat-section" data-cat="<?php echo (int)$cid; ?>">
                <div class="cat-head">
                    <h2><span class="cat-icon"><?php echo e($g['cat']['icon'] ?: '📁'); ?></span><?php echo e($g['cat']['name']); ?> <small><?php echo count($g['pages']); ?></small></h2>
                    <?php if ($edit && $cid): ?>
                        <span class="cat-tools">
                            <button type="button" class="ic" data-move-cat="up" title="Nach oben">▲</button>
                            <button type="button" class="ic" data-move-cat="down" title="Nach unten">▼</button>
                            <button type="button" class="ic" data-cat-edit="<?php echo e(json_encode(['id' => $cid, 'name' => $g['cat']['name'], 'icon' => $g['cat']['icon'], 'members_only' => (int)($g['cat']['members_only'] ?? 0)])); ?>" title="Bearbeiten">✎</button>
                            <form method="POST" onsubmit="return confirm('Kategorie löschen? Ihre Seiten bleiben erhalten (ohne Kategorie).');"><input type="hidden" name="action" value="category_delete"><input type="hidden" name="id" value="<?php echo (int)$cid; ?>"><button class="ic danger" title="Kategorie löschen">✕</button></form>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="cards" data-cat="<?php echo (int)$cid; ?>">
                    <?php foreach ($g['pages'] as $p):
                        $external = $p['kind'] === 'custom' && $p['template'] === 'external' && !$p['locked']; ?>
                        <div class="tile-wrap" data-id="<?php echo (int)$p['id']; ?>" <?php echo $edit ? 'draggable="true"' : ''; ?> id="page-<?php echo (int)$p['id']; ?>">
                            <a href="<?php echo e($p['href']); ?>" class="tile search-item node-item <?php echo $p['locked'] ? 'locked' : ''; ?> <?php echo $p['hidden'] ? 'is-hidden' : ''; ?>"
                               <?php echo $external ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
                               data-search-text="<?php echo e(mb_strtolower($p['title'] . ' ' . $p['description'] . ' ' . $g['cat']['name'] . ' ' . $p['live'], 'UTF-8')); ?>">
                                <span class="tile-icon"><?php echo e($p['icon'] ?: '📄'); ?></span>
                                <span class="tile-body">
                                    <span class="tile-title"><?php echo e($p['title']); ?>
                                        <?php if ($access_icon[$p['access']]): ?><span class="badge-acc" title="<?php echo e(PAGE_ACCESS[$p['access']]); ?>"><?php echo $access_icon[$p['access']]; ?></span><?php endif; ?>
                                        <?php if ($p['hidden']): ?><span class="badge-hid">versteckt</span><?php endif; ?>
                                    </span>
                                    <?php if ($p['description'] !== ''): ?><span class="tile-desc fmt-inline"><?php echo format_inline($p['description'], ['links' => false]); ?></span><?php endif; ?>
                                    <?php if ($p['locked']): ?><span class="tile-live">🔒 Anmelden zum Öffnen</span>
                                    <?php elseif ($p['live'] !== ''): ?><span class="tile-live"><?php echo e($p['live']); ?></span><?php endif; ?>
                                </span>
                                <span class="tile-arrow"><?php echo $external ? '↗' : '→'; ?></span>
                            </a>
                            <?php if ($edit): ?>
                                <span class="tile-tools">
                                    <span class="drag" title="Ziehen zum Sortieren">⠿</span>
                                    <button type="button" class="ic" title="Bearbeiten" data-page-edit="<?php echo e(json_encode(array_intersect_key($p, array_flip(['id', 'kind', 'template', 'title', 'description', 'icon', 'category_id', 'access', 'allowed_roles', 'hidden', 'show_live', 'url', 'slug'])))); ?>">✎</button>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($edit && !$g['pages']): ?><div class="drop-hint">Kacheln hierher ziehen</div><?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <!-- Alle Links aus allen Hubs – nur bei der Suche sichtbar -->
    <section class="cat-section" id="linkResults" hidden>
        <div class="cat-head"><h2><span class="cat-icon">🔎</span>Links aus den Hubs <small id="linkCount"></small></h2></div>
        <div class="link-list">
            <?php foreach ($global_links as $l): ?>
                <a href="<?php echo e($l['url']); ?>" target="_blank" rel="noopener noreferrer" class="sub-item search-item" hidden
                   data-search-text="<?php echo e(mb_strtolower($l['title'] . ' ' . $l['desc'] . ' ' . $l['category'] . ' ' . $l['hub'] . ' ' . $l['url'], 'UTF-8')); ?>">
                    <span class="tile-icon sm"><?php echo e($l['icon']); ?></span>
                    <span class="tile-body">
                        <span class="tile-title"><?php echo e($l['title']); ?><?php if ($l['nsfw']): ?> <span class="nsfw-tag">18+</span><?php endif; ?></span>
                        <span class="tile-desc"><?php echo e($l['hub'] . ' · ' . $l['category']); ?><?php echo $l['desc'] !== '' ? ' — ' . format_inline($l['desc'], ['links' => false]) : ''; ?></span>
                    </span>
                    <span class="tile-arrow">↗</span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<?php if ($wg_edit): ?>
<!-- Widgets -->
<div class="modal-overlay" id="wgModal" role="dialog" aria-modal="true">
    <div class="modal-box"><button type="button" class="modal-close" data-close aria-label="Schließen">&times;</button><div id="wgModalBody"></div></div>
</div>
<?php endif; ?>
<?php if ($edit): ?>
<!-- Seite anlegen / bearbeiten -->
<div class="modal-overlay" id="pageModal" role="dialog" aria-modal="true">
    <form method="POST" class="modal-box page-form" id="pageForm">
        <button type="button" class="modal-close" data-close aria-label="Schließen">&times;</button>
        <div class="panel-title" id="pageFormTitle">// Neue Seite</div>
        <input type="hidden" name="action" value="page_save"><input type="hidden" name="id" value="">

        <div id="tplPick">
            <label class="field">Vorlage</label>
            <div class="tpl-grid">
                <?php foreach (PAGE_TEMPLATES as $key => [$ic, $name, $desc]): ?>
                    <label class="tpl"><input type="radio" name="template" value="<?php echo $key; ?>" <?php echo $key === 'links' ? 'checked' : ''; ?>>
                        <span><b><?php echo $ic; ?> <?php echo e($name); ?></b><small><?php echo e($desc); ?></small></span></label>
                <?php endforeach; ?>
            </div>
        </div>
        <p class="side-note" id="fileNote" hidden>Mitgelieferte Seite – Titel, Beschreibung, Icon, Kategorie und Zugriff lassen sich ändern.</p>

        <div class="f2">
            <div><label class="field">Titel *</label><input type="text" name="title" maxlength="80" required placeholder="z. B. Con-Packliste"></div>
            <div><label class="field">Icon</label><input type="text" name="icon" maxlength="4" placeholder="🎒"></div>
        </div>
        <label class="field">Beschreibung (wird im Index angezeigt)</label>
        <input type="text" name="description" maxlength="240" placeholder="Kurz: Was findet man hier? (**fett**, [color=gold]…[/color] möglich)" data-format="inline">
        <div id="urlRow" hidden><label class="field">Adresse *</label><input type="url" name="url" maxlength="500" placeholder="https://…"></div>

        <div class="f2 even">
            <div><label class="field">Kategorie</label>
                <select name="category_id"><option value="0">– ohne Kategorie –</option><?php foreach ($categories as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo e(($c['icon'] ? $c['icon'] . ' ' : '') . $c['name']); ?></option><?php endforeach; ?></select></div>
            <div><label class="field">Zugriff</label>
                <select name="access" id="accessSel"><?php foreach (PAGE_ACCESS as $k => $l): ?><option value="<?php echo $k; ?>"><?php echo $l; ?></option><?php endforeach; ?></select></div>
        </div>
        <div id="rolesRow" class="roles-pick" hidden>
            <label class="field">Erlaubte Rollen (Administratoren immer)</label>
            <?php foreach ($roles as $r): ?>
                <label class="role-chip" style="--rc: <?php echo e($r['color']); ?>;"><input type="checkbox" name="roles[]" value="<?php echo (int)$r['id']; ?>"> <?php echo e($r['name']); ?></label>
            <?php endforeach; ?>
        </div>
        <label class="toggle-line"><input type="checkbox" name="show_live" value="1" checked> Live-Infos in der Kachel anzeigen (z. B. nächster Termin, Linkanzahl)</label>
        <label class="toggle-line"><input type="checkbox" name="hidden" value="1"> Im Index verstecken (Seite bleibt über ihre Adresse erreichbar)</label>

        <div class="form-actions">
            <button type="submit" class="btn btn-gold">Speichern</button>
            <button type="submit" class="btn btn-danger" id="pageDelete" name="action" value="page_delete" formnovalidate hidden onclick="return confirm('Seite und ihren Inhalt wirklich löschen?');">Seite löschen</button>
        </div>
    </form>
</div>


<!-- Kategorie -->
<div class="modal-overlay" id="catModal" role="dialog" aria-modal="true">
    <form method="POST" class="modal-box" id="catForm" style="max-width: 420px;">
        <button type="button" class="modal-close" data-close aria-label="Schließen">&times;</button>
        <div class="panel-title" id="catFormTitle">// Neue Kategorie</div>
        <input type="hidden" name="action" value="category_save"><input type="hidden" name="id" value="">
        <div class="f2">
            <div><label class="field">Name *</label><input type="text" name="name" maxlength="60" required></div>
            <div><label class="field">Icon</label><input type="text" name="icon" maxlength="4" placeholder="📁"></div>
        </div>
        <label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-size:.85rem"><input type="checkbox" name="members_only" value="1"> 🔒 Nur für Angemeldete – Gäste sehen diese Kategorie gar nicht</label>
        <button type="submit" class="btn btn-gold" style="width: 100%; margin-top: 14px;">Speichern</button>
    </form>
</div>
<?php endif; ?>

<?php
// 9.0: Befehlspalette (Strg+K) – Seiten, die die Person öffnen darf, plus Aktionen
$cmd = [];
foreach ($groups as $g) foreach ($g['pages'] as $p) if (!$p['locked']) $cmd[] = ['i' => $p['icon'] ?: '📄', 't' => $p['title'], 's' => $g['cat']['name'], 'h' => $p['href']];
foreach ([['🔍', 'Globale Suche', 'search.php', null], ['⬇', 'Downloads', 'downloads.php', null], ['📜', 'Changelog', 'changelog.php', null], ['🩺', 'Status der Dienste', 'status.php', null],
          ['👤', 'Mein Bereich', 'me.php', 'login'], ['🔔', 'Benachrichtigungen', 'me.php?tab=notes', 'login'], ['🔐', 'Mein Konto (Passwort, 2FA, Geräte)', 'admin.php?tab=account', 'login'],
          ['👥', 'Admin: Benutzer', 'admin.php?tab=users', 'users.view'], ['📋', 'Admin: Protokoll', 'admin.php?tab=audit', 'audit.view'], ['🛡', 'Admin: Sicherheit', 'admin.php?tab=security', 'security.manage'],
          ['🖥', 'Admin: System', 'admin.php?tab=system', 'system.manage'], ['🗓', 'Admin: Wartungsfenster', 'admin.php?tab=system#maint-plan', 'system.manage']] as [$i, $t, $h, $need]) {
    if ($need === 'login' ? !$user : ($need !== null && !can($need))) continue;
    $cmd[] = ['i' => $i, 't' => $t, 's' => 'Gehe zu', 'h' => $h];
}
?>
<script>window.IDX = { user: <?php echo $user ? json_encode(['id' => (int)$user['id'], 'name' => user_label($user)]) : 'null'; ?>, edit: <?php echo $edit ? 'true' : 'false'; ?>, wgEdit: <?php echo $wg_edit ? 'true' : 'false'; ?>, canManage: <?php echo $can_manage ? 'true' : 'false'; ?>,
    prefs: <?php echo json_encode($wg_prefs, JSON_UNESCAPED_SLASHES); ?>, cmd: <?php echo json_encode($cmd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?> };</script>
<script src="assets/js/index.js"></script>
<script src="assets/js/dash-plus.js"></script>
<script src="assets/js/widgets.js"></script>
<script src="assets/js/dash-ux.js"></script>
<?php foreach ($wg_assets as $t => $m): if ($m['has_js']): ?><script src="widgets/<?php echo e($t); ?>/widget.js?v=<?php echo (int)$m['mtime']; ?>"></script>
<?php endif; endforeach; ?>
<script src="assets/js/gender.js"></script>
<?php if (can('pages.manage') || can('pages.edit')): ?><script src="assets/js/editor.js"></script><?php endif; ?>
<script src="assets/js/reminders.js" data-endpoint="calendar.php?reminders=1"></script>
</body>
</html>
