<?php
session_start();
require_once 'src/Database.php';
require_once 'src/User.php';
require_once 'src/Restaurant.php';
require_once 'src/Order.php';

$pdo = Database::connect();

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$currentUserId = $isLoggedIn ? $_SESSION['user_id'] : null;
$currentUserName = $isLoggedIn ? $_SESSION['user_name'] : 'Guest';

// Handle Order Submission
$orderMessage = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    if (!$isLoggedIn) {
        $orderMessage = "<div class='alert error'>⚠️ You must <a href='login.php' style='color:#ff4757; font-weight:bold;'>log in</a> first before placing an order.</div>";
    } else {
        $order = new Order($currentUserId);
        
        if (!empty($_POST['items'])) {
            foreach ($_POST['items'] as $itemId => $qty) {
                $qty = intval($qty);
                if ($qty > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM food_items WHERE id = ?");
                    $stmt->execute([$itemId]);
                    $row = $stmt->fetch();
                    if ($row) {
                        $foodItem = new FoodItem($row['id'], $row['item_name'], $row['price']);
                        $order->addItem($foodItem, $qty);
                    }
                }
            }
            
            if ($order->getTotalAmount() > 0) {
                $newOrderId = $order->saveToDatabase($pdo);
                $orderMessage = "<div class='alert success'>🎉 Order #{$newOrderId} placed successfully! Total: ₱" . number_format($order->getTotalAmount(), 2) . "</div>";
            } else {
                $orderMessage = "<div class='alert error'>⚠️ Please select at least one item quantity greater than zero.</div>";
            }
        }
    }
}

// Fetch Restaurants & Recent Orders
$restaurants = $pdo->query("SELECT * FROM restaurants")->fetchAll();
$recentOrders = $pdo->query("SELECT o.id, o.status, o.total_amount, o.created_at, u.name as customer_name FROM orders o JOIN users u ON o.customer_id = u.id ORDER BY o.created_at DESC LIMIT 5")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Food Delivery System</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1000px; margin: auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); position: relative; }
        
        /* Top Bar Header */
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 15px; flex-wrap: wrap; gap: 15px; }
        .nav-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        
        .history-btn { background: #2f3542; color: white; border: none; padding: 8px 14px; font-size: 14px; border-radius: 6px; cursor: pointer; font-weight: bold; display: flex; align-items: center; gap: 6px; transition: all 0.2s; text-decoration: none; }
        .history-btn:hover { background: #57606f; transform: translateY(-1px); }
        .history-btn:active { transform: scale(0.97); }

        .login-btn { background: #ff4757; }
        .login-btn:hover { background: #ff6b81; }

        .logout-btn { background: #ff4757; }
        .logout-btn:hover { background: #ff6b81; }

        h1 { color: #ff4757; margin: 0; font-size: 1.8em; }
        .alert { padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; }
        .success { background: #eaffea; color: #2ed573; border: 1px solid #2ed573; }
        .error { background: #ffeaea; color: #ff4757; border: 1px solid #ff4757; }
        
        .restaurant-box { border: 1px solid #e1e1e1; padding: 20px; border-radius: 10px; margin-bottom: 30px; background: #fafafa; }
        .restaurant-box h3 { margin-top: 0; color: #2f3542; font-size: 1.3em; border-bottom: 2px solid #ff4757; padding-bottom: 8px; display: inline-block; }
        
        /* Product Grid & Cards */
        .food-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; margin-top: 15px; }
        .food-card { background: #fff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; transition: transform 0.2s; }
        .food-card:hover { transform: translateY(-3px); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .food-img { width: 100%; height: 140px; object-fit: cover; background: #eccc68; }
        .food-info { padding: 15px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; }
        .food-title { font-weight: bold; font-size: 1.1em; color: #2f3542; margin-bottom: 8px; }
        .food-price { color: #ff4757; font-weight: bold; font-size: 1.05em; margin-bottom: 12px; }
        .food-action { display: flex; align-items: center; justify-content: space-between; }
        
        input[type="number"] { width: 60px; padding: 6px; border: 1px solid #ccc; border-radius: 4px; text-align: center; font-weight: bold; }
        
        .btn { background: #ff4757; color: white; border: none; padding: 14px 25px; font-size: 16px; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%; margin-top: 10px; transition: background 0.2s; }
        .btn:hover { background: #ff6b81; }

        /* Modal Styles */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center; backdrop-filter: blur(3px); }
        .modal-content { background: #fff; padding: 25px; border-radius: 10px; width: 90%; max-width: 650px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); position: relative; animation: fadeIn 0.3s; }
        
        /* Specific Compact Modal Style for Logout Confirmation */
        .logout-modal-content { max-width: 380px; text-align: center; padding: 30px; animation: scaleUp 0.25s ease-out; }
        
        .close-modal { position: absolute; top: 15px; right: 20px; font-size: 24px; font-weight: bold; color: #aaa; cursor: pointer; }
        .close-modal:hover { color: #333; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes scaleUp { from { opacity: 0; transform: scale(0.85); } to { opacity: 1; transform: scale(1); } }

        .modal-actions { display: flex; gap: 10px; margin-top: 20px; justify-content: center; }
        .modal-btn { padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; border: none; font-size: 0.95em; transition: all 0.2s; }
        .btn-yes { background: #ff4757; color: white; }
        .btn-yes:hover { background: #ff6b81; }
        .btn-no { background: #dfe4ea; color: #2f3542; }
        .btn-no:hover { background: #ced6e0; }

        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; font-size: 0.95em; }
        th { background: #f1f2f6; }
    </style>
</head>
<body>

<div class="container">
    <!-- Top Bar with User Session & Navigation -->
    <div class="top-bar">
        <h1>🍔 Online Food Delivery System</h1>
        <div class="nav-actions">
            <?php if ($isLoggedIn): ?>
                <span style="font-weight: bold; color: #2f3542; font-size: 0.95em; margin-right: 5px;">👤 Hello, <?php echo htmlspecialchars($currentUserName); ?></span>
            <?php else: ?>
                <a href="login.php" class="history-btn login-btn">🔑 Login</a>
            <?php endif; ?>
            
            <!-- Recent Orders Log Button -->
            <button type="button" class="history-btn" onclick="openOrdersModal()">
                📜 <span>Recent Orders Log</span>
            </button>

            <!-- Logout Button triggering Custom Modal -->
            <?php if ($isLoggedIn): ?>
                <button type="button" class="history-btn logout-btn" onclick="openLogoutModal()" title="Log out of your account">
                    ⎋ <span>Logout</span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <p>Welcome back! Select your preferred dishes from our partner restaurants below.</p>

    <?php echo $orderMessage; ?>

    <form method="POST">
        <?php foreach ($restaurants as $rest): ?>
            <div class="restaurant-box">
                <h3>📍 <?php echo htmlspecialchars($rest['restaurant_name']); ?></h3>
                
                <div class="food-grid">
                    <?php
                    $itemStmt = $pdo->prepare("SELECT * FROM food_items WHERE restaurant_id = ?");
                    $itemStmt->execute([$rest['id']]);
                    $items = $itemStmt->fetchAll();
                    
                    foreach ($items as $item):
                        $imgSrc = !empty($item['image']) ? htmlspecialchars($item['image']) : 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=400';
                    ?>
                        <div class="food-card">
                            <img src="<?php echo $imgSrc; ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>" class="food-img">
                            <div class="food-info">
                                <div>
                                    <div class="food-title"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                    <div class="food-price">₱<?php echo number_format($item['price'], 2); ?></div>
                                </div>
                                <div class="food-action">
                                    <label style="font-size: 0.9em; color: #666;">Qty:</label>
                                    <input type="number" name="items[<?php echo $item['id']; ?>]" value="0" min="0">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($isLoggedIn): ?>
            <button type="submit" name="place_order" class="btn">Confirm & Place Order</button>
        <?php else: ?>
            <div style="text-align: center; padding: 20px; background: #fdf2f2; border: 1px dashed #ff4757; border-radius: 8px; margin-top: 20px;">
                <p style="margin: 0; color: #ff4757; font-weight: bold;">🔒 Please <a href="login.php" style="color: #ff4757; text-decoration: underline;">log in</a> to confirm and place your order.</p>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- Modal Popup for Recent Orders Log -->
<div id="ordersModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeOrdersModal()">&times;</span>
        <h2 style="margin-top: 0; color: #2f3542; font-size: 1.4em;">📦 Recent Orders Log</h2>
        <table>
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Status</th>
                    <th>Total Amount</th>
                    <th>Date / Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentOrders)): ?>
                    <tr><td colspan="5" style="text-align: center; color: #777;">No orders placed yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentOrders as $ord): ?>
                        <tr>
                            <td>#<?php echo $ord['id']; ?></td>
                            <td><?php echo htmlspecialchars($ord['customer_name']); ?></td>
                            <td><span style="background: #e1f5fe; color: #0288d1; padding: 3px 6px; border-radius: 4px; font-weight: bold;"><?php echo $ord['status']; ?></span></td>
                            <td>₱<?php echo number_format($ord['total_amount'], 2); ?></td>
                            <td><?php echo $ord['created_at']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Custom Logout Confirmation Modal Dialog -->
<div id="logoutModal" class="modal">
    <div class="modal-content logout-modal-content">
        <h3 style="margin-top: 0; color: #2f3542; font-size: 1.3em;">⚠️ Confirm Logout</h3>
        <p style="color: #666; font-size: 0.95em; margin-bottom: 20px;">Do you want to logout from your account?</p>
        <div class="modal-actions">
            <button type="button" class="modal-btn btn-no" onclick="closeLogoutModal()">Cancel</button>
            <a href="logout.php" class="modal-btn btn-yes" style="text-decoration: none; display: inline-block; line-height: normal;">Yes, Logout</a>
        </div>
    </div>
</div>

<script>
    function openOrdersModal() {
        document.getElementById('ordersModal').style.display = 'flex';
    }

    function closeOrdersModal() {
        document.getElementById('ordersModal').style.display = 'none';
    }

    function openLogoutModal() {
        document.getElementById('logoutModal').style.display = 'flex';
    }

    function closeLogoutModal() {
        document.getElementById('logoutModal').style.display = 'none';
    }

    // Close modals when clicking outside the inner content box
    window.onclick = function(event) {
        var ordersModal = document.getElementById('ordersModal');
        var logoutModal = document.getElementById('logoutModal');
        if (event.target == ordersModal) {
            ordersModal.style.display = 'none';
        }
        if (event.target == logoutModal) {
            logoutModal.style.display = 'none';
        }
    }
</script>

</body>
</html>