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
    !isset($_SESSION['event_id']) ||
    !isset($_SESSION['event_name']) ||
    !isset($_SESSION['selected_seats']) ||
    !is_array($_SESSION['selected_seats']) ||
    empty($_SESSION['selected_seats']) ||
    !isset($_SESSION['payment_method'])
) {
    header("Location: index.php");
    exit();
}

$eventId = (int)$_SESSION['event_id'];
$eventName = trim($_SESSION['event_name']);
$paymentMethod = trim($_SESSION['payment_method']);
$selectedSeats = $_SESSION['selected_seats'];

$eventNameEsc = mysqli_real_escape_string($conn, $eventName);

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
| VALIDATE SELECTED SEATS
|--------------------------------------------------------------------------
*/
$validatedSeats = [];
$seatCodesDisplay = [];
$groupNamesDisplay = [];
$seenSeats = [];
$totalGroupPrice = 0.00;

foreach ($selectedSeats as $seat) {
    $seatCode = trim($seat['seatCode'] ?? '');
    $seatNumber = (int)($seat['seatNumber'] ?? 0);
    $groupId = (int)($seat['groupId'] ?? 0);
    $groupName = trim($seat['groupName'] ?? '');
    $groupPriceFromSession = isset($seat['groupPrice']) ? (float)$seat['groupPrice'] : 0.00;

    if ($seatCode === '' || $seatNumber <= 0 || $groupId <= 0 || $groupName === '') {
        die("Invalid seat data.");
    }

    $seatKey = $groupId . ':' . $seatNumber;
    if (isset($seenSeats[$seatKey])) {
        die("Duplicate seat detected.");
    }
    $seenSeats[$seatKey] = true;

    $groupNameEsc = mysqli_real_escape_string($conn, $groupName);

    $groupQuery = mysqli_query(
        $conn,
        "SELECT *
         FROM event_seat_groups
         WHERE id = '$groupId'
         AND event_id = '$eventId'
         AND group_name = '$groupNameEsc'
         LIMIT 1"
    );

    if (!$groupQuery || mysqli_num_rows($groupQuery) === 0) {
        die("Seat group not found.");
    }

    $groupData = mysqli_fetch_assoc($groupQuery);
    $dbGroupPrice = (float)$groupData['group_price'];

    if ($seatCode !== ($groupName . $seatNumber)) {
        die("Seat validation failed.");
    }

    $seatQuery = mysqli_query(
        $conn,
        "SELECT *
         FROM event_group_seats
         WHERE group_id = '$groupId'
         AND seat_number = '$seatNumber'
         LIMIT 1"
    );

    if (!$seatQuery || mysqli_num_rows($seatQuery) === 0) {
        die("Selected seat not found.");
    }

    $seatData = mysqli_fetch_assoc($seatQuery);

    if ($seatData['status'] === 'booked') {
        die($seatCode . " is already booked.");
    }

    if ($seatData['status'] === 'unavailable') {
        die($seatCode . " is unavailable.");
    }

    $validatedSeats[] = [
        'seatCode'   => $seatCode,
        'seatNumber' => $seatNumber,
        'groupId'    => $groupId,
        'groupName'  => $groupName,
        'groupPrice' => $dbGroupPrice
    ];

    $seatCodesDisplay[] = $seatCode;

    if (!in_array($groupName, $groupNamesDisplay, true)) {
        $groupNamesDisplay[] = $groupName;
    }

    $totalGroupPrice += $dbGroupPrice;
}

$seatCount = count($validatedSeats);
$serviceFee = isset($_SESSION['service_fee']) ? (float)$_SESSION['service_fee'] : (float)$event['service_fee'];
$selectedTotal = ($basePrice * $seatCount) + $serviceFee + $totalGroupPrice;

/*
|--------------------------------------------------------------------------
| SAVE UPDATED SUMMARY TO SESSION
|--------------------------------------------------------------------------
*/
$_SESSION['selected_seats'] = $validatedSeats;
$_SESSION['selected_ticket_price'] = $basePrice;
$_SESSION['selected_seat_count'] = $seatCount;
$_SESSION['selected_group_price'] = $totalGroupPrice;
$_SESSION['service_fee'] = $serviceFee;
$_SESSION['selected_total'] = $selectedTotal;
$_SESSION['event_id'] = $eventId;

/*
|--------------------------------------------------------------------------
| HANDLE CONFIRM BOOKING
|--------------------------------------------------------------------------
*/
$confirmError = '';

if (isset($_POST['confirm'])) {

    $customerId = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : 0;
    if ($customerId <= 0) {
        $confirmError = "Customer account is missing.";
    } else {
        $conn->begin_transaction();

        try {
            $lockSeatStmt = $conn->prepare(
                "SELECT status
                 FROM event_group_seats
                 WHERE group_id = ? AND seat_number = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            $insertBookingStmt = $conn->prepare(
                "INSERT INTO bookings
                    (customer_id, event_id, reference_number, subtotal, service_fee, total, payment_method, payment_status)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, 'paid')"
            );

            $insertSeatStmt = $conn->prepare(
                "INSERT INTO booked_seats
                    (booking_id, group_id, seat_number, price)
                 VALUES
                    (?, ?, ?, ?)"
            );

            $updateSeatStmt = $conn->prepare(
                "UPDATE event_group_seats
                 SET status = 'booked'
                 WHERE group_id = ? AND seat_number = ?"
            );

            if (!$lockSeatStmt || !$insertBookingStmt || !$insertSeatStmt || !$updateSeatStmt) {
                throw new Exception("Database prepare failed.");
            }

            foreach ($validatedSeats as $seatInfo) {
                $gid = (int)$seatInfo['groupId'];
                $snum = (int)$seatInfo['seatNumber'];

                $lockSeatStmt->bind_param("ii", $gid, $snum);
                $lockSeatStmt->execute();
                $seatLockResult = $lockSeatStmt->get_result();

                if (!$seatLockResult || $seatLockResult->num_rows === 0) {
                    throw new Exception($seatInfo['seatCode'] . " not found.");
                }

                $lockedSeat = $seatLockResult->fetch_assoc();

                if ($lockedSeat['status'] === 'booked') {
                    throw new Exception($seatInfo['seatCode'] . " is already booked.");
                }

                if ($lockedSeat['status'] === 'unavailable') {
                    throw new Exception($seatInfo['seatCode'] . " is unavailable.");
                }
            }

            $referenceNumber = 'TICK-' . strtoupper(bin2hex(random_bytes(4)));

            $subtotal = ($basePrice * $seatCount) + $totalGroupPrice;

            $insertBookingStmt->bind_param(
                "iisddds",
                $customerId,
                $eventId,
                $referenceNumber,
                $subtotal,
                $serviceFee,
                $selectedTotal,
                $paymentMethod
            );
            $insertBookingStmt->execute();
            $bookingId = $insertBookingStmt->insert_id;

            foreach ($validatedSeats as $seatInfo) {
                $gid = (int)$seatInfo['groupId'];
                $snum = (int)$seatInfo['seatNumber'];
                $price = (float)$seatInfo['groupPrice'];

                $insertSeatStmt->bind_param("iiid", $bookingId, $gid, $snum, $price);
                $insertSeatStmt->execute();

                $updateSeatStmt->bind_param("ii", $gid, $snum);
                $updateSeatStmt->execute();
            }

            $lockSeatStmt->close();
            $insertBookingStmt->close();
            $insertSeatStmt->close();
            $updateSeatStmt->close();

            $conn->commit();

            $_SESSION['ticket_number'] = $referenceNumber;
            $_SESSION['booking_id'] = $bookingId;
            $_SESSION['selected_seat_codes'] = $seatCodesDisplay;
            $_SESSION['selected_group_names'] = $groupNamesDisplay;

            header("Location: receipt.php");
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $confirmError = $e->getMessage();
        }
    }
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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
        .badge{display:inline-block;padding:4px 10px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;margin:2px 4px 2px 0;}
        .badge-list{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;}
        .section-title{margin-top:14px;}
    </style>
</head>
<body>
<div class="main_cont">
    <div class="summary-box">
        <h2>Confirm Your Booking</h2>

        <?php if ($confirmError !== '') { ?>
            <div class="error-box"><?php echo h($confirmError); ?></div>
        <?php } ?>

        <div class="summary-line"><b>Event Type:</b> <?php echo h($eventType); ?></div>
        <div class="summary-line"><b>Event Name:</b> <?php echo h($eventName); ?></div>
        <div class="summary-line"><b>Venue:</b> <?php echo h($venueName); ?></div>
        <div class="summary-line"><b>Date:</b> <?php echo h($eventDate); ?></div>
        <div class="summary-line"><b>Payment Method:</b> <?php echo h($paymentMethod); ?></div>

        <div class="section-title"><b>Selected Seats:</b></div>
        <div class="badge-list">
            <?php foreach ($validatedSeats as $seatInfo) { ?>
                <span class="badge"><?php echo h($seatInfo['seatCode']); ?></span>
            <?php } ?>
        </div>

        <div class="section-title"><b>Selected Groups:</b></div>
        <div class="badge-list">
            <?php foreach ($groupNamesDisplay as $groupName) { ?>
                <span class="badge"><?php echo h($groupName); ?></span>
            <?php } ?>
        </div>

        <hr>

        <div class="summary-line"><b>Base Ticket Price:</b> ₱<?php echo number_format($basePrice, 2); ?></div>
        <div class="summary-line"><b>Seat Count:</b> <?php echo (int)$seatCount; ?></div>
        <div class="summary-line"><b>Group Price:</b> ₱<?php echo number_format($totalGroupPrice, 2); ?></div>
        <div class="summary-line"><b>Service Fee:</b> ₱<?php echo number_format($serviceFee, 2); ?></div>
        <div class="summary-line"><b>Total:</b> ₱<?php echo number_format($selectedTotal, 2); ?></div>

        <form method="POST">
            <button type="submit" name="confirm">Confirm Booking</button>
        </form>
    </div>
</div>
</body>
</html>