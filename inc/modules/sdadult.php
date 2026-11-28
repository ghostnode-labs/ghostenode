<?php
/**
 * 🔞 Zweites Stream-Deck-Plugin „VRC Nexus 🔞“ – gibt es NUR hier auf der Webseite (nie im App-Release, nie auf öffentlichen Seiten).
 *
 * Quelle: App-Repo ghostnode-labs/vrc_nexus, apps/vrc-nexus/streamdeck-adult (dort mit npm test gegen die echte lokale Schnittstelle getestet).
 * `node streamdeck-adult/tools/sync-web.js <diese Arbeitskopie>` kopiert den .sdPlugin-Ordner nach clients/streamdeck-adult/
 * und legt das fertige Paket VRC-Nexus-18.streamDeckPlugin daneben. clients/ ist per .htaccess gesperrt (RedirectMatch 404 + eigene .htaccess).
 *
 * Ausgeliefert wird über nexusdeck.php – nur angemeldet, mit Web-Nexus-Recht, verbundenem VRChat-Konto, Verified 18+ UND NSFW-Schalter
 * (dieselbe Prüfung wie der 18+-Bereich, nx_nsfw_allowed). Das Paket wird beim Download aus der Quelle gebaut:
 * ZipArchive → eigener ZIP-Schreiber (zlib) → vorgebautes Paket (falls beides fehlt).
 * Protokoll: nur ein Zähler je Tag und Plugin-Version (keine Benutzer-ID, keine IP); Bremse je IP (Datei, siehe nw_rate_over).
 */
require_once __DIR__ . '/nexusweb.php';

const SDA_PLUGIN = 'de.ghostnode.vrcnexus.adult.sdPlugin';
const SDA_FILE = 'VRC-Nexus-18.streamDeckPlugin';
const SDA_RATE = 10;   // Downloads je Minute und IP

function sda_dir(): string { return (defined('SDA_DIR_TEST') ? SDA_DIR_TEST : APP_ROOT . '/clients/streamdeck-adult'); }
function sda_version(): string {
    $m = json_decode((string)@file_get_contents(sda_dir() . '/' . SDA_PLUGIN . '/manifest.json'), true);
    return is_array($m) && preg_match('/^[0-9.]{1,20}$/', (string)($m['Version'] ?? '')) ? (string)$m['Version'] : '';
}
/** Dateien der Quelle (relativ, sortiert) – nur normale Dateien, keine versteckten, keine Links */
function sda_files(): array {
    $base = sda_dir() . '/' . SDA_PLUGIN;
    if (!is_dir($base)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->isLink()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
        if (preg_match('#(^|/)\.#', $rel)) continue;
        $out[] = $rel;
    }
    sort($out, SORT_STRING);
    return $out;
}
/** Eigener ZIP-Schreiber (Deflate über zlib, feste Zeit → gleiche Datei bei gleichem Inhalt) */
function sda_zip_php(array $entries): string {
    $data = ''; $cd = ''; $n = 0;
    $dosDate = ((2026 - 1980) << 9) | (1 << 5) | 1;
    foreach ($entries as $name => $raw) {
        $comp = gzdeflate($raw, 9);
        $crc = crc32($raw);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 8, 0, $dosDate, $crc, strlen($comp), strlen($raw), strlen($name), 0);
        $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 8, 0, $dosDate, $crc, strlen($comp), strlen($raw), strlen($name), 0, 0, 0, 0, 0, strlen($data)) . $name;
        $data .= $local . $name . $comp;
        $n++;
    }
    return $data . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($cd), strlen($data), 0);
}
/** Paket bauen → [Bytes, Art]; Art: ziparchive | php | prebuilt. $force zum Testen der Rückfälle */
function sda_build(string $force = ''): array {
    $files = sda_files();
    if ($files && $force !== 'prebuilt') {
        $base = sda_dir() . '/' . SDA_PLUGIN;
        if (class_exists('ZipArchive') && $force === '') {
            $tmp = tempnam(sys_get_temp_dir(), 'sda');
            $z = new ZipArchive();
            if ($z->open($tmp, ZipArchive::OVERWRITE) === true) {
                foreach ($files as $rel) $z->addFile($base . '/' . $rel, SDA_PLUGIN . '/' . $rel);
                $z->close();
                $bin = (string)file_get_contents($tmp);
                @unlink($tmp);
                if ($bin !== '') return [$bin, 'ziparchive'];
            }
            @unlink($tmp);
        }
        if (function_exists('gzdeflate')) {
            $e = [];
            foreach ($files as $rel) $e[SDA_PLUGIN . '/' . $rel] = (string)file_get_contents($base . '/' . $rel);
            return [sda_zip_php($e), 'php'];
        }
    }
    $pre = sda_dir() . '/' . SDA_FILE;
    if (is_file($pre)) return [(string)file_get_contents($pre), 'prebuilt'];
    throw new RuntimeException('Plugin-Paket nicht gefunden.');
}
/** Darf der angemeldete Webseiten-Benutzer das Plugin laden? → [HTTP-Code, Grund] (gleiche Prüfung wie der 18+-Bereich) */
function sda_access(?array $user): array {
    if (!$user) return [403, 'Bitte anmelden.'];
    if (!in_array('vrcx.access', $user['permissions'] ?? [], true)) return [403, 'Kein Zugriff auf Nexus Web.'];
    try { $who = nxweb_who((int)$user['id']); }
    catch (Throwable $e) { return [403, $e->getMessage()]; }
    if (empty($who['_nsfw'])) return [403, 'Nur mit Verified 18+ und eingeschaltetem NSFW.'];
    return [200, ''];
}
/** Zähler je Tag (ohne personenbezogene Daten), 120 Tage */
function sda_count(string $ver): void {
    $d = json_decode(setting('sdadult_downloads', '{}'), true);
    if (!is_array($d)) $d = [];
    $k = date('Y-m-d') . '|' . $ver;
    $d[$k] = (int)($d[$k] ?? 0) + 1;
    $cut = date('Y-m-d', time() - 120 * 86400);
    foreach (array_keys($d) as $x) if (substr($x, 0, 10) < $cut) unset($d[$x]);
    set_setting('sdadult_downloads', json_encode($d));
}
/** Für die Oberfläche (nur im 18+-Bereich angezeigt): Version + Größe */
function sda_info(): array {
    $v = sda_version();
    return ['version' => $v, 'available' => $v !== '' || is_file(sda_dir() . '/' . SDA_FILE), 'file' => SDA_FILE];
}
