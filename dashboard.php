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
$recentActivities = [];

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

try {
    $sql = "SELECT * FROM (
                SELECT 'task_created' AS event_type,
                       t.created_at     AS event_time,
                       t.id             AS task_id,
                       t.title          AS task_title,
                       t.task_code      AS task_code,
                       req.name         AS actor_name,
                       NULL             AS status,
                       NULL             AS submission_version
                FROM tasks t
                LEFT JOIN users req ON req.id = t.requester_id

                UNION ALL

                SELECT 'submission_created' AS event_type,
                       ts.created_at        AS event_time,
                       ts.task_id           AS task_id,
                       t.title              AS task_title,
                       t.task_code          AS task_code,
                       sub.name             AS actor_name,
                       ts.status            AS status,
                       ts.version           AS submission_version
                FROM task_submissions ts
                INNER JOIN tasks t ON t.id = ts.task_id
                LEFT JOIN users sub ON sub.id = ts.submitter_id

                UNION ALL

                SELECT 'submission_reviewed' AS event_type,
                       ts.reviewed_at        AS event_time,
                       ts.task_id            AS task_id,
                       t.title               AS task_title,
                       t.task_code           AS task_code,
                       rev.name              AS actor_name,
                       ts.status             AS status,
                       ts.version            AS submission_version
                FROM task_submissions ts
                INNER JOIN tasks t ON t.id = ts.task_id
                LEFT JOIN users rev ON rev.id = ts.reviewed_by
                WHERE ts.reviewed_at IS NOT NULL
            ) AS recent
            WHERE event_time IS NOT NULL
            ORDER BY event_time DESC
            LIMIT 4";
    $stmt = $pdo->query($sql);
    $recentActivities = $stmt->fetchAll();
} catch (Throwable $e) {
    $recentActivities = [];
}

function thai_time_ago(?string $datetime, DateTimeZone $tz): string
{
    if (!$datetime) {
        return '';
    }

    try {
        $moment = new DateTime($datetime, $tz);
    } catch (Throwable $e) {
        return '';
    }

    $now = new DateTime('now', $tz);
    $diff = $now->diff($moment);

    if ((int)$diff->y > 0) {
        return $diff->y . ' ปีที่แล้ว';
    }
    if ((int)$diff->m > 0) {
        return $diff->m . ' เดือนที่แล้ว';
    }
    if ((int)$diff->d > 0) {
        return $diff->d . ' วันที่แล้ว';
    }
    if ((int)$diff->h > 0) {
        return $diff->h . ' ชม.ที่แล้ว';
    }
    if ((int)$diff->i > 0) {
        return $diff->i . ' นาทีที่แล้ว';
    }

    return 'ไม่กี่วินาทีที่แล้ว';
}

function format_activity_message(array $activity): string
{
    $taskId = isset($activity['task_id']) ? (int)$activity['task_id'] : 0;
    $taskTitle = trim((string)($activity['task_title'] ?? ''));
    $taskCode = trim((string)($activity['task_code'] ?? ''));
    $taskLabel = $taskTitle !== '' ? '“' . e($taskTitle) . '”' : ($taskCode !== '' ? 'งาน ' . e($taskCode) : 'งานที่ไม่ระบุชื่อ');

    if ($taskId > 0) {
        $taskUrl = base_url('tasks/view.php?id=' . $taskId);
        $taskLabel = '<a href="' . e($taskUrl) . '" class="text-decoration-none">' . $taskLabel . '</a>';
    }

    $actor = trim((string)($activity['actor_name'] ?? ''));
    $actorLabel = $actor !== '' ? e($actor) : 'ระบบ';

    $version = isset($activity['submission_version']) ? (int)$activity['submission_version'] : null;
    $versionText = $version && $version > 0 ? ' รอบที่ ' . e((string)$version) : '';

    $status = (string)($activity['status'] ?? '');

    return match ($activity['event_type'] ?? '') {
        'task_created' => $actorLabel . ' สร้างงาน ' . $taskLabel,
        'submission_created' => $actorLabel . ' ส่งงาน' . $versionText . ' ใน ' . $taskLabel,
        'submission_reviewed' => match ($status) {
            'approved' => $actorLabel . ' อนุมัติผลงาน' . $versionText . ' ใน ' . $taskLabel,
            'revision_required' => $actorLabel . ' ขอแก้ไขผลงาน' . $versionText . ' ใน ' . $taskLabel,
            default => $actorLabel . ' ตรวจงาน' . $versionText . ' ใน ' . $taskLabel,
        },
        default => $actorLabel . ' อัปเดต ' . $taskLabel,
    };
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
        <?php if ($recentActivities): ?>
        <ul class="timeline list-unstyled mb-0">
          <?php foreach ($recentActivities as $activity): ?>
          <?php $timeLabel = thai_time_ago($activity['event_time'] ?? null, $tz); ?>
          <li>
            <span class="dot"></span>
            <?= format_activity_message($activity) ?>
            <?php if ($timeLabel !== ''): ?>
            <span class="text-muted small"><?= e($timeLabel) ?></span>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="text-muted mb-0">ยังไม่มีกิจกรรมล่าสุด</p>
        <?php endif; ?>
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
