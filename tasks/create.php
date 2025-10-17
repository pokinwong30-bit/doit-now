<?php
// /tasks/create.php
declare(strict_types=1);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

require_once __DIR__ . '/../includes/layout.php';
render_header('สร้างงานการตลาด');

$u = current_user();

/* ========= รหัสงานตัวอย่างล่วงหน้า (ดู AUTO_INCREMENT) ========= */
$ai = null;
try {
  $stmt = $pdo->prepare("SELECT AUTO_INCREMENT AS ai FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' LIMIT 1");
  $stmt->execute();
  $ai = (int)($stmt->fetch()['ai'] ?? 0);
} catch (Throwable $e) { $ai = 0; }
$preview_code = 'TASK-' . date('Ymd') . '-' . str_pad((string)max($ai,1), 6, '0', STR_PAD_LEFT);

/* ========= วันที่สั่งงาน (ไทย พ.ศ.) ========= */
$thMonths = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
$ts = time();
$thDate = (int)date('j', $ts) . ' ' . $thMonths[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543);

/* ========= รายชื่อผู้รับมอบหมาย (users active) ========= */
$assignees = [];
try {
  $q = $pdo->query("SELECT id, name, email FROM users WHERE status='active' ORDER BY name ASC");
  $assignees = $q->fetchAll();
} catch (Throwable $e) { $assignees = []; }
?>
<div class="row">
  <div class="col-lg-8">
    <form class="card shadow-sm border-0" method="post" action="../tasks/store.php" enctype="multipart/form-data" novalidate>
      <div class="card-header bg-light fw-semibold">รายละเอียดงานการตลาด (โครงการหมู่บ้านหรู)</div>

      <div class="card-body">
        <?= csrf_field('task_create') ?>

        <!-- แถวข้อมูลส่วนหัว -->
        <div class="row g-3 mb-2">
          <div class="col-md-6">
            <label class="form-label mb-1">รหัสงาน</label>
            <div class="form-control bg-light"><?= e($preview_code) ?></div>
            <div class="form-text">รหัสจริงจะถูกกำหนดตามเลขที่บันทึก (อัตโนมัติ)</div>
          </div>
          <div class="col-md-6">
            <label class="form-label mb-1">วันที่สั่งงาน</label>
            <div class="form-control bg-light"><?= e($thDate) ?></div>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label mb-1">ผู้สั่งงาน</label>
          <div class="form-control bg-light"><?= e($u['name']) ?></div>
        </div>

        <div class="mb-3">
          <label class="form-label">ชื่องาน <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control" required>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">ประเภทงาน <span class="text-danger">*</span></label>
            <select name="task_type" class="form-select" required>
              <option value="">-- เลือก --</option>
              <option>Creative</option><option>Copywriting</option><option>Media Buy</option>
              <option>PR</option><option>Event</option><option>Video</option>
              <option>Photo</option><option>Digital Asset</option><option>Other</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">ความเร่งด่วน</label>
            <select name="priority" class="form-select">
              <option value="normal">Normal</option>
              <option value="low">Low</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label">มอบหมายงานให้</label>
          <select name="assignee_id" class="form-select">
            <option value="">-- ยังไม่มอบหมาย --</option>
            <?php foreach($assignees as $a): ?>
              <option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?> (<?= e($a['email']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">เลือกผู้รับผิดชอบหลัก (เปลี่ยนได้ภายหลัง)</div>
        </div>

        <div class="mb-3 mt-3">
          <label class="form-label">รายละเอียดงาน/บริบท</label>
          <textarea name="description" class="form-control" rows="4" placeholder="สรุป brief, ความต้องการ, โทน ฯลฯ"></textarea>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">โครงการ/แบรนด์</label>
            <input type="text" name="project_id" class="form-control" placeholder="ใส่รหัส/ชื่อโครงการ">
          </div>
          <div class="col-md-6">
            <label class="form-label">เฟส/แปลงขาย</label>
            <input type="text" name="phase" class="form-control" placeholder="เช่น Phase 2 / Cluster A">
          </div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-md-6">
            <label class="form-label">Key Message</label>
            <input type="text" name="key_message" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">คู่แข่งอ้างอิง</label>
            <input type="text" name="competitors" class="form-control">
          </div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-md-6">
            <label class="form-label">วัตถุประสงค์ (เลือกได้หลายข้อ)</label>
            <select name="objective[]" class="form-select" multiple>
              <option>Awareness</option><option>Lead</option><option>Booking</option><option>Event RSVP</option>
            </select>
            <div class="form-text">กด Ctrl/Command เพื่อเลือกหลายรายการ</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">ช่องทางสื่อ (เลือกได้หลายข้อ)</label>
            <select name="channels[]" class="form-select" multiple>
              <option>Facebook</option><option>Instagram</option><option>LINE OA</option><option>Google Ads</option>
              <option>YouTube</option><option>TikTok</option><option>Website</option><option>PR</option>
              <option>OOH</option><option>Billboard</option><option>KOL</option><option>Print</option>
            </select>
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label">รายการงานที่จะส่งมอบ (Deliverables)</label>
          <div id="deliverables-list" class="vstack gap-2">
            <div class="input-group">
              <input type="text" name="deliverables[]" class="form-control" placeholder="เช่น KV 1080x1350 หรือ Video 15s 9:16">
              <button class="btn btn-outline-primary" type="button" onclick="addDeliverable()">+</button>
            </div>
          </div>
          <div class="form-text">เพิ่มหลายรายการได้</div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-md-4">
            <label class="form-label">Draft แรกกำหนดส่ง <span class="text-danger">*</span></label>
            <input type="datetime-local" name="due_first_draft" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">อนุมัติสุดท้าย</label>
            <input type="datetime-local" name="due_final" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">เผยแพร่จริง</label>
            <input type="datetime-local" name="launch_date" class="form-control">
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label">ข้อความหลัก</label>
          <input type="text" name="copy_headline" class="form-control" placeholder="Headline">
        </div>
        <div class="mt-2">
          <textarea name="copy_body" class="form-control" rows="3" placeholder="Body/รายละเอียดข้อความ"></textarea>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-md-4"><input type="text" name="cta" class="form-control" placeholder="CTA (เช่น นัดชมโครงการ)"></div>
          <div class="col-md-4"><input type="text" name="mandatory_words" class="form-control" placeholder="คำต้องมี"></div>
          <div class="col-md-4"><input type="text" name="forbidden_words" class="form-control" placeholder="คำต้องห้าม"></div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-md-6">
            <label class="form-label">งบสื่อ (บาท)</label>
            <input type="number" step="0.01" min="0" name="media_budget_amount" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">หมายเหตุงบสื่อ</label>
            <input type="text" name="media_budget_note" class="form-control">
          </div>
        </div>

        <div class="mt-3">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="legalCheck" name="reviewer_legal_required" value="1">
            <label class="form-check-label" for="legalCheck">ต้องตรวจข้อกำกับทางกฎหมาย/ลิขสิทธิ์</label>
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label">ไฟล์อ้างอิง / Reference (หลายไฟล์ได้, ≤ 200 MB/ไฟล์)</label>
          <input class="form-control" type="file" name="references[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.ai,.psd,.mp4">
          <div class="form-text">รองรับ .jpg .jpeg .png .webp .pdf .ai .psd .mp4</div>
          <div class="mt-2">
            <input type="text" class="form-control" name="reference_captions[]" placeholder="คำอธิบายไฟล์ (ตัวเลือก) — จับคู่ตามลำดับไฟล์">
          </div>
        </div>

      </div>
      <div class="card-footer bg-light d-flex justify-content-between">
        <a href="<?= e(base_url('dashboard.php')) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
        <button class="btn btn-primary">บันทึกงาน</button>
      </div>
    </form>
  </div>

  <div class="col-lg-4">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-light fw-semibold">สรุป/คำแนะนำ</div>
      <div class="card-body">
        <ul class="mb-0 small">
          <li>รหัสงานจะกำหนดอัตโนมัติเมื่อบันทึก</li>
          <li>วันที่สั่งงาน: <?= e($thDate) ?></li>
          <li>ผู้สั่งงาน: <?= e($u['name']) ?></li>
        </ul>
      </div>
    </div>
  </div>
</div>

<script>
function addDeliverable(){
  const wrap = document.getElementById('deliverables-list');
  const div = document.createElement('div');
  div.className = 'input-group';
  div.innerHTML = `
    <input type="text" name="deliverables[]" class="form-control" placeholder="เช่น Static 1200x628">
    <button class="btn btn-outline-danger" type="button" onclick="this.closest('.input-group').remove()">-</button>
  `;
  wrap.appendChild(div);
}
</script>
<?php render_footer(); ?>
