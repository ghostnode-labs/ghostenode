<?php
/**
 * 8.1: Wörterbuch der Webseite bauen: assets/i18n/site.tsv → assets/js/site-dict.js
 * Aufruf: php tools/i18n-build.php
 */
$root = dirname(__DIR__);
$out = ['en' => [], 'fr' => [], 'ja' => []];
$n = 0;
foreach (file($root . '/assets/i18n/site.tsv', FILE_IGNORE_NEW_LINES) as $i => $line) {
    if ($line === '' || strpos($line, '//') === 0) continue;   // Kommentar = „//“ ("#" ist der Zahlen-Platzhalter)
    $c = explode("\t", $line);
    if (count($c) !== 4) { fwrite(STDERR, 'Zeile ' . ($i + 1) . ": 4 Spalten erwartet\n"); exit(1); }
    [$de, $en, $fr, $ja] = array_map('trim', $c);
    $out['en'][$de] = $en; $out['fr'][$de] = $fr; $out['ja'][$de] = $ja; $n++;
}
file_put_contents($root . '/assets/js/site-dict.js', "/* automatisch erzeugt von tools/i18n-build.php aus assets/i18n/site.tsv */\nwindow.SiteDict = " . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n");
echo "✔ $n Einträge → assets/js/site-dict.js\n";
