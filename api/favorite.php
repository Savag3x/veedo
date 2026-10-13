<?php
// api/favorite.php
require_once __DIR__ . '/../includes/bootstrap.php';
$data = request_json();
$ad_id = (int)($data['ad_id'] ?? 0);
rate_limit_or_die("favorite", 20, 60);


if (!is_logged()) json_response(['success'=>false,'redirect'=>'/login']);
if (!$ad_id || !DB::fetch("SELECT id FROM ads WHERE id=?", [$ad_id])) json_response(['success'=>false,'error'=>'Invalid'],400);
$uid = current_user()['id'];
$ex = DB::fetch("SELECT id FROM favorites WHERE user_id=? AND ad_id=?",[$uid,$ad_id]);
if ($ex) { DB::query("DELETE FROM favorites WHERE user_id=? AND ad_id=?",[$uid,$ad_id]); $saved=false; }
else { DB::query("INSERT IGNORE INTO favorites (user_id,ad_id) VALUES (?,?)",[$uid,$ad_id]); $saved=true; }
json_response(['success'=>true,'saved'=>$saved]);
