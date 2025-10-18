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
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$position = trim($_POST['position'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? '', 'register')) {
        $errors[] = 'ไม่พบโทเค็นความปลอดภัย กรุณาลองใหม่อีกครั้ง';
    }
    if ($name === '' || mb_strlen($name) < 2) $errors[] = 'กรุณากรอกชื่ออย่างน้อย 2 ตัวอักษร';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    if ($position === '' || mb_strlen($position) < 2) $errors[] = 'กรุณากรอกชื่อตำแหน่งอย่างน้อย 2 ตัวอักษร';
    $pass = $_POST['password'] ?? '';
    $pass2 = $_POST['password_confirm'] ?? '';
    if (mb_strlen($pass) < 8) $errors[] = 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร';
    if ($pass !== $pass2) $errors[] = 'รหัสผ่านยืนยันไม่ตรงกัน';

    if (!$errors) {
        // ตรวจอีเมลซ้ำ
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'อีเมลนี้ถูกใช้แล้ว';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users(name,email,password_hash,position,role,status,created_at,updated_at)
                                   VALUES(?,?,?,?,"employee","active",NOW(),NOW())');
            $stmt->execute([$name, $email, $hash, $position]);

            // Auto-login
            $uid = (int)$pdo->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['user'] = ['id'=>$uid, 'name'=>$name, 'email'=>$email, 'position'=>$position, 'role'=>'employee'];
            go('/dashboard.php');
            exit;
        }
    }
}

require_once __DIR__ . '/../includes/layout.php';
render_header('สมัครสมาชิก');
?>
<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="card shadow-lg border-0">
      <div class="card-header bg-maroon text-white">
        <h5 class="mb-0">สมัครสมาชิกพนักงาน</h5>
      </div>
      <div class="card-body">
        <?php if ($errors): ?>
        <div class="alert alert-danger">
          <ul class="mb-0">
            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
        <form method="post" novalidate>
          <?= csrf_field('register') ?>
          <div class="mb-3">
            <label class="form-label">ชื่อ - นามสกุล</label>
            <input type="text" name="name" class="form-control" value="<?= e($name) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">อีเมล</label>
            <input type="email" name="email" class="form-control" value="<?= e($email) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">ตำแหน่งงาน</label>
            <input type="text" name="position" class="form-control" value="<?= e($position) ?>" required>
            <div class="form-text">เช่น หัวหน้าแผนก, เจ้าหน้าที่ประสานงาน</div>
          </div>
          <div class="mb-3">
            <label class="form-label">รหัสผ่าน</label>
            <input type="password" name="password" class="form-control" minlength="8" required>
            <div class="form-text">อย่างน้อย 8 ตัวอักษร</div>
          </div>
          <div class="mb-4">
            <label class="form-label">ยืนยันรหัสผ่าน</label>
            <input type="password" name="password_confirm" class="form-control" minlength="8" required>
          </div>
          <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg">สมัครสมาชิก</button>
          </div>
          <div class="text-center mt-3">
            มีบัญชีแล้ว? <a href="<?= e(base_url('auth/login.php')) ?>">เข้าสู่ระบบ</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php render_footer(); ?>
