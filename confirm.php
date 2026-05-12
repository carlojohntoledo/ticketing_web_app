<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'session_start.php';
include 'db.php';

/*
|--------------------------------------------------------------------------
| VALIDATE FLOW
|--------------------------------------------------------------------------
*/

if (
	!isset($_SESSION['event_id']) ||
	!isset($_SESSION['event_name']) ||
	!isset($_SESSION['selected_seat']) ||
	!isset($_SESSION['selected_row']) ||
	!isset($_SESSION['payment_method'])
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
$rowPrice = isset($_SESSION['selected_row_price']) ? (float)$_SESSION['selected_row_price'] : 0.00;
$selectedTotal = isset($_SESSION['selected_total']) ? (float)$_SESSION['selected_total'] : 0.00;

/*
|--------------------------------------------------------------------------
| GET EVENT DETAILS
|--------------------------------------------------------------------------
*/

$sql = "SELECT * FROM tickets WHERE id='$eventId'";
$result = mysqli_query($conn, $sql);

if (!$result) {
	die("SQL Error: " . mysqli_error($conn));
}

if (mysqli_num_rows($result) == 0) {
	die("Event not found.");
}

$event = mysqli_fetch_assoc($result);

/*
|--------------------------------------------------------------------------
| EVENT DATA
|--------------------------------------------------------------------------
*/

$eventType = $event['event_type'];
$date = $event['date'];
$basePrice = (float)$event['ticket_price'];
$serviceFee = (float)$event['service_fee'];
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
| TOTAL
|--------------------------------------------------------------------------
*/

if ($selectedTotal <= 0) {
	$selectedTotal = $basePrice + $serviceFee + $rowPrice;
}

$_SESSION['service_fee'] = $serviceFee;
$_SESSION['total'] = $selectedTotal;

/*
|--------------------------------------------------------------------------
| HANDLE CONFIRM BOOKING
|--------------------------------------------------------------------------
*/

if (isset($_POST['confirm'])) {

	/*
	|--------------------------------------------------------------------------
	| CHECK IF SEAT ALREADY BOOKED
	|--------------------------------------------------------------------------
	*/

	$checkSeat = "
		SELECT *
		FROM bookings
		WHERE event_id='$eventId'
		AND seat_code='$seat'
		AND booking_status IN ('reserved', 'paid')
		LIMIT 1
	";

	$seatResult = mysqli_query($conn, $checkSeat);

	if (!$seatResult) {
		die("Seat Check Error: " . mysqli_error($conn));
	}

	if (mysqli_num_rows($seatResult) > 0) {
		die("Seat already booked.");
	}

	/*
	|--------------------------------------------------------------------------
	| GENERATE TICKET NUMBER
	|--------------------------------------------------------------------------
	*/

	$ticketNumber = "TICK-" . rand(10000, 99999);

	/*
	|--------------------------------------------------------------------------
	| INSERT BOOKING
	|--------------------------------------------------------------------------
	*/

	$insert = "
		INSERT INTO bookings
		(
			event_id,
			seat_code,
			payment_method,
			base_price,
			service_fee,
			total,
			ticket_number,
			booking_status
		)
		VALUES
		(
			'$eventId',
			'$seat',
			'$payment',
			'$basePrice',
			'$serviceFee',
			'$selectedTotal',
			'$ticketNumber',
			'paid'
		)
	";

	if (!mysqli_query($conn, $insert)) {
		die("Insert Error: " . mysqli_error($conn));
	}

	/*
	|--------------------------------------------------------------------------
	| SAVE TICKET NUMBER TO SESSION
	|--------------------------------------------------------------------------
	*/

	$_SESSION['ticket_number'] = $ticketNumber;

	header("Location: receipt.php");
	exit();
}

?>

<!DOCTYPE html>
<html>
<head>
	<title>Confirm Booking</title>
	<link rel="stylesheet" href="general_style.css">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">

	<style>
		.main_cont{
			padding:20px;
		}

		.summary-box {
			width: 420px;
			max-width: 100%;
			border: 1px solid #ccc;
			padding: 20px;
			border-radius: 10px;
			background: #fff;
		}

		button {
			padding: 10px 20px;
			cursor: pointer;
			margin-top: 15px;
		}

		.summary-line{
			margin-bottom: 8px;
			line-height: 1.6;
		}
	</style>
</head>

<body>

<div class="main_cont">
	<div class="summary-box">

		<h2>Confirm Your Booking</h2>

		<div class="summary-line">
			<b>Event Type:</b> <?php echo htmlspecialchars($eventType); ?>
		</div>

		<div class="summary-line">
			<b>Event Name:</b> <?php echo htmlspecialchars($eventName); ?>
		</div>

		<div class="summary-line">
			<b>Venue:</b> <?php echo htmlspecialchars($venue); ?>
		</div>

		<div class="summary-line">
			<b>Date:</b> <?php echo htmlspecialchars($date); ?>
		</div>

		<div class="summary-line">
			<b>Selected Row:</b> <?php echo htmlspecialchars($rowName); ?>
		</div>

		<div class="summary-line">
			<b>Selected Seat:</b> <?php echo htmlspecialchars($seat); ?>
		</div>

		<div class="summary-line">
			<b>Payment Method:</b> <?php echo htmlspecialchars($payment); ?>
		</div>

		<hr>

		<div class="summary-line">
			<b>Base Ticket Price:</b> ₱<?php echo number_format($basePrice, 2); ?>
		</div>

		<div class="summary-line">
			<b>Row Price:</b> ₱<?php echo number_format($rowPrice, 2); ?>
		</div>

		<div class="summary-line">
			<b>Service Fee:</b> ₱<?php echo number_format($serviceFee, 2); ?>
		</div>

		<div class="summary-line">
			<b>Total:</b> ₱<?php echo number_format($selectedTotal, 2); ?>
		</div>

		<form method="POST">
			<button type="submit" name="confirm">Confirm Booking</button>
		</form>

	</div>
</div>

</body>
</html>