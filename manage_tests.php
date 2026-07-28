<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'Admin') {
    header("location: dashboard.php");
    exit;
}

$success = $error = "";
$myId = intval($_SESSION['id'] ?? 0);

// Check if insurance columns exist in lab_tests
$hasIns = false;
$chk = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lab_tests'
    AND COLUMN_NAME='insurance_covered' LIMIT 1");
if ($chk && $chk->num_rows > 0) $hasIns = true;

// ─── ADD TEST ─────────────────────────────────────────────────────────────────
if (isset($_POST['add_test'])) {
    csrf_verify();
    $name        = trim($_POST['test_name']          ?? '');
    $cat         = trim($_POST['category']           ?? '');
    $cost        = floatval($_POST['cost']           ?? 0);
    $turnaround  = intval($_POST['turnaround_hours'] ?? 24);
    $ins_covered = isset($_POST['insurance_covered']) ? 1 : 0;
    $ins_pct     = intval($_POST['insurance_cover_pct']  ?? 100);
    $ins_cap     = floatval($_POST['insurance_cap_kes']  ?? 0);

    if (!$name || !$cat || $cost <= 0) {
        $error = "Test name, category, and a valid cost are required.";
    } else {
        if ($hasIns) {
            $stmt = $conn->prepare("INSERT INTO lab_tests
                (test_name, test_category, cost, turnaround_hours, insurance_covered, insurance_cover_pct, insurance_cap_kes)
                VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param("ssdiiid", $name, $cat, $cost, $turnaround, $ins_covered, $ins_pct, $ins_cap);
        } else {
            $stmt = $conn->prepare("INSERT INTO lab_tests (test_name, test_category, cost, turnaround_hours) VALUES (?,?,?,?)");
            $stmt->bind_param("ssdi", $name, $cat, $cost, $turnaround);
        }
        if ($stmt->execute()) {
            audit_log($conn, 'add_test', 'lab_tests', $conn->insert_id, "Added: $name KES $cost");
            $success = "Test '$name' added successfully.";
        } else {
            $error = "Failed to add test: " . $conn->error;
        }
        $stmt->close();
    }
}

// ─── EDIT TEST ────────────────────────────────────────────────────────────────
if (isset($_POST['edit_test'])) {
    csrf_verify();
    $id          = intval($_POST['test_id']);
    $name        = trim($_POST['test_name']          ?? '');
    $cat         = trim($_POST['category']           ?? '');
    $cost        = floatval($_POST['cost']           ?? 0);
    $turnaround  = intval($_POST['turnaround_hours'] ?? 24);
    $ins_covered = isset($_POST['insurance_covered']) ? 1 : 0;
    $ins_pct     = intval($_POST['insurance_cover_pct']  ?? 100);
    $ins_cap     = floatval($_POST['insurance_cap_kes']  ?? 0);

    if ($id <= 0 || !$name || !$cat || $cost <= 0) {
        $error = "All fields are required.";
    } else {
        if ($hasIns) {
            $stmt = $conn->prepare("UPDATE lab_tests
                SET test_name=?, test_category=?, cost=?, turnaround_hours=?,
                    insurance_covered=?, insurance_cover_pct=?, insurance_cap_kes=?
                WHERE test_id=?");
            $stmt->bind_param("ssdiiidi", $name, $cat, $cost, $turnaround, $ins_covered, $ins_pct, $ins_cap, $id);
        } else {
            $stmt = $conn->prepare("UPDATE lab_tests SET test_name=?, test_category=?, cost=?, turnaround_hours=? WHERE test_id=?");
            $stmt->bind_param("ssdii", $name, $cat, $cost, $turnaround, $id);
        }
        if ($stmt->execute()) {
            audit_log($conn, 'edit_test', 'lab_tests', $id, "Edited: $name");
            $success = "Test updated.";
        } else {
            $error = "Update failed: " . $conn->error;
        }
        $stmt->close();
    }
}

// ─── DELETE TEST ──────────────────────────────────────────────────────────────
if (isset($_POST['delete_test'])) {
    csrf_verify();
    $id = intval($_POST['test_id']);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM lab_tests WHERE test_id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            audit_log($conn, 'delete_test', 'lab_tests', $id, "Deleted test ID $id");
            $success = "Test deleted.";
        } else {
            $error = "Delete failed.";
        }
        $stmt->close();
    }
}

// ─── FETCH TESTS ──────────────────────────────────────────────────────────────
if ($hasIns) {
    $res = $conn->query("SELECT test_id, test_name, test_category AS category, cost, turnaround_hours,
        insurance_covered, insurance_cover_pct, insurance_cap_kes
        FROM lab_tests ORDER BY test_category, test_name");
} else {
    $res = $conn->query("SELECT test_id, test_name, test_category AS category, cost, turnaround_hours,
        0 AS insurance_covered, 100 AS insurance_cover_pct, 0 AS insurance_cap_kes
        FROM lab_tests ORDER BY test_category, test_name");
}
$tests = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$grouped = [];
foreach ($tests as $t) $grouped[$t['category']][] = $t;

$csrf = csrf_token();
$page_title = 'Test Catalogue — Yala LIMS';
include 'includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0"><i class="fa-solid fa-flask me-2 text-primary"></i>Test Catalogue</h4>
        <small class="text-muted"><?= count($tests) ?> tests configured</small>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fa-solid fa-plus me-1"></i>Add Test
    </button>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (empty($tests)): ?>
<div class="glass-card p-5 text-center text-muted">
    <i class="fa-solid fa-flask fa-3x mb-3 opacity-25"></i>
    <h5>No tests configured yet</h5>
    <p class="small">Click "Add Test" to add your first lab test.</p>
</div>
<?php else: ?>

<?php foreach ($grouped as $cat => $items): ?>
<div class="glass-card mb-4">
    <div class="px-4 py-2 border-bottom" style="background:#f8fafc;border-radius:15px 15px 0 0">
        <span class="text-uppercase fw-bold text-muted" style="font-size:.72rem;letter-spacing:.08em">
            <?= htmlspecialchars($cat) ?>
        </span>
        <span class="badge bg-secondary ms-2"><?= count($items) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Test Name</th>
                    <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Cost (KES)</th>
                    <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Turnaround</th>
                    <?php if ($hasIns): ?>
                    <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Insurance</th>
                    <?php endif; ?>
                    <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $t): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($t['test_name']) ?></td>
                    <td class="fw-bold text-danger"><?= number_format($t['cost'], 2) ?></td>
                    <td><small><?= $t['turnaround_hours'] ?>h</small></td>
                    <?php if ($hasIns): ?>
                    <td>
                        <?php if (!$t['insurance_covered']): ?>
                            <span class="badge bg-danger-subtle text-danger">Excluded</span>
                        <?php elseif ($t['insurance_cover_pct'] >= 100): ?>
                            <span class="badge bg-success-subtle text-success">Full Cover</span>
                        <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning"><?= $t['insurance_cover_pct'] ?>% covered</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td>
                        <div class="d-flex gap-1">
                            <button class="btn btn-outline-primary btn-sm"
                                onclick="openEdit(<?= htmlspecialchars(json_encode($t)) ?>)">
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <form method="POST" style="display:inline"
                                onsubmit="return confirm('Delete this test?')">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="test_id" value="<?= $t['test_id'] ?>">
                                <button name="delete_test" class="btn btn-outline-danger btn-sm">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ADD MODAL -->
<div class="modal fade" id="addModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content border-0 shadow">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">Add New Test</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST">
    <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label small fw-semibold">Test Name</label>
                <input name="test_name" class="form-control" placeholder="e.g. Full Blood Count" required>
            </div>
            <div class="col-6">
                <label class="form-label small fw-semibold">Category</label>
                <input name="category" class="form-control" placeholder="e.g. Haematology" required>
            </div>
            <div class="col-6">
                <label class="form-label small fw-semibold">Cost (KES)</label>
                <input name="cost" type="number" step="0.01" min="1" class="form-control" placeholder="0.00" required>
            </div>
            <div class="col-12">
                <label class="form-label small fw-semibold">Turnaround (hours)</label>
                <input name="turnaround_hours" type="number" min="1" value="24" class="form-control">
            </div>
            <?php if ($hasIns): ?>
            <div class="col-12">
                <div class="form-check">
                    <input type="checkbox" name="insurance_covered" id="addInsCov" value="1"
                        class="form-check-input" checked
                        onchange="document.getElementById('addInsExtra').style.display=this.checked?'block':'none'">
                    <label class="form-check-label small fw-semibold" for="addInsCov">Insurance covered</label>
                </div>
                <div id="addInsExtra" class="row g-2 mt-1">
                    <div class="col-6">
                        <label class="form-label small">Insurer pays (%)</label>
                        <input name="insurance_cover_pct" type="number" min="0" max="100" value="100" class="form-control form-control-sm">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Cap (KES, 0=none)</label>
                        <input name="insurance_cap_kes" type="number" step="0.01" min="0" value="0" class="form-control form-control-sm">
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="modal-footer border-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button name="add_test" class="btn btn-primary">Add Test</button>
    </div>
    </form>
</div></div></div>

<!-- EDIT MODAL -->
<div class="modal fade" id="editModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content border-0 shadow">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">Edit Test</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST">
    <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="test_id" id="eId">
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label small fw-semibold">Test Name</label>
                <input name="test_name" id="eName" class="form-control" required>
            </div>
            <div class="col-6">
                <label class="form-label small fw-semibold">Category</label>
                <input name="category" id="eCat" class="form-control" required>
            </div>
            <div class="col-6">
                <label class="form-label small fw-semibold">Cost (KES)</label>
                <input name="cost" id="eCost" type="number" step="0.01" min="1" class="form-control" required>
            </div>
            <div class="col-12">
                <label class="form-label small fw-semibold">Turnaround (hours)</label>
                <input name="turnaround_hours" id="eTa" type="number" min="1" class="form-control">
            </div>
            <?php if ($hasIns): ?>
            <div class="col-12">
                <div class="form-check">
                    <input type="checkbox" name="insurance_covered" id="eInsCov" value="1"
                        class="form-check-input"
                        onchange="document.getElementById('eInsExtra').style.display=this.checked?'block':'none'">
                    <label class="form-check-label small fw-semibold" for="eInsCov">Insurance covered</label>
                </div>
                <div id="eInsExtra" class="row g-2 mt-1">
                    <div class="col-6">
                        <label class="form-label small">Insurer pays (%)</label>
                        <input name="insurance_cover_pct" id="eInsPct" type="number" min="0" max="100" class="form-control form-control-sm">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Cap (KES, 0=none)</label>
                        <input name="insurance_cap_kes" id="eInsCap" type="number" step="0.01" min="0" class="form-control form-control-sm">
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="modal-footer border-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button name="edit_test" class="btn btn-primary">Save Changes</button>
    </div>
    </form>
</div></div></div>

<script>
function openEdit(t) {
    document.getElementById('eId').value    = t.test_id;
    document.getElementById('eName').value  = t.test_name;
    document.getElementById('eCat').value   = t.category;
    document.getElementById('eCost').value  = t.cost;
    document.getElementById('eTa').value    = t.turnaround_hours || 24;
    const cov = document.getElementById('eInsCov');
    if (cov) {
        cov.checked = !!parseInt(t.insurance_covered);
        document.getElementById('eInsExtra').style.display = cov.checked ? 'block' : 'none';
        document.getElementById('eInsPct').value = t.insurance_cover_pct || 100;
        document.getElementById('eInsCap').value = t.insurance_cap_kes   || 0;
    }
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>

<?php include 'includes/footer.php'; ?>