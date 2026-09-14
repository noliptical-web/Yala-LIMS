<?php
// inventory.php
// Laboratory Reagents, Consumables & Stock Management Console
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$role = $_SESSION['role'] ?? '';
$my_user = $_SESSION['full_name'] ?? 'Staff';
$isAdmin = ($role === 'Admin');
$isLabTech = ($role === 'LabTech' || $role === 'Lab Technician' || $role === 'LabTechnician');

// Strict RBAC: Lab Technologists and Administrators ONLY
if (!$isAdmin && !$isLabTech) {
    header("location: dashboard.php"); exit;
}

$success = $error = "";

// ── ACTION: ADD NEW INVENTORY ITEM ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    csrf_verify();
    $name     = trim($_POST['item_name'] ?? '');
    $cat      = trim($_POST['category'] ?? '');
    $lot      = trim($_POST['batch_number'] ?? '');
    $exp      = trim($_POST['expiry_date'] ?? '');
    $qty      = intval($_POST['quantity_on_hand'] ?? 0);
    $unit     = trim($_POST['unit_of_measure'] ?? 'Units');
    $reorder  = intval($_POST['reorder_level'] ?? 10);
    $loc      = trim($_POST['storage_location'] ?? 'Main Lab');

    if (empty($name) || empty($cat) || empty($lot) || empty($exp)) {
        $error = "Please fill in all required item details.";
    } else {
        $status = 'In Stock';
        if ($qty <= $reorder) $status = 'Low Stock';
        if (strtotime($exp) < time()) $status = 'Expired';

        $stmt = $conn->prepare("INSERT INTO lab_inventory (item_name, category, batch_number, expiry_date, quantity_on_hand, unit_of_measure, reorder_level, storage_location, status) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("ssssisiss", $name, $cat, $lot, $exp, $qty, $unit, $reorder, $loc, $status);
        if ($stmt->execute()) {
            audit_log($conn, 'add_inventory', 'lab_inventory', $conn->insert_id, "Added $name (Lot $lot, Qty $qty)");
            $success = "Inventory item '$name' added successfully.";
        } else {
            $error = "Error adding item: " . $conn->error;
        }
        $stmt->close();
    }
}

// ── ACTION: ADJUST STOCK (DISPENSE OR RESTOCK) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_stock'])) {
    csrf_verify();
    $itemId = intval($_POST['item_id'] ?? 0);
    $delta  = intval($_POST['adjustment_qty'] ?? 0); // positive for restock, negative for dispense
    $action_type = $_POST['action_type'] ?? 'dispense'; // 'dispense' or 'restock'

    if ($action_type === 'dispense') {
        $delta = -abs($delta);
    } else {
        $delta = abs($delta);
    }

    if ($itemId > 0 && $delta != 0) {
        $stmt = $conn->prepare("SELECT quantity_on_hand, reorder_level, expiry_date, item_name FROM lab_inventory WHERE item_id = ?");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($item) {
            $newQty = max(0, $item['quantity_on_hand'] + $delta);
            $newStatus = 'In Stock';
            if ($newQty <= $item['reorder_level']) $newStatus = 'Low Stock';
            if (strtotime($item['expiry_date']) < time()) $newStatus = 'Expired';

            $upd = $conn->prepare("UPDATE lab_inventory SET quantity_on_hand = ?, status = ? WHERE item_id = ?");
            $upd->bind_param("isi", $newQty, $newStatus, $itemId);
            if ($upd->execute()) {
                $act_label = $delta > 0 ? "Restocked +$delta" : "Dispensed $delta";
                audit_log($conn, 'adjust_inventory', 'lab_inventory', $itemId, "$act_label for {$item['item_name']} (New Qty: $newQty)");
                $success = "Stock updated for {$item['item_name']}. New balance: $newQty";
            } else {
                $error = "Update failed: " . $conn->error;
            }
            $upd->close();
        }
    }
}

// ── KPI METRICS ──────────────────────────────────────────────────────────────
$total_items  = $conn->query("SELECT COUNT(*) FROM lab_inventory")->fetch_row()[0] ?? 0;
$low_stock    = $conn->query("SELECT COUNT(*) FROM lab_inventory WHERE quantity_on_hand <= reorder_level AND expiry_date >= CURDATE()")->fetch_row()[0] ?? 0;
$expired_cnt  = $conn->query("SELECT COUNT(*) FROM lab_inventory WHERE expiry_date < CURDATE()")->fetch_row()[0] ?? 0;
$expiring_soon= $conn->query("SELECT COUNT(*) FROM lab_inventory WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetch_row()[0] ?? 0;

// Filter criteria
$cat_filter = $_GET['cat'] ?? '';
$status_filter = $_GET['status'] ?? '';

$where = "WHERE 1=1";
$params = [];
$types = "";

if (!empty($cat_filter)) {
    $where .= " AND category = ?";
    $params[] = $cat_filter;
    $types .= "s";
}
if ($status_filter === 'low') {
    $where .= " AND quantity_on_hand <= reorder_level";
} elseif ($status_filter === 'expired') {
    $where .= " AND expiry_date < CURDATE()";
} elseif ($status_filter === 'soon') {
    $where .= " AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
}

$query = "SELECT * FROM lab_inventory $where ORDER BY (expiry_date < CURDATE()) DESC, (quantity_on_hand <= reorder_level) DESC, item_name ASC";
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$categories = ['Stains & Dyes', 'Rapid Test Kits', 'Collection Tubes', 'Chemistry Reagents', 'Blood Banking', 'Serology', 'Consumables'];

$page_title = "Laboratory Reagent & Inventory Management";
include 'includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');
body { font-family: 'DM Sans', sans-serif; }
.card-kpi { border-radius: 12px; padding: 18px 20px; color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
.table-inv thead th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.8px; color: #64748b; font-weight: 700; }
.badge-stock { font-size: 0.72rem; font-weight: 700; border-radius: 20px; padding: 3px 10px; }
.badge-instock { background: #dcfce7; color: #15803d; }
.badge-lowstock { background: #fef3c7; color: #b45309; }
.badge-expired { background: #fee2e2; color: #dc2626; }
</style>

<div class="container py-3">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1"><i class="fa-solid fa-boxes-stacked text-primary me-2"></i>Lab Reagent & Consumables Inventory</h2>
            <p class="text-muted mb-0" style="font-size:0.88rem;">Track laboratory test reagents, lot numbers, expiry dates, and automated stock reorder levels</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="fa-solid fa-plus me-1"></i> Add Reagent / Stock
            </button>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card-kpi" style="background:linear-gradient(135deg,#1d4ed8,#3b82f6)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:1.8rem;font-weight:800;"><?= $total_items ?></div>
                        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;opacity:0.9">Total Stock Items</div>
                    </div>
                    <i class="fa-solid fa-box fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card-kpi" style="background:linear-gradient(135deg,#d97706,#f59e0b)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:1.8rem;font-weight:800;"><?= $low_stock ?></div>
                        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;opacity:0.9">Low Stock Alert</div>
                    </div>
                    <i class="fa-solid fa-bell fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card-kpi" style="background:linear-gradient(135deg,#dc2626,#ef4444)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:1.8rem;font-weight:800;"><?= $expired_cnt ?></div>
                        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;opacity:0.9">Expired Items</div>
                    </div>
                    <i class="fa-solid fa-ban fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card-kpi" style="background:linear-gradient(135deg,#0d9488,#14b8a6)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:1.8rem;font-weight:800;"><?= $expiring_soon ?></div>
                        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;opacity:0.9">Expiring in 30 Days</div>
                    </div>
                    <i class="fa-solid fa-clock-rotate-left fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & Table Card -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-white py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <span class="text-muted fw-bold small text-uppercase me-2">Filters:</span>
                <a href="inventory.php" class="btn btn-sm <?= empty($cat_filter)&&empty($status_filter)?'btn-primary':'btn-light' ?> rounded-pill px-3">All Items</a>
                <a href="inventory.php?status=low" class="btn btn-sm <?= $status_filter==='low'?'btn-warning':'btn-light' ?> rounded-pill px-3">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> Low Stock (<?= $low_stock ?>)
                </a>
                <a href="inventory.php?status=soon" class="btn btn-sm <?= $status_filter==='soon'?'btn-info text-white':'btn-light' ?> rounded-pill px-3">
                    <i class="fa-solid fa-hourglass-half me-1"></i> Expiring Soon (<?= $expiring_soon ?>)
                </a>
                <a href="inventory.php?status=expired" class="btn btn-sm <?= $status_filter==='expired'?'btn-danger':'btn-light' ?> rounded-pill px-3">
                    <i class="fa-solid fa-ban me-1"></i> Expired (<?= $expired_cnt ?>)
                </a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle table-inv mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Item Name / Category</th>
                        <th>Batch / Lot No</th>
                        <th>Storage Location</th>
                        <th>Expiry Date</th>
                        <th>Balance (Qty)</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Quick Adjust</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">No inventory items found matching filter criteria.</td></tr>
                    <?php else: foreach ($items as $it): 
                        $is_exp = strtotime($it['expiry_date']) < time();
                        $is_low = $it['quantity_on_hand'] <= $it['reorder_level'];
                        $days_to_exp = round((strtotime($it['expiry_date']) - time()) / 86400);
                    ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold text-dark"><?= htmlspecialchars($it['item_name']) ?></div>
                            <span class="badge bg-light text-secondary border" style="font-size:0.7rem;"><?= htmlspecialchars($it['category']) ?></span>
                        </td>
                        <td><span class="font-monospace fw-bold text-muted" style="font-size:0.8rem;"><?= htmlspecialchars($it['batch_number']) ?></span></td>
                        <td><span class="text-muted small"><i class="fa-solid fa-location-dot me-1 text-secondary"></i><?= htmlspecialchars($it['storage_location']) ?></span></td>
                        <td>
                            <div class="<?= $is_exp?'text-danger fw-bold':($days_to_exp<=30?'text-warning fw-bold':'text-dark') ?>" style="font-size:0.85rem;">
                                <?= date('d M Y', strtotime($it['expiry_date'])) ?>
                            </div>
                            <?php if ($is_exp): ?>
                                <small class="text-danger fw-bold" style="font-size:0.68rem;">EXPIRED</small>
                            <?php elseif ($days_to_exp <= 30): ?>
                                <small class="text-warning fw-bold" style="font-size:0.68rem;"><?= $days_to_exp ?> days left</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-bold <?= $is_low?'text-danger':'text-dark' ?>" style="font-size:0.95rem;">
                                <?= $it['quantity_on_hand'] ?> <small class="text-muted fw-normal" style="font-size:0.75rem;"><?= htmlspecialchars($it['unit_of_measure']) ?></small>
                            </div>
                            <small class="text-muted" style="font-size:0.68rem;">Reorder at &le; <?= $it['reorder_level'] ?></small>
                        </td>
                        <td>
                            <?php if ($is_exp): ?>
                                <span class="badge-stock badge-expired"><i class="fa-solid fa-ban me-1"></i>Expired</span>
                            <?php elseif ($is_low): ?>
                                <span class="badge-stock badge-lowstock"><i class="fa-solid fa-triangle-exclamation me-1"></i>Low Stock</span>
                            <?php else: ?>
                                <span class="badge-stock badge-instock"><i class="fa-solid fa-check me-1"></i>In Stock</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <!-- Quick Dispense Form -->
                            <form method="post" class="d-inline-flex gap-1 align-items-center">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="adjust_stock" value="1">
                                <input type="hidden" name="item_id" value="<?= $it['item_id'] ?>">
                                <input type="hidden" name="action_type" value="dispense">
                                <input type="hidden" name="adjustment_qty" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-1" title="Dispense 1 Unit" <?= $it['quantity_on_hand']<=0?'disabled':'' ?>>
                                    <i class="fa-solid fa-minus"></i> Dispense
                                </button>
                            </form>
                            <!-- Quick Restock Form -->
                            <form method="post" class="d-inline-flex gap-1 align-items-center">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="adjust_stock" value="1">
                                <input type="hidden" name="item_id" value="<?= $it['item_id'] ?>">
                                <input type="hidden" name="action_type" value="restock">
                                <input type="hidden" name="adjustment_qty" value="10">
                                <button type="submit" class="btn btn-sm btn-outline-success px-2 py-1" title="Restock +10">
                                    <i class="fa-solid fa-plus"></i> +10
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Add New Reagent / Item -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i>Add Reagent or Consumable</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="add_item" value="1">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Item Name</label>
                        <input type="text" name="item_name" class="form-control" required placeholder="e.g. Giemsa Stain 500ml or mRDT Strips">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Category</label>
                            <select name="category" class="form-select" required>
                                <option value="">Select Category…</option>
                                <?php foreach ($categories as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Lot / Batch No</label>
                            <input type="text" name="batch_number" class="form-control" required placeholder="e.g. LOT-2026-X1">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Initial Quantity</label>
                            <input type="number" name="quantity_on_hand" class="form-control" min="0" value="50" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Unit of Measure</label>
                            <input type="text" name="unit_of_measure" class="form-control" value="Tests" required placeholder="e.g. Tests, Bottles, Tubes">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Reorder Threshold</label>
                            <input type="number" name="reorder_level" class="form-control" min="1" value="15" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Expiry Date</label>
                            <input type="date" name="expiry_date" class="form-control" required value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Storage Location</label>
                        <input type="text" name="storage_location" class="form-control" value="Main Lab Shelf" placeholder="e.g. Reagent Fridge 2-8°C, Chemical Cabinet">
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
