<?php
/**
 * 🌐 Mehrere Domains und Weiterleitungen (8.1) – verwaltet unter Admin → System.
 *   Haupt-Domain: alle anderen eingetragenen Domains leiten (301) dorthin weiter – gleicher Pfad, gleiche Parameter.
 *   Neben-Domains mit Modus „ausliefern“ zeigen die Seite ohne Weiterleitung (z. B. eine alte Domain während des Umzugs).
 *   Kurz-Weiterleitungen: /discord → https://discord.gg/…, /dl → downloads.php … (301 oder 302, mit Zähler).
 * Unbekannte Domains (z. B. direkt per IP) bleiben unberührt. Gespeichert als JSON in der Einstellung „domains_cfg“.
 * Fehlende Pfade landen per .htaccess (ErrorDocument 404) in go.php, das die Kurz-Weiterleitungen auflöst.
 */
const DOM_MAX_REDIRECTS = 200;
const DOM_RESERVED = ['/inc', '/data', '/tools', '/clients', '/assets', '/api.php', '/admin.php', '/oauth.php', '/go.php', '/cron.php'];

function dom_host(string $h): string {
    $h = strtolower(trim($h));
    $h = preg_replace('#^https?://#', '', $h);
    $h = preg_replace('#[/:].*$#', '', $h);
    return rtrim($h, '.');
}
function dom_valid_host(string $h): bool { return (bool)preg_match('/^(?=.{3,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h); }

function dom_cfg(): array {
    $c = json_decode(function_exists('setting') ? setting('domains_cfg', '') : '', true);
    $c = is_array($c) ? $c : [];
    return ['main' => (string)($c['main'] ?? ''), 'https' => !isset($c['https']) || !empty($c['https']), 'domains' => (array)($c['domains'] ?? []), 'redirects' => (array)($c['redirects'] ?? [])];
}

/** Eingaben aus dem Admin prüfen → gültige Konfiguration (wirft bei Fehlern). */
function dom_clean(array $in): array {
    $main = dom_host((string)($in['main'] ?? ''));
    if ($main !== '' && !dom_valid_host($main)) throw new InvalidArgumentException('Haupt-Domain ungültig: ' . $main);
    $domains = [];
    foreach ((array)($in['domains'] ?? []) as $d) {
        $h = dom_host((string)($d['host'] ?? ''));
        if ($h === '' || $h === $main) continue;
        if (!dom_valid_host($h)) throw new InvalidArgumentException('Domain ungültig: ' . $h);
        $domains[$h] = ['host' => $h, 'mode' => ($d['mode'] ?? '') === 'serve' ? 'serve' : 'redirect'];
    }
    if ($domains && $main === '') throw new InvalidArgumentException('Bitte zuerst eine Haupt-Domain eintragen.');
    $reds = [];
    foreach ((array)($in['redirects'] ?? []) as $r) {
        $from = '/' . trim(strtolower((string)($r['from'] ?? '')), '/ ');
        $to = trim((string)($r['to'] ?? ''));
        if ($from === '/' && $to === '') continue;
        if (!preg_match('#^/[a-z0-9][a-z0-9._/-]{0,79}$#', $from)) throw new InvalidArgumentException('Kurz-Pfad ungültig: ' . $from . ' (a–z, 0–9, . _ - /)');
        foreach (DOM_RESERVED as $res) if ($from === $res || strpos($from, $res . '/') === 0) throw new InvalidArgumentException('Pfad ist reserviert: ' . $from);
        if (is_file(APP_ROOT . $from)) throw new InvalidArgumentException('Diesen Pfad gibt es schon als Datei: ' . $from);
        if (!preg_match('#^(https://[^\s<>"]{3,500}|/?[A-Za-z0-9_-]+\.php(\?[^\s<>"]{0,300})?)$#', $to)) throw new InvalidArgumentException('Ziel muss mit https:// beginnen oder eine Seite wie downloads.php sein: ' . $to);
        if (isset($reds[$from])) throw new InvalidArgumentException('Pfad doppelt: ' . $from);
        $reds[$from] = ['from' => $from, 'to' => $to, 'code' => (int)($r['code'] ?? 302) === 301 ? 301 : 302, 'hits' => (int)($r['hits'] ?? 0)];
        if (count($reds) > DOM_MAX_REDIRECTS) throw new InvalidArgumentException('Höchstens ' . DOM_MAX_REDIRECTS . ' Weiterleitungen.');
    }
    return ['main' => $main, 'https' => !empty($in['https']), 'domains' => array_values($domains), 'redirects' => array_values($reds)];
}
function dom_save(array $cfg): void {
    set_setting('domains_cfg', json_encode(dom_clean($cfg), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** Kern (ohne Seiteneffekte): Wohin mit dieser Anfrage? → ['code' => 301|302, 'url' => …, 'from' => Kurz-Pfad|null] oder null. */
function dom_resolve(array $cfg, string $host, string $uri): ?array {
    $host = dom_host($host);
    $path = (string)parse_url($uri, PHP_URL_PATH);
    $scheme = $cfg['https'] ? 'https' : 'http';
    if ($cfg['main'] !== '' && $host !== $cfg['main']) {
        foreach ($cfg['domains'] as $d) {
            if ($d['host'] === $host && $d['mode'] === 'redirect') return ['code' => 301, 'url' => $scheme . '://' . $cfg['main'] . ($uri === '' ? '/' : $uri), 'from' => null];
        }
    }
    $p = strtolower(rtrim($path, '/'));
    foreach ($cfg['redirects'] as $r) {
        if ($p === $r['from']) {
            $to = $r['to'];
            if (strpos($to, 'https://') !== 0) $to = '/' . ltrim($to, '/');
            return ['code' => $r['code'], 'url' => $to, 'from' => $r['from']];
        }
    }
    return null;
}

/** Aufruf-Zähler einer Kurz-Weiterleitung (Fehler egal – Weiterleiten geht vor). */
function dom_hit(string $from): void {
    try {
        $c = dom_cfg();
        foreach ($c['redirects'] as &$r) if ($r['from'] === $from) $r['hits'] = (int)$r['hits'] + 1;
        unset($r);
        set_setting('domains_cfg', json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) { /* egal */ }
}

/** Früh in jeder Seite: Neben-Domains auf die Haupt-Domain umleiten. */
function dom_guard(): void {
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) return;
    try {
        $c = dom_cfg();
        if ($c['main'] === '' || !$c['domains']) return;
        $to = dom_resolve(['main' => $c['main'], 'https' => $c['https'], 'domains' => $c['domains'], 'redirects' => []], (string)$_SERVER['HTTP_HOST'], (string)($_SERVER['REQUEST_URI'] ?? '/'));
        if ($to) { header('Location: ' . $to['url'], true, $to['code']); exit; }
    } catch (Throwable $e) { /* nie die Seite blockieren */ }
}
