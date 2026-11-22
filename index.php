<?php
session_start();
require 'config/database.php';

if (isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = "Please enter both email and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['email']   = $user['email'];
            $_SESSION['role']    = $user['role'];
            header("Location: dashboard.php");
            exit;
        } else {
            $error = "Invalid email or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login | EnerGuide</title>
<style>
:root{--green-dark:#1B5E20;--green:#2E7D32;--green-light:#4CAF50;--yellow:#F9A825;--yellow-light:#FDD835;--mint:#E8F5E9;--text:#212121;--muted:#616161;}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',Tahoma,sans-serif;}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--green-dark) 0%,var(--green) 50%,var(--yellow) 100%);padding:20px;}
.login-box{background:#fff;border-radius:16px;padding:40px;width:100%;max-width:420px;box-shadow:0 20px 50px rgba(0,0,0,.3);border-top:6px solid var(--yellow);}
.login-box h1{color:var(--green-dark);text-align:center;font-size:28px;letter-spacing:2px;margin-bottom:6px;}
.login-box h1 span{color:var(--yellow);}
.login-box p.sub{text-align:center;color:var(--muted);font-size:13px;margin-bottom:26px;}
label{display:block;font-weight:600;color:var(--green-dark);margin-bottom:6px;font-size:14px;}
.input-group{position:relative;margin-bottom:16px;}
input{width:100%;padding:12px 42px 12px 14px;border:2px solid #ddd;border-radius:8px;font-size:14px;}
input:focus{outline:none;border-color:var(--green);box-shadow:0 0 0 3px rgba(76,175,80,.15);}
.toggle-pass{position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--muted);font-size:16px;user-select:none;}
.btn-login{width:100%;padding:13px;background:var(--green);color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;letter-spacing:1px;}
.btn-login:hover{background:var(--green-dark);}
.error-box{background:#FFEBEE;color:#C62828;padding:12px;border-radius:8px;border-left:4px solid #C62828;margin-bottom:16px;font-size:13px;}
.footer-note{text-align:center;margin-top:22px;font-size:11px;color:var(--muted);}
</style>
</head>
<body>
<div class="login-box">
  <h1>⚡ ENER<span>GUIDE</span></h1>
  <p class="sub">Electricity Consumption Analysis & Decision Support System</p>
  <?php if ($error): ?><div class="error-box"><?=htmlspecialchars($error)?></div><?php endif; ?>
  <form method="POST" autocomplete="off">
    <label>Email / Username</label>
    <div class="input-group"><input type="email" name="email" placeholder="owner@energuide.com" required></div>
    <label>Password</label>
    <div class="input-group">
      <input type="password" name="password" id="password" placeholder="Enter password" required>
      <span class="toggle-pass" onclick="togglePass()">👁</span>
    </div>
    <button type="submit" class="btn-login">LOGIN</button>
  </form>
  <div class="footer-note">© <?=date('Y')?> EnerGuide · Long-Term Rental Accommodation</div>
</div>
<script>
function togglePass(){ const p=document.getElementById('password'); p.type = p.type==='password'?'text':'password'; }
</script>
</body>
</html>