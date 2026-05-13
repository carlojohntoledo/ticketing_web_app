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
    die("No event selected.");
}

$eventName = mysqli_real_escape_string($conn, $_SESSION['event_name']);

/*
|--------------------------------------------------------------------------
| GET EVENT FROM tickets
|--------------------------------------------------------------------------
*/
$eventQuery = mysqli_query(
    $conn,
    "SELECT t.*, v.venue_name
     FROM tickets t
     LEFT JOIN venues v ON v.id = t.venue_id
     WHERE t.event_name = '$eventName'
     LIMIT 1"
);

if (!$eventQuery || mysqli_num_rows($eventQuery) == 0) {
    die("Event not found.");
}

$eventData = mysqli_fetch_assoc($eventQuery);
$eventId = (int)$eventData['id'];

/*
|--------------------------------------------------------------------------
| GET EVENT GROUPS
|--------------------------------------------------------------------------
*/
$groupsQuery = mysqli_query(
    $conn,
    "SELECT *
     FROM event_seat_groups
     WHERE event_id = '$eventId'
     ORDER BY id ASC"
);

if (!$groupsQuery) {
    die("Group query failed: " . mysqli_error($conn));
}

/*
|--------------------------------------------------------------------------
| GET TAKEN SEATS
|--------------------------------------------------------------------------
| booked seats are stored in event_group_seats.status = 'booked'
|--------------------------------------------------------------------------
*/
$takenSeats = [];

$takenQuery = mysqli_query(
    $conn,
    "SELECT g.group_name, s.seat_number
     FROM event_group_seats s
     INNER JOIN event_seat_groups g ON g.id = s.group_id
     WHERE g.event_id = '$eventId'
     AND s.status = 'booked'"
);

if ($takenQuery) {
    while ($row = mysqli_fetch_assoc($takenQuery)) {
        $takenSeats[] = $row['group_name'] . $row['seat_number'];
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE SEAT SUBMIT
|--------------------------------------------------------------------------
*/
if (isset($_POST['next'])) {
    $selectedSeat = trim($_POST['seat_code'] ?? '');
    $groupId = (int)($_POST['group_id'] ?? 0);

    if ($selectedSeat === '' || $groupId <= 0) {
        $_SESSION['seat_error'] = "Please select a seat.";
        header("Location: seat.php");
        exit();
    }

    $groupCheck = mysqli_query(
        $conn,
        "SELECT *
         FROM event_seat_groups
         WHERE id = '$groupId'
         AND event_id = '$eventId'
         LIMIT 1"
    );

    if (!$groupCheck || mysqli_num_rows($groupCheck) == 0) {
        $_SESSION['seat_error'] = "Seat group not found.";
        header("Location: seat.php");
        exit();
    }

    $groupData = mysqli_fetch_assoc($groupCheck);
    $groupName = $groupData['group_name'];

    if (strpos($selectedSeat, $groupName) !== 0) {
        $_SESSION['seat_error'] = "Selected seat does not match the group.";
        header("Location: seat.php");
        exit();
    }

    $seatNumber = (int)preg_replace('/^\D+/', '', $selectedSeat);

    $seatCheck = mysqli_query(
        $conn,
        "SELECT *
         FROM event_group_seats
         WHERE group_id = '$groupId'
         AND seat_number = '$seatNumber'
         LIMIT 1"
    );

    if (!$seatCheck || mysqli_num_rows($seatCheck) == 0) {
        $_SESSION['seat_error'] = "Seat not found.";
        header("Location: seat.php");
        exit();
    }

    $seatRow = mysqli_fetch_assoc($seatCheck);

    if ($seatRow['status'] === 'booked') {
        $_SESSION['seat_error'] = "Seat already taken.";
        header("Location: seat.php");
        exit();
    }

    if ($seatRow['status'] === 'unavailable') {
        $_SESSION['seat_error'] = "Seat is unavailable.";
        header("Location: seat.php");
        exit();
    }

    $groupPrice = (float)$groupData['group_price'];
    $ticketPrice = (float)$eventData['ticket_price'];
    $serviceFee = (float)$eventData['service_fee'];
    $total = $ticketPrice + $serviceFee + $groupPrice;

    $_SESSION['selected_seat'] = $selectedSeat;
    $_SESSION['selected_group'] = $groupName;
    $_SESSION['selected_group_price'] = $groupPrice;
    $_SESSION['service_fee'] = $serviceFee;
    $_SESSION['selected_total'] = $total;

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

$baseTicketPrice = (float)$eventData['ticket_price'];
$serviceFee = (float)$eventData['service_fee'];
$initialTotal = $baseTicketPrice + $serviceFee;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Select Seat</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">

    <style>
        body{
            font-family: Arial;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }

        .main_cont{
            max-width: 1200px;
            margin: auto;
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
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
        }

        .legend-item{
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .legend-box{
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 4px;
            vertical-align: middle;
        }

        .available-box{ background: #d1d5db; }
        .selected-box{ background: #16a34a; }
        .taken-box{ background: #dc2626; }
        .unavailable-box{ background: #facc15; }

        .screen{
            max-width: 100%;
            padding: 15px;
            background: #ddd;
            text-align: center;
            border-radius: 10px;
            margin-bottom: 40px;
            font-weight: bold;
        }

        .group{
            margin-bottom: 18px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            background: #fff;
        }

        .group-head{
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .group-color{
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 1px solid rgba(0,0,0,.2);
        }

        .seat-wrap{
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .seat{
            width: 54px;
            height: 54px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            user-select: none;
            color: #111;
            border: 1px solid rgba(0,0,0,.12);
            font-weight: bold;
            box-sizing: border-box;
            background: var(--seat-color, #d1d5db);
        }

        .seat.available{
            background: var(--seat-color, #d1d5db) !important;
            color: #111;
        }

        .seat.selected{
            background: #16a34a !important;
            color: #fff !important;
            border-color: #15803d;
            box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.18);
        }

        .seat.taken{
            background: #dc2626 !important;
            color: #fff !important;
            cursor: not-allowed;
            border-color: #b91c1c;
        }

        .seat.unavailable{
            background: #facc15 !important;
            color: #111 !important;
            cursor: not-allowed;
            border-color: #eab308;
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

        .group-note{
            margin-left: auto;
            font-size: 13px;
            color: #666;
        }
    </style>

    <script>
        const baseTicketPrice = <?php echo json_encode($baseTicketPrice); ?>;
        const serviceFee = <?php echo json_encode($serviceFee); ?>;

        function formatMoney(value) {
            return "₱" + Number(value).toFixed(2);
        }

        function updateSummary(groupPrice, seatCode, groupName) {
            const total = baseTicketPrice + serviceFee + groupPrice;

            document.getElementById("selectedSeatDisplay").textContent = seatCode || "-";
            document.getElementById("selectedGroupDisplay").textContent = groupName || "-";
            document.getElementById("groupPriceDisplay").textContent = formatMoney(groupPrice);
            document.getElementById("totalDisplay").textContent = formatMoney(total);
        }

        function selectSeat(el) {
            const seatCode = el.dataset.seatCode;
            const groupName = el.dataset.groupName;
            const groupPrice = parseFloat(el.dataset.groupPrice || "0");
            const groupId = el.dataset.groupId;

            document.getElementById("seatInput").value = seatCode;
            document.getElementById("groupIdInput").value = groupId;

            document.querySelectorAll(".seat").forEach(seat => {
                if (!seat.classList.contains("taken") && !seat.classList.contains("unavailable")) {
                    seat.classList.remove("selected");
                }
            });

            el.classList.add("selected");
            updateSummary(groupPrice, seatCode, groupName);
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
        <b>Venue:</b> <?php echo htmlspecialchars($eventData['venue_name']); ?><br>
        <b>Date:</b> <?php echo htmlspecialchars($eventData['event_date']); ?><br>

        <div class="summary-line"><b>Ticket Price:</b> ₱<?php echo number_format($baseTicketPrice, 2); ?></div>
        <div class="summary-line"><b>Service Fee:</b> ₱<?php echo number_format($serviceFee, 2); ?></div>
        <div class="summary-line"><b>Selected Group:</b> <span id="selectedGroupDisplay">-</span></div>
        <div class="summary-line"><b>Group Price:</b> <span id="groupPriceDisplay">₱0.00</span></div>
        <div class="summary-line"><b>Selected Seat:</b> <span id="selectedSeatDisplay">-</span></div>
        <div class="summary-line"><b>Total:</b> <span id="totalDisplay">₱<?php echo number_format($initialTotal, 2); ?></span></div>
    </div>

    <div class="legend">
        <div class="legend-item"><div class="legend-box available-box"></div> Available</div>
        <div class="legend-item"><div class="legend-box selected-box"></div> Selected</div>
        <div class="legend-item"><div class="legend-box taken-box"></div> Taken</div>
        <div class="legend-item"><div class="legend-box unavailable-box"></div> Not Available</div>
    </div>

    <div class="screen">SCREEN</div>

    <?php while ($group = mysqli_fetch_assoc($groupsQuery)) { ?>
        <?php
            $groupId = (int)$group['id'];
            $groupName = $group['group_name'];
            $groupColor = $group['group_color'];
            $seatCount = (int)$group['seat_count'];
            $groupPrice = (float)$group['group_price'];

            $seatStatusMap = [];
            $seatQuery = mysqli_query(
                $conn,
                "SELECT seat_number, status
                 FROM event_group_seats
                 WHERE group_id='$groupId'
                 ORDER BY seat_number ASC"
            );

            if ($seatQuery) {
                while ($s = mysqli_fetch_assoc($seatQuery)) {
                    $seatStatusMap[(int)$s['seat_number']] = $s['status'];
                }
            }
        ?>

        <div class="group">
            <div class="group-head">
                <div class="group-color" style="background: <?php echo htmlspecialchars($groupColor); ?>;"></div>
                <b><?php echo htmlspecialchars($groupName); ?></b>
                <span>Group Price: ₱<?php echo number_format($groupPrice, 2); ?></span>
                <span class="group-note">Color identifies the group only. Status colors override it.</span>
            </div>

            <div class="seat-wrap">
                <?php for ($i = 1; $i <= $seatCount; $i++) { ?>
                    <?php
                        $status = $seatStatusMap[$i] ?? 'available';
                        $seatCode = $groupName . $i;

                        $seatClass = 'seat';
                        if ($status === 'booked') {
                            $seatClass .= ' taken';
                        } elseif ($status === 'unavailable') {
                            $seatClass .= ' unavailable';
                        } else {
                            $seatClass .= ' available';
                        }
                    ?>
                    <div
                        class="<?php echo $seatClass; ?>"
                        style="--seat-color: <?php echo htmlspecialchars($groupColor); ?>;"
                        data-seat-code="<?php echo htmlspecialchars($seatCode); ?>"
                        data-group-name="<?php echo htmlspecialchars($groupName); ?>"
                        data-group-price="<?php echo htmlspecialchars($groupPrice); ?>"
                        data-group-id="<?php echo $groupId; ?>"
                        <?php if ($status === 'available') { ?>onclick="selectSeat(this)"<?php } ?>
                    >
                        <?php echo $i; ?>
                    </div>
                <?php } ?>
            </div>
        </div>
    <?php } ?>

    <form method="POST">
        <input type="hidden" name="seat_code" id="seatInput">
        <input type="hidden" name="group_id" id="groupIdInput">

        <button type="submit" name="next" id="nextBtn" disabled>
            Proceed to Payment
        </button>
    </form>
</div>

<script>
    updateSummary(0, "", "");
</script>
</body>
</html>