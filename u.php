<?php
declare(strict_types=1);
/**
 * 6.6 Öffentliche Profilseite (freiwillig, in „Mein Bereich“ einzuschalten): u.php?n=<adresse>
 * <title>Profil</title>
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/pages.php';
auth_require_setup();
require_once __DIR__ . '/inc/modules/me.php';

$pr = me_public((string)($_GET['n'] ?? ''));
if (!$pr) { http_response_code(404); }
$name = $pr ? $pr['name'] : 'Profil nicht gefunden';
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo e($name); ?> · GhostNode</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="assets/css/hud.css">
<style>
body.up{padding:0;display:block}.up-wrap{box-sizing:border-box;max-width:640px;margin:0 auto;padding:30px 20px 60px}
.up-card{border:1px solid var(--border-color);background:var(--panel-bg);padding:24px;clip-path:var(--cut-md)}
.up-av{width:84px;height:84px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2.2rem;font-weight:800;background:linear-gradient(135deg,var(--accent-cyan),var(--accent-gold));color:#04060a}
.up-head{display:flex;gap:18px;align-items:center}.up-head h1{margin:0}.up-role{display:inline-block;margin-top:6px;padding:2px 10px;border:1px solid;font-size:.75rem;letter-spacing:1px}
.up-bio{white-space:pre-wrap;line-height:1.5;margin:18px 0}.up-links a{display:block;margin:6px 0;word-break:break-all}.muted{color:var(--text-muted);font-size:.85rem}
</style></head><body class="up"><div class="up-wrap"><div class="up-card">
<?php if (!$pr): ?><h1>🔍 Profil nicht gefunden</h1><p class="muted">Diese Adresse gibt es nicht oder das Profil ist nicht öffentlich.</p>
<?php else: ?>
<div class="up-head"><div class="up-av"><?php echo e(mb_strtoupper(mb_substr($pr['name'], 0, 1))); ?></div><div><h1><?php echo e($pr['name']); ?></h1>
<?php if ($pr['role'] !== ''): ?><span class="up-role" style="color:<?php echo e(preg_match('/^#[0-9a-f]{6}$/i', $pr['role_color']) ? $pr['role_color'] : '#38d5e6'); ?>"><?php echo e($pr['role']); ?></span><?php endif; ?>
<div class="muted">Mitglied seit <?php echo e(date('m/Y', strtotime($pr['since']) ?: time())); ?></div></div></div>
<?php if ($pr['bio'] !== ''): ?><div class="up-bio"><?php echo e($pr['bio']); ?></div><?php endif; ?>
<?php if ($pr['links']): ?><div class="up-links"><?php foreach ($pr['links'] as $l) echo '<a href="' . e($l) . '" target="_blank" rel="noopener noreferrer nofollow">🔗 ' . e(preg_replace('#^https://#', '', $l)) . '</a>'; ?></div><?php endif; ?>
<?php endif; ?>
</div></div></body></html>
