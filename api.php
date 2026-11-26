<?php
declare(strict_types=1);
/**
 * GhostNode API – Web-API (PHP, v1) und Dokumentation beider Schnittstellen (Web-API + Client-API über WebSocket).
 * <title>API</title>
 *   API-Aufrufe:  api.php?r=<endpunkt>   (oder api.php/v1/<endpunkt>)   – JSON, CORS offen, ohne Cookies
 *   Ohne r=:      Dokumentation + eigene API-Schlüssel (angemeldet)
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/webapi.php';

$path = (string)($_GET['r'] ?? '');
if ($path === '' && !empty($_SERVER['PATH_INFO'])) $path = preg_replace('#^/?v1/#', '', ltrim((string)$_SERVER['PATH_INFO'], '/'));
$path = trim(preg_replace('#[^a-z0-9/_-]#', '', strtolower($path)), '/');

// ---------------------------------------------------------------- API
if ($path !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, X-Api-Key, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
    $out = function (array $o, int $code = 200) { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
    try {
        $key = wa_auth();
        $who = $key ? 'k' . $key[0]['id'] : 'ip|' . client_ip();
        if (nw_rate_over('api|' . $who, $key ? 120 : 30)) $out(['error' => 'Zu viele Anfragen (Limit ' . ($key ? 120 : 30) . '/min).'], 429);
        $out(wa_route((string)$_SERVER['REQUEST_METHOD'], $path, $key));
    } catch (WaError $e) { $out(['error' => $e->getMessage()], $e->status); }
    catch (InvalidArgumentException | RuntimeException $e) { $out(['error' => $e->getMessage()], 400); }
    catch (Throwable $e) { error_log('api: ' . $e->getMessage()); $out(['error' => 'Serverfehler.'], 500); }
}

// ---------------------------------------------------------------- Seite: Doku + Schlüssel
auth_require_setup();
$u = current_user();
$newKey = ''; $msg = ''; $err = '';
if ($u && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    guard_post();
    try {
        if (($_POST['action'] ?? '') === 'key_create') $newKey = wa_create((int)$u['id'], (string)($_POST['name'] ?? ''), (array)($_POST['scopes'] ?? ['read']));
        if (($_POST['action'] ?? '') === 'key_revoke') { wa_revoke((int)$u['id'], (int)($_POST['id'] ?? 0)); $msg = 'Schlüssel widerrufen.'; }
    } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
}
$keys = $u ? wa_keys((int)$u['id']) : [];
$base = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'ghostnode-labs.dev') . strtok($_SERVER['REQUEST_URI'] ?? '/api.php', '?');
$EP = [
    ['GET', 'status', '–', 'Dienst-Status, ob der Schlüssel erkannt wurde', '{"ok":true,"service":"ghostnode-web-api","version":1,"authed":false}'],
    ['GET', 'versions', '–', 'VRC-Nexus-Versionen mit Downloads (<code>limit</code> 1–100)', '{"items":[{"version":"5.3.0","date":"…","downloads":[…]}]}'],
    ['GET', 'versions/latest', '–', 'Neueste Version', '{"version":"5.3.0","downloads":[…]}'],
    ['GET', 'news', '–', 'Offizielle VRChat-News (<code>kind</code>: patch, dev, security, creator, news · <code>limit</code>)', '{"items":[{"title":"…","kind":"patch","url":"…"}]}'],
    ['GET', 'market', '–', 'Plugin-Katalog des Marktplatzes', '{"plugins":[…]}'],
    ['GET', 'me', 'read', 'Wem der Schlüssel gehört', '{"id":3,"name":"Luna","scopes":["read"]}'],
    ['GET', 'pages', 'read', 'Seiten des Dashboards, die das Konto sehen darf', '{"items":[{"title":"Kalender","url":"calendar.php"}]}'],
    ['GET', 'calendar', 'read', 'Termine & Countdowns der nächsten Tage (<code>days</code> 1–90)', '{"items":[{"title":"Stammtisch","start":"2026-12-01T19:00:00+01:00"}]}'],
    ['GET', 'community/feed', 'community', 'Nexus-Community (SFW): <code>q</code>, <code>tag</code>, <code>author</code>, <code>before</code>', '{"items":[{"id":12,"text":"…","tags":["vrchat"]}]}'],
    ['GET', 'community/trends', 'community', 'Hashtags, meistbeachtete Beiträge, aktive Leute (7 Tage)', '{"tags":[{"tag":"vrchat","n":4}]}'],
    ['POST', 'community/post', 'community', 'Beitrag posten (JSON <code>{"text":"…","parent":0}</code>)', '{"post":{…},"msg":"Gepostet."}'],
];
$WS_OPS = [
    ['hello', '–', 'Version, Befehle, Themen (geht ohne Anmeldung; kommt auch automatisch als <code>welcome</code>)'],
    ['auth', '<code>token</code>', 'Anmelden mit dem Zugangsschlüssel aus VRC Nexus (oder <code>?token=</code> in der Adresse)'],
    ['state', '–', 'Kompletter Status: Spiel, Freunde, OSC, Puls, Medien, Makros, Szenen …'],
    ['action', '<code>action</code>, <code>params</code>', 'Aktion ausführen – dieselben wie das Stream-Deck-Plugin (Liste unten). Felder in <code>params</code>, z. B. <code>{"id":3,"op":"action","action":"avpreset","params":{"id":"Party"}}</code> – <code>id</code> außerhalb ist die Anfragenummer'],
    ['subscribe', '<code>topics</code>', 'Ereignisse abonnieren; danach kommen <code>{"event":"heart","data":{…}}</code> bei jeder Änderung'],
    ['unsubscribe', '<code>topics</code>', 'Abo beenden'],
    ['ping', '–', 'Lebenszeichen → <code>{"pong":…}</code>'],
];
$WS_TOPICS = ['game' => 'Welt, Instanz, Spielerzahl, seit wann', 'heart' => 'Puls (bpm, Quelle)', 'friends' => 'Freunde im Spiel / online / gesamt', 'me' => 'eigener VRChat-Status',
    'media' => 'Musik/Video, das gerade läuft', 'chatbox' => 'letzter Chatbox-Text', 'notifs' => 'ungelesene Benachrichtigungen', 'warn' => 'Leute von der Warnliste in der Instanz', 'osc' => 'Avatar-Parameter (alle 2 s, ohne intime Parameter)'];
$WS_ACTIONS = [
    'status' => '<code>status</code> (join me · active · ask me · busy), <code>desc</code>', 'chatbox' => '<code>text</code>, <code>sound</code>, <code>translate</code>, <code>target</code>', 'typing' => '<code>on</code>',
    'param' => '<code>name</code>, <code>type</code> (bool · int · float), <code>value</code>', 'toggle' => '<code>name</code> (bool umschalten)', 'param_step' => '<code>name</code>, <code>step</code>, <code>type</code>',
    'input' => '<code>name</code>, <code>value</code>', 'preset' => '<code>id</code> (OSC-Vorlage)', 'avatar' => '<code>id</code> (avtr_…)', 'macro' => '<code>id</code> oder <code>script</code>', 'macro_stop' => '–',
    'rotator' => '<code>on</code>', 'launch' => 'VRChat starten', 'rejoin' => 'Instanz neu betreten', 'instance_create' => '<code>world</code>, <code>type</code>, <code>region</code>',
    'sleep' => 'Schlafmodus an/aus · <code>on</code> (leer = umschalten), <code>wake</code> (Wecker in Minuten)', 'avpreset' => '<code>id</code> (Name oder ID eines Avatar-Presets)', 'watch_find' => 'gekoppelte Nexus Watch vibrieren lassen',
    'camera' => '<code>cmd</code> (burst · preset · step …)', 'quicktext' => 'Schnelltext', 'scene' => 'OSC-Szene', 'record' => 'OSC-Aufnahme', 'media' => 'Wiedergabe steuern', 'tts' => 'Vorlesen', 'overlay' => 'VR-Overlay', 'speech' => 'Spracheingabe', 'chatmods' => 'Chatbox-Module', 'friend' => 'Freund (Einladen …)', 'open' => 'Seite der App öffnen', 'invite_self' => 'Selbst-Einladung',
];
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>API · GhostNode</title>
<meta name="description" content="Dokumentation der GhostNode Web-API und der VRC-Nexus Client-API (WebSocket).">
<link rel="stylesheet" href="assets/css/hud.css">
<style>
body.ap{padding:0;display:block}
.ap-wrap{box-sizing:border-box;max-width:1100px;margin:0 auto;padding:20px 20px 60px;display:grid;grid-template-columns:210px minmax(0,1fr);gap:24px}
.ap-toc{position:sticky;top:70px;align-self:start;display:flex;flex-direction:column;gap:2px;font-size:.85rem}
.ap-toc a{color:var(--text-muted);text-decoration:none;padding:5px 8px;border-left:2px solid transparent}.ap-toc a:hover{color:var(--accent-cyan);border-color:var(--accent-cyan)}
.ap-toc b{margin:12px 8px 4px;font-size:.66rem;letter-spacing:1.5px;color:var(--accent-gold);text-transform:uppercase}
.ap-hero h1{font-size:clamp(2rem,5vw,3rem);letter-spacing:6px;margin:8px 0;color:var(--accent-gold)}
.ap-hero p{color:var(--text-muted);line-height:1.6;margin:0}
.ap h2{margin:34px 0 10px;color:var(--accent-cyan);font-size:1.2rem;letter-spacing:1px;border-bottom:1px solid var(--border-color);padding-bottom:6px}
.ap h3{margin:20px 0 8px;font-size:1rem;color:#fff}
.ap p,.ap li{line-height:1.6;font-size:.92rem}
.ap code{background:rgba(56,213,230,.1);padding:1px 5px;font-size:.85em}
.ap pre{background:#05080d;border:1px solid var(--border-color);padding:12px 14px;overflow:auto;font-size:.82rem;line-height:1.5;position:relative}
.ap table{width:100%;border-collapse:collapse;font-size:.85rem;margin:8px 0}
.ap th,.ap td{border-bottom:1px solid rgba(56,213,230,.15);padding:7px 8px;text-align:left;vertical-align:top}
.ap th{color:var(--text-muted);font-weight:600;font-size:.75rem;letter-spacing:1px;text-transform:uppercase}
.m{font-family:monospace;font-weight:700;font-size:.75rem;padding:1px 6px;border:1px solid}.m.GET{color:#50fa7b;border-color:#50fa7b55}.m.POST{color:#f3c262;border-color:#f3c26255}
.sc{font-size:.72rem;color:var(--accent-purple)}
.ap-card{background:var(--panel-bg);border:1px solid var(--border-color);padding:14px 16px;margin:10px 0}
.ap-key{font-family:monospace;font-size:.95rem;color:#50fa7b;word-break:break-all;background:#05080d;padding:10px;border:1px dashed #50fa7b}
.ap-copy{position:absolute;top:6px;right:6px;font-size:.7rem}
.ok{color:#50fa7b}.bad{color:var(--accent-red)}
@media(max-width:820px){.ap-wrap{grid-template-columns:1fr}.ap-toc{position:static;flex-direction:row;flex-wrap:wrap}.ap-toc b{width:100%}}
</style></head><body class="ap"><div class="ap-wrap">
<nav class="ap-toc"><b>Überblick</b><a href="#start">Start</a><a href="#keys">API-Schlüssel</a>
<b>Web-API</b><a href="#web">Grundlagen</a><a href="#web-ep">Endpunkte</a><a href="#web-ex">Beispiele</a><a href="#errors">Fehler &amp; Limits</a>
<b>Client-API</b><a href="#client">Verbinden</a><a href="#client-ops">Befehle</a><a href="#client-topics">Ereignisse</a><a href="#client-actions">Aktionen</a><a href="#client-ex">Beispiele</a>
<b>Sonst</b><a href="#rules">Regeln</a><a href="changelog.php">Changelog</a></nav>
<main class="ap">
<div class="ap-hero" id="start"><div class="hud-tag">GhostNode · Entwickler</div><h1>API</h1>
<p>Zwei Schnittstellen: die <b>Web-API</b> der GhostNode-Webseite (HTTPS, JSON, Schlüssel pro Konto) und die <b>Client-API</b> von VRC Nexus auf deinem PC (WebSocket auf <code>127.0.0.1</code>, Befehle und Live-Ereignisse – z. B. für Stream Deck, OBS-Skripte, Bots oder eigene Overlays).</p></div>

<h2 id="keys">🔑 Deine API-Schlüssel</h2>
<?php if (!$u): ?><div class="ap-card"><a href="admin.php?next=api.php" style="color:var(--accent-cyan)">Anmelden</a>, um Schlüssel zu erstellen. Öffentliche Endpunkte (Status, Versionen, News, Marktplatz) gehen auch ohne.</div>
<?php else: ?>
<?php if ($newKey): ?><div class="ap-card"><b class="ok">✓ Neuer Schlüssel – nur jetzt sichtbar, bitte sicher speichern:</b><div class="ap-key" id="nk"><?= e($newKey) ?></div></div><?php endif; ?>
<?php if ($msg): ?><p class="ok"><?= e($msg) ?></p><?php endif; ?><?php if ($err): ?><p class="bad"><?= e($err) ?></p><?php endif; ?>
<div class="ap-card"><form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><input type="hidden" name="action" value="key_create">
<input type="text" name="name" maxlength="40" placeholder="Name, z. B. „Discord-Bot“" style="flex:1;min-width:180px">
<?php foreach (WA_SCOPES as $k => [$l, $d]): ?><label title="<?= e($d) ?>" style="font-size:.85rem"><input type="checkbox" name="scopes[]" value="<?= e($k) ?>"<?= $k === 'read' ? ' checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?>
<button class="btn btn-gold btn-sm">＋ Schlüssel erstellen</button></form>
<?php if ($keys): ?><table><tr><th>Name</th><th>Beginnt mit</th><th>Rechte</th><th>Zuletzt</th><th>Aufrufe</th><th></th></tr>
<?php foreach ($keys as $k): ?><tr><td><?= e($k['name']) ?></td><td><code><?= e($k['prefix']) ?>…</code></td><td class="sc"><?= e($k['scopes']) ?></td><td><?= $k['last_used'] ? e(date('d.m.Y H:i', (int)$k['last_used'])) : '–' ?></td><td><?= (int)$k['uses'] ?></td>
<td><form method="POST" onsubmit="return confirm('Schlüssel widerrufen? Programme damit funktionieren dann nicht mehr.')"><input type="hidden" name="action" value="key_revoke"><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><button class="btn btn-sm">Widerrufen</button></form></td></tr><?php endforeach; ?></table>
<?php else: ?><p class="muted" style="font-size:.85rem;margin:10px 0 0">Noch keine Schlüssel.</p><?php endif; ?></div>
<?php endif; ?>

<h2 id="web">🌐 Web-API · Grundlagen</h2>
<p>Basis-Adresse: <code><?= e($base) ?>?r=&lt;endpunkt&gt;</code> – alternativ <code><?= e($base) ?>/v1/&lt;endpunkt&gt;</code>, wenn der Server PATH_INFO weitergibt. Antworten sind immer JSON (UTF-8). Browser-Aufrufe von anderen Seiten sind erlaubt (CORS, ohne Cookies).</p>
<p>Schlüssel mitschicken als <code>Authorization: Bearer gnk_…</code> oder <code>X-Api-Key: gnk_…</code>. Der Schlüssel handelt mit den Rechten deines Kontos und nur in seinen Bereichen (<span class="sc">read</span>, <span class="sc">community</span>). Gespeichert wird er nur als Prüfsumme.</p>

<h3 id="web-ep">Endpunkte</h3>
<table><tr><th>Methode</th><th>Endpunkt</th><th>Recht</th><th>Beschreibung</th></tr>
<?php foreach ($EP as [$m, $p, $s, $d]): ?><tr><td><span class="m <?= $m ?>"><?= $m ?></span></td><td><code><?= e($p) ?></code></td><td class="sc"><?= e($s) ?></td><td><?= $d ?></td></tr><?php endforeach; ?></table>

<h3 id="web-ex">Beispiele</h3>
<pre>curl "<?= e($base) ?>?r=versions/latest"

curl -H "Authorization: Bearer gnk_DEIN_SCHLÜSSEL" "<?= e($base) ?>?r=calendar&amp;days=7"

curl -X POST -H "Authorization: Bearer gnk_DEIN_SCHLÜSSEL" -H "Content-Type: application/json" \
     -d '{"text":"Hallo aus meinem Bot! #VRChat"}' "<?= e($base) ?>?r=community/post"</pre>
<pre>// JavaScript (Browser oder Node ≥ 18)
const r = await fetch('<?= e($base) ?>?r=community/feed&amp;tag=vrchat', { headers: { Authorization: 'Bearer ' + KEY } });
const { items } = await r.json();</pre>

<h3 id="errors">Fehler &amp; Limits</h3>
<table><tr><th>Status</th><th>Bedeutung</th></tr>
<tr><td>400</td><td>Ungültige Eingabe – <code>{"error":"…"}</code> sagt, was fehlt</td></tr><tr><td>401</td><td>Kein oder ungültiger Schlüssel</td></tr>
<tr><td>403</td><td>Dem Schlüssel fehlt das Recht, oder das Konto ist gesperrt</td></tr><tr><td>404</td><td>Unbekannter Endpunkt</td></tr>
<tr><td>429</td><td>Limit erreicht: <b>30 Aufrufe/min</b> ohne Schlüssel (je IP), <b>120/min</b> je Schlüssel</td></tr><tr><td>503</td><td>Quelle gerade nicht erreichbar (z. B. GitHub)</td></tr></table>
<p>Über die API gibt es nie 🔞-Inhalte – auch nicht mit einem freigeschalteten Konto.</p>

<h2 id="client">🖥 Client-API · Verbinden</h2>
<p>Läuft in <b>VRC Nexus für Windows</b> (ab 5.4.1). Einschalten unter <b>Einstellungen → Integrationen → Stream-Deck-Schnittstelle</b>; dort stehen auch Port (Standard siehe App) und <b>Zugangsschlüssel</b>. Erreichbar nur vom eigenen PC:</p>
<pre>ws://127.0.0.1:&lt;Port&gt;/ws?token=&lt;Zugangsschlüssel&gt;</pre>
<p>Jede Nachricht ist JSON. Anfragen haben eine <code>id</code>, die Antwort kommt mit derselben <code>id</code>: <code>{"id":1,"ok":true,"result":…}</code> oder <code>{"id":1,"ok":false,"error":"…"}</code>. Ereignisse haben keine <code>id</code>, sondern <code>event</code>.</p>
<p>Schutz: nur <code>127.0.0.1</code>, Verbindungen aus Webseiten im Browser (mit <code>Origin</code>) werden abgewiesen, falscher Schlüssel beendet die Verbindung, höchstens 8 Verbindungen, 64 KB je Nachricht, 30 Befehle pro Sekunde. Im Privat-Modus werden Chatbox und Medien nicht gesendet.</p>

<h3 id="client-ops">Befehle (<code>op</code>)</h3>
<table><tr><th>op</th><th>Felder</th><th>Beschreibung</th></tr><?php foreach ($WS_OPS as [$o, $f, $d]): ?><tr><td><code><?= $o ?></code></td><td><?= $f ?></td><td><?= $d ?></td></tr><?php endforeach; ?></table>

<h3 id="client-topics">Ereignisse (<code>subscribe</code>)</h3>
<table><tr><th>Thema</th><th>Inhalt</th></tr><?php foreach ($WS_TOPICS as $t => $d): ?><tr><td><code><?= $t ?></code></td><td><?= e($d) ?></td></tr><?php endforeach; ?></table>
<p>Nach dem Abonnieren kommt sofort der aktuelle Stand, danach nur bei Änderungen (geprüft jede Sekunde).</p>

<h3 id="client-actions">Aktionen (<code>op: "action"</code>)</h3>
<table><tr><th>action</th><th>Felder</th></tr><?php foreach ($WS_ACTIONS as $a => $d): ?><tr><td><code><?= $a ?></code></td><td><?= $d ?></td></tr><?php endforeach; ?></table>

<h3 id="client-ex">Beispiele</h3>
<pre>// Node ≥ 22 (WebSocket ist eingebaut)
const ws = new WebSocket('ws://127.0.0.1:PORT/ws?token=' + TOKEN);
ws.onopen = () => {
  ws.send(JSON.stringify({ id: 1, op: 'subscribe', topics: ['game', 'heart'] }));
  ws.send(JSON.stringify({ id: 2, op: 'action', action: 'chatbox', params: { text: 'Hallo aus meinem Skript 👋' } }));
};
ws.onmessage = e => {
  const m = JSON.parse(e.data);
  if (m.event === 'heart') console.log('Puls', m.data.bpm);
  if (m.event === 'game') console.log('Welt', m.data.world, m.data.players, 'Spieler');
};</pre>
<pre># Python 3 (Paket „websockets“)
import asyncio, json, websockets
async def main():
    async with websockets.connect("ws://127.0.0.1:PORT/ws?token=TOKEN") as ws:
        await ws.send(json.dumps({"id": 1, "op": "action", "action": "status", "params": {"status": "join me"}}))
        print(await ws.recv())
asyncio.run(main())</pre>
<p>Die bisherige HTTP-Schnittstelle bleibt: <code>GET /api/state</code> und <code>POST /api/action</code> mit Kopfzeile <code>X-Nexus-Token</code> (genutzt vom Stream-Deck-Plugin).</p>

<h2 id="rules">📜 Regeln</h2>
<ul><li>Schlüssel sind geheim – nicht in öffentlichen Code, Streams oder Screenshots.</li><li>Keine Massen-Abfragen: Ergebnisse zwischenspeichern, Limits einhalten.</li>
<li>Posts über die API sind normale Community-Beiträge: gleiche Regeln, Melde- und Moderations-Werkzeuge.</li><li>Missbrauch führt zum Widerruf der Schlüssel bzw. zur Sperre in der Community.</li></ul>
</main></div>
<?php if ($newKey): ?><script>try { document.getElementById('nk').scrollIntoView({ block: 'center' }); } catch (e) {}</script><?php endif; ?>
</body></html>
