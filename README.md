# 🌾 RiceMorph Pro

A PHP-based web application for rice grain morphology analysis. It supports multiple user roles (Farmer, Researcher, Sales, Admin) with features like grain analysis, sales tracking, and data visualization.

## 🚀 Features
- User login with role-based access (Farmer, Researcher, Sales, Admin)
- Rice grain image analysis (length, width, color, grade)
- Sales and purchase tracking
- Charts and data visualization (Chart.js)
- Admin panel to manage users

## 🛠️ Setup Instructions

### 1. Requirements
- PHP 7.4 or higher
- MySQL / MariaDB
- A local server like XAMPP, WAMP, or Laragon

### 2. Installation

```bash
# Clone the repository
git clone https://github.com/YOUR_USERNAME/ricemorph-pro.git

# Go into the project folder
cd ricemorph-pro
```

### 3. Configure Database
- Copy `config.example.php` and rename it to `config.php`
- Open `config.php` and fill in your database credentials:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');        // your MySQL password
define('DB_NAME', 'ricemorph_pro');
```

### 4. Run the App
- Place the project folder inside `htdocs` (XAMPP) or `www` (WAMP)
- Start Apache and MySQL
- Visit: `http://localhost/ricemorph-pro/rice.php`

## 🔐 Default Admin Login
| Email | Password | Role |
|-------|----------|------|
| admin@example.com | admin123 | admin |

> ⚠️ Change the default password after first login!

## 📁 Project Structure
```
ricemorph-pro/
├── rice.php            # Main application file
├── rice1.php           # Supporting module
├── rice2.php           # Supporting module
├── config.php          # ⚠️ NOT in GitHub (your local credentials)
├── config.example.php  # ✅ Safe template for config
├── .gitignore
└── README.md
```

## 📄 License
This project is for educational purposes.
