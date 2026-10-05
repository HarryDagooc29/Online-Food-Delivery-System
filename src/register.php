<?php
require_once 'src/Database.php';

$pdo = Database::connect();
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone    = trim($_POST['phone'] ?? '');
    $address  = trim($_POST['address'] ?? '');

    if (empty($name) || empty($email) || empty($password)) {
        $message = "<div class='alert error'>⚠️ Please fill in all required fields (Name, Email, Password).</div>";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "<div class='alert error'>⚠️ Please enter a valid email address.</div>";
    } else {
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $checkStmt->execute([$email]);
        
        if ($checkStmt->rowCount() > 0) {
            $message = "<div class='alert error'>⚠️ This email is already registered. Please use a different email or log in.</div>";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            try {
                // Matches your exact database columns: name, email, password, phone, role, delivery_address
                $insertStmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, role, delivery_address) VALUES (?, ?, ?, ?, 'Customer', ?)");
                $insertStmt->execute([$name, $email, $hashedPassword, $phone, $address]);
                
                $message = "<div class='alert success'>🎉 Account created successfully! You can now place orders.</div>";
            } catch (PDOException $e) {
                $message = "<div class='alert error'>⚠️ Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - Online Food Delivery System</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 500px; margin: 40px auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 15px; }
        h1 { color: #ff4757; margin: 0; font-size: 1.6em; }
        .home-link { text-decoration: none; background: #2f3542; color: white; padding: 8px 14px; font-size: 13px; border-radius: 6px; font-weight: bold; transition: background 0.2s; }
        .home-link:hover { background: #57606f; }
        .alert { padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; font-size: 0.95em; }
        .success { background: #eaffea; color: #2ed573; border: 1px solid #2ed573; }
        .error { background: #ffeaea; color: #ff4757; border: 1px solid #ff4757; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542; font-size: 0.95em; }
        input[type="text"], input[type="email"], input[type="password"], textarea {
            width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 1em; box-sizing: border-box;
        }
        input:focus, textarea:focus { border-color: #ff4757; outline: none; }
        .btn { background: #ff4757; color: white; border: none; padding: 12px; font-size: 16px; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%; margin-top: 10px; transition: background 0.2s; }
        .btn:hover { background: #ff6b81; }
    </style>
</head>
<body>

<div class="container">
    <div class="top-bar">
        <h1>📝 Create Account</h1>
        <a href="index.php" class="home-link">🏠 Back to Store</a>
    </div>

    <p style="color: #666; font-size: 0.95em; margin-top: 0;">Register your customer details to start ordering delicious meals.</p>

    <?php echo $message; ?>

    <form method="POST">
        <div class="form-group">
            <label>Full Name *</label>
            <input type="text" name="name" required placeholder="e.g., Juan Dela Cruz">
        </div>

        <div class="form-group">
            <label>Email Address *</label>
            <input type="email" name="email" required placeholder="e.g., juan@example.com">
        </div>

        <div class="form-group">
            <label>Password *</label>
            <input type="password" name="password" required placeholder="••••••••">
        </div>

        <div class="form-group">
            <label>Phone Number</label>
            <input type="text" name="phone" placeholder="e.g., 09123456789">
        </div>

        <div class="form-group">
            <label>Delivery Address</label>
            <textarea name="address" rows="3" placeholder="Enter your complete street address..."></textarea>
        </div>

        <button type="submit" name="register" class="btn">Register Account</button>
    </form>
</div>

</body>
</html>