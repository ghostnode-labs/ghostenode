<?php
// 9.0: Admin-Postfach – was gerade Aufmerksamkeit braucht (Prüfungen, neue Konten, Sicherheit, Backup, Dienste, Links)
if (!function_exists('aq_items')) {
    /** Liste offener Punkte: [[stufe ok|info|warn|bad, icon, text, link]] – jeder Bereich nur mit passendem Recht */
    function aq_items(): array {
        $db = site_db(); $out = [];
        $since = fn(int $sec) => date('Y-m-d H:i:s', time() - $sec);
        if (can('market.manage')) {
            try {
                require_once APP_ROOT . '/inc/modules/market.php';
                require_once APP_ROOT . '/inc/modules/themes_market.php';
                $p = 0; foreach (mk_load() as $pl) foreach ((array)($pl['versions'] ?? []) as $v) if (($v['status'] ?? '') === 'pending') $p++;
                $t = count(array_filter(tm_load(), fn($x) => ($x['status'] ?? '') === 'pending'));
                $out[] = [$p + $t ? 'warn' : 'ok', '🧩', $p + $t ? ($p ? $p . ' Plugin-Version(en)' : '') . ($p && $t ? ' und ' : '') . ($t ? $t . ' Theme(s)' : '') . ' warten auf Prüfung' : 'Marktplatz: nichts zu prüfen', 'market.php#mk-review'];
            } catch (Throwable $e) { /* Marktplatz aus */ }
        }
        if (can('users.view')) {
            $st = $db->prepare('SELECT COUNT(*) FROM users WHERE created_at >= ?'); $st->execute([$since(7 * 86400)]);
            $n = (int)$st->fetchColumn();
            $out[] = [$n ? 'info' : 'ok', '👤', $n ? $n . ' neue(s) Konto/Konten in 7 Tagen' : 'Keine neuen Konten (7 Tage)', 'admin.php?tab=users'];
        }
        if (can('audit.view')) {
            $st = $db->prepare("SELECT COUNT(*) FROM audit_log WHERE level = 'security' AND ts >= ?"); $st->execute([$since(86400)]);
            $n = (int)$st->fetchColumn();
            $out[] = [$n >= 10 ? 'bad' : ($n ? 'warn' : 'ok'), '🚨', $n ? $n . ' Sicherheitsereignis(se) in 24 Std.' : 'Keine Sicherheitsereignisse (24 Std.)', 'admin.php?tab=audit'];
        }
        if (can('security.manage')) {
            $st = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE count >= ? AND last >= ?'); $st->execute([AUTH_MAX_FAILS, time() - AUTH_LOCK_SECONDS]);
            $n = (int)$st->fetchColumn();
            if ($n) $out[] = ['warn', '🔒', $n . ' Adresse(n) wegen Fehlversuchen gesperrt', 'admin.php?tab=security'];
        }
        if (can('backup.download')) {
            require_once APP_ROOT . '/inc/core/backup.php';
            $b = backup_list()[0] ?? null;
            $age = $b ? intdiv(time() - $b['time'], 86400) : null;
            $out[] = [$b === null ? 'warn' : ($age > 8 ? 'warn' : 'ok'), '💾', $b === null ? 'Noch kein automatisches Backup' : 'Letztes Backup ' . ($age === 0 ? 'heute' : 'vor ' . $age . ' Tag(en)'), 'admin.php?tab=system'];
        }
        if (can('system.manage')) {
            if (site_settings()['maintenance'] ?? false) $out[] = ['warn', '🛠', 'Wartungsmodus ist an', 'admin.php?tab=system'];
            try {
                require_once APP_ROOT . '/inc/modules/status.php';
                $s = st_summary();
                if ($s['services']) $out[] = [$s['ok'] ? ($s['stale'] ? 'warn' : 'ok') : 'bad', '🩺', $s['ok'] ? ($s['stale'] ? 'Status-Prüfung veraltet (Aufgabe läuft nicht?)' : 'Alle Dienste erreichbar') : $s['down'] . ' Dienst(e) gestört', 'status.php'];
            } catch (Throwable $e) { /* egal */ }
        }
        if (can('linkcheck.run')) {
            try {
                require_once APP_ROOT . '/inc/modules/linkcheck.php';
                $r = load_linkcheck();
                $bad = count(array_filter((array)$r['results'], fn($x) => in_array($x['status'] ?? '', ['dead', 'broken'], true)));
                if ($r['checked_at']) $out[] = [$bad ? 'warn' : 'ok', '🔗', $bad ? $bad . ' kaputte Links in den Hubs' : 'Alle Hub-Links erreichbar', 'linkcheck.php'];
            } catch (Throwable $e) { /* egal */ }
        }
        $rank = ['bad' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
        usort($out, fn($a, $b) => $rank[$a[0]] <=> $rank[$b[0]]);
        return $out;
    }
}
return [
    'name' => 'Admin-Postfach',
    'icon' => '📥',
    'description' => 'Was gerade Aufmerksamkeit braucht: Marktplatz-Prüfungen, neue Konten, Sicherheitsereignisse, Sperren, Backup-Alter, Dienste und kaputte Links.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'permission' => 'admin.access',
    'refresh' => 600,
    'render' => function (WidgetCtx $w): string {
        $items = aq_items();
        $todo = count(array_filter($items, fn($i) => in_array($i[0], ['warn', 'bad'], true)));
        $h = '<div class="gx-big ' . ($todo ? 'warn' : 'ok') . '">' . ($todo ? '📥 ' . $todo . ' zu erledigen' : '✅ Alles erledigt') . '</div><ul class="gx-list">';
        foreach ($items as [$lv, $ic, $txt, $href]) $h .= '<li class="gx-row lv-' . e($lv) . '"><span class="gx-dot ' . e($lv) . '"></span><span class="gx-main"><a href="' . e($href) . '">' . e($ic . ' ' . $txt) . '</a></span></li>';
        return $h . '</ul>' . (!$items ? '<div class="wg-empty">Keine Bereiche mit deinen Rechten.</div>' : '');
    },
];
