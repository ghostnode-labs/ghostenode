<?php
/**
 * 9.0: Prozessanzeige im Stil von htop für Admin → 🖥 Server (nur Anzeige, nie Prozesse beenden).
 *
 * Liest live aus /proc (Wurzel austauschbar → Tests mit Mock-Verzeichnis). CPU-% entsteht aus der Differenz zum letzten Abruf
 * derselben Person (kleine Datei in DATA_DIR/cache, nur Roh-Ticks). Ist /proc nicht lesbar (hidepid, open_basedir), kommt der
 * Schnappschuss „procs“, den der Root-Agent jede Minute in status.json schreibt – mit Hinweis „Stand: …“.
 */
require_once APP_ROOT . '/inc/core/common.php';

/** CPU-Zeilen aus /proc/stat → ['all' => [busy, total], 'cores' => [[busy, total], …], 'procs_running' => n] */
function pr_stat(string $root = '/proc'): ?array {
    $raw = @file_get_contents($root . '/stat');
    if (!is_string($raw) || $raw === '') return null;
    $out = ['all' => null, 'cores' => [], 'running' => 0];
    foreach (explode("\n", $raw) as $l) {
        if (preg_match('/^cpu(\d*)\s+(.+)$/', $l, $m)) {
            $v = array_map('intval', preg_split('/\s+/', trim($m[2])));
            $v = array_pad($v, 8, 0);
            $idle = $v[3] + $v[4];                                  // idle + iowait
            $total = array_sum(array_slice($v, 0, 8));              // ohne guest (steckt schon in user)
            if ($m[1] === '') $out['all'] = [$total - $idle, $total]; else $out['cores'][(int)$m[1]] = [$total - $idle, $total];
        } elseif (preg_match('/^procs_running\s+(\d+)/', $l, $m)) $out['running'] = (int)$m[1];
    }
    if (!$out['all']) return null;
    ksort($out['cores']);
    $out['cores'] = array_values($out['cores']);
    return $out;
}

/** /proc/meminfo → Bytes */
function pr_meminfo(string $root = '/proc'): array {
    $m = [];
    foreach ((array)@file($root . '/meminfo', FILE_IGNORE_NEW_LINES) as $l) if (preg_match('/^(\w+):\s+(\d+)/', (string)$l, $x)) $m[$x[1]] = (int)$x[2] * 1024;
    $total = $m['MemTotal'] ?? 0;
    $avail = $m['MemAvailable'] ?? (($m['MemFree'] ?? 0) + ($m['Buffers'] ?? 0) + ($m['Cached'] ?? 0));
    return ['total' => $total, 'used' => max(0, $total - $avail), 'swap_total' => $m['SwapTotal'] ?? 0, 'swap_used' => max(0, ($m['SwapTotal'] ?? 0) - ($m['SwapFree'] ?? 0))];
}

/** Benutzer-ID → Name aus passwd */
function pr_users(string $passwd = '/etc/passwd'): array {
    $u = [];
    foreach ((array)@file($passwd, FILE_IGNORE_NEW_LINES) as $l) { $p = explode(':', (string)$l); if (count($p) > 2 && ctype_digit($p[2])) $u[(int)$p[2]] = $p[0]; }
    return $u;
}

/** Geheimnisse in Befehlszeilen verbergen (z. B. --password=…, token=…, -p…) – die Anzeige ist nur für Admins, trotzdem nichts im Klartext */
function pr_mask(string $cmd): string {
    $cmd = preg_replace('/(--?(?:pass(?:word)?|pwd|token|secret|api[-_]?key|key|auth)(?:=|\s+))\S+/i', '$1•••', $cmd);
    $cmd = preg_replace('/\b((?:PASS(?:WORD)?|TOKEN|SECRET|API_?KEY)[A-Z_]*=)\S+/i', '$1•••', $cmd);
    return (string)preg_replace('#(://[^/\s:@]+:)[^@\s/]+@#', '$1•••@', $cmd);
}

/** einzelner Prozess aus /proc/<pid> → [pid, uid, comm, state, ticks (utime+stime), threads, rss (Seiten), start (Ticks nach Boot), cmd] */
function pr_proc(string $dir): ?array {
    $stat = @file_get_contents($dir . '/stat');
    if (!is_string($stat) || !preg_match('/^(\d+) \((.*)\) (\S) (.*)$/s', trim($stat), $m)) return null;   // Name kann Leerzeichen und Klammern enthalten
    $f = preg_split('/\s+/', $m[4]);   // ab Feld 4 (ppid) → Index 0
    $uid = null;
    foreach ((array)@file($dir . '/status', FILE_IGNORE_NEW_LINES) as $l) if (preg_match('/^Uid:\s+(\d+)/', (string)$l, $x)) { $uid = (int)$x[1]; break; }
    $cmd = (string)@file_get_contents($dir . '/cmdline');
    $cmd = pr_mask(trim(str_replace("\0", ' ', $cmd)));
    return ['pid' => (int)$m[1], 'uid' => $uid, 'comm' => $m[2], 'state' => $m[3], 'ticks' => (int)($f[10] ?? 0) + (int)($f[11] ?? 0),
            'threads' => (int)($f[16] ?? 1), 'start' => (int)($f[18] ?? 0), 'rss' => (int)($f[20] ?? 0), 'cmd' => $cmd !== '' ? $cmd : '[' . $m[2] . ']'];
}

/** Rohdaten jetzt: [time, stat, mem, uptime, load, procs[pid => proc]] oder null, wenn /proc nicht lesbar ist */
function pr_sample(string $root = '/proc'): ?array {
    $st = pr_stat($root);
    if (!$st) return null;
    $procs = [];
    foreach ((array)@scandir($root) as $d) {
        if (!ctype_digit((string)$d)) continue;
        $p = pr_proc($root . '/' . $d);
        if ($p) $procs[$p['pid']] = $p;
    }
    $load = preg_split('/\s+/', trim((string)@file_get_contents($root . '/loadavg')));
    return ['time' => microtime(true), 'stat' => $st, 'mem' => pr_meminfo($root), 'uptime' => (float)explode(' ', trim((string)@file_get_contents($root . '/uptime')))[0],
            'load' => [(float)($load[0] ?? 0), (float)($load[1] ?? 0), (float)($load[2] ?? 0)], 'procs' => $procs];
}

/**
 * Anzeige-Daten aus aktuellem und vorherigem Abruf (vorheriger darf fehlen → CPU 0 % bzw. Durchschnitt seit Start).
 * CPU-% wie htop: Anteil eines Kerns (100 % = ein Kern voll, max. Kerne × 100).
 */
function pr_view(array $cur, ?array $prev, array $users = [], int $hz = 100, int $page = 4096): array {
    $cores = [];
    foreach ($cur['stat']['cores'] as $i => [$busy, $total]) {
        $pb = $prev['stat']['cores'][$i] ?? null;
        $db = $pb ? $busy - $pb[0] : $busy; $dt = $pb ? $total - $pb[1] : $total;
        $cores[] = $dt > 0 ? round(max(0, min(100, $db / $dt * 100)), 1) : 0.0;
    }
    $n = max(1, count($cores));
    $allDt = $prev ? $cur['stat']['all'][1] - $prev['stat']['all'][1] : 0;
    $wall = $allDt > 0 ? $allDt / $n : 0;   // Ticks je Kern seit dem letzten Abruf
    $memTotal = max(1, (int)$cur['mem']['total']);
    $rows = []; $threads = 0;
    foreach ($cur['procs'] as $pid => $p) {
        $threads += $p['threads'];
        $old = $prev['procs'][$pid] ?? null;
        if ($wall > 0 && $old && $old['start'] === $p['start']) $cpu = ($p['ticks'] - $old['ticks']) / $wall * 100;
        else { $age = $cur['uptime'] - $p['start'] / $hz; $cpu = $age > 0 ? $p['ticks'] / $hz / $age * 100 : 0; }   // Durchschnitt seit Start
        $rss = $p['rss'] * $page;
        $rows[] = ['pid' => $pid, 'user' => $p['uid'] === null ? '?' : ($users[$p['uid']] ?? (string)$p['uid']), 'cpu' => round(max(0, min($n * 100, $cpu)), 1),
                   'mem' => round($rss / $memTotal * 100, 1), 'rss' => $rss, 'time' => max(0, (int)round($cur['uptime'] - $p['start'] / $hz)), 'state' => $p['state'],
                   'threads' => $p['threads'], 'cmd' => mb_substr($p['cmd'], 0, 200)];
    }
    return ['live' => true, 'at' => (int)$cur['time'], 'cores' => $cores, 'load' => $cur['load'], 'mem' => $cur['mem'], 'uptime' => (int)$cur['uptime'],
            'tasks' => count($rows), 'threads' => $threads, 'running' => (int)$cur['stat']['running'], 'rows' => $rows];
}

/** Sortieren, filtern, Top N (auch für den Schnappschuss des Agents) */
function pr_pick(array $rows, string $sort = 'cpu', string $q = '', int $limit = 30): array {
    $q = mb_strtolower(trim($q));
    if ($q !== '') $rows = array_values(array_filter($rows, fn($r) => str_contains(mb_strtolower($r['cmd'] . ' ' . $r['user'] . ' ' . $r['pid']), $q)));
    $key = ['cpu' => fn($a, $b) => [$b['cpu'], $b['mem']] <=> [$a['cpu'], $a['mem']], 'mem' => fn($a, $b) => [$b['rss'], $b['cpu']] <=> [$a['rss'], $a['cpu']],
            'pid' => fn($a, $b) => $a['pid'] <=> $b['pid'], 'name' => fn($a, $b) => strcasecmp($a['cmd'], $b['cmd']), 'time' => fn($a, $b) => $b['time'] <=> $a['time']][$sort] ?? null;
    if ($key) usort($rows, $key);
    return array_slice($rows, 0, max(1, min(100, $limit)));
}

/** vorherigen Abruf dieser Person laden/speichern (nur Ticks, keine Befehlszeilen) */
function pr_prev_file(int $uid): string { return data_path('cache/procs_prev_' . $uid . '.json'); }
function pr_prev_load(int $uid): ?array {
    $j = json_decode((string)@file_get_contents(pr_prev_file($uid)), true);
    if (!is_array($j) || microtime(true) - (float)($j['time'] ?? 0) > 120) return null;   // zu alt → Durchschnitt seit Start
    foreach ($j['procs'] as $pid => $p) $j['procs'][$pid] = ['ticks' => (int)$p[0], 'start' => (int)$p[1]];
    return $j;
}
function pr_prev_save(int $uid, array $cur): void {
    $p = [];
    foreach ($cur['procs'] as $pid => $x) $p[$pid] = [$x['ticks'], $x['start']];
    @file_put_contents(pr_prev_file($uid), json_encode(['time' => $cur['time'], 'stat' => $cur['stat'], 'procs' => $p]), LOCK_EX);
}

/** Antwort für die Admin-Seite: live oder Schnappschuss des Agents */
function pr_report(int $uid, string $sort, string $q, ?array $agentStatus, string $root = '/proc', string $passwd = '/etc/passwd'): array {
    $sort = in_array($sort, ['cpu', 'mem', 'pid', 'name', 'time'], true) ? $sort : 'cpu';
    $cur = pr_sample($root);
    if ($cur) {
        $v = pr_view($cur, pr_prev_load($uid), pr_users($passwd));
        pr_prev_save($uid, $cur);
        // nur der eigene Prozess sichtbar? → /proc ist mit hidepid eingeschränkt
        if ($v['tasks'] <= 2 && !empty($agentStatus['procs']['list'])) $cur = null;
        else { $v['rows'] = pr_pick($v['rows'], $sort, $q); return $v; }
    }
    $s = $agentStatus['procs'] ?? null;
    if (!is_array($s) || empty($s['list'])) return ['live' => false, 'error' => '/proc ist hier nicht lesbar (z. B. open_basedir oder hidepid), und der Server-Agent liefert noch keinen Schnappschuss.'];
    $mem = $agentStatus['mem'] ?? [];
    return ['live' => false, 'at' => (int)($s['time'] ?? $agentStatus['time'] ?? 0), 'cores' => array_map('floatval', (array)($s['cores'] ?? [])), 'load' => (array)($agentStatus['load'] ?? [0, 0, 0]),
            'mem' => ['total' => (int)($mem['total'] ?? 0), 'used' => max(0, (int)($mem['total'] ?? 0) - (int)($mem['avail'] ?? 0)),
                      'swap_total' => (int)($agentStatus['swap']['total'] ?? 0), 'swap_used' => max(0, (int)($agentStatus['swap']['total'] ?? 0) - (int)($agentStatus['swap']['free'] ?? 0))],
            'uptime' => (int)($agentStatus['uptime'] ?? 0), 'tasks' => (int)($s['tasks'] ?? 0), 'threads' => (int)($s['threads'] ?? 0), 'running' => (int)($s['running'] ?? 0),
            'rows' => pr_pick(array_map(fn($r) => ['pid' => (int)($r['pid'] ?? 0), 'user' => (string)($r['user'] ?? '?'), 'cpu' => (float)($r['cpu'] ?? 0), 'mem' => (float)($r['mem'] ?? 0),
                'rss' => (int)($r['rss'] ?? 0), 'time' => (int)($r['time'] ?? 0), 'state' => (string)($r['state'] ?? ''), 'threads' => (int)($r['threads'] ?? 1), 'cmd' => mb_substr((string)($r['cmd'] ?? ''), 0, 200)], (array)$s['list']), $sort, $q)];
}
