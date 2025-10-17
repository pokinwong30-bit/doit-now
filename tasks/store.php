<?php
// /tasks/store.php (fixed)
declare(strict_types=1);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./create.php');
    exit;
}
if (!verify_csrf($_POST['_csrf'] ?? '', 'task_create')) {
    die('CSRF token invalid.');
}

$MAX_FILE = 200 * 1024 * 1024;
$ALLOWED_EXT = [
    'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp',
    'pdf'=>'application/pdf','ai'=>'application/postscript','psd'=>'image/vnd.adobe.photoshop',
    'mp4'=>'video/mp4'
];
$ALLOWED_MIME_PREFIX = ['image/','video/','application/pdf','application/postscript'];

function uuidv4(): string {
  $data = random_bytes(16);
  $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
  $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$u = current_user();

/* ---------- รับค่า POST ---------- */
$title         = trim($_POST['title'] ?? '');
$task_type     = trim($_POST['task_type'] ?? '');
$priority      = $_POST['priority'] ?? 'normal';
$description   = trim($_POST['description'] ?? '');
$assignee_id   = isset($_POST['assignee_id']) && $_POST['assignee_id'] !== '' ? (int)$_POST['assignee_id'] : null;

$project_id    = trim($_POST['project_id'] ?? '');
$phase         = trim($_POST['phase'] ?? '');
$key_message   = trim($_POST['key_message'] ?? '');
$competitors   = trim($_POST['competitors'] ?? '');

$objective     = $_POST['objective'] ?? [];    // array
$channels      = $_POST['channels'] ?? [];     // array
$deliverables  = array_filter($_POST['deliverables'] ?? [], fn($v)=>trim((string)$v)!=='');
$copy_headline = trim($_POST['copy_headline'] ?? '');
$copy_body     = trim($_POST['copy_body'] ?? '');
$cta           = trim($_POST['cta'] ?? '');
$mandatory     = trim($_POST['mandatory_words'] ?? '');
$forbidden     = trim($_POST['forbidden_words'] ?? '');

$budget_amount = $_POST['media_budget_amount'] ?? null;
$budget_note   = trim($_POST['media_budget_note'] ?? '');

$due_first     = $_POST['due_first_draft'] ?? '';
$due_final     = $_POST['due_final'] ?? '';
$launch_date   = $_POST['launch_date'] ?? '';

$legal_required = isset($_POST['reviewer_legal_required']) ? 1 : 0;

/* ---------- Validation ---------- */
$errors = [];
if ($title === '') $errors[] = 'กรุณากรอกชื่องาน';
if ($task_type === '') $errors[] = 'กรุณาเลือกประเภทงาน';
if ($due_first === '') $errors[] = 'กรุณากำหนดวันส่ง Draft แรก';
if (!in_array($priority, ['low','normal','high','critical'], true)) $priority = 'normal';

$due_first_dt = $due_first ? date('Y-m-d H:i:s', strtotime($due_first)) : null;
$due_final_dt = $due_final ? date('Y-m-d H:i:s', strtotime($due_final)) : null;
$launch_dt    = $launch_date ? date('Y-m-d H:i:s', strtotime($launch_date)) : null;

if ($due_first_dt && $due_final_dt && $due_final_dt < $due_first_dt) $errors[] = 'วันอนุมัติสุดท้ายต้องไม่ก่อนวันส่ง Draft แรก';
if ($due_final_dt && $launch_dt && $launch_dt < $due_final_dt)     $errors[] = 'วันเผยแพร่ต้องไม่ก่อนวันอนุมัติสุดท้าย';
if ($budget_amount !== null && $budget_amount !== '' && !is_numeric($budget_amount)) $errors[] = 'งบสื่อต้องเป็นตัวเลข';

if ($errors) {
  echo '<h3>พบข้อผิดพลาด</h3><ul>';
  foreach ($errors as $er) echo '<li>'.htmlspecialchars($er, ENT_QUOTES).'</li>';
  echo '</ul><p><a href="./create.php">ย้อนกลับ</a></p>';
  exit;
}

/* ---------- แปลงค่าที่ต้องใช้ก่อน INSERT ---------- */
$objective_json    = json_encode(array_values($objective), JSON_UNESCAPED_UNICODE);
$channels_json     = json_encode(array_values($channels), JSON_UNESCAPED_UNICODE);
$deliverables_json = json_encode(array_values($deliverables), JSON_UNESCAPED_UNICODE);
$project_id_db = ($project_id === '') ? null : $project_id; // <-- เก็บสตริง (หรือ NULL ถ้าไม่ได้กรอก)


/* ---------- ใช้ temp code กัน NOT NULL + UNIQUE ---------- */
$temp_code = 'TMP-' . bin2hex(random_bytes(6));

/* ---------- INSERT (พารามิเตอร์ต้องตรงกับจำนวน ? ) ---------- */
$stmt = $pdo->prepare("INSERT INTO tasks
(task_code, title, task_type, description, priority,
 project_id, phase, key_message, competitors,
 audience_segment_json, brand_tone_json, languages_json,
 objective_json, channels_json, deliverables_json,
 copy_headline, copy_body, cta, mandatory_words, forbidden_words,
 media_budget_amount, media_budget_note,
 due_first_draft, due_final, launch_date,
 requester_id, assignee_id, status, reviewer_legal_required,
 ordered_at, created_at, updated_at)
VALUES
(?, ?, ?, ?, ?,
 ?, ?, ?, ?,
 NULL, NULL, NULL,
 ?, ?, ?,
 ?, ?, ?, ?, ?,
 ?, ?,
 ?, ?, ?,
 ?, ?, 'new', ?,
 NOW(), NOW(), NOW())");

$stmt->execute([
  // 1..5
  $temp_code, $title, $task_type, $description, $priority,
  // 6..9  (ใช้สตริง/NULL สำหรับ project_id)
  $project_id_db, $phase, $key_message, $competitors,
  // 10..12 -> NULL, NULL, NULL
  // 13..15
  $objective_json, $channels_json, $deliverables_json,
  // 16..20
  $copy_headline, $copy_body, $cta, $mandatory, $forbidden,
  // 21..22
  ($budget_amount === '' ? null : $budget_amount), $budget_note,
  // 23..25
  $due_first_dt, $due_final_dt, $launch_dt,
  // 26..27
  (int)$u['id'], $assignee_id,
  // 28
  $legal_required
]);


$task_id = (int)$pdo->lastInsertId();

/* ---------- อัปเดตรหัสงานจริง ---------- */
$task_code = 'TASK-' . date('Ymd') . '-' . str_pad((string)$task_id, 6, '0', STR_PAD_LEFT);
$pdo->prepare("UPDATE tasks SET task_code=? WHERE id=?")->execute([$task_code, $task_id]);

/* ---------- อัปโหลดไฟล์อ้างอิง (≤ 200 MB) ---------- */
if (!empty($_FILES['references']) && is_array($_FILES['references']['name'])) {
  $names = $_FILES['references']['name'];
  $tmp   = $_FILES['references']['tmp_name'];
  $sizes = $_FILES['references']['size'];
  $errs  = $_FILES['references']['error'];
  $captions = $_POST['reference_captions'] ?? [];

  $year = date('Y'); $month = date('m');
  $baseDir = __DIR__ . '/../uploads/references/' . $year . '/' . $month;
  if (!is_dir($baseDir)) { @mkdir($baseDir, 0775, true); }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  for ($i=0; $i<count($names); $i++) {
    if ($errs[$i] === UPLOAD_ERR_NO_FILE) continue;
    if ($errs[$i] !== UPLOAD_ERR_OK) continue;

    $orig = (string)$names[$i];
    $size = (int)$sizes[$i];
    $tmpf = (string)$tmp[$i];
    if ($size > $MAX_FILE) continue;

    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $mime = $finfo->file($tmpf) ?: 'application/octet-stream';

    $ok_ext = array_key_exists($ext, $ALLOWED_EXT);
    $ok_mime = false;
    foreach ($ALLOWED_MIME_PREFIX as $pre) {
      if (stripos($mime, $pre) === 0) { $ok_mime = true; break; }
    }
    if (!$ok_ext || !$ok_mime) continue;

    $newName = uuidv4() . '.' . $ext;
    $destRel = 'uploads/references/' . $year . '/' . $month . '/' . $newName;
    $destAbs = __DIR__ . '/../' . $destRel;

    if (move_uploaded_file($tmpf, $destAbs)) {
      $cap = is_array($captions) && isset($captions[$i]) ? trim((string)$captions[$i]) : null;
      $pdo->prepare("INSERT INTO task_attachments(task_id,file_path,original_name,mime,size_bytes,caption,created_at)
                     VALUES(?,?,?,?,?,?,NOW())")
          ->execute([$task_id, $destRel, $orig, $mime, $size, $cap ?: null]);
    }
  }
}

/* ---------- เสร็จ → กลับ Dashboard ---------- */
header('Location: ../dashboard.php');
exit;
