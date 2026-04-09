<?php
ob_start();
session_start();
require_once 'includes/db_connect.php';

if (isset($_SESSION['loggedin'])) { ob_end_clean(); header("location: dashboard.php"); exit; }

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $sql = "SELECT user_id,username,password,role,full_name FROM users WHERE username=?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("s",$username); $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows == 1) {
            $stmt->bind_result($id,$u,$hashed,$role,$full_name); $stmt->fetch();
            if (password_verify($password,$hashed)) {
                $_SESSION['loggedin']=true; $_SESSION['id']=$id; $_SESSION['username']=$u;
                $_SESSION['role']=$role; $_SESSION['full_name']=$full_name;
                ob_end_clean(); header("location: dashboard.php"); exit;
            } else { $error="Incorrect password."; }
        } else { $error="No account found with that username."; }
        $stmt->close();
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — Yala Sub-County Hospital LIMS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Serif+Display&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;display:flex;font-family:'DM Sans',sans-serif;background:#f0f4f8}

/* LEFT */
.lp{width:55%;background:#0f172a;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 50px;position:relative;overflow:hidden}
.lp::before{content:'';position:absolute;width:500px;height:500px;border-radius:50%;background:rgba(29,78,216,.15);top:-150px;left:-150px;pointer-events:none}
.lp::after{content:'';position:absolute;width:350px;height:350px;border-radius:50%;background:rgba(29,78,216,.1);bottom:-100px;right:-100px;pointer-events:none}
.lp-logo{width:90px;height:90px;border-radius:50%;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;margin-bottom:28px;position:relative;z-index:1}
.lp h1{font-family:'DM Serif Display',serif;font-size:1.8rem;color:#fff;text-align:center;margin-bottom:8px;position:relative;z-index:1;letter-spacing:-.3px}
.lp .sub{color:rgba(255,255,255,.5);text-align:center;font-size:.85rem;margin-bottom:44px;position:relative;z-index:1;line-height:1.6}
.feature{display:flex;align-items:center;gap:12px;color:rgba(255,255,255,.7);font-size:.82rem;margin-bottom:14px;position:relative;z-index:1;width:100%;max-width:300px}
.fi{width:32px;height:32px;background:rgba(29,78,216,.4);border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.8rem;color:#93c5fd}
.divider-line{width:100%;max-width:300px;height:1px;background:rgba(255,255,255,.08);margin:24px 0;position:relative;z-index:1}
.roles-row{display:flex;flex-wrap:wrap;gap:7px;justify-content:center;position:relative;z-index:1;max-width:300px}
.role-chip{font-size:.7rem;font-weight:600;background:rgba(255,255,255,.07);color:rgba(255,255,255,.55);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:4px 11px}

/* RIGHT */
.rp{width:45%;display:flex;align-items:center;justify-content:center;padding:40px 50px;background:#fff}
.login-box{width:100%;max-width:380px}
.login-box h2{font-family:'DM Serif Display',serif;font-size:1.7rem;color:#0f172a;margin-bottom:6px;letter-spacing:-.3px}
.login-box .tagline{color:#94a3b8;font-size:.85rem;margin-bottom:36px}
.form-lbl{font-size:.73rem;font-weight:600;color:#374151;margin-bottom:6px;display:block}
.inp-wrap{position:relative;margin-bottom:18px}
.inp-wrap i.ico{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#cbd5e1;font-size:.82rem}
.inp-wrap input{width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:12px 14px 12px 36px;font-size:.9rem;font-family:'DM Sans',sans-serif;color:#0f172a;background:#fafafa;outline:none;transition:border-color .15s,box-shadow .15s}
.inp-wrap input:focus{border-color:#1d4ed8;background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.08)}
.inp-wrap input::placeholder{color:#cbd5e1}
.eye-btn{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;font-size:.85rem;padding:4px;line-height:1}
.btn-login{width:100%;background:#1d4ed8;color:#fff;border:none;border-radius:10px;padding:13px;font-size:.95rem;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .15s,transform .1s;letter-spacing:.2px;margin-bottom:28px}
.btn-login:hover{background:#1e40af}
.btn-login:active{transform:scale(.99)}
.err-box{background:#fef2f2;border:1.5px solid #fca5a5;border-radius:9px;color:#991b1b;font-size:.82rem;padding:10px 14px;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.footer-note{text-align:center;color:#cbd5e1;font-size:.73rem;margin-top:8px}
@media(max-width:768px){body{flex-direction:column}.lp{width:100%;min-height:200px;padding:28px 24px}.lp .sub,.feature,.divider-line,.roles-row{display:none}.lp h1{font-size:1.3rem;margin-bottom:0}.rp{width:100%;padding:32px 24px}}
</style>
</head>
<body>

<div class="lp">
    <div class="lp-logo">
        <?php if(file_exists('logo.png')): ?>
        <img src="logo.png" height="55" alt="Logo">
        <?php else: ?>
        <i class="fa-solid fa-flask fa-2x" style="color:#93c5fd"></i>
        <?php endif; ?>
    </div>
    <h1>Yala Sub-County Hospital</h1>
    <p class="sub">Laboratory Information<br>Management System<br><span style="color:rgba(255,255,255,.3)">Siaya County, Kenya</span></p>

    <div class="feature"><div class="fi"><i class="fa-solid fa-flask"></i></div><span>End-to-end lab test management</span></div>
    <div class="feature"><div class="fi"><i class="fa-solid fa-shield-halved"></i></div><span>Role-based secure access</span></div>
    <div class="feature"><div class="fi"><i class="fa-solid fa-file-waveform"></i></div><span>PDF reports &amp; M-Pesa receipts</span></div>
    <div class="feature"><div class="fi"><i class="fa-solid fa-mobile-screen-button"></i></div><span>SMS result delivery via Africa's Talking</span></div>

    <div class="divider-line"></div>
    <div class="roles-row">
        <span class="role-chip"><i class="fa-solid fa-id-card me-1"></i>Receptionist</span>
        <span class="role-chip"><i class="fa-solid fa-user-doctor me-1"></i>Doctor</span>
        <span class="role-chip"><i class="fa-solid fa-microscope me-1"></i>Lab Tech</span>
        <span class="role-chip"><i class="fa-solid fa-shield-halved me-1"></i>Admin</span>
    </div>
</div>

<div class="rp">
    <div class="login-box">
        <h2>Welcome back</h2>
        <p class="tagline">Sign in to access the LIMS portal</p>

        <?php if($error): ?>
        <div class="err-box"><i class="fa-solid fa-circle-exclamation"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" action="index.php">
            <label class="form-lbl">Username</label>
            <div class="inp-wrap">
                <i class="fa-solid fa-user ico"></i>
                <input type="text" name="username" placeholder="Enter your username" required
                       value="<?php echo isset($_POST['username'])?htmlspecialchars($_POST['username']):''; ?>">
            </div>
            <label class="form-lbl">Password</label>
            <div class="inp-wrap">
                <i class="fa-solid fa-lock ico"></i>
                <input type="password" name="password" id="pwInput" placeholder="Enter your password" required>
                <button type="button" class="eye-btn" onclick="togglePw()"><i class="fa-solid fa-eye" id="eyeIco"></i></button>
            </div>
            <button type="submit" class="btn-login"><i class="fa-solid fa-right-to-bracket me-2"></i>Sign In</button>
        </form>
        <p class="footer-note"><i class="fa-solid fa-circle-info me-1"></i>Contact IT Support for account issues</p>
    </div>
</div>

<script>
function togglePw(){
    const i=document.getElementById('pwInput'),ic=document.getElementById('eyeIco');
    const show=i.type==='password'; i.type=show?'text':'password';
    ic.className=show?'fa-solid fa-eye-slash':'fa-solid fa-eye';
}
</script>
</body>
</html>
