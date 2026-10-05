<?php
session_start();
require_once 'src/Database.php';

$pdo = Database::connect();
$message = "";

// Handle Login Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $message = "<div class='alert error'>⚠️️ Please fill in both email and password.</div>";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'] ?? 'Customer';

            header("Location: index.php");
            exit;
        } else {
            $message = "<div class='alert error'>❌ Invalid email or password. Please try again.</div>";
        }
    }
}

// Handle Register Form Submission (from the modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['reg_email'] ?? '');
    $password = $_POST['reg_password'] ?? '';
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
            $message = "<div class='alert error'>⚠️ This email is already registered. Please log in.</div>";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            try {
                $insertStmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, role, delivery_address) VALUES (?, ?, ?, ?, 'Customer', ?)");
                $insertStmt->execute([$name, $email, $hashedPassword, $phone, $address]);
                
                $message = "<div class='alert success'>🎉 Account created successfully! You can now log in.</div>";
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
    <title>Login - Online Food Delivery System</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 450px; margin: 50px auto; background: #fff; padding: 35px; border-radius: 12px; box-shadow: 0 6px 16px rgba(0,0,0,0.08); position: relative; animation: slideUp 0.4s ease-out; }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 15px; }
        h1 { color: #ff4757; margin: 0; font-size: 1.6em; }
        
        .home-link { text-decoration: none; background: #2f3542; color: white; padding: 8px 14px; font-size: 13px; border-radius: 6px; font-weight: bold; transition: all 0.2s; }
        .home-link:hover { background: #57606f; }
        .home-link:active { transform: scale(0.95); }

        .alert { padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; font-size: 0.95em; animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        
        .success { background: #eaffea; color: #2ed573; border: 1px solid #2ed573; }
        .error { background: #ffeaea; color: #ff4757; border: 1px solid #ff4757; }

        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542; font-size: 0.95em; }
        input[type="email"], input[type="password"], input[type="text"], textarea {
            width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 1em; box-sizing: border-box; transition: all 0.2s;
        }
        input:focus, textarea:focus { border-color: #ff4757; outline: none; box-shadow: 0 0 8px rgba(255, 71, 87, 0.25); }
        
        .btn { background: #ff4757; color: white; border: none; padding: 14px; font-size: 16px; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%; transition: all 0.2s; }
        .btn:hover { background: #ff6b81; transform: translateY(-1px); }
        .btn:active { transform: scale(0.97); }

        .register-footer { text-align: center; margin-top: 20px; font-size: 0.95em; color: #666; }
        .register-footer a { color: #ff4757; text-decoration: none; font-weight: bold; cursor: pointer; transition: opacity 0.2s; }
        .register-footer a:hover { text-decoration: underline; opacity: 0.8; }

        /* Smooth Modal Pop-up Animation Styles */
        .modal-overlay { 
            display: flex; 
            visibility: hidden;
            position: fixed; 
            z-index: 1000; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            background-color: rgba(0,0,0,0.5); 
            backdrop-filter: blur(4px); 
            justify-content: center; 
            align-items: center; 
            opacity: 0;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .modal-content { 
            background: #fff; 
            padding: 30px; 
            border-radius: 12px; 
            width: 90%; 
            max-width: 450px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.2); 
            position: relative; 
            max-height: 90vh; 
            overflow-y: auto;
            transform: scale(0.8) translateY(20px);
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        /* Active state classes triggered when "Register here" is clicked */
        .modal-overlay.active { 
            visibility: visible; 
            opacity: 1; 
        }
        .modal-overlay.active .modal-content { 
            transform: scale(1) translateY(0); 
        }

        .modal-close { position: absolute; top: 15px; right: 20px; font-size: 24px; font-weight: bold; color: #aaa; cursor: pointer; background: none; border: none; transition: color 0.2s; }
        .modal-close:hover { color: #333; }
        .modal-form-group { margin-bottom: 15px; text-align: left; }
    </style>
</head>
<body>

<div class="container">
    <div class="top-bar">
        <h1>🔐 Welcome Back</h1>
        <a href="index.php" class="home-link">🏠 Store</a>
    </div>

    <p style="color: #666; font-size: 0.95em; margin-top: 0;">Log in to your account to place and manage your food orders.</p>

    <?php echo $message; ?>

    <!-- Login Form -->
    <form method="POST">
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="e.g., juan@example.com">
        </div>

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required placeholder="••••••••">
        </div>

        <button type="submit" name="login" class="btn">Log In</button>
    </form>

    <div class="register-footer">
        Don't have an account yet? <a onclick="openRegisterModal()">Register here</a>
    </div>
</div>

<!-- Register Modal Popup with Smooth Spring Animation -->
<div id="registerModal" class="modal-overlay">
    <div class="modal-content">
        <button class="modal-close" onclick="closeRegisterModal()">&times;</button>
        <h2 style="color: #ff4757; margin-top: 0; margin-bottom: 5px;">📝 Create Account</h2>
        <p style="color: #666; font-size: 0.9em; margin-bottom: 20px;">Register your details to start placing orders.</p>
        
        <form method="POST">
            <div class="modal-form-group">
                <label>Full Name *</label>
                <input type="text" name="name" required placeholder="e.g., Juan Dela Cruz">
            </div>

            <div class="modal-form-group">
                <label>Email Address *</label>
                <input type="email" name="reg_email" required placeholder="e.g., juan@example.com">
            </div>

            <div class="modal-form-group">
                <label>Password *</label>
                <input type="password" name="reg_password" required placeholder="••••••••">
            </div>

            <div class="modal-form-group">
                <label>Phone Number</label>
                <input type="text" name="phone" placeholder="e.g., 09123456789">
            </div>

            <div class="modal-form-group">
                <label>Delivery Address</label>
                <textarea name="address" rows="2" placeholder="Complete street address..."></textarea>
            </div>

            <button type="submit" name="register" class="btn" style="margin-top: 5px;">
                Register Account
            </button>
        </form>
    </div>
</div>

<script>
    function openRegisterModal() {
        document.getElementById('registerModal').classList.add('active');
    }

    function closeRegisterModal() {
        document.getElementById('registerModal').classList.remove('active');
    }

    // Close modal smoothly when clicking outside the white content box
    window.onclick = function(event) {
        let modal = document.getElementById('registerModal');
        if (event.target === modal) {
            closeRegisterModal();
        }
    }
</script>

</body>
</html>