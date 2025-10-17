<?php
// users/index.php
declare(strict_types=1);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();
require_once __DIR__ . '/../includes/layout.php';

$viewer = current_user();

if (!is_manager_or_higher($viewer)) {
    http_response_code(403);
    render_header('ไม่มีสิทธิ์เข้าถึง');
    ?>
    <div class="alert alert-danger">
      คุณไม่มีสิทธิ์ในการเข้าถึงหน้ารายชื่อผู้ใช้ จำเป็นต้องมีตำแหน่งอย่างน้อย Manager ขึ้นไป
    </div>
    <?php
    render_footer();
    exit;
}

render_header('รายชื่อผู้ใช้งาน');

$stmt = $pdo->query('SELECT id, name, position FROM users ORDER BY name ASC, id ASC');
$users = $stmt->fetchAll();

function split_name_parts(?string $full): array
{
    $full = trim((string)$full);
    if ($full === '') {
        return ['first' => '', 'last' => ''];
    }

    // Normalize whitespace between words
    $normalized = preg_replace('/\s+/u', ' ', $full);
    $parts = explode(' ', $normalized ?? '');

    if (count($parts) === 1) {
        return ['first' => $parts[0], 'last' => ''];
    }

    $first = array_shift($parts);
    $last = trim(implode(' ', $parts));

    return ['first' => $first, 'last' => $last];
}
?>
<div class="d-flex align-items-center mb-4">
  <div>
    <h1 class="h3 mb-1">รายชื่อผู้ใช้ระบบ</h1>
    <p class="text-muted mb-0">สรุปรายชื่อพนักงานพร้อมตำแหน่งในระบบ</p>
  </div>
</div>

<div class="card shadow-sm border-0">
  <div class="card-header bg-light fw-semibold">
    ผู้ใช้งานทั้งหมด <span class="badge text-bg-secondary ms-2"><?= count($users) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:80px" class="text-center">#</th>
          <th>ชื่อ</th>
          <th>นามสกุล</th>
          <th>ตำแหน่ง</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr>
            <td colspan="4" class="text-center text-muted py-4">ยังไม่มีข้อมูลผู้ใช้ในระบบ</td>
          </tr>
        <?php else: ?>
          <?php foreach ($users as $idx => $user): ?>
            <?php $parts = split_name_parts($user['name'] ?? ''); ?>
            <tr>
              <td class="text-center fw-semibold"><?= $idx + 1 ?></td>
              <td><?= e($parts['first'] !== '' ? $parts['first'] : '-') ?></td>
              <td><?= e($parts['last'] !== '' ? $parts['last'] : '-') ?></td>
              <td><?= e(($user['position'] ?? '') !== '' ? (string)$user['position'] : '-') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); ?>
