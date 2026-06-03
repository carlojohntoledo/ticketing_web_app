<?php
include '../db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| LOAD VENUES
|--------------------------------------------------------------------------
*/
$venuesResult = mysqli_query($conn, "SELECT * FROM venues ORDER BY venue_name");

$venues = [];
while ($v = mysqli_fetch_assoc($venuesResult)) {
    $venues[] = $v;
}

/*
|--------------------------------------------------------------------------
| LOAD ROWS PER VENUE
|--------------------------------------------------------------------------
*/
$rowsResult = mysqli_query($conn, "SELECT venue_id, row_name FROM venue_rows ORDER BY venue_id, row_name");

$venueRows = [];
while ($r = mysqli_fetch_assoc($rowsResult)) {
    $venueRows[(string)$r['venue_id']][] = $r['row_name'];
}

/*
|--------------------------------------------------------------------------
| EDIT MODE
|--------------------------------------------------------------------------
*/
$editData = null;
$editRowPrices = [];

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    $editQuery = mysqli_query($conn, "SELECT * FROM tickets WHERE id='$editId'");
    if ($editQuery && mysqli_num_rows($editQuery) > 0) {
        $editData = mysqli_fetch_assoc($editQuery);

        $rowPriceQuery = mysqli_query(
            $conn,
            "SELECT row_name, price FROM event_row_prices WHERE event_id='$editId'"
        );

        if ($rowPriceQuery) {
            while ($rp = mysqli_fetch_assoc($rowPriceQuery)) {
                $editRowPrices[$rp['row_name']] = $rp['price'];
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| ADD EVENT
|--------------------------------------------------------------------------
*/
if (isset($_POST['add'])) {

    $event_type = $_POST['event_type'];
    $event_name = $_POST['event_name'];
    $venue_id   = $_POST['venue_id'];
    $date       = $_POST['date'];
    $ticket_price = $_POST['ticket_price'];
    $service_fee  = $_POST['service_fee'];

    $total = $ticket_price + $service_fee;

    $insertEvent = mysqli_query(
        $conn,
        "INSERT INTO tickets
        (event_type, event_name, venue_id, date, ticket_price, service_fee, total)
        VALUES
        ('$event_type','$event_name','$venue_id','$date','$ticket_price','$service_fee','$total')"
    );

    if (!$insertEvent) {
        die("Insert event failed: " . mysqli_error($conn));
    }

    $event_id = mysqli_insert_id($conn);

    if (isset($_POST['row_price']) && is_array($_POST['row_price'])) {
        foreach ($_POST['row_price'] as $row_name => $price) {
            $row_name = mysqli_real_escape_string($conn, $row_name);
            $price = (float)$price;

            mysqli_query(
                $conn,
                "INSERT INTO event_row_prices
                (event_id, venue_id, row_name, price)
                VALUES
                ('$event_id','$venue_id','$row_name','$price')"
            );
        }
    }

    header("Location: events.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| UPDATE EVENT
|--------------------------------------------------------------------------
*/
if (isset($_POST['update'])) {

    $id          = (int)$_POST['id'];
    $event_type  = $_POST['event_type'];
    $event_name  = $_POST['event_name'];
    $venue_id    = $_POST['venue_id'];
    $date        = $_POST['date'];
    $ticket_price = $_POST['ticket_price'];
    $service_fee  = $_POST['service_fee'];

    $total = $ticket_price + $service_fee;

    $updateEvent = mysqli_query(
        $conn,
        "UPDATE tickets SET
            event_type='$event_type',
            event_name='$event_name',
            venue_id='$venue_id',
            date='$date',
            ticket_price='$ticket_price',
            service_fee='$service_fee',
            total='$total'
        WHERE id='$id'"
    );

    if (!$updateEvent) {
        die("Update event failed: " . mysqli_error($conn));
    }

    mysqli_query($conn, "DELETE FROM event_row_prices WHERE event_id='$id'");

    if (isset($_POST['row_price']) && is_array($_POST['row_price'])) {
        foreach ($_POST['row_price'] as $row_name => $price) {
            $row_name = mysqli_real_escape_string($conn, $row_name);
            $price = (float)$price;

            mysqli_query(
                $conn,
                "INSERT INTO event_row_prices
                (event_id, venue_id, row_name, price)
                VALUES
                ('$id','$venue_id','$row_name','$price')"
            );
        }
    }

    header("Location: events.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| DELETE EVENT
|--------------------------------------------------------------------------
*/
if (isset($_GET['delete'])) {

    $id = (int)$_GET['delete'];

    mysqli_query($conn, "DELETE FROM event_row_prices WHERE event_id='$id'");
    mysqli_query($conn, "DELETE FROM tickets WHERE id='$id'");

    header("Location: events.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| EVENT LIST
|--------------------------------------------------------------------------
*/
$events = mysqli_query(
    $conn,
    "SELECT t.*, v.venue_name
     FROM tickets t
     LEFT JOIN venues v ON t.venue_id = v.id
     ORDER BY t.id DESC"
);

$eventRowPrices = [];
$eventPriceQuery = mysqli_query(
    $conn,
    "SELECT event_id, row_name, price
     FROM event_row_prices
     ORDER BY event_id, row_name"
);

if ($eventPriceQuery) {
    while ($rp = mysqli_fetch_assoc($eventPriceQuery)) {
        $eventRowPrices[$rp['event_id']][] = $rp;
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Events</title>

    <style>

        input, select {
            padding: 8px;
            margin: 5px 0;
            width: 260px;
            box-sizing: border-box;
        }

        button {
            padding: 8px 15px;
            margin-top: 5px;
            cursor: pointer;
        }

        .row-box {
            margin: 10px 0;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: #f9f9f9;
        }

        .row-box label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .row-box input {
            width: 220px;
        }

        table {
            margin-top: 20px;
            width: 100%;
            border-collapse: collapse;
        }

        td, th {
            border: 1px solid #ccc;
            padding: 10px;
            vertical-align: top;
            text-align: left;
        }

        .row-prices {
            white-space: pre-line;
            line-height: 1.5;
        }

        .small-link {
            margin-right: 10px;
        }
    </style>
</head>
<body>

<h2><?php echo $editData ? "Edit Event" : "Add Event"; ?></h2>

<form method="POST">

    <?php if ($editData) { ?>
        <input type="hidden" name="id" value="<?php echo $editData['id']; ?>">
    <?php } ?>

    <select name="event_type" required>
        <option value="Concert" <?php echo ($editData && $editData['event_type'] === 'Concert') ? 'selected' : ''; ?>>Concert</option>
        <option value="Sports" <?php echo ($editData && $editData['event_type'] === 'Sports') ? 'selected' : ''; ?>>Sports</option>
        <option value="Theatre" <?php echo ($editData && $editData['event_type'] === 'Theatre') ? 'selected' : ''; ?>>Theatre</option>
    </select><br>

    <input
        type="text"
        name="event_name"
        value="<?php echo $editData['event_name'] ?? ''; ?>"
        placeholder="Event Name"
        required
    ><br>

    <select
        name="venue_id"
        id="venueSelect"
        onchange="loadRows()"
        required
    >
        <option value="">Select Venue</option>
        <?php foreach ($venues as $v) { ?>
            <option value="<?php echo $v['id']; ?>"
                <?php echo ($editData && (string)$editData['venue_id'] === (string)$v['id']) ? 'selected' : ''; ?>>
                <?php echo $v['venue_name']; ?>
            </option>
        <?php } ?>
    </select><br>

    <div id="rowPricesWrap" style="margin-top:15px;"></div>

    <input
        type="date"
        name="date"
        value="<?php echo $editData['date'] ?? ''; ?>"
        required
    ><br>

    <input
        type="number"
        step="0.01"
        name="ticket_price"
        value="<?php echo $editData['ticket_price'] ?? ''; ?>"
        placeholder="Ticket Price"
        required
    ><br>

    <input
        type="number"
        step="0.01"
        name="service_fee"
        value="<?php echo $editData['service_fee'] ?? ''; ?>"
        placeholder="Service Fee"
        required
    ><br>

    <?php if ($editData) { ?>
        <button type="submit" name="update">Update Event</button>
        <a href="events.php" style="margin-left:10px;">Cancel</a>
    <?php } else { ?>
        <button type="submit" name="add">Add Event</button>
    <?php } ?>

</form>

<hr>

<h2>Event List</h2>

<table>
    <tr>
        <th>ID</th>
        <th>Type</th>
        <th>Name</th>
        <th>Venue</th>
        <th>Date</th>
        <th>Price</th>
        <th>Fee</th>
        <th>Total</th>
        <th>Row Prices</th>
        <th>Action</th>
    </tr>

    <?php while ($e = mysqli_fetch_assoc($events)) { ?>
        <tr>
            <td><?php echo $e['id']; ?></td>
            <td><?php echo $e['event_type']; ?></td>
            <td><?php echo $e['event_name']; ?></td>
            <td><?php echo $e['venue_name']; ?></td>
            <td><?php echo $e['date']; ?></td>
            <td><?php echo $e['ticket_price']; ?></td>
            <td><?php echo $e['service_fee']; ?></td>
            <td><?php echo $e['total']; ?></td>
            <td class="row-prices">
                <?php
                $eid = $e['id'];
                if (isset($eventRowPrices[$eid])) {
                    foreach ($eventRowPrices[$eid] as $rp) {
                        echo $rp['row_name'] . ": " . $rp['price'] . "\n";
                    }
                } else {
                    echo "-";
                }
                ?>
            </td>
            <td>
                <a class="small-link" href="?edit=<?php echo $e['id']; ?>">Edit</a>
                <a class="small-link" href="?delete=<?php echo $e['id']; ?>" onclick="return confirm('Delete this event?')">Delete</a>
            </td>
        </tr>
    <?php } ?>
</table>

<script>
const venueRows = <?php echo json_encode($venueRows); ?>;
const editVenueId = <?php echo $editData ? json_encode((string)$editData['venue_id']) : 'null'; ?>;
const editRowPrices = <?php echo json_encode($editRowPrices); ?>;

function loadRows() {
    const venueId = document.getElementById("venueSelect").value;
    const container = document.getElementById("rowPricesWrap");

    container.innerHTML = "";

    if (!venueId || !venueRows[venueId] || venueRows[venueId].length === 0) {
        container.innerHTML = "<p><i>No rows found for this venue.</i></p>";
        return;
    }

    venueRows[venueId].forEach((row) => {
        const currentValue = editRowPrices[row] ?? "";

        const box = document.createElement("div");
        box.className = "row-box";

        box.innerHTML = `
            <label>Row ${row}</label>
            <input
                type="number"
                step="0.01"
                name="row_price[${row}]"
                placeholder="Enter price for Row ${row}"
                value="${currentValue}"
                required
            >
        `;

        container.appendChild(box);
    });
}

window.addEventListener("load", function () {
    if (editVenueId) {
        loadRows();
    }
});
</script>

</body>
</html>