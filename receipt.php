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
| VALIDATE SESSION
|--------------------------------------------------------------------------
| Receipt should work from the new multi-seat flow.
|--------------------------------------------------------------------------
*/
if (
    !isset($_SESSION['event_id']) ||
    !isset($_SESSION['event_name']) ||
    !isset($_SESSION['payment_method']) ||
    !isset($_SESSION['ticket_number']) ||
    !isset($_SESSION['booking_id'])
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
$paymentMethod = trim($_SESSION['payment_method']);
$ticketNumber = trim($_SESSION['ticket_number']);
$bookingId = (int)$_SESSION['booking_id'];

$selectedSeats = [];
if (isset($_SESSION['selected_seats']) && is_array($_SESSION['selected_seats'])) {
    $selectedSeats = $_SESSION['selected_seats'];
}

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

if (mysqli_num_rows($eventQuery) === 0) {
    die("Event not found.");
}

$event = mysqli_fetch_assoc($eventQuery);
$eventType = $event['event_type'];
$eventDate = $event['event_date'];
$venueName = $event['venue_name'] ?? '-';
$basePrice = (float)$event['ticket_price'];

/*
|--------------------------------------------------------------------------
| GET BOOKING DETAILS
|--------------------------------------------------------------------------
*/
$bookingData = [
    'subtotal' => 0.00,
    'service_fee' => 0.00,
    'total' => 0.00,
    'reference_number' => $ticketNumber,
    'payment_method' => $paymentMethod,
];

$bookingQuery = mysqli_query(
    $conn,
    "SELECT *
     FROM bookings
     WHERE id = '$bookingId'
     LIMIT 1"
);

if ($bookingQuery && mysqli_num_rows($bookingQuery) > 0) {
    $bookingRow = mysqli_fetch_assoc($bookingQuery);
    $bookingData['subtotal'] = isset($bookingRow['subtotal']) ? (float)$bookingRow['subtotal'] : 0.00;
    $bookingData['service_fee'] = isset($bookingRow['service_fee']) ? (float)$bookingRow['service_fee'] : 0.00;
    $bookingData['total'] = isset($bookingRow['total']) ? (float)$bookingRow['total'] : 0.00;
    $bookingData['reference_number'] = $bookingRow['reference_number'] ?? $ticketNumber;
    $bookingData['payment_method'] = $bookingRow['payment_method'] ?? $paymentMethod;
}

if ($bookingData['total'] <= 0) {
    $bookingData['total'] = isset($_SESSION['selected_total']) ? (float)$_SESSION['selected_total'] : 0.00;
}

if ($bookingData['service_fee'] <= 0 && isset($_SESSION['service_fee'])) {
    $bookingData['service_fee'] = (float)$_SESSION['service_fee'];
}

/*
|--------------------------------------------------------------------------
| GET BOOKED SEATS FOR THIS BOOKING
|--------------------------------------------------------------------------
*/
$receiptSeats = [];

$bookedSeatsQuery = mysqli_query(
    $conn,
    "SELECT bs.seat_number, bs.price, g.group_name, g.group_color, g.id AS group_id
     FROM booked_seats bs
     INNER JOIN event_seat_groups g ON g.id = bs.group_id
     WHERE bs.booking_id = '$bookingId'
     ORDER BY g.id ASC, bs.seat_number ASC"
);

if ($bookedSeatsQuery && mysqli_num_rows($bookedSeatsQuery) > 0) {
    while ($row = mysqli_fetch_assoc($bookedSeatsQuery)) {
        $receiptSeats[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| FALLBACK TO SESSION SEATS IF NEEDED
|--------------------------------------------------------------------------
*/
if (empty($receiptSeats) && !empty($selectedSeats)) {
    foreach ($selectedSeats as $seat) {
        $receiptSeats[] = [
            'seat_number' => $seat['seatNumber'] ?? 0,
            'price' => $seat['groupPrice'] ?? 0,
            'group_name' => $seat['groupName'] ?? '-',
            'group_color' => '#3b82f6',
            'group_id' => $seat['groupId'] ?? 0,
        ];
    }
}

/*
|--------------------------------------------------------------------------
| GROUP / SUMMARY VALUES
|--------------------------------------------------------------------------
*/
$selectedSeatCodes = [];
$selectedGroupNames = [];
$totalGroupPrice = 0.00;

foreach ($receiptSeats as $seatRow) {
    $groupName = $seatRow['group_name'] ?? '-';
    $seatNumber = (int)($seatRow['seat_number'] ?? 0);
    $price = isset($seatRow['price']) ? (float)$seatRow['price'] : 0.00;

    $selectedSeatCodes[] = $groupName . $seatNumber;

    if (!in_array($groupName, $selectedGroupNames, true)) {
        $selectedGroupNames[] = $groupName;
    }

    $totalGroupPrice += $price;
}

$seatCount = count($receiptSeats);
$serviceFee = (float)$bookingData['service_fee'];
$totalPaid = (float)$bookingData['total'];
$subtotal = (float)$bookingData['subtotal'];

if ($subtotal <= 0 && $seatCount > 0) {
    $subtotal = ($basePrice * $seatCount) + $totalGroupPrice;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Receipt</title>
    <link rel="stylesheet" href="general_style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <style>
        body{
            font-family: Arial;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        .main_cont{
            max-width: 900px;
            margin: auto;
        }
        .receipt-box{
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 0 10px rgba(0,0,0,.08);
            border: 1px solid #e5e7eb;
        }
        h2{
            margin-top: 0;
        }
        button{
            padding: 10px 20px;
            cursor: pointer;
            margin-top: 20px;
            border: none;
            border-radius: 8px;
            background: #111827;
            color: #fff;
        }
        .line{
            margin-bottom: 8px;
            line-height: 1.6;
        }
        .badge{
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            margin: 2px 4px 2px 0;
        }
        .group-dot{
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            vertical-align: middle;
            margin-right: 6px;
            border: 1px solid rgba(0,0,0,.2);
        }
        .hr{
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 16px 0;
        }
        .seat-list{
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 6px;
        }
        .error-box{
            margin-bottom: 15px;
            padding: 10px 12px;
            border: 1px solid #ffb3b3;
            background: #fff2f2;
            color: #a40000;
            border-radius: 8px;
        }
    </style>
</head>
<body>
<div class="main_cont">
    <div class="receipt-box">
        <h2>Booking Receipt</h2>

        <div class="line"><b>Ticket Number:</b> <?php echo h($ticketNumber); ?></div>
        <div class="line"><b>Booking ID:</b> <?php echo (int)$bookingId; ?></div>

        <hr class="hr">

        <div class="line"><b>Event Type:</b> <?php echo h($eventType); ?></div>
        <div class="line"><b>Event Name:</b> <?php echo h($eventName); ?></div>
        <div class="line"><b>Venue:</b> <?php echo h($venueName); ?></div>
        <div class="line"><b>Date:</b> <?php echo h($eventDate); ?></div>

        <div class="line"><b>Payment Method:</b> <?php echo h($paymentMethod); ?></div>

        <div class="line"><b>Selected Seats:</b></div>
        <div class="seat-list">
            <?php if (!empty($selectedSeatCodes)) { ?>
                <?php foreach ($selectedSeatCodes as $seatCode) { ?>
                    <span class="badge"><?php echo h($seatCode); ?></span>
                <?php } ?>
            <?php } else { ?>
                <span class="badge">-</span>
            <?php } ?>
        </div>

        <div class="line" style="margin-top:12px;"><b>Selected Groups:</b></div>
        <div class="seat-list">
            <?php if (!empty($selectedGroupNames)) { ?>
                <?php foreach ($selectedGroupNames as $groupName) { ?>
                    <span class="badge"><?php echo h($groupName); ?></span>
                <?php } ?>
            <?php } else { ?>
                <span class="badge">-</span>
            <?php } ?>
        </div>

        <hr class="hr">

        <div class="line"><b>Base Ticket Price:</b> ₱<?php echo number_format($basePrice, 2); ?></div>
        <div class="line"><b>Seat Count:</b> <?php echo (int)$seatCount; ?></div>
        <div class="line"><b>Subtotal:</b> ₱<?php echo number_format($subtotal, 2); ?></div>
        <div class="line"><b>Service Fee:</b> ₱<?php echo number_format($serviceFee, 2); ?></div>
        <div class="line"><b>Total Paid:</b> ₱<?php echo number_format($totalPaid, 2); ?></div>

        <?php if (!empty($receiptSeats)) { ?>
            <hr class="hr">
            <div class="line"><b>Seat Details:</b></div>
            <?php foreach ($receiptSeats as $seatRow) { ?>
                <?php
                    $groupColor = $seatRow['group_color'] ?? '#3b82f6';
                    $groupName = $seatRow['group_name'] ?? '-';
                    $seatNumber = (int)($seatRow['seat_number'] ?? 0);
                    $seatPrice = (float)($seatRow['price'] ?? 0);
                ?>
                <div class="line">
                    <span class="badge">
                        <span class="group-dot" style="background:<?php echo h($groupColor); ?>;"></span>
                        <?php echo h($groupName . $seatNumber); ?>
                    </span>
                    ₱<?php echo number_format($seatPrice, 2); ?>
                </div>
            <?php } ?>
        <?php } ?>

        <form method="POST">
            <button type="submit" name="reset">Book Again</button>
        </form>
    </div>
</div>
</body>
</html>