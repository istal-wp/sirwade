<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$success_message = $error_message = '';

/* ── FORM HANDLING ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {

        case 'create':
            try {
                $pfx  = strtoupper(substr($_POST['category'],0,3));
                $ipfx = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($_POST['item_name'])),0,4));
                $item_code = $pfx.'-'.$ipfx.'-'.rand(100,999);
                // Ensure unique code
                while ($pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE item_code=?")->execute([$item_code]) && $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE item_code=?")->fetchColumn() > 0)
                    $item_code = $pfx.'-'.$ipfx.'-'.rand(100,999);
                $pdo->prepare("INSERT INTO inventory_items (item_name,item_code,category,quantity,unit_price,supplier,location,reorder_level,description,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$_POST['item_name'],$item_code,$_POST['category'],$_POST['quantity'],$_POST['unit_price'],$_POST['supplier'],$_POST['location'],$_POST['reorder_level'],$_POST['description'],$_POST['status'],$user_email]);
                $iid = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO stock_movements (item_id,movement_type,quantity,reason,unit_cost,total_cost,performed_by,to_location) VALUES (?,'IN',?,'Initial Stock',?,?,?,?)")
                    ->execute([$iid,$_POST['quantity'],$_POST['unit_price'],$_POST['quantity']*$_POST['unit_price'],$user_email,$_POST['location']]);
                $success_message = "Item created: $item_code";
            } catch (Exception $e) { $error_message = $e->getMessage(); }
            break;

        case 'update':
            try {
                $pdo->beginTransaction();
                $old = $pdo->prepare("SELECT * FROM inventory_items WHERE id=?"); $old->execute([$_POST['item_id']]); $old_data = $old->fetch();
                if (!$old_data) throw new Exception("Item not found.");
                $pdo->prepare("UPDATE inventory_items SET item_name=?,category=?,quantity=?,unit_price=?,supplier=?,location=?,reorder_level=?,description=?,status=?,last_edited_by=?,edit_count=edit_count+1,last_updated=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$_POST['item_name'],$_POST['category'],$_POST['quantity'],$_POST['unit_price'],$_POST['supplier'],$_POST['location'],$_POST['reorder_level'],$_POST['description'],$_POST['status'],$user_email,$_POST['item_id']]);
                // Track changes
                $fields = ['item_name'=>'Item Name','category'=>'Category','quantity'=>'Quantity','unit_price'=>'Unit Price','supplier'=>'Supplier','location'=>'Location','reorder_level'=>'Reorder Level','description'=>'Description','status'=>'Status'];
                foreach ($fields as $f=>$l) {
                    if (($old_data[$f]??'') != ($_POST[$f]??'')) {
                        try { $pdo->prepare("INSERT INTO inventory_edit_history (item_id,field_name,old_value,new_value,edited_by,edit_reason) VALUES (?,?,?,?,?,?)")->execute([$_POST['item_id'],$l,$old_data[$f],$_POST[$f],$user_email,$_POST['edit_reason']??'']); } catch (Exception $e) {}
                    }
                }
                if ($old_data['quantity'] != $_POST['quantity']) {
                    $diff = $_POST['quantity'] - $old_data['quantity']; $type = $diff>0?'IN':'OUT';
                    $pdo->prepare("INSERT INTO stock_movements (item_id,movement_type,quantity,reason,unit_cost,total_cost,performed_by,notes) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$_POST['item_id'],$type,abs($diff),'Manual adjustment via edit',$_POST['unit_price'],abs($diff)*$_POST['unit_price'],$user_email,$_POST['edit_reason']??'']);
                }
                $pdo->commit(); $success_message = "Item updated successfully!";
            } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error_message = $e->getMessage(); }
            break;

        case 'adjust_stock':
            try {
                $pdo->beginTransaction();
                $it = $pdo->prepare("SELECT quantity,unit_price FROM inventory_items WHERE id=?"); $it->execute([$_POST['item_id']]); $item = $it->fetch();
                $mt = $_POST['adjustment_type']; $qty = abs($_POST['adjustment_quantity']);
                $new_qty = $mt==='IN' ? $item['quantity']+$qty : $item['quantity']-$qty;
                if ($new_qty < 0) throw new Exception("Insufficient stock.");
                $pdo->prepare("UPDATE inventory_items SET quantity=?,last_updated=CURRENT_TIMESTAMP WHERE id=?")->execute([$new_qty,$_POST['item_id']]);
                $pdo->prepare("INSERT INTO stock_movements (item_id,movement_type,quantity,reason,reference_number,unit_cost,total_cost,performed_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$_POST['item_id'],$mt,$qty,$_POST['reason'],$_POST['reference_number']??null,$item['unit_price'],$qty*$item['unit_price'],$user_email,$_POST['notes']??null]);
                $pdo->commit(); $success_message = "Stock adjusted successfully!";
            } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error_message = $e->getMessage(); }
            break;

        case 'transfer':
            try {
                $pdo->beginTransaction();
                $it = $pdo->prepare("SELECT quantity,unit_price,location FROM inventory_items WHERE id=?"); $it->execute([$_POST['item_id']]); $item = $it->fetch();
                $qty = abs($_POST['transfer_quantity']);
                if ($qty > $item['quantity']) throw new Exception("Insufficient stock for transfer.");
                $pdo->prepare("INSERT INTO stock_movements (item_id,movement_type,quantity,reason,from_location,to_location,unit_cost,total_cost,performed_by,notes) VALUES (?,'TRANSFER',?,?,?,?,?,?,?,?)")
                    ->execute([$_POST['item_id'],$qty,$_POST['transfer_reason'],$_POST['from_location'],$_POST['to_location'],$item['unit_price'],$qty*$item['unit_price'],$user_email,$_POST['notes']??null]);
                if ($qty == $item['quantity'])
                    $pdo->prepare("UPDATE inventory_items SET location=? WHERE id=?")->execute([$_POST['to_location'],$_POST['item_id']]);
                $pdo->commit(); $success_message = "Stock transferred!";
            } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error_message = $e->getMessage(); }
            break;

        case 'delete':
            try {
                $pdo->prepare("DELETE FROM inventory_items WHERE id=?")->execute([$_POST['item_id']]);
                $success_message = "Item deleted.";
            } catch (Exception $e) { $error_message = $e->getMessage(); }
            break;
    }
}

/* ── PAGE DATA ──────────────────────────────────────────────── */
$stats = [
    'total_items' => $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE status='active'")->fetchColumn(),
    'low_stock'   => $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE quantity<=reorder_level AND status='active'")->fetchColumn(),
    'total_value' => $pdo->query("SELECT COALESCE(SUM(quantity*unit_price),0) FROM inventory_items WHERE status='active'")->fetchColumn(),
    'locations'   => $pdo->query("SELECT COUNT(DISTINCT location) FROM inventory_items WHERE status='active'")->fetchColumn(),
];

$search = trim($_GET['search']??'');
$cat_filter = $_GET['category']??'';
$conds=[]; $params=[];
if ($search) { $conds[]="(item_name LIKE ? OR item_code LIKE ? OR supplier LIKE ?)"; $params=array_merge($params,["%$search%","%$search%","%$search%"]); }
if ($cat_filter) { $conds[]="category=?"; $params[]=$cat_filter; }
$where=$conds?'WHERE '.implode(' AND ',$conds):'';
$stmt=$pdo->prepare("SELECT * FROM inventory_items $where ORDER BY created_at DESC"); $stmt->execute($params); $items=$stmt->fetchAll();

$recent_movements = $pdo->query("SELECT sm.*,ii.item_name,ii.item_code FROM stock_movements sm LEFT JOIN inventory_items ii ON sm.item_id=ii.id ORDER BY sm.created_at DESC LIMIT 10")->fetchAll();
$categories = $pdo->query("SELECT DISTINCT category FROM inventory_items WHERE status='active' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

$item_details = $item_movements = null; $edit_history = [];
if (!empty($_GET['view']) && is_numeric($_GET['view'])) {
    $vd = $pdo->prepare("SELECT * FROM inventory_items WHERE id=?"); $vd->execute([$_GET['view']]); $item_details = $vd->fetch();
    if ($item_details) {
        $vm = $pdo->prepare("SELECT * FROM stock_movements WHERE item_id=? ORDER BY created_at DESC"); $vm->execute([$_GET['view']]); $item_movements = $vm->fetchAll();
        try { $ve = $pdo->prepare("SELECT * FROM inventory_edit_history WHERE item_id=? ORDER BY edited_at DESC LIMIT 20"); $ve->execute([$_GET['view']]); $edit_history = $ve->fetchAll(); } catch (Exception $e) {}
    }
}

$page_title = 'Inventory Updates'; $module_subtitle = 'Smart Warehousing'; $back_btn_href = 'sws.php'; $back_btn_label = 'Warehousing'; $active_nav = 'sws';
include 'includes/head.php';
?>
<body>
<?php include 'includes/topbar.php'; ?>
<div class="layout">
<?php include 'includes/sidebar.php'; ?>
<main class="main-content">

    <div class="page-title">
        <span class="page-title-tag">Warehousing / Inventory</span>
        <h1>Inventory <strong>Updates</strong></h1>
        <p>Add, edit, adjust, and transfer physical inventory items.</p>
    </div>

    <?php if ($success_message): ?><div class="alert alert-success"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
    <?php if ($error_message):   ?><div class="alert alert-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <div class="stats-row">
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg></div><div class="stat-value"><?php echo $stats['total_items']; ?></div><div class="stat-label">Total Items</div></div>
        <div class="stat-card <?php echo $stats['low_stock']>0?'warn':''; ?>"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg></div><div class="stat-value"><?php echo $stats['low_stock']; ?></div><div class="stat-label">Low Stock</div></div>
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div><div class="stat-value" style="font-size:1.3rem;">&#8369;<?php echo number_format($stats['total_value'],0); ?></div><div class="stat-label">Total Value</div></div>
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></div><div class="stat-value"><?php echo $stats['locations']; ?></div><div class="stat-label">Locations</div></div>
    </div>

    <?php if ($item_details): ?>
    <!-- ITEM DETAIL VIEW -->
    <div class="panel" style="margin-bottom:1.4rem;">
        <div class="panel-header">
            <span class="panel-title"><?php echo htmlspecialchars($item_details['item_name']); ?> <span class="item-code">(<?php echo $item_details['item_code']; ?>)</span></span>
            <a href="inventory_updates.php" class="btn btn-outline btn-sm">← Back</a>
        </div>
        <div style="padding:1.4rem 1.6rem;">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;margin-bottom:1.4rem;">
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Category</span><div style="font-weight:600;margin-top:.3rem;"><?php echo htmlspecialchars($item_details['category']); ?></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Quantity</span><div style="font-weight:600;font-size:1.2rem;margin-top:.3rem;color:<?php echo $item_details['quantity']<=$item_details['reorder_level']?'var(--error)':'var(--text)'; ?>"><?php echo $item_details['quantity']; ?></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Unit Price</span><div style="font-weight:600;margin-top:.3rem;">&#8369;<?php echo number_format($item_details['unit_price'],2); ?></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Location</span><div style="font-weight:600;margin-top:.3rem;"><?php echo htmlspecialchars($item_details['location']); ?></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Reorder At</span><div style="font-weight:600;margin-top:.3rem;"><?php echo $item_details['reorder_level']; ?></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Status</span><div style="margin-top:.3rem;"><span class="badge badge-<?php echo $item_details['status']==='active'?'normal':'inactive'; ?>"><?php echo ucfirst($item_details['status']); ?></span></div></div>
            </div>
            <?php if ($item_movements): ?>
            <div style="font-size:.85rem;font-weight:600;margin-bottom:.7rem;">Stock Movement History</div>
            <div class="tbl-wrap"><table class="data-table"><thead><tr><th>Type</th><th>Qty</th><th>Reason</th><th>Performed By</th><th>Date</th></tr></thead><tbody>
                <?php foreach ($item_movements as $mv): ?><tr><td><span class="mi-badge mi-<?php echo strtolower($mv['movement_type']); ?>"><?php echo $mv['movement_type']; ?></span></td><td><?php echo $mv['quantity']; ?></td><td><?php echo htmlspecialchars($mv['reason']??'—'); ?></td><td><?php echo htmlspecialchars($mv['performed_by']??'—'); ?></td><td><?php echo date('M d, Y H:i', strtotime($mv['created_at'])); ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- MAIN GRID: Items Table + Recent Movements -->
    <div class="main-grid">
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">Inventory Items</span>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                    <form method="GET" style="display:flex;gap:.5rem;">
                        <input type="text" name="search" class="form-input" placeholder="Search…" value="<?php echo htmlspecialchars($search); ?>" style="width:160px;padding:.42rem .8rem;">
                        <select name="category" class="form-select" style="width:130px;padding:.42rem .8rem;">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?><option value="<?php echo $cat; ?>" <?php echo $cat_filter===$cat?'selected':''; ?>><?php echo htmlspecialchars($cat); ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-ghost">Filter</button>
                        <?php if ($search||$cat_filter): ?><a href="inventory_updates.php" class="btn btn-outline">Clear</a><?php endif; ?>
                    </form>
                    <button class="btn btn-primary" onclick="openModal('createItemModal')">
                        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Item
                    </button>
                </div>
            </div>
            <div class="tbl-wrap">
                <table class="data-table">
                    <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Qty</th><th>Price</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($items)): ?><tr><td colspan="8" class="empty-td">No items found. <a href="#" onclick="openModal('createItemModal');return false;">Add your first →</a></td></tr>
                        <?php else: foreach ($items as $item): ?>
                        <tr>
                            <td><span class="item-code"><?php echo htmlspecialchars($item['item_code']); ?></span></td>
                            <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($item['category']); ?></td>
                            <td><strong style="color:<?php echo $item['quantity']<=$item['reorder_level']?'var(--error)':''; ?>"><?php echo $item['quantity']; ?></strong><?php if ($item['quantity']<=$item['reorder_level']): ?> <span class="badge badge-low" style="font-size:.5rem;">LOW</span><?php endif; ?></td>
                            <td>&#8369;<?php echo number_format($item['unit_price'],2); ?></td>
                            <td><?php echo htmlspecialchars($item['location']); ?></td>
                            <td><span class="badge badge-<?php echo $item['status']==='active'?'normal':'inactive'; ?>"><?php echo ucfirst($item['status']); ?></span></td>
                            <td>
                                <div class="btn-row">
                                    <a href="?view=<?php echo $item['id']; ?>" class="btn btn-ghost btn-sm">View</a>
                                    <button class="btn btn-green btn-sm" onclick="editItem(<?php echo htmlspecialchars(json_encode($item)); ?>)">Edit</button>
                                    <button class="btn btn-amber btn-sm" onclick="adjustStock(<?php echo $item['id']; ?>, '<?php echo addslashes($item['item_name']); ?>', <?php echo $item['quantity']; ?>, '<?php echo addslashes($item['location']); ?>')">Adjust</button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this item?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="item_id" value="<?php echo $item['id']; ?>"><button type="submit" class="btn btn-red btn-sm">Del</button></form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="panel">
            <div class="panel-header"><span class="panel-title">Recent Movements</span><span class="panel-badge">Last 10</span></div>
            <?php if (empty($recent_movements)): ?><div class="empty-td" style="padding:2rem;text-align:center;">No movements yet.</div>
            <?php else: foreach ($recent_movements as $mv): ?>
            <div class="list-item">
                <div>
                    <div class="li-title"><?php echo htmlspecialchars($mv['item_name']??'Unknown'); ?></div>
                    <div class="li-sub"><?php echo htmlspecialchars($mv['reason']??'Stock movement'); ?></div>
                    <div class="li-sub" style="font-family:'DM Mono',monospace;font-size:.62rem;"><?php echo date('M d H:i',strtotime($mv['created_at'])); ?></div>
                </div>
                <span class="mi-badge mi-<?php echo strtolower($mv['movement_type']==='TRANSFER'?'transfer':($mv['movement_type']==='IN'?'in':'out')); ?>"><?php echo $mv['movement_type']==='IN'?'+':($mv['movement_type']==='TRANSFER'?'⇄':'-'); echo $mv['quantity']; ?></span>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</main>
</div>

<!-- CREATE ITEM MODAL -->
<div id="createItemModal" class="modal">
    <div class="modal-box">
        <div class="modal-head"><h3>Add Inventory Item</h3><button class="modal-close" onclick="closeModal('createItemModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-row"><div class="form-group"><label class="form-label">Item Name <span class="req">*</span></label><input type="text" name="item_name" class="form-input" required></div>
                <div class="form-group"><label class="form-label">Category <span class="req">*</span></label>
                    <select name="category" class="form-select" required><option value="">Select…</option><option>Electronics</option><option>Office Supplies</option><option>Furniture</option><option>Equipment</option><option>Materials</option><option>Tools</option><option>Safety</option><option>Consumables</option><option>Other</option></select></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Quantity <span class="req">*</span></label><input type="number" name="quantity" class="form-input" min="0" required value="0"></div>
                <div class="form-group"><label class="form-label">Unit Price (₱) <span class="req">*</span></label><input type="number" name="unit_price" class="form-input" step="0.01" min="0" required value="0.00"></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Supplier</label><input type="text" name="supplier" class="form-input"></div>
                <div class="form-group"><label class="form-label">Location <span class="req">*</span></label><input type="text" name="location" class="form-input" required></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Reorder Level <span class="req">*</span></label><input type="number" name="reorder_level" class="form-input" min="0" required value="5"></div>
                <div class="form-group"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-textarea"></textarea></div>
                <button type="submit" class="submit-btn">Add Item</button>
            </form>
        </div>
    </div>
</div>

<!-- EDIT ITEM MODAL -->
<div id="editItemModal" class="modal">
    <div class="modal-box">
        <div class="modal-head"><h3>Edit Item</h3><button class="modal-close" onclick="closeModal('editItemModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="item_id" id="ei_id">
                <div class="form-row"><div class="form-group"><label class="form-label">Item Name</label><input type="text" name="item_name" id="ei_name" class="form-input" required></div>
                <div class="form-group"><label class="form-label">Category</label><select name="category" id="ei_cat" class="form-select"><option>Electronics</option><option>Office Supplies</option><option>Furniture</option><option>Equipment</option><option>Materials</option><option>Tools</option><option>Safety</option><option>Consumables</option><option>Other</option></select></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Quantity</label><input type="number" name="quantity" id="ei_qty" class="form-input" min="0"></div>
                <div class="form-group"><label class="form-label">Unit Price (₱)</label><input type="number" name="unit_price" id="ei_price" class="form-input" step="0.01"></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Supplier</label><input type="text" name="supplier" id="ei_supplier" class="form-input"></div>
                <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" id="ei_loc" class="form-input"></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Reorder Level</label><input type="number" name="reorder_level" id="ei_reorder" class="form-input" min="0"></div>
                <div class="form-group"><label class="form-label">Status</label><select name="status" id="ei_status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" id="ei_desc" class="form-textarea"></textarea></div>
                <div class="form-group"><label class="form-label">Reason for Edit</label><input type="text" name="edit_reason" class="form-input" placeholder="Optional — logged in history"></div>
                <button type="submit" class="submit-btn">Save Changes</button>
            </form>
        </div>
    </div>
</div>

<!-- ADJUST STOCK MODAL -->
<div id="adjustStockModal" class="modal">
    <div class="modal-box">
        <div class="modal-head"><h3>Adjust Stock</h3><button class="modal-close" onclick="closeModal('adjustStockModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="item_id" id="as_id">
                <div class="item-info-banner">Item: <strong id="as_name">—</strong> &nbsp;|&nbsp; Current Stock: <strong id="as_qty">—</strong></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Movement Type <span class="req">*</span></label><select name="adjustment_type" class="form-select" required><option value="IN">Stock In</option><option value="OUT">Stock Out</option></select></div>
                    <div class="form-group"><label class="form-label">Quantity <span class="req">*</span></label><input type="number" name="adjustment_quantity" class="form-input" min="1" required></div>
                </div>
                <div class="form-group"><label class="form-label">Reason <span class="req">*</span></label>
                    <select name="reason" class="form-select" required><option>Purchase Received</option><option>Sale/Dispatch</option><option>Damaged/Loss</option><option>Return</option><option>Inventory Count Adjustment</option><option>Other</option></select></div>
                <div class="form-group"><label class="form-label">Reference Number</label><input type="text" name="reference_number" class="form-input" placeholder="PO#, DR#, etc."></div>
                <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea" rows="2"></textarea></div>
                <button type="submit" class="submit-btn">Confirm Adjustment</button>
            </form>
        </div>
    </div>
</div>

<script>
function editItem(item) {
    document.getElementById('ei_id').value       = item.id;
    document.getElementById('ei_name').value     = item.item_name;
    document.getElementById('ei_cat').value      = item.category;
    document.getElementById('ei_qty').value      = item.quantity;
    document.getElementById('ei_price').value    = item.unit_price;
    document.getElementById('ei_supplier').value = item.supplier || '';
    document.getElementById('ei_loc').value      = item.location;
    document.getElementById('ei_reorder').value  = item.reorder_level;
    document.getElementById('ei_desc').value     = item.description || '';
    document.getElementById('ei_status').value   = item.status;
    openModal('editItemModal');
}
function adjustStock(id, name, qty, loc) {
    document.getElementById('as_id').value  = id;
    document.getElementById('as_name').textContent = name;
    document.getElementById('as_qty').textContent  = qty;
    openModal('adjustStockModal');
}
</script>
<?php include 'includes/footer.php'; ?>
