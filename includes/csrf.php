<?php
// includes/csrf.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function csrf_token(string $key = 'default'): string {
    $bucket = $_SESSION['_csrf'] ?? [];
    if (!isset($bucket[$key])) {
        $bucket[$key] = bin2hex(random_bytes(32));
        $_SESSION['_csrf'] = $bucket;
    }
    return $bucket[$key];
}
function verify_csrf(string $token, string $key = 'default'): bool {
    if (!isset($_SESSION['_csrf'][$key])) return false;
    $ok = hash_equals($_SESSION['_csrf'][$key], $token);
    if ($ok) { // single-use token (เพิ่มความปลอดภัย)
        unset($_SESSION['_csrf'][$key]);
    }
    return $ok;
}
function csrf_field(string $key = 'default'): string {
    $t = csrf_token($key);
    return '<input type="hidden" name="_csrf" value="'.htmlspecialchars($t,ENT_QUOTES).'">';
}
