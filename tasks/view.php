<?php
// /tasks/view.php (table layout)
declare(strict_types=1);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
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

$sstmt = $pdo->prepare("
  SELECT ts.*, sub.name AS submitter_name, rev.name AS reviewer_name
  FROM task_submissions ts
  LEFT JOIN users sub ON sub.id = ts.submitter_id
  LEFT JOIN users rev ON rev.id = ts.reviewed_by
  WHERE ts.task_id = ?
  ORDER BY ts.version DESC, ts.id DESC
");
$sstmt->execute([$id]);
$submissions = $sstmt->fetchAll();

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

function submission_status_meta(?string $status): array {
  return match($status) {
    'approved' => ['class' => 'text-bg-success', 'label' => 'ผ่านอนุมัติ'],
    'revision_required' => ['class' => 'text-bg-danger', 'label' => 'ขอแก้ไข'],
    'pending' => ['class' => 'text-bg-warning text-dark', 'label' => 'รออนุมัติ'],
    default => ['class' => 'text-bg-secondary', 'label' => 'ยังไม่เคยส่งงาน'],
  };
}

/* ---------- Decode JSON fields ---------- */
$objective    = json_decode((string)$task['objective_json'],    true) ?: [];
$channels     = json_decode((string)$task['channels_json'],     true) ?: [];
$deliverables = json_decode((string)$task['deliverables_json'], true) ?: [];

$user = current_user();
$canReview = is_director_level($user);

$latestSubmission = $submissions[0] ?? null;
$nextVersion = $latestSubmission ? ((int)$latestSubmission['version'] + 1) : 1;
$latestStatus = $latestSubmission['status'] ?? null;
$hasRevision = $latestStatus === 'revision_required';

$pendingSubmission = null;
foreach ($submissions as $sub) {
  if (($sub['status'] ?? '') === 'pending') { $pendingSubmission = $sub; break; }
}

$flashKey = isset($_GET['flash']) ? trim((string)$_GET['flash']) : '';
$flashMessage = null;
if ($flashKey !== '') {
  $flashMessage = match($flashKey) {
    'submitted' => ['type' => 'success', 'text' => 'บันทึกการส่งงานเรียบร้อยแล้ว'],
    'approved'  => ['type' => 'success', 'text' => 'อนุมัติผลงานเรียบร้อยแล้ว'],
    'revision'  => ['type' => 'warning', 'text' => 'บันทึกคำขอแก้ไขงานแล้ว'],
    default     => null,
  };
}
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

    <!-- RIGHT: submissions & attachments -->
    <div class="col-lg-4">
      <div id="taskDetailRoot" class="d-flex flex-column gap-3" data-task-id="<?= (int)$task['id'] ?>">
        <div class="card shadow-sm border-0">
          <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
            <span>การส่งงาน</span>
            <span class="badge bg-secondary">ครั้งถัดไป #<?= (int)$nextVersion ?></span>
          </div>
          <div class="card-body">
            <div id="taskActionFeedback"></div>

            <?php if ($flashMessage): ?>
              <div class="alert alert-<?= e($flashMessage['type']) ?>">
                <?= e($flashMessage['text']) ?>
              </div>
            <?php endif; ?>

            <?php if ($latestSubmission): ?>
              <?php $meta = submission_status_meta($latestSubmission['status'] ?? null); ?>
              <div class="mb-3">
                <span class="badge badge-pill <?= e($meta['class']) ?>">
                  <?= e($meta['label']) ?> (ครั้งที่ <?= (int)$latestSubmission['version'] ?>)
                </span>
                <div class="small text-muted mt-2">
                  ส่งเมื่อ <?= e(th_date_long($latestSubmission['created_at'] ?? null)) ?>
                </div>
                <?php if (!empty($latestSubmission['review_comment'])): ?>
                  <div class="small text-muted mt-1">ความคิดเห็น: <?= e($latestSubmission['review_comment']) ?></div>
                <?php endif; ?>
                <?php if (!empty($latestSubmission['reviewer_name'])): ?>
                  <div class="small text-muted">
                    โดย <?= e($latestSubmission['reviewer_name']) ?>
                    <?php if (!empty($latestSubmission['reviewed_at'])): ?>
                      — <?= e(th_date_long($latestSubmission['reviewed_at'])) ?>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="text-muted mb-3">ยังไม่มีการส่งงาน</div>
            <?php endif; ?>

            <?php if ($hasRevision && !empty($latestSubmission['review_comment'])): ?>
              <div class="alert alert-warning">
                งานล่าสุดถูกขอให้แก้ไข: <?= e($latestSubmission['review_comment']) ?>
              </div>
            <?php elseif ($hasRevision): ?>
              <div class="alert alert-warning">
                งานล่าสุดถูกขอให้แก้ไข กรุณาส่งฉบับถัดไป
              </div>
            <?php endif; ?>

            <form id="submitWorkForm" method="post" enctype="multipart/form-data" class="border rounded p-3 bg-light-subtle">
              <?= csrf_field('submit_work_' . $task['id']) ?>
              <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
              <div class="mb-3">
                <label class="form-label">ไฟล์ผลงาน</label>
                <input type="file" name="file" class="form-control">
                <div class="form-text">รองรับไฟล์ภาพ วิดีโอ PDF และไฟล์งาน Adobe (สูงสุด 200MB)</div>
              </div>
              <div class="mb-3">
                <label class="form-label">คำอธิบาย / โน้ตเพิ่มเติม</label>
                <textarea name="note" class="form-control" rows="3" placeholder="สรุปสิ่งที่เปลี่ยนแปลง หรือแนบลิงก์ประกอบ"></textarea>
                <div class="form-text">หากไม่มีไฟล์ สามารถส่งเฉพาะข้อความได้</div>
              </div>
              <div class="d-grid d-md-flex justify-content-md-end">
                <button type="submit" class="btn btn-primary" data-action="submit-work">
                  <i class="bi bi-upload me-1"></i> ส่งงานฉบับใหม่
                </button>
              </div>
            </form>

            <?php if ($canReview): ?>
              <?php if ($pendingSubmission): ?>
                <hr class="my-4">
                <h6 class="fw-semibold mb-3">ตรวจงานล่าสุด (ครั้งที่ <?= (int)$pendingSubmission['version'] ?>)</h6>
                <form id="reviewSubmissionForm" method="post">
                  <?= csrf_field('review_submission_' . $pendingSubmission['id']) ?>
                  <input type="hidden" name="submission_id" value="<?= (int)$pendingSubmission['id'] ?>">
                  <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                  <div class="mb-3">
                    <label class="form-label">ผลการตรวจ</label>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="status" id="statusApprove" value="approved" required>
                      <label class="form-check-label" for="statusApprove">อนุมัติ</label>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="status" id="statusRevision" value="revision_required">
                      <label class="form-check-label" for="statusRevision">ขอแก้ไข</label>
                    </div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">ความคิดเห็น</label>
                    <textarea name="comment" class="form-control" rows="3" placeholder="สรุปข้อเสนอแนะหรือเงื่อนไขเพิ่มเติม"></textarea>
                    <div class="form-text">จำเป็นต้องกรอกเมื่อเลือกขอแก้ไข</div>
                  </div>
                  <div class="d-grid d-md-flex justify-content-md-end gap-2">
                    <button type="submit" class="btn btn-success" data-action="review-work">
                      <i class="bi bi-check-circle me-1"></i> บันทึกผลการตรวจ
                    </button>
                  </div>
                </form>
              <?php elseif ($latestSubmission): ?>
                <hr class="my-4">
                <div class="text-muted">ไม่มีงานที่รอตรวจในขณะนี้</div>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($submissions): ?>
              <div class="mt-4">
                <h6 class="fw-semibold mb-3">ประวัติการส่งงาน</h6>
                <div class="list-group" id="submissionList">
                  <?php foreach ($submissions as $submission): ?>
                    <?php $metaRow = submission_status_meta($submission['status'] ?? null); ?>
                    <div class="list-group-item">
                      <div class="d-flex justify-content-between align-items-start">
                        <div>
                          <span class="badge text-bg-secondary me-2">ครั้งที่ <?= (int)$submission['version'] ?></span>
                          <span class="fw-semibold"><?= e($submission['submitter_name'] ?: 'ไม่ทราบชื่อ') ?></span>
                        </div>
                        <small class="text-muted"><?= e(th_date_long($submission['created_at'] ?? null)) ?></small>
                      </div>
                      <?php if (!empty($submission['note'])): ?>
                        <div class="mt-2"><?= nl2br(e($submission['note'])) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($submission['file_path'])): ?>
                        <div class="mt-2 d-flex flex-column">
                          <div>
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(base_url($submission['file_path'])) ?>" target="_blank">
                              <i class="bi bi-paperclip me-1"></i> ดาวน์โหลดไฟล์
                            </a>
                          </div>
                          <div class="small text-muted mt-1">
                            <?= e($submission['original_name'] ?: 'ไม่ระบุชื่อไฟล์') ?>
                            <?php if (!empty($submission['size_bytes'])): ?>
                              • <?= e(human_filesize((int)$submission['size_bytes'])) ?>
                            <?php endif; ?>
                          </div>
                        </div>
                      <?php endif; ?>
                      <div class="mt-3">
                        <span class="badge badge-pill <?= e($metaRow['class']) ?>"><?= e($metaRow['label']) ?></span>
                        <?php if (!empty($submission['review_comment'])): ?>
                          <div class="small text-muted mt-1">ความคิดเห็น: <?= e($submission['review_comment']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($submission['reviewer_name'])): ?>
                          <div class="small text-muted">
                            โดย <?= e($submission['reviewer_name']) ?>
                            <?php if (!empty($submission['reviewed_at'])): ?>
                              — <?= e(th_date_long($submission['reviewed_at'])) ?>
                            <?php endif; ?>
                          </div>
                        <?php elseif (($submission['status'] ?? '') === 'pending'): ?>
                          <div class="small text-muted">รอ Director ตรวจสอบ</div>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card shadow-sm border-0">
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
</div>

<script>
(() => {
  const root = document.getElementById('taskDetailRoot');
  if (!root) {
    return;
  }

  const dedupeById = (id) => {
    const nodes = root.querySelectorAll(`#${id}`);
    if (nodes.length <= 1) {
      return;
    }

    nodes.forEach((node, index) => {
      if (index > 0) {
        node.remove();
      }
    });
  };

  dedupeById('submitWorkForm');
  dedupeById('reviewSubmissionForm');

  const taskId = parseInt(root.dataset.taskId || '0', 10);
  const feedback = document.getElementById('taskActionFeedback');

  const showAlert = (type, message) => {
    if (!feedback) return;
    feedback.innerHTML = '';
    const alert = document.createElement('div');
    alert.className = `alert alert-${type}`;
    alert.textContent = message;
    feedback.appendChild(alert);
  };

  const clearAlert = () => {
    if (feedback) {
      feedback.innerHTML = '';
    }
  };

  const dispatchUpdate = (summary) => {
    window.dispatchEvent(new CustomEvent('task-submission-updated', {
      detail: { taskId, summary }
    }));
  };

  const handleError = (fallbackMessage, error) => {
    console.error(error);
    showAlert('danger', fallbackMessage);
  };

  const submitForm = document.getElementById('submitWorkForm');
  if (submitForm) {
    submitForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearAlert();
      const button = submitForm.querySelector('button[type="submit"]');
      if (button) button.disabled = true;

      try {
        const formData = new FormData(submitForm);
        formData.set('task_id', String(taskId));
        const response = await fetch('submit_work.php', {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
          showAlert('danger', data.error || 'ไม่สามารถส่งงานได้');
        } else {
          dispatchUpdate(data.summary || null);
          if (typeof window.loadTaskDetail === 'function') {
            await window.loadTaskDetail(taskId, data.flash || 'submitted');
          } else {
            window.location.reload();
          }
        }
      } catch (err) {
        handleError('เกิดข้อผิดพลาด ไม่สามารถส่งงานได้', err);
      } finally {
        if (button) button.disabled = false;
      }
    });
  }

  const reviewForm = document.getElementById('reviewSubmissionForm');
  if (reviewForm) {
    reviewForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearAlert();
      const button = reviewForm.querySelector('button[type="submit"]');
      if (button) button.disabled = true;

      try {
        const formData = new FormData(reviewForm);
        formData.set('task_id', String(taskId));
        const response = await fetch('review_submission.php', {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
          showAlert('danger', data.error || 'ไม่สามารถบันทึกผลการตรวจงานได้');
        } else {
          dispatchUpdate(data.summary || null);
          if (typeof window.loadTaskDetail === 'function') {
            await window.loadTaskDetail(taskId, data.flash || 'approved');
          } else {
            window.location.reload();
          }
        }
      } catch (err) {
        handleError('เกิดข้อผิดพลาด ไม่สามารถบันทึกผลการตรวจงานได้', err);
      } finally {
        if (button) button.disabled = false;
      }
    });
  }
})();
</script>
