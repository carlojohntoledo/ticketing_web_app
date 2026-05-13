<?php
include 'session_start.php';
include 'db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
|--------------------------------------------------------------------------
| VALIDATE SESSION
|--------------------------------------------------------------------------
*/
if (
    !isset($_SESSION['event_name']) ||
    !isset($_SESSION['selected_seat']) ||
    !isset($_SESSION['selected_group'])
) {
    header("Location: index.php");
    exit();
}

$eventName = trim($_SESSION['event_name']);
$selectedSeat = trim($_SESSION['selected_seat']);
$selectedGroup = trim($_SESSION['selected_group']);
$selectedGroupPrice = isset($_SESSION['selected_group_price']) ? (float)$_SESSION['selected_group_price'] : 0.00;
$serviceFeeSession = isset($_SESSION['service_fee']) ? (float)$_SESSION['service_fee'] : null;
$selectedTotalSession = isset($_SESSION['selected_total']) ? (float)$_SESSION['selected_total'] : null;

$eventNameEsc = mysqli_real_escape_string($conn, $eventName);

/*
|--------------------------------------------------------------------------
| GET EVENT DATA FROM tickets
|--------------------------------------------------------------------------
*/
$eventQuery = mysqli_query(
    $conn,
    "SELECT t.*, v.venue_name
     FROM tickets t
     LEFT JOIN venues v ON v.id = t.venue_id
     WHERE t.event_name = '$eventNameEsc'
     LIMIT 1"
);

if (!$eventQuery || mysqli_num_rows($eventQuery) == 0) {
    die("Event not found in database.");
}

$event = mysqli_fetch_assoc($eventQuery);
$eventId = (int)$event['id'];
$venueId = (int)$event['venue_id'];

/*
|--------------------------------------------------------------------------
| GET THE SELECTED GROUP DATA
|--------------------------------------------------------------------------
*/
$groupEsc = mysqli_real_escape_string($conn, $selectedGroup);

$groupQuery = mysqli_query(
    $conn,
    "SELECT *
     FROM event_seat_groups
     WHERE event_id = '$eventId'
     AND group_name = '$groupEsc'
     LIMIT 1"
);

if (!$groupQuery || mysqli_num_rows($groupQuery) == 0) {
    die("Seat group not found.");
}

$groupData = mysqli_fetch_assoc($groupQuery);
$groupId = (int)$groupData['id'];
$groupColor = $groupData['group_color'];

/*
|--------------------------------------------------------------------------
| GET SEAT STATUS
|--------------------------------------------------------------------------
*/
$seatNumber = (int)preg_replace('/^\D+/', '', $selectedSeat);

$seatQuery = mysqli_query(
    $conn,
    "SELECT *
     FROM event_group_seats
     WHERE group_id = '$groupId'
     AND seat_number = '$seatNumber'
     LIMIT 1"
);

if (!$seatQuery || mysqli_num_rows($seatQuery) == 0) {
    die("Selected seat not found.");
}

$seatData = mysqli_fetch_assoc($seatQuery);

if ($seatData['status'] === 'booked') {
    die("Selected seat is already booked.");
}

if ($seatData['status'] === 'unavailable') {
    die("Selected seat is unavailable.");
}

/*
|--------------------------------------------------------------------------
| TOTAL COMPUTATION
|--------------------------------------------------------------------------
*/
$basePrice = (float)$event['ticket_price'];
$serviceFee = $serviceFeeSession !== null ? $serviceFeeSession : (float)$event['service_fee'];
$total = $selectedTotalSession !== null ? $selectedTotalSession : ($basePrice + $serviceFee + $selectedGroupPrice);

/*
|--------------------------------------------------------------------------
| SAVE EVENT ID AND TOTALS
|--------------------------------------------------------------------------
*/
$_SESSION['event_id'] = $eventId;
$_SESSION['venue_id'] = $venueId;
$_SESSION['selected_group_id'] = $groupId;
$_SESSION['service_fee'] = $serviceFee;
$_SESSION['selected_group_price'] = $selectedGroupPrice;
$_SESSION['selected_total'] = $total;

/*
|--------------------------------------------------------------------------
| HANDLE PAYMENT SUBMIT
|--------------------------------------------------------------------------
*/
if (isset($_POST['next'])) {
    $paymentMethod = trim($_POST['payment'] ?? '');

    $allowedMethods = ['GCASH', 'MAYA', 'PAYPAL', 'BANK'];

    if ($paymentMethod === '' || !in_array($paymentMethod, $allowedMethods, true)) {
        $_SESSION['payment_error'] = "Please select a valid payment method.";
        header("Location: payment.php");
        exit();
    }

    $_SESSION['payment_method'] = $paymentMethod;

    header("Location: confirm.php");
    exit();
}

$paymentError = $_SESSION['payment_error'] ?? '';
unset($_SESSION['payment_error']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment</title>
    <link rel="stylesheet" href="general_style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">

    <style>
        body{font-family:Arial;background:#f5f5f5;margin:0;padding:20px;}
        .main_cont{max-width:900px;margin:auto;}
        .info-box{margin-bottom:25px;padding:15px;border:1px solid #ddd;border-radius:10px;background:#fff;line-height:1.8;}
        .summary-line{margin-top:4px;}
        .payment-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:10px;}
        .payment{display:flex;align-items:center;justify-content:center;padding:16px;border:2px solid #c9c9c9;background:#fff;border-radius:12px;cursor:pointer;user-select:none;font-weight:bold;min-height:64px;transition:0.15s ease;}
        .payment.selected{background:#16a34a;color:#fff;border-color:#16a34a;transform:translateY(-1px);}
        .payment small{display:block;font-weight:normal;opacity:0.85;margin-top:3px;}
        button{padding:12px 20px;cursor:pointer;margin-top:12px;border:none;border-radius:10px;background:#111827;color:#fff;}
        button:disabled{opacity:0.5;cursor:not-allowed;}
        .error-box{margin-bottom:15px;padding:10px 12px;border:1px solid #ffb3b3;background:#fff2f2;color:#a40000;border-radius:8px;}
        .seat-badge{display:inline-block;padding:4px 10px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;}
        .group-color{display:inline-block;width:12px;height:12px;border-radius:50%;vertical-align:middle;margin-right:6px;border:1px solid rgba(0,0,0,.2);}
        @media (max-width: 640px){.payment-grid{grid-template-columns:1fr;}}
    </style>

    <script>
        let selectedPayment = "";

        function formatMoney(value) {
            return "₱" + Number(value).toFixed(2);
        }

        function selectPayment(method) {
            selectedPayment = method;
            document.getElementById("paymentInput").value = method;

            document.querySelectorAll(".payment").forEach(opt => opt.classList.remove("selected"));
            document.getElementById(method).classList.add("selected");
            document.getElementById("nextBtn").disabled = false;
        }
    </script>
</head>
<body>
    <div class="main_cont">
        <h2>Select Payment Method</h2>

        <?php if ($paymentError !== '') { ?>
            <div class="error-box"><?php echo htmlspecialchars($paymentError); ?></div>
        <?php } ?>

        <div class="info-box">
            <div class="summary-line"><b>Event Type:</b> <?php echo htmlspecialchars($event['event_type']); ?></div>
            <div class="summary-line"><b>Event:</b> <?php echo htmlspecialchars($event['event_name']); ?></div>
            <div class="summary-line"><b>Venue:</b> <?php echo htmlspecialchars($event['venue_name']); ?></div>
            <div class="summary-line"><b>Date:</b> <?php echo htmlspecialchars($event['event_date']); ?></div>
            <div class="summary-line"><b>Selected Group:</b> <span class="seat-badge"><span class="group-color" style="background:<?php echo htmlspecialchars($groupColor); ?>;"></span><?php echo htmlspecialchars($selectedGroup); ?></span></div>
            <div class="summary-line"><b>Selected Seat:</b> <span class="seat-badge"><?php echo htmlspecialchars($selectedSeat); ?></span></div>
            <div class="summary-line"><b>Base Ticket Price:</b> ₱<?php echo number_format($basePrice, 2); ?></div>
            <div class="summary-line"><b>Group Price:</b> ₱<?php echo number_format($selectedGroupPrice, 2); ?></div>
            <div class="summary-line"><b>Service Fee:</b> ₱<?php echo number_format($serviceFee, 2); ?></div>
            <div class="summary-line"><b>Total:</b> ₱<?php echo number_format($total, 2); ?></div>
        </div>

        <div class="payment-grid">
            <div id="GCASH" class="payment" onclick="selectPayment('GCASH')">GCash</div>
            <div id="MAYA" class="payment" onclick="selectPayment('MAYA')">Maya</div>
            <div id="PAYPAL" class="payment" onclick="selectPayment('PAYPAL')">PayPal</div>
            <div id="BANK" class="payment" onclick="selectPayment('BANK')">Bank Transfer</div>
        </div>

        <form method="POST">
            <input type="hidden" name="payment" id="paymentInput">
            <button type="submit" name="next" id="nextBtn" disabled>Proceed</button>
        </form>
    </div>
</body>
</html>
