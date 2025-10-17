<?php
// /tasks/calendar.php
declare(strict_types=1);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

render_header('ปฏิทินงาน');
$u = current_user();

// ค่าเริ่มต้นของฟิลเตอร์
$mine        = isset($_GET['mine']) ? (int)$_GET['mine'] : 0; // 0 = ทั้งหมด
$event_types = $_GET['types']  ?? ['draft', 'final', 'launch'];
$status_in   = $_GET['status'] ?? ['new', 'in_progress', 'review', 'approved', 'scheduled', 'done', 'cancelled'];
$assignee_q  = trim($_GET['assignee'] ?? ''); // <— ช่องค้นหาชื่อผู้รับมอบหมาย
?>
<div class="card shadow-sm border-0 mb-3">
  <div class="card-header bg-light fw-semibold">ตัวกรอง</div>
  <div class="card-body">
    <form id="filterForm" method="get" class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">ขอบเขต</label>
        <select name="mine" class="form-select">
          <option value="1" <?= $mine === 1 ? 'selected' : '' ?>>เฉพาะงานที่มอบหมายให้ฉัน</option>
          <option value="2" <?= $mine === 2 ? 'selected' : '' ?>>งานที่ฉันเกี่ยวข้อง (มอบหมาย/ฉันเป็นผู้สั่ง)</option>
          <option value="0" <?= $mine === 0 ? 'selected' : '' ?>>งานทั้งหมด</option>
        </select>
      </div>

      <div class="col-md-5">
        <label class="form-label">ชนิดเหตุการณ์</label>
        <div class="d-flex flex-wrap gap-3">
          <?php
            $type_all = ['draft' => 'Draft แรก', 'final' => 'อนุมัติสุดท้าย', 'launch' => 'วันเผยแพร่จริง'];
            foreach ($type_all as $k => $v):
          ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="types[]" value="<?= e($k) ?>"
              <?= in_array($k, (array)$event_types, true) ? 'checked' : '' ?> id="t_<?= e($k) ?>">
            <label class="form-check-label" for="t_<?= e($k) ?>"><?= e($v) ?></label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-md-4">
        <label class="form-label">สถานะงาน</label>
        <div class="d-flex flex-wrap gap-3">
          <?php
            $status_all = ['new'=>'ใหม่','in_progress'=>'กำลังทำ','review'=>'รอตรวจ','approved'=>'อนุมัติ','scheduled'=>'ตั้งเวลา','done'=>'เสร็จ','cancelled'=>'ยกเลิก'];
            foreach ($status_all as $k=>$v):
          ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="status[]" value="<?= e($k) ?>"
              <?= in_array($k, (array)$status_in, true) ? 'checked' : '' ?> id="s_<?= e($k) ?>">
            <label class="form-check-label" for="s_<?= e($k) ?>"><?= e($v) ?></label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-md-4">
        <label class="form-label">ผู้รับมอบหมาย (ชื่อ)</label>
        <input type="text" name="assignee" class="form-control" value="<?= e($assignee_q) ?>" placeholder="พิมพ์ชื่อที่ต้องการกรอง เช่น ณัฐพล">
        <div class="form-text">ปล่อยว่างเพื่อดูทั้งหมด / กรอกเพื่อกรองเฉพาะชื่อผู้รับมอบหมาย</div>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2">
        <a href="<?= e(base_url('tasks/calendar.php')) ?>" class="btn btn-outline-secondary">ล้าง</a>
        <button class="btn btn-primary">ใช้ตัวกรอง</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm border-0">
  <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
    <span>ปฏิทินงาน</span>
    <div class="small text-muted">คลิกเหตุการณ์เพื่อดูรายละเอียด</div>
  </div>
  <div class="card-body">
    <div id="calendar"></div>
  </div>
</div>

<!-- Modal -->
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
document.addEventListener('DOMContentLoaded', function(){
  const calendarEl = document.getElementById('calendar');
  const form = document.getElementById('filterForm');

  const calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    themeSystem: 'bootstrap5',
    timeZone: 'Asia/Bangkok',
    height: 'auto',
    firstDay: 1,
    dayMaxEvents: true,
    fixedWeekCount: false,
    headerToolbar: {
      left: 'prev,next today',
      center: 'title',
      right: 'dayGridMonth,dayGridWeek,dayGridDay'
    },

    // ใช้ฟังก์ชันเพื่อประกอบพารามิเตอร์จากฟอร์ม (รวม assignee)
    events: function(fetchInfo, success, failure){
      const params = new URLSearchParams();
      params.set('start', fetchInfo.startStr);
      params.set('end',   fetchInfo.endStr);

      // อ่านค่าฟอร์มปัจจุบัน
      const fd = new FormData(form);
      params.set('mine', fd.get('mine') || '0');

      // types[]
      const types = fd.getAll('types[]');
      (types.length ? types : ['draft','final','launch']).forEach(t => params.append('types[]', t));

      // status[]
      const status = fd.getAll('status[]');
      (status.length ? status : ['new','in_progress','review','approved','scheduled','done','cancelled'])
        .forEach(s => params.append('status[]', s));

      // assignee (ชื่อ)
      const asg = (fd.get('assignee') || '').trim();
      if (asg) params.set('assignee', asg);

      fetch('events.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' }})
        .then(r => r.json())
        .then(data => {
          console.log('Loaded events:', data);
          success(Array.isArray(data) ? data : (Array.isArray(data?.events) ? data.events : []));
        })
        .catch(err => { console.error(err); failure(err); });
    },
    eventContent: function(arg) {
  // ชนิดเหตุการณ์ (มาจาก events.php → extendedProps.type = draft/final/launch)
  const type = (arg.event.extendedProps?.type || '').toLowerCase();
  const typeMap = {
    draft:  { label: 'Draft',  cls: 'badge-amber'  },
    final:  { label: 'Final',  cls: 'badge-maroon' },
    launch: { label: 'Launch', cls: 'badge-green'  }
  };
  const tCfg = typeMap[type] || { label: type || 'Task', cls: 'badge-maroon' };

  // ถ้ามี task_code ใน extendedProps ใช้เป็นรหัส (เสริมในบรรทัด badge)
  const code = arg.event.extendedProps?.task_code || arg.event.extendedProps?.taskCode || '';

  // ชื่อเรื่อง: ใช้ title จาก event (คุณสามารถส่ง title สั้นมาจาก events.php ก็ได้)
  const title = arg.event.title || '';

  // โครงสร้าง DOM
  const wrap   = document.createElement('div');
  wrap.className = 'calendar-pill';

  // แถว badge
  const badges = document.createElement('div');
  badges.className = 'chip-badges';

  const badgeType = document.createElement('span');
  badgeType.className = 'badge badge-pill ' + tCfg.cls;
  badgeType.textContent = '[' + tCfg.label + ']';
  badges.appendChild(badgeType);

  if (code) {
    const badgeCode = document.createElement('span');
    badgeCode.className = 'badge badge-pill text-bg-light';
    badgeCode.textContent = code;
    badges.appendChild(badgeCode);
  }

  // บรรทัดชื่อเรื่อง (หลายบรรทัดได้)
  const titleEl = document.createElement('p');
  titleEl.className = 'chip-title mb-0';
  titleEl.textContent = title;

  // tooltip รวม
  wrap.title = `${tCfg.label}${code ? ' • '+code : ''} — ${title}`;

  wrap.appendChild(badges);
  wrap.appendChild(titleEl);
  return { domNodes: [wrap] };
},


    eventClick: async function(info){
      info.jsEvent.preventDefault();
      const id = info.event.extendedProps.task_id;
      if(!id) return;
      const modalBody = document.getElementById('taskModalBody');
      modalBody.innerHTML = 'กำลังโหลด...';
      const myModal = new bootstrap.Modal(document.getElementById('taskModal'));
      myModal.show();
      try{
        const res = await fetch('view.php?id=' + encodeURIComponent(id), { headers:{'X-Requested-With':'XMLHttpRequest'} });
        modalBody.innerHTML = await res.text();
      }catch(e){
        modalBody.innerHTML = '<div class="text-danger">โหลดรายละเอียดไม่สำเร็จ</div>';
      }
    }
  });

  // เมื่อกด “ใช้ตัวกรอง” อยากให้ reload calendar ด้วยค่าฟอร์มปัจจุบัน
  form.addEventListener('submit', function(e){
    // ให้ส่ง GET ตามเดิม (เพื่อเก็บค่าใน URL) และรีเฟรชทั้งหน้า
    // ถ้าต้องการแบบไม่รีเฟรชหน้า ให้ e.preventDefault(); แล้วเรียก calendar.refetchEvents();
  });

  calendar.render();
});
</script>

<?php render_footer(); ?>
