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
    !isset($_SESSION['selected_seats']) ||
    !is_array($_SESSION['selected_seats']) ||
    empty($_SESSION['selected_seats'])
) {
    header("Location: index.php");
    exit();
}

$eventName = trim($_SESSION['event_name']);
$selectedSeats = $_SESSION['selected_seats'];

$serviceFeeSession = isset($_SESSION['service_fee']) ? (float)$_SESSION['service_fee'] : null;
$selectedTotalSession = isset($_SESSION['selected_total']) ? (float)$_SESSION['selected_total'] : null;
$selectedTicketPriceSession = isset($_SESSION['selected_ticket_price']) ? (float)$_SESSION['selected_ticket_price'] : null;
$selectedSeatCountSession = isset($_SESSION['selected_seat_count']) ? (int)$_SESSION['selected_seat_count'] : count($selectedSeats);

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
| PREPARE / VALIDATE SELECTED SEATS
|--------------------------------------------------------------------------
*/
$validatedSeats = [];
$totalGroupPrice = 0.0;
$seatCodesDisplay = [];
$groupNamesDisplay = [];
$seenSeats = [];

foreach ($selectedSeats as $seat) {
    $seatCode = trim($seat['seatCode'] ?? '');
    $seatNumber = (int)($seat['seatNumber'] ?? 0);
    $groupId = (int)($seat['groupId'] ?? 0);
    $groupName = trim($seat['groupName'] ?? '');
    $groupPrice = isset($seat['groupPrice']) ? (float)$seat['groupPrice'] : 0.0;

    if ($seatCode === '' || $seatNumber <= 0 || $groupId <= 0 || $groupName === '') {
        $_SESSION['payment_error'] = "Invalid seat data detected.";
        header("Location: seat.php");
        exit();
    }

    $seatUniqueKey = $groupId . ':' . $seatNumber;
    if (isset($seenSeats[$seatUniqueKey])) {
        $_SESSION['payment_error'] = "Duplicate seat detected.";
        header("Location: seat.php");
        exit();
    }
    $seenSeats[$seatUniqueKey] = true;

    $groupEsc = mysqli_real_escape_string($conn, $groupName);

    $groupQuery = mysqli_query(
        $conn,
        "SELECT *
         FROM event_seat_groups
         WHERE event_id = '$eventId'
         AND group_name = '$groupEsc'
         AND id = '$groupId'
         LIMIT 1"
    );

    if (!$groupQuery || mysqli_num_rows($groupQuery) == 0) {
        $_SESSION['payment_error'] = "Seat group not found.";
        header("Location: seat.php");
        exit();
    }

    $groupData = mysqli_fetch_assoc($groupQuery);

    $seatQuery = mysqli_query(
        $conn,
        "SELECT *
         FROM event_group_seats
         WHERE group_id = '$groupId'
         AND seat_number = '$seatNumber'
         LIMIT 1"
    );

    if (!$seatQuery || mysqli_num_rows($seatQuery) == 0) {
        $_SESSION['payment_error'] = "Selected seat not found.";
        header("Location: seat.php");
        exit();
    }

    $seatData = mysqli_fetch_assoc($seatQuery);

    if ($seatData['status'] === 'booked') {
        $_SESSION['payment_error'] = $seatCode . " is already booked.";
        header("Location: seat.php");
        exit();
    }

    if ($seatData['status'] === 'unavailable') {
        $_SESSION['payment_error'] = $seatCode . " is unavailable.";
        header("Location: seat.php");
        exit();
    }

    $validatedSeats[] = [
        'seatCode'   => $seatCode,
        'seatNumber' => $seatNumber,
        'groupId'    => $groupId,
        'groupName'  => $groupName,
        'groupPrice' => $groupPrice
    ];

    $seatCodesDisplay[] = $seatCode;

    if (!in_array($groupName, $groupNamesDisplay, true)) {
        $groupNamesDisplay[] = $groupName;
    }

    $totalGroupPrice += $groupPrice;
}

if (empty($validatedSeats)) {
    $_SESSION['payment_error'] = "No valid seat selected.";
    header("Location: seat.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| TOTAL COMPUTATION
|--------------------------------------------------------------------------
*/
$basePrice = isset($selectedTicketPriceSession) ? $selectedTicketPriceSession : (float)$event['ticket_price'];
$serviceFee = $serviceFeeSession !== null ? $serviceFeeSession : (float)$event['service_fee'];
$seatCount = $selectedSeatCountSession > 0 ? $selectedSeatCountSession : count($validatedSeats);

$total = $selectedTotalSession !== null
    ? $selectedTotalSession
    : (($basePrice * $seatCount) + $serviceFee + $totalGroupPrice);

/*
|--------------------------------------------------------------------------
| SAVE EVENT / ORDER DATA TO SESSION
|--------------------------------------------------------------------------
*/
$_SESSION['event_id'] = $eventId;
$_SESSION['venue_id'] = $venueId;
$_SESSION['service_fee'] = $serviceFee;
$_SESSION['selected_total'] = $total;
$_SESSION['selected_ticket_price'] = $basePrice;
$_SESSION['selected_seat_count'] = $seatCount;
$_SESSION['selected_group_price'] = $totalGroupPrice;
$_SESSION['selected_seats'] = $validatedSeats;

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

function formatSeatList(array $seats): string
{
    return implode(', ', $seats);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment</title>
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
        .info-box{
            margin-bottom: 25px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            background: #fff;
            line-height: 1.8;
        }
        .summary-line{
            margin-top: 4px;
        }
        .payment-grid{
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 10px;
        }
        .payment{
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            border: 2px solid #c9c9c9;
            background: #fff;
            border-radius: 12px;
            cursor: pointer;
            user-select: none;
            font-weight: bold;
            min-height: 64px;
            transition: 0.15s ease;
            text-align: center;
        }
        .payment.selected{
            background: #16a34a;
            color: #fff;
            border-color: #16a34a;
            transform: translateY(-1px);
        }
        .payment small{
            display: block;
            font-weight: normal;
            opacity: 0.85;
            margin-top: 3px;
        }
        button{
            padding: 12px 20px;
            cursor: pointer;
            margin-top: 12px;
            border: none;
            border-radius: 10px;
            background: #111827;
            color: #fff;
        }
        button:disabled{
            opacity: 0.5;
            cursor: not-allowed;
        }
        .error-box{
            margin-bottom: 15px;
            padding: 10px 12px;
            border: 1px solid #ffb3b3;
            background: #fff2f2;
            color: #a40000;
            border-radius: 8px;
        }
        .seat-badge{
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            margin: 2px 4px 2px 0;
        }
        .group-color{
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            vertical-align: middle;
            margin-right: 6px;
            border: 1px solid rgba(0,0,0,.2);
        }
        .seat-list{
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        @media (max-width: 640px){
            .payment-grid{
                grid-template-columns: 1fr;
            }
        }
    </style>

    <script>
        let selectedPayment = "";

        function selectPayment(method) {
            selectedPayment = method;
            document.getElementById("paymentInput").value = method;

            document.querySelectorAll(".payment").forEach(opt => opt.classList.remove("selected"));
            const el = document.getElementById(method);
            if (el) {
                el.classList.add("selected");
            }

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

            <div class="summary-line">
                <b>Selected Seats:</b>
                <div class="seat-list" style="margin-top:6px;">
                    <?php foreach ($validatedSeats as $seatRow) { ?>
                        <span class="seat-badge">
                            <?php echo htmlspecialchars($seatRow['seatCode']); ?>
                        </span>
                    <?php } ?>
                </div>
            </div>

            <div class="summary-line">
                <b>Selected Groups:</b>
                <div class="seat-list" style="margin-top:6px;">
                    <?php foreach ($groupNamesDisplay as $groupNameDisplay) { ?>
                        <span class="seat-badge">
                            <?php echo htmlspecialchars($groupNameDisplay); ?>
                        </span>
                    <?php } ?>
                </div>
            </div>

            <div class="summary-line"><b>Base Ticket Price:</b> ₱<?php echo number_format($basePrice, 2); ?></div>
            <div class="summary-line"><b>Seat Count:</b> <?php echo (int)$seatCount; ?></div>
            <div class="summary-line"><b>Group Price:</b> ₱<?php echo number_format($totalGroupPrice, 2); ?></div>
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