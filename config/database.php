<?php
/**
 * ENERGUIDE - Database + Shared Helpers
 */

$host = 'localhost';
$db   = 'energuide';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed. Please check config/database.php");
}

/* ============================================================
   RATE HELPERS
   The partner establishment uses one BENECO customer classification
   across its six rooms. Confirm the constant against the actual bill.
   ============================================================ */
if (!defined('ENERGUIDE_RATE_CATEGORY')) {
    define('ENERGUIDE_RATE_CATEGORY', 'Commercial High Voltage');
}

function getActiveRate($pdo, $category = ENERGUIDE_RATE_CATEGORY) {
    $stmt = $pdo->prepare("SELECT rate_per_kwh FROM electricity_rates WHERE status='Active' AND rate_category=? ORDER BY effective_date DESC, rate_id DESC LIMIT 1");
    $stmt->execute([$category]);
    $r = $stmt->fetchColumn();
    return $r !== false ? (float)$r : null;
}

function getRateForPeriod($pdo, $period, $category = ENERGUIDE_RATE_CATEGORY) {
    // Match BOTH billing month and the establishment's BENECO customer category.
    // Never substitute a rate from another category or billing month.
    $period = trim((string)$period);
    $category = trim((string)$category);
    if ($period === '' || $category === '') return null;
    $stmt = $pdo->prepare("SELECT rate_per_kwh FROM electricity_rates WHERE billing_month=? AND rate_category=? ORDER BY effective_date DESC, rate_id DESC LIMIT 1");
    $stmt->execute([$period, $category]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (float)$v : null;
}

/* ============================================================
   READING HELPERS
   ============================================================ */
function getPreviousReading($pdo, $room_id, $reading_date = null) {
    if ($reading_date) {
        $stmt = $pdo->prepare("SELECT current_reading FROM meter_readings WHERE room_id=? AND reading_date < ? ORDER BY reading_date DESC, reading_id DESC LIMIT 1");
        $stmt->execute([$room_id, $reading_date]);
    } else {
        $stmt = $pdo->prepare("SELECT current_reading FROM meter_readings WHERE room_id=? ORDER BY reading_date DESC, reading_id DESC LIMIT 1");
        $stmt->execute([$room_id]);
    }
    $v = $stmt->fetchColumn();
    return $v !== false ? (float)$v : 0.00;
}

/* ============================================================
   FORMATTERS
   ============================================================ */
function peso($n) { return '₱' . number_format((float)$n, 2); }

function pctChange($current, $previous) {
    if ($previous == 0 || $previous === null) return null;
    return round((($current - $previous) / $previous) * 100, 1);
}
?>