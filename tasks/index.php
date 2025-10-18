<?php
// /tasks/index.php
declare(strict_types=1);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();
require_once __DIR__ . '/../includes/layout.php';

$currentUser = current_user();
$canReviewSubmissions = is_director_level($currentUser);

render_header('ตารางงานรวม');

/* ---------- รับค่า filter ---------- */
$q            = trim($_GET['q']             ?? '');  // ค้นหารวม
$task_code    = trim($_GET['task_code']     ?? '');
$title        = trim($_GET['title']         ?? '');
$requester    = trim($_GET['requester']     ?? '');  // ชื่อผู้สั่งงาน
$assignee     = trim($_GET['assignee']      ?? '');  // ชื่อผู้รับมอบหมาย
$ordered_from = trim($_GET['ordered_from']  ?? '');
$ordered_to   = trim($_GET['ordered_to']    ?? '');
$due_from     = trim($_GET['due_from']      ?? '');
$due_to       = trim($_GET['due_to']        ?? '');
$dashboardFilter = trim((string)($_GET['dashboard_filter'] ?? ''));
$dashboardFilterMessage = '';

/* ---------- pagination ---------- */
$per_page_opts = [10,20,50,100];
$per_page = (int)($_GET['per_page'] ?? 20);
if (!in_array($per_page, $per_page_opts, true)) $per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

/* ---------- สร้างเงื่อนไขค้นหา ---------- */
$where = [];
$params = [];

// quick search ครอบคลุม: task_code, title, users.name (requester/assignee)
if ($q !== '') {
  $where[] = "(t.task_code LIKE ? OR t.title LIKE ? OR req.name LIKE ? OR asg.name LIKE ?)";
  $like = '%' . $q . '%';
  $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($task_code !== '') { $where[] = "t.task_code LIKE ?"; $params[] = '%' . $task_code . '%'; }
if ($title     !== '') { $where[] = "t.title LIKE ?";     $params[] = '%' . $title . '%'; }
if ($requester !== '') { $where[] = "req.name LIKE ?";    $params[] = '%' . $requester . '%'; }
if ($assignee  !== '') { $where[] = "asg.name LIKE ?";    $params[] = '%' . $assignee . '%'; }

if ($ordered_from !== '') { $where[] = "t.ordered_at >= ?"; $params[] = date('Y-m-d 00:00:00', strtotime($ordered_from)); }
if ($ordered_to   !== '') { $where[] = "t.ordered_at <= ?"; $params[] = date('Y-m-d 23:59:59', strtotime($ordered_to)); }
if ($due_from     !== '') { $where[] = "t.due_first_draft >= ?"; $params[] = date('Y-m-d 00:00:00', strtotime($due_from)); }
if ($due_to       !== '') { $where[] = "t.due_first_draft <= ?"; $params[] = date('Y-m-d 23:59:59', strtotime($due_to)); }

if ($dashboardFilter !== '') {
  $tz = new DateTimeZone('Asia/Bangkok');

  switch ($dashboardFilter) {
    case 'pending':
      $where[] = "t.status NOT IN ('done','cancelled')";
      $where[] = "NOT EXISTS (SELECT 1 FROM task_submissions ts WHERE ts.task_id = t.id)";
      $dashboardFilterMessage = 'แสดงเฉพาะงานที่ยังไม่เคยส่งงาน';
      break;
    case 'due_this_week':
      $weekStart = (new DateTime('monday this week', $tz))->setTime(0, 0, 0);
      $weekEnd = (clone $weekStart)->modify('+6 days')->setTime(23, 59, 59);
      $where[] = "t.status NOT IN ('done','cancelled')";
      $where[] = "t.due_first_draft BETWEEN ? AND ?";
      $params[] = $weekStart->format('Y-m-d H:i:s');
      $params[] = $weekEnd->format('Y-m-d H:i:s');
      $dashboardFilterMessage = 'แสดงเฉพาะงานที่ครบกำหนดส่งภายในสัปดาห์นี้';
      break;
    case 'submitted_today':
      $todayStart = (new DateTime('today', $tz))->setTime(0, 0, 0);
      $todayEnd = (clone $todayStart)->setTime(23, 59, 59);
      $where[] = "EXISTS (SELECT 1 FROM task_submissions ts WHERE ts.task_id = t.id AND ts.created_at BETWEEN ? AND ?)";
      $params[] = $todayStart->format('Y-m-d H:i:s');
      $params[] = $todayEnd->format('Y-m-d H:i:s');
      $dashboardFilterMessage = 'แสดงเฉพาะงานที่มีการส่งงานภายในวันนี้';
      break;
  }
}

$sql_where = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ---------- นับทั้งหมด ---------- */
$sql_count = "SELECT COUNT(*) AS c
              FROM tasks t
              LEFT JOIN users req ON req.id = t.requester_id
              LEFT JOIN users asg ON asg.id = t.assignee_id
              $sql_where";
$stmt = $pdo->prepare($sql_count);
$stmt->execute($params);
$total = (int)($stmt->fetch()['c'] ?? 0);

/* ---------- ดึงรายการหน้าแสดงผล ---------- */
$sql = "SELECT
          t.id, t.task_code, t.title, t.description,
          t.ordered_at, t.due_first_draft,
          req.name AS requester_name, asg.name AS assignee_name,
          (SELECT status FROM task_submissions WHERE task_id = t.id ORDER BY version DESC, id DESC LIMIT 1) AS latest_submission_status,
          (SELECT version FROM task_submissions WHERE task_id = t.id ORDER BY version DESC, id DESC LIMIT 1) AS latest_submission_version,
          (SELECT created_at FROM task_submissions WHERE task_id = t.id ORDER BY version DESC, id DESC LIMIT 1) AS latest_submission_created_at,
          (SELECT review_comment FROM task_submissions WHERE task_id = t.id ORDER BY version DESC, id DESC LIMIT 1) AS latest_submission_review_comment,
          (SELECT reviewed_at FROM task_submissions WHERE task_id = t.id ORDER BY version DESC, id DESC LIMIT 1) AS latest_submission_reviewed_at
        FROM tasks t
        LEFT JOIN users req ON req.id = t.requester_id
        LEFT JOIN users asg ON asg.id = t.assignee_id
        $sql_where
        ORDER BY t.ordered_at DESC, t.id DESC
        LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

/* ---------- ฟังก์ชัน วันไทยแบบสั้น ---------- */
function th_date_short(?string $dt): string {
  if (!$dt) return '-';
  $ts = strtotime($dt);
  if ($ts <= 0) return '-';
  $months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
  return (int)date('j',$ts) . ' ' . $months[(int)date('n',$ts)] . ' ' . ((int)date('Y',$ts)+543);
}

function submission_status_meta_php(?string $status): array {
  return match($status) {
    'approved' => ['class' => 'text-bg-success', 'label' => 'ผ่านอนุมัติ'],
    'revision_required' => ['class' => 'text-bg-danger', 'label' => 'ขอแก้ไข'],
    'pending' => ['class' => 'text-bg-warning text-dark', 'label' => 'รออนุมัติ'],
    default => ['class' => 'text-bg-secondary', 'label' => 'ยังไม่มีการส่งงาน'],
  };
}

function submission_row_class(?string $status): string {
  return match($status) {
    'revision_required' => 'table-warning',
    'pending' => 'table-info',
    'approved' => 'table-success',
    default => '',
  };
}

function render_submission_summary(array $row): string {
  $status = $row['latest_submission_status'] ?? null;
  if (!$status) {
    return '<span class="text-muted">ยังไม่มีการส่งงาน</span>';
  }

  $meta = submission_status_meta_php($status);
  $version = isset($row['latest_submission_version']) ? (int)$row['latest_submission_version'] : null;
  $comment = trim((string)($row['latest_submission_review_comment'] ?? ''));
  $createdAt = $row['latest_submission_created_at'] ?? null;
  $reviewedAt = $row['latest_submission_reviewed_at'] ?? null;

  ob_start();
  ?>
  <span class="badge rounded-pill <?= e($meta['class']) ?>">
    <?= e($meta['label']) ?><?= $version ? ' #' . e((string)$version) : '' ?>
  </span>
  <?php if ($comment !== ''): ?>
    <div class="small text-muted mt-1">ความคิดเห็น: <?= e($comment) ?></div>
  <?php endif; ?>
  <?php if ($status === 'pending' && $createdAt): ?>
    <div class="small text-muted">ส่งเมื่อ <?= e(th_date_short($createdAt)) ?></div>
  <?php elseif ($reviewedAt): ?>
    <div class="small text-muted">อัปเดตล่าสุด <?= e(th_date_short($reviewedAt)) ?></div>
  <?php elseif ($createdAt): ?>
    <div class="small text-muted">ส่งเมื่อ <?= e(th_date_short($createdAt)) ?></div>
  <?php endif; ?>
  <?php
  return trim((string)ob_get_clean());
}

/* ---------- สร้าง query string สำหรับลิงก์แบ่งหน้า ---------- */
$qparams = $_GET;
unset($qparams['page']);
$base_qs = http_build_query($qparams);
function page_link(int $p, string $base_qs): string {
  $qs = $base_qs ? ($base_qs . '&') : '';
  return '?' . $qs . 'page=' . $p;
}
$total_pages = max(1, (int)ceil($total / $per_page));
$from = min($total, $offset + 1);
$to   = min($total, $offset + count($rows));
?>
<?php if ($dashboardFilterMessage !== ''): ?>
<div class="alert alert-info d-flex align-items-center gap-2">
  <i class="bi bi-funnel"></i>
  <div><?= e($dashboardFilterMessage) ?></div>
</div>
<?php endif; ?>
<div class="card shadow-sm border-0 mb-3">
  <div class="card-header bg-light fw-semibold">ค้นหางาน</div>
  <div class="card-body">
    <form method="get" class="row g-3">
      <?php if ($dashboardFilter !== ''): ?>
        <input type="hidden" name="dashboard_filter" value="<?= e($dashboardFilter) ?>">
      <?php endif; ?>
      <div class="col-lg-4">
        <label class="form-label">ค้นหารวม</label>
        <input type="text" class="form-control" name="q" value="<?= e($q) ?>" placeholder="รหัสงาน / ชื่องาน / ผู้สั่ง / ผู้รับมอบหมาย">
      </div>
      <div class="col-lg-2">
        <label class="form-label">รหัสงาน</label>
        <input type="text" class="form-control" name="task_code" value="<?= e($task_code) ?>">
      </div>
      <div class="col-lg-3">
        <label class="form-label">ชื่องาน</label>
        <input type="text" class="form-control" name="title" value="<?= e($title) ?>">
      </div>
      <div class="col-lg-3">
        <label class="form-label">ผู้สั่งงาน</label>
        <input type="text" class="form-control" name="requester" value="<?= e($requester) ?>">
      </div>

      <div class="col-lg-3">
        <label class="form-label">ผู้รับมอบหมาย</label>
        <input type="text" class="form-control" name="assignee" value="<?= e($assignee) ?>">
      </div>
      <div class="col-lg-3">
        <label class="form-label">วันที่สั่ง (จาก)</label>
        <input type="date" class="form-control" name="ordered_from" value="<?= e($ordered_from) ?>">
      </div>
      <div class="col-lg-3">
        <label class="form-label">วันที่สั่ง (ถึง)</label>
        <input type="date" class="form-control" name="ordered_to" value="<?= e($ordered_to) ?>">
      </div>
      <div class="col-lg-3">
        <label class="form-label">กำหนดส่ง (ถึง)</label>
        <div class="input-group">
          <input type="date" class="form-control" name="due_from" value="<?= e($due_from) ?>">
          <input type="date" class="form-control" name="due_to" value="<?= e($due_to) ?>">
        </div>
      </div>

      <div class="col-lg-2">
        <label class="form-label">ต่อหน้า</label>
        <select name="per_page" class="form-select">
          <?php foreach($per_page_opts as $opt): ?>
            <option value="<?= $opt ?>" <?= $opt===$per_page ? 'selected':'' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-10 d-flex align-items-end justify-content-end gap-2">
        <a href="<?= e(base_url('tasks/index.php')) ?>" class="btn btn-outline-secondary">ล้างเงื่อนไข</a>
        <button class="btn btn-primary">ค้นหา</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm border-0">
  <div class="card-header bg-light d-flex justify-content-between align-items-center">
    <span class="fw-semibold">ตารางงานรวม</span>
    <span class="small text-muted">แสดง <?= e((string)$from) ?>–<?= e((string)$to) ?> จาก <?= e((string)$total) ?> รายการ</span>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:140px">รหัสงาน</th>
          <th style="width:200px">ผู้รับมอบหมาย</th>
          <th>ชื่องาน</th>
          <th>คำอธิบาย</th>
          <th style="width:140px">วันที่สั่ง</th>
          <th style="width:160px">กำหนดส่ง</th>
          <th style="width:220px">สถานะการส่งงาน</th>
          <th style="width:140px" class="text-center"><?= $canReviewSubmissions ? 'ตรวจงาน' : 'ส่งงาน' ?></th>
          <th style="width:200px">ผู้สั่งงาน</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
      <?php else: foreach($rows as $r): ?>
        <?php $rowStatus = $r['latest_submission_status'] ?? null; ?>
        <?php $rowClass = submission_row_class($rowStatus); ?>
        <tr data-task-id="<?= (int)$r['id'] ?>" data-submission-status="<?= e((string)$rowStatus) ?>" data-submission-version="<?= e((string)($r['latest_submission_version'] ?? '')) ?>" class="<?= e($rowClass) ?>">
          <td>
            <a href="javascript:void(0)" class="fw-semibold link-primary" data-task-id="<?= (int)$r['id'] ?>" onclick="openTaskModal(this)">
              <?= e($r['task_code']) ?>
            </a>
          </td>
          <td><?= e($r['assignee_name'] ?: '-') ?></td>
          <td class="fw-semibold"><?= e($r['title']) ?></td>
          <td class="text-truncate" style="max-width:360px"><?= e(mb_strimwidth((string)$r['description'], 0, 200, '…','UTF-8')) ?></td>
          <td><?= e(th_date_short($r['ordered_at'])) ?></td>
          <td><?= e(th_date_short($r['due_first_draft'])) ?></td>
          <td>
            <div class="submission-status" data-role="submission-status">
              <?= render_submission_summary($r) ?>
            </div>
          </td>
          <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-primary" data-task-id="<?= (int)$r['id'] ?>" onclick="openSubmissionModal(this)">
              <?php if ($canReviewSubmissions): ?>
                <i class="bi bi-clipboard-check"></i> ตรวจงาน
              <?php else: ?>
                <i class="bi bi-upload"></i> ส่งงาน
              <?php endif; ?>
            </button>
          </td>
          <td><?= e($r['requester_name'] ?: '-') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
  <div class="card-footer bg-light">
    <nav>
      <ul class="pagination mb-0">
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= e(page_link(1, $base_qs)) ?>">«</a>
        </li>
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= e(page_link($page-1, $base_qs)) ?>">‹</a>
        </li>
        <?php
          $window = 2;
          $start = max(1, $page-$window);
          $end   = min($total_pages, $page+$window);
          for ($p=$start; $p<=$end; $p++):
        ?>
        <li class="page-item <?= $p===$page?'active':'' ?>">
          <a class="page-link" href="<?= e(page_link($p, $base_qs)) ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
        <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
          <a class="page-link" href="<?= e(page_link($page+1, $base_qs)) ?>">›</a>
        </li>
        <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
          <a class="page-link" href="<?= e(page_link($total_pages, $base_qs)) ?>">»</a>
        </li>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
</div>

<!-- Modal รายละเอียดงาน -->
<div class="modal fade" id="taskModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-maroon text-white">
        <h5 class="modal-title">รายละเอียดงาน</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="taskModalBody" class="text-center text-muted py-4">กำลังโหลด...</div>
      </div>
    </div>
  </div>
</div>

<!-- Modal ส่งงาน -->
<div class="modal fade" id="submissionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">ส่งงาน</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="submissionModalBody" class="text-center text-muted py-4">กำลังโหลด...</div>
      </div>
    </div>
  </div>
</div>

<script>
async function loadTaskDetail(taskId, flash) {
  const modalBody = document.getElementById('taskModalBody');
  if (!modalBody) return;
  modalBody.innerHTML = 'กำลังโหลด...';
  const params = new URLSearchParams({ id: taskId });
  if (flash) {
    params.set('flash', flash);
  }

  try {
    const res = await fetch('view.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const html = await res.text();
    modalBody.innerHTML = html;
    activateInlineScripts(modalBody);
  } catch (err) {
    console.error(err);
    modalBody.innerHTML = '<div class="text-danger">โหลดรายละเอียดไม่สำเร็จ</div>';
  }
}

async function openTaskModal(el, flash) {
  const id = el.getAttribute('data-task-id');
  if (!id) return;
  const modalElement = document.getElementById('taskModal');
  const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
  modal.show();
  await loadTaskDetail(id, flash);
}

window.loadTaskDetail = loadTaskDetail;

async function loadSubmissionPanel(taskId, flash) {
  const modalBody = document.getElementById('submissionModalBody');
  if (!modalBody) return;
  modalBody.innerHTML = 'กำลังโหลด...';
  const params = new URLSearchParams({ id: taskId });
  if (flash) {
    params.set('flash', flash);
  }

  try {
    const res = await fetch('submissions_panel.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const html = await res.text();
    modalBody.innerHTML = html;
    activateInlineScripts(modalBody);
  } catch (err) {
    console.error(err);
    modalBody.innerHTML = '<div class="text-danger">โหลดฟอร์มส่งงานไม่สำเร็จ</div>';
  }
}

async function openSubmissionModal(el, flash) {
  const id = el.getAttribute('data-task-id');
  if (!id) return;
  const modalElement = document.getElementById('submissionModal');
  const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
  modal.show();
  await loadSubmissionPanel(id, flash);
}

window.loadSubmissionPanel = loadSubmissionPanel;

function activateInlineScripts(container) {
  if (!container) return;
  const scripts = container.querySelectorAll('script');
  scripts.forEach((oldScript) => {
    const newScript = document.createElement('script');
    for (const attr of oldScript.attributes) {
      newScript.setAttribute(attr.name, attr.value);
    }
    newScript.appendChild(document.createTextNode(oldScript.textContent || ''));
    oldScript.parentNode.replaceChild(newScript, oldScript);
  });
}

function submissionStatusMetaClient(status) {
  switch ((status || '').toLowerCase()) {
    case 'approved':
      return { label: 'ผ่านอนุมัติ', badgeClass: 'text-bg-success', rowClass: 'table-success' };
    case 'revision_required':
      return { label: 'ขอแก้ไข', badgeClass: 'text-bg-danger', rowClass: 'table-warning' };
    case 'pending':
      return { label: 'รออนุมัติ', badgeClass: 'text-bg-warning text-dark', rowClass: 'table-info' };
    default:
      return { label: 'ยังไม่มีการส่งงาน', badgeClass: 'text-bg-secondary', rowClass: '' };
  }
}

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  })[char] || char);
}

function formatThaiDateTime(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat('th-TH', {
    dateStyle: 'medium',
    timeStyle: 'short'
  }).format(date);
}

function renderSubmissionSummaryClient(summary) {
  if (!summary || !summary.status) {
    return '<span class="text-muted">ยังไม่มีการส่งงาน</span>';
  }

  const meta = submissionStatusMetaClient(summary.status);
  const versionText = summary.version ? ` #${escapeHtml(summary.version)}` : '';
  const parts = [
    `<span class="badge rounded-pill ${meta.badgeClass}">${meta.label}${versionText}</span>`
  ];

  if (summary.review_comment) {
    parts.push(`<div class="small text-muted mt-1">ความคิดเห็น: ${escapeHtml(summary.review_comment)}</div>`);
  }

  const timestamp = summary.status === 'pending' ? (summary.created_at || '') : (summary.reviewed_at || summary.created_at || '');
  const formatted = formatThaiDateTime(timestamp);
  if (formatted) {
    const prefix = summary.status === 'pending' ? 'ส่งเมื่อ' : 'อัปเดต';
    parts.push(`<div class="small text-muted">${prefix} ${escapeHtml(formatted)}</div>`);
  }

  return parts.join('');
}

window.addEventListener('task-submission-updated', (event) => {
  const detail = event.detail || {};
  const taskId = detail.taskId;
  if (!taskId) return;

  const row = document.querySelector(`tr[data-task-id="${taskId}"]`);
  if (!row) return;

  const summary = detail.summary || null;
  const status = summary?.status || '';
  const meta = submissionStatusMetaClient(status);

  row.dataset.submissionStatus = status;
  row.dataset.submissionVersion = summary?.version ? String(summary.version) : '';
  row.classList.remove('table-warning', 'table-info', 'table-success');
  if (meta.rowClass) {
    row.classList.add(meta.rowClass);
  }

  const cell = row.querySelector('[data-role="submission-status"]');
  if (cell) {
    cell.innerHTML = renderSubmissionSummaryClient(summary);
  }
});
</script>

<?php render_footer(); ?>
