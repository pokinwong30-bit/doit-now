<?php
// tasks/review_submission.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

function json_out(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Invalid method'], 405);
}

$user = current_user();
if (!$user || !is_director_level($user)) {
    json_out(['ok' => false, 'error' => 'เฉพาะ Director เท่านั้นที่สามารถอนุมัติหรือขอแก้ไขงานได้'], 403);
if (!$user || !is_manager_or_higher($user)) {
    json_out(['ok' => false, 'error' => 'เฉพาะผู้จัดการขึ้นไปเท่านั้นที่สามารถอนุมัติหรือขอแก้ไขงานได้'], 403);
}

$submissionId = isset($_POST['submission_id']) ? (int)$_POST['submission_id'] : 0;
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
if ($submissionId <= 0 || $taskId <= 0) {
    json_out(['ok' => false, 'error' => 'ข้อมูลการส่งงานไม่ถูกต้อง'], 400);
}

if (!verify_csrf($_POST['_csrf'] ?? '', 'review_submission_' . $submissionId)) {
    json_out(['ok' => false, 'error' => 'ไม่สามารถยืนยันความปลอดภัยของคำขอนี้ได้ กรุณารีเฟรชหน้าแล้วลองใหม่'], 400);
}

$status = (string)($_POST['status'] ?? '');
$status = strtolower(trim($status));
$allowedStatus = ['approved', 'revision_required'];
if (!in_array($status, $allowedStatus, true)) {
    json_out(['ok' => false, 'error' => 'สถานะที่เลือกไม่ถูกต้อง'], 422);
}

$comment = trim((string)($_POST['comment'] ?? ''));
if ($status === 'revision_required' && $comment === '') {
    json_out(['ok' => false, 'error' => 'กรุณาระบุความคิดเห็นเมื่อขอให้งานแก้ไข'], 422);
}

try {
    $stmt = $pdo->prepare('SELECT id, task_id, status FROM task_submissions WHERE id = ? AND task_id = ? LIMIT 1');
    $stmt->execute([$submissionId, $taskId]);
    $submission = $stmt->fetch();
    if (!$submission) {
        json_out(['ok' => false, 'error' => 'ไม่พบรายการส่งงานที่ต้องการ'], 404);
    }

    if ((string)$submission['status'] !== 'pending') {
        json_out(['ok' => false, 'error' => 'รายการนี้ได้รับการตรวจแล้ว ไม่สามารถแก้ไขซ้ำได้'], 422);
    }

    $update = $pdo->prepare('UPDATE task_submissions SET status = ?, review_comment = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?');
    $update->execute([
        $status,
        $comment !== '' ? $comment : null,
        (int)$user['id'],
        $submissionId,
    ]);

    $summary = fetch_latest_submission_summary($pdo, $taskId);

    json_out([
        'ok' => true,
        'flash' => $status === 'approved' ? 'approved' : 'revision',
        'summary' => $summary,
    ]);
} catch (Throwable $e) {
    error_log('[review_submission] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'ไม่สามารถบันทึกผลการตรวจงานได้'], 500);
}

function fetch_latest_submission_summary(PDO $pdo, int $taskId): ?array
{
    $stmt = $pdo->prepare('SELECT id, status, version, review_comment, reviewed_at, created_at FROM task_submissions WHERE task_id = ? ORDER BY version DESC, id DESC LIMIT 1');
    $stmt->execute([$taskId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'submission_id' => (int)$row['id'],
        'status' => (string)$row['status'],
        'version' => (int)$row['version'],
        'review_comment' => $row['review_comment'],
        'reviewed_at' => $row['reviewed_at'],
        'created_at' => $row['created_at'],
    ];
}
