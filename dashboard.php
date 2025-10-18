<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db.php';
require_login();

render_header('Dashboard');
$u = current_user();

/* นับงานของฉัน (สถานะยังทำอยู่)ทดสอบ */
$myAssignedCount = 0;
try {
    $sql = "SELECT COUNT(*) AS c
            FROM tasks
            WHERE assignee_id = ?
              AND status IN ('new','in_progress','review','approved','scheduled')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int)$u['id']]);
    $myAssignedCount = (int)($stmt->fetch()['c'] ?? 0);
} catch (Throwable $e) {
    $myAssignedCount = 0;
}

/* สร้างเมตริก พร้อมลิงก์เฉพาะใบแรก */
$pendingCount = 0;
$dueThisWeekCount = 0;
$updatedTodayCount = 0;

try {
    $sql = "SELECT COUNT(*) AS c
            FROM tasks t
            WHERE t.status NOT IN ('done','cancelled')
              AND NOT EXISTS (
                SELECT 1
                FROM task_submissions ts
                WHERE ts.task_id = t.id
              )";
    $stmt = $pdo->query($sql);
    $pendingCount = (int)($stmt->fetch()['c'] ?? 0);
} catch (Throwable $e) {
    $pendingCount = 0;
}

$tz = new DateTimeZone('Asia/Bangkok');
$weekStart = (new DateTime('monday this week', $tz))->setTime(0, 0, 0);
$weekEnd = (clone $weekStart)->modify('+6 days')->setTime(23, 59, 59);
$todayStart = (new DateTime('today', $tz))->setTime(0, 0, 0);
$todayEnd = (clone $todayStart)->setTime(23, 59, 59);

try {
    $sql = "SELECT COUNT(*) AS c
            FROM tasks t
            WHERE t.status NOT IN ('done','cancelled')
              AND t.due_first_draft BETWEEN ? AND ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$weekStart->format('Y-m-d H:i:s'), $weekEnd->format('Y-m-d H:i:s')]);
    $dueThisWeekCount = (int)($stmt->fetch()['c'] ?? 0);
} catch (Throwable $e) {
    $dueThisWeekCount = 0;
}

try {
    $sql = "SELECT COUNT(DISTINCT t.id) AS c
            FROM tasks t
            INNER JOIN task_submissions ts ON ts.task_id = t.id
            WHERE ts.created_at BETWEEN ? AND ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $todayStart->format('Y-m-d H:i:s'),
        $todayEnd->format('Y-m-d H:i:s'),
    ]);
    $updatedTodayCount = (int)($stmt->fetch()['c'] ?? 0);
} catch (Throwable $e) {
    $updatedTodayCount = 0;
}

$stats = [
  [
    'title' => 'งานที่มอบหมายให้ฉัน',
    'value' => $myAssignedCount,
    'icon'  => 'bi-person-check',
    'href'  => base_url('tasks/index.php?assignee=' . urlencode($u['name'])) // <— ไปหน้า all work กรองชื่อฉัน
  ],
  [
    'title' => 'งานค้าง',
    'value' => $pendingCount,
    'icon'  => 'bi-hourglass-split',
    'href'  => base_url('tasks/index.php?dashboard_filter=pending')
  ],
  [
    'title' => 'ครบกำหนดสัปดาห์นี้',
    'value' => $dueThisWeekCount,
    'icon'  => 'bi-calendar-week',
    'href'  => base_url('tasks/index.php?dashboard_filter=due_this_week')
  ],
  [
    'title' => 'อัปเดตล่าสุดวันนี้',
    'value' => $updatedTodayCount,
    'icon'  => 'bi-activity',
    'href'  => base_url('tasks/index.php?dashboard_filter=submitted_today')
  ],
];
?>
<div class="mb-4">
  <div class="p-4 rounded-3 bg-maroon text-white shadow-sm d-flex justify-content-between align-items-center">
    <div>
      <h2 class="mb-1">ยินดีต้อนรับ, <?= e($u['name']) ?></h2>
      <?php if (!empty($u['position'])): ?>
      <p class="mb-1 text-white-50">ตำแหน่ง: <?= e($u['position']) ?></p>
      <?php endif; ?>
      <p class="mb-0 text-white-50">นี่คือศูนย์กลางงานภายในทีม คุณสามารถดูภาพรวมและลัดไปยังหน้าที่ต้องการได้</p>
    </div>
    <div class="d-none d-md-block display-6 opacity-75">
      <i class="bi bi-speedometer2"></i>
    </div>
  </div>
</div>

<div class="row g-3">
  <?php foreach ($stats as $s): ?>
  <div class="col-12 col-sm-6 col-lg-3">
    <div class="card card-stat shadow-sm border-0 h-100 position-relative">
      <div class="card-body d-flex align-items-center">
        <?php if (!empty($s['href'])): ?>
          <!-- ทำให้ไอคอน/การ์ดคลิกได้ด้วย stretched-link -->
          <a href="<?= e($s['href']) ?>" class="stretched-link" aria-label="<?= e($s['title']) ?>"></a>
        <?php endif; ?>
        <div class="me-3 icon-wrap">
          <?php if (!empty($s['href'])): ?>
            <a href="<?= e($s['href']) ?>" class="text-decoration-none text-reset">
              <i class="bi <?= e($s['icon']) ?>"></i>
            </a>
          <?php else: ?>
            <i class="bi <?= e($s['icon']) ?>"></i>
          <?php endif; ?>
        </div>
        <div>
          <div class="h4 mb-0 fw-bold"><?= e((string)$s['value']) ?></div>
          <div class="text-muted small"><?= e($s['title']) ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-8">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-light fw-semibold">กิจกรรมล่าสุด</div>
      <div class="card-body">
        <ul class="timeline list-unstyled mb-0">
          <li><span class="dot"></span> คุณอัปเดตงาน “เตรียมเอกสารลูกค้า A” <span class="text-muted small">2 ชม.ที่แล้ว</span></li>
          <li><span class="dot"></span> มอบหมายงาน “สรุปรายงานประจำสัปดาห์” ให้คุณ <span class="text-muted small">เมื่อวาน</span></li>
          <li><span class="dot"></span> งาน “ประชุมแผน Q4” ถูกปิดสำเร็จ <span class="text-muted small">2 วันก่อน</span></li>
        </ul>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-light fw-semibold">ลัดไปที่</div>
      <div class="card-body d-grid gap-2">
        <a href="<?= e(base_url('tasks/create.php')) ?>" class="btn btn-primary">
          <i class="bi bi-plus-circle me-2"></i>สร้างงานใหม่
        </a>
        <a href="<?= e(base_url('tasks/index.php?assignee=' . urlencode($u['name']))) ?>" class="btn btn-outline-primary">
          <i class="bi bi-list-check me-2"></i>งานของฉัน
        </a>
        <a href="tasks/calendar.php" class="btn btn-outline-primary"><i class="bi bi-calendar-event me-2"></i>ปฏิทินงาน</a>
      </div>
    </div>
  </div>
</div>
<?php render_footer(); ?>
