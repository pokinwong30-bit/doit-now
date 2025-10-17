<?php
declare(strict_types=1);

/* --- session secure --- */
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'domain' => '',
    'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* --- auth helpers --- */
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function is_role(string $role): bool { $u = current_user(); return $u && ($u['role'] ?? 'employee') === $role; }
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * Normalize position (trim + lowercase) for comparisons.
 */
function normalize_position(?string $position): string
{
    $position = (string)$position;
    return strtolower(trim($position));
}

/**
 * Determine whether the provided (or current) user has at least manager-level access.
 * Allowed positions: "manager", "senior manager" and any position containing the word "director".
 */
function is_manager_or_higher(?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }

    $position = normalize_position($user['position'] ?? '');
    if ($position === '') {
        return false;
    }

    if (str_contains($position, 'director')) {
        return true;
    }

    return in_array($position, ['manager', 'senior manager'], true);
}

/* =========================
   URL helpers (no .env)
   ========================= */

/** คืนค่า base path ของแอป เช่น "/taskapp" หรือ "" (root) โดยหาจากตำแหน่งไฟล์จริง */
function app_base_path(): string {
    $doc = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : null;
    $app = realpath(dirname(__DIR__)); // โฟลเดอร์รากโปรเจ็กต์ (ที่มี /includes)
    if (!$doc || !$app) return '';
    $doc = rtrim(str_replace('\\','/', $doc), '/');
    $app = rtrim(str_replace('\\','/', $app), '/');
    if (str_starts_with($app, $doc)) {
        $rel = substr($app, strlen($doc));          // เช่น "/taskapp"
        return rtrim($rel, '/');                    // "" หรือ "/taskapp"
    }
    return ''; // fallback
}

/** คืน URL แบบ path สำหรับ href/src เช่น "/taskapp/auth/login.php" หรือ "/auth/login.php" */
function base_url(string $path = ''): string {
    $base = app_base_path();
    $path = '/' . ltrim($path, '/');
    return ($base !== '') ? $base . $path : $path;
}

/** คืน absolute URL สำหรับ redirect เช่น "https://domain.com/taskapp/xxx" */
function absolute_url(string $path = ''): string {
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'))
              ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . base_url($path);
}

/** redirect ปลอดภัย ไม่ง้อ APP_URL */
function go(string $path): void {
    $url = absolute_url($path);
    if (!headers_sent()) header('Location: ' . $url, true, 302);
    exit;
}

/** บังคับล็อกอิน */
function require_login(): void {
    if (!current_user()) go('auth/login.php');
}
