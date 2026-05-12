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

if (!isset($_SESSION['event_name'])) {
    die("No event selected. Please go back.");
}

$eventName = $_SESSION['event_name'];

/*
|--------------------------------------------------------------------------
| GET EVENT
|--------------------------------------------------------------------------
*/

$eventSql = "
    SELECT *
    FROM tickets
    WHERE event_name='$eventName'
";
$eventResult = mysqli_query($conn, $eventSql);

if (!$eventResult || mysqli_num_rows($eventResult) == 0) {
    die("Event not found in database.");
}

$eventData = mysqli_fetch_assoc($eventResult);

$eventId = (int)$eventData['id'];
$venueId = (int)$eventData['venue_id'];

/*
|--------------------------------------------------------------------------
| GET VENUE
|--------------------------------------------------------------------------
*/

$venueSql = "
    SELECT *
    FROM venues
    WHERE id='$venueId'
";
$venueResult = mysqli_query($conn, $venueSql);

if (!$venueResult || mysqli_num_rows($venueResult) == 0) {
    die("Venue not found.");
}

$venueData = mysqli_fetch_assoc($venueResult);

/*
|--------------------------------------------------------------------------
| GET VENUE SEATS
|--------------------------------------------------------------------------
*/

$seatSql = "
    SELECT *
    FROM venue_seats
    WHERE venue_id='$venueId'
    ORDER BY row_name, seat_number
";
$seatResult = mysqli_query($conn, $seatSql);

if (!$seatResult) {
    die("Seat query failed: " . mysqli_error($conn));
}

/*
|--------------------------------------------------------------------------
| GET ROW PRICES FOR THIS EVENT
|--------------------------------------------------------------------------
*/

$rowPriceMap = [];

$rowPriceSql = "
    SELECT row_name, price
    FROM event_row_prices
    WHERE event_id='$eventId'
";
$rowPriceResult = mysqli_query($conn, $rowPriceSql);

if ($rowPriceResult) {
    while ($rp = mysqli_fetch_assoc($rowPriceResult)) {
        $rowPriceMap[$rp['row_name']] = (float)$rp['price'];
    }
}

/*
|--------------------------------------------------------------------------
| GET TAKEN SEATS
|--------------------------------------------------------------------------
*/

$takenSeats = [];

$takenSql = "
    SELECT seat_code
    FROM bookings
    WHERE event_id='$eventId'
    AND booking_status IN ('reserved', 'paid')
";
$takenResult = mysqli_query($conn, $takenSql);

if ($takenResult) {
    while ($row = mysqli_fetch_assoc($takenResult)) {
        $takenSeats[] = $row['seat_code'];
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE SUBMIT FIRST
|--------------------------------------------------------------------------
*/

if (isset($_POST['next'])) {

    $selectedSeat = trim($_POST['seat'] ?? '');
    $selectedRow = trim($_POST['row_name'] ?? '');

    if ($selectedSeat === '' || $selectedRow === '') {
        $_SESSION['seat_error'] = "Please select a seat.";
        header("Location: seat.php");
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | PARSE SEAT CODE
    |--------------------------------------------------------------------------
    | Example: GENAD25 -> row = GENAD, seat_number = 25
    |--------------------------------------------------------------------------
    */

    if (!preg_match('/^(.+?)(\d+)$/', $selectedSeat, $matches)) {
        $_SESSION['seat_error'] = "Invalid seat selection.";
        header("Location: seat.php");
        exit();
    }

    $parsedRow = $matches[1];
    $seatNumber = (int)$matches[2];

    if (strtoupper($parsedRow) !== strtoupper($selectedRow)) {
        $_SESSION['seat_error'] = "Selected seat does not match the row.";
        header("Location: seat.php");
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY SEAT EXISTS
    |--------------------------------------------------------------------------
    */

    $seatCheckSql = "
        SELECT *
        FROM venue_seats
        WHERE venue_id='$venueId'
        AND row_name='$selectedRow'
        AND seat_number='$seatNumber'
        LIMIT 1
    ";
    $seatCheckResult = mysqli_query($conn, $seatCheckSql);

    if (!$seatCheckResult || mysqli_num_rows($seatCheckResult) == 0) {
        $_SESSION['seat_error'] = "Seat not found.";
        header("Location: seat.php");
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK IF SEAT IS TAKEN
    |--------------------------------------------------------------------------
    */

    $checkSql = "
        SELECT *
        FROM bookings
        WHERE event_id='$eventId'
        AND seat_code='$selectedSeat'
        AND booking_status IN ('reserved', 'paid')
    ";
    $checkResult = mysqli_query($conn, $checkSql);

    if (!$checkResult) {
        die("Seat check failed: " . mysqli_error($conn));
    }

    if (mysqli_num_rows($checkResult) > 0) {
        $_SESSION['seat_error'] = "Seat already taken.";
        header("Location: seat.php");
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | GET ROW PRICE
    |--------------------------------------------------------------------------
    */

    $rowPrice = isset($rowPriceMap[$selectedRow]) ? (float)$rowPriceMap[$selectedRow] : 0.00;

    $ticketPrice = (float)$eventData['ticket_price'];
    $serviceFee = (float)$eventData['service_fee'];
    $selectedTotal = $ticketPrice + $serviceFee + $rowPrice;

    $_SESSION['selected_seat'] = $selectedSeat;
    $_SESSION['selected_row'] = $selectedRow;
    $_SESSION['selected_row_price'] = $rowPrice;
    $_SESSION['service_fee'] = $serviceFee;
    $_SESSION['selected_total'] = $selectedTotal;

    header("Location: payment.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| SESSION ERROR
|--------------------------------------------------------------------------
*/

$seatError = $_SESSION['seat_error'] ?? '';
unset($_SESSION['seat_error']);

/*
|--------------------------------------------------------------------------
| INITIAL VALUES
|--------------------------------------------------------------------------
*/

$baseTicketPrice = (float)$eventData['ticket_price'];
$serviceFee = (float)$eventData['service_fee'];
$initialRowPrice = 0.00;
$initialTotal = $baseTicketPrice + $serviceFee;

?>

<!DOCTYPE html>
<html>
<head>

    <title>Select Seat</title>

    <link rel="stylesheet" href="general_style.css">

    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">

    <style>
        body{
            font-family: Arial;
        }

        .main_cont{
            padding: 20px;
        }

        .event-info{
            margin-bottom: 30px;
            line-height: 1.8;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fafafa;
        }

        .legend{
            margin-bottom: 20px;
        }

        .legend-box{
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 4px;
            margin-right: 5px;
            vertical-align: middle;
        }

        .available-box{
            background: #ccc;
        }

        .selected-box{
            background: green;
        }

        .taken-box{
            background: red;
        }

        .screen{
            max-width: 100%;
            padding: 15px;
            background: #ddd;
            text-align: center;
            border-radius: 10px;
            margin-bottom: 40px;
            font-weight: bold;
        }

        .row{
            margin-bottom: 12px;
        }

        .row-head{
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }

        .row-label{
            display: inline-block;
            font-weight: bold;
        }

        .row-price{
            display: inline-block;
            font-size: 14px;
            color: #444;
        }

        .seat{
            display: inline-block;
            width: 50px;
            padding: 15px 0;
            margin: 5px;
            border-radius: 5px;
            text-align: center;
            cursor: pointer;
            user-select: none;
            background: #ccc;
        }

        .seat.selected{
            background: green;
            color: white;
        }

        .seat.taken{
            background: red;
            color: white;
            cursor: not-allowed;
        }

        button{
            padding: 10px 20px;
            cursor: pointer;
        }

        .summary-line{
            margin-top: 4px;
        }

        .error-box{
            margin-bottom: 15px;
            padding: 10px 12px;
            border: 1px solid #ffb3b3;
            background: #fff2f2;
            color: #a40000;
            border-radius: 6px;
        }
    </style>

    <script>
        const baseTicketPrice = <?php echo json_encode($baseTicketPrice); ?>;
        const serviceFee = <?php echo json_encode($serviceFee); ?>;

        function formatMoney(value) {
            return "₱" + Number(value).toFixed(2);
        }

        function updateSummary(rowPrice, seatCode, rowName) {
            const total = baseTicketPrice + serviceFee + rowPrice;

            document.getElementById("selectedSeatDisplay").textContent = seatCode || "-";
            document.getElementById("selectedRowDisplay").textContent = rowName || "-";
            document.getElementById("rowPriceDisplay").textContent = formatMoney(rowPrice);
            document.getElementById("totalDisplay").textContent = formatMoney(total);
        }

        function selectSeat(el) {

            const seatCode = el.dataset.seatCode;
            const rowName = el.dataset.rowName;
            const rowPrice = parseFloat(el.dataset.rowPrice || "0");

            document.getElementById("seatInput").value = seatCode;
            document.getElementById("rowInput").value = rowName;
            document.getElementById("rowPriceInput").value = rowPrice.toFixed(2);

            const seats = document.getElementsByClassName("seat");
            for (let seat of seats) {
                if (!seat.classList.contains("taken")) {
                    seat.classList.remove("selected");
                }
            }

            el.classList.add("selected");

            updateSummary(rowPrice, seatCode, rowName);

            document.getElementById("nextBtn").disabled = false;
        }
    </script>

</head>

<body>

<div class="main_cont">

    <h2>Choose Your Seat</h2>

    <?php if ($seatError !== '') { ?>
        <div class="error-box"><?php echo htmlspecialchars($seatError); ?></div>
    <?php } ?>

    <div class="event-info">

        <b>Event Type:</b> <?php echo htmlspecialchars($eventData['event_type']); ?><br>

        <b>Event:</b> <?php echo htmlspecialchars($eventData['event_name']); ?><br>

        <b>Venue:</b> <?php echo htmlspecialchars($venueData['venue_name']); ?><br>

        <b>Date:</b> <?php echo htmlspecialchars($eventData['date']); ?><br>

        <div class="summary-line">
            <b>Ticket Price:</b> ₱<?php echo number_format($baseTicketPrice, 2); ?>
        </div>

        <div class="summary-line">
            <b>Service Fee:</b> ₱<?php echo number_format($serviceFee, 2); ?>
        </div>

        <div class="summary-line">
            <b>Selected Row:</b> <span id="selectedRowDisplay">-</span>
        </div>

        <div class="summary-line">
            <b>Row Price:</b> <span id="rowPriceDisplay">₱0.00</span>
        </div>

        <div class="summary-line">
            <b>Selected Seat:</b> <span id="selectedSeatDisplay">-</span>
        </div>

        <div class="summary-line">
            <b>Total:</b> <span id="totalDisplay">₱<?php echo number_format($initialTotal, 2); ?></span>
        </div>

    </div>

    <div class="legend">
        <div class="legend-box available-box"></div> Available
        &nbsp;&nbsp;
        <div class="legend-box selected-box"></div> Selected
        &nbsp;&nbsp;
        <div class="legend-box taken-box"></div> Taken
    </div>

    <div class="screen">SCREEN</div>

    <?php
    $currentRow = "";
    $rowPriceForCurrentRow = 0.00;

    while ($seat = mysqli_fetch_assoc($seatResult)) {

        $rowName = $seat['row_name'];
        $seatNumber = $seat['seat_number'];
        $seatCode = $rowName . $seatNumber;
        $isTaken = in_array($seatCode, $takenSeats);

        if ($currentRow !== $rowName) {

            if ($currentRow !== "") {
                echo "</div>";
            }

            $rowPriceForCurrentRow = isset($rowPriceMap[$rowName]) ? (float)$rowPriceMap[$rowName] : 0.00;

            echo "<div class='row'>";
            echo "<div class='row-head'>";
            echo "<span class='row-label'>Row " . htmlspecialchars($rowName) . "</span>";
            echo "<span class='row-price'>- Row Price: ₱" . number_format($rowPriceForCurrentRow, 2) . "</span>";
            echo "</div>";

            $currentRow = $rowName;
        }
    ?>

        <div
            id="<?php echo htmlspecialchars($seatCode); ?>"
            class="seat <?php echo $isTaken ? 'taken' : ''; ?>"
            data-seat-code="<?php echo htmlspecialchars($seatCode); ?>"
            data-row-name="<?php echo htmlspecialchars($rowName); ?>"
            data-row-price="<?php echo htmlspecialchars($rowPriceForCurrentRow); ?>"
            <?php if (!$isTaken) { ?>
                onclick="selectSeat(this)"
            <?php } ?>
        >
            <?php echo htmlspecialchars($seatNumber); ?>
        </div>

    <?php
    }

    if ($currentRow !== "") {
        echo "</div>";
    }
    ?>

    <br><br>

    <form method="POST">
        <input type="hidden" name="seat" id="seatInput">
        <input type="hidden" name="row_name" id="rowInput">
        <input type="hidden" name="row_price" id="rowPriceInput">

        <button
            type="submit"
            name="next"
            id="nextBtn"
            disabled
        >
            Proceed to Payment
        </button>
    </form>

</div>

<script>
    updateSummary(0, "", "");
</script>

</body>
</html>