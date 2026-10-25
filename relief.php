<?php
require_once "config.php";
require_once "helpers.php";
require_login();

$isAdmin = (current_user()['role'] ?? '') === 'admin';
$flash = flash_message('flash');
$error = '';
$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Admin access required.');
    }

    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        exit('Invalid form token. Please reload the ledger and try again.');
    }

    if (!$conn || !$conn->ping()) {
        $error = 'The database is unavailable. Stock was not changed.';
    } elseif (($_POST['action'] ?? '') === 'add_stock') {
        $itemName = trim((string)($_POST['item_name'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $unit = trim((string)($_POST['unit'] ?? ''));
        $donor = trim((string)($_POST['donor'] ?? ''));
        $batchCode = trim((string)($_POST['batch_code'] ?? ''));
        $quantityReceived = filter_var($_POST['quantity_received'] ?? null, FILTER_VALIDATE_INT);

        if ($itemName === '' || strlen($itemName) > 120
            || $category === '' || strlen($category) > 80
            || $unit === '' || strlen($unit) > 40
            || strlen($donor) > 150 || strlen($batchCode) > 60
            || $quantityReceived === false || $quantityReceived < 1) {
            $error = 'Enter a valid item, category, unit, and received quantity.';
        } else {
            $quantityDistributed = 0;
            $stmt = $conn->prepare('INSERT INTO relief_inventory (item_name, category, quantity_received, quantity_distributed, unit, donor, batch_code) VALUES (?, ?, ?, ?, ?, ?, ?)');
            if (!$stmt) {
                $error = 'Unable to prepare the stock entry.';
            } else {
                $stmt->bind_param('ssiisss', $itemName, $category, $quantityReceived, $quantityDistributed, $unit, $donor, $batchCode);
                if ($stmt->execute()) {
                    $stmt->close();
                    flash_message('flash', 'Stock added to the relief ledger.');
                    header('Location: relief.php');
                    exit;
                }
                $stmt->close();
                $error = 'Unable to add stock. Check whether the batch code is already in use.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'delete_stock') {
        $stockId = filter_var($_POST['stock_id'] ?? null, FILTER_VALIDATE_INT);
        if ($stockId === false || $stockId < 1) {
            $error = 'Select a valid stock entry to delete.';
        } else {
            $stmt = $conn->prepare('DELETE FROM relief_inventory WHERE id = ?');
            if (!$stmt) {
                $error = 'Unable to prepare the stock deletion.';
            } else {
                $stmt->bind_param('i', $stockId);
                if ($stmt->execute() && $stmt->affected_rows === 1) {
                    $stmt->close();
                    flash_message('flash', 'Stock entry deleted. Existing distribution receipts were kept.');
                    header('Location: relief.php');
                    exit;
                }
                $stmt->close();
                $error = 'Stock entry was not found or could not be deleted.';
            }
        }
    } else {
        http_response_code(400);
        exit('Unknown ledger action.');
    }
}

$inventory = db_rows($conn, "SELECT * FROM relief_inventory ORDER BY id DESC", []);
$transactions = db_rows($conn, "SELECT * FROM relief_transactions ORDER BY created_at DESC LIMIT 30", []);
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Relief Ledger — VetraDRR</title><script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="app.css"></head>
<body class="bg-ink text-white min-h-screen"><div class="max-w-6xl mx-auto px-5 py-8"><div class="flex items-center justify-between gap-3"><a href="index.php" class="text-gray-500 text-sm">← Dashboard</a><a href="logout.php" class="btn btn-ghost">Logout</a></div>
<?php if ($flash): ?><div class="mt-4 rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100"><?=e($flash)?></div><?php endif; ?>
<?php if ($error): ?><div class="mt-4 rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-100"><?=e($error)?></div><?php endif; ?>
<div class="mt-7 panel p-6"><div class="section-label">TRANSPARENT RELIEF LEDGER</div><h1 class="text-4xl font-bold mt-2"><?= $isAdmin ? 'Inventory & distribution control' : 'Public relief inventory' ?></h1><p class="muted mt-2"><?= $isAdmin ? 'Monitor incoming stock, distribution, and receipts for the local response.' : 'Review the latest public distribution data for the barangay response.' ?></p>
<?php if ($isAdmin): ?>
<form method="post" class="grid md:grid-cols-2 lg:grid-cols-4 gap-3 mt-6">
  <input type="hidden" name="csrf_token" value="<?=e($csrfToken)?>">
  <input type="hidden" name="action" value="add_stock">
  <div><label class="auth-label" for="item_name">Item name</label><input class="auth-input" id="item_name" name="item_name" maxlength="120" required></div>
  <div><label class="auth-label" for="category">Category</label><input class="auth-input" id="category" name="category" maxlength="80" required></div>
  <div><label class="auth-label" for="quantity_received">Quantity received</label><input class="auth-input" id="quantity_received" name="quantity_received" type="number" min="1" step="1" required></div>
  <div><label class="auth-label" for="unit">Unit</label><input class="auth-input" id="unit" name="unit" maxlength="40" placeholder="packs" required></div>
  <div><label class="auth-label" for="donor">Donor</label><input class="auth-input" id="donor" name="donor" maxlength="150"></div>
  <div><label class="auth-label" for="batch_code">Batch code</label><input class="auth-input" id="batch_code" name="batch_code" maxlength="60"></div>
  <div class="md:col-span-2 lg:col-span-4"><button type="submit" class="btn btn-orange">Add stock</button></div>
</form>
<?php endif; ?>
<div class="table-wrap mt-6"><table><thead><tr><th>Item</th><th>Category</th><th>Donor</th><th>Batch</th><th>Received</th><th>Distributed</th><th>Remaining</th><?php if ($isAdmin): ?><th>Action</th><?php endif; ?></tr></thead><tbody><?php foreach($inventory as $i):?><tr><td><b><?=e($i['item_name'])?></b></td><td><?=e($i['category'])?></td><td><?=e($i['donor'])?></td><td><?=e($i['batch_code'])?></td><td><?=number_format($i['quantity_received'])?></td><td><?=number_format($i['quantity_distributed'])?></td><td><span class="remaining"><?=number_format($i['quantity_received']-$i['quantity_distributed'])?></span></td><?php if ($isAdmin): ?><td><form method="post" onsubmit="return confirm('Delete this stock entry? Existing distribution receipts will be kept.')"><input type="hidden" name="csrf_token" value="<?=e($csrfToken)?>"><input type="hidden" name="action" value="delete_stock"><input type="hidden" name="stock_id" value="<?=(int)$i['id']?>"><button type="submit" class="mini text-red-300">Delete stock</button></form></td><?php endif; ?></tr><?php endforeach;?></tbody></table></div></div>
<div class="panel p-6 mt-4"><h2 class="text-xl font-semibold mb-4">Recent receipts</h2><div class="table-wrap"><table><thead><tr><th>Receipt</th><th>Family</th><th>Item</th><th>Qty</th><th>Issued by</th><th>Time</th></tr></thead><tbody><?php foreach($transactions as $t):?><tr><td><?=e($t['receipt_code'])?></td><td><?=e($t['family_code'])?></td><td><?=e($t['item_name'])?></td><td><?=e($t['quantity'])?></td><td><?=e($t['issued_by'])?></td><td><?=e($t['created_at'])?></td></tr><?php endforeach;?></tbody></table></div></div></div></body></html>