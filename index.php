<?php
include 'session_start.php';
include 'db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
*/
if (isset($_GET['logout'])) {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
    header("Location: index.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalize_email($email) {
    return strtolower(trim($email));
}

/*
|--------------------------------------------------------------------------
| INITIAL STATE
|--------------------------------------------------------------------------
*/
$loginError = '';
$registerError = '';
$registerSuccess = '';

$loggedIn = isset($_SESSION['customer_id']) && (int)$_SESSION['customer_id'] > 0;
$customerName = $_SESSION['customer_name'] ?? '';
$customerEmail = $_SESSION['customer_email'] ?? '';
$customerPhone = $_SESSION['customer_phone'] ?? '';

/*
|--------------------------------------------------------------------------
| HANDLE LOGIN
|--------------------------------------------------------------------------
*/
if (isset($_POST['login'])) {
    $email = normalize_email($_POST['login_email'] ?? '');
    $password = trim($_POST['login_password'] ?? '');

    if ($email === '' || $password === '') {
        $loginError = "Please enter your email and password.";
    } else {
        $stmt = $conn->prepare("SELECT id, fullname, email, phone, password FROM customers WHERE email = ? LIMIT 1");
        if (!$stmt) {
            die("Login query failed: " . $conn->error);
        }

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $customer = $result->fetch_assoc();
            $storedPassword = (string)($customer['password'] ?? '');

            $passwordMatches = false;

            if ($storedPassword !== '') {
                if (password_verify($password, $storedPassword)) {
                    $passwordMatches = true;
                } elseif ($password === $storedPassword) {
                    $passwordMatches = true;
                }
            }

            if ($passwordMatches) {
                session_regenerate_id(true);

                $_SESSION['customer_id'] = (int)$customer['id'];
                $_SESSION['customer_email'] = $customer['email'];
                $_SESSION['customer_name'] = $customer['fullname'] ?? $customer['email'];
                $_SESSION['customer_phone'] = $customer['phone'] ?? '';

                header("Location: index.php");
                exit();
            } else {
                $loginError = "Invalid email or password.";
            }
        } else {
            $loginError = "Invalid email or password.";
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE REGISTRATION
|--------------------------------------------------------------------------
*/
if (isset($_POST['register'])) {
    $fullName = trim($_POST['register_full_name'] ?? '');
    $email = normalize_email($_POST['register_email'] ?? '');
    $phone = trim($_POST['register_phone'] ?? '');
    $password = trim($_POST['register_password'] ?? '');
    $confirmPassword = trim($_POST['register_confirm_password'] ?? '');

    if ($fullName === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $registerError = "Please complete all required registration fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $registerError = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $registerError = "Password must be at least 6 characters.";
    } elseif ($password !== $confirmPassword) {
        $registerError = "Passwords do not match.";
    } else {
        $checkStmt = $conn->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
        if (!$checkStmt) {
            die("Registration check failed: " . $conn->error);
        }

        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult && $checkResult->num_rows > 0) {
            $registerError = "This email is already registered.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $insertStmt = $conn->prepare(
                "INSERT INTO customers (fullname, email, phone, password) VALUES (?, ?, ?, ?)"
            );

            if (!$insertStmt) {
                die("Registration insert failed: " . $conn->error);
            }

            $insertStmt->bind_param("ssss", $fullName, $email, $phone, $hashedPassword);

            if ($insertStmt->execute()) {
                session_regenerate_id(true);

                $_SESSION['customer_id'] = (int)$insertStmt->insert_id;
                $_SESSION['customer_email'] = $email;
                $_SESSION['customer_name'] = $fullName;
                $_SESSION['customer_phone'] = $phone;

                $registerSuccess = "Registration successful. You are now logged in.";
                $loggedIn = true;
                $customerName = $fullName;
                $customerEmail = $email;
                $customerPhone = $phone;
            } else {
                $registerError = "Registration failed. Please try again.";
            }

            $insertStmt->close();
        }

        $checkStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| SESSION CHECK AFTER AUTH
|--------------------------------------------------------------------------
*/
$loggedIn = isset($_SESSION['customer_id']) && (int)$_SESSION['customer_id'] > 0;
$customerName = $_SESSION['customer_name'] ?? '';
$customerEmail = $_SESSION['customer_email'] ?? '';
$customerPhone = $_SESSION['customer_phone'] ?? '';

/*
|--------------------------------------------------------------------------
| LOAD EVENTS
|--------------------------------------------------------------------------
*/
$events = [];
$eventQuery = $conn->query(
    "SELECT t.id, t.event_type, t.event_name, t.event_date, t.ticket_price, t.service_fee, v.venue_name
     FROM tickets t
     LEFT JOIN venues v ON v.id = t.venue_id
     ORDER BY t.event_date ASC, t.id DESC"
);

if ($eventQuery) {
    while ($row = $eventQuery->fetch_assoc()) {
        $events[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Ticketing System</title>
    <link rel="stylesheet" href="general_style.css">
    <style>
        body{
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        .main_cont{
            max-width: 1100px;
            margin: auto;
        }
        .hero{
            background: #fff;
            padding: 24px;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            margin-bottom: 20px;
        }
        .hero h1{
            margin-top: 0;
            margin-bottom: 10px;
        }
        .grid{
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .card{
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .card h3{
            margin-top: 0;
            margin-bottom: 10px;
        }
        .meta{
            line-height: 1.7;
            color: #374151;
        }
        .actions{
            margin-top: 14px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        a.btn, button{
            padding: 10px 16px;
            border: none;
            border-radius: 10px;
            text-decoration: none;
            cursor: pointer;
            display: inline-block;
            font-size: 14px;
        }
        button{
            background: #111827;
            color: #fff;
        }
        a.btn{
            background: #e5e7eb;
            color: #111827;
        }
        .muted{
            color: #6b7280;
        }
        .empty{
            background: #fff;
            border: 1px dashed #d1d5db;
            border-radius: 14px;
            padding: 18px;
            color: #6b7280;
        }
        .auth-grid{
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-top: 18px;
        }
        .auth-box{
            padding: 18px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fafafa;
        }
        .auth-box h3{
            margin-top: 0;
        }
        .field{
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 12px;
        }
        .field input{
            padding: 12px 14px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 14px;
            background: #fff;
        }
        .error-box{
            margin-top: 12px;
            padding: 10px 12px;
            border: 1px solid #ffb3b3;
            background: #fff2f2;
            color: #a40000;
            border-radius: 8px;
        }
        .success-box{
            margin-top: 12px;
            padding: 10px 12px;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #166534;
            border-radius: 8px;
        }
        .user-bar{
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 18px;
            padding: 14px 18px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
        }
        .user-bar strong{
            display: block;
        }
        @media (max-width: 760px){
            .grid, .auth-grid{
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="main_cont">

    <div class="hero">
        <h1>Welcome to Ticketing System</h1>
        <p class="muted">
            Browse available events, then go to the browse page to choose how you want to search.
        </p>

        <div class="actions">
            <a class="btn" href="browse.php">Go to Browse Page</a>
            <?php if ($loggedIn) { ?>
                <a class="btn" href="index.php?logout=1">Logout</a>
            <?php } ?>
        </div>

        <?php if ($registerSuccess !== '') { ?>
            <div class="success-box"><?php echo h($registerSuccess); ?></div>
        <?php } ?>

        <div class="auth-grid">
            <div class="auth-box">
                <h3>Customer Login</h3>
                <?php if ($loggedIn) { ?>
                    <div class="success-box">
                        Logged in as <b><?php echo h($customerName ?: $customerEmail); ?></b>.
                    </div>
                <?php } else { ?>
                    <form method="POST">
                        <div class="field">
                            <label for="login_email">Email</label>
                            <input type="email" name="login_email" id="login_email" placeholder="Enter your email" required>
                        </div>
                        <div class="field">
                            <label for="login_password">Password</label>
                            <input type="password" name="login_password" id="login_password" placeholder="Enter your password" required>
                        </div>

                        <?php if ($loginError !== '') { ?>
                            <div class="error-box"><?php echo h($loginError); ?></div>
                        <?php } ?>

                        <div class="actions">
                            <button type="submit" name="login">Login</button>
                        </div>
                    </form>
                <?php } ?>
            </div>

            <div class="auth-box">
                <h3>Customer Registration</h3>

                <?php if ($loggedIn) { ?>
                    <div class="muted">
                        You are already logged in. You may still browse events and continue booking.
                    </div>
                <?php } else { ?>
                    <form method="POST">
                        <div class="field">
                            <label for="register_full_name">Full Name</label>
                            <input type="text" name="register_full_name" id="register_full_name" placeholder="Enter your full name" required>
                        </div>
                        <div class="field">
                            <label for="register_email">Email</label>
                            <input type="email" name="register_email" id="register_email" placeholder="Enter your email" required>
                        </div>
                        <div class="field">
                            <label for="register_phone">Phone (optional)</label>
                            <input type="text" name="register_phone" id="register_phone" placeholder="Enter your phone number">
                        </div>
                        <div class="field">
                            <label for="register_password">Password</label>
                            <input type="password" name="register_password" id="register_password" placeholder="Create a password" required>
                        </div>
                        <div class="field">
                            <label for="register_confirm_password">Confirm Password</label>
                            <input type="password" name="register_confirm_password" id="register_confirm_password" placeholder="Confirm your password" required>
                        </div>

                        <?php if ($registerError !== '') { ?>
                            <div class="error-box"><?php echo h($registerError); ?></div>
                        <?php } ?>

                        <div class="actions">
                            <button type="submit" name="register">Register</button>
                        </div>
                    </form>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="user-bar">
        <div>
            <strong>Session Status</strong>
            <?php if ($loggedIn) { ?>
                Customer ID: <?php echo (int)$_SESSION['customer_id']; ?>
            <?php } else { ?>
                Not logged in
            <?php } ?>
        </div>
        <div class="muted">
            <?php if ($loggedIn) { ?>
                <?php echo h($customerEmail); ?>
            <?php } else { ?>
                Login or register first so your booking flow can continue to payment and confirm.
            <?php } ?>
        </div>
    </div>

    <div class="grid">
        <?php if (!empty($events)) { ?>
            <?php foreach ($events as $event) { ?>
                <div class="card">
                    <h3><?php echo h($event['event_name']); ?></h3>
                    <div class="meta">
                        <div><b>Type:</b> <?php echo h($event['event_type']); ?></div>
                        <div><b>Venue:</b> <?php echo h($event['venue_name'] ?? '-'); ?></div>
                        <div><b>Date:</b> <?php echo h($event['event_date']); ?></div>
                        <div><b>Base Ticket:</b> ₱<?php echo number_format((float)$event['ticket_price'], 2); ?></div>
                        <div><b>Service Fee:</b> ₱<?php echo number_format((float)$event['service_fee'], 2); ?></div>
                    </div>
                </div>
            <?php } ?>
        <?php } else { ?>
            <div class="empty">No events available yet.</div>
        <?php } ?>
    </div>
</div>
</body>
</html>