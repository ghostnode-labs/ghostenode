<?php
declare(strict_types=1);
/**
 * Download des zweiten Stream-Deck-Plugins (nur im 18+-Bereich von Nexus Web verlinkt, siehe inc/modules/sdadult.php).
 *   GET nexusdeck.php            → .streamDeckPlugin (angemeldet + Nexus Web + VRChat verbunden + Verified 18+ + NSFW an), sonst 403
 *   GET nexusdeck.php?info=1     → {"version", "available"} mit derselben Prüfung
 * Kein Weiterleiten zur Anmeldung (403 statt Login-Seite), keine Ausgabe von 🔞-Details an Gäste.
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/sdadult.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
$deny = function (int $code, string $msg) { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; };
if (!auth_configured()) $deny(404, 'Nicht gefunden.');
ip_block_check();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') $deny(405, 'Nur GET.');
if (nw_rate_over('sda|' . client_ip(), SDA_RATE)) $deny(429, 'Zu viele Anfragen – kurz warten.');
$user = current_user();
session_write_close();
[$code, $why] = sda_access($user);
if ($code !== 200) $deny($code, $user ? $why : 'Bitte anmelden.');
if (isset($_GET['info'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(sda_info(), JSON_UNESCAPED_UNICODE); exit; }
try { [$bin] = sda_build(); }
catch (Throwable $e) { $deny(404, 'Plugin-Paket nicht gefunden.'); }
sda_count(sda_version() ?: '?');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . SDA_FILE . '"');
header('Content-Length: ' . strlen($bin));
echo $bin;
