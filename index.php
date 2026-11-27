<?php
/**
 * Startseite als Landing Page: wer/was GhostNode ist, Projekte (VRC Nexus, VRChat-Poster, Hubs …), Neuigkeiten,
 * Community-Links und der Weg ins Dashboard. Das bisherige Kachel-Dashboard liegt unter dashboard.php.
 * Inhalte kommen aus dem Seitenregister (Titel, Icon, Beschreibung, Zugriff) – öffentliche Seiten für alle,
 * geschützte nur mit 🔒-Hinweis. Neuigkeiten = die letzten VRC-Nexus-Versionen von GitHub (1 Std. zwischengespeichert).
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';

auth_require_setup();
pages_sync();
$db = site_db();
$settings = site_settings();
$user = current_user();

const LANDING_LINKS = [
    'download' => 'https://github.com/ghostnode-labs/vrc_nexus/releases/latest',
    'github'   => 'https://github.com/ghostnode-labs/vrc_nexus',
    'discord'  => 'https://discord.gg/YaktdxVr2C',
    'patreon'  => 'https://www.patreon.com/cw/VRCNexusTools',
];

/** Letzte VRC-Nexus-Versionen (GitHub, öffentlich) – höchstens einmal pro Stunde abgefragt */
function landing_releases(): array {
    $file = DATA_DIR . '/cache/landing_releases.json';
    $cached = load_json($file, null);
    if (is_array($cached) && time() - (int)($cached['at'] ?? 0) < 3600) return $cached['items'] ?? [];
    $items = $cached['items'] ?? [];
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.github.com/repos/ghostnode-labs/vrc_nexus/releases?per_page=6');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: GhostNode-Landing']]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $list = $code === 200 ? json_decode((string)$body, true) : null;
        if (is_array($list)) {
            $items = [];
            foreach ($list as $r) {
                if (!empty($r['draft'])) continue;
                // Titel ohne NSFW-Inhalt: nur Version + öffentlicher Untertitel
                $items[] = ['tag' => (string)($r['tag_name'] ?? ''), 'name' => mb_substr(preg_replace('/\s+/', ' ', (string)($r['name'] ?? '')), 0, 120),
                            'url' => (string)($r['html_url'] ?? LANDING_LINKS['download']), 'date' => substr((string)($r['published_at'] ?? ''), 0, 10)];
                if (count($items) >= 4) break;
            }
        }
    }
    @save_json($file, ['at' => time(), 'items' => $items]);
    return $items;
}
$releases = landing_releases();
$latest = preg_replace('/^vrc-nexus-/', '', $releases[0]['tag'] ?? '');

// Seiten aus dem Register: Highlights + alle sichtbaren
$pages = [];
foreach ($db->query('SELECT * FROM pages ORDER BY sort, title')->fetchAll() as $p) {
    if (!page_visible_in_index($p, false)) continue;
    $p['ok'] = page_access_ok($p);
    $p['href'] = $p['ok'] ? page_href($p) : 'admin.php?next=' . urlencode(page_href($p));
    $pages[$p['slug']] = $p;
}
$HIGHLIGHT = ['poster.php', 'furhub.php', '2VRchat.php', 'furry.php', 'calendar.php', 'feed.php', 'countdown.php', '1linkseite.php', '3hueter-Zentrale.php'];
$highlights = array_values(array_filter(array_map(fn($s) => $pages[$s] ?? null, $HIGHLIGHT)));
$counts = ['pages' => count(array_filter($pages, fn($p) => $p['ok'])), 'posters' => 0, 'community' => 0];
try { require_once __DIR__ . '/inc/modules/posters.php'; $counts['posters'] = (int)ps_db()->query("SELECT COUNT(*) FROM slots WHERE enabled = 1")->fetchColumn(); } catch (Throwable $e) { /* ohne Poster-Datenbank */ }
try { require_once __DIR__ . '/inc/modules/nexusacct.php'; $counts['community'] = (int)nx_db()->query('SELECT COUNT(*) FROM nexus_accounts WHERE banned = 0')->fetchColumn(); } catch (Throwable $e) { /* ohne Nexus-Datenbank */ }
$title = in_array($settings['site_title'], ['', 'Startseite'], true) ? 'GhostNode' : $settings['site_title'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?> · VRC Nexus & VRChat-Werkzeuge</title>
    <meta name="description" content="<?php echo e($title); ?> – Heimat von VRC Nexus, der All-in-one-Begleit-App für VRChat (Windows, Android, Stream Deck), dazu VRChat-Poster, Link-Hubs, Furry Hub und mehr.">
    <meta property="og:title" content="<?php echo e($title); ?> · VRC Nexus">
    <meta property="og:description" content="Die All-in-one-Begleit-App für VRChat – Freunde, Verlauf, OSC, Chatbox, Overlay, Stream Deck. Kostenlos, alles bleibt auf deinem Gerät.">
    <meta property="og:image" content="assets/img/landing/nexus-home.jpg">
    <?php echo emoji_favicon('◈'); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <link rel="stylesheet" href="assets/css/landing.css">
</head>
<body class="ld">
<nav class="ld-top">
    <a href="index.php" class="ld-brand"><span>◈</span> GHOST<b>NODE</b></a>
    <div class="ld-nav">
        <a href="#nexus">VRC Nexus</a><a href="#projekte">Projekte</a><a href="#news">Neuigkeiten</a><a href="#community">Community</a>
    </div>
    <div class="ld-acc">
        <?php if ($user): ?>
            <a href="dashboard.php" class="btn btn-sm btn-gold">🧭 Dashboard</a>
            <?php if (can('admin.access')): ?><a href="admin.php" class="btn btn-sm">⚙</a><?php endif; ?>
        <?php else: ?>
            <a href="dashboard.php" class="btn btn-sm">🧭 Dashboard</a><a href="admin.php?next=dashboard.php" class="btn btn-sm btn-gold">🔒 Anmelden</a>
        <?php endif; ?>
    </div>
</nav>

<main id="main">
<header class="ld-hero">
    <div class="ld-hero-txt">
        <div class="ld-eyebrow">VRCHAT // TOOLS // COMMUNITY</div>
        <h1><?php echo e($title); ?></h1>
        <p class="ld-lead">Heimat von <b>VRC Nexus</b> – der All-in-one-Begleit-App für VRChat – und einer Sammlung von Werkzeugen rund um VRChat, Furry-Community und Creator.</p>
        <p class="ld-sub"><?php echo e($settings['site_tagline']); ?></p>
        <div class="ld-cta">
            <a class="btn btn-gold ld-big" href="<?php echo e(LANDING_LINKS['download']); ?>" target="_blank" rel="noopener">⬇ VRC Nexus herunterladen<?php echo $latest ? ' <small>' . e($latest) . '</small>' : ''; ?></a>
            <a class="btn ld-big" href="dashboard.php">🧭 Zum Dashboard</a>
            <a class="btn ld-big ld-discord" href="<?php echo e(LANDING_LINKS['discord']); ?>" target="_blank" rel="noopener">💬 Discord</a>
        </div>
        <div class="ld-badges"><span>🪟 Windows</span><span>🤖 Android</span><span>🎛 Stream Deck</span><span>🌍 DE · EN · FR · JA</span><span>🔒 Daten bleiben bei dir</span></div>
    </div>
    <div class="ld-hero-img"><?php echo img_pic('assets/img/landing/nexus-home.jpg', 'VRC Nexus – Startseite der App', 'eager', 'fetchpriority="high"'); ?></div>
</header>

<section class="ld-stats">
    <div><b><?php echo $latest ? e($latest) : '–'; ?></b><span>aktuelle Nexus-Version</span></div>
    <div><b><?php echo $counts['pages']; ?></b><span>Seiten & Werkzeuge</span></div>
    <?php if ($counts['community']): ?><div><b><?php echo $counts['community']; ?></b><span>Nexus-Community-Konten</span></div><?php endif; ?>
    <?php if ($counts['posters']): ?><div><b><?php echo $counts['posters']; ?></b><span>VRChat-Poster aktiv</span></div><?php endif; ?>
    <div><b>4</b><span>Sprachen</span></div>
</section>

<section class="ld-sec" id="nexus">
    <div class="ld-head"><span class="ld-eyebrow">PROJEKT</span><h2>🥽 VRC Nexus</h2><p>Deine Zentrale für VRChat – Freunde, Welten, Avatare, Gruppen, OSC, Chatbox, Overlay und vieles mehr in einer App. Kostenlos, ohne Konto bei uns: alles bleibt auf deinem Gerät.</p></div>
    <div class="ld-feat">
        <?php foreach ([
            ['👥', 'Freunde & Orte', 'Status, Welt, Notizen, Favoriten, „online seit“, Beitreten und Einladen mit einem Klick.'],
            ['📜', 'Verlauf & Statistik', 'Online/Offline, Weltwechsel, Spielzeit, Zeitachse, Heatmaps und Rückblick.'],
            ['🎮', 'Spiel-Log & OSC', 'Liest die VRChat-Logs und spricht per OSC/OSCQuery mit VRChat – parallel zu anderen OSC-Tools.'],
            ['💬', 'Chatbox-Module', 'Status, Musik, Uhrzeit, Puls, Rotator – mit Desktop- und VR-Modus.'],
            ['🎙', 'Sprache & Untertitel', 'Sprache → Chatbox (offline), Vorlesen, Live-Untertitel und Übersetzer.'],
            ['🥽', 'SteamVR-Overlay & HUD', 'Infos, Untertitel und Bedienung direkt in VR – passend zum gewählten Theme.'],
            ['🫂', 'Nexus Community', 'Profile, Feed, Ende-zu-Ende-verschlüsselter Chat und Sprachanrufe.'],
            ['🎛', 'Stream Deck & Stream-Bot', 'Tasten für Avatar, Instanz und Freunde; Bot für Twitch und YouTube.'],
            ['⌚', 'Nexus Watch & Handy', 'Wear-OS-Uhr: Puls vom Handgelenk, Schlafmodus, Themen. Das Handy steuert den PC direkt im WLAN.'],
        ] as [$ic, $t, $d]): ?>
            <div class="ld-card"><span class="ld-ic"><?php echo $ic; ?></span><b><?php echo e($t); ?></b><p><?php echo e($d); ?></p></div>
        <?php endforeach; ?>
    </div>
    <div class="ld-shots">
        <?php foreach ([['nexus-friends.jpg', 'Freunde & Community'], ['nexus-client.jpg', 'Spiel, OSC & Chatbox'], ['nexus-stats.jpg', 'Diagramme & Statistik'], ['nexus-tools.jpg', 'Werkzeuge'], ['nexus-groups.jpg', 'Gruppen'], ['nexus-osc.jpg', 'OSC-Verbindung']] as [$f, $cap]): ?>
            <figure><a href="assets/img/landing/<?php echo $f; ?>" target="_blank" rel="noopener"><?php echo img_pic('assets/img/landing/' . $f, $cap); ?></a><figcaption><?php echo e($cap); ?></figcaption></figure>
        <?php endforeach; ?>
    </div>
    <div class="ld-watch">
        <div class="ld-watch-head"><h3>⌚ Nexus Watch</h3><p>Die App für Wear-OS-Uhren (z. B. Galaxy Watch 4 und neuer, Pixel Watch): Puls direkt vom Uhr-Sensor an VRC Nexus – ohne HypeRate- oder Pulsoid-Konto –, Schlafmodus mit Wecker, sechs Themen, eigener Hintergrund aus der Galerie der Uhr und Updates von selbst. Verbindet sich im Heimnetz direkt mit der Windows-App, gekoppelt mit einem 6-stelligen Code.</p></div>
        <div class="ld-shots ld-shots-watch">
            <?php foreach ([['watch-menu.jpg', 'Hauptmenü, Puls & Schlafmodus'], ['watch-themes.jpg', 'Sechs Themen'], ['watch-pair.jpg', 'Koppeln & Einstellungen']] as [$f, $cap]): ?>
                <figure><a href="assets/img/landing/<?php echo $f; ?>" target="_blank" rel="noopener"><?php echo img_pic('assets/img/landing/' . $f, $cap); ?></a><figcaption><?php echo e($cap); ?></figcaption></figure>
            <?php endforeach; ?>
        </div>
    </div>
    <p class="ld-note">Screenshots mit Testdaten; Uhr-Ansichten nachgestellt (gleiche Oberfläche wie in der App). 🔒 Dein VRChat-Passwort wird nie gespeichert – nur das verschlüsselte Sitzungs-Cookie, alles lokal.</p>
    <div class="ld-cta center">
        <a class="btn btn-gold" href="<?php echo e(LANDING_LINKS['download']); ?>" target="_blank" rel="noopener">⬇ Neueste Version</a>
        <a class="btn" href="<?php echo e(LANDING_LINKS['github']); ?>" target="_blank" rel="noopener">📄 Alle Funktionen & Changelog</a>
        <?php if (isset($pages['nexus.php'])): ?><a class="btn" href="<?php echo e($pages['nexus.php']['href']); ?>">🌐 Nexus im Browser<?php echo $pages['nexus.php']['ok'] ? '' : ' 🔒'; ?></a><?php endif; ?>
    </div>
</section>

<section class="ld-sec" id="projekte">
    <div class="ld-head"><span class="ld-eyebrow">PROJEKTE & WERKZEUGE</span><h2>🧩 Was es hier noch gibt</h2><p>Werkzeuge und Sammlungen auf dieser Seite – vieles öffentlich, manches nur mit Konto.</p></div>
    <div class="ld-proj">
        <?php foreach ($highlights as $p): ?>
            <a class="ld-pcard<?php echo $p['ok'] ? '' : ' locked'; ?>" href="<?php echo e($p['href']); ?>">
                <span class="ld-ic"><?php echo e($p['icon'] ?: '📄'); ?></span>
                <span><b><?php echo e($p['title']); ?><?php echo $p['ok'] ? '' : ' 🔒'; ?></b><small><?php echo e(mb_strimwidth(strip_tags((string)$p['description']), 0, 140, '…')); ?></small></span>
                <i>→</i>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="ld-cta center"><a class="btn btn-gold" href="dashboard.php">🧭 Alle <?php echo $counts['pages']; ?> Seiten im Dashboard</a></div>
</section>

<section class="ld-sec" id="news">
    <div class="ld-head"><span class="ld-eyebrow">NEUIGKEITEN</span><h2>📰 Letzte Nexus-Versionen</h2></div>
    <?php if ($releases): ?>
        <div class="ld-news">
            <?php foreach ($releases as $i => $r): ?>
                <a class="ld-ncard<?php echo $i === 0 ? ' new' : ''; ?>" href="<?php echo e($r['url']); ?>" target="_blank" rel="noopener">
                    <span class="ld-tag"><?php echo e(preg_replace('/^vrc-nexus-/', '', $r['tag'])); ?></span>
                    <b><?php echo e($r['name'] ?: $r['tag']); ?></b>
                    <small><?php echo $r['date'] ? e(date('d.m.Y', strtotime($r['date']))) : ''; ?><?php echo $i === 0 ? ' · neu' : ''; ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="ld-note">Gerade keine Verbindung zu GitHub – alle Versionen gibt es unter <a href="<?php echo e(LANDING_LINKS['download']); ?>" target="_blank" rel="noopener">Releases</a>.</p>
    <?php endif; ?>
</section>

<section class="ld-sec" id="community">
    <div class="ld-head"><span class="ld-eyebrow">COMMUNITY</span><h2>💬 Mach mit</h2></div>
    <div class="ld-links">
        <a class="ld-lcard discord" href="<?php echo e(LANDING_LINKS['discord']); ?>" target="_blank" rel="noopener"><span>💬</span><b>Discord</b><small>Hilfe, Ideen, Updates und Community</small></a>
        <a class="ld-lcard patreon" href="<?php echo e(LANDING_LINKS['patreon']); ?>" target="_blank" rel="noopener"><span>❤️</span><b>Patreon</b><small>Die Entwicklung unterstützen</small></a>
        <a class="ld-lcard" href="<?php echo e(LANDING_LINKS['github']); ?>" target="_blank" rel="noopener"><span>🐙</span><b>GitHub</b><small>Downloads, Changelog, Fehler melden</small></a>
        <?php if (is_file(__DIR__ . '/Roadmap.php')): ?><a class="ld-lcard" href="Roadmap.php"><span>🗺</span><b>Roadmap</b><small>Was als Nächstes kommt</small></a><?php endif; ?>
    </div>
</section>

</main>
<footer class="ld-foot">
    <span>◈ <?php echo e($title); ?> · <?php echo date('Y'); ?></span>
    <span><a href="dashboard.php">Dashboard</a> · <a href="admin.php<?php echo $user ? '' : '?next=dashboard.php'; ?>"><?php echo $user ? 'Admin & Konto' : 'Anmelden'; ?></a> · <a href="<?php echo e(LANDING_LINKS['github']); ?>" target="_blank" rel="noopener">GitHub</a></span>
</footer>
<?php echo site_i18n_tags(); ?>
</body>
</html>
