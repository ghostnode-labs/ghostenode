<?php
/** 6.6: Offline-Seite der Web-App (wird vom Service Worker gezeigt, wenn kein Netz da ist) */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=86400');
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline · GhostNode</title>
<link rel="stylesheet" href="assets/css/hud.css"></head><body data-no-sitenav style="display:flex;align-items:center;justify-content:center;min-height:100vh">
<div style="max-width:420px;text-align:center;padding:24px;border:1px solid var(--border-color);background:var(--panel-bg)">
<img src="assets/img/pwa-192.png" alt="" width="72" height="72"><h1>📴 Offline</h1>
<p style="color:var(--text-muted)">Gerade keine Verbindung zu GhostNode. Sobald du wieder online bist, lädt die Seite neu.</p>
<button class="btn" onclick="location.reload()">⟳ Neu laden</button></div>
<script>addEventListener('online', function () { location.reload(); });</script></body></html>
