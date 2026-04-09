<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';
if (!isset($_SESSION['loggedin'])||($_SESSION['role']!='Admin'&&$_SESSION['role']!='Receptionist')) { header("location: dashboard.php"); exit; }

$results = []; $preview = []; $step = 'upload';

// STEP 2: PREVIEW
if ($_SERVER["REQUEST_METHOD"]=="POST" && isset($_POST['preview']) && isset($_FILES['csv'])) {
    csrf_verify();
    $file = $_FILES['csv']['tmp_name'];
    if (($h = fopen($file, 'r')) !== false) {
        $headers = array_map('trim', fgetcsv($h));
        $required = ['opd_number','full_name','age','gender'];
        $missing  = array_diff($required, array_map('strtolower', $headers));
        if ($missing) {
            $step = 'error';
            $error = "Missing columns: " . implode(', ', $missing);
        } else {
            $step = 'preview';
            while (($row = fgetcsv($h)) !== false && count($preview) < 10) {
                $preview[] = array_combine($headers, $row);
            }
            copy($file, sys_get_temp_dir().'/lims_import.csv');
        }
        fclose($h);
    }
}

// STEP 3: IMPORT
if ($_SERVER["REQUEST_METHOD"]=="POST" && isset($_POST['confirm_import'])) {
    csrf_verify();
    $step = 'done';
    $file = sys_get_temp_dir().'/lims_import.csv';
    if (($h = fopen($file,'r')) !== false) {
        $headers = array_map('trim', fgetcsv($h));
        $stmt = $conn->prepare("INSERT INTO patients (opd_number,full_name,age,gender,phone_number) VALUES (?,?,?,?,?)");
        $row_n = 1;
        while (($row = fgetcsv($h)) !== false) {
            $row_n++;
            $d = array_combine($headers, $row);
            $opd = trim($d['opd_number']); $name = trim($d['full_name']);
            $age = intval($d['age']??0); $gender = trim($d['gender']??'');
            $phone = trim($d['phone_number']??$d['phone']??'');

            $chk = $conn->prepare("SELECT patient_id FROM patients WHERE opd_number=?");
            $chk->bind_param("s",$opd); $chk->execute(); $chk->store_result();
            if ($chk->num_rows > 0) { $results[] = ['row'=>$row_n,'status'=>'skip','msg'=>"$opd already exists"]; $chk->close(); continue; }
            $chk->close();

            $stmt->bind_param("ssiss",$opd,$name,$age,$gender,$phone);
            if ($stmt->execute()) {
                audit_log($conn,'import_patient','patients',$conn->insert_id,$opd);
                $results[] = ['row'=>$row_n,'status'=>'ok','msg'=>"$name ($opd) imported"];
            } else {
                $results[] = ['row'=>$row_n,'status'=>'err','msg'=>$conn->error];
            }
        }
        $stmt->close(); fclose($h); unlink($file);
    }
}

$page_title = "Import Patients";
include 'includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Serif+Display&display=swap');
.pw{max-width:800px;margin:0 auto;padding:0 0 48px;font-family:'DM Sans',sans-serif}
.ph h1{font-family:'DM Serif Display',serif;font-size:1.85rem;color:#0f172a;margin:0 0 3px;letter-spacing:-.4px}
.ph p{font-size:.8rem;color:#94a3b8;margin:0 0 28px}
.panel{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:18px}
.phead{padding:14px 20px;border-bottom:1px solid #f1f5f9}
.ptitle{font-size:.63rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8}
.pbody{padding:20px}
.drop-zone{border:2px dashed #e2e8f0;border-radius:12px;padding:40px 20px;text-align:center;cursor:pointer;transition:border-color .15s;background:#fafafa}
.drop-zone:hover,.drop-zone.over{border-color:#3b82f6;background:#eff6ff}
.drop-zone input[type=file]{display:none}
.drop-icon{font-size:2rem;color:#cbd5e1;margin-bottom:10px}
.drop-text{font-size:.9rem;color:#64748b}
.drop-hint{font-size:.75rem;color:#94a3b8;margin-top:6px}
.btn-primary{background:#1d4ed8;color:#fff;border:none;border-radius:8px;padding:11px 22px;font-size:.88rem;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .15s;display:inline-flex;align-items:center;gap:7px}
.btn-primary:hover{background:#1e40af}
.btn-secondary{background:#fff;color:#64748b;border:1.5px solid #e2e8f0;border-radius:8px;padding:11px 18px;font-size:.85rem;font-family:'DM Sans',sans-serif;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.schema-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;font-family:monospace;font-size:.78rem;color:#374151;line-height:1.8}
table.pt{width:100%;border-collapse:collapse}
table.pt thead th{padding:9px 14px;font-size:.63rem;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#94a3b8;text-align:left;border-bottom:1px solid #f1f5f9}
table.pt tbody tr{border-bottom:1px solid #f9fafb}
table.pt td{padding:9px 14px;font-size:.83rem}
.res-ok{color:#16a34a;font-size:.8rem}.res-skip{color:#d97706;font-size:.8rem}.res-err{color:#dc2626;font-size:.8rem}
</style>

<div class="pw">
<div class="ph"><h1>Bulk Import Patients</h1><p>Upload a CSV to register multiple patients at once</p></div>

<?php if($step==='upload'||$step==='error'): ?>
<?php if(isset($error)): ?><div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:9px;padding:11px 15px;color:#991b1b;font-size:.84rem;margin-bottom:16px"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<div class="panel">
    <div class="phead"><span class="ptitle">Upload CSV File</span></div>
    <div class="pbody">
    <form method="post" enctype="multipart/form-data" id="uploadForm">
        <?php csrf_field(); ?>
        <div class="drop-zone" id="dropZone" onclick="document.getElementById('csvFile').click()">
            <div class="drop-icon"><i class="fa-solid fa-file-csv"></i></div>
            <div class="drop-text" id="dropText">Click to select a CSV file</div>
            <div class="drop-hint">or drag and drop here</div>
            <input type="file" id="csvFile" name="csv" accept=".csv" onchange="fileChosen(this)">
        </div>
        <div style="margin-top:20px">
            <button type="submit" name="preview" class="btn-primary"><i class="fa-solid fa-eye"></i> Preview Import</button>
        </div>
    </form>
    </div>
</div>

<div class="panel">
    <div class="phead"><span class="ptitle">Required CSV format</span></div>
    <div class="pbody">
        <div class="schema-box">
            opd_number, full_name, age, gender, phone_number<br>
            OP-2025-001, Jane Achieng, 34, Female, 0712345678<br>
            OP-2025-002, John Odhiambo, 52, Male, 0798765432
        </div>
        <p style="font-size:.75rem;color:#94a3b8;margin-top:10px">Columns <code>opd_number</code>, <code>full_name</code>, <code>age</code>, and <code>gender</code> are required. <code>phone_number</code> is optional.</p>
    </div>
</div>

<?php elseif($step==='preview'): ?>
<div class="panel">
    <div class="phead"><span class="ptitle">Preview — first 10 rows</span></div>
    <table class="pt">
        <thead><tr><th>OPD Number</th><th>Full Name</th><th>Age</th><th>Gender</th><th>Phone</th></tr></thead>
        <tbody>
        <?php foreach($preview as $r): ?>
        <tr>
            <td><?php echo htmlspecialchars($r['opd_number']??''); ?></td>
            <td><?php echo htmlspecialchars($r['full_name']??''); ?></td>
            <td><?php echo htmlspecialchars($r['age']??''); ?></td>
            <td><?php echo htmlspecialchars($r['gender']??''); ?></td>
            <td><?php echo htmlspecialchars($r['phone_number']??$r['phone']??''); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div style="padding:16px 20px;background:#f8fafc;border-top:1px solid #f1f5f9;display:flex;gap:10px">
        <a href="import_patients.php" class="btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <form method="post" style="display:inline">
            <?php csrf_field(); ?>
            <button type="submit" name="confirm_import" class="btn-primary"><i class="fa-solid fa-file-import"></i> Confirm Import</button>
        </form>
    </div>
</div>

<?php elseif($step==='done'):
    $ok   = count(array_filter($results, fn($r)=>$r['status']==='ok'));
    $skip = count(array_filter($results, fn($r)=>$r['status']==='skip'));
    $err  = count(array_filter($results, fn($r)=>$r['status']==='err'));
?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:14px 18px;margin-bottom:16px;font-size:.85rem;color:#166534">
    <strong>Import complete.</strong> <?php echo $ok; ?> imported · <?php echo $skip; ?> skipped (duplicate) · <?php echo $err; ?> errors
</div>
<div class="panel">
    <div class="phead"><span class="ptitle">Import results</span></div>
    <table class="pt">
        <thead><tr><th>Row</th><th>Status</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach($results as $r): ?>
        <tr>
            <td style="color:#94a3b8;font-size:.78rem"><?php echo $r['row']; ?></td>
            <td><span class="res-<?php echo $r['status']; ?>"><?php echo strtoupper($r['status']); ?></span></td>
            <td style="font-size:.8rem;color:#374151"><?php echo htmlspecialchars($r['msg']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div style="padding:14px 20px;background:#f8fafc;border-top:1px solid #f1f5f9">
        <a href="import_patients.php" class="btn-secondary"><i class="fa-solid fa-arrow-left"></i> Import another file</a>
    </div>
</div>
<?php endif; ?>
</div>

<script>
function fileChosen(input){
    if(input.files[0]) document.getElementById('dropText').textContent=input.files[0].name;
}
const dz=document.getElementById('dropZone');
if(dz){
    dz.addEventListener('dragover',e=>{e.preventDefault();dz.classList.add('over')});
    dz.addEventListener('dragleave',()=>dz.classList.remove('over'));
    dz.addEventListener('drop',e=>{
        e.preventDefault();dz.classList.remove('over');
        const f=e.dataTransfer.files[0];
        if(f){document.getElementById('csvFile').files=e.dataTransfer.files;fileChosen(document.getElementById('csvFile'));}
    });
}
</script>
<?php include 'includes/footer.php'; ?>
