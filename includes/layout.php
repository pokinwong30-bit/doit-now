<?php
// includes/layout.php
declare(strict_types=1);
require_once __DIR__ . '/guard.php';
$appName = 'Team Task App';

function render_header(string $title = ''): void
{
    global $appName;
    $u = current_user();
?>
    <!doctype html>
    <html lang="th" data-bs-theme="light">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title ? "$title | $appName" : $appName) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
        <link href="<?= e(base_url('assets/css/theme.css')) ?>" rel="stylesheet">
        <!-- FullCalendar (Global build) -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

        <!-- Plugins: Bootstrap5 theme + DayGrid + Interaction (drag/select) -->
        <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/bootstrap5@6.1.15/index.global.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/daygrid@6.1.15/index.global.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/interaction@6.1.15/index.global.min.js"></script>
        <!-- Prompt font -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <!-- เลือกน้ำหนักที่ใช้จริง เพื่อลดโหลด (ปรับได้) -->
        <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    </head>

    <body>
        <nav class="navbar navbar-expand-lg navbar-dark bg-maroon shadow-sm">
            <div class="container">
                <a class="navbar-brand fw-bold" href="<?= e(base_url('dashboard.php')) ?>"><?= e($appName) ?></a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topnav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="topnav">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        <li class="nav-item"><a class="nav-link" href="<?= e(base_url('dashboard.php')) ?>">Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= e(base_url('/tasks/create.php')) ?>">Work order</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= e(base_url('/tasks/index.php')) ?>">All works</a></li>
                    </ul>

                    <ul class="navbar-nav">
                        <?php if ($u): ?>
                            <li class="nav-item me-2"><span class="navbar-text">
                                    สวัสดี, <strong><?= e($u['name']) ?></strong>
                                </span></li>
                            <li class="nav-item"><a class="btn btn-sm btn-outline-light" href="<?= e(base_url('logout.php')) ?>">ออกจากระบบ</a></li>
                        <?php else: ?>
                            <li class="nav-item me-2"><a class="btn btn-sm btn-outline-light" href="<?= e(base_url('auth/login.php')) ?>">เข้าสู่ระบบ</a></li>
                            <li class="nav-item"><a class="btn btn-sm btn-light" href="<?= e(base_url('auth/register.php')) ?>">สมัครสมาชิก</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </nav>
        <main class="py-4">
            <div class="container">
            <?php
        }

        function render_footer(): void
        {
            ?>
            </div>
        </main>
        <footer class="mt-auto py-4 bg-maroon text-white-50">
            <div class="container small text-center">
                © <?= date('Y') ?> Team Task App — All rights reserved.
            </div>
        </footer>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>

    </html>
<?php
        }
