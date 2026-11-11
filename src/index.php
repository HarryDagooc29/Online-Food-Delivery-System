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
$currentUserRole = $isLoggedIn ? ($_SESSION['user_role'] ?? 'Customer') : 'Guest';
$isAdmin = ($currentUserRole === 'Admin');
$isRider = ($currentUserRole === 'Rider');

// Determine active tab view from URL parameter (Default to 'rider_delivery' if logged in as Rider)
$defaultTab = $isRider ? 'rider_delivery' : 'order';
$activeTab = $_GET['tab'] ?? $defaultTab;

// Handle Actions (Order placement, Admin Product Addition, Stock Replenishment, Profile Update, or Rider Creation)
$message = "";

// Pull flash messages stored in session after redirection
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 0. Handle Admin Creating a New Delivery Rider with Strict Email & Phone Format Validation
    if (isset($_POST['create_rider'])) {
        if (!$isAdmin) {
            $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Unauthorized action.</div>";
        } else {
            $riderName = trim($_POST['rider_name'] ?? '');
            $riderEmail = trim($_POST['rider_email'] ?? '');
            $riderPhoneRaw = trim($_POST['rider_phone'] ?? '');
            $riderPhone = preg_replace('/\D/', '', $riderPhoneRaw);
            $riderPassword = $_POST['rider_password'] ?? '';

            if (empty($riderName) || empty($riderEmail) || empty($riderPassword)) {
                $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Please fill in all required fields for the rider.</div>";
            } elseif (!filter_var($riderEmail, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Invalid email format. Please enter a valid email address (e.g., rider@fooddelivery.com).</div>";
            } elseif (!empty($riderPhone) && strlen($riderPhone) !== 11) {
                $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Phone number must contain exactly 11 digits (e.g. 09123456789).</div>";
            } else {
                try {
                    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $checkStmt->execute([$riderEmail]);
                    if ($checkStmt->fetch()) {
                        $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Email address is already registered in the system.</div>";
                    } else {
                        // Format phone: 0912 345 6789
                        $formattedPhone = strlen($riderPhone) === 11 ? substr($riderPhone, 0, 4) . ' ' . substr($riderPhone, 4, 3) . ' ' . substr($riderPhone, 7, 4) : $riderPhoneRaw;
                        
                        $hashedPassword = password_hash($riderPassword, PASSWORD_DEFAULT);
                        $insRider = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, address) VALUES (?, ?, ?, ?, 'Rider', ?)");
                        $insRider->execute([$riderName, $riderEmail, $formattedPhone, $hashedPassword, 'Assigned Depot / Hub']);
                        $_SESSION['flash_message'] = "<div class='alert success'>🎉 Delivery Rider '<strong>{$riderName}</strong>' created and registered successfully!</div>";
                    }
                } catch (Exception $e) {
                    $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Database Error creating rider: " . $e->getMessage() . "</div>";
                }
            }
            header("Location: index.php?tab=manage_riders");
            exit;
        }
    }

    // 0.5 Handle Customer Profile Update
    if (isset($_POST['update_profile'])) {
        if (!$isLoggedIn) {
            $_SESSION['flash_message'] = "<div class='alert error'>⚠️ You must be logged in to update your profile.</div>";
        } else {
            $newName = trim($_POST['name'] ?? '');
            $newPhoneRaw = trim($_POST['phone'] ?? '');
            $newPhoneDigits = preg_replace('/\D/', '', $newPhoneRaw);
            $country = trim($_POST['country'] ?? '');
            $province = trim($_POST['province'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $zipcode = trim($_POST['zipcode'] ?? '');
            $specific = trim($_POST['specific_address'] ?? '');

            $addressParts = array_filter([$specific, $city, $province, $zipcode, $country]);
            $newAddress = implode(', ', $addressParts);

            if (empty($newName)) {
                $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Full Name cannot be empty.</div>";
            } elseif (!empty($newPhoneDigits) && strlen($newPhoneDigits) !== 11) {
                $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Phone number must contain exactly 11 digits (e.g. 09123456789).</div>";
            } else {
                try {
                    $formattedPhone = strlen($newPhoneDigits) === 11 ? substr($newPhoneDigits, 0, 4) . ' ' . substr($newPhoneDigits, 4, 3) . ' ' . substr($newPhoneDigits, 7, 4) : $newPhoneRaw;

                    $upProf = $pdo->prepare("UPDATE users SET name = ?, phone = ?, address = ? WHERE id = ?");
                    $upProf->execute([$newName, $formattedPhone, $newAddress, $currentUserId]);
                    $_SESSION['user_name'] = $newName;
                    $_SESSION['flash_message'] = "<div class='alert success'>🎉 Profile and delivery information updated successfully!</div>";
                } catch (Exception $e) {
                    $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Database Error updating profile: " . $e->getMessage() . "</div>";
                }
            }
            header("Location: index.php?tab=profile");
            exit;
        }
    }

    // 1. Handle Admin Adding a New Product with File Upload / Drag & Drop
    if (isset($_POST['add_product'])) {
        if (!$isAdmin) {
            $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Unauthorized action.</div>";
        } else {
            $restaurantId = intval($_POST['restaurant_id']);
            $itemName = trim($_POST['item_name']);
            $category = trim($_POST['category']);
            $price = floatval($_POST['price']);
            $stock = intval($_POST['stock']);
            
            $imagePath = '';
            if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $fileExtension = pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
                $fileName = time() . '_' . uniqid() . '.' . $fileExtension;
                $targetFilePath = $uploadDir . $fileName;
                if (move_uploaded_file($_FILES['product_image']['tmp_name'], $targetFilePath)) {
                    $imagePath = $targetFilePath;
                }
            }

            if (!empty($itemName) && $price > 0 && $restaurantId > 0) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO food_items (restaurant_id, item_name, category, price, stock, image) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$restaurantId, $itemName, $category, $price, $stock, $imagePath]);
                    $_SESSION['flash_message'] = "<div class='alert success'>🎉 New product '{$itemName}' added successfully!</div>";
                } catch (Exception $e) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO food_items (restaurant_id, item_name, category, price, image) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$restaurantId, $itemName, $category, $price, $imagePath]);
                        $_SESSION['flash_message'] = "<div class='alert success'>🎉 New product '{$itemName}' added successfully! (Note: Stock column skipped)</div>";
                    } catch (Exception $ex) {
                        $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Database Error: " . $ex->getMessage() . "</div>";
                    }
                }
            } else {
                $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Please fill in all required fields with valid values.</div>";
            }
            header("Location: index.php?tab=inventory");
            exit;
        }
    }

    // 1.5 Handle Admin Updating Stock & Optional Image Replacement via Modal
    if (isset($_POST['update_stock'])) {
        if (!$isAdmin) {
            $message = "<div class='alert error'>⚠️ Unauthorized action.</div>";
        } else {
            $productId = intval($_POST['product_id']);
            $additionalStock = intval($_POST['additional_stock']);
            $updateMode = $_POST['update_mode'] ?? 'add'; 

            if ($productId > 0 && ($additionalStock > 0 || $updateMode === 'set')) {
                try {
                    $pStmt = $pdo->prepare("SELECT item_name, stock, image FROM food_items WHERE id = ?");
                    $pStmt->execute([$productId]);
                    $prod = $pStmt->fetch();

                    if ($prod) {
                        $currentStock = isset($prod['stock']) && $prod['stock'] !== '' ? intval($prod['stock']) : 0;
                        
                        if ($updateMode === 'set') {
                            $newStockTotal = $additionalStock;
                        } else {
                            $newStockTotal = $currentStock + $additionalStock;
                        }

                        // Check if a new image was uploaded in the modal
                        $imagePath = $prod['image'];
                        if (isset($_FILES['modal_product_image']) && $_FILES['modal_product_image']['error'] === UPLOAD_ERR_OK) {
                            $uploadDir = 'uploads/';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0777, true);
                            }
                            $fileExtension = pathinfo($_FILES['modal_product_image']['name'], PATHINFO_EXTENSION);
                            $fileName = time() . '_' . uniqid() . '.' . $fileExtension;
                            $targetFilePath = $uploadDir . $fileName;
                            if (move_uploaded_file($_FILES['modal_product_image']['tmp_name'], $targetFilePath)) {
                                $imagePath = $targetFilePath;
                            }
                        }

                        $upStmt = $pdo->prepare("UPDATE food_items SET stock = ?, image = ? WHERE id = ?");
                        $upStmt->execute([$newStockTotal, $imagePath, $productId]);

                        $_SESSION['flash_message'] = "<div class='alert success'>📦 Product '<strong>{$prod['item_name']}</strong>' updated successfully! Stock: <strong>{$newStockTotal}</strong>.</div>";
                    } else {
                        $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Selected product could not be found in the database.</div>";
                    }
                } catch (Exception $e) {
                    $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Database Error updating product: " . $e->getMessage() . "</div>";
                }
            } else {
                $_SESSION['flash_message'] = "<div class='alert error'>⚠️ Please select a valid product and quantity.</div>";
            }
            
            header("Location: index.php?tab=inventory");
            exit;
        }
    }

    // 2. Handle Customer Order Submission with Stock Validation, Delivery Address & Automatic Stock Deduction
    if (isset($_POST['place_order'])) {
        if (!$isLoggedIn || $isAdmin || $isRider) {
            $message = "<div class='alert error'>⚠️ Only registered customers can place orders.</div>";
        } else {
            $country = trim($_POST['country'] ?? '');
            $province = trim($_POST['province'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $zipcode = trim($_POST['zipcode'] ?? '');
            $specific = trim($_POST['specific_address'] ?? '');

            $addressParts = array_filter([$specific, $city, $province, $zipcode, $country]);
            $deliveryAddress = implode(', ', $addressParts);

            if (empty($specific) || empty($city)) {
                $message = "<div class='alert error'>⚠️ Please provide your specific location and city for delivery.</div>";
            } else {
                $addrStmt = $pdo->prepare("UPDATE users SET address = ? WHERE id = ?");
                $addrStmt->execute([$deliveryAddress, $currentUserId]);

                $order = new Order($currentUserId);
                $stockErrors = [];
                
                if (!empty($_POST['items'])) {
                    foreach ($_POST['items'] as $itemId => $qty) {
                        $qty = intval($qty);
                        if ($qty > 0) {
                            $stmt = $pdo->prepare("SELECT * FROM food_items WHERE id = ?");
                            $stmt->execute([$itemId]);
                            $row = $stmt->fetch();
                            
                            if ($row) {
                                $availableStock = isset($row['stock']) && $row['stock'] !== '' ? intval($row['stock']) : 20;
                                if ($qty > $availableStock) {
                                    $stockErrors[] = "⚠️ <strong>{$row['item_name']}</strong> is low on stock! You requested <strong>{$qty}</strong>, but there are only <strong>{$availableStock}</strong> left.";
                                }
                            }
                        }
                    }

                    if (!empty($stockErrors)) {
                        $errorHtml = implode("<br><br>", $stockErrors);
                        $message = "<div class='alert error'>{$errorHtml}</div>";
                    } else {
                        foreach ($_POST['items'] as $itemId => $qty) {
                            $qty = intval($qty);
                            if ($qty > 0) {
                                $stmt = $pdo->prepare("SELECT * FROM food_items WHERE id = ?");
                                $stmt->execute([$itemId]);
                                $row = $stmt->fetch();
                                if ($row) {
                                    $foodItem = new FoodItem($row['id'], $row['item_name'], $row['price'], $row['category'] ?? 'General');
                                    $order->addItem($foodItem, $qty);
                                }
                            }
                        }
                        
                        if ($order->getTotalAmount() > 0) {
                            $newOrderId = $order->saveToDatabase($pdo);

                            foreach ($_POST['items'] as $itemId => $qty) {
                                $qty = intval($qty);
                                if ($qty > 0) {
                                    $deductStmt = $pdo->prepare("UPDATE food_items SET stock = GREATEST(0, stock - ?) WHERE id = ?");
                                    $deductStmt->execute([$qty, $itemId]);
                                }
                            }

                            $_SESSION['flash_message'] = "<div class='alert success'>🎉 Order #{$newOrderId} placed successfully! Total: ₱" . number_format($order->getTotalAmount(), 2) . "</div>";
                            header("Location: index.php?tab=recent_orders");
                            exit;
                        } else {
                            $message = "<div class='alert error'>⚠️ Please select at least one item quantity greater than zero.</div>";
                        }
                    }
                }
            }
        }
    }
}

// Fetch Restaurants
$restaurants = $pdo->query("SELECT * FROM restaurants")->fetchAll();

// Fetch Customer Address & Profile Data if logged in
$customerAddress = "";
$currentUserData = [];
$parsedCountry = "Philippines";
$parsedProvince = "";
$parsedCity = "";
$parsedZipcode = "";
$parsedSpecific = "";

if ($isLoggedIn && !$isRider) {
    $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $uStmt->execute([$currentUserId]);
    $currentUserData = $uStmt->fetch();
    $customerAddress = $currentUserData['address'] ?? '';

    if (!empty($customerAddress)) {
        $parts = array_map('trim', explode(',', $customerAddress));
        $count = count($parts);
        if ($count >= 1) $parsedSpecific = $parts[0];
        if ($count >= 2) $parsedCity = $parts[1];
        if ($count >= 3) $parsedProvince = $parts[2];
        if ($count >= 4) $parsedZipcode = $parts[3];
        if ($count >= 5) $parsedCountry = $parts[4];
    }
}

// Fetch Data for Tabs
if ($isLoggedIn) {
    if ($isAdmin) {
        $recentOrders = $pdo->query("SELECT o.id, o.status, o.total_amount, o.created_at, u.name as customer_name, u.address as customer_address FROM orders o JOIN users u ON o.customer_id = u.id ORDER BY o.created_at DESC LIMIT 10")->fetchAll();
        $transactions = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC LIMIT 15")->fetchAll();
        $inventoryItems = $pdo->query("SELECT f.*, r.restaurant_name FROM food_items f JOIN restaurants r ON f.restaurant_id = r.id ORDER BY f.item_name ASC")->fetchAll();
    } elseif ($isRider) {
        $recentOrders = $pdo->query("SELECT o.id, o.status, o.total_amount, o.created_at, u.name as customer_name, u.address as customer_address FROM orders o JOIN users u ON o.customer_id = u.id ORDER BY o.created_at DESC LIMIT 10")->fetchAll();
        $transactions = [];
        $inventoryItems = [];
    } else {
        $recentStmt = $pdo->prepare("SELECT o.id, o.status, o.total_amount, o.created_at, u.name as customer_name, u.address as customer_address FROM orders o JOIN users u ON o.customer_id = u.id WHERE o.customer_id = ? ORDER BY o.created_at DESC LIMIT 10");
        $recentStmt->execute([$currentUserId]);
        $recentOrders = $recentStmt->fetchAll();

        $transStmt = $pdo->prepare("SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 15");
        $transStmt->execute([$currentUserId]);
        $transactions = $transStmt->fetchAll();
        $inventoryItems = [];
    }
} else {
    $recentOrders = [];
    $transactions = [];
    $inventoryItems = [];
}

// Fetch Deliveries for Rider View
if ($isAdmin) {
    $riderOrders = $pdo->query("SELECT o.id, o.status, o.total_amount, o.created_at, u.name as customer_name, u.email as customer_email, u.address as customer_address FROM orders o JOIN users u ON o.customer_id = u.id ORDER BY o.created_at DESC LIMIT 10")->fetchAll();
} elseif ($isRider) {
    $riderOrders = $pdo->query("SELECT o.id, o.status, o.total_amount, o.created_at, u.name as customer_name, u.email as customer_email, u.address as customer_address FROM orders o JOIN users u ON o.customer_id = u.id ORDER BY o.created_at DESC LIMIT 5")->fetchAll();
} elseif ($isLoggedIn) {
    $rStmt = $pdo->prepare("SELECT o.id, o.status, o.total_amount, o.created_at, u.name as customer_name, u.email as customer_email, u.address as customer_address FROM orders o JOIN users u ON o.customer_id = u.id WHERE o.customer_id = ? ORDER BY o.created_at DESC LIMIT 5");
    $rStmt->execute([$currentUserId]);
    $riderOrders = $rStmt->fetchAll();
} else {
    $riderOrders = [];
}

// Fetch registered riders list for Admin
$registeredRiders = [];
if ($isAdmin) {
    $riderQuery = $pdo->query("SELECT * FROM users WHERE role = 'Rider' ORDER BY id DESC");
    if ($riderQuery) {
        $registeredRiders = $riderQuery->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Food Delivery System</title>
    <!-- Leaflet CSS for Real Map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7f6; margin: 0; padding: 0; color: #333; display: flex; height: 100vh; overflow: hidden; }
        
        /* Left Sidebar Navigation */
        .sidebar { width: 260px; background: #2f3542; color: white; display: flex; flex-direction: column; justify-content: space-between; padding: 20px; box-shadow: 4px 0 10px rgba(0,0,0,0.05); }
        .sidebar-top h2 { font-size: 1.2em; color: #ff4757; margin-top: 0; margin-bottom: 30px; border-bottom: 1px solid #57606f; padding-bottom: 15px; display: flex; align-items: center; gap: 8px; }
        
        .nav-menu { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
        .nav-menu li a { display: flex; align-items: center; gap: 10px; color: #a4b0be; text-decoration: none; padding: 12px 15px; border-radius: 8px; font-weight: 600; font-size: 0.95em; transition: all 0.2s; }
        .nav-menu li a:hover, .nav-menu li a.active { background: #ff4757; color: white; }

        .sidebar-user { border-top: 1px solid #57606f; padding-top: 15px; display: flex; flex-direction: column; gap: 10px; }
        .user-info { font-size: 0.9em; color: #dfe4ea; font-weight: bold; }
        
        .sidebar-btn { background: #ff4757; color: white; border: none; padding: 10px; border-radius: 6px; cursor: pointer; font-weight: bold; text-align: center; text-decoration: none; display: block; transition: background 0.2s; font-size: 0.9em; }
        .sidebar-btn:hover { background: #ff6b81; }
        .sidebar-btn.logout { background: #2f3542; border: 1px solid #ff4757; color: #ff4757; }
        .sidebar-btn.logout:hover { background: #ff4757; color: white; }

        /* Main Content Area */
        .main-content { flex-grow: 1; padding: 30px; overflow-y: auto; height: 100vh; }
        .container { max-width: 1000px; margin: auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); position: relative; }
        
        h1 { color: #ff4757; margin-top: 0; font-size: 1.8em; }
        .alert { padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; line-height: 1.5; }
        .success { background: #eaffea; color: #2ed573; border: 1px solid #2ed573; }
        .error { background: #ffeaea; color: #ff4757; border: 1px solid #ff4757; }
        
        .restaurant-box { border: 1px solid #e1e1e1; padding: 20px; border-radius: 10px; margin-bottom: 30px; background: #fafafa; }
        .restaurant-box h3 { margin-top: 0; color: #2f3542; font-size: 1.3em; border-bottom: 2px solid #ff4757; padding-bottom: 8px; display: inline-block; }
        
        .food-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; margin-top: 15px; }
        .food-card { background: #fff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; transition: transform 0.2s; }
        .food-card:hover { transform: translateY(-3px); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .food-img { width: 100%; height: 130px; object-fit: cover; background: #eccc68; }
        .food-info { padding: 15px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; }
        .food-title { font-weight: bold; font-size: 1.05em; color: #2f3542; margin-bottom: 6px; }
        .food-price { color: #ff4757; font-weight: bold; font-size: 1em; margin-bottom: 6px; }
        .food-stock { font-size: 0.8em; color: #666; margin-bottom: 10px; font-weight: 600; }
        .food-action { display: flex; align-items: center; justify-content: space-between; }
        
        input[type="number"], input[type="text"], input[type="email"], input[type="password"], textarea, select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.95em; margin-top: 5px; background: #fff; }
        input[type="number"].qty-input { width: 60px; text-align: center; font-weight: bold; margin-top: 0; }
        
        .btn { background: #ff4757; color: white; border: none; padding: 14px 25px; font-size: 16px; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%; margin-top: 10px; transition: background 0.2s; }
        .btn:hover { background: #ff6b81; }

        /* Form Card for Admin */
        .admin-form-card { background: #f8f9fa; border: 1px solid #dee2e6; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; font-size: 0.9em; color: #2f3542; }

        /* Restock Product Cards Grid */
        .restock-card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; max-height: 280px; overflow-y: auto; padding: 5px; border: 1px solid #ddd; border-radius: 6px; background: #fff; margin-top: 5px; }
        .restock-item-card { border: 2px solid #e1e1e1; border-radius: 8px; padding: 10px; text-align: center; cursor: pointer; background: #fafafa; transition: all 0.2s; }
        .restock-item-card:hover { border-color: #0288d1; background: #e1f5fe; transform: translateY(-2px); }
        .restock-item-card.selected { border-color: #0288d1; background: #e1f5fe; box-shadow: 0 0 8px rgba(2, 136, 209, 0.4); }
        .restock-item-img { width: 100%; height: 90px; object-fit: cover; border-radius: 6px; background: #ddd; margin-bottom: 8px; }
        .restock-item-title { font-weight: bold; font-size: 0.9em; color: #2f3542; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .restock-item-meta { font-size: 0.8em; color: #666; }

        /* Drag & Drop Upload Zone Styles */
        .drop-zone { border: 2px dashed #0288d1; border-radius: 6px; padding: 20px; text-align: center; cursor: pointer; background: #f0f9ff; transition: all 0.2s; margin-top: 5px; }
        .drop-zone.dragover { border-color: #ff4757; background: #fff5f5; }
        .drop-zone-text { font-size: 0.9em; color: #0288d1; font-weight: 500; }
        .image-preview { max-height: 90px; margin-top: 8px; border-radius: 6px; display: none; margin-left: auto; margin-right: auto; border: 1px solid #ccc; }

        /* Rider & Map Styles */
        .rider-panel { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px; }
        .rider-info-card { background: #f8f9fa; border: 1px solid #dee2e6; padding: 20px; border-radius: 8px; }
        #leaflet-map { height: 320px; border-radius: 8px; border: 1px solid #ced4da; z-index: 1; }
        .map-legend { display: flex; gap: 15px; font-size: 0.85em; margin-top: 10px; font-weight: bold; }

        /* Tables & Reports */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-size: 0.95em; }
        th { background: #f1f2f6; color: #2f3542; }

        .badge { padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 0.85em; }
        .badge-success { background: #eaffea; color: #2ed573; }
        .badge-warning { background: #fff8e1; color: #fbc02d; }
        .badge-danger { background: #ffeaea; color: #ff4757; }
        .badge-info { background: #e1f5fe; color: #0288d1; }
        .badge-admin { background: #2f3542; color: #fff; }

        /* Custom Modern Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); z-index: 1000; justify-content: center; align-items: center; animation: fadeIn 0.2s ease-in-out; }
        .modal-box { background: #fff; width: 440px; padding: 25px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); text-align: center; position: relative; max-height: 90vh; overflow-y: auto; }
        .modal-title { font-size: 1.3em; font-weight: bold; color: #2f3542; margin-bottom: 10px; }
        .modal-text { font-size: 0.95em; color: #57606f; margin-bottom: 20px; line-height: 1.5; background: #f1f2f6; padding: 12px; border-radius: 6px; text-align: left; }
        .modal-buttons { display: flex; gap: 10px; }
        .modal-btn { flex: 1; padding: 10px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.95em; }
        .modal-btn.cancel { background: #dfe4ea; color: #2f3542; }
        .modal-btn.cancel:hover { background: #ced6e0; }
        .modal-btn.confirm { background: #0288d1; color: white; }
        .modal-btn.confirm:hover { background: #0277bd; }
        @keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
    </style>
    <!-- Leaflet JS Library -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        const locationData = {
            "Philippines": {
                "Cebu": {
                    "cities": {
                        "Bogo City": "6010",
                        "Cebu City": "6000",
                        "Mandaue City": "6014",
                        "Lapu-Lapu City": "6015",
                        "Talisay City": "6045",
                        "Danao City": "6004",
                        "Toledo City": "6038",
                        "San Remigio": "6011",
                        "Medellin": "6012",
                        "Daanbantayan": "6013"
                    }
                },
                "Metro Manila": {
                    "cities": {
                        "Manila": "1000",
                        "Quezon City": "1100",
                        "Makati": "1200",
                        "Taguig": "1630",
                        "Pasig": "1600"
                    }
                },
                "Davao del Sur": {
                    "cities": {
                        "Davao City": "8000",
                        "Digos City": "8002"
                    }
                }
            },
            "United States": {
                "California": {
                    "cities": {
                        "Los Angeles": "90001",
                        "San Francisco": "94102",
                        "San Diego": "92101"
                    }
                },
                "New York": {
                    "cities": {
                        "New York City": "10001",
                        "Buffalo": "14201"
                    }
                }
            }
        };

        // Phone number formatter: 11 digits, spaced as 4-3-4
        function formatPhoneNumber(input) {
            let digits = input.value.replace(/\D/g, '').substring(0, 11);
            let formatted = '';
            if (digits.length > 0) {
                formatted += digits.substring(0, 4);
            }
            if (digits.length > 4) {
                formatted += ' ' + digits.substring(4, 7);
            }
            if (digits.length > 7) {
                formatted += ' ' + digits.substring(7, 11);
            }
            input.value = formatted;
        }

        function updateProvinces(prefix = '', selectedProv = '', selectedCity = '', selectedZip = '') {
            const countrySelect = document.getElementById(prefix + 'country-select');
            const provinceSelect = document.getElementById(prefix + 'province-select');
            const citySelect = document.getElementById(prefix + 'city-select');
            const zipcodeInput = document.getElementById(prefix + 'zipcode-input');

            if (!countrySelect || !provinceSelect) return;

            const selectedCountry = countrySelect.value;

            provinceSelect.innerHTML = '<option value="">-- Select Province --</option>';
            if (citySelect) citySelect.innerHTML = '<option value="">-- Select City --</option>';
            if (zipcodeInput && !selectedZip) zipcodeInput.value = '';

            if (locationData[selectedCountry]) {
                for (let province in locationData[selectedCountry]) {
                    let opt = document.createElement('option');
                    opt.value = province;
                    opt.innerHTML = province;
                    if (province === selectedProv) {
                        opt.selected = true;
                    }
                    provinceSelect.appendChild(opt);
                }
            }

            if (selectedProv) {
                updateCities(prefix, selectedCity, selectedZip);
            }
        }

        function updateCities(prefix = '', selectedCity = '', selectedZip = '') {
            const countrySelect = document.getElementById(prefix + 'country-select');
            const provinceSelect = document.getElementById(prefix + 'province-select');
            const citySelect = document.getElementById(prefix + 'city-select');
            const zipcodeInput = document.getElementById(prefix + 'zipcode-input');

            if (!countrySelect || !provinceSelect || !citySelect) return;

            const selectedCountry = countrySelect.value;
            const selectedProvince = provinceSelect.value;

            citySelect.innerHTML = '<option value="">-- Select City --</option>';
            if (zipcodeInput && !selectedZip) zipcodeInput.value = '';

            if (locationData[selectedCountry] && locationData[selectedCountry][selectedProvince]) {
                const cities = locationData[selectedCountry][selectedProvince].cities;
                for (let city in cities) {
                    let opt = document.createElement('option');
                    opt.value = city;
                    opt.innerHTML = city;
                    if (city === selectedCity) {
                        opt.selected = true;
                    }
                    citySelect.appendChild(opt);
                }
            }

            if (selectedCity && selectedZip && zipcodeInput) {
                zipcodeInput.value = selectedZip;
            } else if (selectedCity) {
                updateZipCode(prefix);
            }
        }

        function updateZipCode(prefix = '') {
            const countrySelect = document.getElementById(prefix + 'country-select');
            const provinceSelect = document.getElementById(prefix + 'province-select');
            const citySelect = document.getElementById(prefix + 'city-select');
            const zipcodeInput = document.getElementById(prefix + 'zipcode-input');

            if (!countrySelect || !provinceSelect || !citySelect || !zipcodeInput) return;

            const selectedCountry = countrySelect.value;
            const selectedProvince = provinceSelect.value;
            const selectedCity = citySelect.value;

            if (
                locationData[selectedCountry] &&
                locationData[selectedCountry][selectedProvince] &&
                locationData[selectedCountry][selectedProvince].cities[selectedCity]
            ) {
                zipcodeInput.value = locationData[selectedCountry][selectedProvince].cities[selectedCity];
            } else {
                zipcodeInput.value = '';
            }
        }

        function confirmUnlockAddress(prefix = '') {
            const confirmed = confirm("⚠️ Do you really want to update your location?");
            if (confirmed) {
                document.getElementById(prefix + 'country-select').removeAttribute('disabled');
                document.getElementById(prefix + 'province-select').removeAttribute('disabled');
                document.getElementById(prefix + 'city-select').removeAttribute('disabled');
                document.getElementById(prefix + 'specific-address-input').removeAttribute('disabled');
                alert("🔒 Address fields are now unlocked for editing.");
            }
        }

        let selectedProductId = null;
        let selectedProductText = "";

        function selectRestockItem(cardElement, productId, productName, currentStock, restaurantName, category, price, imageUrl) {
            document.querySelectorAll('.restock-item-card').forEach(c => c.classList.remove('selected'));
            cardElement.classList.add('selected');

            selectedProductId = productId;
            selectedProductText = `${restaurantName} - ${productName} (Stock: ${currentStock})`;
            document.getElementById('hidden_product_id').value = productId;

            const imgEl = document.getElementById('modal-img-preview');
            imgEl.src = imageUrl ? imageUrl : 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=400';
            document.getElementById('modal-title-preview').innerText = productName;
            document.getElementById('modal-meta-preview').innerHTML = `<strong>Restaurant:</strong> ${restaurantName}<br><strong>Category:</strong> ${category || 'General'}<br><strong>Price:</strong> ₱${parseFloat(price).toFixed(2)}<br><strong>Current Stock:</strong> ${currentStock}`;
            
            // Reset modal file input & preview state
            document.getElementById('modal_product_image_input').value = '';
            document.getElementById('modal-img-change-hint').style.display = 'block';

            document.getElementById('restock-card-modal').style.display = 'flex';
        }

        function closeRestockModal() {
            document.getElementById('restock-card-modal').style.display = 'none';
        }

        function handleModalFileDrop(e) {
            e.preventDefault();
            document.getElementById('modal-image-drop-zone').classList.remove('dragover');
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                document.getElementById('modal_product_image_input').files = e.dataTransfer.files;
                displayModalImagePreview(e.dataTransfer.files[0]);
            }
        }

        function handleModalFileSelect(input) {
            if (input.files && input.files[0]) {
                displayModalImagePreview(input.files[0]);
            }
        }

        function displayModalImagePreview(file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('modal-img-preview');
                preview.src = e.target.result;
                document.getElementById('modal-img-change-hint').innerText = '✅ New image selected (Ready to update)';
            }
            reader.readAsDataURL(file);
        }

        function openFinalConfirmModal() {
            const amount = document.getElementById('modal_additional_stock').value;
            const modeElem = document.getElementById('modal_update_mode');
            const modeText = modeElem.options[modeElem.selectedIndex].text;

            if (!amount || amount <= 0) {
                alert("⚠️ Please enter a valid quantity.");
                return;
            }

            document.getElementById('final_additional_stock').value = amount;
            document.getElementById('final_update_mode').value = modeElem.value;

            document.getElementById('final-confirm-text').innerHTML = `
                Are you sure you want to <strong>${modeText}</strong> with quantity <strong>${amount}</strong> for:<br><br>
                <span style="color: #ff4757; font-weight: bold;">"${selectedProductText}"</span>?
            `;

            document.getElementById('restock-card-modal').style.display = 'none';
            document.getElementById('final-confirm-modal').style.display = 'flex';
        }

        function closeFinalConfirmModal() {
            document.getElementById('final-confirm-modal').style.display = 'none';
            document.getElementById('restock-card-modal').style.display = 'flex';
        }

        function executeFinalRestockSubmit() {
            document.getElementById('restock-action-form').submit();
        }

        function filterRestockProducts() {
            const query = document.getElementById('restock-search-input').value.toLowerCase();
            const cards = document.querySelectorAll('.restock-item-card');
            
            cards.forEach(card => {
                const name = card.getAttribute('data-name');
                const restaurant = card.getAttribute('data-restaurant');
                if (name.includes(query) || restaurant.includes(query)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function handleFileDrop(e) {
            e.preventDefault();
            document.getElementById('drop-zone').classList.remove('dragover');
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                document.getElementById('product_image_input').files = e.dataTransfer.files;
                displayImagePreview(e.dataTransfer.files[0]);
            }
        }

        function handleFileSelect(input) {
            if (input.files && input.files[0]) {
                displayImagePreview(input.files[0]);
            }
        }

        function displayImagePreview(file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('upload-preview');
                preview.src = e.target.result;
                preview.style.display = 'block';
                document.getElementById('drop-text').style.display = 'none';
            }
            reader.readAsDataURL(file);
        }

        document.addEventListener("DOMContentLoaded", function() {
            <?php if ($isLoggedIn && !$isAdmin && !$isRider): ?>
                updateProvinces('', '<?php echo addslashes($parsedProvince); ?>', '<?php echo addslashes($parsedCity); ?>', '<?php echo addslashes($parsedZipcode); ?>');
                updateProvinces('profile-', '<?php echo addslashes($parsedProvince); ?>', '<?php echo addslashes($parsedCity); ?>', '<?php echo addslashes($parsedZipcode); ?>');
            <?php endif; ?>

            const mapContainer = document.getElementById('leaflet-map');
            <?php if ($activeTab === 'rider_delivery'): ?>
            if (mapContainer) {
                let defaultLat = 10.3157;
                let defaultLng = 123.8854;

                const map = L.map('leaflet-map').setView([defaultLat, defaultLng], 14);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(map);

                const restaurantLatLng = [10.3201, 123.8782];
                const customerLatLng = [10.3112, 123.8921];

                const restaurantMarker = L.marker(restaurantLatLng).addTo(map)
                    .bindPopup("<b>🏪 Partner Restaurant</b><br>Pickup Point");

                const customerMarker = L.marker(customerLatLng).addTo(map)
                    .bindPopup("<b>📍 Customer Delivery Address</b><br><?php echo htmlspecialchars($riderOrders[0]['customer_address'] ?? 'No address specified'); ?>");

                let riderMarker = L.marker([defaultLat, defaultLng]).addTo(map)
                    .bindPopup("<b>🛵 Rider Device Location</b><br>Fetching current device GPS...");

                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        const riderLat = position.coords.latitude;
                        const riderLng = position.coords.longitude;
                        const riderLatLng = [riderLat, riderLng];

                        riderMarker.setLatLng(riderLatLng)
                            .setPopupContent("<b>🛵 Rider Device Location</b><br>Current Live GPS Position")
                            .openPopup();

                        map.setView(riderLatLng, 14);

                        L.polyline([restaurantLatLng, riderLatLng, customerLatLng], {
                            color: '#ff4757',
                            weight: 4,
                            opacity: 0.8,
                            dashArray: '6, 6'
                        }).addTo(map);

                        const group = L.featureGroup([restaurantMarker, riderMarker, customerMarker]);
                        map.fitBounds(group.getBounds().pad(0.2));
                    }, function(error) {
                        console.warn("Geolocation warning: " + error.message);
                        const riderLatLng = [10.3160, 123.8850];
                        riderMarker.setLatLng(riderLatLng);
                        L.polyline([restaurantLatLng, riderLatLng, customerLatLng], {
                            color: '#ff4757', weight: 4, opacity: 0.8, dashArray: '6, 6'
                        }).addTo(map);
                    });
                }
            }
            <?php endif; ?>
        });
    </script>
</head>
<body>

<!-- 1. Restock Details Modal with Clickable / Drag-and-Drop Image Replacement -->
<div id="restock-card-modal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-title">📦 Restock & Update Product</div>
        
        <div style="text-align: center; margin-bottom: 15px; background: #fdf2f2; padding: 12px; border-radius: 8px; border: 1px solid #ff4757;">
            <p style="margin: 0 0 8px 0; font-size: 0.85em; font-weight: bold; color: #ff4757;">Click or drop an image below to replace product photo:</p>
            
            <div id="modal-image-drop-zone" onclick="document.getElementById('modal_product_image_input').click()" ondragover="event.preventDefault(); this.classList.add('dragover');" ondragleave="this.classList.remove('dragover')" ondrop="handleModalFileDrop(event)" style="cursor: pointer; display: inline-block; position: relative;">
                <img id="modal-img-preview" src="" style="width: 100px; height: 100px; object-fit: cover; border-radius: 8px; border: 2px dashed #0288d1; display: block; margin: auto;">
                <input type="file" name="modal_product_image" id="modal_product_image_input" form="restock-action-form" accept="image/*" style="display:none;" onchange="handleModalFileSelect(this)">
            </div>
            <div id="modal-img-change-hint" style="font-size: 0.8em; color: #555; margin-top: 5px;">📷 Click image to update photo</div>
        </div>

        <div style="background: #f1f2f6; padding: 10px; border-radius: 6px; margin-bottom: 12px; text-align: left;">
            <div id="modal-title-preview" style="font-weight: bold; font-size: 1.05em; color: #2f3542;">-</div>
            <div id="modal-meta-preview" style="font-size: 0.85em; color: #57606f; margin-top: 4px;">-</div>
        </div>

        <div class="modal-text" style="background: #f1f2f6; margin-bottom: 15px;">
            <label style="display: block; font-weight: bold; margin-bottom: 5px; color: #2f3542;">Quantity to Add/Set:</label>
            <input type="number" id="modal_additional_stock" value="10" min="1" required style="margin-bottom: 10px;">
            
            <label style="display: block; font-weight: bold; margin-bottom: 5px; color: #2f3542;">Update Action:</label>
            <select id="modal_update_mode" style="margin-top: 0;">
                <option value="add">➕ Add to Current Stock</option>
                <option value="set">✏️ Set Exact Stock Amount</option>
            </select>
        </div>
        <div class="modal-buttons">
            <button type="button" class="modal-btn cancel" onclick="closeRestockModal()">Cancel</button>
            <button type="button" class="modal-btn confirm" onclick="openFinalConfirmModal()">Yes, Update Product</button>
        </div>
    </div>
</div>

<!-- 2. Final Security Confirmation Modal -->
<div id="final-confirm-modal" class="modal-overlay">
    <div class="modal-box" style="border-top: 5px solid #ff4757;">
        <div style="font-size: 2.5rem; margin-bottom: 10px;">⚠️</div>
        <div class="modal-title">Security Confirmation</div>
        <div id="final-confirm-text" class="modal-text" style="text-align: center; background: #fff5f5; border: 1px solid #ffccd5;">
            -
        </div>
        <div class="modal-buttons">
            <button type="button" class="modal-btn cancel" onclick="closeFinalConfirmModal()">Back</button>
            <button type="button" class="modal-btn confirm" style="background: #ff4757;" onclick="executeFinalRestockSubmit()">Confirm & Proceed</button>
        </div>
    </div>
</div>

<!-- Left Sidebar Navigation -->
<div class="sidebar">
    <div class="sidebar-top">
        <h2>🍔 FoodDelivery</h2>
        <ul class="nav-menu">
            <?php if ($isRider): ?>
                <li><a href="index.php?tab=rider_delivery" class="active">🚴 Delivery Rider Dashboard</a></li>
            <?php else: ?>
                <li><a href="index.php?tab=order" class="<?php echo ($activeTab === 'order') ? 'active' : ''; ?>">🏠 <?php echo $isAdmin ? 'Product Catalog' : 'Order Menu'; ?></a></li>
                <li><a href="index.php?tab=recent_orders" class="<?php echo ($activeTab === 'recent_orders') ? 'active' : ''; ?>">📜 Recent Orders</a></li>
                <li><a href="index.php?tab=transactions" class="<?php echo ($activeTab === 'transactions') ? 'active' : ''; ?>">💳 Transaction History</a></li>
                <li><a href="index.php?tab=rider_delivery" class="<?php echo ($activeTab === 'rider_delivery') ? 'active' : ''; ?>">🚴 Delivery Rider</a></li>
                <?php if ($isAdmin): ?>
                    <li><a href="index.php?tab=manage_riders" class="<?php echo ($activeTab === 'manage_riders') ? 'active' : ''; ?>">🛵 Create Rider</a></li>
                <?php endif; ?>
                <?php if ($isLoggedIn && !$isAdmin): ?>
                    <li><a href="index.php?tab=profile" class="<?php echo ($activeTab === 'profile') ? 'active' : ''; ?>">👤 My Profile</a></li>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <li><a href="index.php?tab=inventory" class="<?php echo ($activeTab === 'inventory') ? 'active' : ''; ?>">📦 Inventory & Reports</a></li>
                <?php endif; ?>
            <?php endif; ?>
        </ul>
    </div>

    <div class="sidebar-user">
        <?php if ($isLoggedIn): ?>
            <div class="user-info">👤 <?php echo htmlspecialchars($currentUserName); ?><br><span style="font-size: 0.8em; color: #ff4757;"><?php echo $currentUserRole; ?></span></div>
            <a href="logout.php" class="sidebar-btn logout">⎋ Logout</a>
        <?php else: ?>
            <a href="login.php" class="sidebar-btn">🔑 Login / Register</a>
        <?php endif; ?>
    </div>
</div>

<!-- Main Content Window -->
<div class="main-content">
    <div class="container">

        <?php echo $message; ?>

        <?php if ($activeTab === 'order' && !$isRider): ?>
            <!-- TAB 1: ORDER MENU / PRODUCT CATALOG -->
            <h1>🍽️ <?php echo $isAdmin ? 'Product Catalog (Admin View)' : 'Menu & Ordering'; ?></h1>
            <p><?php echo $isAdmin ? 'Viewing available dishes and items across partner restaurants.' : 'Welcome back! Select your preferred dishes from our partner restaurants below. Your saved delivery address is pre-filled securely.'; ?></p>

            <?php if ($isAdmin): ?>
                <div style="background: #e1f5fe; border: 1px solid #0288d1; color: #01579b; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold;">
                    ℹ️ Admin Mode: You are viewing products in read-only mode. To add products or manage stock, go to the <strong>Inventory & Reports</strong> tab.
                </div>
            <?php endif; ?>

            <form method="POST">
                <?php foreach ($restaurants as $rest): ?>
                    <div class="restaurant-box">
                        <h3>📍 <?php echo htmlspecialchars($rest['restaurant_name']); ?></h3>
                        
                        <?php
                        $catStmt = $pdo->prepare("SELECT DISTINCT category FROM food_items WHERE restaurant_id = ?");
                        $catStmt->execute([$rest['id']]);
                        $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
                        if (empty($categories)) { $categories = ['General']; }

                        foreach ($categories as $cat):
                        ?>
                            <h4 style="color: #ff4757; border-bottom: 1px dashed #ddd; padding-bottom: 5px; margin-top: 20px;">
                                🏷️ <?php echo htmlspecialchars($cat); ?>
                            </h4>

                            <div class="food-grid">
                                <?php
                                $itemStmt = $pdo->prepare("SELECT * FROM food_items WHERE restaurant_id = ? AND (category = ? OR category IS NULL)");
                                $itemStmt->execute([$rest['id'], $cat]);
                                $items = $itemStmt->fetchAll();
                                
                                foreach ($items as $item):
                                    $imgSrc = !empty($item['image']) ? htmlspecialchars($item['image']) : 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=400';
                                    $stockQty = isset($item['stock']) && $item['stock'] !== '' ? intval($item['stock']) : 20;
                                ?>
                                    <div class="food-card">
                                        <img src="<?php echo $imgSrc; ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>" class="food-img">
                                        <div class="food-info">
                                            <div>
                                                <div class="food-title"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                                <div class="food-price">₱<?php echo number_format($item['price'], 2); ?></div>
                                                <div class="food-stock">📦 Stock: <?php echo $stockQty; ?> left</div>
                                            </div>
                                            <div class="food-action">
                                                <?php if ($isAdmin): ?>
                                                    <span class="badge badge-admin">View Only</span>
                                                <?php else: ?>
                                                    <label style="font-size: 0.85em; color: #666;">Qty:</label>
                                                    <input type="number" class="qty-input" name="items[<?php echo $item['id']; ?>]" value="0" min="0" max="<?php echo $stockQty; ?>">
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($isLoggedIn && !$isAdmin): ?>
                    <div style="background: #f8f9fa; border: 1px solid #ced4da; padding: 20px; border-radius: 8px; margin-top: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ddd; padding-bottom: 8px; margin-bottom: 12px;">
                            <h3 style="margin: 0; color: #2f3542; font-size: 1.1em;">📍 Delivery Address Information</h3>
                            <button type="button" onclick="confirmUnlockAddress('')" style="background: #0288d1; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85em; font-weight: bold;">
                                ✏️ Change / Update Address
                            </button>
                        </div>
                        
                        <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                            <div>
                                <label style="display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542; font-size: 0.9em;">Country *</label>
                                <select name="country" id="country-select" onchange="updateProvinces('')" disabled required>
                                    <option value="Philippines" <?php echo ($parsedCountry === 'Philippines') ? 'selected' : ''; ?>>Philippines</option>
                                    <option value="United States" <?php echo ($parsedCountry === 'United States') ? 'selected' : ''; ?>>United States</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542; font-size: 0.9em;">Province / State *</label>
                                <select name="province" id="province-select" onchange="updateCities('')" disabled required>
                                    <option value="">-- Select Province --</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                            <div>
                                <label style="display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542; font-size: 0.9em;">City / Municipality *</label>
                                <select name="city" id="city-select" onchange="updateZipCode('')" disabled required>
                                    <option value="">-- Select City --</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542; font-size: 0.9em;">ZIP / Postal Code *</label>
                                <input type="text" name="zipcode" id="zipcode-input" value="<?php echo htmlspecialchars($parsedZipcode); ?>" placeholder="Auto-filled" readonly style="background-color: #e9ecef; font-weight: bold;" required>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542; font-size: 0.9em;">Specific Location / Street Address Description *</label>
                            <textarea name="specific_address" id="specific-address-input" rows="2" placeholder="House/Unit No., Street Name, Barangay, Landmark..." disabled required><?php echo htmlspecialchars($parsedSpecific); ?></textarea>
                        </div>
                    </div>
                    <button type="submit" name="place_order" class="btn">Confirm & Place Order</button>
                <?php elseif (!$isLoggedIn): ?>
                    <div style="text-align: center; padding: 20px; background: #fdf2f2; border: 1px dashed #ff4757; border-radius: 8px; margin-top: 20px;">
                        <p style="margin: 0; color: #ff4757; font-weight: bold;">🔒 Please <a href="login.php" style="color: #ff4757; text-decoration: underline;">log in</a> as a customer to place an order.</p>
                    </div>
                <?php endif; ?>
            </form>

        <?php elseif ($activeTab === 'recent_orders' && !$isRider): ?>
            <!-- TAB 2: RECENT ORDERS -->
            <h1>📜 Recent Orders Log</h1>
            <p>Review the latest order activities <?php echo $isAdmin ? 'across all users (Admin View)' : 'associated with your account'; ?>.</p>
            
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Delivery Address</th>
                        <th>Status</th>
                        <th>Total Amount</th>
                        <th>Date / Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentOrders)): ?>
                        <tr><td colspan="6" style="text-align: center; color: #777;">No recent orders found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentOrders as $ord): ?>
                            <tr>
                                <td>#<?php echo $ord['id']; ?></td>
                                <td><?php echo htmlspecialchars($ord['customer_name'] ?? 'Guest'); ?></td>
                                <td><?php echo htmlspecialchars($ord['customer_address'] ?? 'No address provided'); ?></td>
                                <td><span class="badge badge-info"><?php echo $ord['status']; ?></span></td>
                                <td>₱<?php echo number_format($ord['total_amount'], 2); ?></td>
                                <td><?php echo $ord['created_at']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        <?php elseif ($activeTab === 'transactions' && !$isRider): ?>
            <!-- TAB 3: TRANSACTION HISTORY -->
            <h1>💳 Transaction History</h1>
            <p>Comprehensive ledger of past financial transactions and invoice summaries.</p>
            
            <table>
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>Status</th>
                        <th>Amount Paid</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr><td colspan="4" style="text-align: center; color: #777;">No transaction records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                            <tr>
                                <td>TXN-<?php echo $tx['id']; ?></td>
                                <td><span class="badge badge-success">Completed</span></td>
                                <td>₱<?php echo number_format($tx['total_amount'], 2); ?></td>
                                <td><?php echo $tx['created_at']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        <?php elseif ($activeTab === 'rider_delivery'): ?>
            <!-- TAB 4: DELIVERY RIDER DASHBOARD -->
            <h1>🚴 Delivery Rider Dashboard</h1>
            <p><?php echo $isAdmin ? 'Admin View: All active deliveries across the system.' : 'Your active delivery queue and live route mapping.'; ?></p>

            <div class="rider-panel">
                <div class="rider-info-card">
                    <h3 style="margin-top: 0; color: #0288d1; border-bottom: 2px solid #0288d1; padding-bottom: 6px;">📋 Active Order Details</h3>
                    <?php if (empty($riderOrders)): ?>
                        <p style="color: #777;">No active deliveries currently assigned.</p>
                    <?php else: ?>
                        <?php $activeOrd = $riderOrders[0]; ?>
                        <p><strong>Order ID:</strong> #<?php echo $activeOrd['id']; ?></p>
                        <p><strong>Customer Name:</strong> <?php echo htmlspecialchars($activeOrd['customer_name']); ?></p>
                        <p><strong>Customer Email:</strong> <?php echo htmlspecialchars($activeOrd['customer_email']); ?></p>
                        <p><strong>Delivery Address:</strong> <span style="color: #d63031; font-weight: bold;"><?php echo htmlspecialchars($activeOrd['customer_address'] ?? 'No address specified'); ?></span></p>
                        <p><strong>Total Bill:</strong> ₱<?php echo number_format($activeOrd['total_amount'], 2); ?></p>
                        <p><strong>Order Status:</strong> <span class="badge badge-warning"><?php echo $activeOrd['status']; ?></span></p>
                        
                        <div style="background: #e1f5fe; padding: 12px; border-radius: 6px; margin-top: 15px; border: 1px solid #0288d1;">
                            <strong>🛵 Assigned Rider:</strong> <?php echo htmlspecialchars($currentUserName); ?><br>
                            <strong>Rider Status:</strong> Live Device Location Active
                        </div>
                    <?php endif; ?>
                </div>

                <div class="rider-info-card">
                    <h3 style="margin-top: 0; color: #ff4757; border-bottom: 2px solid #ff4757; padding-bottom: 6px;">🗺️ Live GPS Route Map</h3>
                    <div id="leaflet-map"></div>
                    <div class="map-legend">
                        <span>🏪 Restaurant Pin</span>
                        <span>🛵 Rider (Current Device GPS)</span>
                        <span>📍 Customer Address Pin</span>
                    </div>
                </div>
            </div>

            <h3 style="color: #2f3542; margin-top: 30px;"><?php echo $isAdmin ? 'All System Deliveries' : 'Assigned Deliveries Queue'; ?></h3>
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer Name</th>
                        <th>Delivery Address</th>
                        <th>Status</th>
                        <th>Amount</th>
                        <th>Assigned Rider</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($riderOrders)): ?>
                        <tr><td colspan="6" style="text-align: center; color: #777;">No active delivery records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($riderOrders as $ro): ?>
                            <tr>
                                <td>#<?php echo $ro['id']; ?></td>
                                <td><?php echo htmlspecialchars($ro['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($ro['customer_address'] ?? 'No address'); ?></td>
                                <td><span class="badge badge-info"><?php echo $ro['status']; ?></span></td>
                                <td>₱<?php echo number_format($ro['total_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($currentUserName); ?> (Assigned)</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        <?php elseif ($activeTab === 'manage_riders' && $isAdmin): ?>
            <!-- TAB 5: CREATE & MANAGE DELIVERY RIDERS (ADMIN ONLY) -->
            <h1>🛵 Create & Manage Delivery Riders</h1>
            <p>Register new delivery riders, assign login credentials with strict email & phone validation, and monitor active accounts.</p>

            <div class="admin-form-card" style="background: #f8f9fa; border-color: #0288d1;">
                <h3 style="margin-top: 0; color: #0288d1;">➕ Register New Delivery Rider</h3>
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="rider_name" placeholder="e.g. Alex Santos" required>
                        </div>
                        <div class="form-group">
                            <label>Email Address * (Must be valid format)</label>
                            <input type="email" name="rider_email" placeholder="rider@fooddelivery.com" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Phone Number * (11 digits: e.g. 0912 345 6789)</label>
                            <input type="text" name="rider_phone" placeholder="0912 345 6789" maxlength="13" oninput="formatPhoneNumber(this)" required>
                        </div>
                        <div class="form-group">
                            <label>Temporary Password *</label>
                            <input type="password" name="rider_password" placeholder="••••••••" required>
                        </div>
                    </div>
                    <button type="submit" name="create_rider" class="btn" style="background: #0288d1; margin-top: 10px;">Register Rider Account</button>
                </form>
            </div>

            <h3 style="color: #2f3542; margin-top: 30px;">Registered Delivery Riders</h3>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Role / Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($registeredRiders)): ?>
                        <tr><td colspan="5" style="text-align: center; color: #777;">No delivery riders registered yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($registeredRiders as $r): ?>
                            <tr>
                                <td>#<?php echo $r['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($r['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($r['email']); ?></td>
                                <td><?php echo htmlspecialchars($r['phone'] ?? 'N/A'); ?></td>
                                <td><span class="badge badge-success">Active Rider</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        <?php elseif ($activeTab === 'profile' && $isLoggedIn && !$isAdmin && !$isRider): ?>
            <!-- TAB 6: CUSTOMER PROFILE & DELIVERY INFO MANAGEMENT -->
            <h1>👤 My Profile & Delivery Information</h1>
            <p>Update your personal information, contact phone number (11 digits), and structured delivery address.</p>

            <form method="POST" style="background: #f8f9fa; border: 1px solid #ced4da; padding: 25px; border-radius: 8px; margin-top: 20px;">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542;">Full Name *</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($currentUserData['name'] ?? ''); ?>" required>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542;">Email Address (Read-only)</label>
                    <input type="email" value="<?php echo htmlspecialchars($currentUserData['email'] ?? ''); ?>" disabled style="background: #e9ecef; cursor: not-allowed;">
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542;">Cellphone / Phone Number (11 digits: e.g. 0912 345 6789)</label>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($currentUserData['phone'] ?? ''); ?>" placeholder="0912 345 6789" maxlength="13" oninput="formatPhoneNumber(this)" required>
                </div>

                <div style="background: #fff; border: 1px solid #e1e1e1; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ddd; padding-bottom: 8px; margin-bottom: 12px;">
                        <h3 style="margin: 0; font-size: 1.05em; color: #2f3542;">📍 Structured Delivery Address</h3>
                        <button type="button" onclick="confirmUnlockAddress('profile-')" style="background: #0288d1; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85em; font-weight: bold;">
                            ✏️ Change / Update Address
                        </button>
                    </div>
                    
                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542; font-size: 0.9em;">Country *</label>
                            <select name="country" id="profile-country-select" onchange="updateProvinces('profile-')" disabled required>
                                <option value="Philippines" <?php echo ($parsedCountry === 'Philippines') ? 'selected' : ''; ?>>Philippines</option>
                                <option value="United States" <?php echo ($parsedCountry === 'United States') ? 'selected' : ''; ?>>United States</option>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542; font-size: 0.9em;">Province / State *</label>
                            <select name="province" id="profile-province-select" onchange="updateCities('profile-')" disabled required>
                                <option value="">-- Select Province --</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542; font-size: 0.9em;">City / Municipality *</label>
                            <select name="city" id="profile-city-select" onchange="updateZipCode('profile-')" disabled required>
                                <option value="">-- Select City --</option>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542; font-size: 0.9em;">ZIP / Postal Code *</label>
                            <input type="text" name="zipcode" id="profile-zipcode-input" value="<?php echo htmlspecialchars($parsedZipcode); ?>" placeholder="Auto-filled" readonly style="background-color: #e9ecef; font-weight: bold;" required>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="display: block; font-weight: bold; margin-bottom: 6px; color: #2f3542; font-size: 0.9em;">Specific Location / Street Address Description *</label>
                        <textarea name="specific_address" id="profile-specific-address-input" rows="3" placeholder="House/Unit No., Street Name, Barangay, Landmark..." disabled required><?php echo htmlspecialchars($parsedSpecific); ?></textarea>
                    </div>
                </div>

                <button type="submit" name="update_profile" class="btn">Save Profile Changes</button>
            </form>

        <?php elseif ($activeTab === 'inventory' && $isAdmin): ?>
            <!-- TAB 7: INVENTORY, STOCK REPORTS & PRODUCT MANAGEMENT -->
            <h1>📦 Inventory & Product Management</h1>
            <p>Add new products, restock existing items, update product images, and monitor stock levels.</p>

            <form id="restock-action-form" method="POST" enctype="multipart/form-data" style="display:none;">
                <input type="hidden" name="update_stock" value="1">
                <input type="hidden" name="product_id" id="hidden_product_id">
                <input type="hidden" name="additional_stock" id="final_additional_stock">
                <input type="hidden" name="update_mode" id="final_update_mode">
            </form>

            <div class="admin-form-card" style="background: #e8f4fd; border-color: #bbeecc;">
                <h3 style="margin-top: 0; color: #0288d1;">🔄 Restock & Update Product Card / Image</h3>
                <p style="font-size: 0.9em; color: #555; margin-bottom: 8px;">Search or click on any product card below to open the update modal (where you can adjust stock or replace product photos).</p>
                
                <input type="text" id="restock-search-input" placeholder="🔍 Search product name or restaurant..." onkeyup="filterRestockProducts()" style="margin-bottom: 12px; background: #fff;">

                <div class="restock-card-grid" id="restock-grid-container">
                    <?php 
                    $allProds = $pdo->query("SELECT f.*, r.restaurant_name FROM food_items f JOIN restaurants r ON f.restaurant_id = r.id ORDER BY f.item_name ASC")->fetchAll();
                    if (empty($allProds)) {
                        echo '<p style="grid-column: 1/-1; text-align: center; color: #777; padding: 20px;">No products available for restock.</p>';
                    } else {
                        foreach ($allProds as $p) {
                            $currStock = isset($p['stock']) && $p['stock'] !== '' ? intval($p['stock']) : 0;
                            $pImg = !empty($p['image']) ? htmlspecialchars($p['image']) : '';
                            $safeName = htmlspecialchars($p['item_name'], ENT_QUOTES);
                            $safeRest = htmlspecialchars($p['restaurant_name'], ENT_QUOTES);
                            $safeCat = htmlspecialchars($p['category'] ?? 'General', ENT_QUOTES);
                            echo '<div class="restock-item-card" data-name="' . strtolower($safeName) . '" data-restaurant="' . strtolower($safeRest) . '" onclick="selectRestockItem(this, ' . $p['id'] . ', \'' . $safeName . '\', ' . $currStock . ', \'' . $safeRest . '\', \'' . $safeCat . '\', ' . $p['price'] . ', \'' . $pImg . '\')">';
                            echo '<img src="' . ($pImg ? $pImg : 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=400') . '" class="restock-item-img">';
                            echo '<div class="restock-item-title" title="' . $safeName . '">' . $safeName . '</div>';
                            echo '<div class="restock-item-meta">' . $safeRest . '<br><strong>Stock: ' . $currStock . '</strong></div>';
                            echo '</div>';
                        }
                    }
                    ?>
                </div>
            </div>

            <div class="admin-form-card">
                <h3 style="margin-top: 0; color: #ff4757;">➕ Add Brand New Product</h3>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Select Restaurant:</label>
                            <select name="restaurant_id" required>
                                <option value="">-- Select Restaurant --</option>
                                <?php foreach ($restaurants as $r): ?>
                                    <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['restaurant_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Item Name:</label>
                            <input type="text" name="item_name" placeholder="e.g. Cheeseburger" required>
                        </div>
                        <div class="form-group">
                            <label>Category:</label>
                            <input type="text" name="category" placeholder="e.g. Burgers, Drinks, Desserts" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Price (₱):</label>
                            <input type="number" step="0.01" name="price" placeholder="150.00" required>
                        </div>
                        <div class="form-group">
                            <label>Initial Stock Quantity:</label>
                            <input type="number" name="stock" value="20" min="0" required>
                        </div>
                        <div class="form-group">
                            <label>Product Image (Drag & Drop or Select):</label>
                            <div class="drop-zone" id="drop-zone" onclick="document.getElementById('product_image_input').click()" ondragover="event.preventDefault(); this.classList.add('dragover');" ondragleave="this.classList.remove('dragover')" ondrop="handleFileDrop(event)">
                                <span class="drop-zone-text" id="drop-text">📁 Drag & drop image here or <u>browse device</u></span>
                                <input type="file" name="product_image" id="product_image_input" accept="image/*" style="display:none;" onchange="handleFileSelect(this)">
                                <img id="upload-preview" class="image-preview">
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="add_product" class="btn" style="margin-top: 15px;">Save & Add Product</button>
                </form>
            </div>
            
            <h3 style="color: #2f3542; margin-top: 30px;">Current Stock Reports</h3>
            <table>
                <thead>
                    <tr>
                        <th>Item ID</th>
                        <th>Restaurant</th>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inventoryItems)): ?>
                        <tr><td colspan="6" style="text-align: center; color: #777;">No inventory items registered.</td></tr>
                    <?php else: ?>
                        <?php foreach ($inventoryItems as $inv): 
                            $stockCount = isset($inv['stock']) && $inv['stock'] !== '' ? intval($inv['stock']) : 0; 
                            if ($stockCount == 0) {
                                $badgeClass = 'badge-danger';
                                $statusText = '🔴 Out of Stock';
                            } elseif ($stockCount <= 5) {
                                $badgeClass = 'badge-warning';
                                $statusText = '⚠️ Low Stock (' . $stockCount . ')';
                            } else {
                                $badgeClass = 'badge-success';
                                $statusText = '🟢 In Stock (' . $stockCount . ')';
                            }
                        ?>
                            <tr>
                                <td>#<?php echo $inv['id']; ?></td>
                                <td><?php echo htmlspecialchars($inv['restaurant_name']); ?></td>
                                <td><strong><?php echo htmlspecialchars($inv['item_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($inv['category'] ?? 'General'); ?></td>
                                <td>₱<?php echo number_format($inv['price'], 2); ?></td>
                                <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $statusText; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
