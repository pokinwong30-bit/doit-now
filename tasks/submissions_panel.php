<?php
// tasks/submissions_panel.php
// แสดงฟอร์มส่งงานและประวัติการส่งในรูปแบบการ์ดสำหรับแสดงในป็อปอัปจากหน้าตารางงานรวม

declare(strict_types=1);

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login();

$taskId = (int)($_GET['id'] ?? 0);
if ($taskId <= 0) {
    http_response_code(400);
    exit('ไม่พบงานที่ต้องการ');
}

$stmt = $pdo->prepare('
    SELECT t.id, t.task_code, t.title, t.description, t.assignee_id
    FROM tasks t
    WHERE t.id = ?
    LIMIT 1
');
$stmt->execute([$taskId]);
$task = $stmt->fetch();
if (!$task) {
    http_response_code(404);
    exit('ไม่พบงานที่ต้องการ');
}

$sstmt = $pdo->prepare('
    SELECT ts.*, sub.name AS submitter_name, rev.name AS reviewer_name
    FROM task_submissions ts
    LEFT JOIN users sub ON sub.id = ts.submitter_id
    LEFT JOIN users rev ON rev.id = ts.reviewed_by
    WHERE ts.task_id = ?
    ORDER BY ts.version DESC, ts.id DESC
');
$sstmt->execute([$taskId]);
$submissions = $sstmt->fetchAll();

$user = current_user();
$canReview = $user ? is_director_level($user) : false;

$latestSubmission = $submissions[0] ?? null;
$nextVersion = $latestSubmission ? ((int)$latestSubmission['version'] + 1) : 1;

$pendingSubmissions = [];
foreach ($submissions as $submission) {
    if (($submission['status'] ?? '') === 'pending') {
        $pendingSubmissions[] = $submission;
    }
}
$firstPendingSubmission = $pendingSubmissions[0] ?? null;

$reviewTokens = [];
if ($canReview && $pendingSubmissions) {
    foreach ($pendingSubmissions as $submission) {
        $reviewTokens[(int)$submission['id']] = csrf_token('review_submission_' . $submission['id']);
    }
}

$flashKey = isset($_GET['flash']) ? trim((string)$_GET['flash']) : '';
$flashMessage = null;
if ($flashKey !== '') {
    $flashMessage = match ($flashKey) {
        'submitted' => ['type' => 'success', 'text' => 'บันทึกการส่งงานเรียบร้อยแล้ว'],
        'approved'  => ['type' => 'success', 'text' => 'อนุมัติผลงานเรียบร้อยแล้ว'],
        'revision'  => ['type' => 'warning', 'text' => 'บันทึกคำขอแก้ไขงานแล้ว'],
        default     => null,
    };
}

function th_date_long(?string $dt): string
{
    if (!$dt) {
        return '-';
    }

    $ts = strtotime($dt);
    if ($ts <= 0) {
        return '-';
    }

    $months = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    return (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543) . ' เวลา ' . date('H:i', $ts) . ' น.';
}

function human_filesize(int $bytes, int $dec = 1): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pos = 0;
    while ($bytes >= 1024 && $pos < count($units) - 1) {
        $bytes /= 1024;
        $pos++;
    }

    return number_format($bytes, $dec) . ' ' . $units[$pos];
}

function submission_status_meta(?string $status): array
{
    return match ($status) {
        'approved' => ['class' => 'text-bg-success', 'label' => 'ผ่านอนุมัติ', 'border' => 'border-success'],
        'revision_required' => ['class' => 'text-bg-danger', 'label' => 'ขอแก้ไข', 'border' => 'border-warning'],
        'pending' => ['class' => 'text-bg-warning text-dark', 'label' => 'รออนุมัติ', 'border' => 'border-info'],
        default => ['class' => 'text-bg-secondary', 'label' => 'ยังไม่เคยส่งงาน', 'border' => 'border-secondary'],
    };
}

?>
<div id="taskSubmissionRoot" class="task-submission-root" data-task-id="<?= (int)$task['id'] ?>">
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-3 gap-2">
        <div>
          <h5 class="mb-0">ส่งงานสำหรับ <?= e($task['task_code']) ?></h5>
          <div class="text-muted small"><?= e($task['title']) ?></div>
        </div>
        <span class="badge bg-secondary flex-shrink-0">ครั้งถัดไป #<?= (int)$nextVersion ?></span>
      </div>
      <div data-role="feedback"></div>
      <?php if ($flashMessage): ?>
        <div class="alert alert-<?= e($flashMessage['type']) ?>"><?= e($flashMessage['text']) ?></div>
      <?php endif; ?>
      <form id="taskSubmissionForm" method="post" enctype="multipart/form-data" class="bg-light-subtle border rounded p-3">
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
          <button type="submit" class="btn btn-primary" data-role="submit-work">
            <i class="bi bi-upload me-1"></i> บันทึกการส่งงาน
          </button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($canReview): ?>
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-body">
        <h6 class="fw-semibold mb-3">ตรวจงาน</h6>
        <?php if ($pendingSubmissions): ?>
          <div class="alert alert-info d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-2 mb-3" data-role="review-empty">
            <div>
              <div><strong>คลิกการ์ดที่สถานะ "รออนุมัติ"</strong> เพื่อบันทึกผลการตรวจงาน</div>
              <div class="small text-muted">มีงานรอตรวจ <?= count($pendingSubmissions) ?> รายการ</div>
            </div>
            <i class="bi bi-hand-index-thumb fs-3 text-info"></i>
          </div>
          <form id="taskReviewForm" method="post" class="border rounded p-3 bg-light-subtle d-none" data-role="review-form">
            <input type="hidden" name="_csrf" value="">
            <input type="hidden" name="submission_id" value="">
            <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
            <input type="hidden" name="status" value="">
            <div class="mb-3">
              <div class="fw-semibold mb-1">การส่งงานครั้งที่ <span data-role="selected-version">-</span></div>
              <div class="small text-muted" data-role="selected-submitter"></div>
              <div class="small text-muted" data-role="selected-sent-at"></div>
            </div>
            <div class="mb-3">
              <label class="form-label">ความคิดเห็น</label>
              <textarea name="comment" class="form-control" rows="3" placeholder="สรุปข้อเสนอแนะหรือเงื่อนไขเพิ่มเติม"></textarea>
              <div class="form-text">จำเป็นต้องกรอกเมื่อกดปุ่ม "ขอแก้ไข"</div>
            </div>
            <div class="d-flex flex-column flex-md-row gap-2 justify-content-md-end">
              <button type="submit" class="btn btn-success" data-status="approved">
                <i class="bi bi-check-circle me-1"></i> Approved
              </button>
              <button type="submit" class="btn btn-outline-warning text-dark" data-status="revision_required">
                <i class="bi bi-arrow-counterclockwise me-1"></i> ขอแก้ไข
              </button>
            </div>
          </form>
        <?php else: ?>
          <div class="alert alert-success mb-0">ไม่มีงานที่รอตรวจในขณะนี้</div>
        <?php endif; ?>
      </div>
    </div>
  <?php elseif ($firstPendingSubmission): ?>
    <div class="alert alert-info">
      <div><strong>ครั้งที่ <?= (int)$firstPendingSubmission['version'] ?></strong> ส่งเมื่อ <?= e(th_date_long($firstPendingSubmission['created_at'] ?? null)) ?></div>
      <div class="small text-muted">โดย <?= e($firstPendingSubmission['submitter_name'] ?: 'ไม่ทราบชื่อ') ?> — รอผู้อำนวยการตรวจสอบ</div>
    </div>
  <?php endif; ?>

  <div class="mb-3 d-flex justify-content-between align-items-center">
    <h6 class="fw-semibold mb-0">ประวัติการส่งงาน</h6>
    <span class="badge bg-secondary">ทั้งหมด <?= count($submissions) ?> รายการ</span>
  </div>

  <?php if ($submissions): ?>
    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
      <?php foreach ($submissions as $submission): ?>
        <?php
          $meta = submission_status_meta($submission['status'] ?? null);
          $status = (string)($submission['status'] ?? '');
          $isPending = $status === 'pending';
          $cardClasses = 'card h-100 shadow-sm border ' . $meta['border'] . ' submission-card';
          if ($canReview && $isPending) {
              $cardClasses .= ' submission-card--actionable';
          }
          $submissionId = (int)$submission['id'];
          $submitterName = $submission['submitter_name'] ?: 'ไม่ทราบชื่อ';
          $submittedText = th_date_long($submission['created_at'] ?? null);
          $reviewToken = $reviewTokens[$submissionId] ?? '';
        ?>
        <div class="col">
          <div
            class="<?= e($cardClasses) ?>"
            data-role="submission-card"
            data-submission-id="<?= $submissionId ?>"
            data-status="<?= e($status) ?>"
            data-version="<?= e((string)($submission['version'] ?? '')) ?>"
            data-submitter="<?= e($submitterName) ?>"
            data-created-at-text="<?= e($submittedText) ?>"
            data-review-token="<?= e($reviewToken) ?>"
          >
            <div class="card-body d-flex flex-column">
              <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                  <span class="badge text-bg-secondary me-2">ครั้งที่ <?= (int)$submission['version'] ?></span>
                  <span class="fw-semibold"><?= e($submitterName) ?></span>
                </div>
                <small class="text-muted text-end"><?= e($submittedText) ?></small>
              </div>

              <?php if ($canReview && $isPending): ?>
                <div class="alert alert-warning py-2 small d-flex align-items-center gap-2">
                  <i class="bi bi-hand-index-thumb"></i>
                  <span>คลิกการ์ดนี้เพื่อตรวจงาน</span>
                </div>
              <?php endif; ?>

              <?php if (!empty($submission['note'])): ?>
                <div class="mb-3">
                  <div class="fw-semibold">โน้ต / รายละเอียด</div>
                  <div class="text-muted"><?= nl2br(e($submission['note'])) ?></div>
                </div>
              <?php endif; ?>

              <?php if (!empty($submission['file_path'])): ?>
                <div class="mb-3">
                  <div class="fw-semibold mb-2"><i class="bi bi-paperclip me-1"></i>ไฟล์แนบ</div>
                  <a class="btn btn-sm btn-outline-primary" href="<?= e(base_url($submission['file_path'])) ?>" target="_blank">
                    ดาวน์โหลดไฟล์
                  </a>
                  <div class="small text-muted mt-1">
                    <?= e($submission['original_name'] ?: 'ไม่ระบุชื่อไฟล์') ?>
                    <?php if (!empty($submission['size_bytes'])): ?>
                      • <?= e(human_filesize((int)$submission['size_bytes'])) ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>

              <div class="mt-auto pt-3 border-top">
                <span class="badge rounded-pill <?= e($meta['class']) ?>"><?= e($meta['label']) ?></span>
                <?php if (!empty($submission['review_comment'])): ?>
                  <div class="small text-muted mt-2">ความคิดเห็น: <?= e($submission['review_comment']) ?></div>
                <?php endif; ?>
                <?php if (!empty($submission['reviewer_name'])): ?>
                  <div class="small text-muted mt-1">
                    โดย <?= e($submission['reviewer_name']) ?>
                    <?php if (!empty($submission['reviewed_at'])): ?>
                      — <?= e(th_date_long($submission['reviewed_at'])) ?>
                    <?php endif; ?>
                  </div>
                <?php elseif (($submission['status'] ?? '') === 'pending'): ?>
                  <div class="small text-muted mt-1">รอผู้อำนวยการตรวจสอบ</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="text-muted">ยังไม่มีการส่งงาน</div>
  <?php endif; ?>
</div>

<?php if ($canReview): ?>
<style>
  #taskSubmissionRoot .submission-card {
    transition: transform 0.15s ease, box-shadow 0.15s ease;
  }
  #taskSubmissionRoot .submission-card--actionable {
    cursor: pointer;
  }
  #taskSubmissionRoot .submission-card--actionable:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.08);
  }
  #taskSubmissionRoot .submission-card--selected {
    border-width: 2px;
    border-color: var(--bs-primary) !important;
    box-shadow: 0 0.75rem 1.5rem rgba(0, 0, 0, 0.12);
  }
</style>
<?php endif; ?>

<script>
(() => {
  const root = document.getElementById('taskSubmissionRoot');
  if (!root) {
    return;
  }

  const taskId = parseInt(root.dataset.taskId || '0', 10);
  if (!taskId) {
    return;
  }

  const feedback = root.querySelector('[data-role="feedback"]');
  const reviewEmpty = root.querySelector('[data-role="review-empty"]');
  const submissionCards = root.querySelectorAll('[data-role="submission-card"]');
  const reviewForm = document.getElementById('taskReviewForm');

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

  const submissionForm = document.getElementById('taskSubmissionForm');
  if (submissionForm) {
    submissionForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearAlert();

      const submitButton = submissionForm.querySelector('[data-role="submit-work"]');
      if (submitButton) {
        submitButton.disabled = true;
      }

      try {
        const formData = new FormData(submissionForm);
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
          if (typeof window.loadSubmissionPanel === 'function') {
            await window.loadSubmissionPanel(taskId, data.flash || 'submitted');
          }
        }
      } catch (err) {
        console.error(err);
        showAlert('danger', 'เกิดข้อผิดพลาด ไม่สามารถส่งงานได้');
      } finally {
        if (submitButton) {
          submitButton.disabled = false;
        }
      }
    });
  }

  if (reviewForm) {
    const statusInput = reviewForm.querySelector('input[name="status"]');
    const csrfInput = reviewForm.querySelector('input[name="_csrf"]');
    const submissionInput = reviewForm.querySelector('input[name="submission_id"]');
    const commentField = reviewForm.querySelector('textarea[name="comment"]');
    const selectedVersionEl = reviewForm.querySelector('[data-role="selected-version"]');
    const selectedSubmitterEl = reviewForm.querySelector('[data-role="selected-submitter"]');
    const selectedSentAtEl = reviewForm.querySelector('[data-role="selected-sent-at"]');
    const reviewButtons = reviewForm.querySelectorAll('button[data-status]');

    const resetReviewButtons = () => {
      reviewButtons.forEach((button) => button.classList.remove('active'));
    };

    const prepareReviewForm = (card) => {
      const token = card.dataset.reviewToken || '';
      if (!token) {
        showAlert('danger', 'ไม่พบโทเคนสำหรับบันทึกผลการตรวจงาน กรุณารีเฟรชหน้านี้');
        return;
      }

      clearAlert();
      reviewForm.dataset.status = '';
      if (statusInput) {
        statusInput.value = '';
      }
      if (csrfInput) {
        csrfInput.value = token;
      }
      if (submissionInput) {
        submissionInput.value = card.dataset.submissionId || '';
      }
      if (commentField) {
        commentField.value = '';
      }
      if (selectedVersionEl) {
        selectedVersionEl.textContent = card.dataset.version || '-';
      }
      if (selectedSubmitterEl) {
        const submitter = card.dataset.submitter || '';
        selectedSubmitterEl.textContent = submitter ? `โดย ${submitter}` : '';
      }
      if (selectedSentAtEl) {
        const sentText = card.dataset.createdAtText || '';
        selectedSentAtEl.textContent = sentText ? `ส่งเมื่อ ${sentText}` : '';
      }

      resetReviewButtons();
      submissionCards.forEach((item) => item.classList.remove('submission-card--selected'));
      card.classList.add('submission-card--selected');

      reviewForm.classList.remove('d-none');
      if (reviewEmpty) {
        reviewEmpty.classList.add('d-none');
      }
    };

    submissionCards.forEach((card) => {
      card.addEventListener('click', () => {
        const status = (card.dataset.status || '').toLowerCase();
        if (status !== 'pending') {
          showAlert('info', 'รายการนี้ได้รับการตรวจแล้ว');
          return;
        }

        prepareReviewForm(card);
      });
    });

    reviewButtons.forEach((button) => {
      button.addEventListener('click', () => {
        reviewButtons.forEach((btn) => btn.classList.remove('active'));
        button.classList.add('active');
        if (statusInput) {
          statusInput.value = button.dataset.status || '';
        }
        reviewForm.dataset.status = button.dataset.status || '';
      });
    });

    reviewForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearAlert();

      const status = reviewForm.dataset.status || (statusInput ? statusInput.value : '');
      if (!status) {
        showAlert('warning', 'กรุณาเลือกผลการตรวจงาน');
        return;
      }

      const comment = commentField ? commentField.value.trim() : '';
      if (status === 'revision_required' && comment === '') {
        showAlert('warning', 'กรุณากรอกความคิดเห็นเมื่อขอแก้ไขงาน');
        return;
      }

      reviewButtons.forEach((button) => { button.disabled = true; });

      try {
        const formData = new FormData(reviewForm);
        formData.set('task_id', String(taskId));
        formData.set('status', status);
        if (commentField) {
          formData.set('comment', comment);
        }
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
          if (typeof window.loadSubmissionPanel === 'function') {
            await window.loadSubmissionPanel(taskId, data.flash || 'approved');
          }
        }
      } catch (err) {
        console.error(err);
        showAlert('danger', 'เกิดข้อผิดพลาด ไม่สามารถบันทึกผลการตรวจงานได้');
      } finally {
        reviewButtons.forEach((button) => { button.disabled = false; });
      }
    });
  }
})();
</script>
