<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
require 'config/database.php';

function monthIndexFromPeriod($period, $fallbackDate = null) {
    $dt = DateTime::createFromFormat('!F Y', trim((string)$period));
    if (!$dt && $fallbackDate) $dt = new DateTime($fallbackDate);
    return $dt ? ((int)$dt->format('Y') * 12 + (int)$dt->format('n')) : null;
}
function linearForecast(array $points, int $targetIndex) {
    $n = count($points);
    if ($n < 3) return null;
    $xs = array_column($points, 'x');
    $ys = array_column($points, 'y');
    $meanX = array_sum($xs) / $n;
    $meanY = array_sum($ys) / $n;
    $numerator = 0.0; $denominator = 0.0;
    for ($i=0; $i<$n; $i++) {
        $numerator += ($xs[$i] - $meanX) * ($ys[$i] - $meanY);
        $denominator += ($xs[$i] - $meanX) ** 2;
    }
    if ($denominator == 0.0) return null;
    $slope = $numerator / $denominator;
    $intercept = $meanY - ($slope * $meanX);
    return max(0, $intercept + ($slope * $targetIndex));
}

$rooms = $pdo->query('SELECT room_id, room_name, room_type FROM rooms ORDER BY room_id')->fetchAll();
$latestDate = $pdo->query('SELECT MAX(reading_date) FROM meter_readings')->fetchColumn();
$defaultMonth = $latestDate ? (new DateTime($latestDate))->modify('first day of next month')->format('Y-m') : date('Y-m', strtotime('first day of next month'));
$targetMonth = $_GET['target_month'] ?? $defaultMonth;
$selectedRoom = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$targetDate = DateTime::createFromFormat('!Y-m', $targetMonth);
$error = '';
$results = [];
$chartLabels = [];
$chartActual = [];
$chartPrediction = [];
$totalPredicted = 0.0;
$forecastCount = 0;
$targetIndex = $targetDate ? ((int)$targetDate->format('Y') * 12 + (int)$targetDate->format('n')) : null;

if (!$targetDate) {
    $error = 'Please select a valid forecast month.';
} else {
    $targetDate->modify('first day of this month');
    $targetLabel = $targetDate->format('F Y');
    foreach ($rooms as $room) {
        $stmt = $pdo->prepare('SELECT billing_period, reading_date, consumption_kwh FROM meter_readings WHERE room_id=? ORDER BY reading_date ASC, reading_id ASC');
        $stmt->execute([$room['room_id']]);
        $rows = $stmt->fetchAll();
        $monthly = [];
        foreach ($rows as $row) {
            $idx = monthIndexFromPeriod($row['billing_period'], $row['reading_date']);
            if ($idx !== null) $monthly[$idx] = (float)$row['consumption_kwh'];
        }
        ksort($monthly);
        $points = [];
        foreach ($monthly as $idx=>$kwh) $points[] = ['x'=>(int)$idx, 'y'=>(float)$kwh];
        $lastIndex = $points ? $points[count($points)-1]['x'] : null;
        $forecast = null;
        $status = 'Insufficient historical data';
        if (count($points) >= 3 && $lastIndex !== null && $targetIndex > $lastIndex) {
            $forecast = linearForecast($points, $targetIndex);
            $status = $forecast === null ? 'Unable to calculate trend' : 'Prediction available';
        } elseif (count($points) >= 3 && $targetIndex <= $lastIndex) {
            $status = 'Select a month after the latest recorded period';
        }
        if ($forecast !== null) { $totalPredicted += $forecast; $forecastCount++; }
        $results[] = ['room'=>$room, 'count'=>count($points), 'forecast'=>$forecast, 'status'=>$status, 'points'=>$points];
        if ($selectedRoom === (int)$room['room_id']) {
            $chartRows = array_slice($points, -12);
            foreach ($chartRows as $pt) {
                $dt = DateTime::createFromFormat('!Y-n', floor($pt['x']/12).'-'.(($pt['x']%12) ?: 12));
                // Format month label using arithmetic that handles year boundaries.
                $year = intdiv($pt['x']-1, 12); $month = (($pt['x']-1)%12)+1;
                $chartLabels[] = date('M Y', strtotime(sprintf('%04d-%02d-01', $year, $month)));
                $chartActual[] = $pt['y'];
                $chartPrediction[] = null;
            }
            $chartLabels[] = $targetDate->format('M Y');
            $chartActual[] = null;
            $chartPrediction[] = $forecast;
        }
    }
}
if ($selectedRoom === 0 && !empty($rooms)) $selectedRoom = (int)$rooms[0]['room_id'];
if ($selectedRoom && empty($chartLabels) && $targetDate) {
    foreach ($results as $res) if ((int)$res['room']['room_id'] === $selectedRoom) {
        foreach (array_slice($res['points'], -12) as $pt) {
            $year = intdiv($pt['x']-1, 12); $month = (($pt['x']-1)%12)+1;
            $chartLabels[] = date('M Y', strtotime(sprintf('%04d-%02d-01', $year, $month)));
            $chartActual[] = $pt['y']; $chartPrediction[] = null;
        }
        $chartLabels[] = $targetDate->format('M Y'); $chartActual[] = null; $chartPrediction[] = $res['forecast'];
    }
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
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Predictive Analytics | EnerGuide</title><script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--green-dark:#1B5E20;--green:#2E7D32;--green-light:#4CAF50;--yellow:#F9A825;--yellow-light:#FDD835;--cream:#FFF9C4;--mint:#E8F5E9;--text:#212121;--muted:#616161}*{box-sizing:border-box;font-family:'Segoe UI',Tahoma,sans-serif}body{margin:0;background:var(--mint);color:var(--text);display:flex;min-height:100vh}.sidebar{width:240px;background:linear-gradient(180deg,var(--green-dark),var(--green));color:#fff;padding:20px 0;position:fixed;height:100vh;overflow:auto}.sidebar h2{padding:0 20px 18px;color:var(--yellow-light);border-bottom:1px solid #ffffff33;font-size:20px;letter-spacing:2px}.sidebar a{display:block;padding:14px 20px;color:#fff;text-decoration:none;font-size:14px;border-left:4px solid transparent}.sidebar a.active{background:var(--yellow);color:var(--green-dark);font-weight:700}.main{margin-left:240px;flex:1;padding:24px}.topbar,.card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px #0000000d;margin-bottom:20px}.topbar{border-left:5px solid var(--yellow)}h1,h3{color:var(--green-dark);margin-top:0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}.stat{background:var(--green);color:white;border-radius:10px;padding:18px}.stat.yellow{background:var(--yellow);color:var(--green-dark)}.stat strong{font-size:25px;display:block;margin-top:8px}label{display:block;color:var(--green-dark);font-weight:600;font-size:13px;margin-bottom:6px}input,select{padding:10px;border:1px solid #ccc;border-radius:7px;width:100%;margin-bottom:12px}.btn{background:var(--green);color:white;padding:10px 16px;border:0;border-radius:7px;text-decoration:none;cursor:pointer}.btn-yellow{background:var(--yellow);color:var(--green-dark)}table{width:100%;border-collapse:collapse;font-size:14px}th{background:var(--green);color:white;text-align:left;padding:10px}td{padding:10px;border-bottom:1px solid #eee}.note{background:var(--cream);border-left:4px solid var(--yellow);padding:12px;border-radius:7px;font-size:13px;margin-bottom:14px}.muted{color:var(--muted);font-size:13px}.status{font-size:12px;color:var(--green-dark);font-weight:600}@media(max-width:800px){.sidebar{width:190px}.main{margin-left:190px;padding:12px}}@media(max-width:600px){body{display:block}.sidebar{position:relative;width:100%;height:auto}.main{margin:0}}

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
</style></head><body>
<aside class="eg-sidebar">
  <div class="eg-brand"><div class="eg-brand-mark">⚡</div><div class="eg-brand-copy"><strong>EnerGuide</strong><small>Energy Monitoring &amp; Decision Support<br>for Long-Term Rentals</small></div></div>
  <nav class="eg-nav">
    <?php foreach($menu as $url=>$item): $href=explode('#',$url)[0]; $isBill=($url==='settings.php#bills'); $active=($href===$current && !$isBill && ($href!=='settings.php' || $url==='settings.php')); ?>
      <a href="<?=$url?>" id="<?=$isBill?'nav-bills':($url==='settings.php'?'nav-settings':'nav-'.$href)?>" class="<?=$active?'active':''?>"><span class="eg-ico"><?=$item[1]?></span><span class="eg-label"><?=$item[0]?></span></a>
    <?php endforeach; ?>
  </nav>
  <a class="eg-logout" href="logout.php"><span class="eg-logout-icon">↪</span><span class="eg-logout-label">Logout</span></a>
</aside>
<main class="eg-main"><div class="topbar"><h1>Predictive Analytics</h1><p class="muted">Estimate future electricity consumption using actual historical meter-reading records saved in EnerGuide.</p></div>
<?php if($error): ?><div class="card"><?=htmlspecialchars($error)?></div><?php endif; ?>
<form class="card" method="GET"><div class="grid"><div><label>Forecast month</label><input type="month" name="target_month" value="<?=htmlspecialchars($targetMonth)?>" required></div><div><label>Unit for chart</label><select name="room_id"><?php foreach($rooms as $room): ?><option value="<?=$room['room_id']?>" <?=$selectedRoom===(int)$room['room_id']?'selected':''?>><?=htmlspecialchars($room['room_name'])?></option><?php endforeach; ?></select></div><div style="align-self:end"><button class="btn" type="submit">Generate Forecast</button></div></div></form>
<div class="grid"><div class="stat"><span>Units with available predictions</span><strong><?=$forecastCount?> / <?=count($rooms)?></strong></div><div class="stat yellow"><span>Combined predicted consumption</span><strong><?=$forecastCount ? number_format($totalPredicted,2).' kWh' : 'Not available'?></strong></div></div>
<div class="card"><h3>Forecast by Rental Unit — <?=isset($targetLabel)?htmlspecialchars($targetLabel):'Selected Month'?></h3><p class="muted">Each unit is forecast separately. The combined total includes only units with enough historical data to calculate a prediction.</p><div style="overflow-x:auto"><table><thead><tr><th>Rental unit</th><th>Historical months</th><th>Predicted consumption</th><th>Result</th></tr></thead><tbody><?php foreach($results as $res): ?><tr><td><?=htmlspecialchars($res['room']['room_name'])?></td><td><?=$res['count']?></td><td><?=$res['forecast']!==null?number_format($res['forecast'],2).' kWh':'—'?></td><td><?=htmlspecialchars($res['status'])?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card"><h3>Actual History and Selected Forecast</h3><canvas id="forecastChart" height="100"></canvas><p class="muted">Actual values come from saved meter readings. The forecast is calculated with a simple linear regression over the available monthly records. At least three distinct historical months are required. Predictions are estimates, not guaranteed usage.</p></div>
</main><script>
const labels=<?=json_encode($chartLabels,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
const actual=<?=json_encode($chartActual)?>;
const predicted=<?=json_encode($chartPrediction)?>;
new Chart(document.getElementById('forecastChart'),{type:'line',data:{labels,datasets:[{label:'Actual consumption (kWh)',data:actual,borderColor:'#2E7D32',backgroundColor:'#2E7D32',tension:.25,spanGaps:false},{label:'Predicted consumption (kWh)',data:predicted,borderColor:'#F9A825',backgroundColor:'#F9A825',borderDash:[6,5],tension:.25,spanGaps:false}]},options:{responsive:true,plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true,title:{display:true,text:'kWh'}}}}});
</script></body></html>
