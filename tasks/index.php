<?php
// /tasks/index.php
declare(strict_types=1);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();
require_once __DIR__ . '/../includes/layout.php';

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
          req.name AS requester_name, asg.name AS assignee_name
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
<div class="card shadow-sm border-0 mb-3">
  <div class="card-header bg-light fw-semibold">ค้นหางาน</div>
  <div class="card-body">
    <form method="get" class="row g-3">
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
          <th style="width:200px">ผู้สั่งงาน</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
      <?php else: foreach($rows as $r): ?>
        <tr>
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

<script>
async function openTaskModal(el){
  const id = el.getAttribute('data-task-id');
  const modalBody = document.getElementById('taskModalBody');
  modalBody.innerHTML = 'กำลังโหลด...';
  const myModal = new bootstrap.Modal(document.getElementById('taskModal'));
  myModal.show();

  try{
    const res = await fetch('view.php?id=' + encodeURIComponent(id), {headers:{'X-Requested-With':'XMLHttpRequest'}});
    const html = await res.text();
    modalBody.innerHTML = html;
  }catch(e){
    modalBody.innerHTML = '<div class="text-danger">โหลดรายละเอียดไม่สำเร็จ</div>';
  }
}
</script>

<?php render_footer(); ?>
