<?php
/**
 * 8.2.1: Schutz für JSON-Schnittstellen der App (nexusacct, nexuschat, nexuscomm, nexuswarn, nexuslic, toyrelay).
 * Hoster wie Plesk schalten display_errors oft ein – dann landen Warnungen/Deprecations vor dem JSON und die App
 * meldet „Antwort kein JSON“. Hier: Meldungen nur ins Log, fremde Ausgabe vor dem JSON verwerfen, und auch bei einem
 * fatalen Fehler (Zeitlimit, Speicher, fehlende Datei) eine JSON-Antwort statt einer leeren/HTML-Seite.
 */
function json_guard(): void {
    static $on = false;
    if ($on || PHP_SAPI === 'cli') return;
    $on = true;
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    ob_start();
    register_shutdown_function(function () {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) return;
        while (ob_get_level() > 0) @ob_end_clean();
        error_log('json_guard: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }
        echo '{"error":"Serverfehler auf der Webseite – bitte gleich noch einmal versuchen."}';
    });
}
/** vor dem JSON: alles verwerfen, was bis hierher ausgegeben wurde (BOM, Leerzeilen, Hinweise) */
function json_guard_clean(): void {
    if (ob_get_level() > 0 && ob_get_length()) ob_clean();
}
