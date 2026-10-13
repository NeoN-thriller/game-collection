<?php
// The collection value graphs on the dashboard, the signed-in user's own settings (values keep being recorded):
//   {show: 0|1}       the switch in Settings › Language & appearance (off = nothing about them on the dashboard;
//                     on = also opens the card again)
//   {collapsed: 0|1}  the dashboard card's Hide / Show graphs button (folds the card away)
// POST JSON with either or both; CSRF is checked for every /api/* POST in core.php.
require_once __DIR__ . '/../boot.php';
$user = requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok'=>false,'error'=>tRaw('common.err_unknown_action')], 405);

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$sets = []; $vals = [];
if (array_key_exists('show', $body))      { $sets[] = 'show_value_graph=?';      $vals[] = !empty($body['show']) ? 1 : 0; }
if (array_key_exists('collapsed', $body)) { $sets[] = 'value_graph_collapsed=?'; $vals[] = !empty($body['collapsed']) ? 1 : 0; }
// Switching the graphs on in Settings always shows them open, whatever the card was when they were switched off
if (!empty($body['show']) && !array_key_exists('collapsed', $body)) { $sets[] = 'value_graph_collapsed=0'; }
if (!$sets) jsonOut(['ok'=>false,'error'=>tRaw('api.no_data')], 400);
$vals[] = $user['id'];
db()->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
jsonOut(['ok'=>true]);
