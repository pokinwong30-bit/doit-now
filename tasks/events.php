<?php
// /tasks/events.php
declare(strict_types=1);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
function out($data){ echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

try {
  $u = current_user();
  $debug = (string)($_GET['debug'] ?? '');

  // ช่วงเวลา (ISO -> Asia/Bangkok)
  $startIso = $_GET['start'] ?? null;
  $endIso   = $_GET['end']   ?? null;
  if (!$startIso || !$endIso) {
    $first = new DateTime('first day of this month 00:00:00');
    $next  = new DateTime('first day of next month 00:00:00');
    $startIso = $first->format(DateTime::ATOM);
    $endIso   = $next->format(DateTime::ATOM);
  }
  $tzDb   = new DateTimeZone('Asia/Bangkok');
  $startD = new DateTime($startIso); $startD->setTimezone($tzDb);
  $endD   = new DateTime($endIso);   $endD->setTimezone($tzDb);
  $start  = $startD->format('Y-m-d H:i:s');
  $end    = $endD->format('Y-m-d H:i:s');

  // ฟิลเตอร์
  $mine        = isset($_GET['mine']) ? (int)$_GET['mine'] : 0;
  $types       = $_GET['types']  ?? ['draft','final','launch'];
  $status      = $_GET['status'] ?? ['new','in_progress','review','approved','scheduled','done','cancelled'];
  $assignee_id = isset($_GET['assignee_id']) && $_GET['assignee_id'] !== '' ? (int)$_GET['assignee_id'] : null;
  $assignee_q  = trim((string)($_GET['assignee'] ?? ''));

  if (!is_array($types))  $types  = [$types];
  if (!is_array($status)) $status = [$status];

  $where  = [];
  $params = [];

  if ($debug !== 'all') {
    $where[] = "(
      (t.due_first_draft IS NOT NULL AND t.due_first_draft >= ? AND t.due_first_draft < ?)
      OR (t.due_final   IS NOT NULL AND t.due_final       >= ? AND t.due_final       < ?)
      OR (t.launch_date IS NOT NULL AND t.launch_date     >= ? AND t.launch_date     < ?)
    )";
    array_push($params, $start, $end, $start, $end, $start, $end);
  }

  // mine
  if ($mine === 1) {
    $where[]  = "t.assignee_id = ?";
    $params[] = (int)$u['id'];
  } elseif ($mine === 2) {
    $where[]  = "(t.assignee_id = ? OR t.requester_id = ?)";
    $params[] = (int)$u['id'];
    $params[] = (int)$u['id'];
  }

  // กรองผู้รับมอบหมาย (id ตรง ๆ)
  if ($assignee_id) {
    $where[]  = "t.assignee_id = ?";
    $params[] = $assignee_id;
  }

  // กรองชื่อผู้รับมอบหมาย (LIKE)
  if ($assignee_q !== '') {
    $where[]  = "asg.name LIKE ?";
    $params[] = '%'.$assignee_q.'%';
  }

  // status
  if ($status && count($status) > 0) {
    $ph = implode(',', array_fill(0, count($status), '?'));
    $where[] = "t.status IN ($ph)";
    foreach ($status as $s) $params[] = $s;
  }

  $sql_where = $where ? ('WHERE '.implode(' AND ', $where)) : '';

  // JOIN users as asg เพื่อค้นด้วยชื่อผู้รับมอบหมาย
  $sql = "SELECT t.id, t.task_code, t.title, t.status,
                 t.due_first_draft, t.due_final, t.launch_date,
                 asg.name AS assignee_name
          FROM tasks t
          LEFT JOIN users asg ON asg.id = t.assignee_id
          $sql_where";

  if ($debug) {
    error_log('[events.php] where='.$sql_where);
    error_log('[events.php] params='.json_encode($params, JSON_UNESCAPED_UNICODE));
  }

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();

  // สีธีม
  $color = [
    'draft'  => '#6c1b2a',
    'final'  => '#f0ad4e',
    'launch' => '#198754',
    'done'   => '#6c757d',
    'cancel' => '#dc3545'
  ];

  $events = [];
  foreach ($rows as $r) {
    $st = (string)$r['status'];

    if (in_array('draft', $types, true) && !empty($r['due_first_draft'])) {
      $events[] = [
        'id' => 'task-'.$r['id'].'-draft',
        'title' => '[Draft] '.$r['task_code'].' — '.$r['title'],
        'start' => date('c', strtotime($r['due_first_draft'])),
        'allDay'=> false,
        'backgroundColor' => ($st==='cancelled' ? $color['cancel'] : ($st==='done' ? $color['done'] : $color['draft'])),
        'borderColor' => 'transparent', 'textColor' => '#fff',
        'extendedProps' => ['type'=>'draft','task_id'=>(int)$r['id'],'status'=>$st],
      ];
    }
    if (in_array('final', $types, true) && !empty($r['due_final'])) {
      $events[] = [
        'id' => 'task-'.$r['id'].'-final',
        'title' => '[Final] '.$r['task_code'].' — '.$r['title'],
        'start' => date('c', strtotime($r['due_final'])),
        'allDay'=> false,
        'backgroundColor' => ($st==='cancelled' ? $color['cancel'] : ($st==='done' ? $color['done'] : $color['final'])),
        'borderColor' => 'transparent', 'textColor' => '#fff',
        'extendedProps' => ['type'=>'final','task_id'=>(int)$r['id'],'status'=>$st],
      ];
    }
    if (in_array('launch', $types, true) && !empty($r['launch_date'])) {
      $events[] = [
        'id' => 'task-'.$r['id'].'-launch',
        'title' => '[Launch] '.$r['task_code'].' — '.$r['title'],
        'start' => date('c', strtotime($r['launch_date'])),
        'allDay'=> false,
        'backgroundColor' => ($st==='cancelled' ? $color['cancel'] : ($st==='done' ? $color['done'] : $color['launch'])),
        'borderColor' => 'transparent', 'textColor' => '#fff',
        'extendedProps' => ['type'=>'launch','task_id'=>(int)$r['id'],'status'=>$st],
      ];
    }
  }

  out($events);

} catch (Throwable $e) {
  error_log('[events.php] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
  out([]);
}
