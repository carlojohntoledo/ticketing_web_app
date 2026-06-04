<?php
session_start();
include '../db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getOrCreateVenueId(mysqli $conn, string $venueName): int
{
    $venueName = trim($venueName);
    if ($venueName === '') {
        return 0;
    }

    $stmt = $conn->prepare("SELECT id FROM venues WHERE venue_name = ? LIMIT 1");
    $stmt->bind_param("s", $venueName);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return (int)$row['id'];
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO venues (venue_name) VALUES (?)");
    $stmt->bind_param("s", $venueName);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    return (int)$newId;
}

function buildUrl(array $overrides = []): string
{
    $params = $_GET;

    unset($params['edit_event'], $params['delete_event']);

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    return $_SERVER['PHP_SELF'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function lower_text(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
}

function filterSortEvents(array $events, string $search = '', string $type = 'all', string $sort = 'latest'): array
{
    $search = trim($search);
    $searchLower = lower_text($search);

    $filtered = array_values(array_filter($events, function ($event) use ($searchLower, $type) {
        $eventType = (string)($event['event_type'] ?? '');
        $eventName = (string)($event['event_name'] ?? '');
        $venueName = (string)($event['venue_name'] ?? '');
        $eventDate = (string)($event['event_date'] ?? '');

        if ($type !== 'all' && strcasecmp($eventType, $type) !== 0) {
            return false;
        }

        if ($searchLower === '') {
            return true;
        }

        $haystack = trim($eventName . ' ' . $venueName . ' ' . $eventType . ' ' . $eventDate);
        $haystackLower = lower_text($haystack);

        return strpos($haystackLower, $searchLower) !== false;
    }));

    usort($filtered, function ($a, $b) use ($sort) {
        $aId = (int)($a['id'] ?? 0);
        $bId = (int)($b['id'] ?? 0);

        $aName = (string)($a['event_name'] ?? '');
        $bName = (string)($b['event_name'] ?? '');

        $aVenue = (string)($a['venue_name'] ?? '');
        $bVenue = (string)($b['venue_name'] ?? '');

        $aDate = (string)($a['event_date'] ?? '');
        $bDate = (string)($b['event_date'] ?? '');

        $aPrice = (float)($a['ticket_price'] ?? 0);
        $bPrice = (float)($b['ticket_price'] ?? 0);

        switch ($sort) {
            case 'oldest':
                return $aId <=> $bId;
            case 'name_asc':
                return strcmp(lower_text($aName), lower_text($bName));
            case 'name_desc':
                return strcmp(lower_text($bName), lower_text($aName));
            case 'date_asc':
                return strcmp($aDate, $bDate);
            case 'date_desc':
                return strcmp($bDate, $aDate);
            case 'venue_asc':
                return strcmp(lower_text($aVenue), lower_text($bVenue));
            case 'venue_desc':
                return strcmp(lower_text($bVenue), lower_text($aVenue));
            case 'price_asc':
                return $aPrice <=> $bPrice;
            case 'price_desc':
                return $bPrice <=> $aPrice;
            case 'latest':
            default:
                return $bId <=> $aId;
        }
    });

    return $filtered;
}

/*
|--------------------------------------------------------------------------
| ADD / EDIT / DELETE EVENT
|--------------------------------------------------------------------------
*/
$editEvent = null;
$editGroups = [];

if (isset($_GET['edit_event'])) {
    $editId = (int)$_GET['edit_event'];

    $stmt = $conn->prepare(
        "SELECT t.*, v.venue_name
         FROM tickets t
         LEFT JOIN venues v ON v.id = t.venue_id
         WHERE t.id = ?"
    );
    $stmt->bind_param("i", $editId);
    $stmt->execute();
    $editEvent = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($editEvent) {
        $stmt = $conn->prepare("SELECT * FROM event_seat_groups WHERE event_id = ? ORDER BY id ASC");
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $groupsResult = $stmt->get_result();

        while ($group = $groupsResult->fetch_assoc()) {
            $groupId = (int)$group['id'];
            $seatStmt = $conn->prepare("SELECT seat_number, status FROM event_group_seats WHERE group_id = ? ORDER BY seat_number ASC");
            $seatStmt->bind_param("i", $groupId);
            $seatStmt->execute();
            $seatResult = $seatStmt->get_result();

            $group['unavailable_seats'] = [];
            while ($seat = $seatResult->fetch_assoc()) {
                if ($seat['status'] === 'unavailable') {
                    $group['unavailable_seats'][] = (string)$seat['seat_number'];
                }
            }

            $seatStmt->close();
            $editGroups[] = $group;
        }

        $stmt->close();
    }
}

if (isset($_POST['save_event'])) {
    $eventId      = (int)($_POST['id'] ?? 0);
    $eventType    = trim($_POST['event_type'] ?? '');
    $eventName    = trim($_POST['event_name'] ?? '');
    $venueName    = trim($_POST['venue_name'] ?? '');
    $eventDate    = trim($_POST['event_date'] ?? '');
    $ticketPrice  = (float)($_POST['ticket_price'] ?? 0);
    $serviceFee   = (float)($_POST['service_fee'] ?? 0);
    $totalPrice   = $ticketPrice + $serviceFee;

    $groupNames   = $_POST['group_name'] ?? [];
    $groupColors  = $_POST['group_color'] ?? [];
    $seatCounts   = $_POST['seat_count'] ?? [];
    $groupPrices  = $_POST['group_price'] ?? [];
    $unavailableM = $_POST['group_unavailable'] ?? [];

    $venueId = getOrCreateVenueId($conn, $venueName);

    if ($venueId <= 0) {
        die('Venue name is required.');
    }

    if ($eventId > 0) {
        $stmt = $conn->prepare(
            "UPDATE tickets SET
                event_type = ?,
                event_name = ?,
                venue_id = ?,
                event_date = ?,
                ticket_price = ?,
                service_fee = ?,
                total = ?
             WHERE id = ?"
        );
        $stmt->bind_param(
            "ssisdddi",
            $eventType,
            $eventName,
            $venueId,
            $eventDate,
            $ticketPrice,
            $serviceFee,
            $totalPrice,
            $eventId
        );
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT id FROM event_seat_groups WHERE event_id = ?");
        $stmt->bind_param("i", $eventId);
        $stmt->execute();
        $oldGroups = $stmt->get_result();
        while ($oldGroup = $oldGroups->fetch_assoc()) {
            $oldGroupId = (int)$oldGroup['id'];
            $conn->query("DELETE FROM event_group_seats WHERE group_id = '$oldGroupId'");
        }
        $stmt->close();
        $conn->query("DELETE FROM event_seat_groups WHERE event_id = '$eventId'");
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO tickets
                (event_type, event_name, venue_id, event_date, ticket_price, service_fee, total)
             VALUES
                (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "ssisddd",
            $eventType,
            $eventName,
            $venueId,
            $eventDate,
            $ticketPrice,
            $serviceFee,
            $totalPrice
        );
        $stmt->execute();
        $eventId = $stmt->insert_id;
        $stmt->close();
    }

    if ($eventId > 0 && is_array($groupNames)) {
        for ($i = 0; $i < count($groupNames); $i++) {
            $groupName = trim($groupNames[$i] ?? '');
            $groupColor = trim($groupColors[$i] ?? '#3b82f6');
            $seatCount = (int)($seatCounts[$i] ?? 0);
            $groupPrice = (float)($groupPrices[$i] ?? 0);
            $unavailableSeats = $unavailableM[$i] ?? [];

            if ($groupName === '' || $seatCount <= 0) {
                continue;
            }

            $stmt = $conn->prepare(
                "INSERT INTO event_seat_groups
                    (event_id, group_name, group_color, seat_count, group_price)
                 VALUES
                    (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("issid", $eventId, $groupName, $groupColor, $seatCount, $groupPrice);
            $stmt->execute();
            $groupId = $stmt->insert_id;
            $stmt->close();

            $unavailableLookup = [];
            if (is_array($unavailableSeats)) {
                foreach ($unavailableSeats as $seatNo) {
                    $seatNo = (int)$seatNo;
                    if ($seatNo > 0) {
                        $unavailableLookup[$seatNo] = true;
                    }
                }
            }

            for ($seat = 1; $seat <= $seatCount; $seat++) {
                $status = isset($unavailableLookup[$seat]) ? 'unavailable' : 'available';
                $seatStmt = $conn->prepare(
                    "INSERT INTO event_group_seats (group_id, seat_number, status)
                     VALUES (?, ?, ?)"
                );
                $seatStmt->bind_param("iis", $groupId, $seat, $status);
                $seatStmt->execute();
                $seatStmt->close();
            }
        }
    }

    header("Location: admin_dashboard.php#events");
    exit();
}

if (isset($_GET['delete_event'])) {
    $eventId = (int)$_GET['delete_event'];

    $stmt = $conn->prepare("SELECT id FROM event_seat_groups WHERE event_id = ?");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $groupResult = $stmt->get_result();
    while ($group = $groupResult->fetch_assoc()) {
        $groupId = (int)$group['id'];
        $conn->query("DELETE FROM event_group_seats WHERE group_id = '$groupId'");
    }
    $stmt->close();

    $conn->query("DELETE FROM event_seat_groups WHERE event_id = '$eventId'");
    $conn->query("DELETE FROM tickets WHERE id = '$eventId'");

    header("Location: admin_dashboard.php#events");
    exit();
}

/*
|--------------------------------------------------------------------------
| LOAD ALL EVENTS / GROUPS
|--------------------------------------------------------------------------
*/
$eventsAll = [];
$eventQuery = $conn->query(
    "SELECT t.*, v.venue_name
     FROM tickets t
     LEFT JOIN venues v ON v.id = t.venue_id
     ORDER BY t.id DESC"
);
if ($eventQuery) {
    while ($row = $eventQuery->fetch_assoc()) {
        $eventsAll[] = $row;
    }
}

$eventGroups = [];
$groupQuery = $conn->query(
    "SELECT g.*, t.event_name, t.event_type, t.event_date, v.venue_name
     FROM event_seat_groups g
     LEFT JOIN tickets t ON t.id = g.event_id
     LEFT JOIN venues v ON v.id = t.venue_id
     ORDER BY g.id ASC"
);
if ($groupQuery) {
    while ($group = $groupQuery->fetch_assoc()) {
        $groupId = (int)$group['id'];
        $seatQuery = $conn->query("SELECT seat_number, status FROM event_group_seats WHERE group_id = '$groupId' ORDER BY seat_number ASC");

        $group['seats'] = [];
        $group['counts'] = [
            'available' => 0,
            'unavailable' => 0,
            'booked' => 0
        ];

        if ($seatQuery) {
            while ($seat = $seatQuery->fetch_assoc()) {
                $group['seats'][] = $seat;
                if (isset($group['counts'][$seat['status']])) {
                    $group['counts'][$seat['status']]++;
                }
            }
        }

        $eventGroups[(int)$group['event_id']][] = $group;
    }
}

/*
|--------------------------------------------------------------------------
| EVENTS LIST FILTERS
|--------------------------------------------------------------------------
*/
$eventsSearch = trim($_GET['events_q'] ?? '');
$eventsType = trim($_GET['events_type'] ?? 'all');
$eventsSort = trim($_GET['events_sort'] ?? 'latest');

$eventsFiltered = filterSortEvents($eventsAll, $eventsSearch, $eventsType, $eventsSort);

/*
|--------------------------------------------------------------------------
| SEAT OVERVIEW FILTERS + PAGINATION
|--------------------------------------------------------------------------
*/
$overviewSearch = trim($_GET['overview_q'] ?? '');
$overviewType = trim($_GET['overview_type'] ?? 'all');
$overviewSort = trim($_GET['overview_sort'] ?? 'latest');
$overviewPage = max(1, (int)($_GET['overview_page'] ?? 1));
$overviewPerPage = 2;

$overviewFiltered = filterSortEvents($eventsAll, $overviewSearch, $overviewType, $overviewSort);
$overviewTotalPages = max(1, (int)ceil(count($overviewFiltered) / $overviewPerPage));
if ($overviewPage > $overviewTotalPages) {
    $overviewPage = $overviewTotalPages;
}
$overviewOffset = ($overviewPage - 1) * $overviewPerPage;
$overviewPaged = array_slice($overviewFiltered, $overviewOffset, $overviewPerPage);

/*
|--------------------------------------------------------------------------
| RECEIPTS / BOOKINGS LIST
|--------------------------------------------------------------------------
*/
$receiptSearch = trim($_GET['receipt_q'] ?? '');
$receiptSort = $_GET['receipt_sort'] ?? 'latest';
$receiptStatus = $_GET['receipt_status'] ?? 'all';
$receiptMethod = $_GET['receipt_method'] ?? 'all';

$sortMap = [
    'latest' => 'b.id DESC',
    'oldest' => 'b.id ASC',
    'ref_asc' => 'b.reference_number ASC',
    'ref_desc' => 'b.reference_number DESC',
    'total_asc' => 'b.total ASC',
    'total_desc' => 'b.total DESC',
    'event_date_asc' => 't.event_date ASC, b.id ASC',
    'event_date_desc' => 't.event_date DESC, b.id DESC',
];

if (!isset($sortMap[$receiptSort])) {
    $receiptSort = 'latest';
}

$receiptSearchEsc = mysqli_real_escape_string($conn, $receiptSearch);
$receiptStatusEsc = mysqli_real_escape_string($conn, $receiptStatus);
$receiptMethodEsc = mysqli_real_escape_string($conn, $receiptMethod);

$receiptSql = "
    SELECT
        b.id,
        b.reference_number,
        b.subtotal,
        b.service_fee,
        b.total,
        b.payment_method,
        b.payment_status,
        c.fullname AS customer_name,
        c.email AS customer_email,
        c.phone AS customer_phone,
        t.event_name,
        t.event_type,
        t.event_date,
        v.venue_name,
        (
            SELECT GROUP_CONCAT(CONCAT(g.group_name, bs.seat_number) ORDER BY g.id ASC, bs.seat_number ASC SEPARATOR ', ')
            FROM booked_seats bs
            INNER JOIN event_seat_groups g ON g.id = bs.group_id
            WHERE bs.booking_id = b.id
        ) AS seat_codes,
        (
            SELECT COUNT(*)
            FROM booked_seats bs
            WHERE bs.booking_id = b.id
        ) AS seat_count
    FROM bookings b
    LEFT JOIN customers c ON c.id = b.customer_id
    LEFT JOIN tickets t ON t.id = b.event_id
    LEFT JOIN venues v ON v.id = t.venue_id
    WHERE 1=1
";

if ($receiptSearch !== '') {
    $receiptSql .= " AND (
        b.reference_number LIKE '%$receiptSearchEsc%'
        OR c.fullname LIKE '%$receiptSearchEsc%'
        OR t.event_name LIKE '%$receiptSearchEsc%'
    )";
}

if ($receiptStatus !== 'all') {
    $receiptSql .= " AND b.payment_status = '$receiptStatusEsc'";
}

if ($receiptMethod !== 'all') {
    $receiptSql .= " AND b.payment_method = '$receiptMethodEsc'";
}

$receiptSql .= " ORDER BY " . $sortMap[$receiptSort];

$receiptQuery = $conn->query($receiptSql);
$receipts = [];
if ($receiptQuery) {
    while ($row = $receiptQuery->fetch_assoc()) {
        $receipts[] = $row;
    }
}
$receiptCount = count($receipts);

$editGroupsJson = [];
foreach ($editGroups as $group) {
    $editGroupsJson[] = [
        'group_name' => $group['group_name'],
        'group_color' => $group['group_color'],
        'seat_count' => (int)$group['seat_count'],
        'group_price' => $group['group_price'],
        'unavailable_seats' => $group['unavailable_seats'],
    ];
}

$eventTypes = ['all', 'Concert', 'Sports', 'Theatre', 'Conference', 'Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <style>
        body{
            font-family:Arial,sans-serif;
            background:#f6f7fb;
            color:#222;
            padding:20px;
        }
        .wrap{
            max-width:1450px;
            margin:0 auto;
        }
        .topbar{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }
        .nav a{
            margin-right:12px;
            text-decoration:none;
            color:#2563eb;
        }
        .section{
            background:#fff;
            border:1px solid #ddd;
            border-radius:12px;
            padding:18px;
            margin-bottom:20px;
        }
        h2,h3,h4{
            margin-top:0;
        }
        input,select,button{
            padding:9px 10px;
            margin:6px 0;
            box-sizing:border-box;
            border:1px solid #cfcfcf;
            border-radius:8px;
        }
        input,select{
            width:100%;
            max-width:360px;
        }
        button{
            cursor:pointer;
            background:#111827;
            color:#fff;
            border:none;
        }
        button.secondary{
            background:#6b7280;
        }
        button.danger{
            background:#b91c1c;
        }
        .grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:16px;
        }
        .group-card{
            border:1px solid #e3e3e3;
            border-radius:12px;
            padding:14px;
            background:#fafafa;
            margin-top:12px;
        }
        .group-head{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
            margin-bottom:10px;
        }
        .group-row{
            display:grid;
            grid-template-columns:repeat(5,minmax(0,1fr));
            gap:10px;
            align-items:start;
        }
        .seat-select{
            width:100%;
            min-height:130px;
            max-width:100%;
        }
        .inline{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
        }
        .swatch{
            display:inline-block;
            width:14px;
            height:14px;
            border-radius:50%;
            vertical-align:middle;
            margin-right:6px;
            border:1px solid #999;
        }
        table{
            width:100%;
            border-collapse:collapse;
            margin-top:12px;
        }
        th,td{
            border:1px solid #d6d6d6;
            padding:10px;
            vertical-align:top;
            text-align:left;
        }
        .badge{
            display:inline-block;
            padding:4px 10px;
            border-radius:999px;
            border:1px solid #ddd;
            margin:3px 4px 3px 0;
            font-size:12px;
        }
        .available{
            background:#dcfce7;
            border-color:#86efac;
        }
        .unavailable{
            background:#fef9c3;
            border-color:#fde047;
        }
        .booked{
            background:#fee2e2;
            border-color:#fca5a5;
        }
        .paid{
            background:#dcfce7;
            border-color:#86efac;
        }
        .pending{
            background:#fef9c3;
            border-color:#fde047;
        }
        .failed{
            background:#fee2e2;
            border-color:#fca5a5;
        }
        .cancelled{
            background:#e5e7eb;
            border-color:#cbd5e1;
        }
        .muted{
            color:#6b7280;
        }
        .event-box{
            border:1px solid #e5e7eb;
            border-radius:12px;
            padding:14px;
            background:#fff;
            margin-top:14px;
        }
        .actions a{
            margin-right:10px;
            text-decoration:none;
            color:#2563eb;
        }
        .actions a.delete{
            color:#b91c1c;
        }
        .small{
            font-size:12px;
        }
        .group-seats{
            margin-top:8px;
            display:flex;
            flex-wrap:wrap;
            gap:6px;
        }
        .group-seats .badge{
            min-width:76px;
            text-align:center;
        }
        .section label{
            font-weight:bold;
            display:inline-block;
            margin-top:2px;
        }
        .toolbar{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
            margin-bottom:10px;
        }
        .toolbar input[type="text"]{
            max-width:420px;
            flex:1 1 280px;
        }
        .filter-row{
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            align-items:center;
            margin:10px 0;
        }
        .filter-btn{
            display:inline-block;
            padding:8px 12px;
            border:1px solid #d1d5db;
            border-radius:999px;
            text-decoration:none;
            color:#111827;
            background:#fff;
            font-size:13px;
        }
        .filter-btn.active{
            background:#111827;
            color:#fff;
            border-color:#111827;
        }
        .pagination{
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            align-items:center;
            margin-top:14px;
        }
        .pagination a{
            display:inline-block;
            padding:8px 12px;
            border:1px solid #d1d5db;
            border-radius:10px;
            text-decoration:none;
            color:#111827;
            background:#fff;
        }
        .pagination a.active{
            background:#111827;
            color:#fff;
            border-color:#111827;
        }
        .pagination a.disabled{
            pointer-events:none;
            opacity:.5;
        }
        .control-title{
            margin:12px 0 6px;
            font-weight:bold;
        }
        .seat-status-toggles{
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            align-items:center;
            margin:10px 0 14px;
        }
        .seat-status-toggle{
            border-radius:999px;
            border:1px solid #d1d5db;
            background:#fff;
            color:#111827;
            padding:8px 12px;
            cursor:pointer;
            font-size:13px;
        }
        .seat-status-toggle.active{
            background:#111827;
            color:#fff;
            border-color:#111827;
        }
        .seat-pill.hidden-seat{
            display:none !important;
        }
        @media (max-width: 980px){
            .grid,.group-row{
                grid-template-columns:1fr;
            }
            input,select{
                max-width:100%;
            }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <h2>Admin Dashboard</h2>
        <div class="nav">
            <a href="#events">Events</a>
            <a href="#event-list">Added Events</a>
            <a href="#seat-overview">Seats Overview</a>
            <a href="#receipts">Receipts</a>
        </div>
    </div>

    <section id="events" class="section">
        <h3><?php echo $editEvent ? 'Edit Event' : 'Add Event'; ?></h3>

        <form method="POST" id="eventForm">
            <?php if ($editEvent) { ?>
                <input type="hidden" name="id" value="<?php echo (int)$editEvent['id']; ?>">
            <?php } ?>

            <div class="grid">
                <div>
                    <label>Event Type</label><br>
                    <select name="event_type" required>
                        <?php foreach ($eventTypes as $type) {
                            $selected = ($editEvent && $editEvent['event_type'] === $type) ? 'selected' : '';
                            echo '<option value="' . h($type) . '" ' . $selected . '>' . h($type) . '</option>';
                        } ?>
                    </select>
                </div>

                <div>
                    <label>Event Name</label><br>
                    <input type="text" name="event_name" value="<?php echo h($editEvent['event_name'] ?? ''); ?>" placeholder="Event Name" required>
                </div>

                <div>
                    <label>Venue Name</label><br>
                    <input type="text" name="venue_name" value="<?php echo h($editEvent['venue_name'] ?? ''); ?>" placeholder="Type venue name here" required>
                </div>

                <div>
                    <label>Event Date</label><br>
                    <input type="date" name="event_date" value="<?php echo h($editEvent['event_date'] ?? ''); ?>" required>
                </div>

                <div>
                    <label>Event Ticket Price</label><br>
                    <input type="number" step="0.01" name="ticket_price" value="<?php echo h($editEvent['ticket_price'] ?? ''); ?>" placeholder="Ticket Price" required>
                </div>

                <div>
                    <label>Event Service Charge / Fee</label><br>
                    <input type="number" step="0.01" name="service_fee" value="<?php echo h($editEvent['service_fee'] ?? ''); ?>" placeholder="Service Fee" required>
                </div>
            </div>

            <hr>

            <div class="inline" style="justify-content:space-between;">
                <h4 style="margin:0;">Seat Groups for this Event Venue</h4>
                <button type="button" class="secondary" onclick="addGroup()">+ Add Another Group</button>
            </div>

            <div id="groupsWrap"></div>

            <hr>
            <button type="submit" name="save_event"><?php echo $editEvent ? 'Apply Changes' : 'Add Event'; ?></button>
            <?php if ($editEvent) { ?>
                <a href="admin_dashboard.php#events" style="margin-left:10px;">Cancel</a>
            <?php } ?>
        </form>
    </section>

    <section id="event-list" class="section">
        <h3>Added Events</h3>

        <form method="GET" class="toolbar">
            <input type="text" name="events_q" value="<?php echo h($eventsSearch); ?>" placeholder="Search event name, venue, type, or date">
            <input type="hidden" name="events_type" value="<?php echo h($eventsType); ?>">
            <input type="hidden" name="events_sort" value="<?php echo h($eventsSort); ?>">
            <button type="submit">Search</button>
            <a class="filter-btn" href="<?php echo h(buildUrl(['events_q' => '', 'events_type' => 'all', 'events_sort' => 'latest'])); ?>">Reset</a>
        </form>

        <div class="control-title">Filter by type</div>
        <div class="filter-row">
            <?php foreach ($eventTypes as $type) { ?>
                <a
                    class="filter-btn <?php echo $eventsType === $type ? 'active' : ''; ?>"
                    href="<?php echo h(buildUrl(['events_type' => $type, 'events_q' => $eventsSearch])); ?>"
                >
                    <?php echo h($type === 'all' ? 'All' : $type); ?>
                </a>
            <?php } ?>
        </div>

        <div class="control-title">Sort</div>
        <div class="filter-row">
            <a class="filter-btn <?php echo $eventsSort === 'latest' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['events_sort' => 'latest', 'events_q' => $eventsSearch, 'events_type' => $eventsType])); ?>">Newest</a>
            <a class="filter-btn <?php echo $eventsSort === 'oldest' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['events_sort' => 'oldest', 'events_q' => $eventsSearch, 'events_type' => $eventsType])); ?>">Oldest</a>
            <a class="filter-btn <?php echo $eventsSort === 'name_asc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['events_sort' => 'name_asc', 'events_q' => $eventsSearch, 'events_type' => $eventsType])); ?>">Name A-Z</a>
            <a class="filter-btn <?php echo $eventsSort === 'name_desc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['events_sort' => 'name_desc', 'events_q' => $eventsSearch, 'events_type' => $eventsType])); ?>">Name Z-A</a>
            <a class="filter-btn <?php echo $eventsSort === 'date_asc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['events_sort' => 'date_asc', 'events_q' => $eventsSearch, 'events_type' => $eventsType])); ?>">Date ↑</a>
            <a class="filter-btn <?php echo $eventsSort === 'date_desc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['events_sort' => 'date_desc', 'events_q' => $eventsSearch, 'events_type' => $eventsType])); ?>">Date ↓</a>
            <a class="filter-btn <?php echo $eventsSort === 'venue_asc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['events_sort' => 'venue_asc', 'events_q' => $eventsSearch, 'events_type' => $eventsType])); ?>">Venue A-Z</a>
            <a class="filter-btn <?php echo $eventsSort === 'venue_desc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['events_sort' => 'venue_desc', 'events_q' => $eventsSearch, 'events_type' => $eventsType])); ?>">Venue Z-A</a>
            <a class="filter-btn <?php echo $eventsSort === 'price_asc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['events_sort' => 'price_asc', 'events_q' => $eventsSearch, 'events_type' => $eventsType])); ?>">Price Low-High</a>
            <a class="filter-btn <?php echo $eventsSort === 'price_desc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['events_sort' => 'price_desc', 'events_q' => $eventsSearch, 'events_type' => $eventsType])); ?>">Price High-Low</a>
        </div>

        <table>
            <tr>
                <th>Event ID</th>
                <th>Event Type</th>
                <th>Event Name</th>
                <th>Event Date</th>
                <th>Event Venue</th>
                <th>Event Venue Seat Groups</th>
                <th>Event Venue Seat Group Color</th>
                <th>Event Seat Group Price</th>
                <th>Event Ticket Price</th>
                <th>Event Service Charge/Fee</th>
                <th>Total Event Price</th>
                <th>Action</th>
            </tr>
            <?php if (!empty($eventsFiltered)) { ?>
                <?php foreach ($eventsFiltered as $event) { ?>
                    <tr>
                        <td><?php echo (int)$event['id']; ?></td>
                        <td><?php echo h($event['event_type']); ?></td>
                        <td><?php echo h($event['event_name']); ?></td>
                        <td><?php echo h($event['event_date']); ?></td>
                        <td><?php echo h($event['venue_name'] ?? '-'); ?></td>
                        <td>
                            <?php
                            $eid = (int)$event['id'];
                            if (!empty($eventGroups[$eid])) {
                                foreach ($eventGroups[$eid] as $group) {
                                    echo h($group['group_name']) . '<br>';
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            if (!empty($eventGroups[$eid])) {
                                foreach ($eventGroups[$eid] as $group) {
                                    echo '<span class="swatch" style="background:' . h($group['group_color']) . '"></span>' . h($group['group_color']) . '<br>';
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            if (!empty($eventGroups[$eid])) {
                                foreach ($eventGroups[$eid] as $group) {
                                    echo '₱' . h($group['group_price']) . '<br>';
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td>₱<?php echo h($event['ticket_price']); ?></td>
                        <td>₱<?php echo h($event['service_fee']); ?></td>
                        <td>₱<?php echo h($event['total']); ?></td>
                        <td class="actions">
                            <a href="?edit_event=<?php echo $eid; ?>#events">Edit</a>
                            <a href="?delete_event=<?php echo $eid; ?>#events" class="delete" onclick="return confirm('Delete this event and all its groups?')">Delete</a>
                        </td>
                    </tr>
                <?php } ?>
            <?php } else { ?>
                <tr>
                    <td colspan="12" class="muted">No events found.</td>
                </tr>
            <?php } ?>
        </table>
    </section>

    <section id="seat-overview" class="section">
        <h3>Seats Overview of Each Added Event</h3>
        <p class="muted small">Green = available, Yellow = not available, Red = booked. Only 2 events are shown per page.</p>

        <div class="seat-status-toggles">
            <button type="button" class="seat-status-toggle active" data-seat-status="available">Hide Available</button>
            <button type="button" class="seat-status-toggle active" data-seat-status="unavailable">Hide Not Available</button>
            <button type="button" class="seat-status-toggle active" data-seat-status="booked">Hide Booked</button>
            <button type="button" class="seat-status-toggle" data-seat-status="reset">Show All</button>
        </div>

        <form method="GET" class="toolbar">
            <input type="text" name="overview_q" value="<?php echo h($overviewSearch); ?>" placeholder="Search event name, venue, type, or date">
            <input type="hidden" name="overview_type" value="<?php echo h($overviewType); ?>">
            <input type="hidden" name="overview_sort" value="<?php echo h($overviewSort); ?>">
            <input type="hidden" name="overview_page" value="1">
            <button type="submit">Search</button>
            <a class="filter-btn" href="<?php echo h(buildUrl(['overview_q' => '', 'overview_type' => 'all', 'overview_sort' => 'latest', 'overview_page' => 1])); ?>">Reset</a>
        </form>

        <div class="control-title">Filter by type</div>
        <div class="filter-row">
            <?php foreach ($eventTypes as $type) { ?>
                <a
                    class="filter-btn <?php echo $overviewType === $type ? 'active' : ''; ?>"
                    href="<?php echo h(buildUrl(['overview_type' => $type, 'overview_q' => $overviewSearch, 'overview_page' => 1])); ?>"
                >
                    <?php echo h($type === 'all' ? 'All' : $type); ?>
                </a>
            <?php } ?>
        </div>

        <div class="control-title">Sort</div>
        <div class="filter-row">
            <a class="filter-btn <?php echo $overviewSort === 'latest' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['overview_sort' => 'latest', 'overview_q' => $overviewSearch, 'overview_type' => $overviewType, 'overview_page' => 1])); ?>">Newest</a>
            <a class="filter-btn <?php echo $overviewSort === 'oldest' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['overview_sort' => 'oldest', 'overview_q' => $overviewSearch, 'overview_type' => $overviewType, 'overview_page' => 1])); ?>">Oldest</a>
            <a class="filter-btn <?php echo $overviewSort === 'name_asc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['overview_sort' => 'name_asc', 'overview_q' => $overviewSearch, 'overview_type' => $overviewType, 'overview_page' => 1])); ?>">Name A-Z</a>
            <a class="filter-btn <?php echo $overviewSort === 'name_desc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['overview_sort' => 'name_desc', 'overview_q' => $overviewSearch, 'overview_type' => $overviewType, 'overview_page' => 1])); ?>">Name Z-A</a>
            <a class="filter-btn <?php echo $overviewSort === 'date_asc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['overview_sort' => 'date_asc', 'overview_q' => $overviewSearch, 'overview_type' => $overviewType, 'overview_page' => 1])); ?>">Date ↑</a>
            <a class="filter-btn <?php echo $overviewSort === 'date_desc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['overview_sort' => 'date_desc', 'overview_q' => $overviewSearch, 'overview_type' => $overviewType, 'overview_page' => 1])); ?>">Date ↓</a>
            <a class="filter-btn <?php echo $overviewSort === 'venue_asc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['overview_sort' => 'venue_asc', 'overview_q' => $overviewSearch, 'overview_type' => $overviewType, 'overview_page' => 1])); ?>">Venue A-Z</a>
            <a class="filter-btn <?php echo $overviewSort === 'venue_desc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['overview_sort' => 'venue_desc', 'overview_q' => $overviewSearch, 'overview_type' => $overviewType, 'overview_page' => 1])); ?>">Venue Z-A</a>
            <a class="filter-btn <?php echo $overviewSort === 'price_asc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['overview_sort' => 'price_asc', 'overview_q' => $overviewSearch, 'overview_type' => $overviewType, 'overview_page' => 1])); ?>">Price Low-High</a>
            <a class="filter-btn <?php echo $overviewSort === 'price_desc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['overview_sort' => 'price_desc', 'overview_q' => $overviewSearch, 'overview_type' => $overviewType, 'overview_page' => 1])); ?>">Price High-Low</a>
        </div>

        <?php if (!empty($overviewPaged)) { ?>
            <?php foreach ($overviewPaged as $event) { ?>
                <div class="event-box">
                    <h4>Event #<?php echo (int)$event['id']; ?> — <?php echo h($event['event_name']); ?></h4>
                    <div class="muted">Venue: <?php echo h($event['venue_name'] ?? '-'); ?> | Date: <?php echo h($event['event_date']); ?></div>

                    <?php if (!empty($eventGroups[(int)$event['id']])) { ?>
                        <?php foreach ($eventGroups[(int)$event['id']] as $group) { ?>
                            <div style="margin-top:14px;">
                                <div class="inline">
                                    <strong><?php echo h($group['group_name']); ?></strong>
                                    <span class="swatch" style="background:<?php echo h($group['group_color']); ?>"></span>
                                    <span class="muted">Color: <?php echo h($group['group_color']); ?></span>
                                    <span class="muted">Price: ₱<?php echo h($group['group_price']); ?></span>
                                    <span class="badge available">Available: <?php echo (int)$group['counts']['available']; ?></span>
                                    <span class="badge unavailable">Not Available: <?php echo (int)$group['counts']['unavailable']; ?></span>
                                    <span class="badge booked">Booked: <?php echo (int)$group['counts']['booked']; ?></span>
                                </div>

                                <div class="group-seats">
                                    <?php foreach ($group['seats'] as $seat) { ?>
                                        <?php
                                            $status = $seat['status'] ?: 'available';
                                            $seatLabel = $group['group_name'] . $seat['seat_number'];
                                        ?>
                                        <span class="badge seat-pill <?php echo h($status); ?>" data-seat-status="<?php echo h($status); ?>">
                                            <?php echo h($seatLabel); ?>
                                        </span>
                                    <?php } ?>
                                </div>
                            </div>
                        <?php } ?>
                    <?php } else { ?>
                        <p class="muted">No seat groups yet for this event.</p>
                    <?php } ?>
                </div>
            <?php } ?>
        <?php } else { ?>
            <div class="event-box">
                <p class="muted">No events found for the current overview filters.</p>
            </div>
        <?php } ?>

        <div class="pagination">
            <?php if ($overviewPage > 1) { ?>
                <a href="<?php echo h(buildUrl(['overview_page' => $overviewPage - 1, 'overview_q' => $overviewSearch, 'overview_type' => $overviewType, 'overview_sort' => $overviewSort])); ?>">Prev</a>
            <?php } else { ?>
                <a class="disabled" href="#">Prev</a>
            <?php } ?>

            <?php for ($p = 1; $p <= $overviewTotalPages; $p++) { ?>
                <a
                    class="<?php echo $p === $overviewPage ? 'active' : ''; ?>"
                    href="<?php echo h(buildUrl(['overview_page' => $p, 'overview_q' => $overviewSearch, 'overview_type' => $overviewType, 'overview_sort' => $overviewSort])); ?>"
                >
                    <?php echo $p; ?>
                </a>
            <?php } ?>

            <?php if ($overviewPage < $overviewTotalPages) { ?>
                <a href="<?php echo h(buildUrl(['overview_page' => $overviewPage + 1, 'overview_q' => $overviewSearch, 'overview_type' => $overviewType, 'overview_sort' => $overviewSort])); ?>">Next</a>
            <?php } else { ?>
                <a class="disabled" href="#">Next</a>
            <?php } ?>
        </div>
    </section>

    <section id="receipts" class="section">
        <h3>Receipts</h3>

        <form method="GET" class="toolbar">
            <input
                type="text"
                name="receipt_q"
                value="<?php echo h($receiptSearch); ?>"
                placeholder="Search ticket number, customer name, or event name"
            >
            <input type="hidden" name="receipt_sort" value="<?php echo h($receiptSort); ?>">
            <input type="hidden" name="receipt_status" value="<?php echo h($receiptStatus); ?>">
            <input type="hidden" name="receipt_method" value="<?php echo h($receiptMethod); ?>">
            <button type="submit">Search</button>
            <a class="filter-btn" href="<?php echo h(buildUrl(['receipt_q' => '', 'receipt_sort' => 'latest', 'receipt_status' => 'all', 'receipt_method' => 'all'])); ?>">Reset</a>
        </form>

        <div class="filter-row">
            <span class="muted">Sort:</span>
            <a class="filter-btn <?php echo $receiptSort === 'latest' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_sort' => 'latest'])); ?>">Newest</a>
            <a class="filter-btn <?php echo $receiptSort === 'oldest' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_sort' => 'oldest'])); ?>">Oldest</a>
            <a class="filter-btn <?php echo $receiptSort === 'ref_asc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_sort' => 'ref_asc'])); ?>">Ticket A-Z</a>
            <a class="filter-btn <?php echo $receiptSort === 'ref_desc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_sort' => 'ref_desc'])); ?>">Ticket Z-A</a>
            <a class="filter-btn <?php echo $receiptSort === 'total_asc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_sort' => 'total_asc'])); ?>">Total Low-High</a>
            <a class="filter-btn <?php echo $receiptSort === 'total_desc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_sort' => 'total_desc'])); ?>">Total High-Low</a>
            <a class="filter-btn <?php echo $receiptSort === 'event_date_asc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_sort' => 'event_date_asc'])); ?>">Event Date ↑</a>
            <a class="filter-btn <?php echo $receiptSort === 'event_date_desc' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_sort' => 'event_date_desc'])); ?>">Event Date ↓</a>
        </div>

        <div class="filter-row">
            <span class="muted">Payment Status:</span>
            <a class="filter-btn <?php echo $receiptStatus === 'all' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_status' => 'all'])); ?>">All</a>
            <a class="filter-btn <?php echo $receiptStatus === 'paid' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_status' => 'paid'])); ?>">Paid</a>
            <a class="filter-btn <?php echo $receiptStatus === 'pending' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_status' => 'pending'])); ?>">Pending</a>
            <a class="filter-btn <?php echo $receiptStatus === 'failed' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_status' => 'failed'])); ?>">Failed</a>
            <a class="filter-btn <?php echo $receiptStatus === 'cancelled' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_status' => 'cancelled'])); ?>">Cancelled</a>
        </div>

        <div class="filter-row">
            <span class="muted">Payment Method:</span>
            <a class="filter-btn <?php echo $receiptMethod === 'all' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_method' => 'all'])); ?>">All</a>
            <a class="filter-btn <?php echo $receiptMethod === 'GCASH' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_method' => 'GCASH'])); ?>">GCash</a>
            <a class="filter-btn <?php echo $receiptMethod === 'MAYA' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_method' => 'MAYA'])); ?>">Maya</a>
            <a class="filter-btn <?php echo $receiptMethod === 'PAYPAL' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_method' => 'PAYPAL'])); ?>">PayPal</a>
            <a class="filter-btn <?php echo $receiptMethod === 'BANK' ? 'active' : ''; ?>" href="<?php echo h(buildUrl(['receipt_method' => 'BANK'])); ?>">Bank</a>
        </div>

        <div class="muted small" style="margin-bottom:10px;">
            Showing <?php echo (int)$receiptCount; ?> receipt(s).
        </div>

        <table class="receipt-table">
            <tr>
                <th>Ticket No.</th>
                <th>Customer</th>
                <th>Event</th>
                <th>Venue</th>
                <th>Seats</th>
                <th>Payment Method</th>
                <th>Status</th>
                <th>Subtotal</th>
                <th>Service Fee</th>
                <th>Total</th>
            </tr>
            <?php if (!empty($receipts)) { ?>
                <?php foreach ($receipts as $receipt) { ?>
                    <?php
                        $status = strtolower((string)($receipt['payment_status'] ?? ''));
                        $statusClass = in_array($status, ['paid', 'pending', 'failed', 'cancelled'], true) ? $status : '';
                        $seatCodes = trim((string)($receipt['seat_codes'] ?? ''));
                        if ($seatCodes === '') {
                            $seatCodes = '-';
                        }
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo h($receipt['reference_number'] ?? '-'); ?></strong><br>
                            <span class="muted small">Booking #<?php echo (int)$receipt['id']; ?></span>
                        </td>
                        <td>
                            <?php echo h($receipt['customer_name'] ?? '-'); ?><br>
                            <span class="muted small"><?php echo h($receipt['customer_email'] ?? '-'); ?></span><br>
                            <span class="muted small"><?php echo h($receipt['customer_phone'] ?? '-'); ?></span>
                        </td>
                        <td>
                            <?php echo h($receipt['event_name'] ?? '-'); ?><br>
                            <span class="muted small"><?php echo h($receipt['event_type'] ?? '-'); ?></span><br>
                            <span class="muted small"><?php echo h($receipt['event_date'] ?? '-'); ?></span>
                        </td>
                        <td><?php echo h($receipt['venue_name'] ?? '-'); ?></td>
                        <td>
                            <strong><?php echo (int)($receipt['seat_count'] ?? 0); ?></strong><br>
                            <span class="muted small"><?php echo h($seatCodes); ?></span>
                        </td>
                        <td><?php echo h($receipt['payment_method'] ?? '-'); ?></td>
                        <td><span class="badge <?php echo h($statusClass); ?>"><?php echo h($receipt['payment_status'] ?? '-'); ?></span></td>
                        <td>₱<?php echo number_format((float)($receipt['subtotal'] ?? 0), 2); ?></td>
                        <td>₱<?php echo number_format((float)($receipt['service_fee'] ?? 0), 2); ?></td>
                        <td><strong>₱<?php echo number_format((float)($receipt['total'] ?? 0), 2); ?></strong></td>
                    </tr>
                <?php } ?>
            <?php } else { ?>
                <tr>
                    <td colspan="10" class="muted">No receipts found.</td>
                </tr>
            <?php } ?>
        </table>
    </section>
</div>

<script>
const editGroups = <?php echo json_encode($editGroupsJson); ?>;
let groupIndex = 0;

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}

function seatOptions(count, selected = []) {
    let html = '';
    for (let i = 1; i <= count; i++) {
        const sel = selected.includes(String(i)) ? 'selected' : '';
        html += `<option value="${i}" ${sel}>Seat ${i}</option>`;
    }
    return html;
}

function updateUnavailableSummary(select) {
    const card = select.closest('.group-card');
    const summary = card.querySelector('.unavailable-summary');
    const selected = Array.from(select.selectedOptions).map(opt => opt.value);

    summary.textContent = selected.length
        ? 'Selected unavailable seats: ' + selected.join(', ')
        : 'Selected unavailable seats: none';
}

function refreshGroupSeats(input) {
    const card = input.closest('.group-card');
    const count = parseInt(input.value, 10) || 0;
    const select = card.querySelector('select.seat-select');
    const previouslySelected = Array.from(select.selectedOptions).map(opt => opt.value);

    select.innerHTML = seatOptions(count, previouslySelected.filter(v => parseInt(v, 10) <= count));
    updateUnavailableSummary(select);
}

function groupTemplate(index, data = null) {
    const groupName = data?.group_name ?? '';
    const groupColor = data?.group_color ?? '#3b82f6';
    const seatCount = parseInt(data?.seat_count ?? 10, 10);
    const groupPrice = data?.group_price ?? '';
    const unavailable = data?.unavailable_seats ?? [];

    return `
        <div class="group-card" data-index="${index}">
            <div class="group-head">
                <strong>Group ${index + 1}</strong>
                <button type="button" class="danger" onclick="removeGroup(this)">Remove</button>
            </div>

            <div class="group-row">
                <div>
                    <label>Group Name (Row)</label><br>
                    <input type="text" name="group_name[]" value="${escapeHtml(groupName)}" placeholder="VIP, A, B, GENAD" required>
                </div>

                <div>
                    <label>Seat Group Color</label><br>
                    <input type="color" name="group_color[]" value="${groupColor}" required>
                </div>

                <div>
                    <label>Add seats</label><br>
                    <input type="number" min="1" name="seat_count[]" value="${seatCount}" onchange="refreshGroupSeats(this)" onkeyup="refreshGroupSeats(this)" required>
                </div>

                <div>
                    <label>Add price</label><br>
                    <input type="number" step="0.01" name="group_price[]" value="${groupPrice}" placeholder="Price for this group" required>
                </div>

                <div>
                    <label>Seat that is not available</label><br>
                    <select class="seat-select" name="group_unavailable[${index}][]" multiple onchange="updateUnavailableSummary(this)">
                        ${seatOptions(seatCount, unavailable)}
                    </select>
                    <div class="muted small unavailable-summary" style="margin-top:6px;">
                        Selected unavailable seats: ${unavailable.length ? unavailable.join(', ') : 'none'}
                    </div>
                    <div class="muted small">Hold Ctrl on Windows or Command on Mac to choose multiple seats.</div>
                </div>
            </div>
        </div>
    `;
}

function rebuildIndexes() {
    const cards = document.querySelectorAll('.group-card');
    cards.forEach((card, idx) => {
        card.dataset.index = idx;
        const strong = card.querySelector('.group-head strong');
        if (strong) strong.textContent = `Group ${idx + 1}`;

        const select = card.querySelector('select.seat-select');
        if (select) {
            select.name = `group_unavailable[${idx}][]`;
        }
    });
    groupIndex = cards.length;
}

function addGroup(data = null) {
    const wrap = document.getElementById('groupsWrap');
    wrap.insertAdjacentHTML('beforeend', groupTemplate(groupIndex, data));
    groupIndex++;
    rebuildIndexes();
}

function removeGroup(btn) {
    const card = btn.closest('.group-card');
    if (card) {
        card.remove();
        rebuildIndexes();
        if (document.querySelectorAll('.group-card').length === 0) {
            addGroup();
        }
    }
}

/*
|--------------------------------------------------------------------------
| PRESERVE SCROLL POSITION ON FILTER/SORT/SEARCH/PAGINATION
|--------------------------------------------------------------------------
*/
(function () {
    const storageKey = 'admin_dashboard_scroll_y';

    function saveScroll() {
        sessionStorage.setItem(storageKey, String(window.scrollY || window.pageYOffset || 0));
    }

    document.addEventListener('click', function (e) {
        const target = e.target.closest(
            'a.filter-btn, .pagination a, .toolbar a, .toolbar button, .actions a'
        );
        if (target) {
            saveScroll();
        }
    }, true);

    document.addEventListener('submit', function () {
        saveScroll();
    }, true);

    window.addEventListener('beforeunload', saveScroll);

    window.addEventListener('load', function () {
        const y = sessionStorage.getItem(storageKey);
        if (y !== null && y !== '') {
            window.scrollTo(0, parseInt(y, 10) || 0);
        }
        sessionStorage.removeItem(storageKey);
    });
})();

/*
|--------------------------------------------------------------------------
| SEAT OVERVIEW TOGGLES
|--------------------------------------------------------------------------
*/
(function () {
    const storageKey = 'admin_dashboard_seat_visibility';
    const defaultState = {
        available: true,
        unavailable: true,
        booked: true
    };

    let state = { ...defaultState };

    function loadState() {
        try {
            const raw = localStorage.getItem(storageKey);
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') {
                    state.available = parsed.available !== undefined ? !!parsed.available : true;
                    state.unavailable = parsed.unavailable !== undefined ? !!parsed.unavailable : true;
                    state.booked = parsed.booked !== undefined ? !!parsed.booked : true;
                }
            }
        } catch (e) {
            state = { ...defaultState };
        }
    }

    function saveState() {
        localStorage.setItem(storageKey, JSON.stringify(state));
    }

    function applyState() {
        document.querySelectorAll('.seat-pill').forEach(function (el) {
            const status = el.dataset.seatStatus || 'available';
            el.classList.toggle('hidden-seat', !state[status]);
        });

        document.querySelectorAll('.seat-status-toggle').forEach(function (btn) {
            const status = btn.dataset.seatStatus;

            if (status === 'reset') {
                btn.classList.toggle('active', state.available && state.unavailable && state.booked);
                btn.textContent = 'Show All';
                return;
            }

            const isVisible = !!state[status];
            btn.classList.toggle('active', isVisible);
            btn.textContent = (isVisible ? 'Hide ' : 'Show ') + capitalize(status);
        });
    }

    function capitalize(text) {
        return text.charAt(0).toUpperCase() + text.slice(1);
    }

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.seat-status-toggle');
        if (!btn) {
            return;
        }

        const status = btn.dataset.seatStatus;

        if (status === 'reset') {
            state.available = true;
            state.unavailable = true;
            state.booked = true;
            saveState();
            applyState();
            return;
        }

        if (status in state) {
            state[status] = !state[status];
            saveState();
            applyState();
        }
    });

    window.addEventListener('load', function () {
        loadState();
        applyState();
    });
})();

window.addEventListener('load', function () {
    if (editGroups.length > 0) {
        editGroups.forEach(g => addGroup(g));
    } else {
        addGroup();
    }
});
</script>
</body>
</html>