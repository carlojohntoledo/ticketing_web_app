<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'session_start.php';
include 'db.php';

/*
|--------------------------------------------------------------------------
| RESET BOOKING FLOW
|--------------------------------------------------------------------------
*/

if (isset($_POST['reset'])) {
	session_destroy();
	header("Location: index.php");
	exit();
}

/*
|--------------------------------------------------------------------------
| VALIDATE SESSION
|--------------------------------------------------------------------------
*/

if (
	!isset($_SESSION['event_id']) ||
	!isset($_SESSION['event_name']) ||
	!isset($_SESSION['selected_seat']) ||
	!isset($_SESSION['selected_row']) ||
	!isset($_SESSION['payment_method']) ||
	!isset($_SESSION['ticket_number'])
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
$eventName = $_SESSION['event_name'];
$seat = $_SESSION['selected_seat'];
$rowName = $_SESSION['selected_row'];
$payment = $_SESSION['payment_method'];
$ticketNumber = $_SESSION['ticket_number'];
$rowPrice = isset($_SESSION['selected_row_price']) ? (float)$_SESSION['selected_row_price'] : 0.00;
$total = isset($_SESSION['total']) ? (float)$_SESSION['total'] : 0.00;
$serviceFee = isset($_SESSION['service_fee']) ? (float)$_SESSION['service_fee'] : 0.00;

/*
|--------------------------------------------------------------------------
| GET EVENT DETAILS
|--------------------------------------------------------------------------
*/

$eventSql = "
	SELECT *
	FROM tickets
	WHERE id='$eventId'
";
$eventResult = mysqli_query($conn, $eventSql);

if (!$eventResult) {
	die("Event Query Error: " . mysqli_error($conn));
}

if (mysqli_num_rows($eventResult) == 0) {
	die("Event not found.");
}

$event = mysqli_fetch_assoc($eventResult);

$date = $event['date'];
$eventType = $event['event_type'];
$basePrice = (float)$event['ticket_price'];
$venueId = (int)$event['venue_id'];

/*
|--------------------------------------------------------------------------
| GET VENUE NAME
|--------------------------------------------------------------------------
*/

$venueQuery = mysqli_query(
	$conn,
	"SELECT venue_name FROM venues WHERE id='$venueId'"
);

if (!$venueQuery || mysqli_num_rows($venueQuery) == 0) {
	die("Venue not found.");
}

$venueData = mysqli_fetch_assoc($venueQuery);
$venue = $venueData['venue_name'];

/*
|--------------------------------------------------------------------------
| FINAL TOTAL FALLBACK
|--------------------------------------------------------------------------
*/

if ($total <= 0) {
	$total = $basePrice + $serviceFee + $rowPrice;
}

?>

<!DOCTYPE html>
<html>
<head>
	<title>Receipt</title>
	<link rel="stylesheet" href="general_style.css">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">

	<style>
		.main_cont{
			padding:20px;
		}

		.receipt-box {
			width: 450px;
			max-width: 100%;
			background: white;
			padding: 25px;
			border-radius: 10px;
			box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
		}

		h2 {
			margin-top: 0;
		}

		button {
			padding: 10px 20px;
			cursor: pointer;
			margin-top: 20px;
		}

		.line{
			margin-bottom: 8px;
			line-height: 1.6;
		}
	</style>
</head>

<body>

<div class="main_cont">
	<div class="receipt-box">

		<h2>Booking Receipt</h2>

		<div class="line">
			<b>Ticket Number:</b> <?php echo htmlspecialchars($ticketNumber); ?>
		</div>

		<hr>

		<div class="line">
			<b>Event Type:</b> <?php echo htmlspecialchars($eventType); ?>
		</div>

		<div class="line">
			<b>Event Name:</b> <?php echo htmlspecialchars($eventName); ?>
		</div>

		<div class="line">
			<b>Venue:</b> <?php echo htmlspecialchars($venue); ?>
		</div>

		<div class="line">
			<b>Date:</b> <?php echo htmlspecialchars($date); ?>
		</div>

		<div class="line">
			<b>Selected Row:</b> <?php echo htmlspecialchars($rowName); ?>
		</div>

		<div class="line">
			<b>Selected Seat:</b> <?php echo htmlspecialchars($seat); ?>
		</div>

		<div class="line">
			<b>Payment Method:</b> <?php echo htmlspecialchars($payment); ?>
		</div>

		<hr>

		<div class="line">
			<b>Base Ticket Price:</b> ₱<?php echo number_format($basePrice, 2); ?>
		</div>

		<div class="line">
			<b>Row Price:</b> ₱<?php echo number_format($rowPrice, 2); ?>
		</div>

		<div class="line">
			<b>Service Fee:</b> ₱<?php echo number_format($serviceFee, 2); ?>
		</div>

		<div class="line">
			<b>Total Paid:</b> ₱<?php echo number_format($total, 2); ?>
		</div>

		<form method="POST">
			<button type="submit" name="reset">Book Again</button>
		</form>

	</div>
</div>

</body>
</html>