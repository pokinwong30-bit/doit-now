<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

if (current_user()) {
    go('/dashboard.php');
    exit;
}

$errors = [];
$email = trim($_POST['email'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Throttle เบื้องต้น: ถ้าล้มเหลว >=5 ครั้งใน 10 นาที ล่าสุด ให้ดีเลย์
$too_many = false;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM login_attempts
                           WHERE (email = ? OR ip = ?) AND attempted_at >= (NOW() - INTERVAL 10 MINUTE) AND success = 0");
    $stmt->execute([$email, $ip]);
    $row = $stmt->fetch();
    $too_many = ($row && (int)$row['c'] >= 5);
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($too_many) {
        // ยังบันทึก attempt เพื่อร่องรอย
        $pdo->prepare("INSERT INTO login_attempts(email, ip, success, attempted_at) VALUES(?,?,0,NOW())")
            ->execute([$email, $ip]);
        $errors[] = 'พยายามมากเกินไป กรุณาลองใหม่ภายหลัง';
    } else {
        if (!verify_csrf($_POST['_csrf'] ?? '', 'login')) {
            $errors[] = 'ไม่พบโทเค็นความปลอดภัย กรุณาลองใหม่อีกครั้ง';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
        $pass = $_POST['password'] ?? '';
        if ($pass === '') $errors[] = 'กรุณากรอกรหัสผ่าน';

        if (!$errors) {
            $stmt = $pdo->prepare('SELECT id,name,email,password_hash,role,status FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            $ok = $user && $user['status'] === 'active' && password_verify($pass, $user['password_hash']);

            // Log ความพยายาม
            $pdo->prepare("INSERT INTO login_attempts(email, ip, success, attempted_at) VALUES(?,?,?,NOW())")
                ->execute([$email, $ip, $ok ? 1 : 0]);

            if ($ok) {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id'    => (int)$user['id'],
                    'name'  => $user['name'],
                    'email' => $user['email'],
                    'role'  => $user['role'],
                ];
                go('/dashboard.php');
                exit;
            } else {
                $errors[] = 'อีเมลหรือรหัสผ่านไม่ถูกต้อง';
            }
        }
    }
}

require_once __DIR__ . '/../includes/layout.php';
render_header('เข้าสู่ระบบ');
?>
<div class="row justify-content-center">
  <div class="col-lg-5">
    <div class="card shadow-lg border-0">
      <div class="card-header bg-maroon text-white">
        <h5 class="mb-0">เข้าสู่ระบบพนักงาน</h5>
      </div>
      <div class="card-body">
        <?php if ($errors): ?>
        <div class="alert alert-danger">
          <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>
        <form method="post" novalidate>
          <?= csrf_field('login') ?>
          <div class="mb-3">
            <label class="form-label">อีเมล</label>
            <input type="email" name="email" class="form-control" value="<?= e($email) ?>" required>
          </div>
          <div class="mb-4">
            <label class="form-label">รหัสผ่าน</label>
            <input type="password" name="password" class="form-control" required>
          </div>
          <div class="d-grid">
            <button class="btn btn-primary btn-lg" type="submit" <?= $too_many ? 'disabled' : '' ?>>เข้าสู่ระบบ</button>
          </div>
          <div class="text-center mt-3">
            ยังไม่มีบัญชี? <a href="<?= e(base_url('auth/register.php')) ?>">สมัครสมาชิก</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php render_footer(); ?>
