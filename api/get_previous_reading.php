<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Not authenticated']); exit; }
require '../config/database.php';
$room = filter_input(INPUT_GET, 'room_id', FILTER_VALIDATE_INT);
$date = $_GET['reading_date'] ?? date('Y-m-d');
if (!$room || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { http_response_code(400); echo json_encode(['error'=>'Invalid room or date']); exit; }
$stmt = $pdo->prepare('SELECT current_reading FROM meter_readings WHERE room_id=? AND reading_date < ? ORDER BY reading_date DESC, reading_id DESC LIMIT 1');
$stmt->execute([$room,$date]);
$val=$stmt->fetchColumn();
echo json_encode(['previous_reading'=>$val === false ? null : (float)$val, 'has_previous'=>$val !== false]);
