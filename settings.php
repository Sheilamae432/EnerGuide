<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
require 'config/database.php';

$msg=''; $err='';

/* 1. Update Unit */
if (($_POST['action'] ?? '') === 'update_unit') {
    $id = (int)$_POST['room_id'];
    $type = $_POST['room_type'];
    $stat = $_POST['status'];
    $meter = trim($_POST['meter_id']);
    $notes = trim($_POST['notes']);
    $allowed = ['Studio Unit','Bachelor Unit','2-Bedroom Penthouse'];
    if (!in_array($type, $allowed)) {
        $err = "Invalid unit type.";
    } else {
        $pdo->prepare("UPDATE rooms SET room_type=?,status=?,meter_id=?,notes=? WHERE room_id=?")
            ->execute([$type,$stat,$meter,$notes,$id]);
        $msg = "Unit updated successfully.";
    }
}

/* 2. Add Rate */
if (($_POST['action'] ?? '') === 'add_rate') {
    $rate = (float)($_POST['rate_per_kwh'] ?? 0);
    $date = $_POST['effective_date'] ?? '';
    $month = trim($_POST['billing_month'] ?? '');
    $cat = trim($_POST['rate_category'] ?? 'Commercial High Voltage');
    $notes = trim($_POST['notes'] ?? '');

    if ($rate <= 0 || $date === '' || $month === '' || $cat === '') {
        $err = "Please enter a valid rate, effective date, billing month, and customer category.";
    } else {
        $pdo->prepare("UPDATE electricity_rates SET status='Inactive' WHERE status='Active'")
            ->execute();
        $pdo->prepare("INSERT INTO electricity_rates (rate_per_kwh,effective_date,billing_month,rate_category,status,notes) VALUES (?,?,?,?, 'Active', ?)")
            ->execute([$rate,$date,$month,$cat,$notes]);
        $msg = "Rate saved. New active rate: ".peso($rate)."/kWh";
    }
}

/* 4. Add User */
if (($_POST['action'] ?? '') === 'add_user') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'staff';
    if ($name==='' || $email==='' || $pass==='') {
        $err = "All user fields are required.";
    } else {
        try {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)")
                ->execute([$name,$email,$hash,$role]);
            $msg = "User added successfully.";
        } catch (PDOException $e) {
            $err = "Email already exists.";
        }
    }
}

$rate = getActiveRate($pdo, ENERGUIDE_RATE_CATEGORY);
$rooms = $pdo->query("SELECT * FROM rooms ORDER BY room_id")->fetchAll();
$rates = $pdo->query("SELECT * FROM electricity_rates ORDER BY effective_date DESC LIMIT 10")->fetchAll();
$users = $pdo->query("SELECT user_id, name, email, role, created_at FROM users ORDER BY user_id")->fetchAll();
$bills = $pdo->query("SELECT b.*, r.room_name FROM beneco_bills b LEFT JOIN rooms r ON r.room_id=b.room_id ORDER BY b.bill_date DESC, b.bill_id DESC LIMIT 30")->fetchAll();

$editRoom = null;
if (isset($_GET['edit_unit'])) {
    $s = $pdo->prepare("SELECT * FROM rooms WHERE room_id=?");
    $s->execute([(int)$_GET['edit_unit']]);
    $editRoom = $s->fetch();
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
<title>Settings | EnerGuide</title>
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
.topbar{background:#fff;padding:16px 24px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:22px;border-left:5px solid var(--yellow);}
.topbar h1{color:var(--green-dark);font-size:22px;}
.card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.05);margin-bottom:20px;}
.card h3{color:var(--green-dark);margin-bottom:14px;border-bottom:2px solid var(--yellow-light);padding-bottom:8px;font-size:16px;}
label{display:block;font-weight:600;color:var(--green-dark);font-size:13px;margin-bottom:5px;}
input,select,textarea{width:100%;padding:10px;border:2px solid #ddd;border-radius:8px;font-size:14px;margin-bottom:12px;}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--green);}
input[readonly]{background:var(--cream);font-weight:600;}
.btn{background:var(--green);color:#fff;padding:10px 18px;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:14px;text-decoration:none;display:inline-block;}
.btn:hover{background:var(--green-dark);}
.btn-yellow{background:var(--yellow);color:var(--green-dark);}
.btn-yellow:hover{background:var(--yellow-light);}
.btn-sm{padding:6px 12px;font-size:12px;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th{background:var(--green);color:#fff;padding:10px;text-align:left;font-size:13px;}
td{padding:10px;border-bottom:1px solid #eee;}
tr:hover{background:var(--cream);}
.badge{padding:3px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.badge-green{background:var(--green);color:#fff;}
.badge-yellow{background:var(--yellow);color:var(--green-dark);}
.badge-gray{background:#9E9E9E;color:#fff;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;}
.alert-success{background:var(--mint);color:var(--green-dark);border-left:4px solid var(--green);}
.alert-error{background:#FFEBEE;color:#C62828;border-left:4px solid #C62828;}
.grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:18px;}
.note{background:var(--cream);padding:12px 16px;border-radius:8px;border-left:4px solid var(--yellow);font-size:13px;color:var(--green-dark);margin-bottom:14px;}

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
  <div class="topbar"><h1>Settings</h1></div>

  <?php if($msg): ?><div class="alert alert-success">✅ <?=htmlspecialchars($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-error">❌ <?=htmlspecialchars($err)?></div><?php endif; ?>

  <div class="note">
    ⚙️ Manage the six long-term rental units, monthly BENECO rate configuration, user accounts, and actual BENECO bills. Historical readings and rates are preserved when configurations change.
  </div>

  <div class="grid-2">

    <!-- 1. UNITS -->
    <div class="card" id="units">
      <h3>🏠 Manage the Six Units</h3>
      <?php if($editRoom): ?>
        <form method="POST">
          <input type="hidden" name="action" value="update_unit">
          <input type="hidden" name="room_id" value="<?=$editRoom['room_id']?>">
          <label>Unit (fixed)</label>
          <input type="text" value="<?=htmlspecialchars($editRoom['room_name'])?>" readonly>
          <label>Unit Type</label>
          <select name="room_type" required>
            <?php foreach(['Studio Unit','Bachelor Unit','2-Bedroom Penthouse'] as $t): ?>
              <option <?= $editRoom['room_type']===$t?'selected':'' ?>><?=$t?></option>
            <?php endforeach; ?>
          </select>
          <label>Status</label>
          <select name="status">
            <?php foreach(['Occupied','Vacant','Maintenance'] as $s): ?>
              <option <?= $editRoom['status']===$s?'selected':'' ?>><?=$s?></option>
            <?php endforeach; ?>
          </select>
          <label>Meter ID</label>
          <input type="text" name="meter_id" value="<?=htmlspecialchars($editRoom['meter_id'])?>">
          <label>Notes</label>
          <textarea name="notes" rows="2"><?=htmlspecialchars($editRoom['notes'])?></textarea>
          <button class="btn" type="submit">💾 Update Unit</button>
          <a href="settings.php" class="btn btn-yellow">Cancel</a>
        </form>
      <?php else: ?>
        <table>
          <tr><th>Unit</th><th>Type</th><th>Status</th><th>Action</th></tr>
          <?php foreach($rooms as $r): ?>
            <tr>
              <td><strong><?=htmlspecialchars($r['room_name'])?></strong></td>
              <td><?=htmlspecialchars($r['room_type'])?></td>
              <td><?=htmlspecialchars($r['status'])?></td>
              <td><a class="btn btn-sm btn-yellow" href="?edit_unit=<?=$r['room_id']?>">Edit</a></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

    <!-- 2. RATES -->
    <div class="card">
      <h3>💡 BENECO Rate Configuration</h3>
      <p style="font-size:13px;color:var(--muted);margin-bottom:14px;">
        Most recently configured rate: <strong><?=$rate !== null ? peso($rate).'/kWh' : 'Not configured'?></strong>
      </p>
      <form method="POST">
        <input type="hidden" name="action" value="add_rate">
        <label>Rate per kWh (₱)</label>
        <input type="number" step="0.0001" name="rate_per_kwh" value="<?= $rate !== null ? number_format($rate,4,'.','') : '' ?>" required>
        <label>Effective Date</label>
        <input type="date" name="effective_date" value="<?=date('Y-m-d')?>" required>
        <label>Billing Month</label>
        <input type="text" name="billing_month" placeholder="e.g., November 2026" required>
        <label>BENECO Customer Category (shared by all six rooms)</label>
        <select name="rate_category" required>
          <option value="Commercial High Voltage" selected>Commercial High Voltage</option>
          <option value="Commercial Low Voltage">Commercial Low Voltage</option>
          <option value="Residential">Residential</option>
          <option value="Other">Other (specify in notes)</option>
        </select>
        <label>Notes</label>
        <textarea name="notes" rows="2" placeholder="Optional notes"></textarea>
        <button class="btn" type="submit">💾 Save New Rate</button>
      </form>
      <h4 style="margin-top:20px;color:var(--green-dark);font-size:14px;">Rate History</h4>
      <table>
        <tr><th>Rate</th><th>Effective</th><th>Category</th><th>Status</th></tr>
        <?php foreach($rates as $r): ?>
          <tr>
            <td><?=peso($r['rate_per_kwh'])?>/kWh</td>
            <td><?=htmlspecialchars($r['effective_date'])?></td>
            <td><?=htmlspecialchars($r['rate_category'])?></td>
            <td><span class="badge <?= $r['status']==='Active'?'badge-green':'badge-gray' ?>"><?=$r['status']?></span></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <!-- 4. USERS -->
    <div class="card">
      <h3>👤 User Accounts</h3>
      <form method="POST">
        <input type="hidden" name="action" value="add_user">
        <label>Full Name</label>
        <input type="text" name="name" required>
        <label>Email</label>
        <input type="email" name="email" required>
        <label>Password</label>
        <input type="password" name="password" required>
        <label>Role</label>
        <select name="role">
          <option value="owner">Owner</option>
          <option value="admin">Admin</option>
          <option value="staff">Staff</option>
        </select>
        <button class="btn" type="submit">➕ Add User</button>
      </form>
      <h4 style="margin-top:20px;color:var(--green-dark);font-size:14px;">Existing Users</h4>
      <table>
        <tr><th>Name</th><th>Email</th><th>Role</th></tr>
        <?php foreach($users as $u): ?>
          <tr>
            <td><?=htmlspecialchars($u['name'])?></td>
            <td><?=htmlspecialchars($u['email'])?></td>
            <td><span class="badge badge-yellow"><?=htmlspecialchars($u['role'])?></span></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <!-- ACTUAL BENECO BILL HISTORY (entry is handled from Dashboard) -->
    <div class="card" id="bills">
      <h3>📄 Bill Records</h3>
      <p style="font-size:13px;color:var(--muted);margin-bottom:14px;">
        Review actual BENECO bills recorded from the Dashboard, including their billing details and uploaded bill images.
      </p>
      <h4 style="margin-top:10px;color:var(--green-dark);font-size:14px;">Recent Bills</h4>
      <table>
        <tr><th>Room</th><th>Month</th><th>Amount</th><th>Date</th><th>Attachment</th></tr>
        <?php foreach($bills as $b): ?>
          <tr>
            <td><?=htmlspecialchars($b['room_name'] ?: 'Unassigned legacy bill')?></td>
            <td><?=htmlspecialchars($b['billing_month'])?></td>
            <td><?=peso($b['total_amount'])?></td>
            <td><?=htmlspecialchars($b['bill_date'])?></td>
            <td><?php if(!empty($b['image_path'])): ?><a class="btn btn-sm btn-yellow" href="<?=htmlspecialchars($b['image_path'])?>" target="_blank" rel="noopener">View image</a><?php else: ?>No image<?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
</div>
<script>
(function(){
  const billsLink=document.getElementById('nav-bills');
  const settingsLink=document.getElementById('nav-settings');
  function updateActive(){
    if(location.hash==='#bills'){billsLink?.classList.add('active');settingsLink?.classList.remove('active');}
    else{settingsLink?.classList.add('active');billsLink?.classList.remove('active');}
  }
  updateActive();window.addEventListener('hashchange',updateActive);
})();
</script>
</body>
</html>