<?php
// /tasks/view.php (table layout)
declare(strict_types=1);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Invalid id'); }

$stmt = $pdo->prepare("
  SELECT
    t.*,
    req.name  AS requester_name, req.email  AS requester_email,
    asg.name  AS assignee_name,  asg.email  AS assignee_email
  FROM tasks t
  LEFT JOIN users req ON req.id = t.requester_id
  LEFT JOIN users asg ON asg.id = t.assignee_id
  WHERE t.id = ?
  LIMIT 1
");
$stmt->execute([$id]);
$task = $stmt->fetch();
if (!$task) { http_response_code(404); exit('Not found'); }

$astmt = $pdo->prepare("
  SELECT id,file_path,original_name,mime,size_bytes,caption,created_at
  FROM task_attachments WHERE task_id=? ORDER BY id ASC
");
$astmt->execute([$id]);
$files = $astmt->fetchAll();

/* ---------- Helpers ---------- */
function th_date_long(?string $dt): string {
  if (!$dt) return '-';
  $ts = strtotime($dt);
  if ($ts <= 0) return '-';
  $months = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  return (int)date('j',$ts).' '.$months[(int)date('n',$ts)].' '.((int)date('Y',$ts)+543).' เวลา '.date('H:i',$ts).' น.';
}
function th_date_short(?string $dt): string {
  if (!$dt) return '-';
  $ts = strtotime($dt);
  if ($ts <= 0) return '-';
  $months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
  return (int)date('j',$ts).' '.$months[(int)date('n',$ts)].' '.((int)date('Y',$ts)+543);
}
function human_filesize($bytes, $dec=1){
  $units=['B','KB','MB','GB','TB']; $pos=0;
  while ($bytes>=1024 && $pos<count($units)-1){ $bytes/=1024; $pos++; }
  return number_format($bytes,$dec).' '.$units[$pos];
}
function is_image_mime(string $mime): bool { return stripos($mime, 'image/') === 0; }

/* ---------- Decode JSON fields ---------- */
$objective    = json_decode((string)$task['objective_json'],    true) ?: [];
$channels     = json_decode((string)$task['channels_json'],     true) ?: [];
$deliverables = json_decode((string)$task['deliverables_json'], true) ?: [];
?>
<div class="container-fluid">
  <div class="row g-3">
    <!-- LEFT: detail tables -->
    <div class="col-lg-8">
      <!-- Badges -->
      <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="badge bg-primary">รหัสงาน: <?= e($task['task_code']) ?></span>
        <span class="badge bg-secondary">สถานะ: <?= e($task['status']) ?></span>
        <span class="badge bg-outline text-maroon border border-1"><?= e($task['task_type']) ?></span>
        <span class="badge <?= ($task['priority']==='critical'?'bg-danger':($task['priority']==='high'?'bg-warning text-dark':'bg-info')) ?>">
          Priority: <?= e($task['priority']) ?>
        </span>
      </div>

      <!-- Title + Description -->
      <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
          <h4 class="mb-2"><?= e($task['title']) ?></h4>
          <?php if (!empty($task['description'])): ?>
            <div class="text-muted"><?= nl2br(e($task['description'])) ?></div>
          <?php else: ?>
            <div class="text-muted">-</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Table: ข้อมูลงาน -->
      <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-light fw-semibold">ข้อมูลงาน</div>
        <div class="card-body p-0">
          <table class="table table-sm table-striped mb-0 align-middle">
            <tbody>
              <tr>
                <th class="w-25 text-muted">โครงการ/แบรนด์</th>
                <td><?= e((string)($task['project_id'] ?? '')) ?: '-' ?></td>
              </tr>
              <tr>
                <th class="text-muted">เฟส/แปลงขาย</th>
                <td><?= e($task['phase'] ?: '-') ?></td>
              </tr>
              <tr>
                <th class="text-muted">Key Message</th>
                <td><?= e($task['key_message'] ?: '-') ?></td>
              </tr>
              <tr>
                <th class="text-muted">คู่แข่งอ้างอิง</th>
                <td><?= e($task['competitors'] ?: '-') ?></td>
              </tr>
              <tr>
                <th class="text-muted">วัตถุประสงค์</th>
                <td><?= $objective ? e(implode(', ', $objective)) : '-' ?></td>
              </tr>
              <tr>
                <th class="text-muted">ช่องทางสื่อ</th>
                <td><?= $channels ? e(implode(', ', $channels)) : '-' ?></td>
              </tr>
              <tr>
                <th class="text-muted">Deliverables</th>
                <td>
                  <?php if ($deliverables): ?>
                    <ul class="mb-0"><?php foreach($deliverables as $d): ?><li><?= e((string)$d) ?></li><?php endforeach; ?></ul>
                  <?php else: ?>-<?php endif; ?>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Table: ผู้เกี่ยวข้อง -->
      <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-light fw-semibold">ผู้เกี่ยวข้อง</div>
        <div class="card-body p-0">
          <table class="table table-sm table-borderless mb-0 align-middle">
            <tbody>
              <tr>
                <th class="w-25 text-muted">ผู้สั่งงาน</th>
                <td>
                  <?= e($task['requester_name'] ?: '-') ?>
                  <?php if (!empty($task['requester_email'])): ?>
                    <span class="text-muted">(<?= e($task['requester_email']) ?>)</span>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <th class="text-muted">ผู้รับมอบหมาย</th>
                <td>
                  <?= e($task['assignee_name'] ?: '-') ?>
                  <?php if (!empty($task['assignee_email'])): ?>
                    <span class="text-muted">(<?= e($task['assignee_email']) ?>)</span>
                  <?php endif; ?>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Table: คอนเทนต์/ข้อกำหนด -->
      <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-light fw-semibold">คอนเทนต์ / ข้อกำหนด</div>
        <div class="card-body p-0">
          <table class="table table-sm table-striped mb-0 align-middle">
            <tbody>
              <tr>
                <th class="w-25 text-muted">Headline</th>
                <td><?= e($task['copy_headline'] ?: '-') ?></td>
              </tr>
              <tr>
                <th class="text-muted">Body</th>
                <td><?= nl2br(e($task['copy_body'] ?: '-')) ?></td>
              </tr>
              <tr>
                <th class="text-muted">CTA</th>
                <td><?= e($task['cta'] ?: '-') ?></td>
              </tr>
              <tr>
                <th class="text-muted">คำต้องมี</th>
                <td><?= e($task['mandatory_words'] ?: '-') ?></td>
              </tr>
              <tr>
                <th class="text-muted">คำต้องห้าม</th>
                <td><?= e($task['forbidden_words'] ?: '-') ?></td>
              </tr>
              <tr>
                <th class="text-muted">ต้องตรวจข้อกำกับ/ลิขสิทธิ์</th>
                <td><?= ((int)$task['reviewer_legal_required'] === 1) ? 'ต้องตรวจ' : 'ไม่ต้องตรวจ' ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Table: ไทม์ไลน์ / งบ -->
      <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-light fw-semibold">ไทม์ไลน์ / งบ</div>
        <div class="card-body p-0">
          <table class="table table-sm table-borderless mb-0 align-middle">
            <tbody>
              <tr>
                <th class="w-25 text-muted">วันที่สั่งงาน</th>
                <td><?= e(th_date_long($task['ordered_at'])) ?></td>
              </tr>
              <tr>
                <th class="text-muted">กำหนดส่ง Draft แรก</th>
                <td><?= e(th_date_long($task['due_first_draft'])) ?></td>
              </tr>
              <tr>
                <th class="text-muted">อนุมัติสุดท้าย</th>
                <td><?= e(th_date_long($task['due_final'])) ?></td>
              </tr>
              <tr>
                <th class="text-muted">วันเผยแพร่จริง</th>
                <td><?= e(th_date_long($task['launch_date'])) ?></td>
              </tr>
              <tr>
                <th class="text-muted">งบสื่อ</th>
                <td><?= $task['media_budget_amount'] !== null ? number_format((float)$task['media_budget_amount'],2).' บาท' : '-' ?></td>
              </tr>
              <tr>
                <th class="text-muted">หมายเหตุงบสื่อ</th>
                <td><?= e($task['media_budget_note'] ?: '-') ?></td>
              </tr>
              <tr>
                <th class="text-muted">อัปเดตล่าสุด</th>
                <td><?= e(th_date_short($task['updated_at'])) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>

    <!-- RIGHT: attachments -->
    <div class="col-lg-4">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-header bg-light fw-semibold">ไฟล์แนบ / รูปอ้างอิง</div>
        <div class="card-body">
          <?php if (!$files): ?>
            <div class="text-muted">ไม่มีไฟล์แนบ</div>
          <?php else: ?>
            <div class="row g-2">
              <?php foreach ($files as $f): ?>
                <div class="col-6">
                  <div class="border rounded p-2 h-100">
                    <?php if (is_image_mime((string)$f['mime'])): ?>
                      <a href="<?= e(base_url($f['file_path'])) ?>" target="_blank">
                        <img src="<?= e(base_url($f['file_path'])) ?>" class="img-fluid rounded" alt="<?= e($f['original_name']) ?>">
                      </a>
                    <?php else: ?>
                      <div class="small mb-1">
                        <i class="bi bi-paperclip me-1"></i><?= e($f['original_name']) ?>
                      </div>
                      <div class="small text-muted"><?= e(human_filesize((int)$f['size_bytes'])) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($f['caption'])): ?>
                      <div class="small text-muted mt-1"><?= e($f['caption']) ?></div>
                    <?php endif; ?>
                    <div class="mt-1">
                      <a class="btn btn-sm btn-outline-primary w-100" href="<?= e(base_url($f['file_path'])) ?>" target="_blank">เปิด/ดาวน์โหลด</a>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
