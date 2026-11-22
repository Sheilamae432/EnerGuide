<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
require 'config/database.php';


$period = $_GET['period'] ?? '';
$periodRows = $pdo->query("SELECT billing_period AS period_name, MAX(reading_date) AS sort_date FROM meter_readings WHERE billing_period IS NOT NULL AND billing_period <> '' GROUP BY billing_period UNION SELECT billing_month AS period_name, MAX(bill_date) AS sort_date FROM beneco_bills WHERE billing_month IS NOT NULL AND billing_month <> '' GROUP BY billing_month ORDER BY sort_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$periods = array_column($periodRows, 'period_name');
if ($period === '' && !empty($periods)) $period = $periods[0];

$rate = getRateForPeriod($pdo, $period);

// Per-unit
$stmt = $pdo->prepare("
    SELECT r.room_id, r.room_name, r.room_type,
           (SELECT consumption_kwh FROM meter_readings m
            WHERE m.room_id=r.room_id AND m.billing_period=?
            ORDER BY reading_date DESC LIMIT 1) AS kwh
    FROM rooms r ORDER BY r.room_id
");
$stmt->execute([$period]);
$unitData = $stmt->fetchAll();

$totalKwh = 0;
foreach ($unitData as $u) { if ($u['kwh']!==null) $totalKwh += (float)$u['kwh']; }
$estimatedCost = $rate !== null ? $totalKwh * $rate : null;

// Previous period
$prevPeriod = null;
$idx = array_search($period, $periods);
if ($idx !== false && isset($periods[$idx+1])) $prevPeriod = $periods[$idx+1];

$prevTotal = 0;
if ($prevPeriod) {
    $s = $pdo->prepare("SELECT COALESCE(SUM(consumption_kwh),0) FROM meter_readings WHERE billing_period=?");
    $s->execute([$prevPeriod]);
    $prevTotal = (float)$s->fetchColumn();
}

$increaseKwh = $totalKwh - $prevTotal;
$increasePct = $prevTotal > 0 ? round(($increaseKwh / $prevTotal) * 100, 1) : null;

// Actual bills: one separate BENECO account/bill per room
$stmt = $pdo->prepare("SELECT b.*, r.room_name FROM beneco_bills b LEFT JOIN rooms r ON r.room_id=b.room_id WHERE b.billing_month=? AND b.room_id IS NOT NULL ORDER BY r.room_id, b.bill_date DESC, b.bill_id DESC");
$stmt->execute([$period]);
$actualBills = $stmt->fetchAll(PDO::FETCH_ASSOC);
$actualBillByRoom = [];
foreach ($actualBills as $billRow) {
    if (!isset($actualBillByRoom[$billRow['room_id']])) $actualBillByRoom[$billRow['room_id']] = $billRow;
}
$actualBillsTotal = array_sum(array_map(function($b){return (float)$b['total_amount'];}, $actualBillByRoom));

// CSV
if (isset($_GET['export']) && $_GET['export']==='csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="energuide_report_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['EnerGuide Report — '.$period]);
    fputcsv($out, []);
    fputcsv($out, ['Unit','Type','Consumption (kWh)','Estimated Cost (₱)','Reading Status']);
    foreach($unitData as $u) {
        $kwh = $u['kwh']!==null ? (float)$u['kwh'] : 0;
        $status = $u['kwh'] !== null ? 'Recorded' : 'Missing';
        fputcsv($out, [$u['room_name'],$u['room_type'],$u['kwh'] !== null ? number_format($kwh,2) : 'No reading',$u['kwh'] !== null && $rate !== null ? number_format($kwh*$rate,2) : 'Not available',$status]);
    }
    fputcsv($out, []);
    fputcsv($out, ['TOTAL','',number_format($totalKwh,2),$estimatedCost !== null ? number_format($estimatedCost,2) : 'Rate not configured','']);
    fputcsv($out, []);
    fputcsv($out, ['Previous Period ('.$prevPeriod.')','',number_format($prevTotal,2),'','']);
    fputcsv($out, ['Increase','',number_format($increaseKwh,2),'',''.$increasePct.'%']);
    fputcsv($out, []);
    fputcsv($out, ['Room','Actual BENECO kWh','Actual BENECO Amount (PHP)','Bill Date','Account Number']);
    foreach ($unitData as $u) {
        $billRow = $actualBillByRoom[$u['room_id']] ?? null;
        fputcsv($out, [$u['room_name'], $billRow && $billRow['total_kwh']!==null ? number_format((float)$billRow['total_kwh'],2) : 'No bill / kWh not recorded', $billRow ? number_format((float)$billRow['total_amount'],2) : 'No bill recorded', $billRow['bill_date'] ?? '', $billRow['account_number'] ?? '']);
    }
    fputcsv($out, ['TOTAL ACTUAL BILLS','',''.number_format($actualBillsTotal,2),'','']);
    fclose($out);
    exit;
}

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
<title>Reports | EnerGuide</title>
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
.topbar .actions{display:flex;gap:8px;}
.card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.05);margin-bottom:20px;}
.card h3{color:var(--green-dark);margin-bottom:14px;border-bottom:2px solid var(--yellow-light);padding-bottom:8px;font-size:16px;}
.btn{background:var(--green);color:#fff;padding:10px 18px;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:14px;transition:.2s;text-decoration:none;display:inline-block;}
.btn:hover{background:var(--green-dark);}
.btn-yellow{background:var(--yellow);color:var(--green-dark);}
.btn-yellow:hover{background:var(--yellow-light);}
label{display:block;font-weight:600;color:var(--green-dark);font-size:13px;margin-bottom:5px;}
select{padding:10px;border:2px solid #ddd;border-radius:8px;font-size:14px;}
select:focus{outline:none;border-color:var(--green);}
table{width:100%;border-collapse:collapse;font-size:14px;}
th{background:var(--green);color:#fff;padding:10px;text-align:left;font-size:13px;}
td{padding:10px;border-bottom:1px solid #eee;}
tr:hover{background:var(--cream);}
tfoot td{background:var(--cream);font-weight:700;color:var(--green-dark);}
.badge{padding:3px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.badge-red{background:#C62828;color:#fff;}
.badge-yellow{background:var(--yellow);color:var(--green-dark);}
.badge-green{background:var(--green);color:#fff;}
.badge-gray{background:#9E9E9E;color:#fff;}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:18px;}
.summary-box{background:linear-gradient(135deg,var(--green),var(--green-light));color:#fff;padding:16px;border-radius:10px;}
.summary-box.yellow{background:linear-gradient(135deg,var(--yellow),var(--yellow-light));color:var(--green-dark);}
.summary-box h4{font-size:12px;text-transform:uppercase;letter-spacing:1px;opacity:.9;}
.summary-box p{font-size:22px;font-weight:700;margin-top:6px;}
.summary-box small{display:block;font-size:12px;margin-top:4px;opacity:.9;}
.bill-box{background:var(--cream);padding:14px;border-radius:8px;border-left:4px solid var(--yellow);margin-top:14px;}
.bill-box h4{color:var(--green-dark);font-size:14px;margin-bottom:6px;}
.bill-box p{font-size:13px;color:var(--muted);}
.bill-box .diff{font-weight:700;color:var(--green-dark);font-size:14px;margin-top:8px;}
@media print{
  .sidebar, .topbar .actions, .no-print{display:none!important;}
  .main{margin:0;padding:20px;}
  body{background:#fff;}
  .card{box-shadow:none;border:1px solid #ddd;page-break-inside:avoid;}
}

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
    <h1>Reports</h1>
    <div class="actions">
      <a class="btn btn-yellow" href="?period=<?=urlencode($period)?>&export=csv">⬇️ CSV</a>
      <button class="btn" onclick="window.print()">🖨️ Print / PDF</button>
    </div>
  </div>

  <div class="card no-print">
    <form method="GET" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
      <div style="flex:1;min-width:220px;">
        <label>Billing Period</label>
        <select name="period" onchange="this.form.submit()">
          <?php foreach($periods as $p): ?>
            <option value="<?=htmlspecialchars($p)?>" <?= $period===$p?'selected':'' ?>><?=htmlspecialchars($p)?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>📊 Energy Management Summary — <?=htmlspecialchars($period)?></h3>

    <div class="summary-grid">
      <div class="summary-box">
        <h4>Total Recorded kWh</h4>
        <p><?=number_format($totalKwh,2)?> kWh</p>
      </div>
      <div class="summary-box yellow">
        <h4>Estimated Electricity Cost</h4>
        <p><?=$estimatedCost !== null ? peso($estimatedCost) : 'Rate not configured'?></p>
        <small><?=$rate !== null ? '@ '.peso($rate).'/kWh' : 'Configure the rate for this billing month'?></small>
      </div>
      <div class="summary-box">
        <h4>Current Period</h4>
        <p><?=number_format($totalKwh,2)?> kWh</p>
      </div>
      <div class="summary-box yellow">
        <h4>Previous Period (<?=htmlspecialchars($prevPeriod ?: '—')?>)</h4>
        <p><?=number_format($prevTotal,2)?> kWh</p>
      </div>
    </div>

    <?php if($prevPeriod): ?>
      <div style="background:var(--mint);padding:14px;border-radius:8px;border-left:4px solid var(--green);margin-bottom:16px;">
        <strong style="color:var(--green-dark);">Change vs Previous Period:</strong>
        <span style="color:<?= $increaseKwh >= 0 ? '#C62828' : 'var(--green)' ?>;font-weight:700;font-size:16px;">
          <?= $increaseKwh >= 0 ? '+' : '' ?><?=number_format($increaseKwh,2)?> kWh
          <?= $increasePct !== null ? '('.(($increasePct>=0)?'+':'').$increasePct.'%)' : '' ?>
        </span>
        <br>
        <small style="color:var(--muted);">
          Previous: <?=number_format($prevTotal,2)?> kWh; Current: <?=number_format($totalKwh,2)?> kWh; 
          <?= $increaseKwh >= 0 ? 'Increase' : 'Decrease' ?>: <?=number_format(abs($increaseKwh),2)?> kWh
        </small>
      </div>
    <?php endif; ?>

    <table>
      <thead>
        <tr><th>Unit</th><th>Type</th><th>Consumption (kWh)</th><th>Estimated Cost (₱)</th><th>Reading Status</th></tr>
      </thead>
      <tbody>
        <?php foreach($unitData as $u): ?>
          <tr>
            <td><strong><?=htmlspecialchars($u['room_name'])?></strong></td>
            <td><?=htmlspecialchars($u['room_type'])?></td>
            <td><?= $u['kwh']!==null ? number_format($u['kwh'],2) : 'No reading' ?></td>
            <td><?= $u['kwh']!==null && $rate!==null ? peso($u['kwh']*$rate) : '—' ?></td>
            <td><?= $u['kwh'] !== null ? 'Recorded' : 'Missing' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="2">TOTAL</td><td><?=number_format($totalKwh,2)?> kWh</td><td><?=$estimatedCost !== null ? peso($estimatedCost) : 'Rate not configured'?></td><td></td></tr>
      </tfoot>
    </table>

    <div class="bill-box">
      <h4>📄 Actual BENECO Bills — Per Room</h4>
      <p>Each room's actual bill is displayed separately and compared with its own estimated cost. Actual bills may include charges and billing-period differences not reflected in the simple kWh estimate.</p>
      <div style="overflow-x:auto;margin-top:10px;">
        <table>
          <tr><th>Room</th><th>Account</th><th>Bill Date</th><th>Actual kWh</th><th>Actual Amount</th><th>Estimated Cost</th><th>Bill Image</th></tr>
          <?php foreach($unitData as $u): $billRow=$actualBillByRoom[$u['room_id']] ?? null; $roomEstimate=($u['kwh']!==null && $rate!==null)?(float)$u['kwh']*$rate:null; ?>
            <tr>
              <td><?=htmlspecialchars($u['room_name'])?></td>
              <td><?=htmlspecialchars($billRow['account_number'] ?? '—')?></td>
              <td><?=htmlspecialchars($billRow['bill_date'] ?? '—')?></td>
              <td><?=$billRow && $billRow['total_kwh']!==null?number_format((float)$billRow['total_kwh'],2).' kWh':'—'?></td>
              <td><?=$billRow?peso($billRow['total_amount']):'No bill'?></td>
              <td><?=$roomEstimate!==null?peso($roomEstimate):'—'?></td>
              <td><?php if($billRow && !empty($billRow['image_path'])): ?><a href="<?=htmlspecialchars($billRow['image_path'])?>" target="_blank" rel="noopener">View image</a><?php else: ?>—<?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <p style="font-weight:700;margin-top:10px;">Combined actual bill total: <?=peso($actualBillsTotal)?></p>
      <?php if(count($actualBillByRoom)<count($unitData)): ?>
        <p style="margin-top:6px;">Some rooms have no bill recorded for this billing period.</p>
      <?php endif; ?>
    </div>


  </div>
</div>
</body>
</html>