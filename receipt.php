<?php
include 'session_start.php';
include 'db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
|--------------------------------------------------------------------------
| RESET BOOKING FLOW
|--------------------------------------------------------------------------
*/
if (isset($_POST['reset'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| VALIDATE SESSION
|--------------------------------------------------------------------------
*/
if (
    !isset($_SESSION['event_id']) ||
    !isset($_SESSION['event_name']) ||
    !isset($_SESSION['selected_seat']) ||
    !isset($_SESSION['selected_group']) ||
    !isset($_SESSION['payment_method']) ||
    !isset($_SESSION['ticket_number'])
) {
    header("Location: index.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| SESSION DATA
|--------------------------------------------------------------------------
*/
$eventId = (int)$_SESSION['event_id'];
$eventName = trim($_SESSION['event_name']);
$selectedSeat = trim($_SESSION['selected_seat']);
$selectedGroup = trim($_SESSION['selected_group']);
$paymentMethod = trim($_SESSION['payment_method']);
$ticketNumber = trim($_SESSION['ticket_number']);
$selectedGroupPrice = isset($_SESSION['selected_group_price']) ? (float)$_SESSION['selected_group_price'] : 0.00;
$serviceFee = isset($_SESSION['service_fee']) ? (float)$_SESSION['service_fee'] : 0.00;
$total = isset($_SESSION['selected_total']) ? (float)$_SESSION['selected_total'] : 0.00;
$selectedGroupId = isset($_SESSION['selected_group_id']) ? (int)$_SESSION['selected_group_id'] : 0;

/*
|--------------------------------------------------------------------------
| GET EVENT DETAILS
|--------------------------------------------------------------------------
*/
$eventQuery = mysqli_query(
    $conn,
    "SELECT t.*, v.venue_name
     FROM tickets t
     LEFT JOIN venues v ON v.id = t.venue_id
     WHERE t.id = '$eventId'
     LIMIT 1"
);

if (!$eventQuery) {
    die("Event Query Error: " . mysqli_error($conn));
}

if (mysqli_num_rows($eventQuery) == 0) {
    die("Event not found.");
}

$event = mysqli_fetch_assoc($eventQuery);
$eventType = $event['event_type'];
$eventDate = $event['event_date'];
$basePrice = (float)$event['ticket_price'];
$venueName = $event['venue_name'] ?? '-';

/*
|--------------------------------------------------------------------------
| GET GROUP DETAILS
|--------------------------------------------------------------------------
*/
$groupColor = '#3b82f6';
if ($selectedGroupId > 0) {
    $groupQuery = mysqli_query(
        $conn,
        "SELECT group_color
         FROM event_seat_groups
         WHERE id = '$selectedGroupId'
         AND event_id = '$eventId'
         LIMIT 1"
    );

    if ($groupQuery && mysqli_num_rows($groupQuery) > 0) {
        $groupData = mysqli_fetch_assoc($groupQuery);
        $groupColor = $groupData['group_color'] ?? $groupColor;
    }
}

/*
|--------------------------------------------------------------------------
| TOTAL FALLBACK
|--------------------------------------------------------------------------
*/
if ($total <= 0) {
    $total = $basePrice + $serviceFee + $selectedGroupPrice;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Receipt</title>
    <link rel="stylesheet" href="general_style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <style>
        body{font-family:Arial;background:#f5f5f5;margin:0;padding:20px;}
        .main_cont{max-width:900px;margin:auto;}
        .receipt-box{background:#fff;padding:25px;border-radius:12px;box-shadow:0 0 10px rgba(0,0,0,.08);border:1px solid #e5e7eb;}
        h2{margin-top:0;}
        button{padding:10px 20px;cursor:pointer;margin-top:20px;border:none;border-radius:8px;background:#111827;color:#fff;}
        .line{margin-bottom:8px;line-height:1.6;}
        .badge{display:inline-block;padding:4px 10px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;}
        .group-dot{display:inline-block;width:12px;height:12px;border-radius:50%;vertical-align:middle;margin-right:6px;border:1px solid rgba(0,0,0,.2);}
        .hr{border:none;border-top:1px solid #e5e7eb;margin:16px 0;}
    </style>
</head>
<body>
<div class="main_cont">
    <div class="receipt-box">
        <h2>Booking Receipt</h2>

        <div class="line"><b>Ticket Number:</b> <?php echo htmlspecialchars($ticketNumber); ?></div>

        <hr class="hr">

        <div class="line"><b>Event Type:</b> <?php echo htmlspecialchars($eventType); ?></div>
        <div class="line"><b>Event Name:</b> <?php echo htmlspecialchars($eventName); ?></div>
        <div class="line"><b>Venue:</b> <?php echo htmlspecialchars($venueName); ?></div>
        <div class="line"><b>Date:</b> <?php echo htmlspecialchars($eventDate); ?></div>

        <div class="line">
            <b>Selected Group:</b>
            <span class="badge">
                <span class="group-dot" style="background:<?php echo htmlspecialchars($groupColor); ?>;"></span>
                <?php echo htmlspecialchars($selectedGroup); ?>
            </span>
        </div>

        <div class="line"><b>Selected Seat:</b> <?php echo htmlspecialchars($selectedSeat); ?></div>
        <div class="line"><b>Payment Method:</b> <?php echo htmlspecialchars($paymentMethod); ?></div>

        <hr class="hr">

        <div class="line"><b>Base Ticket Price:</b> ₱<?php echo number_format($basePrice, 2); ?></div>
        <div class="line"><b>Group Price:</b> ₱<?php echo number_format($selectedGroupPrice, 2); ?></div>
        <div class="line"><b>Service Fee:</b> ₱<?php echo number_format($serviceFee, 2); ?></div>
        <div class="line"><b>Total Paid:</b> ₱<?php echo number_format($total, 2); ?></div>

        <form method="POST">
            <button type="submit" name="reset">Book Again</button>
        </form>
    </div>
</div>
</body>
</html>
