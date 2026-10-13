<?php
// api/admin-report.php
require_once __DIR__ . '/../includes/bootstrap.php';
require_moderator();  // we'll define this below

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $report_id = (int)($_POST['report_id'] ?? 0);
    $action    = $_POST['action'] ?? '';

    $report = DB::fetch("SELECT * FROM reports WHERE id=?", [$report_id]);
    if (!$report) { flash('error','Raport negăsit.'); redirect('/admin?s=reports'); }

    if ($action === 'resolve') {
        DB::query("UPDATE reports SET status='resolved' WHERE id=?", [$report_id]);
        admin_log('report_resolve','reports',$report_id);
        flash('success','Raport marcat ca rezolvat.');
    } elseif ($action === 'delete_ad' && $report['ad_id']) {
        DB::query("UPDATE ads SET status='rejected', reject_reason='Raportat și eliminat de moderatori' WHERE id=?", [$report['ad_id']]);
        DB::query("UPDATE reports SET status='resolved' WHERE id=?", [$report_id]);
        admin_log('report_delete_ad','ads',$report['ad_id']);
        flash('success','Anunț eliminat și raport rezolvat.');
    }
}
redirect('/admin?s=reports');

function require_moderator(): void {
    if (!is_moderator()) { http_response_code(403); die('Acces interzis'); }
}
