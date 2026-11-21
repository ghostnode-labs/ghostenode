<?php
// Testkopie: Rollen & Benutzer für den Poster-Test (NIE auf dem echten Server ausführen)
require __DIR__ . '/../../inc/core/pages.php';
$db = site_db();
$roles = ['PosterAdmin' => ['posters.view', 'posters.upload', 'posters.edit', 'posters.manage'], 'PosterEditor' => ['posters.view', 'posters.upload', 'posters.edit'], 'PosterUploader' => ['posters.view', 'posters.upload'], 'Viewer' => ['posters.view'], 'Nobody' => []];
foreach ($roles as $name => $perms) {
    $db->prepare('INSERT INTO roles (name) VALUES (?)')->execute([$name]);
    $rid = (int)$db->lastInsertId();
    foreach ($perms as $p) $db->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)')->execute([$rid, $p]);
    auth_create(strtolower($name), 'Test-Passwort-123!', $rid, $name . ' Test');
}
echo "ok\n";
