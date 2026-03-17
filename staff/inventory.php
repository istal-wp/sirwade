<?php
/**
 * staff/inventory.php
 * Staff — Inventory Management (CRUD)
 * All write operations live here. Admin has read-only view.
 */
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';
require_once '../includes/activity_log.php';
require_role('staff');

// ── POST HANDLERS ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $actor  = $_SESSION['user_name'] ?? 'staff';

    try {
        switch ($action) {

            case 'add_item':
                $stmt = db()->prepare("INSERT INTO inventory_items
                    (item_code, item_name, category, quantity, unit_price, supplier, location,
                     reorder_level, description, status, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    trim($_POST['item_code']), trim($_POST['item_name']),
                    trim($_POST['category']),  (int)$_POST['quantity'],
                    (float)$_POST['unit_price'], trim($_POST['supplier'] ?? ''),
                    trim($_POST['location']),  (int)($_POST['reorder_level'] ?? 10),
                    trim($_POST['description'] ?? ''), $_POST['status'] ?? 'active',
                    $actor
                ]);
                $new_id = (int)db()->lastInsertId();
                // Initial stock movement
                if ((int)$_POST['quantity'] > 0) {
                    db()->prepare("INSERT INTO stock_movements
                        (item_id, movement_type, quantity, reason, performed_by, unit_cost, total_cost)
                        VALUES (?, 'IN', ?, 'Initial stock', ?, ?, ?)")
                    ->execute([$new_id, (int)$_POST['quantity'], $actor,
                               (float)$_POST['unit_price'],
                               (int)$_POST['quantity'] * (float)$_POST['unit_price']]);
                }
                log_activity('add_inventory_item', 'inventory_items', $new_id, $_POST['item_name']);
                redirect_with_flash('inventory.php', 'success', "Item '{$_POST['item_name']}' added successfully.");

            case 'update_item':
                db()->prepare("UPDATE inventory_items
                    SET item_name=?, category=?, unit_price=?, supplier=?, location=?,
                        reorder_level=?, description=?, status=?
                    WHERE id=?")
                ->execute([
                    trim($_POST['item_name']), trim($_POST['category']),
                    (float)$_POST['unit_price'], trim($_POST['supplier'] ?? ''),
                    trim($_POST['location']),  (int)$_POST['reorder_level'],
                    trim($_POST['description'] ?? ''), $_POST['status'],
                    (int)$_POST['item_id']
                ]);
                log_activity('update_inventory_item', 'inventory_items', (int)$_POST['item_id'], $_POST['item_name']);
                redirect_with_flash('inventory.php', 'success', "Item updated successfully.");

            case 'stock_movement':
                $item = db_one("SELECT * FROM inventory_items WHERE id=?", [(int)$_POST['item_id']]);
                if (!$item) redirect_with_flash('inventory.php', 'error', 'Item not found.');
                $qty   = (int)$_POST['quantity'];
                $mtype = strtoupper($_POST['movement_type']);
                $new_qty = ($mtype === 'IN') ? $item['quantity'] + $qty : $item['quantity'] - $qty;
                if ($new_qty < 0) redirect_with_flash('inventory.php', 'error', 'Insufficient stock for OUT movement.');
                db()->prepare("UPDATE inventory_items SET quantity=? WHERE id=?")
                     ->execute([$new_qty, $item['id']]);
                db()->prepare("INSERT INTO stock_movements
                    (item_id, movement_type, quantity, reason, reference_number,
                     from_location, to_location, unit_cost, total_cost, performed_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $item['id'], $mtype, $qty,
                    trim($_POST['reason'] ?? ''), trim($_POST['reference_number'] ?? ''),
                    trim($_POST['from_location'] ?? ''), trim($_POST['to_location'] ?? ''),
                    $item['unit_price'], $qty * $item['unit_price'], $actor
                ]);
                log_activity("stock_{$mtype}", 'stock_movements', $item['id'],
                    "qty={$qty}, item={$item['item_name']}");
                redirect_with_flash('inventory.php', 'success', "Stock movement recorded. New qty: {$new_qty}");

            case 'delete_item':
                db()->prepare("UPDATE inventory_items SET status='inactive' WHERE id=?")
                     ->execute([(int)$_POST['item_id']]);
                log_activity('deactivate_inventory_item', 'inventory_items', (int)$_POST['item_id'], '');
                redirect_with_flash('inventory.php', 'success', "Item deactivated.");
        }
    } catch (Throwable $e) {
        error_log('Staff inventory error: ' . $e->getMessage());
        redirect_with_flash('inventory.php', 'error', 'Operation failed. Please try again.');
    }
}

// ── FETCH DATA ────────────────────────────────────────────────────
$search   = trim($_GET['search']   ?? '');
$category = trim($_GET['category'] ?? '');
$location = trim($_GET['location'] ?? '');
$status   = $_GET['status'] ?? 'active';

$where = ['1=1']; $params = [];
if ($search)   { $where[] = "(item_name LIKE ? OR item_code LIKE ? OR description LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($category) { $where[] = "category=?"; $params[] = $category; }
if ($location) { $where[] = "location=?"; $params[] = $location; }
if ($status)   { $where[] = "status=?";   $params[] = $status; }

$total_count = (int)db_scalar("SELECT COUNT(*) FROM inventory_items WHERE " . implode(' AND ', $where), $params);
$pg = paginate($total_count, 25);
$inventory_items = db_all("SELECT * FROM inventory_items WHERE " . implode(' AND ', $where) . " ORDER BY item_name LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);

// Stats
$stats = [
    'total_items'  => db_scalar("SELECT COUNT(*) FROM inventory_items WHERE status='active'"),
    'total_value'  => db_scalar("SELECT COALESCE(SUM(quantity*unit_price),0) FROM inventory_items WHERE status='active'"),
    'low_stock'    => db_scalar("SELECT COUNT(*) FROM inventory_items WHERE quantity<=reorder_level AND status='active'"),
    'out_of_stock' => db_scalar("SELECT COUNT(*) FROM inventory_items WHERE quantity=0 AND status='active'"),
    'categories'   => db_scalar("SELECT COUNT(DISTINCT category) FROM inventory_items WHERE status='active'"),
    'locations'    => db_scalar("SELECT COUNT(DISTINCT location) FROM inventory_items WHERE status='active' AND location!=''"),
];

// Dynamic dropdowns (no hard-coding)
$categories = db_all("SELECT DISTINCT category FROM inventory_items WHERE category!='' ORDER BY category");
$categories = array_column($categories, 'category');
$locations  = db_all("SELECT DISTINCT location FROM inventory_items WHERE location!='' ORDER BY location");
$locations  = array_column($locations, 'location');
$suppliers  = db_all("SELECT DISTINCT supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name");
$suppliers  = array_column($suppliers, 'supplier_name');

// Recent movements (dynamic)
$recent_movements = db_all("SELECT sm.*, ii.item_name, ii.item_code
    FROM stock_movements sm
    JOIN inventory_items ii ON sm.item_id=ii.id
    ORDER BY sm.created_at DESC LIMIT 8");

$page_title = 'Inventory Management';
$page_sub   = 'Inventory Management';
$back_url   = 'dashboard.php';
require_once '../includes/staff/header.php';
?>
<main class="main">

<div class="page-title">
    <h1>Inventory Management</h1>
    <p>Add, edit, and manage all inventory items and stock movements</p>
</div>

<?php echo render_flash(); ?>

<?php if ($stats['low_stock'] > 0): ?>
<div class="alert alert-warn">
    <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <strong>Low Stock Alert:</strong>&nbsp;<?php echo (int)$stats['low_stock']; ?> items are running low and need restocking.
</div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Total Items</span><div class="stat-badge"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg></div></div>
        <div class="stat-value"><?php echo number_format($stats['total_items']); ?></div>
        <div class="stat-sub good">Active inventory</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Total Value</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div></div>
        <div class="stat-value" style="font-size:1.4rem"><?php echo peso((float)$stats['total_value']); ?></div>
        <div class="stat-sub good">Inventory worth</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Low Stock</span><div class="stat-badge warn-badge"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div></div>
        <div class="stat-value"><?php echo number_format($stats['low_stock']); ?></div>
        <div class="stat-sub warn">Need reordering</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Out of Stock</span><div class="stat-badge error-badge"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></div></div>
        <div class="stat-value"><?php echo number_format($stats['out_of_stock']); ?></div>
        <div class="stat-sub error">Zero quantity</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Categories</span><div class="stat-badge"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg></div></div>
        <div class="stat-value"><?php echo number_format($stats['categories']); ?></div>
        <div class="stat-sub">Item groups</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Locations</span><div class="stat-badge"><svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg></div></div>
        <div class="stat-value"><?php echo number_format($stats['locations']); ?></div>
        <div class="stat-sub">Storage sites</div>
    </div>
</div>

<!-- Controls -->
<div class="panel">
    <div class="controls-bar">
        <h2>Inventory Control</h2>
        <div class="btn-row">
            <button onclick="openModal('addItemModal')" class="btn btn-primary">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add New Item
            </button>
            <button onclick="openModal('stockMovementModal')" class="btn btn-success">
                <svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                Stock Movement
            </button>
        </div>
    </div>
    <form method="GET" class="search-grid">
        <div class="field"><label>Search Items</label><input type="text" name="search" class="form-control" placeholder="Name, code…" value="<?php echo h($search); ?>"></div>
        <div class="field"><label>Category</label>
            <select name="category" class="form-control">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?><option value="<?php echo h($c); ?>"<?php echo $category===$c?' selected':''; ?>><?php echo h($c); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>Location</label>
            <select name="location" class="form-control">
                <option value="">All Locations</option>
                <?php foreach ($locations as $l): ?><option value="<?php echo h($l); ?>"<?php echo $location===$l?' selected':''; ?>><?php echo h($l); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>Status</label>
            <select name="status" class="form-control">
                <option value="active"<?php echo $status==='active'?' selected':''; ?>>Active</option>
                <option value="inactive"<?php echo $status==='inactive'?' selected':''; ?>>Inactive</option>
                <option value=""<?php echo $status===''?' selected':''; ?>>All</option>
            </select>
        </div>
        <div class="field" style="align-self:flex-end"><button type="submit" class="btn btn-primary" style="width:100%">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Filter</button></div>
    </form>
</div>

<!-- Inventory Table -->
<div class="panel" style="padding:0;overflow:hidden">
    <table class="data-table">
        <thead><tr>
            <th>Item Code</th><th>Item Name</th><th>Category</th><th>Qty</th>
            <th>Unit Price</th><th>Total Value</th><th>Location</th>
            <th>Status</th><th>Stock Level</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php if (empty($inventory_items)): ?>
        <tr><td colspan="10"><div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
            <h3>No inventory items found</h3><p>Adjust filters or add a new item.</p>
        </div></td></tr>
        <?php else: ?>
        <?php foreach ($inventory_items as $item): ?>
        <tr>
            <td><strong style="font-family:'DM Mono',monospace;font-size:.82rem"><?php echo h($item['item_code']); ?></strong></td>
            <td><?php echo h($item['item_name']); ?></td>
            <td><?php echo h($item['category']); ?></td>
            <td><strong><?php echo number_format((int)$item['quantity']); ?></strong></td>
            <td><?php echo peso((float)$item['unit_price']); ?></td>
            <td><strong><?php echo peso($item['quantity'] * $item['unit_price']); ?></strong></td>
            <td><?php echo h($item['location']); ?></td>
            <td><?php echo render_badge($item['status']); ?></td>
            <td>
                <?php if ((int)$item['quantity'] === 0): ?>
                    <span class="badge badge-error">Out of Stock</span>
                <?php elseif ((int)$item['quantity'] <= (int)$item['reorder_level']): ?>
                    <span class="badge badge-warn">Low Stock</span>
                <?php else: ?>
                    <span class="badge badge-active">In Stock</span>
                <?php endif; ?>
            </td>
            <td>
                <div style="display:flex;gap:.4rem">
                    <button onclick="editItem(<?php echo h(json_encode($item)); ?>)" class="btn btn-warning btn-sm" title="Edit">
                        <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button onclick="openStockModal(<?php echo (int)$item['id']; ?>, '<?php echo h($item['item_name']); ?>')" class="btn btn-success btn-sm" title="Stock Movement">
                        <svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    </button>
                    <button onclick="deleteItem(<?php echo (int)$item['id']; ?>, '<?php echo h($item['item_name']); ?>')" class="btn btn-danger btn-sm" title="Deactivate">
                        <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                    </button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <?php if ($pg['total_pages'] > 1): ?>
    <div style="padding:1rem 1.5rem;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:.82rem;color:var(--muted)">
        <span>Showing <?php echo ($pg['offset']+1); ?>–<?php echo min($pg['offset']+$pg['limit'], $pg['total']); ?> of <?php echo $pg['total']; ?> items</span>
        <div style="display:flex;gap:.4rem">
            <?php for ($p=1; $p<=$pg['total_pages']; $p++): ?>
            <a href="?page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category); ?>&location=<?php echo urlencode($location); ?>&status=<?php echo urlencode($status); ?>"
               style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:7px;font-size:.82rem;font-weight:500;text-decoration:none;border:1.5px solid var(--border);color:var(--text);background:<?php echo $p===$pg['page']?'var(--navy)':'var(--white)'; ?>;color:<?php echo $p===$pg['page']?'#fff':'var(--text)'; ?>">
                <?php echo $p; ?>
            </a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Recent Movements -->
<div class="panel">
    <div class="panel-head">
        <h2><svg viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>Recent Stock Movements</h2>
    </div>
    <?php if (!empty($recent_movements)): ?>
    <?php foreach ($recent_movements as $m): ?>
    <div class="feed-row">
        <div class="feed-icon">
            <svg viewBox="0 0 24 24"><?php echo $m['movement_type']==='IN' ? '<polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>' : '<polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>'; ?></svg>
        </div>
        <div class="feed-body">
            <h4><?php echo h($m['movement_type']); ?>: <?php echo h($m['item_name']); ?> (<?php echo h($m['item_code']); ?>)</h4>
            <p>Qty: <?php echo number_format((int)$m['quantity']); ?> &nbsp;|&nbsp; <?php echo h($m['reason'] ?: 'N/A'); ?> &nbsp;|&nbsp; <?php echo h($m['performed_by']); ?></p>
        </div>
        <div class="feed-time"><?php echo fmt_date($m['created_at'], 'M j, Y g:i A'); ?></div>
    </div>
    <?php endforeach; ?>
    <?php else: ?><div class="no-data">No recent stock movements.</div>
    <?php endif; ?>
</div>

</main>

<!-- ADD ITEM MODAL -->
<div id="addItemModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Add New Inventory Item</h3><button class="modal-close" onclick="closeModal('addItemModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="add_item">
        <div class="form-grid-2">
          <div class="form-field"><label>Item Code *</label><input type="text" name="item_code" class="form-control" required></div>
          <div class="form-field"><label>Item Name *</label><input type="text" name="item_name" class="form-control" required></div>
          <div class="form-field"><label>Category *</label><input type="text" name="category" class="form-control" required list="catList">
            <datalist id="catList"><?php foreach($categories as $c): ?><option value="<?php echo h($c); ?>"><?php endforeach; ?></datalist></div>
          <div class="form-field"><label>Supplier</label><input type="text" name="supplier" class="form-control" list="supList">
            <datalist id="supList"><?php foreach($suppliers as $s): ?><option value="<?php echo h($s); ?>"><?php endforeach; ?></datalist></div>
          <div class="form-field"><label>Initial Quantity</label><input type="number" name="quantity" class="form-control" min="0" value="0"></div>
          <div class="form-field"><label>Unit Price</label><input type="number" name="unit_price" class="form-control" step="0.01" min="0" value="0"></div>
          <div class="form-field"><label>Storage Location *</label><input type="text" name="location" class="form-control" required list="locList">
            <datalist id="locList"><?php foreach($locations as $l): ?><option value="<?php echo h($l); ?>"><?php endforeach; ?></datalist></div>
          <div class="form-field"><label>Reorder Level</label><input type="number" name="reorder_level" class="form-control" min="0" value="10"></div>
        </div>
        <div class="form-field"><label>Status</label><select name="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <div class="form-field"><label>Description</label><textarea name="description" class="form-control" placeholder="Item description, specifications…"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('addItemModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Item</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- EDIT ITEM MODAL -->
<div id="editItemModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Edit Inventory Item</h3><button class="modal-close" onclick="closeModal('editItemModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST" id="editItemForm">
        <input type="hidden" name="action" value="update_item">
        <input type="hidden" id="edit_item_id" name="item_id">
        <div class="form-grid-2">
          <div class="form-field"><label>Item Code</label><input type="text" id="edit_item_code" class="form-control" readonly style="background:var(--off)"></div>
          <div class="form-field"><label>Item Name *</label><input type="text" id="edit_item_name" name="item_name" class="form-control" required></div>
          <div class="form-field"><label>Category *</label><input type="text" id="edit_category" name="category" class="form-control" required list="catList"></div>
          <div class="form-field"><label>Supplier</label><input type="text" id="edit_supplier" name="supplier" class="form-control" list="supList"></div>
          <div class="form-field"><label>Current Qty</label><input type="number" id="edit_qty" class="form-control" readonly style="background:var(--off)"><small style="color:var(--muted);font-size:.74rem">Use Stock Movement to change</small></div>
          <div class="form-field"><label>Unit Price</label><input type="number" id="edit_unit_price" name="unit_price" class="form-control" step="0.01" min="0"></div>
          <div class="form-field"><label>Location *</label><input type="text" id="edit_location" name="location" class="form-control" required list="locList"></div>
          <div class="form-field"><label>Reorder Level</label><input type="number" id="edit_reorder_level" name="reorder_level" class="form-control" min="0"></div>
        </div>
        <div class="form-field"><label>Status</label><select id="edit_status" name="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <div class="form-field"><label>Description</label><textarea id="edit_description" name="description" class="form-control"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('editItemModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Item</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- STOCK MOVEMENT MODAL -->
<div id="stockMovementModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Record Stock Movement</h3><button class="modal-close" onclick="closeModal('stockMovementModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="stock_movement">
        <input type="hidden" id="sm_item_id" name="item_id">
        <div class="form-field"><label>Item</label><input type="text" id="sm_item_name" class="form-control" readonly style="background:var(--off)"></div>
        <div class="form-grid-2">
          <div class="form-field"><label>Movement Type *</label>
            <select name="movement_type" class="form-control">
              <option value="IN">IN — Stock Received</option>
              <option value="OUT">OUT — Stock Issued</option>
              <option value="TRANSFER">TRANSFER</option>
              <option value="ADJUSTMENT">ADJUSTMENT</option>
            </select>
          </div>
          <div class="form-field"><label>Quantity *</label><input type="number" name="quantity" class="form-control" min="1" required></div>
          <div class="form-field"><label>From Location</label><input type="text" name="from_location" class="form-control" list="locList"></div>
          <div class="form-field"><label>To Location</label><input type="text" name="to_location" class="form-control" list="locList"></div>
          <div class="form-field"><label>Reference #</label><input type="text" name="reference_number" class="form-control" placeholder="PO, DR, etc."></div>
          <div class="form-field"><label>Reason *</label><input type="text" name="reason" class="form-control" required placeholder="e.g. Purchase, Issuance…"></div>
        </div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('stockMovementModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-success">Record Movement</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editItem(item) {
    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_item_code').value = item.item_code;
    document.getElementById('edit_item_name').value = item.item_name;
    document.getElementById('edit_category').value  = item.category;
    document.getElementById('edit_supplier').value  = item.supplier || '';
    document.getElementById('edit_qty').value       = item.quantity;
    document.getElementById('edit_unit_price').value= item.unit_price;
    document.getElementById('edit_location').value  = item.location;
    document.getElementById('edit_reorder_level').value = item.reorder_level;
    document.getElementById('edit_status').value    = item.status;
    document.getElementById('edit_description').value  = item.description || '';
    openModal('editItemModal');
}
function openStockModal(id, name) {
    document.getElementById('sm_item_id').value   = id;
    document.getElementById('sm_item_name').value = name;
    openModal('stockMovementModal');
}
function deleteItem(id, name) {
    if (confirm('Deactivate "' + name + '"? This will mark it as inactive.')) {
        var f = document.createElement('form');
        f.method = 'POST';
        f.innerHTML = '<input type="hidden" name="action" value="delete_item"><input type="hidden" name="item_id" value="' + id + '">';
        document.body.appendChild(f);
        f.submit();
    }
}
</script>
<?php require_once '../includes/staff/footer.php'; ?>
