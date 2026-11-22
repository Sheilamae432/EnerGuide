<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
require 'config/database.php';

$msg = ''; $err = '';
/* Dashboard shortcut: save an actual BENECO bill for the selected room. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_bill') {
    $roomId = (int)($_POST['room_id'] ?? 0);
    $month = trim($_POST['billing_month'] ?? '');
    $date = $_POST['bill_date'] ?? '';
    $kwhInput = trim($_POST['total_kwh'] ?? '');
    $kwh = $kwhInput !== '' ? (float)$kwhInput : null;
    $amount = (float)($_POST['total_amount'] ?? 0);
    $cat = trim($_POST['rate_category'] ?? 'Commercial High Voltage');
    $acct = trim($_POST['account_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $roomCheck = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_id=?");
    $roomCheck->execute([$roomId]);
    if ($roomId <= 0 || (int)$roomCheck->fetchColumn() === 0) {
        $err = "Please select the room that matches this BENECO bill.";
    } elseif ($month === '' || $date === '' || $amount <= 0 || ($kwh !== null && $kwh < 0)) {
        $err = "Please provide the room, billing month, bill date, and a valid total amount.";
    } else {
        $imagePath = null; $originalName = null;
        $upload = $_FILES['bill_image'] ?? null;
        if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($upload['error'] !== UPLOAD_ERR_OK) {
                $err = "The bill image could not be uploaded. Please try again.";
            } elseif ($upload['size'] > 5 * 1024 * 1024) {
                $err = "Bill image must be 5 MB or smaller.";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($upload['tmp_name']);
                $extensions = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
                if (!isset($extensions[$mime])) {
                    $err = "Only JPG, PNG, or WEBP bill images are accepted.";
                } else {
                    $uploadDir = __DIR__ . '/uploads/bills';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                        $err = "Unable to prepare the bill image folder.";
                    } else {
                        $safeName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                        if (move_uploaded_file($upload['tmp_name'], $uploadDir . '/' . $safeName)) {
                            $imagePath = 'uploads/bills/' . $safeName;
                            $originalName = basename($upload['name']);
                        } else { $err = "Unable to save the uploaded bill image."; }
                    }
                }
            }
        }
        if ($err === '') {
            try {
                $pdo->prepare("INSERT INTO beneco_bills (room_id,billing_month,bill_date,total_kwh,total_amount,rate_category,account_number,notes,image_path,image_original_name,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$roomId,$month,$date,$kwh,$amount,$cat,$acct,$notes,$imagePath,$originalName,$_SESSION['user_id']]);
                header("Location: dashboard.php?period=" . urlencode($month) . "&bill_saved=1");
                exit;
            } catch (PDOException $e) {
                if ($imagePath && is_file(__DIR__ . '/' . $imagePath)) unlink(__DIR__ . '/' . $imagePath);
                $err = "The bill could not be saved. Please confirm that the updated EnerGuide database SQL has been imported.";
            }
        }
    }
}
if (isset($_GET['bill_saved'])) $msg = "Actual BENECO bill saved successfully.";

$periodRows = $pdo->query("
    SELECT billing_period AS period_name, MAX(reading_date) AS sort_date
    FROM meter_readings WHERE billing_period IS NOT NULL AND billing_period <> ''
    GROUP BY billing_period
    UNION
    SELECT billing_month AS period_name, MAX(bill_date) AS sort_date
    FROM beneco_bills WHERE billing_month IS NOT NULL AND billing_month <> ''
    GROUP BY billing_month
    ORDER BY sort_date DESC
")->fetchAll(PDO::FETCH_ASSOC);
$periods = array_column($periodRows, 'period_name');
$period = $_GET['period'] ?? '';
if ($period === '' && !empty($periods)) $period = $periods[0];
$rate = getRateForPeriod($pdo, $period);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(consumption_kwh),0) FROM meter_readings WHERE billing_period=?");
$stmt->execute([$period]);
$totalKwh = (float)$stmt->fetchColumn();
$estimatedCost = $rate !== null ? $totalKwh * $rate : null;

$totalUnits = (int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT room_id) FROM meter_readings WHERE billing_period=?");
$stmt->execute([$period]);
$unitsWithReading = (int)$stmt->fetchColumn();
$completeness = $totalUnits > 0 ? round(($unitsWithReading / $totalUnits) * 100) : 0;

$stmt = $pdo->prepare("
    SELECT r.room_id,r.room_name,r.room_type,r.status,
      (SELECT consumption_kwh FROM meter_readings m
       WHERE m.room_id=r.room_id AND m.billing_period=?
       ORDER BY reading_date DESC LIMIT 1) AS kwh
    FROM rooms r ORDER BY r.room_id
");
$stmt->execute([$period]);
$unitData = $stmt->fetchAll(PDO::FETCH_ASSOC);
$rooms = $pdo->query("SELECT room_id,room_name,meter_id FROM rooms ORDER BY room_id")->fetchAll(PDO::FETCH_ASSOC);

$highestUnit = null; $highestKwh = 0;
foreach ($unitData as $u) {
    if ($u['kwh'] !== null && (float)$u['kwh'] > $highestKwh) {
        $highestKwh = (float)$u['kwh']; $highestUnit = $u['room_name'];
    }
}
$billStmt = $pdo->prepare("SELECT b.*,r.room_name FROM beneco_bills b LEFT JOIN rooms r ON r.room_id=b.room_id WHERE b.billing_month=? AND b.room_id IS NOT NULL ORDER BY b.bill_date DESC,b.bill_id DESC");
$billStmt->execute([$period]);
$bills = $billStmt->fetchAll(PDO::FETCH_ASSOC);
$billByRoom = [];
foreach ($bills as $b) if (!isset($billByRoom[$b['room_id']])) $billByRoom[$b['room_id']] = $b;
$actualBillTotal = array_sum(array_map(function($b){return (float)$b['total_amount'];}, $billByRoom));

$menu = [
 'dashboard.php'=>['Dashboard','⌂'],
 'consumption_analysis.php'=>['Consumption Analysis','▥'],
 'predictive_analytics.php'=>['Predictive Analytics','⌁'],
 'reports.php'=>['Reports','▤'],
 'settings.php'=>['Settings','⚙']
];
$current = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard | EnerGuide</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--green-dark:#14532d;--green:#21833b;--green-mid:#2e9b46;--green-light:#e8f5e9;--yellow:#facc15;--yellow-soft:#fff8d6;--ink:#17231b;--muted:#647267;--line:#e5ece6;--white:#fff}
*{box-sizing:border-box;font-family:Inter,"Segoe UI",Arial,sans-serif}
body{margin:0;background:#f3f7f3;color:var(--ink);min-height:100vh}
.sidebar{position:fixed;inset:0 auto 0 0;width:248px;background:linear-gradient(165deg,#14532d,#0d4025);color:#fff;padding:24px 14px;display:flex;flex-direction:column;z-index:5}
.brand{display:flex;gap:11px;align-items:center;padding:3px 10px 25px;border-bottom:1px solid #ffffff30;margin-bottom:22px}
.brand-mark{width:43px;height:43px;border-radius:13px;background:var(--yellow);color:var(--green-dark);display:grid;place-items:center;font-size:25px;font-weight:900}
.brand strong{display:block;font-size:21px;letter-spacing:.3px}.brand small{display:block;font-size:10px;color:#d9eddd;margin-top:3px}
.nav{display:grid;gap:7px}.nav a{color:#eaf5ec;text-decoration:none;padding:13px 14px;border-radius:10px;font-size:14px;display:flex;align-items:center;gap:13px;transition:.18s}
.nav a:hover{background:#ffffff18}.nav a.active{background:var(--yellow);color:#174b28;font-weight:750}.nav-ico{width:23px;text-align:center;font-size:18px}
.logout{margin-top:auto;color:#fff;text-decoration:none;padding:14px;border-top:1px solid #ffffff30}
.main{margin-left:248px;padding:24px 28px 35px;max-width:1600px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;background:#fff;border:1px solid var(--line);border-radius:15px;padding:14px 20px;margin-bottom:22px;box-shadow:0 3px 12px #153c1e08}
.top-left{display:flex;align-items:center;gap:14px}.hamb{border:0;background:none;color:var(--green-dark);font-size:24px;cursor:pointer}.topbar h1{font-size:23px;margin:0;color:#153e24}.topbar p{font-size:12px;color:var(--muted);margin:4px 0 0}
.top-right{display:flex;align-items:center;gap:12px}.period-form{display:flex;align-items:center;gap:8px}.period-form label{font-size:12px;font-weight:700;color:var(--green-dark)}select,input,textarea{font:inherit}.period-form select{padding:9px 12px;border:1px solid #d8e2d9;border-radius:9px;background:white}
.owner{font-size:13px;font-weight:700;color:#34513b;background:#f1f7f1;border-radius:22px;padding:9px 13px}
.notice{padding:12px 15px;border-radius:10px;margin:0 0 16px;font-size:13px}.success{background:#e8f5e9;color:#1b5e20;border-left:4px solid #2e7d32}.error{background:#ffebee;color:#b71c1c;border-left:4px solid #c62828}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:22px}
.stat{background:#fff;border:1px solid var(--line);border-radius:15px;padding:17px 18px;box-shadow:0 3px 12px #153c1e07;min-width:0}
.stat-top{display:flex;align-items:center;gap:11px}.stat-icon{width:46px;height:46px;border-radius:50%;display:grid;place-items:center;font-size:22px;background:#e5f5e8;color:#18823a;flex-shrink:0}.stat:nth-child(2) .stat-icon{background:#fff4c4;color:#b88700}.stat:nth-child(3) .stat-icon{background:#e9f1ff;color:#2864bd}.stat:nth-child(4) .stat-icon{background:#f0f6e8;color:#58862b}
.stat h4{font-size:12px;color:#526257;margin:0;font-weight:650}.stat .value{font-size:23px;font-weight:800;margin:8px 0 0;color:#17251b;overflow-wrap:anywhere}.stat small{display:block;color:var(--muted);font-size:11px;margin-top:5px}
.section-title{font-size:17px;color:#1b3522;margin:0 0 12px;display:flex;align-items:center;gap:9px}.section-title:before{content:"";width:5px;height:20px;background:var(--green);border-radius:5px}
.content-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:16px}.panel{background:#fff;border:1px solid var(--line);border-radius:15px;padding:18px;box-shadow:0 3px 12px #153c1e07;min-width:0}.panel h3{font-size:15px;color:#1b3e26;margin:0 0 15px}.chart-wrap{height:285px;position:relative}
.room-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px;margin-top:16px}.room-card{border:1px solid var(--line);border-radius:12px;padding:13px;background:#fbfdfb}.room-head{display:flex;justify-content:space-between;gap:6px;align-items:center}.room-card h4{margin:0;color:#1c5a2c;font-size:13px}.room-card .kwh{font-size:20px;font-weight:800;margin:10px 0 4px}.room-card small{color:var(--muted);font-size:11px}.pill{font-size:10px;padding:4px 7px;border-radius:20px;background:#edf5ee;color:#236236;white-space:nowrap}
.bill-summary{margin-top:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;background:#f5faf5;border:1px solid #e2eee3;border-radius:12px}.bill-summary strong{color:#1d542b}.bill-summary .amount{font-size:20px;font-weight:800;color:#173d22}
.modal-backdrop{position:fixed;inset:0;background:#10221655;display:none;align-items:stretch;justify-content:flex-end;padding:0;z-index:20}.modal-backdrop.open{display:flex}\n.modal{width:min(500px,100%);height:100vh;max-height:100vh;overflow:auto;background:#fff;border-radius:17px 0 0 17px;box-shadow:-12px 0 40px #0002;padding:24px}
.modal-head{display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid var(--yellow);padding-bottom:13px;margin-bottom:15px}.modal-head h2{font-size:19px;color:#14532d;margin:0}.close{border:0;background:none;font-size:25px;cursor:pointer;color:#536258}
.modal p.help{font-size:13px;color:#5f6e62;line-height:1.5;margin:0 0 16px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}.field{margin-bottom:12px}.field.full{grid-column:1/-1}.field label{display:block;font-size:12px;font-weight:700;color:#1b5e20;margin-bottom:6px}.field input,.field select,.field textarea{width:100%;padding:11px 12px;border:1.5px solid #d8dfd9;border-radius:9px;background:#fff;font-size:13px}.field input:focus,.field select:focus,.field textarea:focus{outline:2px solid #b9dfbf;border-color:#2e7d32}.field textarea{min-height:75px;resize:vertical}.modal-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:7px}.btn{border:0;border-radius:9px;padding:11px 17px;font-weight:700;cursor:pointer}.btn-cancel{background:#f1f3f1;color:#344238}.btn-save{background:#178139;color:#fff}.btn-save:hover{background:#11642c}
@media(max-width:1100px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.content-grid{grid-template-columns:1fr}}
@media(max-width:760px){.sidebar{width:70px;padding:18px 8px}.brand{justify-content:center;padding:0 0 18px}.brand-copy,.nav-label,.eg-logout-label{display:none}.brand-mark{width:42px;height:42px}.nav a{justify-content:center;padding:13px 6px}.nav-ico{font-size:20px}.main{margin-left:70px;padding:14px}.topbar{align-items:flex-start;flex-direction:column}.top-right{width:100%;justify-content:space-between;flex-wrap:wrap}.room-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:480px){.modal{width:100%;border-radius:0;padding:17px}.stats{grid-template-columns:1fr 1fr;gap:9px}.stat{padding:13px}.stat .value{font-size:18px}.stat-icon{width:36px;height:36px;font-size:18px}.room-grid{grid-template-columns:1fr 1fr}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.modal{padding:17px}}
.eg-logout{display:flex!important;align-items:center;gap:10px}.eg-logout-icon{font-size:20px;line-height:1}.eg-logout-label{font-size:14px}

.stat-bills{position:relative}
.bill-entry-btn{margin-top:11px;border:0;border-radius:8px;background:#facc15;color:#174b28;font-size:12px;font-weight:750;padding:8px 11px;cursor:pointer}
.bill-entry-btn:hover{background:#f7df65}
.panel-heading-row{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}
.panel-heading-row h3{margin:0}
.text-link{color:#18763a;font-weight:700;text-decoration:none;font-size:12px}
.text-link:hover{text-decoration:underline}
.empty-state,.muted-text{font-size:13px;color:#647267}
.table-scroll{overflow-x:auto}
.records-table{width:100%;border-collapse:collapse;font-size:13px}
.records-table th{text-align:left;color:#526257;background:#f4f8f4;padding:10px;border-bottom:1px solid #e5ece6}
.records-table td{padding:11px 10px;border-bottom:1px solid #edf1ed}
.eg-logout{display:flex!important;align-items:center;gap:10px}
.eg-logout-icon{font-size:20px;line-height:1}
.eg-logout-label{font-size:14px}
@media(max-width:760px){.eg-logout{justify-content:center}.eg-logout-label{display:none}}

</style>
</head>
<body>
<aside class="sidebar eg-sidebar">
  <div class="brand eg-brand"><div class="brand-mark eg-brand-mark">⚡</div><div class="brand-copy eg-brand-copy"><strong>EnerGuide</strong><small>Energy Monitoring &amp; Decision Support<br>for Long-Term Rentals</small></div></div>
  <nav class="nav eg-nav">
    <?php foreach($menu as $url=>$item): $href=explode('#',$url)[0]; $isBill=($url==='settings.php#bills'); $active=($href===$current && !$isBill && ($href!=='settings.php' || $url==='settings.php')); ?>
      <a href="<?=$url?>" id="<?=$isBill?'nav-bills':($url==='settings.php'?'nav-settings':'nav-'.$href)?>" class="<?=($active?'active':'')?>"><span class="nav-ico eg-ico"><?=$item[1]?></span><span class="nav-label eg-label"><?=$item[0]?></span></a>
    <?php endforeach; ?>
  </nav>
  <a class="logout eg-logout" href="logout.php"><span class="eg-logout-icon">↪</span><span class="eg-logout-label">Logout</span></a>
</aside>

<main class="main eg-main">
  <header class="topbar">
    <div class="top-left"><div><h1>Dashboard</h1><p>Overview of electricity consumption and billing for your rental units.</p></div></div>
    <div class="top-right">
      <form class="period-form" method="GET"><label for="period">Billing Period</label><select id="period" name="period" onchange="this.form.submit()">
        <?php if(!$periods): ?><option value="">No periods yet</option><?php endif; ?>
        <?php foreach($periods as $p): ?><option value="<?=htmlspecialchars($p)?>" <?=$period===$p?'selected':''?>><?=htmlspecialchars($p)?></option><?php endforeach; ?>
      </select></form>
      <div class="owner">● &nbsp; Owner</div>
    </div>
  </header>

  <?php if($msg): ?><div class="notice success">✓ <?=htmlspecialchars($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="notice error">! <?=htmlspecialchars($err)?></div><?php endif; ?>

  <section class="stats">
    <div class="stat"><div class="stat-top"><div class="stat-icon">ϟ</div><div><h4>Total Consumption</h4><div class="value"><?=number_format($totalKwh,2)?> kWh</div></div></div><small>Recorded submeter consumption · <?=htmlspecialchars($period ?: 'No period selected')?></small></div>
    <div class="stat"><div class="stat-top"><div class="stat-icon">₱</div><div><h4>Total Estimated Cost</h4><div class="value"><?=$estimatedCost!==null?peso($estimatedCost):'—'?></div></div></div><small><?=$rate!==null?'Configured rate: '.peso($rate).'/kWh':'Configure an electricity rate in Settings'?></small></div>
    <div class="stat stat-bills"><div class="stat-top"><div class="stat-icon">▤</div><div><h4>Total Actual Bills</h4><div class="value"><?=peso($actualBillTotal)?></div></div></div><small><?=count($billByRoom)?> of <?=$totalUnits?> room bills recorded for this period</small><button class="bill-entry-btn" type="button" id="openBillModal">＋ Record Bill</button></div>
    <div class="stat"><div class="stat-top"><div class="stat-icon">▦</div><div><h4>Reading Completeness</h4><div class="value"><?=$completeness?>%</div></div></div><small><?=$unitsWithReading?> of <?=$totalUnits?> units have readings</small></div>
  </section>

  <section class="content-grid">
    <div class="panel"><h3>Unit Consumption (kWh) · <?=htmlspecialchars($period ?: 'Selected Period')?></h3><div class="chart-wrap"><canvas id="chartUnits"></canvas></div></div>
    <div class="panel"><h3>Actual BENECO Bills per Room</h3><div class="chart-wrap"><canvas id="chartBills"></canvas></div>
      <div class="bill-summary"><div><strong>Combined actual bill amount</strong><br><small style="color:#647267">Separate bills recorded for this month</small></div><div class="amount"><?=peso($actualBillTotal)?></div></div>
    </div>
  </section>

  <section class="panel" style="margin-top:16px">
    <div class="panel-heading-row"><h3>Recent BENECO Bill Records</h3><a class="text-link" href="settings.php#bills">View all bill records →</a></div>
    <?php if (!$bills): ?>
      <p class="empty-state">No actual BENECO bills have been recorded for this billing period.</p>
    <?php else: ?>
      <div class="table-scroll"><table class="records-table">
        <thead><tr><th>Rental Unit</th><th>Billing Month</th><th>Actual Amount</th><th>Bill Date</th><th>Attachment</th></tr></thead>
        <tbody><?php foreach(array_slice($bills,0,6) as $bill): ?>
          <tr><td><?=htmlspecialchars($bill['room_name'] ?? 'Unassigned')?></td><td><?=htmlspecialchars($bill['billing_month'])?></td><td><?=peso($bill['total_amount'])?></td><td><?=htmlspecialchars($bill['bill_date'])?></td><td><?php if(!empty($bill['image_path'])): ?><a class="text-link" href="<?=htmlspecialchars($bill['image_path'])?>" target="_blank" rel="noopener">View image</a><?php else: ?><span class="muted-text">No image</span><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </section>

  <section class="panel" style="margin-top:16px">
    <h3>Six Rental Units · Submeter Consumption and Bill Records</h3>
    <div class="room-grid">
      <?php foreach($unitData as $u): $b=$billByRoom[$u['room_id']]??null; ?>
      <div class="room-card">
        <div class="room-head"><h4><?=htmlspecialchars($u['room_name'])?></h4><span class="pill"><?=htmlspecialchars($u['room_type'])?></span></div>
        <div class="kwh"><?=$u['kwh']!==null?number_format((float)$u['kwh'],2).' kWh':'No reading'?></div>
        <small><?=htmlspecialchars($u['status'])?> · <?= $b?'Actual bill recorded':'No bill for this period' ?></small>
        <?php if($b): ?><div style="margin-top:9px;font-size:12px;font-weight:700;color:#205a2d"><?=peso($b['total_amount'])?> actual bill</div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
</main>

<div class="modal-backdrop" id="billModal" aria-hidden="true">
  <section class="modal" role="dialog" aria-modal="true" aria-labelledby="billModalTitle">
    <div class="modal-head"><h2 id="billModalTitle">▤ &nbsp; Record BENECO Bill</h2><button type="button" class="close" id="closeBillModal" aria-label="Close">&times;</button></div>
    <p class="help">Record a separate actual BENECO bill for each room. Select the room printed on the bill; EnerGuide can compare it with that room's submeter consumption for the same billing month.</p>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add_bill">
      <div class="form-grid">
        <div class="field full"><label for="room_id">Select Room *</label><select name="room_id" id="room_id" required><option value="">-- Select room --</option><?php foreach($rooms as $r): ?><option value="<?=$r['room_id']?>"><?=htmlspecialchars($r['room_name'])?><?=!empty($r['meter_id'])?' — '.htmlspecialchars($r['meter_id']):''?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="billing_month">Billing Month *</label><input type="text" name="billing_month" id="billing_month" placeholder="e.g., November 2026" required></div>
        <div class="field"><label for="bill_date">Bill Date *</label><input type="date" name="bill_date" id="bill_date" value="<?=date('Y-m-d')?>" required></div>
        <div class="field"><label for="total_kwh">Total kWh (optional)</label><input type="number" name="total_kwh" id="total_kwh" step="0.01" min="0" placeholder="e.g., 980.00"></div>
        <div class="field"><label for="total_amount">Total Amount (₱) *</label><input type="number" name="total_amount" id="total_amount" step="0.01" min="0.01" placeholder="e.g., 10780.00" required></div>
        <div class="field"><label for="rate_category">BENECO Customer Category *</label><select name="rate_category" id="rate_category" required><option value="Commercial High Voltage" selected>Commercial High Voltage</option><option value="Commercial Low Voltage">Commercial Low Voltage</option><option value="Residential">Residential</option><option value="Other">Other</option></select></div>
        <div class="field"><label for="account_number">Account Number</label><input type="text" name="account_number" id="account_number" placeholder="BENECO account no."></div>
        <div class="field full"><label for="bill_image">Upload BENECO Bill Image (JPG, PNG, WEBP; max 5 MB)</label><input type="file" name="bill_image" id="bill_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"></div>
        <div class="field full"><label for="notes">Notes</label><textarea name="notes" id="notes" placeholder="Add remarks (optional)"></textarea></div>
      </div>
      <div class="modal-actions"><button class="btn btn-cancel" type="button" id="cancelBillModal">Cancel</button><button class="btn btn-save" type="submit">▣ &nbsp; Save Bill</button></div>
    </form>
  </section>
</div>

<script>
const roomLabels = <?=json_encode(array_column($unitData,'room_name'),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>;
const roomKwh = <?=json_encode(array_map(function($u){return $u['kwh']!==null?(float)$u['kwh']:0;},$unitData))?>;
const billAmounts = <?=json_encode(array_map(function($u)use($billByRoom){$b=$billByRoom[$u['room_id']]??null;return $b?(float)$b['total_amount']:0;},$unitData))?>;
const chartOptions = {responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'#edf2ed'}},x:{grid:{display:false}}}};
new Chart(document.getElementById('chartUnits'),{type:'bar',data:{labels:roomLabels,datasets:[{data:roomKwh,backgroundColor:'#48a94d',borderRadius:7,maxBarThickness:48}]},options:{...chartOptions,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>c.parsed.y.toFixed(2)+' kWh'}}}}});
new Chart(document.getElementById('chartBills'),{type:'doughnut',data:{labels:roomLabels,datasets:[{data:billAmounts,backgroundColor:['#176b35','#36a852','#a8d96d','#f4d23d','#ffb91f','#f28b32'],borderWidth:2,borderColor:'#fff',hoverOffset:5}]},options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{display:true,position:'right',labels:{boxWidth:10,padding:12,font:{size:11}}},tooltip:{callbacks:{label:c=>c.label+': ₱ '+c.parsed.toLocaleString('en-PH',{minimumFractionDigits:2})}}}}});
const modal=document.getElementById('billModal');
function openModal(){modal.classList.add('open');modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';document.getElementById('room_id').focus();}
function closeModal(){modal.classList.remove('open');modal.setAttribute('aria-hidden','true');document.body.style.overflow='';}
document.getElementById('openBillModal').addEventListener('click',openModal);
document.getElementById('closeBillModal').addEventListener('click',closeModal);
document.getElementById('cancelBillModal').addEventListener('click',closeModal);
modal.addEventListener('click',e=>{if(e.target===modal)closeModal();});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('open'))closeModal();});
<?php if($err): ?>openModal();<?php endif; ?>
</script>
</body>
</html>
