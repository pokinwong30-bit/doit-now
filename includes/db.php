<?php
// includes/db.php
declare(strict_types=1);

// โหลด .env อย่างง่าย (รองรับ quote และคอมเมนต์ท้ายบรรทัด)
$envPath = dirname(__DIR__) . '/.env';
if (is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $line) {
        if ($line === false) continue;
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;

        if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/', $line, $m)) continue;
        $k = $m[1];
        $v = $m[2];

        // ตัดคอมเมนต์ท้ายบรรทัดกรณีไม่ใส่ quote
        if ($v !== '' && $v[0] !== '"' && $v[0] !== "'") {
            $v = preg_replace('/\s+#.*$/', '', $v);
            $v = trim($v);
        } else {
            // ตัดอัญประกาศรอบค่า
            $v = trim($v);
            $v = trim($v, "\"'");
        }

        // normalize ค่าบางตัว เช่น APP_URL
        if ($k === 'APP_URL') {
            $v = rtrim($v, '/'); // ไม่ให้มี / ท้าย
        }

        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
        putenv("$k=$v");
    }
}

$DB_HOST   = $_ENV['DB_HOST']   ?? 'localhost';
$DB_NAME   = $_ENV['DB_NAME']   ?? 'botani_database';
$DB_USER   = $_ENV['DB_USER']   ?? 'root';
$DB_PASS   = $_ENV['DB_PASS']   ?? 'root';
$DB_CHAR   = $_ENV['DB_CHARSET']?? 'utf8mb4';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHAR}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // โยน Exception เมื่อเกิด error
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // คืนค่าเป็น associative array
    PDO::ATTR_EMULATE_PREPARES   => false,                   // ใช้ native prepares (ปลอดภัยกว่า)
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$DB_CHAR}"
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (Throwable $e) {
    // ควร log ไฟล์จริงจังใน production
    http_response_code(500);
    exit('Database connection failed.');
}
