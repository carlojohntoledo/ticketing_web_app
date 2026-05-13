<?php
include 'session_start.php';
include 'db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
|--------------------------------------------------------------------------
| VALIDATE FLOW
|--------------------------------------------------------------------------
*/
if (
    !isset($_SESSION['event_id']) ||
    !isset($_SESSION['event_name']) ||
    !isset($_SESSION['selected_seat']) ||
    !isset($_SESSION['selected_group']) ||
    !isset($_SESSION['payment_method'])
) {
    header("Location: index.php");
    exit();
}

$eventId = (int)$_SESSION['event_id'];
$eventName = trim($_SESSION['event_name']);
$selectedSeat = trim($_SESSION['selected_seat']);
$selectedGroup = trim($_SESSION['selected_group']);
$paymentMethod = trim($_SESSION['payment_method']);
$selectedGroupPrice = isset($_SESSION['selected_group_price']) ? (float)$_SESSION['selected_group_price'] : 0.00;
$serviceFee = isset($_SESSION['service_fee']) ? (float)$_SESSION['service_fee'] : 0.00;
$selectedTotal = isset($_SESSION['selected_total']) ? (float)$_SESSION['selected_total'] : 0.00;
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

if (!$eventQuery || mysqli_num_rows($eventQuery) === 0) {
    die("Event not found.");
}

$event = mysqli_fetch_assoc($eventQuery);
$venueName = $event['venue_name'] ?? '-';
$eventType = $event['event_type'];
$eventDate = $event['event_date'];
$basePrice = (float)$event['ticket_price'];

/*
|--------------------------------------------------------------------------
| GET GROUP DETAILS
|--------------------------------------------------------------------------
*/
$groupQuery = mysqli_query(
    $conn,
    "SELECT *
     FROM event_seat_groups
     WHERE id = '$selectedGroupId'
     AND event_id = '$eventId'
     LIMIT 1"
);

if (!$groupQuery || mysqli_num_rows($groupQuery) === 0) {
    die("Seat group not found.");
}

$groupData = mysqli_fetch_assoc($groupQuery);
$groupPrice = (float)$groupData['group_price'];

/*
|--------------------------------------------------------------------------
| PARSE SEAT NUMBER
|--------------------------------------------------------------------------
*/
$seatNumber = (int)preg_replace('/^\D+/', '', $selectedSeat);
if ($seatNumber <= 0) {
    die("Invalid seat number.");
}

/*
|--------------------------------------------------------------------------
| VALIDATE SELECTED SEAT AGAINST DB
|--------------------------------------------------------------------------
*/
$seatQuery = mysqli_query(
    $conn,
    "SELECT *
     FROM event_group_seats
     WHERE group_id = '$selectedGroupId'
     AND seat_number = '$seatNumber'
     LIMIT 1"
);

if (!$seatQuery || mysqli_num_rows($seatQuery) === 0) {
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
| TOTAL RE-COMPUTE
|--------------------------------------------------------------------------
*/
if ($selectedTotal <= 0) {
    $selectedTotal = $basePrice + $serviceFee + $groupPrice;
}

/*
|--------------------------------------------------------------------------
| HANDLE CONFIRM BOOKING
|--------------------------------------------------------------------------
*/
if (isset($_POST['confirm'])) {
    $conn->begin_transaction();

    try {
        // Re-check the seat inside the transaction.
        $lockSeat = $conn->prepare(
            "SELECT status
             FROM event_group_seats
             WHERE group_id = ? AND seat_number = ?
             LIMIT 1
             FOR UPDATE"
        );
        $lockSeat->bind_param("ii", $selectedGroupId, $seatNumber);
        $lockSeat->execute();
        $seatLockResult = $lockSeat->get_result();

        if ($seatLockResult->num_rows === 0) {
            throw new Exception("Selected seat not found.");
        }

        $lockedSeat = $seatLockResult->fetch_assoc();
        $lockSeat->close();

        if ($lockedSeat['status'] === 'booked') {
            throw new Exception("Seat already booked.");
        }

        if ($lockedSeat['status'] === 'unavailable') {
            throw new Exception("Seat is unavailable.");
        }

        $referenceNumber = 'TICK-' . strtoupper(bin2hex(random_bytes(4)));

        // NOTE:
        // This assumes you already have customer_id stored in session.
        // If you are not using customer login yet, set this earlier in the flow.
        $customerId = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : 0;

        if ($customerId <= 0) {
            // throw new Exception("Customer account is missing.");
			return;
        }

        $subtotal = $basePrice + $groupPrice;

        $insertBooking = $conn->prepare(
            "INSERT INTO bookings
                (customer_id, event_id, reference_number, subtotal, service_fee, total, payment_method, payment_status)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, 'paid')"
        );
        $insertBooking->bind_param(
            "iisddds",
            $customerId,
            $eventId,
            $referenceNumber,
            $subtotal,
            $serviceFee,
            $selectedTotal,
            $paymentMethod
        );
        $insertBooking->execute();
        $bookingId = $insertBooking->insert_id;
        $insertBooking->close();

        $insertSeat = $conn->prepare(
            "INSERT INTO booked_seats
                (booking_id, group_id, seat_number, price)
             VALUES
                (?, ?, ?, ?)"
        );
        $insertSeat->bind_param("iiid", $bookingId, $selectedGroupId, $seatNumber, $groupPrice);
        $insertSeat->execute();
        $insertSeat->close();

        $updateSeat = $conn->prepare(
            "UPDATE event_group_seats
             SET status = 'booked'
             WHERE group_id = ? AND seat_number = ?"
        );
        $updateSeat->bind_param("ii", $selectedGroupId, $seatNumber);
        $updateSeat->execute();
        $updateSeat->close();

        $conn->commit();

        $_SESSION['ticket_number'] = $referenceNumber;
        $_SESSION['booking_id'] = $bookingId;

        header("Location: receipt.php");
        exit();
    } catch (Throwable $e) {
        $conn->rollback();
        $confirmError = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Confirm Booking</title>
    <link rel="stylesheet" href="general_style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <style>
        body{font-family:Arial;background:#f5f5f5;margin:0;padding:20px;}
        .main_cont{max-width:900px;margin:auto;}
        .summary-box{border:1px solid #ccc;padding:20px;border-radius:12px;background:#fff;}
        .summary-line{margin-bottom:8px;line-height:1.6;}
        button{padding:10px 20px;cursor:pointer;margin-top:15px;border:none;border-radius:8px;background:#111827;color:#fff;}
        .error-box{margin-bottom:15px;padding:10px 12px;border:1px solid #ffb3b3;background:#fff2f2;color:#a40000;border-radius:8px;}
        .badge{display:inline-block;padding:4px 10px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;}
    </style>
</head>
<body>
<div class="main_cont">
    <div class="summary-box">
        <h2>Confirm Your Booking</h2>

        <?php if (!empty($confirmError)) { ?>
            <div class="error-box"><?php echo htmlspecialchars($confirmError); ?></div>
        <?php } ?>

        <div class="summary-line"><b>Event Type:</b> <?php echo htmlspecialchars($eventType); ?></div>
        <div class="summary-line"><b>Event Name:</b> <?php echo htmlspecialchars($eventName); ?></div>
        <div class="summary-line"><b>Venue:</b> <?php echo htmlspecialchars($venueName); ?></div>
        <div class="summary-line"><b>Date:</b> <?php echo htmlspecialchars($eventDate); ?></div>
        <div class="summary-line"><b>Seat Group:</b> <span class="badge"><?php echo htmlspecialchars($selectedGroup); ?></span></div>
        <div class="summary-line"><b>Selected Seat:</b> <span class="badge"><?php echo htmlspecialchars($selectedSeat); ?></span></div>
        <div class="summary-line"><b>Payment Method:</b> <?php echo htmlspecialchars($paymentMethod); ?></div>

        <hr>

        <div class="summary-line"><b>Base Ticket Price:</b> ₱<?php echo number_format($basePrice, 2); ?></div>
        <div class="summary-line"><b>Group Price:</b> ₱<?php echo number_format($groupPrice, 2); ?></div>
        <div class="summary-line"><b>Service Fee:</b> ₱<?php echo number_format($serviceFee, 2); ?></div>
        <div class="summary-line"><b>Total:</b> ₱<?php echo number_format($selectedTotal, 2); ?></div>

        <form method="POST">
            <button type="submit" name="confirm">Confirm Booking</button>
        </form>
    </div>
</div>
</body>
</html>
