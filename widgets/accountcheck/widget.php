<?php
// 9.0: Konto-Check – Sicherheits-Punkte des eigenen Kontos mit direkten Links zum Beheben
if (!function_exists('ac_checks')) {
    /** Prüfpunkte für ein Konto → [[ok, icon, text, link, gewicht]] (rein, für Tests ohne Datenbank nutzbar) */
    function ac_checks(array $u, int $sessions, array $policy, int $now = 0): array {
        $now = $now ?: time();
        $pwAge = ($t = strtotime((string)($u['password_changed'] ?? ''))) ? intdiv($now - $t, 86400) : null;
        $out = [];
        $out[] = [($u['totp_secret'] ?? '') !== '', '🔐', ($u['totp_secret'] ?? '') !== '' ? 'Zwei-Faktor-Anmeldung ist an' : 'Zwei-Faktor-Anmeldung einschalten', 'admin.php?tab=account', 35];
        if ((int)($u['pw_set'] ?? 1)) {
            $max = (int)($policy['max_age'] ?? 0);
            $ok = $pwAge === null || ($max ? $pwAge < $max : $pwAge < 365);
            $out[] = [$ok, '🔑', $pwAge === null ? 'Passwort gesetzt' : ($ok ? 'Passwort ist ' . $pwAge . ' Tage alt' : 'Passwort ist ' . $pwAge . ' Tage alt – erneuern'), 'admin.php?tab=account', 20];
        } else $out[] = [false, '🔑', 'Noch kein Passwort (nur Discord-Anmeldung) – Passwort setzen', 'admin.php?tab=account', 20];
        $out[] = [$sessions <= 3, '💻', $sessions . ' angemeldete' . ($sessions === 1 ? 's Gerät' : ' Geräte') . ($sessions > 3 ? ' – alte abmelden?' : ''), 'admin.php?tab=account', 15];
        $out[] = [!empty($u['discord_id']), '💬', !empty($u['discord_id']) ? 'Discord verknüpft (' . (string)($u['discord_name'] ?? '') . ')' : 'Discord verknüpfen (Anmeldung ohne Passwort möglich)', 'admin.php?tab=account', 10];
        $out[] = [trim((string)($u['display_name'] ?? '')) !== '', '🪪', trim((string)($u['display_name'] ?? '')) !== '' ? 'Anzeigename gesetzt' : 'Anzeigename festlegen', 'admin.php?tab=account', 10];
        $out[] = [empty($u['expires_at']) || strtotime((string)$u['expires_at']) > $now + 14 * 86400, '⏳', empty($u['expires_at']) ? 'Konto läuft nicht ab' : 'Konto läuft ab am ' . date('d.m.Y', strtotime((string)$u['expires_at'])), 'admin.php?tab=account', 10];
        return $out;
    }
}
return [
    'name' => 'Konto-Check',
    'icon' => '🛡️',
    'description' => 'Wie sicher ist dein Konto? Zwei-Faktor, Passwort-Alter, angemeldete Geräte, Discord-Verknüpfung – mit Punktzahl und Links zum Beheben.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'login' => true,
    'render' => function (WidgetCtx $w): string {
        $me = current_user();
        if (!$me) return '<div class="wg-empty">Bitte anmelden.</div>';
        require_once APP_ROOT . '/inc/core/accounts.php';
        acc_schema();
        $st = site_db()->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([(int)$me['id']]);
        $u = $st->fetch() ?: [];
        $sc = site_db()->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?'); $sc->execute([(int)$me['id']]);
        $checks = ac_checks($u, (int)$sc->fetchColumn(), password_policy());
        $total = array_sum(array_column($checks, 4));
        $score = (int)round(array_sum(array_map(fn($c) => $c[0] ? $c[4] : 0, $checks)) / max(1, $total) * 100);
        $cls = $score >= 80 ? 'ok' : ($score >= 50 ? 'warn' : 'bad');
        $h = '<div class="ac-score ' . $cls . '"><span style="--p:' . $score . '"><b>' . $score . '</b></span><div>' . ($score >= 80 ? 'Gut geschützt' : ($score >= 50 ? 'Ausbaufähig' : 'Bitte absichern')) . '<small>' . count(array_filter($checks, fn($c) => !$c[0])) . ' offene Punkte</small></div></div><ul class="gx-list">';
        foreach ($checks as [$ok, $ic, $txt, $href]) $h .= '<li class="gx-row"><span class="gx-ic">' . ($ok ? '✅' : '⚠️') . '</span><span class="gx-main">' . ($ok ? e($ic . ' ' . $txt) : '<a href="' . e($href) . '">' . e($ic . ' ' . $txt) . ' →</a>') . '</span></li>';
        return $h . '</ul>';
    },
];
