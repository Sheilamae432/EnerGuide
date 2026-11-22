<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
require 'config/database.php';


$msg=''; $err='';

/* STEP 1: RECORD METER READING */
if (($_POST['action'] ?? '') === 'add_reading') {
    $room = (int)($_POST['room_id'] ?? 0);
    $date = $_POST['reading_date'] ?? '';
    $period = trim($_POST['billing_period'] ?? '');
    $rawCurrent = $_POST['current_reading'] ?? '';
    $curr = is_numeric($rawCurrent) ? (float)$rawCurrent : null;
    // Normal cumulative meter reading only.
    $prevStmt = $pdo->prepare("SELECT current_reading FROM meter_readings WHERE room_id=? AND reading_date < ? ORDER BY reading_date DESC, reading_id DESC LIMIT 1");
    $prevStmt->execute([$room, $date]);
    $previousValue = $prevStmt->fetchColumn();
    $savedPrevious = $previousValue !== false ? (float)$previousValue : null;
    $rawPrevious = $_POST['previous_reading'] ?? '';
    $enteredPrevious = is_numeric($rawPrevious) ? (float)$rawPrevious : null;

    if ($room <= 0 || $date === '' || $period === '') {
        $err = "Please complete all fields (room, date, billing period).";
    } elseif (!preg_match('/^(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}$/i', $period)) {
        $err = "Enter the billing period in Month YYYY format, for example November 2026.";
    } elseif (date('F Y', strtotime($date)) !== date('F Y', strtotime('1 '.$period))) {
        $err = "The billing period must match the month and year of the reading date.";
    } elseif ($curr === null || $curr < 0) {
        $err = "Enter a valid non-negative current meter reading.";
    } elseif ($savedPrevious === null && $enteredPrevious === null) {
        $err = "For the first record, enter the actual starting meter reading.";
    } elseif ($savedPrevious !== null && $curr < $savedPrevious) {
        $err = "Current cumulative reading is lower than the previous saved reading. Please verify the meter reading. Normal monthly readings cannot decrease; do not save an incorrect value.";
    } elseif ($savedPrevious === null && ($enteredPrevious < 0 || $curr < $enteredPrevious)) {
        $err = "The current reading must be equal to or greater than the actual starting reading.";
    } else {
        $prev = ($savedPrevious !== null ? $savedPrevious : $enteredPrevious);
        // Do not allow backdated entries to silently invalidate later records.
        $later = $pdo->prepare("SELECT COUNT(*) FROM meter_readings WHERE room_id=? AND reading_date >= ?");
        $later->execute([$room, $date]);
        if ((int)$later->fetchColumn() > 0) {
            $err = "A reading already exists on or after this date for the room. Enter readings chronologically.";
        }
        $chk = $pdo->prepare("SELECT COUNT(*) FROM meter_readings WHERE room_id=? AND billing_period=?");
        $chk->execute([$room,$period]);
        if ($err === '' && (int)$chk->fetchColumn() > 0) {
            $err = "A reading for this room and billing period already exists. Choose the correct month or review the saved record.";
        } elseif ($err === '') {
            $cons = round($curr - $prev, 2);
            $stmt = $pdo->prepare("INSERT INTO meter_readings (room_id,reading_date,billing_period,previous_reading,current_reading,consumption_kwh,created_by) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$room,$date,$period,$prev,$curr,$cons,$_SESSION['user_id']]);
            $msg = "Reading saved. Consumption: ".number_format($cons,2)." kWh.";
        }
    }
}

/* Filter */
$periodRows = $pdo->query("SELECT billing_period AS period_name, MAX(reading_date) AS sort_date FROM meter_readings WHERE billing_period IS NOT NULL AND billing_period <> '' GROUP BY billing_period UNION SELECT billing_month AS period_name, MAX(bill_date) AS sort_date FROM beneco_bills WHERE billing_month IS NOT NULL AND billing_month <> '' GROUP BY billing_month ORDER BY sort_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$periods = array_column($periodRows, 'period_name');
$period = $_GET['period'] ?? '';
if ($period === '' && !empty($periods)) $period = $periods[0];
$formBillingPeriod = trim($_POST['billing_period'] ?? date('F Y'));
if ($formBillingPeriod === '') { $formBillingPeriod = date('F Y'); }
$rate = getRateForPeriod($pdo, $period);
$billStmt = $pdo->prepare("SELECT b.*, r.room_name FROM beneco_bills b LEFT JOIN rooms r ON r.room_id=b.room_id WHERE b.billing_month=? AND b.room_id IS NOT NULL ORDER BY r.room_id, b.bill_date DESC, b.bill_id DESC");
$billStmt->execute([$period]);
$roomBills = $billStmt->fetchAll(PDO::FETCH_ASSOC);
$roomBillById = [];
foreach ($roomBills as $billRow) {
    if (!isset($roomBillById[$billRow['room_id']])) $roomBillById[$billRow['room_id']] = $billRow;
}
$actualBillTotal = array_sum(array_map(function($b){ return (float)$b['total_amount']; }, $roomBillById));
$actualBillKwhTotal = 0;
$actualBillKwhCount = 0;
foreach ($roomBillById as $b) {
    if ($b['total_kwh'] !== null) { $actualBillKwhTotal += (float)$b['total_kwh']; $actualBillKwhCount++; }
}


$prevPeriod = null;
$idx = array_search($period, $periods);
if ($idx !== false && isset($periods[$idx+1])) $prevPeriod = $periods[$idx+1];

/* STEP 3: COMPARE ALL CONFIGURED UNITS (SIX INITIAL UNITS) */
$stmt = $pdo->prepare("
    SELECT r.room_id, r.room_name, r.room_type,
           (SELECT consumption_kwh FROM meter_readings m
            WHERE m.room_id=r.room_id AND m.billing_period=?
            ORDER BY reading_date DESC LIMIT 1) AS current_kwh
    FROM rooms r ORDER BY r.room_id
");
$stmt->execute([$period]);
$compareData = $stmt->fetchAll();

foreach ($compareData as &$row) {
    $row['previous_kwh'] = null;
    if ($prevPeriod !== null) {
        $s = $pdo->prepare("SELECT consumption_kwh FROM meter_readings WHERE room_id=? AND billing_period=? ORDER BY reading_date DESC LIMIT 1");
        $s->execute([$row['room_id'], $prevPeriod]);
        $v = $s->fetchColumn();
        if ($v !== false) $row['previous_kwh'] = (float)$v;
    }
    $row['difference'] = ($row['current_kwh'] !== null && $row['previous_kwh'] !== null)
        ? round($row['current_kwh'] - $row['previous_kwh'], 2) : null;
    $row['pct_change'] = ($row['difference'] !== null && $row['previous_kwh'] > 0)
        ? round(($row['difference'] / $row['previous_kwh']) * 100, 1) : null;
}
unset($row);

/* STEP 4: HISTORY */
$historyRoom = (int)($_GET['history_room'] ?? 0);
$historyData = [];
if ($historyRoom > 0) {
    $s = $pdo->prepare("SELECT billing_period, reading_date, consumption_kwh FROM meter_readings WHERE room_id=? AND billing_period IS NOT NULL ORDER BY reading_date");
    $s->execute([$historyRoom]);
    $historyData = $s->fetchAll();
    foreach ($historyData as &$historyRow) {
        $historyRow['period_rate'] = getRateForPeriod($pdo, $historyRow['billing_period']);
    }
    unset($historyRow);
}

/* STEP 5: MONTH-TO-MONTH OBSERVATIONS (descriptive analytics) */
$observationList = [];
foreach ($compareData as $row) {
    if ($row['current_kwh'] === null) {
        $observationList[] = ['room'=>$row['room_name'], 'type'=>$row['room_type'], 'kwh'=>null,
            'observation'=>'No meter reading has been recorded for this billing period.',
            'recommendation'=>'Record and verify the meter reading for this unit.'];
        continue;
    }
    if ($row['difference'] === null) {
        $observation = 'Current consumption is '.number_format((float)$row['current_kwh'],2).' kWh. No previous-period reading is available for comparison.';
        $recommendation = 'Continue recording monthly readings to build a historical consumption record.';
    } elseif ($row['difference'] > 0) {
        $observation = 'Consumption increased by '.number_format($row['difference'],2).' kWh'.($row['pct_change'] !== null ? ' (+'.number_format($row['pct_change'],1).'%)' : '').' compared with '.$prevPeriod.'.';
        $recommendation = 'Verify the meter reading and review possible reasons for the increase with the property records.';
    } elseif ($row['difference'] < 0) {
        $observation = 'Consumption decreased by '.number_format(abs($row['difference']),2).' kWh'.($row['pct_change'] !== null ? ' ('.number_format($row['pct_change'],1).'%)' : '').' compared with '.$prevPeriod.'.';
        $recommendation = 'Continue regular monthly monitoring and verify that the reading is accurate.';
    } else {
        $observation = 'Consumption remained unchanged compared with '.$prevPeriod.'.';
        $recommendation = 'Continue regular monthly monitoring.';
    }
    $observationList[] = ['room'=>$row['room_name'], 'type'=>$row['room_type'], 'kwh'=>(float)$row['current_kwh'],
        'observation'=>$observation, 'recommendation'=>$recommendation];
}

$menu = [
 'dashboard.php'=>['Dashboard','⌂'],
 'consumption_analysis.php'=>['Consumption Analysis','▥'],
 'predictive_analytics.php'=>['Predictive Analytics','⌁'],
 'reports.php'=>['Reports','▤'],
 'settings.php'=>['Settings','⚙']
];
$current = basename($_SERVER['PHP_SELF']);
$activeMenu = (($_GET['view'] ?? '') === 'readings') ? 'readings.php' : $current;
$rooms = $pdo->query("SELECT room_id, room_name, room_type FROM rooms ORDER BY room_id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Consumption Analysis | EnerGuide</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--green-dark:#1B5E20;--green:#2E7D32;--green-light:#4CAF50;--yellow:#F9A825;--yellow-light:#FDD835;--cream:#FFF9C4;--mint:#E8F5E9;--text:#212121;--muted:#616161;}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',Tahoma,sans-serif;}
body{background:var(--mint);color:var(--text);display:flex;min-height:100vh;}
.sidebar{width:240px;background:linear-gradient(180deg,var(--green-dark),var(--green));color:#fff;padding:20px 0;position:fixed;height:100vh;overflow-y:auto;}
.sidebar h2{padding:0 20px 18px;color:var(--yellow-light);border-bottom:1px solid rgba(255,255,255,.2);font-size:20px;letter-spacing:2px;}
.sidebar a{display:block;padding:14px 20px;color:#fff;text-decoration:none;font-size:14px;border-left:4px solid transparent;transition:.2s;}
.sidebar a:hover{background:rgba(255,255,255,.1);border-left-color:var(--yellow);}
.sidebar a.active{background:var(--yellow);color:var(--green-dark);font-weight:700;border-left-color:var(--yellow-light);}
.main{margin-left:240px;flex:1;padding:24px;}
.topbar{background:#fff;padding:16px 24px;border-radius:10px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:22px;border-left:5px solid var(--yellow);flex-wrap:wrap;gap:10px;}
.topbar h1{color:var(--green-dark);font-size:22px;}
.card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.05);margin-bottom:20px;}
.card h3{color:var(--green-dark);margin-bottom:14px;border-bottom:2px solid var(--yellow-light);padding-bottom:8px;font-size:16px;}
.card h3 small{color:var(--muted);font-weight:400;font-size:12px;margin-left:6px;}
label{display:block;font-weight:600;color:var(--green-dark);font-size:13px;margin-bottom:5px;}
input,select{width:100%;padding:10px;border:2px solid #ddd;border-radius:8px;font-size:14px;margin-bottom:12px;}
input:focus,select:focus{outline:none;border-color:var(--green);}
input[readonly]{background:var(--cream);font-weight:600;}
.btn{background:var(--green);color:#fff;padding:10px 18px;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:14px;transition:.2s;text-decoration:none;display:inline-block;}
.btn:hover{background:var(--green-dark);}
.btn-yellow{background:var(--yellow);color:var(--green-dark);}
.btn-yellow:hover{background:var(--yellow-light);}
.btn-sm{padding:6px 12px;font-size:12px;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th{background:var(--green);color:#fff;padding:10px;text-align:left;font-size:13px;}
td{padding:10px;border-bottom:1px solid #eee;}
tr:hover{background:var(--cream);}
.badge{padding:3px 8px;border-radius:10px;font-size:11px;font-weight:600;display:inline-block;}
.badge-red{background:#C62828;color:#fff;}
.badge-yellow{background:var(--yellow);color:var(--green-dark);}
.badge-green{background:var(--green);color:#fff;}
.badge-gray{background:#9E9E9E;color:#fff;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;}
.alert-success{background:var(--mint);color:var(--green-dark);border-left:4px solid var(--green);}
.alert-error{background:#FFEBEE;color:#C62828;border-left:4px solid #C62828;}
.section-nav{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;}
.section-nav a{background:#fff;color:var(--green-dark);padding:8px 14px;border-radius:20px;text-decoration:none;font-size:13px;font-weight:600;border:2px solid var(--yellow-light);}
.section-nav a:hover{background:var(--yellow-light);}
.filter-bar{display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-bottom:14px;}
.filter-bar > div{flex:1;min-width:180px;}
.grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px;}
.attention-item{background:var(--cream);padding:14px;border-radius:8px;border-left:4px solid var(--yellow);margin-bottom:10px;}
.attention-item.high{border-left-color:#C62828;background:#FFEBEE;}
.attention-item h5{color:var(--green-dark);font-size:14px;margin-bottom:4px;}
.attention-item p{font-size:13px;color:var(--muted);margin:4px 0;}
.attention-item .rec{background:#fff;padding:8px 12px;border-radius:6px;margin-top:6px;font-size:13px;color:var(--green-dark);}
canvas{max-height:320px;}

/* EnerGuide unified visual system - shared across all authenticated pages */
:root{--eg-green-dark:#14532d;--eg-green:#21833b;--eg-yellow:#facc15;--eg-bg:#f3f7f3;--eg-line:#e5ece6;--eg-ink:#17231b;--eg-muted:#647267}
*{box-sizing:border-box;font-family:Inter,"Segoe UI",Arial,sans-serif}
body{margin:0!important;background:var(--eg-bg)!important;color:var(--eg-ink)!important;min-height:100vh;display:block!important}
.eg-sidebar{position:fixed!important;inset:0 auto 0 0!important;width:248px!important;height:100vh!important;background:linear-gradient(165deg,#14532d,#0d4025)!important;color:#fff!important;padding:24px 14px!important;display:flex!important;flex-direction:column!important;z-index:50!important;overflow-y:auto!important}
.eg-brand{display:flex;gap:11px;align-items:center;padding:3px 10px 25px;border-bottom:1px solid #ffffff30;margin-bottom:22px}
.eg-brand-mark{width:43px;height:43px;flex:0 0 43px;border-radius:13px;background:var(--eg-yellow);color:var(--eg-green-dark);display:grid;place-items:center;font-size:25px;font-weight:900}
.eg-brand strong{display:block;font-size:21px;letter-spacing:.3px;color:#fff}.eg-brand small{display:block;font-size:10px;color:#d9eddd;margin-top:3px;line-height:1.4}
.eg-nav{display:grid;gap:7px}.eg-nav a{color:#eaf5ec!important;text-decoration:none!important;padding:13px 14px!important;border-radius:10px!important;font-size:14px!important;display:flex!important;align-items:center!important;gap:13px!important;border:0!important;transition:.18s;background:transparent}
.eg-nav a:hover{background:#ffffff18!important}.eg-nav a.active{background:var(--eg-yellow)!important;color:#174b28!important;font-weight:750!important;border:0!important}
.eg-nav .eg-ico{width:23px;text-align:center;font-size:18px;flex:0 0 23px}
.eg-logout{margin-top:auto;color:#fff!important;text-decoration:none!important;padding:14px!important;border-top:1px solid #ffffff30;display:block;font-size:14px}
.eg-main{margin-left:248px!important;padding:24px 28px 35px!important;max-width:1600px;min-height:100vh}
.eg-main .topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;background:#fff!important;border:1px solid var(--eg-line)!important;border-left:1px solid var(--eg-line)!important;border-radius:15px!important;padding:14px 20px!important;margin-bottom:22px!important;box-shadow:0 3px 12px #153c1e08!important}
.eg-main .topbar h1{font-size:23px;margin:0;color:#153e24}.eg-main .topbar p{font-size:12px;color:var(--eg-muted);margin:4px 0 0}
.eg-main .card,.eg-main .panel{background:#fff;border:1px solid var(--eg-line);border-radius:15px;box-shadow:0 3px 12px #153c1e07}
.eg-main h1,.eg-main h2,.eg-main h3,.eg-main h4{color:#153e24}
.eg-main th{background:#21833b!important;color:#fff!important}
.eg-main .btn,.eg-main button.btn{background:#178139;color:#fff;border:0;border-radius:9px}
.eg-main .btn-yellow{background:var(--eg-yellow)!important;color:#174b28!important}
.eg-main input:focus,.eg-main select:focus,.eg-main textarea:focus{outline:2px solid #b9dfbf;border-color:#2e7d32}
@media(max-width:760px){.eg-sidebar{width:70px!important;padding:18px 8px!important}.eg-brand{justify-content:center;padding:0 0 18px}.eg-brand-copy,.eg-nav .eg-label,.eg-logout-label{display:none}.eg-brand-mark{width:42px;height:42px;flex-basis:42px}.eg-nav a{justify-content:center;padding:13px 6px!important}.eg-nav .eg-ico{font-size:20px}.eg-main{margin-left:70px!important;padding:14px!important}}
@media(max-width:480px){.eg-main .topbar{align-items:flex-start;flex-direction:column}}

.eg-logout{display:flex!important;align-items:center;gap:10px}.eg-logout-icon{font-size:20px;line-height:1}.eg-logout-label{font-size:14px}
</style>
</head>
<body>
<aside class="eg-sidebar">
  <div class="eg-brand"><div class="eg-brand-mark">⚡</div><div class="eg-brand-copy"><strong>EnerGuide</strong><small>Energy Monitoring &amp; Decision Support<br>for Long-Term Rentals</small></div></div>
  <nav class="eg-nav">
    <?php foreach($menu as $url=>$item): $href=explode('#',$url)[0]; $isBill=($url==='settings.php#bills'); $active=($href===$current && !$isBill && ($href!=='settings.php' || $url==='settings.php')); ?>
      <a href="<?=$url?>" id="<?=$isBill?'nav-bills':($url==='settings.php'?'nav-settings':'nav-'.$href)?>" class="<?=$active?'active':''?>"><span class="eg-ico"><?=$item[1]?></span><span class="eg-label"><?=$item[0]?></span></a>
    <?php endforeach; ?>
  </nav>
  <a class="eg-logout" href="logout.php"><span class="eg-logout-icon">↪</span><span class="eg-logout-label">Logout</span></a>
</aside>

<div class="eg-main">
  <div class="topbar">
    <h1>Consumption Analysis</h1>
    <form method="GET" style="display:flex;gap:10px;align-items:center;">
      <label style="font-weight:600;color:var(--green-dark);font-size:13px;">Billing Period:</label>
      <select name="period" onchange="this.form.submit()" style="padding:8px 12px;border:2px solid #ddd;border-radius:8px;font-size:14px;">
        <?php foreach($periods as $p): ?>
          <option value="<?=htmlspecialchars($p)?>" <?= $period===$p?'selected':'' ?>><?=htmlspecialchars($p)?></option>
        <?php endforeach; ?>
      </select>
      <?php if($historyRoom): ?><input type="hidden" name="history_room" value="<?=$historyRoom?>"><?php endif; ?>
    </form>
  </div>

  <?php if($msg): ?><div class="alert alert-success">✅ <?=htmlspecialchars($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-error">❌ <?=htmlspecialchars($err)?></div><?php endif; ?>

  <div class="section-nav">
    <a href="#secA">A. Enter Meter Readings</a>
    <a href="#secB">B. Compare 6 Units</a>
    <a href="#secC">C. Historical Consumption</a>
    <a href="#secD">D. Month-to-Month Observations</a>
    <a href="#secE">E. Actual BENECO Bills by Room</a>
  </div>

  <!-- SECTION A -->
  <div class="card" id="secA">
    <h3>A. 📝 Enter Meter Readings <small>Formula: Consumption = Current − Previous</small></h3>
    <form method="POST" autocomplete="off">
      <input type="hidden" name="action" value="add_reading">
      <div class="grid-2">
        <div>
          <label>Accommodation Unit</label>
          <select name="room_id" id="room_id" required onchange="loadPrev()">
            <option value="">-- Select Unit --</option>
            <?php foreach($rooms as $r): ?>
              <option value="<?=$r['room_id']?>"><?=htmlspecialchars($r['room_name'])?> — <?=htmlspecialchars($r['room_type'])?></option>
            <?php endforeach; ?>
          </select>
          <label>Reading Date</label>
          <input type="date" name="reading_date" id="reading_date" onchange="loadPrev()" value="<?=date('Y-m-d')?>" required>
          <label>Billing Period</label>
          <input type="text" name="billing_period" value="<?=htmlspecialchars($formBillingPeriod)?>" placeholder="e.g., November 2026" required>
        </div>
        <div>
          <label>Previous Cumulative Reading</label>
          <input type="number" step="0.01" min="0" name="previous_reading" id="prev" value="" readonly>
          <small id="prevHelp" style="display:block;color:var(--muted);margin:-8px 0 12px;">Automatically loaded from the latest saved reading. For the first record, enter the actual starting reading.</small>
          <label>Current Meter Reading</label>
          <input type="number" step="0.01" min="0" name="current_reading" id="curr" required oninput="calc()">
          <label>Computed Consumption (kWh)</label>
          <input type="text" id="cons" readonly>
        </div>
      </div>
      <button class="btn" type="submit">💾 Save Reading</button>
    </form>
  </div>

  <!-- SECTION B -->
  <div class="card" id="secB">
    <h3>B. 📊 Compare the Six Units <small>Period: <?=htmlspecialchars($period ?: '—')?><?= $prevPeriod ? ' vs '.htmlspecialchars($prevPeriod) : '' ?></small></h3>
    <table>
      <tr><th>Unit</th><th>Type</th><th>Consumption (kWh)</th><th>Estimated Cost</th><th>Change vs Previous</th><th>Reading Status</th></tr>
      <?php foreach($compareData as $row): ?>
        <tr>
          <td><strong><?=htmlspecialchars($row['room_name'])?></strong></td>
          <td><?=htmlspecialchars($row['room_type'])?></td>
          <td><?= $row['current_kwh']!==null ? number_format($row['current_kwh'],2) : 'No reading' ?></td>
          <td><?= $row['current_kwh']!==null ? ($rate !== null ? peso($row['current_kwh']*$rate) : 'Rate not configured') : '—' ?></td>
          <td>
            <?php if($row['difference']===null): ?>—
            <?php elseif($row['difference']>0): ?>
              <span style="color:#C62828;">▲ +<?=number_format($row['difference'],2)?><?= $row['pct_change']!==null?' ('.$row['pct_change'].'%)':'' ?></span>
            <?php elseif($row['difference']<0): ?>
              <span style="color:var(--green);">▼ <?=number_format($row['difference'],2)?><?= $row['pct_change']!==null?' ('.$row['pct_change'].'%)':'' ?></span>
            <?php else: ?><span style="color:var(--muted);">—</span><?php endif; ?>
          </td>
          <td><?= $row['current_kwh'] !== null ? 'Recorded' : 'Missing' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <div style="margin-top:20px;"><canvas id="chartCompare"></canvas></div>
  </div>

  <!-- SECTION C -->
  <div class="card" id="secC">
    <h3>C. 📈 Historical Consumption</h3>
    <form method="GET" class="filter-bar">
      <input type="hidden" name="period" value="<?=htmlspecialchars($period)?>">
      <div>
        <label>Select Unit</label>
        <select name="history_room" onchange="this.form.submit()">
          <option value="0">-- Select Unit --</option>
          <?php foreach($rooms as $r): ?>
            <option value="<?=$r['room_id']?>" <?= $historyRoom==$r['room_id']?'selected':'' ?>><?=htmlspecialchars($r['room_name'])?> — <?=htmlspecialchars($r['room_type'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
    <?php if($historyRoom && $historyData): ?>
      <table>
        <tr><th>Billing Period</th><th>Reading Date</th><th>Consumption (kWh)</th><th>Estimated Cost</th></tr>
        <?php foreach($historyData as $h): ?>
          <tr>
            <td><?=htmlspecialchars($h['billing_period'])?></td>
            <td><?=htmlspecialchars($h['reading_date'])?></td>
            <td><?=number_format($h['consumption_kwh'],2)?></td>
            <td><?=($h['period_rate'] !== null ? peso($h['consumption_kwh']*$h['period_rate']) : 'Rate not configured')?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <div style="margin-top:20px;"><canvas id="chartHistory"></canvas></div>
    <?php elseif($historyRoom): ?>
      <p style="color:var(--muted);">No historical data available for this unit.</p>
    <?php else: ?>
      <p style="color:var(--muted);">Select a unit to view its historical consumption.</p>
    <?php endif; ?>
  </div>

  <!-- SECTION D -->
  <div class="card" id="secD">
    <h3>D. 📋 Month-to-Month Consumption Observations</h3>
    <p style="font-size:13px;color:var(--muted);margin-bottom:14px;">
      These observations summarize recorded readings and changes from the previous billing period. They are descriptive results, not BENECO consumption classifications or proof of electricity waste.
    </p>
    <?php foreach($observationList as $o): ?>
      <div class="attention-item">
        <h5><?=htmlspecialchars($o['room'])?> — <?=htmlspecialchars($o['type'])?></h5>
        <p><?=htmlspecialchars($o['observation'])?></p>
        <div class="rec">💡 <?=htmlspecialchars($o['recommendation'])?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <!-- SECTION E: ROOM-SPECIFIC ACTUAL BENECO BILLS -->
  <div class="card" id="secE">
    <h3>E. 📄 Actual BENECO Bills by Room <small>Billing period: <?=htmlspecialchars($period ?: '—')?></small></h3>
    <p style="font-size:13px;color:var(--muted);margin-bottom:14px;">
      Each room has its own BENECO account and submeter. Actual bill values below are matched by room and billing month. Estimated cost and actual bill amount are shown separately because the bill may include additional charges and different reading dates.
    </p>
    <div class="summary-grid">
      <div class="summary-box"><h4>Rooms with Bills</h4><p><?=count($roomBillById)?> / <?=count($rooms)?> </p></div>
      <div class="summary-box yellow"><h4>Combined Actual Bill Amount</h4><p><?=peso($actualBillTotal)?></p></div>
      <div class="summary-box"><h4>Combined Bill kWh</h4><p><?=$actualBillKwhCount ? number_format($actualBillKwhTotal,2).' kWh' : 'Not recorded'?></p></div>
    </div>
    <div style="overflow-x:auto;">
      <table>
        <tr><th>Room</th><th>Submeter kWh</th><th>Estimated Cost</th><th>Actual Bill kWh</th><th>Actual Bill Amount</th><th>Bill Image</th><th>Comparison</th></tr>
        <?php foreach($compareData as $roomRow): $billRow=$roomBillById[$roomRow['room_id']] ?? null; $estRoomCost=($roomRow['current_kwh']!==null && $rate!==null)?(float)$roomRow['current_kwh']*$rate:null; ?>
          <tr>
            <td><strong><?=htmlspecialchars($roomRow['room_name'])?></strong></td>
            <td><?=$roomRow['current_kwh']!==null?number_format((float)$roomRow['current_kwh'],2).' kWh':'No reading'?></td>
            <td><?=$estRoomCost!==null?peso($estRoomCost):'Not available'?></td>
            <td><?=$billRow && $billRow['total_kwh']!==null?number_format((float)$billRow['total_kwh'],2).' kWh':'—'?></td>
            <td><?=$billRow?peso($billRow['total_amount']):'No bill recorded'?></td>
            <td><?php if($billRow && !empty($billRow['image_path'])): ?><a href="<?=htmlspecialchars($billRow['image_path'])?>" target="_blank" rel="noopener">View bill</a><?php else: ?>—<?php endif; ?></td>
            <td><?php if($billRow && $estRoomCost!==null): ?><?=peso((float)$billRow['total_amount']-$estRoomCost)?> difference<?php elseif(!$billRow): ?>Missing bill<?php else: ?>Rate / reading unavailable<?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php if(count($roomBillById)<count($rooms)): ?>
      <p style="font-size:12px;color:#8A5A00;margin-top:12px;">Some rooms do not yet have an actual bill recorded for this period. Add each room's bill in Settings.</p>
    <?php endif; ?>
  </div>
</div>

<script>
const compareLabels = <?=json_encode(array_column($compareData,'room_name'))?>;
const compareCurrent = <?=json_encode(array_map(function($r){ return $r['current_kwh']!==null ? (float)$r['current_kwh'] : 0; }, $compareData))?>;
const comparePrev = <?=json_encode(array_map(function($r){ return $r['previous_kwh']!==null ? (float)$r['previous_kwh'] : 0; }, $compareData))?>;

if (compareLabels.length > 0) {
  new Chart(document.getElementById('chartCompare'),{
    type:'bar',
    data:{labels: compareLabels,
      datasets:[
        {label:'Current kWh',data: compareCurrent,backgroundColor:'#4CAF50',borderColor:'#1B5E20',borderWidth:2,borderRadius:6},
        {label:'Previous kWh',data: comparePrev,backgroundColor:'#F9A825',borderColor:'#1B5E20',borderWidth:2,borderRadius:6}
      ]},
    options:{plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true}}}
  });
}

<?php if($historyRoom && $historyData): ?>
const historyLabels = <?=json_encode(array_column($historyData,'billing_period'))?>;
const historyValues = <?=json_encode(array_map(function($h){ return (float)$h['consumption_kwh']; }, $historyData))?>;
if (historyLabels.length > 0) {
  new Chart(document.getElementById('chartHistory'),{
    type:'line',
    data:{labels: historyLabels,
      datasets:[{label:'kWh',data: historyValues,borderColor:'#2E7D32',backgroundColor:'rgba(76,175,80,.2)',fill:true,tension:.3,borderWidth:3,pointBackgroundColor:'#F9A825',pointRadius:6}]},
    options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}
  });
}
<?php endif; ?>

function loadPrev(){
  const id = document.getElementById('room_id').value;
  if(!id){ document.getElementById('prev').value='0'; document.getElementById('prev').readOnly=true; return; }
  fetch('api/get_previous_reading.php?room_id='+encodeURIComponent(id)+'&reading_date='+encodeURIComponent(document.getElementById('reading_date').value))
    .then(r=>{if(!r.ok) throw new Error('Request failed'); return r.json();}).then(d=>{
      document.getElementById('prev').value = d.has_previous ? d.previous_reading : ''; 
      document.getElementById('prev').readOnly = !!d.has_previous;
      document.getElementById('prevHelp').textContent = d.has_previous ? 'Previous cumulative reading loaded from the latest saved record.' : 'No earlier reading exists. Enter the actual previous/start reading shown on the meter record; use zero only if the meter starts at zero.';
      calc();
    }).catch(()=>{ document.getElementById('prev').value=''; document.getElementById('prev').readOnly=false; document.getElementById('prevHelp').textContent='Could not load a previous reading. Verify the meter history and enter the correct starting value.'; });
}
function calc(){
  const p = parseFloat(document.getElementById('prev').value);
  const c = parseFloat(document.getElementById('curr').value);
  const out = document.getElementById('cons');
  if (!Number.isFinite(p) || !Number.isFinite(c)) {out.value='Enter both readings'; out.style.color='#616161';}
  else if(c < p){ out.value = 'Invalid: current is below the selected meter baseline'; out.style.color='#C62828'; }
  else {
    out.value = (c-p).toFixed(2)+' kWh';
    out.style.color='#1B5E20';
  }
}
</script>
</body>
</html>