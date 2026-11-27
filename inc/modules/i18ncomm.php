<?php
/**
 * 🌍 Übersetzungs-Werkzeug für die Community (8.1).
 * Grundlage ist das Wörterbuch assets/i18n/site.tsv (DE → EN/FR/JA). Angemeldete schlagen Übersetzungen vor oder verbessern bestehende,
 * das Team gibt frei. Freigegebenes gilt sofort (i18n.php?js=1 → window.SiteDictExtra, überschreibt das Wörterbuch) und lässt sich
 * als TSV exportieren, um es dauerhaft in site.tsv zu übernehmen.
 */
const TC_LANGS = ['en' => ['🇬🇧', 'English'], 'fr' => ['🇫🇷', 'Français'], 'ja' => ['🇯🇵', '日本語']];
const TC_MAX_PENDING = 50;

function tc_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('i18ncomm_schema', '0') === '1') return;
    site_db()->exec('CREATE TABLE IF NOT EXISTS i18n_suggest (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, lang TEXT NOT NULL, src TEXT NOT NULL, text TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'pending\', note TEXT NOT NULL DEFAULT \'\', decided_by INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL, decided INTEGER NOT NULL DEFAULT 0)');
    set_setting('i18ncomm_schema', '1');
}

/** Wörterbuch aus site.tsv: [deutscher Text => ['en' => …, 'fr' => …, 'ja' => …]] */
function tc_sources(?string $file = null): array {
    $out = [];
    $file = $file ?? APP_ROOT . '/assets/i18n/site.tsv';
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ($line === '' || strpos($line, '//') === 0) continue;
        $c = explode("\t", $line);
        if (count($c) !== 4) continue;
        $c = array_map('trim', $c);
        $out[$c[0]] = ['en' => $c[1], 'fr' => $c[2], 'ja' => $c[3]];
    }
    return $out;
}

function tc_clean(string $s, int $max = 500): string {
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)));
    return mb_substr($s, 0, $max);
}

/** Vorschlag speichern → id. Neuer deutscher Quelltext ist erlaubt (wird mit Freigabe Teil des Wörterbuchs). */
function tc_suggest(int $uid, string $lang, string $src, string $text): int {
    tc_schema();
    if (!isset(TC_LANGS[$lang])) throw new InvalidArgumentException('Unbekannte Sprache.');
    $src = tc_clean($src, 300); $text = tc_clean($text);
    if ($src === '' || $text === '') throw new InvalidArgumentException('Bitte Original und Übersetzung ausfüllen.');
    if (strpos($src, "\t") !== false || strpos($text, "\t") !== false) throw new InvalidArgumentException('Tabulatoren sind nicht erlaubt.');
    if (substr_count($src, '#') !== substr_count($text, '#')) throw new InvalidArgumentException('Die Zahl-Platzhalter „#“ müssen in beiden Texten gleich oft vorkommen.');
    $db = site_db();
    $st = $db->prepare('SELECT COUNT(*) FROM i18n_suggest WHERE user_id = ? AND status = \'pending\''); $st->execute([$uid]);
    if ((int)$st->fetchColumn() >= TC_MAX_PENDING) throw new InvalidArgumentException('Du hast schon ' . TC_MAX_PENDING . ' offene Vorschläge – bitte warte auf die Prüfung.');
    $st = $db->prepare('SELECT id FROM i18n_suggest WHERE lang = ? AND src = ? AND text = ? AND status IN (\'pending\', \'approved\')'); $st->execute([$lang, $src, $text]);
    if ($st->fetchColumn()) throw new InvalidArgumentException('Genau diesen Vorschlag gibt es schon.');
    $db->prepare('INSERT INTO i18n_suggest (user_id, lang, src, text, created) VALUES (?, ?, ?, ?, ?)')->execute([$uid, $lang, $src, $text, time()]);
    return (int)$db->lastInsertId();
}

function tc_list(string $status = 'pending', int $limit = 200, int $uid = 0): array {
    tc_schema();
    $sql = 'SELECT s.*, u.username FROM i18n_suggest s LEFT JOIN users u ON u.id = s.user_id WHERE s.status = ?' . ($uid ? ' AND s.user_id = ?' : '') . ' ORDER BY s.id DESC LIMIT ' . max(1, min(1000, $limit));
    $st = site_db()->prepare($sql);
    $st->execute($uid ? [$status, $uid] : [$status]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Freigeben/ablehnen. Bei Freigabe ersetzt der neue Text ältere freigegebene für dieselbe Sprache + Quelle. */
function tc_decide(int $id, bool $approve, int $by, string $note = ''): void {
    tc_schema();
    $db = site_db();
    $st = $db->prepare('SELECT * FROM i18n_suggest WHERE id = ? AND status = \'pending\''); $st->execute([$id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new InvalidArgumentException('Vorschlag nicht gefunden oder schon entschieden.');
    if ($approve) $db->prepare('UPDATE i18n_suggest SET status = \'replaced\' WHERE lang = ? AND src = ? AND status = \'approved\'')->execute([$s['lang'], $s['src']]);
    $db->prepare('UPDATE i18n_suggest SET status = ?, note = ?, decided_by = ?, decided = ? WHERE id = ?')->execute([$approve ? 'approved' : 'rejected', tc_clean($note, 200), $by, time(), $id]);
    set_setting('i18ncomm_ver', (string)time());
    audit('Übersetzung ' . ($approve ? 'freigegeben' : 'abgelehnt'), $s['lang'] . ': ' . mb_substr($s['src'], 0, 60));
}

/** Freigegebene Texte: ['en' => [de => text], …] */
function tc_overlay(): array {
    tc_schema();
    $out = ['en' => [], 'fr' => [], 'ja' => []];
    foreach (site_db()->query('SELECT lang, src, text FROM i18n_suggest WHERE status = \'approved\' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r)
        if (isset($out[$r['lang']])) $out[$r['lang']][$r['src']] = $r['text'];
    return $out;
}

/** Fortschritt je Sprache (Wörterbuch + Freigaben). */
function tc_progress(array $src, array $ov): array {
    $keys = array_unique(array_merge(array_keys($src), ...array_map('array_keys', array_values($ov))));
    $out = [];
    foreach (TC_LANGS as $l => $_) {
        $n = 0;
        foreach ($keys as $k) if (($ov[$l][$k] ?? '') !== '' || ($src[$k][$l] ?? '') !== '') $n++;
        $out[$l] = ['done' => $n, 'total' => count($keys)];
    }
    return $out;
}

/** TSV-Zeilen für site.tsv: Wörterbuch + Freigaben zusammengeführt (Freigaben gewinnen). */
function tc_export_tsv(array $src, array $ov): string {
    $keys = array_unique(array_merge(array_keys($src), ...array_map('array_keys', array_values($ov))));
    $lines = ["// Wörterbuch der Webseite (8.1) – Deutsch<TAB>Englisch<TAB>Französisch<TAB>Japanisch. Bauen: php tools/i18n-build.php"];
    foreach ($keys as $k) {
        $row = [$k];
        foreach (array_keys(TC_LANGS) as $l) { $v = $ov[$l][$k] ?? ($src[$k][$l] ?? ''); $row[] = $v === '' ? $k : $v; }
        $lines[] = implode("\t", $row);
    }
    return implode("\n", $lines) . "\n";
}

function tc_js(): string {
    return 'window.SiteDictExtra = ' . json_encode(tc_overlay(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';';
}
