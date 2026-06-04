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
| GROUP PAGINATION HELPERS
|--------------------------------------------------------------------------
*/
function buildGroupPageUrl(array $currentGroupPages, int $groupId, int $page): string
{
    $params = [];

    if (!empty($currentGroupPages)) {
        $params['group_page'] = $currentGroupPages;
    }

    $params['group_page'][$groupId] = $page;

    return 'seat.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function renderGroupPagination(int $groupId, int $currentPage, int $totalPages, array $currentGroupPages): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $html = '<div class="pagination">';

    $prevPage = max(1, $currentPage - 1);
    $nextPage = min($totalPages, $currentPage + 1);

    $prevClass = $currentPage <= 1 ? 'disabled' : '';
    $nextClass = $currentPage >= $totalPages ? 'disabled' : '';

    $html .= '<a class="' . $prevClass . ' seat-page-link" href="' . htmlspecialchars(buildGroupPageUrl($currentGroupPages, $groupId, $prevPage)) . '">Prev</a>';

    $window = 2;
    $startPage = max(1, $currentPage - $window);
    $endPage = min($totalPages, $currentPage + $window);

    if ($startPage > 1) {
        $html .= '<a class="seat-page-link" href="' . htmlspecialchars(buildGroupPageUrl($currentGroupPages, $groupId, 1)) . '">1</a>';
        if ($startPage > 2) {
            $html .= '<span>...</span>';
        }
    }

    for ($p = $startPage; $p <= $endPage; $p++) {
        $active = $p === $currentPage ? 'active' : '';
        $html .= '<a class="' . $active . ' seat-page-link" href="' . htmlspecialchars(buildGroupPageUrl($currentGroupPages, $groupId, $p)) . '">' . $p . '</a>';
    }

    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $html .= '<span>...</span>';
        }
        $html .= '<a class="seat-page-link" href="' . htmlspecialchars(buildGroupPageUrl($currentGroupPages, $groupId, $totalPages)) . '">' . $totalPages . '</a>';
    }

    $html .= '<a class="' . $nextClass . ' seat-page-link" href="' . htmlspecialchars(buildGroupPageUrl($currentGroupPages, $groupId, $nextPage)) . '">Next</a>';
    $html .= '</div>';

    return $html;
}

/*
|--------------------------------------------------------------------------
| GROUP PAGINATION INPUT
|--------------------------------------------------------------------------
| Example URL:
| seat.php?group_page[3]=2&group_page[7]=1
|--------------------------------------------------------------------------
*/
$currentGroupPages = [];
if (isset($_GET['group_page']) && is_array($_GET['group_page'])) {
    foreach ($_GET['group_page'] as $gid => $page) {
        $gid = (int)$gid;
        $page = max(1, (int)$page);
        if ($gid > 0) {
            $currentGroupPages[$gid] = $page;
        }
    }
}

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
| HANDLE SEAT SUBMIT
|--------------------------------------------------------------------------
*/
if (isset($_POST['next'])) {

    $selectedSeats = json_decode($_POST['selected_seats'] ?? '[]', true);
    if (!is_array($selectedSeats)) {
        $selectedSeats = [];
    }

    if (empty($selectedSeats)) {
        $_SESSION['seat_error'] = "Please select at least one seat.";
        header("Location: seat.php");
        exit();
    }

    $validatedSeats = [];
    $totalGroupPrice = 0.0;
    $seenSeats = [];

    foreach ($selectedSeats as $seat) {

        $seatCode = trim($seat['seatCode'] ?? '');
        $groupId = (int)($seat['groupId'] ?? 0);
        $seatNumber = (int)($seat['seatNumber'] ?? 0);

        if ($seatCode === '' || $groupId <= 0 || $seatNumber <= 0) {
            $_SESSION['seat_error'] = "Invalid seat selection.";
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
            $_SESSION['seat_error'] = "Invalid seat group.";
            header("Location: seat.php");
            exit();
        }

        $groupData = mysqli_fetch_assoc($groupCheck);
        $groupName = $groupData['group_name'];
        $groupPrice = (float)$groupData['group_price'];

        $expectedSeatCode = $groupName . $seatNumber;
        if ($seatCode !== $expectedSeatCode) {
            $_SESSION['seat_error'] = "Seat validation failed.";
            header("Location: seat.php");
            exit();
        }

        $seatUniqueKey = $groupId . ':' . $seatNumber;
        if (isset($seenSeats[$seatUniqueKey])) {
            $_SESSION['seat_error'] = "Duplicate seat selected.";
            header("Location: seat.php");
            exit();
        }
        $seenSeats[$seatUniqueKey] = true;

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
            $_SESSION['seat_error'] = $seatCode . " is already taken.";
            header("Location: seat.php");
            exit();
        }

        if ($seatRow['status'] === 'unavailable') {
            $_SESSION['seat_error'] = $seatCode . " is unavailable.";
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

        $totalGroupPrice += $groupPrice;
    }

    if (empty($validatedSeats)) {
        $_SESSION['seat_error'] = "Please select a valid seat.";
        header("Location: seat.php");
        exit();
    }

    $ticketPrice = (float)$eventData['ticket_price'];
    $serviceFee = (float)$eventData['service_fee'];
    $seatCount = count($validatedSeats);

    $total = ($ticketPrice * $seatCount) + $serviceFee + $totalGroupPrice;

    $_SESSION['selected_seats'] = $validatedSeats;
    $_SESSION['service_fee'] = $serviceFee;
    $_SESSION['selected_total'] = $total;
    $_SESSION['selected_ticket_price'] = $ticketPrice;
    $_SESSION['selected_seat_count'] = $seatCount;

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
$initialTotal = 0.00;
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
            margin-bottom: 20px;
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
            margin-bottom: 20px;
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

        .pagination{
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            margin: 14px 0 22px;
        }

        .pagination a{
            display: inline-block;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            text-decoration: none;
            background: #fff;
            color: #111827;
        }

        .pagination a.active{
            background: #111827;
            color: #fff;
            border-color: #111827;
        }

        .pagination a.disabled{
            opacity: .5;
            pointer-events: none;
        }

        .page-info{
            margin-bottom: 10px;
            color: #6b7280;
            font-size: 14px;
        }
    </style>

    <script>
        const baseTicketPrice = <?php echo json_encode($baseTicketPrice); ?>;
        const serviceFee = <?php echo json_encode($serviceFee); ?>;
        const storageKey = "selected_seats_event_<?php echo (int)$eventId; ?>";
        const scrollKey = "seat_scroll_position_event_<?php echo (int)$eventId; ?>";

        let selectedSeats = [];

        function formatMoney(value) {
            return "₱" + Number(value).toFixed(2);
        }

        function saveSelectedSeats() {
            localStorage.setItem(storageKey, JSON.stringify(selectedSeats));
            document.getElementById("seatInput").value = JSON.stringify(selectedSeats);
        }

        function updateSummary() {
            let totalGroupPrice = 0;
            let seatCodes = [];
            let groupNames = [];

            selectedSeats.forEach(seat => {
                totalGroupPrice += Number(seat.groupPrice || 0);
                seatCodes.push(seat.seatCode);

                if (!groupNames.includes(seat.groupName)) {
                    groupNames.push(seat.groupName);
                }
            });

            const total = selectedSeats.length
                ? ((baseTicketPrice * selectedSeats.length) + serviceFee + totalGroupPrice)
                : 0;

            document.getElementById("selectedSeatDisplay").textContent =
                seatCodes.length ? seatCodes.join(", ") : "-";

            document.getElementById("selectedGroupDisplay").textContent =
                groupNames.length ? groupNames.join(", ") : "-";

            document.getElementById("groupPriceDisplay").textContent =
                formatMoney(totalGroupPrice);

            document.getElementById("totalDisplay").textContent =
                formatMoney(total);

            document.getElementById("nextBtn").disabled =
                selectedSeats.length === 0;

            saveSelectedSeats();
        }

        function loadSelectedSeats() {
            try {
                const raw = localStorage.getItem(storageKey);
                if (!raw) {
                    selectedSeats = [];
                    return;
                }

                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) {
                    selectedSeats = parsed;
                } else {
                    selectedSeats = [];
                }
            } catch (e) {
                selectedSeats = [];
            }
        }

        function applySelectedSeatsToPage() {
            const pageSeatMap = new Map();

            document.querySelectorAll(".seat").forEach(el => {
                const seatCode = el.dataset.seatCode;
                const groupId = parseInt(el.dataset.groupId || "0", 10);
                pageSeatMap.set(`${groupId}:${seatCode}`, el);
            });

            selectedSeats.forEach(seat => {
                const key = `${parseInt(seat.groupId || "0", 10)}:${seat.seatCode}`;
                const el = pageSeatMap.get(key);
                if (el && !el.classList.contains("taken") && !el.classList.contains("unavailable")) {
                    el.classList.add("selected");
                }
            });
        }

        function selectSeat(el) {
            const seatCode = el.dataset.seatCode;
            const groupName = el.dataset.groupName;
            const groupPrice = parseFloat(el.dataset.groupPrice || "0");
            const groupId = parseInt(el.dataset.groupId || "0", 10);
            const seatNumber = parseInt(el.dataset.seatNumber || "0", 10);

            const existingIndex = selectedSeats.findIndex(
                seat => seat.seatCode === seatCode && parseInt(seat.groupId || "0", 10) === groupId
            );

            if (existingIndex > -1) {
                selectedSeats.splice(existingIndex, 1);
                el.classList.remove("selected");
            } else {
                selectedSeats.push({
                    seatCode: seatCode,
                    seatNumber: seatNumber,
                    groupName: groupName,
                    groupPrice: groupPrice,
                    groupId: groupId
                });
                el.classList.add("selected");
            }

            updateSummary();
        }

        function restoreSummaryFromStorage() {
            loadSelectedSeats();
            applySelectedSeatsToPage();
            updateSummary();
        }

        (function () {
            function saveScroll() {
                sessionStorage.setItem(scrollKey, String(window.scrollY || window.pageYOffset || 0));
            }

            document.addEventListener("click", function (e) {
                const link = e.target.closest(".seat-page-link");
                if (link) {
                    saveScroll();
                }
            }, true);

            window.addEventListener("beforeunload", saveScroll);

            window.addEventListener("load", function () {
                const y = sessionStorage.getItem(scrollKey);
                if (y !== null && y !== "") {
                    window.scrollTo(0, parseInt(y, 10) || 0);
                }
                sessionStorage.removeItem(scrollKey);
            });
        })();

        document.addEventListener("DOMContentLoaded", function () {
            restoreSummaryFromStorage();

            const form = document.getElementById("seatForm");
            form.addEventListener("submit", function () {
                document.getElementById("seatInput").value = JSON.stringify(selectedSeats);
                localStorage.setItem(storageKey, JSON.stringify(selectedSeats));
            });
        });
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
        <div class="summary-line"><b>Selected Group(s):</b> <span id="selectedGroupDisplay">-</span></div>
        <div class="summary-line"><b>Group Price:</b> <span id="groupPriceDisplay">₱0.00</span></div>
        <div class="summary-line"><b>Selected Seat(s):</b> <span id="selectedSeatDisplay">-</span></div>
        <div class="summary-line"><b>Total:</b> <span id="totalDisplay">₱<?php echo number_format($initialTotal, 2); ?></span></div>
    </div>

    <div class="page-info">
        Groups with more than 1000 seats have their own pages.
    </div>

    <div class="legend">
        <div class="legend-item"><div class="legend-box available-box"></div> Available</div>
        <div class="legend-item"><div class="legend-box selected-box"></div> Selected</div>
        <div class="legend-item"><div class="legend-box taken-box"></div> Taken</div>
        <div class="legend-item"><div class="legend-box unavailable-box"></div> Not Available</div>
    </div>

    <div class="screen">SCREEN</div>

    <?php
    mysqli_data_seek($groupsQuery, 0);
    while ($group = mysqli_fetch_assoc($groupsQuery)) {
        $groupId = (int)$group['id'];
        $groupName = $group['group_name'];
        $groupColor = $group['group_color'];
        $groupPrice = (float)$group['group_price'];

        $seatCountQuery = mysqli_query(
            $conn,
            "SELECT COUNT(*) AS total_group_seats
             FROM event_group_seats
             WHERE group_id = '$groupId'"
        );

        $groupSeatTotal = 0;
        if ($seatCountQuery) {
            $seatCountRow = mysqli_fetch_assoc($seatCountQuery);
            $groupSeatTotal = (int)($seatCountRow['total_group_seats'] ?? 0);
        }

        $groupTotalPages = max(1, (int)ceil($groupSeatTotal / 1000));
        $currentGroupPage = isset($currentGroupPages[$groupId]) ? max(1, (int)$currentGroupPages[$groupId]) : 1;
        if ($currentGroupPage > $groupTotalPages) {
            $currentGroupPage = $groupTotalPages;
        }
        $groupOffset = ($currentGroupPage - 1) * 1000;

        $groupSeatRows = [];
        $groupSeatQuery = mysqli_query(
            $conn,
            "SELECT seat_number, status
             FROM event_group_seats
             WHERE group_id = '$groupId'
             ORDER BY seat_number ASC
             LIMIT $groupOffset, 1000"
        );

        if ($groupSeatQuery) {
            while ($s = mysqli_fetch_assoc($groupSeatQuery)) {
                $groupSeatRows[] = $s;
            }
        }
    ?>

        <div class="group">
            <div class="group-head">
                <div class="group-color" style="background: <?php echo htmlspecialchars($groupColor); ?>;"></div>
                <b><?php echo htmlspecialchars($groupName); ?></b>
                <span>Group Price: ₱<?php echo number_format($groupPrice, 2); ?></span>
                <span class="group-note">
                    Showing seats <?php echo $groupOffset + 1; ?> to <?php echo min($groupOffset + 1000, $groupSeatTotal); ?> of <?php echo $groupSeatTotal; ?>
                </span>
            </div>

            <?php if ($groupTotalPages > 1) { ?>
                <?php echo renderGroupPagination($groupId, $currentGroupPage, $groupTotalPages, $currentGroupPages); ?>
            <?php } ?>

            <div class="seat-wrap">
                <?php foreach ($groupSeatRows as $seat) { ?>
                    <?php
                        $status = $seat['status'] ?? 'available';
                        $seatNumber = (int)$seat['seat_number'];
                        $seatCode = $groupName . $seatNumber;

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
                        data-seat-number="<?php echo $seatNumber; ?>"
                        <?php if ($status === 'available') { ?>onclick="selectSeat(this)"<?php } ?>
                    >
                        <?php echo $seatNumber; ?>
                    </div>
                <?php } ?>
            </div>

            <?php if ($groupTotalPages > 1) { ?>
                <?php echo renderGroupPagination($groupId, $currentGroupPage, $groupTotalPages, $currentGroupPages); ?>
            <?php } ?>
        </div>
    <?php } ?>

    <form method="POST" id="seatForm">
        <input type="hidden" name="selected_seats" id="seatInput">
        <button type="submit" name="next" id="nextBtn" disabled>
            Proceed to Payment
        </button>
    </form>
</div>
</body>
</html>