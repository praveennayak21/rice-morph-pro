<?php
session_start();

// Load database configuration from config.php (NOT uploaded to GitHub)
require_once 'config.php';

// Attempt to connect to MySQL
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
$conn->select_db(DB_NAME);

// Create tables
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('farmer', 'researcher', 'sales', 'admin') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    product VARCHAR(100) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    purchase_date DATE NOT NULL
)");

$conn->query("CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product VARCHAR(100) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    sale_date DATE NOT NULL
)");

$sql_create_analysis_results = "CREATE TABLE IF NOT EXISTS analysis_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    total_grains INT,
    avg_length DECIMAL(10,2),
    avg_width DECIMAL(10,2),
    grain_color VARCHAR(50),
    grade VARCHAR(10),
    broken_grains DECIMAL(5,2),
    chalky_grains DECIMAL(5,2),
    immature_grains DECIMAL(5,2),
    color_consistency DECIMAL(5,2),
    original_image_data LONGTEXT,   -- Added column for original image data (base64)
    processed_image_data LONGTEXT,  -- Added column for processed image data (base64)
    analysis_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";

if ($conn->query($sql_create_analysis_results) === TRUE) {
    // echo "Table 'analysis_results' created successfully or already exists.<br>"; // Commented out for cleaner output
} else {
    error_log("Error creating table 'analysis_results': " . $conn->error); // Log error instead of echoing
    // echo "Error creating table 'analysis_results': " . $conn->error . "<br>"; // Commented out for cleaner output
}

// Create admin user if not exists
$result = $conn->query("SELECT COUNT(*) as count FROM users");
$row = $result->fetch_assoc();
if ($row['count'] == 0) {
    $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
    $conn->query("INSERT INTO users (name, email, password, role)
                 VALUES ('Admin User', 'admin@example.com', '$hashed_password', 'admin')");
}

// Handle saving analysis results
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_analysis_results'])) {
    if (isset($_SESSION['user'])) {
        $userId = $_SESSION['user']['id'];

        // Get analysis data from POST request, using null coalescing operator for safety
        $total_grains = $_POST['total_grains'] ?? null;
        $avg_length = $_POST['avg_length'] ?? null;
        $avg_width = $_POST['avg_width'] ?? null;
        $grain_color = $_POST['grain_color'] ?? null;
        $grade = $_POST['grade'] ?? null;
        $broken_grains = $_POST['broken_grains'] ?? null;
        $chalky_grains = $_POST['chalky_grains'] ?? null;
        $immature_grains = $_POST['immature_grains'] ?? null;
        $color_consistency = $_POST['color_consistency'] ?? null;
        $original_image_data = $_POST['original_image_data'] ?? null; // Get original image data
        $processed_image_data = $_POST['processed_image_data'] ?? null; // Get processed image data

        // Prepare an INSERT statement to prevent SQL injection, now including image data columns
        $stmt = $conn->prepare("INSERT INTO analysis_results (
            user_id, total_grains, avg_length, avg_width, grain_color, grade,
            broken_grains, chalky_grains, immature_grains, color_consistency,
            original_image_data, processed_image_data
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // Bind parameters (s = string, i = integer, d = double)
        // Ensure data types match your database schema - added 'ss' for the two new LONGTEXT columns
        $stmt->bind_param("iisdssddddss",
            $userId,
            $total_grains,
            $avg_length,
            $avg_width,
            $grain_color,
            $grade,
            $broken_grains,
            $chalky_grains,
            $immature_grains,
            $color_consistency,
            $original_image_data,    // Bind original image data
            $processed_image_data    // Bind processed image data
        );

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Analysis results saved successfully.']);
        } else {
            error_log("Error saving analysis results: " . $stmt->error); // Log the actual error
            echo json_encode(['status' => 'error', 'message' => 'Failed to save analysis results.', 'db_error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'User not logged in.']);
    }
    // Important: Exit after sending JSON response to prevent rendering the whole HTML page
    exit;
}

// Initialize current user
$currentUser = null;
$loginError = ''; // Initialize error message variable

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';

    // Basic validation
    if (empty($email) || empty($password) || empty($role)) {
        $loginError = "All fields are required.";
    } else {
        $stmt = $conn->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            // Check password and role
            if (password_verify($password, $user['password']) && $user['role'] === $role) {
                $_SESSION['user'] = $user;
                $currentUser = $user;
                // Redirect to prevent form re-submission on refresh
                header("Location: ".$_SERVER['PHP_SELF']);
                exit;
            } else {
                $loginError = "Invalid email, password, or role selection.";
            }
        } else {
            $loginError = "No user found with that email.";
        }
    }
}

// Handle add user (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    // Ensure only admin can add users
    if (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin') {
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $password_raw = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';

        if (!empty($name) && !empty($email) && !empty($password_raw) && !empty($role)) {
            $hashed_password = password_hash($password_raw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $name, $email, $hashed_password, $role);
            if ($stmt->execute()) {
                // Success
                header("Location: ".$_SERVER['PHP_SELF']."?user_added=1");
                exit;
            } else {
                // Handle error, e.g., duplicate email
                $addUserError = "Failed to add user. Email might already exist.";
            }
        } else {
            $addUserError = "All fields are required for adding a user.";
        }
    } else {
        // Not authorized
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

// Handle purchase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purchase'])) {
    if (isset($_SESSION['user'])) {
        $customerId = $_SESSION['user']['id'];
        $product = $_POST['product'] ?? '';
        $quantity = $_POST['quantity'] ?? 0;
        $price = $_POST['price'] ?? 0;
        $purchaseDate = date('Y-m-d');
        $total = (float)$quantity * (float)$price;

        if (!empty($product) && $quantity > 0 && $price > 0) {
            $stmt = $conn->prepare("INSERT INTO purchases (customer_id, product, quantity, price, total, purchase_date) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isddds", $customerId, $product, $quantity, $price, $total, $purchaseDate);
            $stmt->execute();
            header("Location: ".$_SERVER['PHP_SELF']);
            exit;
        }
    }
}

// Handle sale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sale'])) {
    if (isset($_SESSION['user'])) {
        $userId = $_SESSION['user']['id'];
        $product = $_POST['product'] ?? '';
        $quantity = $_POST['quantity'] ?? 0;
        $price = $_POST['price'] ?? 0;
        $saleDate = date('Y-m-d');
        $total = (float)$quantity * (float)$price;

        if (!empty($product) && $quantity > 0 && $price > 0) {
            $stmt = $conn->prepare("INSERT INTO sales (user_id, product, quantity, price, total, sale_date) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isddds", $userId, $product, $quantity, $price, $total, $saleDate);
            $stmt->execute();
            header("Location: ".$_SERVER['PHP_SELF']);
            exit;
        }
    }
}

// Get current user from session
if (isset($_SESSION['user'])) {
    $currentUser = $_SESSION['user'];
}

// Get all users for admin
$users = [];
if (isset($currentUser) && $currentUser['role'] === 'admin') {
    $result = $conn->query("SELECT * FROM users");
    $users = $result->fetch_all(MYSQLI_ASSOC);
}

// Get all purchases for current user (or all for admin/sales if needed, adjust logic)
$purchases = [];
if (isset($currentUser)) {
    $userId = (int)$currentUser['id'];
    // For sales/admin, you might want to show all purchases/sales or specific ones
    // For now, it shows current user's purchases/sales
    $result = $conn->query("SELECT * FROM purchases WHERE customer_id = $userId ORDER BY purchase_date DESC");
    $purchases = $result->fetch_all(MYSQLI_ASSOC);
}

// Get all sales for current user (or all for admin/sales if needed, adjust logic)
$sales = [];
if (isset($currentUser)) {
    $userId = (int)$currentUser['id'];
    $result = $conn->query("SELECT * FROM sales WHERE user_id = $userId ORDER BY sale_date DESC");
    $sales = $result->fetch_all(MYSQLI_ASSOC);
}

// Fetch analysis results for the current user
$analysisResults = [];
if (isset($currentUser)) {
    $userId = (int)$currentUser['id'];
    $stmt = $conn->prepare("SELECT id, total_grains, avg_length, avg_width, grain_color, grade, broken_grains, chalky_grains, immature_grains, color_consistency, analysis_date, original_image_data, processed_image_data FROM analysis_results WHERE user_id = ? ORDER BY analysis_date DESC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $analysisResults = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}


// Close connection (Moved to the end, after all data fetching)
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RiceMorph Pro - PHP Edition</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    /* CSS Styles */
    :root {
      --primary: #1a5d3f;
      --primary-light: #2a7d5f;
      --secondary: #16a34a;
      --danger: #ef4444;
      --warning: #facc15;
      --success: #16a34a;
      --light: #f9f9f9;
      --dark: #333;
      --gray: #e0e0e0;
      --text: #333;
      --card-shadow: 0 4px 10px rgba(0,0,0,0.05);
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    body {
      background: var(--light);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      transition: background 0.3s ease;
    }

    /* Login Page Styles */
    #login-page {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 2rem;
      background: linear-gradient(135deg, #1a5d3f 0%, #2a7d5f 100%);
      background-size: cover;
      background-position: center;
    }

    .login-container {
      background: white;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.15);
      width: 100%;
      max-width: 450px;
      padding: 2.5rem;
      text-align: center;
      transform: translateY(0);
      animation: fadeIn 0.6s ease;
      position: relative;
      overflow: hidden;
    }

    .login-container::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 6px;
      background: linear-gradient(90deg, #1a5d3f, #2a7d5f, #16a34a);
    }

    .app-logo {
      width: 120px;
      height: 120px;
      background: linear-gradient(135deg, #1a5d3f, #2a7d5f);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 1.5rem;
      box-shadow: 0 5px 15px rgba(0,0,0,0.1);
      animation: pulse 2s infinite;
    }

    @keyframes pulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.05); }
      100% { transform: scale(1); }
    }

    .app-logo i {
      font-size: 4rem;
      color: white;
    }

    .login-title {
      color: var(--primary);
      margin-bottom: 0.5rem;
      font-size: 2.2rem;
      font-weight: 700;
    }

    .login-subtitle {
      color: #666;
      margin-bottom: 2rem;
      font-size: 1.1rem;
    }

    .login-form {
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
    }

    .input-group {
      position: relative;
    }

    .input-group i {
      position: absolute;
      left: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: #777;
      transition: color 0.3s ease;
    }

    .form-input {
      width: 100%;
      padding: 14px 14px 14px 45px;
      border: 2px solid #ddd;
      border-radius: 8px;
      font-size: 1rem;
      transition: all 0.3s ease;
      background: #f9f9f9;
    }

    .form-input:focus {
      border-color: var(--primary);
      outline: none;
      box-shadow: 0 0 0 3px rgba(26, 93, 63, 0.2);
      background: white;
    }

    .form-input:focus + i {
      color: var(--primary);
    }

    .role-select {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 0.8rem;
      margin-bottom: 0.5rem;
    }

    .role-option {
      background: #f5f5f5;
      border: 2px solid #ddd;
      border-radius: 8px;
      padding: 12px 5px;
      cursor: pointer;
      transition: all 0.2s ease;
      text-align: center;
      font-weight: 500;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .role-option:hover {
      background: #eaf5ef;
      transform: translateY(-3px);
      box-shadow: 0 3px 8px rgba(0,0,0,0.1);
    }

    .role-option.selected {
      background: var(--primary);
      color: white;
      border-color: var(--primary);
    }

    .role-option i {
      display: block;
      font-size: 1.8rem;
      margin-bottom: 8px;
    }

    .login-btn {
      background: var(--primary);
      color: white;
      border: none;
      padding: 14px;
      border-radius: 8px;
      font-size: 1.1rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      margin-top: 0.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
    }

    .login-btn:hover {
      background: var(--primary-light);
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(26, 93, 63, 0.3);
    }

    .login-footer {
      margin-top: 1.5rem;
      color: #777;
      font-size: 0.9rem;
    }

    .form-options {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: -0.5rem;
      margin-bottom: 0.5rem;
      font-size: 0.9rem;
    }

    .remember-label {
      display: flex;
      align-items: center;
      cursor: pointer;
      gap: 8px;
    }

    .forgot-link {
      color: var(--primary);
      text-decoration: none;
      transition: color 0.3s ease;
    }

    .forgot-link:hover {
      text-decoration: underline;
      color: var(--primary-light);
    }

    /* Dashboard Page Styles */
    #dashboard-page {
      display: none;
      flex-direction: column;
      min-height: 100vh;
    }

    header {
      background: var(--primary);
      color: white;
      padding: 1.2rem 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      position: sticky;
      top: 0;
      z-index: 100;
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .logo-mini {
      width: 40px;
      height: 40px;
      background: rgba(255,255,255,0.2);
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .logo-mini i {
      font-size: 1.5rem;
    }

    header h1 {
      margin: 0;
      font-size: 1.5rem;
      font-weight: 600;
    }

    .user-info {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .user-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: #2a7d5f;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      color: white;
    }

    .user-details {
      text-align: right;
    }

    .user-name {
      font-weight: 500;
    }

    .user-role {
      font-size: 0.85rem;
      opacity: 0.9;
    }

    .logout-btn {
      background: var(--danger);
      border: none;
      color: white;
      padding: 0.5rem 1.2rem;
      border-radius: 5px;
      cursor: pointer;
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .logout-btn:hover {
      background: #b91c1c;
      transform: translateY(-2px);
      box-shadow: 0 3px 8px rgba(239, 68, 68, 0.3);
    }

    .main-content {
      flex: 1;
      padding: 2rem;
      max-width: 1400px;
      margin: 0 auto;
      width: 100%;
    }

    .welcome-section {
      background: white;
      border-radius: 12px;
      padding: 1.8rem;
      box-shadow: var(--card-shadow);
      margin-bottom: 2rem;
      position: relative;
      overflow: hidden;
    }

    .welcome-section::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 5px;
      height: 100%;
      background: var(--primary);
    }

    .welcome-section h2 {
      color: var(--primary);
      margin-bottom: 0.5rem;
    }

    .role-indicator {
      display: inline-block;
      background: rgba(26, 93, 63, 0.1);
      color: var(--primary);
      padding: 0.3rem 0.8rem;
      border-radius: 20px;
      font-size: 0.9rem;
      font-weight: 500;
      margin-top: 0.5rem;
    }

    .dashboard-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 2rem;
      margin-bottom: 2rem;
    }

    .card {
      background: white;
      border-radius: 12px;
      padding: 1.5rem;
      box-shadow: var(--card-shadow);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    }

    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      padding-bottom: 0.8rem;
      border-bottom: 1px solid #eee;
    }

    .card-title {
      font-size: 1.2rem;
      font-weight: 600;
      color: var(--primary);
    }

    .card-actions {
      display: flex;
      gap: 0.5rem;
    }

    .action-btn {
      background: #f5f5f5;
      border: none;
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .action-btn:hover {
      background: #e0e0e0;
    }

    .upload-area {
      border: 2px dashed #ddd;
      border-radius: 10px;
      padding: 2rem;
      text-align: center;
      margin-bottom: 1.5rem;
      transition: all 0.3s ease;
      cursor: pointer;
      position: relative;
    }

    .upload-area:hover, .upload-area.drag-over {
      border-color: var(--primary);
      background: rgba(26, 93, 63, 0.03);
    }

    .upload-icon {
      font-size: 3rem;
      color: #bbb;
      margin-bottom: 1rem;
    }

    .upload-text {
      margin-bottom: 1rem;
      color: #666;
    }

    .upload-btn {
      background: var(--primary);
      color: white;
      border: none;
      padding: 0.6rem 1.5rem;
      border-radius: 6px;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .upload-btn:hover {
      background: var(--primary-light);
    }

    .file-input {
      display: none;
    }

    .image-preview {
      display: flex;
      gap: 1.5rem;
      margin: 1.5rem 0;
      flex-wrap: wrap;
    }

    .preview-box {
      flex: 1;
      min-width: 250px;
    }

    .preview-title {
      font-size: 0.9rem;
      color: #666;
      margin-bottom: 0.5rem;
    }

    .preview-img {
      width: 100%;
      border-radius: 8px;
      border: 1px solid #eee;
      display: none;
    }

    .metrics-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 1rem;
    }

    .metrics-table th,
    .metrics-table td {
      padding: 0.9rem;
      text-align: left;
    }

    .metrics-table th {
      background: #f8faf9;
      font-weight: 500;
      color: #555;
    }

    .metrics-table tr:nth-child(even) {
      background: #fbfdfc;
    }

    .metrics-table td {
      font-weight: 500;
    }

    .grade-a {
      color: var(--success);
      font-weight: bold;
    }

    .grade-b {
      color: var(--warning);
      font-weight: bold;
    }

    .grade-c {
      color: var(--danger);
      font-weight: bold;
    }

    .charts-container {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 1.5rem;
      margin-top: 1.5rem;
    }

    .chart-card {
      background: white;
      padding: 1.2rem;
      border-radius: 12px;
      box-shadow: var(--card-shadow);
    }

    .chart-title {
      font-size: 1rem;
      color: #555;
      margin-bottom: 1rem;
      text-align: center;
      font-weight: 500;
    }

    .chart-container {
      position: relative;
      height: 250px;
    }

    .research-data {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 1.5rem;
      margin-top: 1.5rem;
    }

    .data-card {
      background: white;
      border-radius: 12px;
      padding: 1.5rem;
      box-shadow: var(--card-shadow);
    }

    .sales-stats {
      display: grid;
      grid-template-columns: repeat(3, 1fr)); /* Corrected to 3 columns */
      gap: 1.5rem;
      margin-top: 1.5rem;
    }

    .stat-card {
      background: white;
      border-radius: 12px;
      padding: 1.5rem;
      box-shadow: var(--card-shadow);
      text-align: center;
      transition: transform 0.3s ease;
    }

    .stat-card:hover {
      transform: translateY(-3px);
    }

    .stat-value {
      font-size: 2.5rem;
      font-weight: 600;
      color: var(--primary);
      margin: 1rem 0;
    }

    .stat-label {
      color: #666;
      font-size: 0.95rem;
    }

    footer {
      background: #f1f1f1;
      padding: 1.5rem;
      text-align: center;
      font-size: 0.9rem;
      color: #555;
      margin-top: auto;
    }

    /* Animations */
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .fade-in {
      animation: fadeIn 0.5s ease forwards;
    }

    /* New styles for analysis status */
    .analysis-status {
      padding: 1rem;
      border-radius: 8px;
      margin: 1rem 0;
      text-align: center;
      display: none;
    }

    .status-processing {
      background: rgba(250, 204, 21, 0.1);
      color: #ca8a04;
      border: 1px solid rgba(250, 204, 21, 0.3);
    }

    .status-error {
      background: rgba(239, 68, 68, 0.1);
      color: #b91c1c;
      border: 1px solid rgba(239, 68, 68, 0.3);
    }

    .status-success {
      background: rgba(22, 163, 74, 0.1);
      color: #15803d;
      border: 1px solid rgba(22, 163, 74, 0.3);
    }

    /* Responsive */
    @media (max-width: 768px) {
      .login-container {
        padding: 1.8rem;
      }

      .role-select {
        grid-template-columns: 1fr 1fr;
      }

      .sales-stats {
        grid-template-columns: 1fr;
      }

      .image-preview {
        flex-direction: column;
      }

      .header-left h1 {
        font-size: 1.2rem;
      }

      .user-info {
        flex-direction: column;
        align-items: flex-end;
      }

      .user-details {
        text-align: right;
      }
    }

    /* Error message display */
    .error-message {
        color: var(--danger);
        background-color: rgba(239, 68, 68, 0.1);
        border: 1px solid var(--danger);
        padding: 10px;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        font-size: 0.95rem;
        display: <?php echo !empty($loginError) ? 'block' : 'none'; ?>;
    }
    .image-thumbnail {
        width: 80px; /* Small size for table */
        height: 80px;
        object-fit: contain; /* Maintain aspect ratio */
        border-radius: 4px;
        border: 1px solid #eee;
    }
  </style>
</head>
<body>
  <?php if (!$currentUser): ?>
  <div id="login-page">
    <div class="login-container">
      <div class="app-logo">
        <i class="fas fa-seedling"></i>
      </div>

      <h1 class="login-title">Morphological parameter of rice</h1>
      <p class="login-subtitle">Advanced Rice Grain Analysis Platform</p>

      <?php if (!empty($loginError)): ?>
        <div class="error-message">
            <?= htmlspecialchars($loginError) ?>
        </div>
      <?php endif; ?>

      <form class="login-form" method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
        <div class="input-group">
          <i class="fas fa-envelope"></i>
          <input type="email" class="form-input" id="email" name="email" placeholder="Enter your email" required>
        </div>

        <div class="input-group">
          <i class="fas fa-lock"></i>
          <input type="password" class="form-input" id="password" name="password" placeholder="Enter your password" required>
        </div>

        <div class="role-select">
          <div class="role-option" data-role="farmer" onclick="selectRole(this, 'farmer')">
            <i class="fas fa-tractor"></i>
            Farmer
          </div>
          <div class="role-option" data-role="researcher" onclick="selectRole(this, 'researcher')">
            <i class="fas fa-flask"></i>
            Researcher
          </div>
          <div class="role-option" data-role="sales" onclick="selectRole(this, 'sales')">
            <i class="fas fa-chart-line"></i>
            Sales
          </div>
          <div class="role-option" data-role="admin" onclick="selectRole(this, 'admin')">
            <i class="fas fa-user-shield"></i>
            Admin </div>
        </div>
        <input type="hidden" id="selected-role-input" name="role" value="farmer">

        <button type="submit" class="login-btn" name="login">
          <i class="fas fa-sign-in-alt"></i> Login to Dashboard
        </button>
      </form>

      <p class="login-footer">
        &copy; 2025 RiceMorph Pro. Contact: support@ricemorphpro.com
      </p>
    </div>
  </div>
  <?php else: ?>
  <div id="dashboard-page" style="display: flex;">
    <header>
      <div class="header-left">
        <div class="logo-mini">
          <i class="fas fa-seedling"></i>
        </div>
        <h1>RiceMorph Pro Dashboard</h1>
      </div>

      <div class="user-info">
        <div class="user-avatar"><?= htmlspecialchars(strtoupper(substr($currentUser['name'], 0, 1))) ?></div>
        <div class="user-details">
          <div class="user-name"><?= htmlspecialchars($currentUser['name']) ?></div>
          <div class="user-role"><?= htmlspecialchars(ucfirst($currentUser['role'])) ?></div>
        </div>
        <button class="logout-btn" onclick="window.location.href='?logout=1'">
          <i class="fas fa-sign-out-alt"></i> Logout
        </button>
      </div>
    </header>

    <div class="main-content">
      <div class="welcome-section fade-in">
        <h2>Welcome back, <?= htmlspecialchars($currentUser['name']) ?></h2>
        <p>Access advanced tools and analytics for rice grain morphology analysis.</p>
        <div class="role-indicator">Role: <?= htmlspecialchars(ucfirst($currentUser['role'])) ?></div>
      </div>

      <?php if ($currentUser['role'] === 'farmer'): ?>
      <div id="farmer-dashboard" class="dashboard-section">
        <div class="dashboard-grid">
          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Upload Rice Grain Image</h3>
              <div class="card-actions">
                <button class="action-btn"><i class="fas fa-info-circle"></i></button>
              </div>
            </div>

            <div class="upload-area" id="upload-area" onclick="document.getElementById('image-input').click()">
              <div class="upload-icon">
                <i class="fas fa-cloud-upload-alt"></i>
              </div>
              <p class="upload-text">Drag & drop your rice grain image here or click to browse</p>
              <button class="upload-btn">Select Image</button>
              <input type="file" id="image-input" class="file-input" accept="image/*">
            </div>

            <div id="analysis-status" class="analysis-status">
              <i class="fas fa-spinner fa-spin"></i> Processing image...
            </div>

            <div class="image-preview">
              <div class="preview-box">
                <div class="preview-title">Original Image</div>
                <img id="original-image" class="preview-img" alt="Original Rice Grains">
              </div>
              <div class="preview-box">
                <div class="preview-title">Processed Image</div>
                <img id="processed-image" class="preview-img" alt="Processed Rice Grains">
              </div>
            </div>

            <button class="login-btn" onclick="analyzeImage()" style="width:100%; margin-top:1rem;">
              <i class="fas fa-microscope"></i> Analyze Image
            </button>
          </div>

          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Analysis Results</h3>
              <div class="card-actions">
                <button class="action-btn"><i class="fas fa-download"></i></button>
              </div>
            </div>

            <div class="chart-container">
              <canvas id="grain-metrics-chart"></canvas>
            </div>

            <table class="metrics-table">
              <tr>
                <th>Parameter</th>
                <th>Value</th>
              </tr>
              <tr>
                <td>Total Grains</td>
                <td id="total-grains">-</td>
              </tr>
              <tr>
                <td>Average Length</td>
                <td id="avg-length">-</td>
              </tr>
              <tr>
                <td>Average Width</td>
                <td id="avg-width">-</td>
              </tr>
              <tr>
                <td>Grain Color</td>
                <td id="grain-color">-</td>
              </tr>
              <tr>
                <td>Grade</td>
                <td id="grain-grade">-</td>
              </tr>
            </table>
          </div>
        </div>

        <h3 style="margin: 2rem 0 1.5rem; color: var(--primary);">Detailed Analytics</h3>
        <div class="charts-container">
          <div class="chart-card">
            <div class="chart-title">Grain Size Distribution</div>
            <div class="chart-container">
              <canvas id="size-distribution-chart"></canvas>
            </div>
          </div>
          <div class="chart-card">
            <div class="chart-title">Color Composition</div>
            <div class="chart-container">
              <canvas id="color-chart"></canvas>
            </div>
          </div>
          <div class="chart-card">
            <div class="chart-title">Grade Comparison</div>
            <div class="chart-container">
              <canvas id="grade-chart"></canvas>
            </div>
          </div>
        </div>

        <!-- Detailed Analysis Section - Added Here -->
        <div id="detailed-analysis" style="display:none; margin-top: 3rem;">
          <h3 style="margin-bottom: 1.5rem; color: var(--primary);">Comprehensive Grain Metrics</h3>
          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Detailed Grain Analysis</h3>
            </div>
            <div class="charts-container">
              <div class="chart-card">
                <div class="chart-title">Length vs Width Distribution</div>
                <div class="chart-container">
                  <canvas id="scatter-chart"></canvas>
                </div>
              </div>
              <div class="chart-card">
                <div class="chart-title">Defect Analysis</div>
                <div class="chart-container">
                  <canvas id="defect-chart"></canvas>
                </div>
              </div>
            </div>
            <table class="metrics-table" style="margin-top: 1.5rem;">
              <tr>
                <th>Quality Metric</th>
                <th>Value</th>
                <th>Rating</th>
              </tr>
              <tr>
                <td>Broken Grains</td>
                <td id="broken-grains">-</td>
                <td id="broken-rating">-</td>
              </tr>
              <tr>
                <td>Chalky Grains</td>
                <td id="chalky-grains">-</td>
                <td id="chalky-rating">-</td>
              </tr>
              <tr>
                <td>Immature Grains</td>
                <td id="immature-grains">-</td>
                <td id="immature-rating">-</td>
              </tr>
              <tr>
                <td>Color Consistency</td>
                <td id="color-consistency">-</td>
                <td id="color-rating">-</td>
              </tr>
            </table>
          </div>
        </div>
        <!-- End Detailed Analysis Section -->

        <!-- Past Analysis Results Section -->
        <div id="past-analysis-results" style="margin-top: 3rem;">
          <h3 style="margin-bottom: 1.5rem; color: var(--primary);">Past Analysis Results</h3>
          <div class="card">
            <div class="card-header">
              <h3 class="card-title">My Analysis History</h3>
              <div class="card-actions">
                <button class="action-btn"><i class="fas fa-sync-alt"></i></button>
              </div>
            </div>
            <div style="overflow-x: auto;">
              <table class="metrics-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Total Grains</th>
                    <th>Avg. Length</th>
                    <th>Avg. Width</th>
                    <th>Grain Color</th>
                    <th>Grade</th>
                    <th>Broken Grains</th>
                    <th>Chalky Grains</th>
                    <th>Immature Grains</th>
                    <th>Color Consistency</th>
                    <th>Original Image</th>
                    <th>Processed Image</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($analysisResults)): ?>
                    <?php foreach ($analysisResults as $result): ?>
                      <tr>
                        <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($result['analysis_date']))) ?></td>
                        <td><?= htmlspecialchars($result['total_grains']) ?></td>
                        <td><?= htmlspecialchars($result['avg_length']) ?> mm</td>
                        <td><?= htmlspecialchars($result['avg_width']) ?> mm</td>
                        <td><?= htmlspecialchars($result['grain_color']) ?></td>
                        <td><span class="grade-<?= strtolower($result['grade']) ?>"><?= htmlspecialchars($result['grade']) ?></span></td>
                        <td><?= htmlspecialchars($result['broken_grains']) ?>%</td>
                        <td><?= htmlspecialchars($result['chalky_grains']) ?>%</td>
                        <td><?= htmlspecialchars($result['immature_grains']) ?>%</td>
                        <td><?= htmlspecialchars($result['color_consistency']) ?>%</td>
                        <td>
                          <?php if (!empty($result['original_image_data'])): ?>
                            <img src="<?= htmlspecialchars($result['original_image_data']) ?>" class="image-thumbnail" alt="Original">
                          <?php else: ?>
                            N/A
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if (!empty($result['processed_image_data'])): ?>
                            <img src="<?= htmlspecialchars($result['processed_image_data']) ?>" class="image-thumbnail" alt="Processed">
                          <?php else: ?>
                            N/A
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="12" style="text-align: center;">No past analysis results found.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <!-- End Past Analysis Results Section -->

      </div>
      <?php elseif ($currentUser['role'] === 'researcher'): ?>
      <div id="researcher-dashboard" class="dashboard-section">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Research Data Analytics</h3>
            <div class="card-actions">
              <button class="action-btn"><i class="fas fa-download"></i></button>
              <button class="action-btn"><i class="fas fa-sync-alt"></i></button>
            </div>
          </div>

          <p>Analyze grain morphology, compare parameters, and export research data.</p>

          <div class="research-data">
            <div class="data-card">
              <div class="chart-title">Variety Comparison</div>
              <div class="chart-container">
                <canvas id="variety-chart"></canvas>
              </div>
            </div>
            <div class="data-card">
              <div class="chart-title">Quality Metrics</div>
              <div class="chart-container">
                <canvas id="quality-chart"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php elseif ($currentUser['role'] === 'sales'): ?>
      <div id="sales-dashboard" class="dashboard-section">
        <div class="dashboard-grid">
          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Record a Sale</h3>
            </div>
            <form method="POST" class="login-form" style="padding: 1.5rem;">
              <div class="input-group">
                <i class="fas fa-box"></i>
                <input type="text" class="form-input" name="product" placeholder="Product Name" required>
              </div>

              <div class="input-group">
                <i class="fas fa-weight"></i>
                <input type="number" class="form-input" name="quantity" placeholder="Quantity (kg)" step="0.01" required>
              </div>

              <div class="input-group">
                <i class="fas fa-dollar-sign"></i>
                <input type="number" class="form-input" name="price" placeholder="Price per kg" step="0.01" required>
              </div>

              <button type="submit" name="sale" class="login-btn" style="width:100%;">
                <i class="fas fa-cash-register"></i> Record Sale
              </button>
            </form>
          </div>

          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Record a Purchase</h3>
            </div>
            <form method="POST" class="login-form" style="padding: 1.5rem;">
              <div class="input-group">
                <i class="fas fa-box"></i>
                <input type="text" class="form-input" name="product" placeholder="Product Name" required>
              </div>

              <div class="input-group">
                <i class="fas fa-weight"></i>
                <input type="number" class="form-input" name="quantity" placeholder="Quantity (kg)" step="0.01" required>
              </div>

              <div class="input-group">
                <i class="fas fa-dollar-sign"></i>
                <input type="number" class="form-input" name="price" placeholder="Price per kg" step="0.01" required>
              </div>

              <button type="submit" name="purchase" class="login-btn" style="width:100%;">
                <i class="fas fa-shopping-cart"></i> Record Purchase
              </button>
            </form>
          </div>
        </div>

        <div class="dashboard-grid">
          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Sales History</h3>
            </div>
            <div style="overflow-x: auto;">
              <table class="metrics-table">
                <tr>
                  <th>Date</th>
                  <th>Product</th>
                  <th>Quantity</th>
                  <th>Price</th>
                  <th>Total</th>
                </tr>
                <?php foreach ($sales as $sale): ?>
                <tr>
                  <td><?= htmlspecialchars($sale['sale_date']) ?></td>
                  <td><?= htmlspecialchars($sale['product']) ?></td>
                  <td><?= htmlspecialchars($sale['quantity']) ?> kg</td>
                  <td>$<?= htmlspecialchars(number_format($sale['price'], 2)) ?></td>
                  <td>$<?= htmlspecialchars(number_format($sale['total'], 2)) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sales)): ?>
                <tr>
                  <td colspan="5" style="text-align: center;">No sales recorded yet</td>
                </tr>
                <?php endif; ?>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Purchase History</h3>
            </div>
            <div style="overflow-x: auto;">
              <table class="metrics-table">
                <tr>
                  <th>Date</th>
                  <th>Product</th>
                  <th>Quantity</th>
                  <th>Price</th>
                  <th>Total</th>
                </tr>
                <?php foreach ($purchases as $purchase): ?>
                <tr>
                  <td><?= htmlspecialchars($purchase['purchase_date']) ?></td>
                  <td><?= htmlspecialchars($purchase['product']) ?></td>
                  <td><?= htmlspecialchars($purchase['quantity']) ?> kg</td>
                  <td>$<?= htmlspecialchars(number_format($purchase['price'], 2)) ?></td>
                  <td>$<?= htmlspecialchars(number_format($purchase['total'], 2)) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($purchases)): ?>
                <tr>
                  <td colspan="5" style="text-align: center;">No purchases recorded yet</td>
                </tr>
                <?php endif; ?>
              </table>
            </div>
          </div>
        </div>
      </div>
      <?php elseif ($currentUser['role'] === 'admin'): ?>
      <div id="admin-dashboard" class="dashboard-section">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">User Management</h3>
          </div>

          <div style="overflow-x: auto; margin-top: 1rem;">
            <table class="metrics-table">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Created At</th>
                <th>Actions</th>
              </tr>
              <?php foreach ($users as $user): ?>
              <tr>
                <td><?= htmlspecialchars($user['id']) ?></td>
                <td><?= htmlspecialchars($user['name']) ?></td>
                <td><?= htmlspecialchars($user['email']) ?></td>
                <td><?= htmlspecialchars(ucfirst($user['role'])) ?></td>
                <td><?= htmlspecialchars($user['created_at']) ?></td>
                <td>
                  <button class="action-btn"><i class="fas fa-edit"></i></button>
                  <button class="action-btn"><i class="fas fa-trash"></i></button>
                </td>
              </tr>
              <?php endforeach; ?>
            </table>
          </div>

          <h3 style="margin: 2rem 0 1.5rem; color: var(--primary);">Add New User</h3>
          <form method="POST" class="login-form" style="padding: 0 1.5rem 1.5rem;">
            <div class="input-group">
              <i class="fas fa-user"></i>
              <input type="text" class="form-input" name="name" placeholder="Full Name" required>
            </div>

            <div class="input-group">
              <i class="fas fa-envelope"></i>
              <input type="email" class="form-input" name="email" placeholder="Email" required>
            </div>

            <div class="input-group">
              <i class="fas fa-lock"></i>
              <input type="password" class="form-input" name="password" placeholder="Password" required>
            </div>

            <div class="input-group">
              <i class="fas fa-user-tag"></i>
              <select class="form-input" name="role" required>
                <option value="farmer">Farmer</option>
                <option value="researcher">Researcher</option>
                <option value="sales">Sales</option>
                <option value="admin">Admin</option>
              </select>
            </div>

            <button type="submit" name="add_user" class="login-btn" style="width:100%;">
              <i class="fas fa-user-plus"></i> Add User
            </button>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <footer>
      &copy; 2025 RiceMorph Pro. Contact: support@ricemorphpro.com
    </footer>
  </div>
  <?php endif; ?>

  <script>
    // Current user data
    // This part of JS is client-side and doesn't directly impact PHP login logic after the form submission.
    // It's mainly for dynamic UI updates and chart drawing.
    let currentUser = <?= $currentUser ? json_encode($currentUser) : 'null' ?>;
    let isAnalyzing = false;

    // DOM elements
    const loginPage = document.getElementById('login-page');
    const dashboardPage = document.getElementById('dashboard-page');
    const farmerDashboard = document.getElementById('farmer-dashboard');
    const analysisStatus = document.getElementById('analysis-status');
    const selectedRoleInput = document.getElementById('selected-role-input'); // Get the hidden input
    const detailedAnalysisSection = document.getElementById('detailed-analysis'); // New: Detailed analysis section

    // Initialize the page
    function init() {
      // Initialize drag and drop
      const uploadArea = document.getElementById('upload-area');
      if (uploadArea) {
        uploadArea.addEventListener('dragover', (e) => {
          e.preventDefault();
          uploadArea.classList.add('drag-over');
        });

        uploadArea.addEventListener('dragleave', () => {
          uploadArea.classList.remove('drag-over');
        });

        uploadArea.addEventListener('drop', (e) => {
          e.preventDefault();
          uploadArea.classList.remove('drag-over');

          const files = e.dataTransfer.files;
          if (files.length && files[0].type.startsWith('image/')) {
            document.getElementById('image-input').files = files;
            analyzeImage();
          }
        });
      }

      // Draw charts if user is logged in (excluding farmer as they trigger it manually)
      if (currentUser && currentUser.role !== 'farmer' && (currentUser.role === 'researcher' || currentUser.role === 'sales' || currentUser.role === 'admin')) {
        setTimeout(drawCharts, 300);
      }

      // Set initial selected role on load for the login form
      const initialRoleOption = document.querySelector(`.role-option[data-role="${selectedRoleInput.value}"]`);
      if (initialRoleOption) {
          initialRoleOption.classList.add('selected');
      }
    }

    // Select user role - updated to handle the hidden input
    function selectRole(element, role) {
      const options = document.querySelectorAll('.role-option');
      options.forEach(option => option.classList.remove('selected'));

      element.classList.add('selected');
      selectedRoleInput.value = role; // Update the hidden input's value
    }

    // Analyze uploaded image
    function analyzeImage() {
      if (isAnalyzing) return;

      const input = document.getElementById('image-input');
      const originalImg = document.getElementById('original-image');
      const processedImg = document.getElementById('processed-image');

      if (!input.files || !input.files[0]) {
        showAnalysisStatus('Please select an image first.', 'error');
        return;
      }

      isAnalyzing = true;
      showAnalysisStatus('Processing image...', 'processing');

      const reader = new FileReader();
      reader.onload = function(e) {
        // Display original image
        originalImg.src = e.target.result;
        originalImg.style.display = 'block';

        // Create processed image (simulated grayscale)
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const img = new Image();

        img.onload = function() {
          canvas.width = img.width;
          canvas.height = img.height;
          ctx.drawImage(img, 0, 0);

          // Convert to grayscale (simple simulation)
          const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
          const data = imageData.data;

          for (let i = 0; i < data.length; i += 4) {
            const avg = (data[i] + data[i + 1] + data[i + 2]) / 3;
            data[i] = avg; // R
            data[i + 1] = avg; // G
            data[i + 2] = avg; // B
          }

          ctx.putImageData(imageData, 0, 0);
          processedImg.src = canvas.toDataURL();
          processedImg.style.display = 'block';

          // Simulate analysis results
          setTimeout(() => {
            // Pass image data to simulateAnalysisResults
            simulateAnalysisResults(originalImg.src, processedImg.src);
            isAnalyzing = false;
          }, 1500);
        };

        img.src = e.target.result;
      };

      reader.readAsDataURL(input.files[0]);
    }

    // Show analysis status
    function showAnalysisStatus(message, type) {
      if (!analysisStatus) return;

      analysisStatus.innerHTML = type === 'processing'
        ? `<i class="fas fa-spinner fa-spin"></i> ${message}`
        : `<i class="fas fa-exclamation-circle"></i> ${message}`;

      analysisStatus.className = `analysis-status status-${type}`;
      analysisStatus.style.display = 'block';

      if (type === 'error') {
        // Auto-hide error message after 3 seconds
        setTimeout(() => {
          analysisStatus.style.display = 'none';
        }, 3000);
      }
    }

    // Simulate analysis results and send to PHP
    async function simulateAnalysisResults(originalImageData, processedImageData) {
      // Generate random values for demonstration
      const totalGrains = Math.floor(Math.random() * 100) + 100;
      const avgLength = (5 + Math.random() * 2).toFixed(2); // Changed to 2 decimal places for consistency
      const avgWidth = (1.8 + Math.random() * 0.8).toFixed(2); // Changed to 2 decimal places for consistency
      const grainColor = "White";
      const grade = totalGrains > 150 ? "A" : totalGrains > 120 ? "B" : "C";

      const brokenGrains = (Math.random() * 10).toFixed(2);
      const chalkyGrains = (Math.random() * 8).toFixed(2);
      const immatureGrains = (Math.random() * 5).toFixed(2);
      const colorConsistency = (70 + Math.random() * 30).toFixed(2); // 70-100%

      // Update UI with simulated data
      document.getElementById('total-grains').textContent = totalGrains;
      document.getElementById('avg-length').textContent = `${avgLength} mm`;
      document.getElementById('avg-width').textContent = `${avgWidth} mm`;
      document.getElementById('grain-color').textContent = grainColor;

      // Format grade with color
      const gradeElement = document.getElementById('grain-grade');
      gradeElement.textContent = grade;
      gradeElement.className = `grade-${grade.toLowerCase()}`;

      // Show detailed analysis section
      if (detailedAnalysisSection) {
          detailedAnalysisSection.style.display = 'block';
      }

      // Simulate detailed analysis results for the new section
      updateDetailedAnalysisUI(brokenGrains, chalkyGrains, immatureGrains, colorConsistency);

      // Prepare data for saving, now including image data
      const analysisData = {
          save_analysis_results: 1, // Flag to trigger PHP save logic
          total_grains: totalGrains,
          avg_length: parseFloat(avgLength),
          avg_width: parseFloat(avgWidth),
          grain_color: grainColor,
          grade: grade,
          broken_grains: parseFloat(brokenGrains),
          chalky_grains: parseFloat(chalkyGrains),
          immature_grains: parseFloat(immatureGrains),
          color_consistency: parseFloat(colorConsistency),
          original_image_data: originalImageData, // Include original image
          processed_image_data: processedImageData // Include processed image
      };

      try {
          const response = await fetch(window.location.href, { // Send to the same PHP file
              method: 'POST',
              headers: {
                  'Content-Type': 'application/x-www-form-urlencoded',
              },
              // Convert JS object to URL-encoded string
              body: new URLSearchParams(analysisData).toString(),
          });

          const result = await response.json(); // PHP returns JSON

          if (result.status === 'success') {
              showAnalysisStatus('Analysis completed successfully and saved!', 'success');
              // Reload the page to fetch and display updated historical results
              location.reload();
          } else {
              showAnalysisStatus(`Analysis completed, but failed to save: ${result.message}`, 'error');
              console.error("Database error:", result.db_error);
          }
      } catch (error) {
          console.error('Error sending analysis data:', error);
          showAnalysisStatus('Analysis completed, but network error occurred while saving.', 'error');
      }

      // Update charts regardless of save success
      setTimeout(() => {
        drawCharts();
        // Auto-hide success/error message after 2 seconds if not a persistent error
        setTimeout(() => {
          if (analysisStatus && analysisStatus.className.indexOf('status-error') === -1) {
              analysisStatus.style.display = 'none';
          }
        }, 2000);
      }, 500);
    }

    // Update detailed analysis UI
    function updateDetailedAnalysisUI(brokenGrains, chalkyGrains, immatureGrains, colorConsistency) {
        document.getElementById('broken-grains').textContent = `${brokenGrains}%`;
        document.getElementById('chalky-grains').textContent = `${chalkyGrains}%`;
        document.getElementById('immature-grains').textContent = `${immatureGrains}%`;
        document.getElementById('color-consistency').textContent = `${colorConsistency}%`;

        // Assign ratings based on simulated values
        document.getElementById('broken-rating').className = brokenGrains < 2 ? 'grade-a' : (brokenGrains < 5 ? 'grade-b' : 'grade-c');
        document.getElementById('broken-rating').textContent = brokenGrains < 2 ? 'Excellent' : (brokenGrains < 5 ? 'Good' : 'Poor');

        document.getElementById('chalky-rating').className = chalkyGrains < 3 ? 'grade-a' : (chalkyGrains < 6 ? 'grade-b' : 'grade-c');
        document.getElementById('chalky-rating').textContent = chalkyGrains < 3 ? 'Excellent' : (chalkyGrains < 6 ? 'Good' : 'Poor');

        document.getElementById('immature-rating').className = immatureGrains < 1 ? 'grade-a' : (immatureGrains < 3 ? 'grade-b' : 'grade-c');
        document.getElementById('immature-rating').textContent = immatureGrains < 1 ? 'Excellent' : (immatureGrains < 3 ? 'Good' : 'Poor');

        document.getElementById('color-rating').className = colorConsistency > 90 ? 'grade-a' : (colorConsistency > 80 ? 'grade-b' : 'grade-c');
        document.getElementById('color-rating').textContent = colorConsistency > 90 ? 'Excellent' : (colorConsistency > 80 ? 'Good' : 'Fair');
    }

    // Chart instances to prevent recreation
    let grainMetricsChart, sizeDistributionChart, colorChart, gradeChart, varietyChart, qualityChart, scatterChart, defectChart;

    // Draw charts
    function drawCharts() {
      // Destroy existing charts if they exist to prevent errors when redrawing
      if (grainMetricsChart) grainMetricsChart.destroy();
      if (sizeDistributionChart) sizeDistributionChart.destroy();
      if (colorChart) colorChart.destroy();
      if (gradeChart) gradeChart.destroy();
      if (varietyChart) varietyChart.destroy();
      if (qualityChart) qualityChart.destroy();
      if (scatterChart) scatterChart.destroy(); // New: Destroy scatter chart
      if (defectChart) defectChart.destroy();   // New: Destroy defect chart


      // Grain Metrics Chart
      const metricsCtx = document.getElementById('grain-metrics-chart')?.getContext('2d');
      if (metricsCtx) {
        grainMetricsChart = new Chart(metricsCtx, {
          type: 'radar',
          data: {
            labels: ['Length', 'Width', 'Color', 'Purity', 'Uniformity'],
            datasets: [{
              label: 'Grain Metrics',
              data: [6.2, 2.1, 85, 92, 78],
              backgroundColor: 'rgba(26, 93, 63, 0.2)',
              borderColor: '#1a5d3f',
              borderWidth: 2,
              pointBackgroundColor: '#1a5d3f'
            }]
          },
          options: {
            scales: {
              r: {
                angleLines: { color: 'rgba(0, 0, 0, 0.1)' },
                grid: { color: 'rgba(0, 0, 0, 0.1)' },
                suggestedMin: 0,
                suggestedMax: 100
              }
            },
            responsive: true,
            maintainAspectRatio: false,
          }
        });
      }

      // Size Distribution Chart
      const sizeCtx = document.getElementById('size-distribution-chart')?.getContext('2d');
      if (sizeCtx) {
        sizeDistributionChart = new Chart(sizeCtx, {
          type: 'bar',
          data: {
            labels: ['< 5mm', '5-6mm', '6-7mm', '> 7mm'],
            datasets: [{
              label: 'Number of Grains',
              data: [15, 42, 28, 15],
              backgroundColor: '#1a5d3f'
            }]
          },
          options: {
            scales: {
              y: { beginAtZero: true }
            },
            responsive: true,
            maintainAspectRatio: false,
          }
        });
      }

      // Color Chart
      const colorCtx = document.getElementById('color-chart')?.getContext('2d');
      if (colorCtx) {
        colorChart = new Chart(colorCtx, {
          type: 'pie',
          data: {
            labels: ['White', 'Light Brown', 'Golden'],
            datasets: [{
              data: [50, 30, 20],
              backgroundColor: ['#ffffff', '#e4c49b', '#f9d44d'],
              borderColor: ['#ddd', '#c4a87d', '#e0b920'],
              borderWidth: 1
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
          }
        });
      }

      // Grade Chart
      const gradeCtx = document.getElementById('grade-chart')?.getContext('2d');
      if (gradeCtx) {
        gradeChart = new Chart(gradeCtx, {
          type: 'doughnut',
          data: {
            labels: ['Grade A', 'Grade B', 'Grade C'],
            datasets: [{
              data: [65, 25, 10],
              backgroundColor: ['#16a34a', '#facc15', '#ef4444']
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
          }
        });
      }


      // Researcher Charts (example data)
      const varietyCtx = document.getElementById('variety-chart')?.getContext('2d');
      if (varietyCtx) {
        varietyChart = new Chart(varietyCtx, {
            type: 'bar',
            data: {
                labels: ['Variety A', 'Variety B', 'Variety C'],
                datasets: [
                    {
                        label: 'Avg Length (mm)',
                        data: [6.5, 7.1, 5.8],
                        backgroundColor: '#1a5d3f'
                    },
                    {
                        label: 'Avg Width (mm)',
                        data: [2.0, 2.3, 1.9],
                        backgroundColor: '#2a7d5f'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
      }

      const qualityCtx = document.getElementById('quality-chart')?.getContext('2d');
      if (qualityCtx) {
        qualityChart = new Chart(qualityCtx, {
            type: 'line',
            data: {
                labels: ['Q1', 'Q2', 'Q3', 'Q4'],
                datasets: [
                    {
                        label: 'Quality Score',
                        data: [85, 90, 88, 92],
                        borderColor: '#16a34a',
                        fill: false
                    },
                    {
                        label: 'Purity %',
                        data: [90, 88, 91, 93],
                        borderColor: '#facc15',
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
      }

      // New: Scatter Chart for Length vs Width Distribution
      const scatterCtx = document.getElementById('scatter-chart')?.getContext('2d');
      if (scatterCtx) {
        // Generate random data for scatter plot
        const scatterData = Array.from({ length: 50 }, () => ({
            x: parseFloat((5 + Math.random() * 2).toFixed(2)), // Length between 5 and 7
            y: parseFloat((1.8 + Math.random() * 0.8).toFixed(2)) // Width between 1.8 and 2.6
        }));

        scatterChart = new Chart(scatterCtx, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Grain Dimensions (Length vs Width)',
                    data: scatterData,
                    backgroundColor: 'rgba(26, 93, 63, 0.6)',
                    borderColor: 'rgba(26, 93, 63, 1)',
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'linear',
                        position: 'bottom',
                        title: {
                            display: true,
                            text: 'Length (mm)'
                        }
                    },
                    y: {
                        title: {
                            display: true,
                            text: 'Width (mm)'
                        }
                    }
                }
            }
        });
      }

      // New: Defect Analysis Chart (Pie Chart)
      const defectCtx = document.getElementById('defect-chart')?.getContext('2d');
      if (defectCtx) {
        // Ensure the UI elements are updated before trying to read their values for the chart
        const broken = parseFloat(document.getElementById('broken-grains').textContent);
        const chalky = parseFloat(document.getElementById('chalky-grains').textContent);
        const immature = parseFloat(document.getElementById('immature-grains').textContent);
        const healthy = (100 - broken - chalky - immature).toFixed(2); // Calculate healthy grains

        defectChart = new Chart(defectCtx, {
            type: 'pie',
            data: {
                labels: ['Broken Grains', 'Chalky Grains', 'Immature Grains', 'Healthy Grains'],
                datasets: [{
                    data: [broken, chalky, immature, healthy],
                    backgroundColor: ['#ef4444', '#facc15', '#a855f7', '#16a34a'], // Red, Yellow, Purple, Green
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed !== null) {
                                    label += context.parsed + '%';
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
      }
    }


    // Initialize the application
    window.onload = function() {
      // Set default selected role (e.g., 'farmer') when the page loads if no user is logged in.
      // This ensures the hidden input 'role' has a value before submission.
      if (!currentUser) {
          const defaultRoleOption = document.querySelector('.role-option[data-role="farmer"]');
          if (defaultRoleOption) {
              selectRole(defaultRoleOption, 'farmer');
          }
      }
      init();
    };
  </script>
</body>
</html>
