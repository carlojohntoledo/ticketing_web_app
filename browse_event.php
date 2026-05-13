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

if (!isset($_SESSION['selected_event_type'])) {
    header("Location: select_event.php");
    exit();
}

$eventType = $_SESSION['selected_event_type'];

/*
|--------------------------------------------------------------------------
| GET EVENTS
|--------------------------------------------------------------------------
| Uses new database structure
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        t.id,
        t.event_name,
        t.event_type,
        t.event_date,
        t.ticket_price,
        t.service_fee,
        v.venue_name
    FROM tickets t
    LEFT JOIN venues v
        ON v.id = t.venue_id
    WHERE t.event_type='$eventType'
    ORDER BY t.event_date ASC
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Event query failed: " . mysqli_error($conn));
}

/*
|--------------------------------------------------------------------------
| HANDLE EVENT SELECT
|--------------------------------------------------------------------------
*/

if (isset($_POST['next'])) {

    $eventId = (int)($_POST['event_id'] ?? 0);
    $eventName = trim($_POST['event_name'] ?? '');

    if ($eventId <= 0 || $eventName == '') {

        $error = "Please select an event.";

    } else {

        $_SESSION['event_id'] = $eventId;
        $_SESSION['event_name'] = $eventName;

        header("Location: seat.php");
        exit();
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <title>Select Event</title>

    <link rel="stylesheet" href="general_style.css">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, user-scalable=no"
    >

    <style>

        body{
            font-family:Arial,sans-serif;
            background:#f5f5f5;
            margin:0;
            padding:20px;
        }

        .main_cont{
            max-width:900px;
            margin:auto;
        }

        .header-box{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:14px;
            padding:22px;
            margin-bottom:20px;
        }

        .event-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
            gap:16px;
        }

        .event-card{
            background:#fff;
            border:2px solid #e5e7eb;
            border-radius:14px;
            padding:18px;
            cursor:pointer;
            transition:.2s;
        }

        .event-card:hover{
            border-color:#111827;
            transform:translateY(-2px);
        }

        .event-card.active{
            border-color:#16a34a;
            background:#f0fdf4;
        }

        .event-name{
            font-size:20px;
            font-weight:bold;
            margin-bottom:12px;
        }

        .line{
            margin-bottom:8px;
            color:#374151;
            line-height:1.5;
        }

        .price{
            margin-top:12px;
            font-weight:bold;
        }

        .error-box{
            background:#fff2f2;
            border:1px solid #fecaca;
            color:#b91c1c;
            padding:12px;
            border-radius:10px;
            margin-bottom:15px;
        }

        .empty-box{
            background:#fff;
            border:1px dashed #ccc;
            border-radius:12px;
            padding:20px;
            color:#666;
        }

        .action-box{
            margin-top:25px;
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:14px;
            padding:20px;
        }

        button{
            padding:12px 20px;
            border:none;
            border-radius:10px;
            background:#111827;
            color:white;
            cursor:pointer;
            width:100%;
            font-size:15px;
        }

        button:disabled{
            opacity:.5;
            cursor:not-allowed;
        }

    </style>

    <script>

        function selectEvent(eventId, eventName, cardEl) {

            document.getElementById("selectedEventId").value = eventId;
            document.getElementById("selectedEventName").value = eventName;

            document.getElementById("nextBtn").disabled = false;

            const cards = document.getElementsByClassName("event-card");

            for (let card of cards) {
                card.classList.remove("active");
            }

            cardEl.classList.add("active");
        }

    </script>

</head>

<body>

<div class="main_cont">

    <div class="header-box">

        <h2>Select Event</h2>

        <div class="line">
            <b>Selected Category:</b>
            <?php echo htmlspecialchars($eventType); ?>
        </div>

    </div>

    <?php if (!empty($error)) { ?>

        <div class="error-box">
            <?php echo htmlspecialchars($error); ?>
        </div>

    <?php } ?>

    <?php if (mysqli_num_rows($result) > 0) { ?>

        <div class="event-grid">

            <?php while ($row = mysqli_fetch_assoc($result)) { ?>

                <?php
                    $totalStart =
                        (float)$row['ticket_price'] +
                        (float)$row['service_fee'];
                ?>

                <div
                    class="event-card"
                    onclick="selectEvent(
                        '<?php echo $row['id']; ?>',
                        '<?php echo htmlspecialchars($row['event_name'], ENT_QUOTES); ?>',
                        this
                    )"
                >

                    <div class="event-name">
                        <?php echo htmlspecialchars($row['event_name']); ?>
                    </div>

                    <div class="line">
                        <b>Type:</b>
                        <?php echo htmlspecialchars($row['event_type']); ?>
                    </div>

                    <div class="line">
                        <b>Date:</b>
                        <?php echo htmlspecialchars($row['event_date']); ?>
                    </div>

                    <div class="line">
                        <b>Venue:</b>
                        <?php echo htmlspecialchars($row['venue_name']); ?>
                    </div>

                    <div class="line">
                        <b>Ticket Price:</b>
                        ₱<?php echo number_format((float)$row['ticket_price'], 2); ?>
                    </div>

                    <div class="line">
                        <b>Service Fee:</b>
                        ₱<?php echo number_format((float)$row['service_fee'], 2); ?>
                    </div>

                    <div class="price">
                        Starts at ₱<?php echo number_format($totalStart, 2); ?>
                    </div>

                </div>

            <?php } ?>

        </div>

        <div class="action-box">

            <form method="POST">

                <input
                    type="hidden"
                    name="event_id"
                    id="selectedEventId"
                >

                <input
                    type="hidden"
                    name="event_name"
                    id="selectedEventName"
                >

                <button
                    type="submit"
                    name="next"
                    id="nextBtn"
                    disabled
                >
                    Proceed to Seat Selection
                </button>

            </form>

        </div>

    <?php } else { ?>

        <div class="empty-box">
            No events found under this category.
        </div>

    <?php } ?>

</div>

</body>
</html>