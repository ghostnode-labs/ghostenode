<?php
/**
 * Startseite als Landing Page (allgemein: Software & Programmierung): eigene Projekte mit Technik und Versionen,
 * Arbeitsweise, Neuigkeiten aller Repositories, Werkzeuge der Seite, Community-Links und der Weg ins Dashboard. Das bisherige Kachel-Dashboard liegt unter dashboard.php.
 * Inhalte kommen aus dem Seitenregister (Titel, Icon, Beschreibung, Zugriff) – öffentliche Seiten für alle,
 * geschützte nur mit 🔒-Hinweis. Neuigkeiten = die letzten Versionen aller Projekte von GitHub (1 Std. zwischengespeichert).
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';

auth_require_setup();
pages_sync();
$db = site_db();
$settings = site_settings();
$user = current_user();

const LANDING_LINKS = [
    'github'   => 'https://github.com/ghostnode-labs',
    'download' => 'https://github.com/ghostnode-labs/vrc_nexus/releases/latest',
    'discord'  => 'https://discord.gg/YaktdxVr2C',
    'patreon'  => 'https://www.patreon.com/cw/VRCNexusTools',
];
/** Repositories mit Releases für „Neuigkeiten“: Repo => [Name, Tag-Präfix] */
const LANDING_REPOS = [
    'ghostnode-labs/vrc_nexus'    => ['VRC Nexus', 'vrc-nexus-v'],
    'ghostnode-labs/d2_nexus'     => ['D2 Nexus', 'v'],
    'ghostnode-labs/node_discord' => ['GhostNode-Bot', 'v'],
];

/** Letzte Versionen aller Projekte (GitHub, öffentlich) – höchstens einmal pro Stunde abgefragt */
function landing_releases(): array {
    $file = DATA_DIR . '/cache/landing_releases_all.json';
    $cached = load_json($file, null);
    if (is_array($cached) && time() - (int)($cached['at'] ?? 0) < 3600) return $cached['items'] ?? [];
    $items = [];
    $old = is_array($cached) ? ($cached['items'] ?? []) : [];
    foreach (LANDING_REPOS as $repo => [$label, $prefix]) {
        if (!function_exists('curl_init')) break;
        $ch = curl_init('https://api.github.com/repos/' . $repo . '/releases?per_page=4');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: GhostNode-Landing']]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $list = $code === 200 ? json_decode((string)$body, true) : null;
        if (!is_array($list)) { foreach ($old as $o) if (($o['repo'] ?? '') === $repo) $items[] = $o; continue; }   // GitHub weg → alter Stand
        $n = 0;
        foreach ($list as $r) {
            $tag = (string)($r['tag_name'] ?? '');
            if (!empty($r['draft']) || !empty($r['prerelease']) || !str_starts_with($tag, $prefix)) continue;
            // Titel ohne NSFW-Inhalt: nur Projekt + Version
            $items[] = ['repo' => $repo, 'project' => $label, 'ver' => substr($tag, strlen($prefix)), 'url' => (string)($r['html_url'] ?? ''), 'date' => substr((string)($r['published_at'] ?? ''), 0, 10)];
            if (++$n >= 2) break;
        }
    }
    usort($items, fn($a, $b) => strcmp($b['date'], $a['date']));
    $items = array_slice($items, 0, 8);
    @save_json($file, ['at' => time(), 'items' => $items]);
    return $items;
}
/** Eigene Projekte: Name, Icon, Beschreibung, Technik, Links, Versions-Quelle (Projektname in LANDING_REPOS) */
const LANDING_PROJECTS = [
    ['name' => 'VRC Nexus', 'icon' => '🥽', 'ver' => 'VRC Nexus', 'desc' => 'All-in-one-Begleiter für VRChat: Freunde, Verlauf, OSC/OSCQuery, Chatbox, SteamVR-Overlay, Stream Deck, Community mit Ende-zu-Ende-Verschlüsselung.',
     'tech' => ['Electron', 'Node.js', 'SQLite', 'OSC', 'C# Overlay'], 'links' => [['Download', 'https://github.com/ghostnode-labs/vrc_nexus/releases/latest'], ['Changelog', 'changelog.php']]],
    ['name' => 'D2 Nexus', 'icon' => '💠', 'ver' => 'D2 Nexus', 'desc' => 'Destiny-2-Begleiter: Inventar & Tresor, Loadouts, Rüstungs-Optimierer, Raids, Triumphe, Händler und Hüter-Karte – über die offizielle Bungie-API.',
     'tech' => ['Electron', 'Bungie API', 'OAuth'], 'links' => [['Download', 'https://github.com/ghostnode-labs/D2_Nexus/releases/latest'], ['Im Web', 'd2nexus.php']]],
    ['name' => 'Nexus für Android & Wear OS', 'icon' => '⌚', 'ver' => '', 'desc' => 'Handy-App und Uhr-App: Puls vom Handgelenk, Schlafmodus, Fernsteuerung des PCs im WLAN, Updates von selbst.',
     'tech' => ['Kotlin', 'Wear OS', 'WebView'], 'links' => [['Downloads', 'downloads.php']]],
    ['name' => 'GhostNode-Bot', 'icon' => '🤖', 'ver' => 'GhostNode-Bot', 'desc' => 'Discord-Bot ohne Pakete: Begrüßung, Rollen, Level, AutoMod, eigene Befehle und Web-Dashboard mit Anmeldung über das GhostNode-Konto.',
     'tech' => ['Node.js', 'Discord Gateway', 'WebSocket'], 'links' => [['Dashboard', 'botdash.php']]],
    ['name' => 'Nexus Live & HypeRate-Relay', 'icon' => '⚡', 'ver' => '', 'desc' => 'Echtzeit-Server für Fernsteuerung, Puls und Benachrichtigungen – eigener WebSocket-Server nach RFC 6455, als systemd-Dienst.',
     'tech' => ['Node.js', 'WebSocket', 'systemd'], 'links' => []],
    ['name' => 'Web-Plattform & Dashboard', 'icon' => '🌐', 'ver' => '', 'desc' => 'Diese Seite: Konten mit Rollen und 2FA, Admin-Zentrale, Web-Nexus, Plugin-Marktplatz, VRChat-Poster, API, Kalender, Budget und mehr.',
     'tech' => ['PHP 8', 'SQLite / MySQL', 'Web Push'], 'links' => [['Dashboard', 'dashboard.php'], ['API', 'api.php']]],
    ['name' => 'Root-Server-Installer', 'icon' => '🐧', 'ver' => '', 'desc' => 'Ein Befehl für den ganzen Server: nginx als Reverse Proxy, MariaDB, Zertifikate, Dienste, Cron, Wächter, Backups, Auto-Update und Server-Verwaltung im Admin.',
     'tech' => ['Bash', 'Python', 'nginx', 'systemd'], 'links' => [['GitHub', 'https://github.com/ghostnode-labs']]],
    ['name' => 'Stream Deck, Twitch & Unity', 'icon' => '🎛', 'ver' => '', 'desc' => 'Stream-Deck-Plugin, Twitch-Panel-Erweiterung und Unity-Editor-Werkzeuge für VRChat-Creator – alles an VRC Nexus angebunden.',
     'tech' => ['JavaScript', 'Twitch Ext.', 'C# / Unity'], 'links' => [['Downloads', 'downloads.php']]],
    ['name' => 'Nexus Pet', 'icon' => '🐾', 'ver' => '', 'desc' => 'Ein virtuelles Haustier für Android: füttern, spielen, putzen, 13 Entwicklungsformen und ein Widget – ohne Konto und ohne Internet.',
     'tech' => ['Kotlin', 'Android'], 'links' => [['Downloads', 'downloads.php']]],
];
$releases = landing_releases();
$latestOf = function (string $project) use ($releases) { foreach ($releases as $r) if ($r['project'] === $project) return $r['ver']; return ''; };

// Seiten aus dem Register: Highlights + alle sichtbaren
$pages = [];
foreach ($db->query('SELECT * FROM pages ORDER BY sort, title')->fetchAll() as $p) {
    if (!page_visible_in_index($p, false)) continue;
    $p['ok'] = page_access_ok($p);
    $p['href'] = $p['ok'] ? page_href($p) : 'admin.php?next=' . urlencode(page_href($p));
    $pages[$p['slug']] = $p;
}
$HIGHLIGHT = ['api.php', 'd2nexus.php', 'botdash.php', 'poster.php', 'market.php', 'downloads.php', 'feed.php', '1linkseite.php', '2VRchat.php'];
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
    <title><?php echo e($title); ?> · Software & Programmierung</title>
    <meta name="description" content="<?php echo e($title); ?> – eigene Software: Desktop- und Handy-Apps (VRC Nexus, D2 Nexus), Discord-Bot, Echtzeit-Server, Web-Plattform und Linux-Automatisierung. Selbst programmiert, ohne fremde Pakete, mit Tests und Auto-Updates.">
    <meta property="og:title" content="<?php echo e($title); ?> · Software & Programmierung">
    <meta property="og:description" content="Apps, Bots, Server und Web – selbst programmiert. Node.js, Electron, PHP, Kotlin, C#, Python und Linux.">
    <meta property="og:image" content="assets/img/landing/nexus-home.jpg">
    <?php echo emoji_favicon('◈'); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <link rel="stylesheet" href="assets/css/landing.css">
</head>
<body class="ld">
<nav class="ld-top">
    <a href="index.php" class="ld-brand"><span>◈</span> GHOST<b>NODE</b></a>
    <div class="ld-nav">
        <a href="#projekte">Projekte</a><a href="#technik">Technik</a><a href="#news">Neuigkeiten</a><a href="#werkzeuge">Werkzeuge</a><a href="#community">Community</a>
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
        <div class="ld-eyebrow">SOFTWARE // PROGRAMMIERUNG // OPEN RELEASES</div>
        <h1><?php echo e($title); ?></h1>
        <p class="ld-lead">Wir programmieren eigene Software – Desktop- und Handy-Apps, Discord-Bots, Echtzeit-Server, Web-Plattformen und Server-Automatisierung. Alles selbst geschrieben, getestet und mit Auto-Updates.</p>
        <p class="ld-sub"><?php echo e($settings['site_tagline']); ?></p>
        <div class="ld-cta">
            <a class="btn btn-gold ld-big" href="#projekte">🧩 Projekte ansehen</a>
            <a class="btn ld-big" href="<?php echo e(LANDING_LINKS['github']); ?>" target="_blank" rel="noopener">🐙 GitHub</a>
            <a class="btn ld-big ld-discord" href="<?php echo e(LANDING_LINKS['discord']); ?>" target="_blank" rel="noopener">💬 Discord</a>
        </div>
        <div class="ld-badges"><span>⬢ Node.js</span><span>⚛ Electron</span><span>🐘 PHP</span><span>🤖 Kotlin / Android</span><span>♯ C# / Unity</span><span>🐍 Python</span><span>🐧 Linux & systemd</span></div>
    </div>
    <div class="ld-hero-img ld-code" aria-hidden="true">
<pre><span class="c">// ghostnode – so bauen wir</span>
<span class="k">const</span> projekt = {
  name: <span class="s">'eigene Idee'</span>,
  pakete: <span class="n">0</span>,          <span class="c">// nur Bordmittel</span>
  tests: <span class="s">'grün'</span>,
  secrets: <span class="s">'nie im Code'</span>,
  release: <span class="s">'bei jedem Push'</span>,
  updates: <span class="s">'automatisch'</span>,
};
<span class="k">await</span> bauen(projekt);   <span class="c">// ✔ fertig</span></pre>
    </div>
</header>

<section class="ld-stats">
    <div><b><?php echo count(LANDING_PROJECTS); ?></b><span>eigene Projekte</span></div>
    <div><b>7</b><span>Sprachen & Plattformen</span></div>
    <div><b><?php echo $counts['pages']; ?></b><span>Werkzeuge auf dieser Seite</span></div>
    <?php if ($counts['community']): ?><div><b><?php echo $counts['community']; ?></b><span>Community-Konten</span></div><?php endif; ?>
    <div><b>0</b><span>fremde Laufzeit-Pakete*</span></div>
</section>

<section class="ld-sec" id="projekte">
    <div class="ld-head"><span class="ld-eyebrow">PROJEKTE</span><h2>🧩 Was wir programmiert haben</h2><p>Jedes Projekt mit eigenem Repository, automatischen Tests und Releases auf GitHub.</p></div>
    <div class="ld-feat ld-projects">
        <?php foreach (LANDING_PROJECTS as $pr): $v = $pr['ver'] ? $latestOf($pr['ver']) : ''; ?>
            <div class="ld-card ld-prj">
                <span class="ld-ic"><?php echo $pr['icon']; ?></span>
                <b><?php echo e($pr['name']); ?><?php echo $v ? ' <span class="ld-tag">v' . e($v) . '</span>' : ''; ?></b>
                <p><?php echo e($pr['desc']); ?></p>
                <div class="ld-tech"><?php foreach ($pr['tech'] as $t) echo '<span>' . e($t) . '</span>'; ?></div>
                <div class="ld-plinks">
                    <?php foreach ($pr['links'] as [$label, $href]): $ext = str_starts_with($href, 'http'); ?>
                        <a href="<?php echo e($href); ?>"<?php echo $ext ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo e($label); ?> →</a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="ld-shots">
        <?php foreach ([['nexus-home.jpg', 'VRC Nexus – Desktop-App'], ['nexus-client.jpg', 'VRC Nexus – OSC & Chatbox'], ['nexus-stats.jpg', 'VRC Nexus – Statistik']] as [$f, $cap]): ?>
            <figure><a href="assets/img/landing/<?php echo $f; ?>" target="_blank" rel="noopener"><?php echo img_pic('assets/img/landing/' . $f, $cap); ?></a><figcaption><?php echo e($cap); ?></figcaption></figure>
        <?php endforeach; ?>
    </div>
</section>

<section class="ld-sec" id="technik">
    <div class="ld-head"><span class="ld-eyebrow">TECHNIK</span><h2>⚙ Wie wir programmieren</h2><p>Ein paar Regeln, an die sich jedes Projekt hält.</p></div>
    <div class="ld-feat">
        <?php foreach ([
            ['📦', 'Ohne fremde Pakete', 'Apps, Bot und Server laufen mit den Bordmitteln von Node.js, PHP und Android. Weniger Abhängigkeiten, weniger Sicherheitslücken, schnellere Updates.'],
            ['🧪', 'Tests bei jedem Push', 'Automatische Tests mit Attrappen statt echter Konten – erst wenn alles grün ist, entsteht ein Release.'],
            ['🚀', 'Releases & Auto-Update', 'Jeder Push baut Windows-, Linux- und Android-Dateien und veröffentlicht sie; die Apps aktualisieren sich selbst.'],
            ['🔐', 'Keine Geheimnisse im Code', 'Schlüssel und Passwörter nur verschlüsselt auf dem Gerät oder als Server-Geheimnis – nie im Repository.'],
            ['🛡', 'Sicherheit zuerst', 'Strenge Content-Security-Policy, Freigabelisten statt freier Befehle, verschlüsselte Ablage, Prüfung jeder Eingabe.'],
            ['🐧', 'Server per Skript', 'Ein Befehl richtet den ganzen Linux-Server ein: nginx, Datenbank, Dienste, Zertifikate, Backups, Wächter und Auto-Update.'],
            ['🌍', 'Mehrsprachig', 'Oberflächen in Deutsch, Englisch, Französisch und Japanisch – Übersetzungen zentral gepflegt.'],
            ['♿', 'Barrierearm', 'Tastaturbedienung, Kontraste, Screenreader-Texte und Hinweise beim Überfahren.'],
            ['🔓', 'Daten bleiben bei dir', 'Die Apps sprechen direkt mit den offiziellen Diensten – ohne Umweg über unsere Server.'],
        ] as [$ic, $t, $d]): ?>
            <div class="ld-card"><span class="ld-ic"><?php echo $ic; ?></span><b><?php echo e($t); ?></b><p><?php echo e($d); ?></p></div>
        <?php endforeach; ?>
    </div>
    <p class="ld-note">* zur Laufzeit; zum Bauen der Desktop-Apps nutzen wir Electron und electron-builder, für Android Gradle.</p>
</section>

<section class="ld-sec" id="news">
    <div class="ld-head"><span class="ld-eyebrow">NEUIGKEITEN</span><h2>📰 Letzte Versionen</h2></div>
    <?php if ($releases): ?>
        <div class="ld-news">
            <?php foreach ($releases as $i => $r): ?>
                <a class="ld-ncard<?php echo $i === 0 ? ' new' : ''; ?>" href="<?php echo e($r['url']); ?>" target="_blank" rel="noopener">
                    <span class="ld-tag"><?php echo e($r['project']); ?></span>
                    <b>v<?php echo e($r['ver']); ?></b>
                    <small><?php echo $r['date'] ? e(date('d.m.Y', strtotime($r['date']))) : ''; ?><?php echo $i === 0 ? ' · neu' : ''; ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="ld-note">Gerade keine Verbindung zu GitHub – alle Projekte gibt es unter <a href="<?php echo e(LANDING_LINKS['github']); ?>" target="_blank" rel="noopener">github.com/ghostnode-labs</a>.</p>
    <?php endif; ?>
</section>

<section class="ld-sec" id="werkzeuge">
    <div class="ld-head"><span class="ld-eyebrow">AUF DIESER SEITE</span><h2>🧰 Werkzeuge & Seiten</h2><p>Vieles öffentlich, manches nur mit Konto.</p></div>
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

<section class="ld-sec" id="community">
    <div class="ld-head"><span class="ld-eyebrow">COMMUNITY</span><h2>💬 Mach mit</h2></div>
    <div class="ld-links">
        <a class="ld-lcard discord" href="<?php echo e(LANDING_LINKS['discord']); ?>" target="_blank" rel="noopener"><span>💬</span><b>Discord</b><small>Hilfe, Ideen, Updates und Community</small></a>
        <a class="ld-lcard" href="<?php echo e(LANDING_LINKS['github']); ?>" target="_blank" rel="noopener"><span>🐙</span><b>GitHub</b><small>Quelltext-Projekte, Downloads, Fehler melden</small></a>
        <a class="ld-lcard patreon" href="<?php echo e(LANDING_LINKS['patreon']); ?>" target="_blank" rel="noopener"><span>❤️</span><b>Patreon</b><small>Die Entwicklung unterstützen</small></a>
        <?php if (isset($pages['api.php'])): ?><a class="ld-lcard" href="<?php echo e($pages['api.php']['href']); ?>"><span>🔌</span><b>API</b><small>Für Entwickler: Web- und Client-API mit Doku</small></a><?php endif; ?>
        <?php if (is_file(__DIR__ . '/Roadmap.php')): ?><a class="ld-lcard" href="Roadmap.php"><span>🗺</span><b>Roadmap</b><small>Was als Nächstes kommt</small></a><?php endif; ?>
    </div>
</section>

</main>
<footer class="ld-foot">
    <span>◈ <?php echo e($title); ?> · Software & Programmierung · <?php echo date('Y'); ?></span>
    <span><a href="dashboard.php">Dashboard</a> · <a href="admin.php<?php echo $user ? '' : '?next=dashboard.php'; ?>"><?php echo $user ? 'Admin & Konto' : 'Anmelden'; ?></a> · <a href="<?php echo e(LANDING_LINKS['github']); ?>" target="_blank" rel="noopener">GitHub</a></span>
</footer>
<?php echo site_i18n_tags(); ?>
</body>
</html>
