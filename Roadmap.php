<?php
declare(strict_types=1);

/**
 * VRC Nexus Roadmap – 5.x-Plan, Rückblick 3.x/4.x, Ideen-Pool.
 * Öffentlich lesbar. Status (erledigt / in Arbeit / geplant) wird serverseitig gespeichert und ist für alle sichtbar;
 * ändern dürfen nur freigeschaltete Bearbeiter (Roadmap-Passwort, SHA-512) oder Admins der Seite.
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php'; // Lädt auch das bestehende Auth-System der Website.
auth_require_setup();

const ROADMAP_PASSWORD_SHA512 = '8af0d7279882a570a1f446fc368d40db612ad550c8a9b88f5ef145679462f8b88b730e531e7453063cb1c74fea4e7d6af1c0ac6b375a85b6e18ce9d7fb9e573a';
const RM_STATES = ['planned' => 'Geplant', 'wip' => 'In Arbeit', 'done' => 'Erledigt'];

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['roadmap_csrf'])) $_SESSION['roadmap_csrf'] = bin2hex(random_bytes(32));

$error = '';
$edit_requested = isset($_GET['edit']) && $_GET['edit'] === '1';

// ---------- Anmeldung für den Bearbeitungsmodus ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['roadmap_login'])) {
    if (!hash_equals($_SESSION['roadmap_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Ungültige Anfrage. Bitte lade die Seite neu.';
    } elseif (!empty($_SESSION['roadmap_lock_until']) && time() < (int)$_SESSION['roadmap_lock_until']) {
        $error = 'Zu viele Fehlversuche. Bitte später erneut versuchen.';
    } elseif (hash_equals(ROADMAP_PASSWORD_SHA512, hash('sha512', (string)($_POST['password'] ?? '')))) {
        session_regenerate_id(true);
        $_SESSION['roadmap_authenticated'] = true;
        $_SESSION['roadmap_failures'] = 0;
        $_SESSION['roadmap_lock_until'] = 0;
        $_SESSION['roadmap_csrf'] = bin2hex(random_bytes(32));
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?edit=1');
        exit;
    } else {
        $_SESSION['roadmap_failures'] = (int)($_SESSION['roadmap_failures'] ?? 0) + 1;
        if ($_SESSION['roadmap_failures'] >= 5) { $_SESSION['roadmap_failures'] = 0; $_SESSION['roadmap_lock_until'] = time() + 900; }
        $error = 'Passwort ist nicht korrekt.';
        $edit_requested = true;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['roadmap_logout'])) {
    if (hash_equals($_SESSION['roadmap_csrf'], (string)($_POST['csrf'] ?? ''))) { unset($_SESSION['roadmap_authenticated']); session_regenerate_id(true); }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
$is_admin = function_exists('can') && can('admin.access');
$can_edit = !empty($_SESSION['roadmap_authenticated']) || $is_admin;
$edit_mode = $can_edit && ($edit_requested || $is_admin && isset($_GET['edit']));
$show_edit_login = $edit_requested && !$can_edit;

// ---------- Status-Speicher (für alle sichtbar) ----------
function rm_status_file(): string { return DATA_DIR . '/roadmap_status.json'; }
function rm_status_load(): array {
    $f = rm_status_file();
    if (!is_file($f)) return [];
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) ? $j : [];
}
function rm_key(string $group, string $item): string { return substr(sha1($group . '|' . $item), 0, 16); }

if (isset($_GET['api']) && $_GET['api'] === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$can_edit || !hash_equals($_SESSION['roadmap_csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['error' => 'Nicht erlaubt.']); exit; }
    $key = (string)($_POST['key'] ?? ''); $st = (string)($_POST['status'] ?? '');
    if (!preg_match('/^[0-9a-f]{16}$/', $key) || !isset(RM_STATES[$st])) { http_response_code(400); echo json_encode(['error' => 'Ungültig.']); exit; }
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    $fp = fopen(rm_status_file(), 'c+');
    if (!$fp) { http_response_code(500); echo json_encode(['error' => 'Speichern fehlgeschlagen.']); exit; }
    flock($fp, LOCK_EX);
    $all = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($all)) $all = [];
    $all[$key] = $st;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($all, JSON_PRETTY_PRINT));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    echo json_encode(['ok' => true, 'key' => $key, 'status' => $st]);
    exit;
}

// =====================================================================================================
// 5.x – Plan (Status-Vorgabe: geplant; einzelne Punkte mit [Text, 'wip'|'done'])
// =====================================================================================================
$plan5 = [
    '5.0 – Plattform & Plugin-Marktplatz' => ['🧩', 'Plugins finden, prüfen und mit einem Klick installieren – sicher und abgeschottet.', [
        'Plugin-Marktplatz in der App (Suchen, Kategorien, Bewertungen, Screenshots)',
        'Nur signierte, geprüfte Pakete; Rechte werden vor der Installation angezeigt',
        'Automatische Plugin-Updates mit Änderungsprotokoll',
        'Plugin-SDK mit Vorlagen, Dokumentation und lokalem Test-Modus',
        'Einreichen von Plugins über die Website mit Prüf-Workflow',
        'Einstellungen und Daten je Plugin sichern und wiederherstellen',
    ]],
    '5.1 – Discord-Bot & Konto-Verknüpfung' => ['🤖', 'Ein eigener Bot auf Node.js, verbunden mit Nexus-Konto und Website.', [
        'Discord-Bot auf dem eigenen Server (dauerhafte Gateway-Verbindung)',
        'Slash-Befehle: /status, /welt, /puls, /poster, /release',
        'Discord- und Nexus-Konto koppeln; Supporter- und 18+-Rollen automatisch',
        'Neue Releases, Events und Poster automatisch posten',
        'Warnliste und Mod-Meldungen in einen Mod-Kanal',
        '„Wer ist gerade in VRChat online“ (nur mit Freigabe)',
    ]],
    '5.2 – Web & Foren' => ['🌐', 'Nexus außerhalb der App: Browser, Website und Foren.', [
        'WoltLab-Core-Plugin: BBCodes [vrcworld] und [vrcavatar] mit Info-Karten',
        'WoltLab: VRChat-Profilfeld, Online-Box und Kopplung mit dem Nexus-Konto',
        'Nexus-Web-Dashboard im Browser (Freunde, Welten, Kalender – nur lesen)',
        'Öffentliche Profilseiten mit eigener Adresse (freiwillig)',
        'Einbettbare Widgets für Streams und Websites (Puls, Welt, Status)',
    ]],
    '5.3 – Android 2.0' => ['📱', 'Das Handy wird vollwertig – auch ohne laufenden PC.', [
        'Push-Benachrichtigungen ohne Google-Zwang (eigener Dienst)',
        'Homescreen-Widgets: Freunde online, Puls, nächstes Event',
        'Sprachanrufe auf dem Handy',
        'Nexus-Knöpfe als Schnellzugriff und Kachel in den Schnelleinstellungen',
        'Offline-Modus mit Abgleich beim nächsten Start',
    ]],
    '5.4 – Voice 2.0' => ['🎙', 'Gruppen-Sprachchats über den eigenen Server.', [
        'Gruppen-Sprachräume (SFU auf dem eigenen Server, eigener TURN)',
        'Sprachräume für VRChat-Gruppen mit Rollen',
        'Push-to-Talk, Geräuschunterdrückung, Lautstärke je Person',
        'Verschlüsselte Übertragung auch in Gruppen',
        'Sprach-Nachrichten im Messenger',
    ]],
    '5.5 – Events & Social' => ['📅', 'Gemeinsam planen, treffen und erinnern.', [
        'Event-Planer mit VRChat-Gruppen-Events, Zusagen und Erinnerungen',
        'Gemeinsame Instanz-Einladungen für Freundesgruppen',
        'Geteilte Foto-Alben (Ende-zu-Ende verschlüsselt)',
        'Treffen-Rückblick: wer, wo, wie lange – als Karte und Zeitachse',
        'Nachrichten an VRChat-Nutzer ohne Nexus (Abruf nach Registrierung)',
    ]],
    '5.6 – Creator & OSC' => ['🛠', 'Werkzeuge für Avatar- und Welt-Bastler.', [
        'Avatar-Parameter-Presets: Outfits und Zustände speichern und laden',
        'OSC-Szenen: mehrere Parameter, Chatbox und Licht in einem Knopf',
        'Welt-Statistiken für eigene Welten (Besuche, Favoriten, Trends)',
        'Poster-Verwaltung direkt in der App (Anbindung an die Website)',
        'OBS-Anbindung: Szenen je Welt, Panik-Szene, Puls-Overlay',
    ]],
    '5.7 – Komfort & Wohlbefinden' => ['🌙', 'Gesund spielen, ohne erhobenen Zeigefinger.', [
        'Schlafmodus: Status, Stummschalten, gedimmtes Overlay, Wecker',
        'Spielzeit-Ziele und sanfte Pausen-Hinweise',
        'Puls-Warnungen (zu hoch / zu niedrig) mit eigenen Grenzen',
        'Smart-Home: Licht reagiert auf Welt, Puls oder Panik (z. B. Hue)',
        'Barrierefreiheit: Schriftgröße, Kontrast, Bildschirmleser',
    ]],
    '5.8 – 🔞 NSFW 2.0' => ['🔞', 'Nur mit 18+-Freigabe sichtbar – Details stehen im NSFW-Changelog der App.', [
        'Erweiterungen für Spielzeug, Szenen und Avatar-Systeme',
        'Mehr Privatsphäre- und Sicherheitsfunktionen',
        'Weitere Integrationen (nur nach 18+-Freigabe)',
    ]],
    '5.9 – Öffnung & Föderation' => ['🛰', 'Nexus spricht mit dem Rest des Internets.', [
        'Fediverse/ActivityPub: föderierte Profile und Beiträge',
        'Öffentliche, dokumentierte Nexus-API (mit Schlüsseln und Limits)',
        'Geräteübergreifender, verschlüsselter Einstellungs-Abgleich',
        'Mehrsprachigkeit ausbauen (weitere Sprachen über die Community)',
    ]],
];
$plan5_always = ['Stabilität und Fehlerbehebungen in jedem Release', 'Automatische Tests für jede neue Funktion', 'Keine Geheimnisse in Git – Schlüssel bleiben auf dem Server', 'Alle Texte in Deutsch, Englisch, Französisch und Japanisch', 'Lokale Funktionen bleiben kostenlos, Online-Dienste nur mit Opt-in'];
// =====================================================================================================
// Webseite 2.0 (ghostnode) – Phasen
// =====================================================================================================
$planWeb = [
    'Phase 1 – Design & Navigation' => ['🎨', 'Ein Gesicht für alle Seiten – am PC und auf dem Handy.', [
        'Einheitlicher Kopfbereich mit Menü auf allen Seiten (statt einzelner Zurück-Links)',
        'Mobiles Menü und durchgehend handytaugliche Seiten',
        'Helles und dunkles Design, gleiche Bausteine überall (Karten, Knöpfe, Formulare)',
        'Globale Suche über Seiten, Poster, News und Hilfe',
        'Deutsch und Englisch für alle öffentlichen Seiten',
    ]],
    'Phase 2 – Konto & Profile' => ['👤', 'Ein Konto für Website, App und Discord.', [
        'Anmelden mit Discord oder dem Nexus-Konto (VRChat-ID)',
        'Passkeys und Zwei-Faktor-Anmeldung',
        'Öffentliche Mitgliederprofile (freiwillig) mit VRChat-Status',
        'Persönliches Dashboard: Widgets per Drag & Drop anordnen',
        'Benachrichtigungen auf der Website (neue Poster, Events, Releases)',
    ]],
    'Phase 3 – Nexus & Community' => ['🥽', 'Die Website wird zum zweiten Zuhause der App.', [
        'Download-Center: alle Versionen, Changelog, Prüfsummen, Android und Windows',
        'Hilfe & Wiki mit FAQ, Anleitungen und Suche',
        'Nexus-Web-Dashboard: Freunde, Welten, Kalender im Browser',
        'Poster 2.0: Zeitplan-Kalender, Statistiken, Freigaben für Gruppen',
        'Öffentlicher Event-Kalender mit VRChat-Gruppen-Events',
        'Entwickler-Bereich: API-Doku und Plugin-Einreichung für den Marktplatz',
    ]],
    'Phase 4 – Admin, Betrieb & Sicherheit' => ['🛡', 'Sicher, schnell und gut zu verwalten.', [
        'Status-Seite für alle Dienste (nexus.php, HypeRate-Weiterleitung, Discord-Bot)',
        'Rollen- und Rechte-Editor mit Änderungsprotokoll',
        'Automatische Backups mit Wiederherstellung aus dem Admin-Bereich',
        'Sicherheits-Header (CSP), Anfrage-Limits, Schutz vor Spam',
        'Schneller: Zwischenspeicher, Bilder als WebP, gute Lighthouse-Werte',
        'Barrierefreiheit, Impressum und Datenschutz auf allen Seiten, ohne Tracking-Cookies',
    ]],
];
$ideas = ['Wallpaper Engine: Wallpaper je Welt oder Uhrzeit', 'Twitch/YouTube-Chat in die VRChat-Chatbox', 'Spotify/Musik-Steuerung aus dem VR-Overlay', 'Steam Deck als zweiter Bildschirm', 'Avatar-Galerie mit Tags und Suche', 'KI-Übersetzung für Sprachchat (lokal)', 'Gruppen-Statistiken für Moderatoren', 'Themes-Marktplatz für die Oberfläche'];

// =====================================================================================================
// 3.x / 4.x – Rückblick (Vorgabe: erledigt; noch offene Punkte unten)
// =====================================================================================================
$modules = [
'Nexus Core & Stabilität'=>['Fehler seit Version 2.5.0 beheben','Codebasis überarbeiten','Performance-Optimierungen','Fehlerbehandlung verbessern','Zentrale Konfiguration und Einstellungen','Protokollierung verbessern','Logout mit vollständiger Session-Bereinigung','Automatisierte UI-Tests mit Playwright','CI- und Regressionstests','Englische Dokumentation','Changelog'],
'Heartbeat & Tracking'=>['Pulsoid-Integration','HypeRate-Integration (über eigene Weiterleitung)','OSC-Heartbeat','SteamVR-HUD','Heartbeat auf der Startseite','Heartbeat-Verlaufsdiagramm','Stream-Deck-Heartbeat','Chatbox-Integration','Gemeinsame Heartbeat-Datenquelle','NSFW-Toy-Heartbeat-Integration'],
'VRChat Companion'=>['Friends Bar verbessern','Navigations-Shortcuts','Home-Shortcuts','Spielerinformationen erweitern','VRChat-Status anzeigen','Weltinformationen anzeigen','Crasher-, Ripper- und Troll-Warnliste','Lokale Nutzernotizen','Community-bestätigte Meldungen','„Wer war hier“ – Instanz-Verlauf','Info-Karten für Links und IDs'],
'OSC & Avatar'=>['OSCQuery (eigene Umsetzung)','Typrichtiges Senden und Bündeln','SPS/OGB/PCS-Erkennung','Avatar-Debugger','Parameter-Seite nur für den getragenen Avatar','„Nur live“-Filter','Chatbox-Module','OSC-Werkzeuge und Aufzeichnung'],
'GhostNode Community'=>['Community-Profile','Zuordnung über VRChat User ID','Profilbilder und Banner','Interessen, Pronomen, Sprachen, Links','Pride-Profilthemen','Optionaler 18+-Profilbereich','Privatsphäre-Einstellungen','Online-/Offline-Status','Benachrichtigungen','Nutzer blockieren und melden'],
'Nexus Messenger'=>['Private Direktnachrichten (Ende-zu-Ende)','Gruppennachrichten (Sender-Schlüssel)','Bilder und Medien','Reaktionen, Antworten, Anheften','Sicherheitsnummern','Geräte-Schlüsselverwaltung','Nachrichten an VRChat-Nutzer ohne Nexus','Boob-Funktion im Chat'],
'Nexus Voice'=>['Private Sprachgespräche (1:1)','Aktiven Sprecher anzeigen','Eigener TURN-Server','Gruppen-Sprachchats (SFU)'],
'Rechte & Schlüssel (NXK1)'=>['Signierte Lizenz-Keys','Bindung an VRChat User ID','Multiaccount','Supporter-, Toys- und Remote-Rechte','Rechte-Protokoll','Admin-PC-Registrierung'],
'Website & Dienste'=>['Landing-Page','VRChat-Poster mit festen Adressen','HypeRate-Weiterleitung','Nexus Pulse (News & Reddit, Opt-in)','Discord-Bot','Fediverse'],
'Android & Mobile'=>['Gemeinsame Codebasis','Nexus-Knöpfe (lokale Stream-Deck-Alternative, Idee von Natsu)','Handy-OSC','Push-Benachrichtigungen','Homescreen-Widget','Voice Chat'],
'Design & Sprachen'=>['Startseite mit Tabs','Modernisierte Kacheln und Widgets','VR-Overlay im Theme','Deutsch, Englisch, Französisch, Japanisch','Alle sichtbaren Texte übersetzt'],
'🔞 NSFW (nur mit 18+-Freigabe)'=>['18+-Freigabe über den VRChat-Altersstatus','Spielzeug (Buttplug/Intiface)','Panik-Knopf mit Wallpaper Engine und Entwarnung'],
];
// noch nicht umgesetzt → werden nach 5.x übernommen bzw. sind dort eingeplant
$open34 = ['Nachrichten an VRChat-Nutzer ohne Nexus', 'Boob-Funktion im Chat', 'Gruppen-Sprachchats (SFU)', 'Admin-PC-Registrierung', 'Discord-Bot', 'Fediverse', 'Push-Benachrichtigungen', 'Homescreen-Widget', 'Voice Chat'];
$principles = ['Lokale VRChat-Funktionen bleiben kostenlos.', 'Online-Dienste sind standardmäßig aus und brauchen Opt-in.', 'Die VRChat User ID ist die Grundlage der Identität.', 'Desktop und Android bleiben eine Anwendung.', 'Lokale Funktionen laufen auch ohne GhostNode Online.', 'Schlüssel und Geheimnisse bleiben auf dem Server, nie im Client oder in Git.', 'Privatsphäre zuerst: Ende-zu-Ende-Verschlüsselung, wo es geht.', 'Jede Funktion bekommt Tests und Übersetzungen.'];

// ---------- Status zusammenführen ----------
$saved = rm_status_load();
$st = function (string $group, string $item, string $def) use ($saved): string { $k = rm_key($group, $item); return isset($saved[$k], RM_STATES[$saved[$k]]) ? $saved[$k] : $def; };
$count = ['planned' => 0, 'wip' => 0, 'done' => 0];
$rows5 = [];
foreach ($plan5 as $rel => [$ic, $desc, $items]) {
    foreach ($items as $it) { $s = $st('5x|' . $rel, $it, 'planned'); $count[$s]++; $rows5[$rel][] = [$it, $s, rm_key('5x|' . $rel, $it)]; }
}
$rowsWeb = []; $countWeb = ['planned' => 0, 'wip' => 0, 'done' => 0];
foreach ($planWeb as $rel => [$ic, $desc, $items]) {
    foreach ($items as $it) { $s = $st('web|' . $rel, $it, 'planned'); $countWeb[$s]++; $rowsWeb[$rel][] = [$it, $s, rm_key('web|' . $rel, $it)]; }
}
$rows34 = [];
foreach ($modules as $mod => $items) {
    foreach ($items as $it) { $s = $st('34|' . $mod, $it, in_array($it, $open34, true) ? 'planned' : 'done'); $rows34[$mod][] = [$it, $s, rm_key('34|' . $mod, $it)]; }
}
$done34 = 0; $all34 = 0; foreach ($rows34 as $r) foreach ($r as $x) { $all34++; if ($x[1] === 'done') $done34++; }
$all5 = array_sum($count);
$pct = fn(int $a, int $b) => $b ? (int)round($a / $b * 100) : 0;
$chip = fn(string $s) => '<span class="rm-st rm-st-' . $s . '">' . e(RM_STATES[$s]) . '</span>';
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Roadmap · VRC Nexus</title>
<meta name="description" content="VRC Nexus Roadmap: Plan für 5.x, Rückblick auf 3.x/4.x und Ideen.">
<link rel="stylesheet" href="assets/css/hud.css">
<style>
body.rm{padding:0;display:block}
.rm-wrap{box-sizing:border-box;width:100%;max-width:1100px;margin:0 auto;padding:20px 24px 60px}
.rm-top{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px}
.rm-top .sp{flex:1}
.rm-hero{padding:30px 0 22px;border-bottom:1px solid var(--border-color)}
.rm-eyebrow{color:var(--accent-cyan);font-size:.7rem;letter-spacing:3px;font-weight:700}
.rm-hero h1{font-size:clamp(2rem,5vw,3.2rem);letter-spacing:6px;margin:10px 0;color:var(--accent-gold);text-shadow:0 0 24px rgba(243,194,98,.3)}
.rm-hero p{color:var(--text-muted);line-height:1.6;max-width:760px;margin:0}
.rm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:22px 0}
.rm-card{background:var(--panel-bg);border:1px solid var(--border-color);padding:14px 16px}
.rm-card b{display:block;font-size:1.6rem;color:var(--accent-gold)}
.rm-card span{font-size:.72rem;color:var(--text-muted);letter-spacing:1px;text-transform:uppercase}
.rm-bar{height:6px;background:rgba(255,255,255,.06);margin-top:8px;overflow:hidden}.rm-bar i{display:block;height:100%;background:linear-gradient(90deg,var(--accent-cyan),var(--accent-gold))}
.rm-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 18px;border-bottom:1px solid var(--border-color)}
.rm-tabs button{background:none;border:1px solid transparent;border-bottom:none;color:var(--text-muted);padding:10px 14px;cursor:pointer;font:inherit;letter-spacing:1px}
.rm-tabs button.on{color:var(--accent-cyan);border-color:var(--border-color);background:var(--panel-bg)}
.rm-pane[hidden],.rm-items li[hidden],.rm-rel[hidden]{display:none}
.rm-tools{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.rm-tools input{flex:1 1 220px}.rm-tools select{flex:0 1 180px}
.rm-tl{position:relative;padding-left:26px}
.rm-tl:before{content:"";position:absolute;left:9px;top:6px;bottom:6px;width:2px;background:linear-gradient(var(--accent-cyan),var(--accent-gold))}
.rm-rel{position:relative;background:var(--panel-bg);border:1px solid var(--border-color);padding:16px 18px;margin-bottom:14px}
.rm-rel:before{content:"";position:absolute;left:-22px;top:20px;width:12px;height:12px;border-radius:50%;background:var(--accent-cyan);box-shadow:0 0 10px var(--accent-cyan)}
.rm-rel.done:before{background:#4ade80;box-shadow:0 0 10px #4ade80}
.rm-rel header{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.rm-rel h3{margin:0;font-size:1.05rem;letter-spacing:.5px}
.rm-rel .ic{font-size:1.5rem}
.rm-rel header small{margin-left:auto;color:var(--text-muted);font-size:.75rem}
.rm-rel p{color:var(--text-muted);margin:6px 0 10px;font-size:.86rem}
.rm-items{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:6px}
.rm-items li{display:flex;gap:8px;align-items:flex-start;justify-content:space-between;padding:8px 10px;border:1px solid rgba(56,213,230,.12);background:rgba(4,6,9,.35);font-size:.84rem;line-height:1.4}
.rm-items li.s-done span.t{opacity:.65}
.rm-st{flex:none;font-size:.64rem;letter-spacing:1px;text-transform:uppercase;padding:2px 7px;border:1px solid;white-space:nowrap}
.rm-st-planned{color:var(--text-muted);border-color:var(--border-color)}
.rm-st-wip{color:var(--accent-gold);border-color:var(--accent-gold)}
.rm-st-done{color:#4ade80;border-color:#4ade80}
button.rm-st{background:none;cursor:pointer;font:inherit;font-size:.64rem}
.rm-grid2{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px}
.rm-mod{background:var(--panel-bg);border:1px solid var(--border-color)}
.rm-mod summary{display:flex;gap:10px;align-items:center;padding:12px 14px;cursor:pointer;list-style:none}
.rm-mod summary::-webkit-details-marker{display:none}
.rm-mod summary b{flex:1}
.rm-mod summary small{color:var(--text-muted);font-size:.75rem}
.rm-mod .rm-items{padding:0 12px 12px;grid-template-columns:1fr}
.rm-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px}
.rm-list div{padding:10px 12px;border:1px solid rgba(56,213,230,.14);background:rgba(56,213,230,.035);font-size:.86rem}
.rm-note{color:var(--text-muted);font-size:.82rem;margin:4px 0 14px}
.rm-login{margin:12px 0;padding:16px;border:1px solid var(--accent-gold);background:rgba(243,194,98,.06)}
.rm-login input[type=password]{max-width:360px}
.rm-err{color:var(--accent-red)}
.rm-toast{position:fixed;bottom:18px;left:50%;transform:translateX(-50%);background:var(--panel-bg);border:1px solid var(--accent-cyan);padding:8px 14px;font-size:.85rem;z-index:9}
@media(max-width:640px){.rm-wrap{padding:14px 16px 40px}.rm-items{grid-template-columns:1fr}.rm-tl{padding-left:20px}.rm-rel:before{left:-17px}}
</style></head><body class="rm">
<div class="rm-wrap">
<div class="rm-top">
    <a href="index.php" class="nav-back">&larr; STARTSEITE</a><a href="dashboard.php" class="nav-back">DASHBOARD</a><span class="sp"></span>
    <?php if ($edit_mode): ?><span class="rm-st rm-st-wip">Bearbeitungsmodus</span>
        <?php if (!empty($_SESSION['roadmap_authenticated'])): ?><form method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['roadmap_csrf']) ?>"><button class="btn btn-sm btn-danger" name="roadmap_logout" value="1">Bearbeiten beenden</button></form><?php else: ?><a class="btn btn-sm" href="Roadmap.php">Ansicht</a><?php endif; ?>
    <?php else: ?><a class="btn btn-sm" href="?edit=1">✎ Bearbeiten</a><?php endif; ?>
</div>
<?php if ($show_edit_login): ?><form class="rm-login" method="post"><b>Bearbeitungsmodus freischalten</b><p class="rm-note">Die Roadmap bleibt öffentlich lesbar. Nur das Ändern des Status braucht das Passwort.</p><input name="password" type="password" autocomplete="current-password" required autofocus placeholder="Passwort"><input type="hidden" name="csrf" value="<?= e($_SESSION['roadmap_csrf']) ?>"> <button class="btn btn-sm btn-gold" name="roadmap_login" value="1">Freischalten</button><?php if ($error !== ''): ?><p class="rm-err"><?= e($error) ?></p><?php endif; ?></form><?php endif; ?>

<header class="rm-hero">
    <div class="rm-eyebrow">GHOSTNODE · VRC NEXUS · ROADMAP</div>
    <h1>NEXUS 5</h1>
    <p>Wohin sich VRC Nexus entwickelt: Plugin-Marktplatz, eigener Discord-Bot, Web &amp; Foren, Android 2.0, Gruppen-Voice, Events, Creator-Werkzeuge und mehr. Die Reihenfolge ist eine Orientierung – Releases erscheinen, sobald sie fertig und getestet sind.</p>
</header>

<div class="rm-stats">
    <div class="rm-card"><b><?= count($plan5) ?></b><span>Releases in 5.x</span></div>
    <div class="rm-card"><b><?= $all5 ?></b><span>geplante Punkte</span></div>
    <div class="rm-card"><b><?= $count['wip'] ?></b><span>in Arbeit</span></div>
    <div class="rm-card"><b><?= $pct($count['done'], $all5) ?> %</b><span>5.x erledigt</span><div class="rm-bar"><i style="width:<?= $pct($count['done'], $all5) ?>%"></i></div></div>
    <div class="rm-card"><b><?= $pct($countWeb['done'], array_sum($countWeb)) ?> %</b><span>Webseite 2.0</span><div class="rm-bar"><i style="width:<?= $pct($countWeb['done'], array_sum($countWeb)) ?>%"></i></div></div>
    <div class="rm-card"><b><?= $pct($done34, $all34) ?> %</b><span>3.x / 4.x erledigt</span><div class="rm-bar"><i style="width:<?= $pct($done34, $all34) ?>%"></i></div></div>
</div>

<nav class="rm-tabs" role="tablist">
    <button type="button" class="on" data-tab="plan">🚀 5.x-Plan</button>
    <button type="button" data-tab="web">🌐 Webseite 2.0</button>
    <button type="button" data-tab="past">✅ 3.x &amp; 4.x</button>
    <button type="button" data-tab="ideas">💡 Ideen</button>
    <button type="button" data-tab="rules">🧭 Grundsätze</button>
</nav>

<section class="rm-pane" data-pane="plan">
    <div class="rm-tools"><input type="search" id="rmQ" placeholder="Im Plan suchen …"><select id="rmF"><option value="">Alle Status</option><?php foreach (RM_STATES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php if ($edit_mode): ?><p class="rm-note">Klick auf einen Status schaltet ihn weiter: Geplant → In Arbeit → Erledigt. Änderungen sind sofort für alle sichtbar.</p><?php endif; ?>
    <div class="rm-tl">
    <?php foreach ($plan5 as $rel => [$ic, $desc, $items]): $r = $rows5[$rel]; $d = count(array_filter($r, fn($x) => $x[1] === 'done')); ?>
        <article class="rm-rel<?= $d === count($r) ? ' done' : '' ?>">
            <header><span class="ic"><?= $ic ?></span><h3><?= e($rel) ?></h3><small><?= $d ?>/<?= count($r) ?> erledigt</small></header>
            <p><?= e($desc) ?></p>
            <ul class="rm-items"><?php foreach ($r as [$it, $s, $k]): ?><li class="s-<?= $s ?>" data-s="<?= $s ?>"><span class="t"><?= e($it) ?></span><?= $edit_mode ? '<button type="button" class="rm-st rm-st-' . $s . '" data-k="' . $k . '" data-s="' . $s . '">' . e(RM_STATES[$s]) . '</button>' : $chip($s) ?></li><?php endforeach; ?></ul>
        </article>
    <?php endforeach; ?>
    </div>
    <h3>In jedem 5.x-Release</h3>
    <div class="rm-list"><?php foreach ($plan5_always as $p): ?><div>✓ <?= e($p) ?></div><?php endforeach; ?></div>
</section>

<section class="rm-pane" data-pane="web" hidden>
    <p class="rm-note">Die GhostNode-Website in Version 2.0 – parallel zu Nexus 5.x, in vier Phasen.</p>
    <div class="rm-tl">
    <?php foreach ($planWeb as $rel => [$ic, $desc, $items]): $r = $rowsWeb[$rel]; $d = count(array_filter($r, fn($x) => $x[1] === 'done')); ?>
        <article class="rm-rel<?= $d === count($r) ? ' done' : '' ?>">
            <header><span class="ic"><?= $ic ?></span><h3><?= e($rel) ?></h3><small><?= $d ?>/<?= count($r) ?> erledigt</small></header>
            <p><?= e($desc) ?></p>
            <ul class="rm-items"><?php foreach ($r as [$it, $s, $k]): ?><li class="s-<?= $s ?>" data-s="<?= $s ?>"><span class="t"><?= e($it) ?></span><?= $edit_mode ? '<button type="button" class="rm-st rm-st-' . $s . '" data-k="' . $k . '" data-s="' . $s . '">' . e(RM_STATES[$s]) . '</button>' : $chip($s) ?></li><?php endforeach; ?></ul>
        </article>
    <?php endforeach; ?>
    </div>
</section>

<section class="rm-pane" data-pane="past" hidden>
    <p class="rm-note">Was in den Generationen 3.x und 4.x entstanden ist. Offene Punkte wandern in den 5.x-Plan.</p>
    <div class="rm-grid2">
    <?php foreach ($rows34 as $mod => $r): $d = count(array_filter($r, fn($x) => $x[1] === 'done')); ?>
        <details class="rm-mod"><summary><b><?= e($mod) ?></b><small><?= $d ?>/<?= count($r) ?></small></summary>
            <ul class="rm-items"><?php foreach ($r as [$it, $s, $k]): ?><li class="s-<?= $s ?>" data-s="<?= $s ?>"><span class="t"><?= e($it) ?></span><?= $edit_mode ? '<button type="button" class="rm-st rm-st-' . $s . '" data-k="' . $k . '" data-s="' . $s . '">' . e(RM_STATES[$s]) . '</button>' : $chip($s) ?></li><?php endforeach; ?></ul>
        </details>
    <?php endforeach; ?>
    </div>
</section>

<section class="rm-pane" data-pane="ideas" hidden>
    <p class="rm-note">Ideen ohne festen Termin – Wünsche gern im Discord melden.</p>
    <div class="rm-list"><?php foreach ($ideas as $p): ?><div>💡 <?= e($p) ?></div><?php endforeach; ?></div>
</section>

<section class="rm-pane" data-pane="rules" hidden>
    <div class="rm-list"><?php foreach ($principles as $p): ?><div>✓ <?= e($p) ?></div><?php endforeach; ?></div>
</section>
</div>
<script>
(() => {
    const tabs = document.querySelectorAll('.rm-tabs button'), panes = document.querySelectorAll('.rm-pane');
    const show = t => { tabs.forEach(b => b.classList.toggle('on', b.dataset.tab === t)); panes.forEach(p => p.hidden = p.dataset.pane !== t); try { localStorage.setItem('rmTab', t); } catch (e) {} };
    tabs.forEach(b => b.addEventListener('click', () => show(b.dataset.tab)));
    try { const t = location.hash.slice(1) || localStorage.getItem('rmTab'); if (t && document.querySelector('[data-pane="' + t + '"]')) show(t); } catch (e) {}
    const q = document.getElementById('rmQ'), f = document.getElementById('rmF');
    const filter = () => { const s = q.value.toLocaleLowerCase('de'), v = f.value;
        document.querySelectorAll('[data-pane=plan] .rm-rel').forEach(r => { let any = false;
            r.querySelectorAll('li').forEach(li => { const ok = (!v || li.dataset.s === v) && (!s || li.textContent.toLocaleLowerCase('de').includes(s) || r.querySelector('h3').textContent.toLocaleLowerCase('de').includes(s)); li.hidden = !ok; any = any || ok; });
            r.hidden = !any; }); };
    q.addEventListener('input', filter); f.addEventListener('change', filter);
<?php if ($edit_mode): ?>
    const NEXT = { planned: 'wip', wip: 'done', done: 'planned' }, LABEL = <?= json_encode(RM_STATES, JSON_UNESCAPED_UNICODE) ?>, CSRF = <?= json_encode($_SESSION['roadmap_csrf']) ?>;
    const toast = m => { const t = document.createElement('div'); t.className = 'rm-toast'; t.textContent = m; document.body.appendChild(t); setTimeout(() => t.remove(), 1800); };
    document.addEventListener('click', e => {
        const b = e.target.closest('button.rm-st[data-k]'); if (!b) return;
        const to = NEXT[b.dataset.s], fd = new FormData(); fd.append('key', b.dataset.k); fd.append('status', to); fd.append('csrf', CSRF);
        b.disabled = true;
        fetch('Roadmap.php?api=status', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json()).then(d => {
            if (!d.ok) throw new Error(d.error || 'Fehler');
            b.dataset.s = to; b.className = 'rm-st rm-st-' + to; b.textContent = LABEL[to];
            const li = b.closest('li'); li.dataset.s = to; li.className = 's-' + to;
        }).catch(er => toast('⚠ ' + er.message)).finally(() => { b.disabled = false; });
    });
<?php endif; ?>
})();
</script>
</body></html>
