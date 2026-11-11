<?php
session_start();
require_once 'src/Database.php';

$pdo = Database::connect();

// Retrieve and clear flash message from session
$message = $_SESSION['flash_message'] ?? "";
unset($_SESSION['flash_message']);

// Automatically seed a default Admin account if none exists yet
try {
    $adminCheck = $pdo->prepare("SELECT id FROM users WHERE role = 'Admin'");
    $adminCheck->execute();
    if ($adminCheck->rowCount() === 0) {
        $hashedAdminPass = password_hash('admin123', PASSWORD_DEFAULT);
        $seedAdmin = $pdo->prepare("INSERT INTO users (name, email, password, phone, role, delivery_address) VALUES (?, ?, ?, ?, 'Admin', ?)");
        $seedAdmin->execute(['System Administrator', 'admin@gmail.com', $hashedAdminPass, '09000000000', 'Admin Headquarters']);
    }
} catch (PDOException $e) {
    // Fail silently if tables aren't set up yet
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone    = trim($_POST['phone'] ?? '');
    $address  = trim($_POST['address'] ?? '');

    if (empty($name) || empty($email) || empty($password)) {
        $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Please fill in all required fields (Name, Email, Password).</div>";
        header("Location: register.php");
        exit;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Please enter a valid email address.</div>";
        header("Location: register.php");
        exit;
    } else {
        // Check if email already exists in database (removed name restriction)
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $checkStmt->execute([$email]);
        
        if ($checkStmt->rowCount() > 0) {
            $_SESSION['flash_message'] = "<div class='alert error'>⚠️ An account with this Email address already exists. <a href='login.php' style='color:#ff4757; font-weight:bold;'>Please log in.</a></div>";
            header("Location: register.php");
            exit;
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            try {
                // Registers regular users as 'Customer'
                $insertStmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, role, delivery_address) VALUES (?, ?, ?, ?, 'Customer', ?)");
                $insertStmt->execute([$name, $email, $hashedPassword, $phone, $address]);
                $newUserId = $pdo->lastInsertId();

                // Automatically log the user in after registration
                $_SESSION['user_id']   = $newUserId;
                $_SESSION['user_name'] = $name;
                $_SESSION['user_role'] = 'Customer';
                
                header("Location: index.php");
                exit;
            } catch (PDOException $e) {
                $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
                header("Location: register.php");
                exit;
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
        .admin-info { background: #e1f5fe; color: #0288d1; padding: 10px; border-radius: 6px; font-size: 0.85em; margin-bottom: 15px; }
        .login-footer { text-align: center; margin-top: 20px; font-size: 0.95em; color: #666; }
        .login-footer a { color: #ff4757; text-decoration: none; font-weight: bold; }
        .login-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="container">
    <div class="top-bar">
        <h1>📝 Create Account</h1>
        <a href="index.php" class="home-link">🏠 Back to Store</a>
    </div>

    <div class="admin-info">
        💡 <b>Admin Access Note:</b> A default admin account is automatically created for management:<br>
        Email: <code>admin@gmail.com</code> | Password: <code>admin123</code>
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

    <div class="login-footer">
        Already have an account? <a href="login.php">Log in here</a>
    </div>
</div>

</body>
</html>
