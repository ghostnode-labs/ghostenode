<?php
/**
 * Admin-Zentrale – Bereich „🖥 Server“ (Recht system.manage): Server-Dashboard für den GhostNode-Root-Server.
 * Liest den Status, den der Root-Agent (ghostnode-root.sh → /usr/local/sbin/ghostnode-agent) jede Minute schreibt,
 * und legt Aufträge ab, die der Agent als root ausführt – nur fest erlaubte (Dienst neu starten, Backup, Update, Prüfung,
 * Zertifikate, Wächter). Die Webseite selbst bekommt keine Root-Rechte. Auf Plesk/Webhosting ohne Agent: Hinweis.
 */

const SV_ADMIN_TABS = ['server' => ['🖥', 'Server', 'system.manage']];
const SV_DIR = '/var/lib/ghostnode';
const SV_SERVICES = ['nginx', 'apache2', 'php-fpm', 'mariadb', 'ghostnode-live', 'ghostnode-bot', 'ghostnode-hr', 'coturn', 'mumble-server', 'vsftpd', 'cron', 'fail2ban'];
const SV_ACTIONS = ['backup' => '💾 Backup jetzt', 'update' => '⬆ Alles aktualisieren (GitHub)', 'check' => '🩺 Prüfung', 'certs' => '🔒 Zertifikate erneuern', 'watchdog' => '🐕 Wächter jetzt',
    'apt_upgrade' => '🧩 System-Updates einspielen', 'reboot' => '🔁 Server neu starten'];
/** Aufträge mit Argument (geprüft hier und noch einmal im Agent) */
const SV_ARG_ACTIONS = ['ssh_key_add', 'ssh_key_del', 'ssh_password', 'f2b_unban', 'logs'];
const SV_LABELS = ['nginx' => 'nginx (Reverse Proxy)', 'apache2' => 'Apache (Webseite)', 'php-fpm' => 'PHP-FPM', 'mariadb' => 'MariaDB (Datenbank)',
    'ghostnode-live' => 'Live-Dienst (Echtzeit)', 'ghostnode-bot' => 'Discord-Bot', 'ghostnode-hr' => 'HypeRate-Weiterleitung', 'coturn' => 'TURN (Anrufe)', 'mumble-server' => 'Mumble (Voice)',
    'vsftpd' => 'Poster-FTPS', 'cron' => 'Cron', 'fail2ban' => 'fail2ban'];

/** Status des Agents oder null (kein Root-Server / Agent läuft nicht) */
function sv_status(): ?array {
    $f = SV_DIR . '/status.json';
    if (!@is_readable($f)) return null;
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : null;
}
/** letzte Aufträge (neueste zuerst) */
function sv_results(int $n = 12): array {
    $out = [];
    foreach ((array)@glob(SV_DIR . '/results/*.json') as $f) { $j = json_decode((string)@file_get_contents($f), true); if (is_array($j)) $out[] = $j; }
    usort($out, fn($a, $b) => ($b['started'] ?? 0) <=> ($a['started'] ?? 0));
    return array_slice($out, 0, $n);
}
/** wartende Aufträge (noch nicht vom Agent abgeholt) */
function sv_pending(): array {
    $out = [];
    foreach ((array)@glob(SV_DIR . '/requests/*.json') as $f) { $j = json_decode((string)@file_get_contents($f), true); if (is_array($j)) $out[] = $j; }
    return $out;
}
function sv_arg_ok(string $action, string $arg): bool {
    switch ($action) {
        case 'ssh_key_add': return (bool)preg_match('#^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com) [A-Za-z0-9+/=]{40,1000}( [\x20-\x7e]{0,100})?$#', $arg);
        case 'ssh_key_del': return (bool)preg_match('#^SHA256:[A-Za-z0-9+/]{43}$#', $arg);
        case 'ssh_password': return in_array($arg, ['on', 'off'], true);
        case 'f2b_unban': return (bool)filter_var($arg, FILTER_VALIDATE_IP);
        case 'logs': return in_array($arg, SV_SERVICES, true);
    }
    return $arg === '';
}
function sv_request(string $action, string $arg = ''): string {
    $arg = trim(preg_replace('/\s+/', ' ', $arg));
    $ok = isset(SV_ACTIONS[$action]) || in_array($action, SV_ARG_ACTIONS, true) || (strpos($action, 'restart:') === 0 && in_array(substr($action, 8), SV_SERVICES, true));
    if (!$ok) throw new InvalidArgumentException('Unbekannter Auftrag.');
    if (!sv_arg_ok($action, $arg)) throw new InvalidArgumentException($action === 'ssh_key_add' ? 'Das ist kein öffentlicher SSH-Schlüssel (Form: ssh-ed25519 AAAA… name). Nie den privaten Schlüssel einfügen!' : 'Ungültige Angabe.');
    if (!@is_dir(SV_DIR . '/requests') || !@is_writable(SV_DIR . '/requests')) throw new InvalidArgumentException('Aufträge gehen nur auf dem GhostNode-Root-Server (Agent nicht gefunden).');
    foreach (sv_pending() as $p) if (($p['action'] ?? '') === $action && ($p['arg'] ?? '') === $arg) return 'Auftrag wartet schon – der Server holt ihn innerhalb einer Minute ab.';
    $u = current_user();
    $id = bin2hex(random_bytes(8));
    $f = SV_DIR . '/requests/' . $id . '.json';
    if (@file_put_contents($f . '.tmp', json_encode(['id' => $id, 'action' => $action, 'arg' => $arg, 'by' => (string)($u['username'] ?? '?'), 'ts' => time()])) === false || !@rename($f . '.tmp', $f))
        throw new InvalidArgumentException('Auftrag konnte nicht abgelegt werden.');
    @chmod($f, 0660);
    $shown = $action === 'ssh_key_add' ? implode(' ', array_slice(explode(' ', $arg), 2)) ?: 'Schlüssel' : $arg;
    audit('Server: Auftrag', $action . ($shown !== '' ? ' ' . $shown : ''), in_array($action, ['update', 'reboot', 'apt_upgrade', 'ssh_key_add', 'ssh_key_del', 'ssh_password', 'f2b_unban'], true) || strpos($action, 'restart:') === 0 ? 'security' : 'info');
    return 'Auftrag angenommen – der Server führt ihn innerhalb einer Minute aus. Ergebnis erscheint unten.';
}

function admin_server_post(string $act, array $in): string {
    if ($act === 'sv_do') return sv_request((string)($in['do'] ?? ''), (string)($in['arg'] ?? ''));
    throw new InvalidArgumentException('Unbekannte Aktion.');
}

function sv_bytes(float $b): string { foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) { if ($b < 1024 || $u === 'TB') return ($u === 'B' ? (int)$b : number_format($b, 1, ',', '.')) . ' ' . $u; $b /= 1024; } return ''; }
function sv_dur(int $s): string { $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60); return ($d ? $d . ' T ' : '') . ($d || $h ? $h . ' h ' : '') . $m . ' min'; }
function sv_bar(float $pct): string {
    $pct = max(0, min(100, $pct)); $c = $pct >= 90 ? '#ff5c5c' : ($pct >= 75 ? 'var(--accent-gold)' : 'var(--accent-cyan)');
    return '<div class="sv-bar"><i style="width:' . round($pct, 1) . '%;background:' . $c . '"></i></div>';
}

function admin_server_view(): void {
    $s = sv_status();
    $res = sv_results(); $pend = sv_pending();
    $when = fn($t) => $t ? date('d.m. H:i', (int)$t) : '–';
    $btn = function (string $do, string $label, string $confirm = '', string $arg = '') {
        return '<form method="POST" style="display:inline"' . ($confirm ? ' onsubmit="return confirm(' . e(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>'
            . '<input type="hidden" name="tab" value="server"><input type="hidden" name="action" value="sv_do"><input type="hidden" name="do" value="' . e($do) . '">'
            . ($arg !== '' ? '<input type="hidden" name="arg" value="' . e($arg) . '">' : '')
            . '<button class="btn btn-sm">' . e($label) . '</button></form>';
    };
    ?>
    <style>
        .sv-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px}
        .sv-bar{height:8px;background:rgba(255,255,255,.08);border-radius:4px;overflow:hidden;margin:4px 0 10px}.sv-bar i{display:block;height:100%}
        .sv-k{display:flex;justify-content:space-between;gap:8px;font-size:.85rem}.sv-k span{color:var(--text-muted)}
        .sv-t{width:100%;border-collapse:collapse;font-size:.84rem}.sv-t td{padding:5px 4px;border-bottom:1px solid rgba(56,213,230,.12);vertical-align:middle}
        .sv-ok{color:var(--accent-green,#51e57e)}.sv-bad{color:#ff5c5c}.sv-warn{color:var(--accent-gold)}
        .sv-log{font-family:monospace;font-size:.75rem;white-space:pre-wrap;max-height:280px;overflow:auto;background:rgba(0,0,0,.25);padding:8px;margin:6px 0 0}
        .sv-actions{display:flex;flex-wrap:wrap;gap:6px}
    </style>
    <?php if (!$s): ?>
        <div class="panel"><div class="panel-title"><span>// Server</span></div>
            <p>Das Server-Dashboard gibt es auf dem <b>GhostNode-Root-Server</b>. Der Agent schreibt jede Minute den Status, und diese Seite liest ihn.</p>
            <p class="muted">Hier wurde kein Status gefunden (<code><?= e(SV_DIR) ?>/status.json</code>). Auf Plesk oder einem normalen Webhosting steht er nicht zur Verfügung.
                Auf dem Root-Server richtest du ihn so ein: <code>bash /root/ghostenode/server/ghostnode-root.sh update</code></p></div>
        <?php return; endif;
    $age = time() - (int)($s['time'] ?? 0);
    $mem = $s['mem'] ?? ['total' => 0, 'avail' => 0]; $memUsed = max(0, $mem['total'] - $mem['avail']);
    $disk = $s['disk'] ?? ['total' => 0, 'used' => 0];
    $cpus = max(1, (int)($s['cpus'] ?? 1)); $load = $s['load'] ?? [0, 0, 0];
    $cert = $s['cert'] ?? null; $certDays = $cert ? intdiv((int)$cert['until'] - time(), 86400) : null;
    $down = array_filter($s['services'] ?? [], fn($x) => empty($x['active']));
    ?>
    <?php if ($age > 180): ?><p class="flash err">⚠ Der Status ist <?= e(sv_dur($age)) ?> alt – läuft der Agent? (Cron: <code>* * * * * root /usr/local/sbin/ghostnode-agent</code>)</p><?php endif; ?>
    <?php if ($down): ?><p class="flash err">✖ Nicht aktiv: <?= e(implode(', ', array_map(fn($x) => SV_LABELS[$x['name']] ?? $x['name'], $down))) ?> – der Wächter startet Dienste nach 2 Fehlversuchen selbst neu, oder unten „↻“.</p><?php endif; ?>
    <?php if (!empty($s['reboot_required'])): ?><p class="flash err">🔁 Ein Sicherheitsupdate braucht einen Neustart des Servers (per SSH: <code>reboot</code>).</p><?php endif; ?>

    <div class="sv-grid">
        <div class="panel"><div class="panel-title"><span>// System</span></div>
            <div class="sv-k"><span>Server</span><b><?= e($s['host'] ?? '') ?></b></div>
            <div class="sv-k"><span>System</span><b><?= e($s['os'] ?? '') ?></b></div>
            <div class="sv-k"><span>Läuft seit</span><b><?= e(sv_dur((int)($s['uptime'] ?? 0))) ?></b></div>
            <div class="sv-k"><span>Rolle</span><b><?= e($s['role'] ?? '') ?> · <?= e($s['domain'] ?? '') ?></b></div>
            <div class="sv-k" style="margin-top:10px"><span>CPU-Last (<?= $cpus ?> Kerne)</span><b><?= e(implode(' · ', array_map(fn($l) => number_format((float)$l, 2, ',', ''), $load))) ?></b></div>
            <?= sv_bar((float)$load[0] / $cpus * 100) ?>
            <div class="sv-k"><span>Arbeitsspeicher</span><b><?= e(sv_bytes($memUsed)) ?> / <?= e(sv_bytes($mem['total'])) ?></b></div>
            <?= sv_bar($mem['total'] ? $memUsed / $mem['total'] * 100 : 0) ?>
            <div class="sv-k"><span>Festplatte /</span><b><?= e(sv_bytes($disk['used'])) ?> / <?= e(sv_bytes($disk['total'])) ?></b></div>
            <?= sv_bar($disk['total'] ? $disk['used'] / $disk['total'] * 100 : 0) ?>
            <div class="sv-k"><span>Paket-Updates offen</span><b><?= isset($s['apt_upgradable']) && $s['apt_upgradable'] >= 0 ? (int)$s['apt_upgradable'] . ' (Sicherheitsupdates laufen automatisch)' : '–' ?></b></div>
            <div class="sv-k"><span>HTTPS-Zertifikat</span><b class="<?= $certDays === null ? 'sv-warn' : ($certDays < 14 ? 'sv-bad' : 'sv-ok') ?>"><?= $certDays === null ? 'noch keins' : 'gültig bis ' . e(date('d.m.Y', (int)$cert['until'])) . ' (' . $certDays . ' Tage)' ?></b></div>
            <p class="muted small">Stand: <?= e(date('H:i:s', (int)$s['time'])) ?> · aktualisiert sich jede Minute</p>
        </div>

        <div class="panel"><div class="panel-title"><span>// Dienste</span></div>
            <table class="sv-t">
            <?php foreach ($s['services'] ?? [] as $x): ?>
                <tr><td><?= !empty($x['active']) ? '<span class="sv-ok">●</span>' : '<span class="sv-bad">●</span>' ?> <?= e(SV_LABELS[$x['name']] ?? $x['name']) ?></td>
                    <td class="<?= !empty($x['active']) ? 'sv-ok' : 'sv-bad' ?>"><?= !empty($x['active']) ? 'läuft' : 'aus' ?></td>
                    <td style="text-align:right"><?= $btn('restart:' . $x['name'], '↻', 'Dienst „' . (SV_LABELS[$x['name']] ?? $x['name']) . '“ neu starten?') ?></td></tr>
            <?php endforeach; ?>
            </table>
        </div>

        <div class="panel"><div class="panel-title"><span>// Aktionen</span></div>
            <div class="sv-actions">
                <?php $confirms = ['update' => 'Webseite, Bot, HypeRate und Installer von GitHub aktualisieren? Dienste starten dabei kurz neu.',
                    'apt_upgrade' => 'Alle verfügbaren System-Updates jetzt einspielen? Das kann einige Minuten dauern.',
                    'reboot' => 'Server in 1 Minute neu starten? Webseite, Bot und alle Dienste sind dann kurz nicht erreichbar.'];
                foreach (SV_ACTIONS as $k => $label) echo $btn($k, $label, $confirms[$k] ?? ''); ?>
            </div>
            <?php if ($pend): ?><p class="sv-warn small" style="margin-top:10px">⏳ Wartet: <?= e(implode(', ', array_map(fn($p) => (string)($p['action'] ?? ''), $pend))) ?> – wird innerhalb einer Minute ausgeführt.</p><?php endif; ?>
            <div class="panel-title" style="margin-top:14px"><span>// Versionen</span></div>
            <table class="sv-t">
            <?php foreach (['webseite' => 'Webseite', 'bot' => 'Discord-Bot', 'hyperate' => 'HypeRate + Live', 'installer' => 'Installer'] as $k => $n): $v = $s['versions'][$k] ?? null; ?>
                <tr><td><?= e($n) ?></td><td><?= $v ? '<code>' . e($v['hash']) . '</code> <span class="muted small">' . e(date('d.m.Y', strtotime($v['date']))) . '</span>' : '–' ?></td></tr>
            <?php endforeach; ?>
            </table>
            <p class="muted small">Auto-Update holt jede Nacht um 04:41 alles von GitHub.</p>
        </div>

        <div class="panel"><div class="panel-title"><span>// Geplante Aufgaben</span></div>
            <table class="sv-t">
            <?php $names = ['aufgaben' => 'Aufgaben der Webseite (5 min)', 'waechter' => 'Wächter (5 min)', 'backup' => 'Backup (03:17)', 'update' => 'Auto-Update (04:41)'];
            foreach ($s['cron'] ?? [] as $c): if (strpos($c['name'], 'web-') === 0) continue; ?>
                <tr><td><?= e($names[$c['name']] ?? $c['name']) ?></td><td><?= e($when($c['time'])) ?></td><td class="<?= (int)$c['rc'] === 0 ? 'sv-ok' : 'sv-bad' ?>"><?= (int)$c['rc'] === 0 ? '✔' : '✖ ' . (int)$c['rc'] ?></td></tr>
            <?php endforeach; ?>
            </table>
            <div class="panel-title" style="margin-top:14px"><span>// Backups (/var/backups/ghostnode)</span></div>
            <table class="sv-t">
            <?php foreach (array_slice($s['backups'] ?? [], 0, 8) as $b): ?>
                <tr><td><code><?= e($b['name']) ?></code></td><td><?= $b['size'] ? e(sv_bytes($b['size'])) : 'Ordner' ?></td><td><?= e($when($b['time'])) ?></td></tr>
            <?php endforeach; if (empty($s['backups'])): ?><tr><td class="muted">noch keine</td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <?php $ssh = $s['ssh'] ?? null; ?>
    <div class="sv-grid" style="margin-top:12px">
        <div class="panel"><div class="panel-title"><span>// SSH-Zugang</span><?php if ($ssh && empty($ssh['error'])): ?><span class="<?= !empty($ssh['active']) ? 'sv-ok' : 'sv-bad' ?>"><?= !empty($ssh['active']) ? '● läuft' : '● aus' ?></span><?php endif; ?></div>
            <?php if (!$ssh || !empty($ssh['error'])): ?><p class="muted">Noch keine SSH-Angaben – nach dem nächsten Update des Servers verfügbar.</p><?php else: ?>
            <div class="sv-k"><span>Port</span><b><?= e($ssh['port'] ?: '22') ?></b></div>
            <div class="sv-k"><span>Anmeldung als root</span><b><?= e(['yes' => 'erlaubt', 'prohibit-password' => 'nur mit Schlüssel', 'without-password' => 'nur mit Schlüssel', 'no' => 'aus'][$ssh['root']] ?? ($ssh['root'] ?: '?')) ?></b></div>
            <div class="sv-k"><span>Passwort-Login</span><b class="<?= $ssh['password'] === 'no' ? 'sv-ok' : 'sv-warn' ?>"><?= $ssh['password'] === 'no' ? 'aus (nur Schlüssel) ✔' : 'an' ?></b></div>
            <div class="sv-k"><span>Fehlversuche (24 h)</span><b class="<?= (int)($ssh['failed_24h'] ?? 0) > 50 ? 'sv-warn' : '' ?>"><?= $ssh['failed_24h'] === null ? '–' : (int)$ssh['failed_24h'] ?></b></div>
            <div class="sv-actions" style="margin:10px 0">
                <?= $ssh['password'] === 'no' ? $btn('ssh_password', '🔓 Passwort-Login wieder erlauben', 'Passwort-Login per SSH wieder erlauben?', 'on')
                    : $btn('ssh_password', '🔐 Passwort-Login abschalten (nur Schlüssel)', 'Passwort-Login abschalten? Danach geht SSH nur noch mit einem hinterlegten Schlüssel. Vorher prüfen, dass dein Schlüssel funktioniert!', 'off') ?>
            </div>
            <p class="muted small">Tipp: mit Schlüssel anmelden und den Passwort-Login abschalten – das stoppt die meisten Angriffe. Notfall-Zugang zur Webseite: <code>ghostnode-root.sh login …</code></p>
            <?php endif; ?>
        </div>

        <div class="panel"><div class="panel-title"><span>// SSH-Schlüssel (root)</span></div>
            <?php if ($ssh && empty($ssh['error'])): ?>
            <table class="sv-t">
            <?php foreach ($ssh['keys'] ?? [] as $k): ?>
                <tr><td><b><?= e($k['comment'] ?: 'ohne Namen') ?></b><br><code class="small" style="word-break:break-all"><?= e($k['type'] . ' ' . $k['fp']) ?></code></td>
                    <td style="text-align:right"><?= $btn('ssh_key_del', '✕', 'Diesen SSH-Schlüssel entfernen? Wer ihn benutzt, kommt danach nicht mehr per SSH rein.', $k['fp']) ?></td></tr>
            <?php endforeach; if (empty($ssh['keys'])): ?><tr><td class="muted">Noch kein Schlüssel hinterlegt.</td></tr><?php endif; ?>
            </table>
            <form method="POST" style="margin-top:10px"><input type="hidden" name="tab" value="server"><input type="hidden" name="action" value="sv_do"><input type="hidden" name="do" value="ssh_key_add">
                <label class="field" for="sv-key">Öffentlichen Schlüssel hinzufügen (Inhalt von <code>id_ed25519.pub</code>)</label>
                <textarea id="sv-key" name="arg" rows="3" placeholder="ssh-ed25519 AAAA… dein-pc" style="width:100%;font-family:monospace;font-size:.78rem"></textarea>
                <button class="btn btn-sm" style="margin-top:6px">＋ Schlüssel hinzufügen</button></form>
            <p class="muted small">Schlüssel erzeugen (Windows/Mac/Linux): <code>ssh-keygen -t ed25519</code> – dann den Inhalt der <b>.pub</b>-Datei einfügen. Den privaten Schlüssel nie hier eintragen.</p>
            <?php else: ?><p class="muted">Nach dem nächsten Update des Servers verfügbar.</p><?php endif; ?>
        </div>

        <div class="panel"><div class="panel-title"><span>// Gesperrte IPs (fail2ban)</span></div>
            <?php $f2b = $s['f2b'] ?? []; if (!$f2b): ?><p class="muted">fail2ban meldet nichts.</p><?php endif; ?>
            <?php foreach ($f2b as $j): ?>
                <div class="sv-k"><span><?= e($j['jail']) ?></span><b><?= count($j['banned']) ?> gesperrt · <?= (int)$j['total'] ?> insgesamt</b></div>
                <?php foreach ($j['banned'] as $ip): ?><div class="sv-k" style="padding-left:12px"><code><?= e($ip) ?></code><?= $btn('f2b_unban', 'entsperren', 'IP ' . $ip . ' entsperren?', $ip) ?></div><?php endforeach; ?>
            <?php endforeach; ?>
            <div class="panel-title" style="margin-top:14px"><span>// Firewall (ufw)</span></div>
            <div class="sv-log" style="max-height:180px"><?= e(implode("\n", $s['ufw'] ?? [])) ?: 'keine Angaben' ?></div>
        </div>

        <div class="panel"><div class="panel-title"><span>// Protokolle ansehen</span></div>
            <p class="muted small">Die letzten 80 Zeilen eines Dienstes – erscheinen unten bei „Aufträge“.</p>
            <div class="sv-actions"><?php foreach ($s['services'] ?? [] as $x) echo $btn('logs', '📜 ' . (SV_LABELS[$x['name']] ?? $x['name']), '', $x['name']); ?></div>
            <div class="panel-title" style="margin-top:14px"><span>// Letzte SSH-Anmeldungen</span></div>
            <div class="sv-log" style="max-height:200px"><?= e(implode("\n", $ssh['logins'] ?? [])) ?: 'keine Angaben' ?></div>
        </div>
    </div>

    <div class="panel" style="margin-top:12px"><div class="panel-title"><span>// Aufträge aus dem Admin-Panel</span></div>
        <?php if (!$res): ?><p class="muted">Noch keine.</p><?php endif; ?>
        <?php foreach ($res as $r): $st = (string)($r['state'] ?? ''); ?>
            <details<?= $st === 'läuft' ? ' open' : '' ?>><summary><b class="<?= $st === 'ok' ? 'sv-ok' : ($st === 'läuft' ? 'sv-warn' : 'sv-bad') ?>"><?= $st === 'ok' ? '✔' : ($st === 'läuft' ? '⏳' : '✖') ?></b>
                <?= e((string)($r['action'] ?? '')) ?><?= !empty($r['arg']) ? ' ' . e((string)$r['arg']) : '' ?> · <?= e($when($r['started'] ?? 0)) ?> · von <?= e((string)($r['by'] ?? '')) ?><?= isset($r['finished']) ? ' · ' . ((int)$r['finished'] - (int)$r['started']) . ' s' : '' ?></summary>
                <?php if (!empty($r['out'])): ?><div class="sv-log"><?= e((string)$r['out']) ?></div><?php endif; ?></details>
        <?php endforeach; ?>
    </div>

    <div class="panel" style="margin-top:12px"><div class="panel-title"><span>// Protokoll (/var/log/ghostnode-cron.log)</span></div>
        <div class="sv-log"><?= e(implode("\n", $s['cronlog'] ?? [])) ?: 'leer' ?></div>
    </div>
    <?php if ($pend || array_filter($res, fn($r) => ($r['state'] ?? '') === 'läuft')): ?><script>setTimeout(() => location.reload(), 15000);</script><?php endif; ?>
    <?php
}
