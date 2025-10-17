<?php
// tasks/submit_work.php
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

$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
if ($taskId <= 0) {
    json_out(['ok' => false, 'error' => 'ไม่พบงานที่ต้องการส่ง'], 400);
}

if (!verify_csrf($_POST['_csrf'] ?? '', 'submit_work_' . $taskId)) {
    json_out(['ok' => false, 'error' => 'ไม่สามารถยืนยันความปลอดภัยของคำขอนี้ได้ กรุณารีเฟรชหน้าแล้วลองใหม่'], 400);
}

try {
    $stmt = $pdo->prepare('SELECT id, assignee_id FROM tasks WHERE id = ? LIMIT 1');
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();
    if (!$task) {
        json_out(['ok' => false, 'error' => 'ไม่พบข้อมูลงานในระบบ'], 404);
    }

    $user = current_user();
    if (!$user) {
        json_out(['ok' => false, 'error' => 'จำเป็นต้องเข้าสู่ระบบ'], 401);
    }

    $note = trim((string)($_POST['note'] ?? ''));
    $file = $_FILES['file'] ?? null;

    if ($note === '' && (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
        json_out(['ok' => false, 'error' => 'กรุณาแนบไฟล์งานหรือกรอกคำอธิบายอย่างน้อยหนึ่งรายการ'], 422);
    }

    $filePath = null;
    $originalName = null;
    $mime = null;
    $sizeBytes = null;

    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            json_out(['ok' => false, 'error' => 'อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่'], 400);
        }

        $MAX_FILE = 200 * 1024 * 1024; // 200MB
        $ALLOWED_EXT = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'ai' => 'application/postscript',
            'psd' => 'image/vnd.adobe.photoshop',
            'mp4' => 'video/mp4',
        ];
        $ALLOWED_MIME_PREFIX = ['image/', 'video/', 'application/pdf', 'application/postscript'];

        $originalName = (string)$file['name'];
        $sizeBytes = (int)$file['size'];
        $tmpName = (string)$file['tmp_name'];

        if ($sizeBytes <= 0 || $sizeBytes > $MAX_FILE) {
            json_out(['ok' => false, 'error' => 'ไฟล์มีขนาดใหญ่เกินกำหนด (สูงสุด 200MB)'], 422);
        }

        $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName) ?: 'application/octet-stream';

        $allowedExt = array_key_exists($ext, $ALLOWED_EXT);
        $allowedMime = false;
        foreach ($ALLOWED_MIME_PREFIX as $prefix) {
            if (stripos((string)$mime, $prefix) === 0) {
                $allowedMime = true;
                break;
            }
        }

        if (!$allowedExt || !$allowedMime) {
            json_out(['ok' => false, 'error' => 'ชนิดไฟล์ไม่รองรับ กรุณาใช้นามสกุลที่ระบบกำหนด'], 422);
        }

        $fileName = uuidv4() . ($ext ? ('.' . $ext) : '');
        $year = date('Y');
        $month = date('m');
        $relativePath = 'uploads/submissions/' . $year . '/' . $month . '/' . $fileName;
        $absolutePath = __DIR__ . '/../' . $relativePath;
        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                json_out(['ok' => false, 'error' => 'ไม่สามารถสร้างโฟลเดอร์จัดเก็บไฟล์ได้'], 500);
            }
        }

        if (!move_uploaded_file($tmpName, $absolutePath)) {
            json_out(['ok' => false, 'error' => 'บันทึกไฟล์ไม่สำเร็จ กรุณาลองใหม่'], 500);
        }

        $filePath = $relativePath;
    }

    $pdo->beginTransaction();

    $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) AS v FROM task_submissions WHERE task_id = ?');
    $versionStmt->execute([$taskId]);
    $nextVersion = (int)($versionStmt->fetch()['v'] ?? 0) + 1;

    $insert = $pdo->prepare('INSERT INTO task_submissions(task_id, submitter_id, version, note, file_path, original_name, mime, size_bytes, status, created_at, updated_at)
                              VALUES(?,?,?,?,?,?,?,?,"pending",NOW(),NOW())');
    $insert->execute([
        $taskId,
        (int)$user['id'],
        $nextVersion,
        $note !== '' ? $note : null,
        $filePath,
        $originalName,
        $mime,
        $sizeBytes,
    ]);

    $pdo->commit();

    $summary = fetch_latest_submission_summary($pdo, $taskId);

    json_out([
        'ok' => true,
        'flash' => 'submitted',
        'summary' => $summary,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[submit_work] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'เกิดข้อผิดพลาด ไม่สามารถส่งงานได้'], 500);
}

function uuidv4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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
