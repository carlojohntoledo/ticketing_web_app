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

if (!isset($_SESSION['event_name']) || !isset($_SESSION['selected_seat']) || !isset($_SESSION['selected_row'])) {
	header("Location: index.php");
	exit();
}

$eventName = $_SESSION['event_name'];
$selectedSeat = $_SESSION['selected_seat'];
$selectedRow = $_SESSION['selected_row'];

/*
|--------------------------------------------------------------------------
| GET EVENT DATA
|--------------------------------------------------------------------------
*/

$sql = "
	SELECT *
	FROM tickets
	WHERE event_name='$eventName'
";
$result = mysqli_query($conn, $sql);

if (!$result || mysqli_num_rows($result) == 0) {
	die("Event not found in database.");
}

$event = mysqli_fetch_assoc($result);

$eventId = (int)$event['id'];
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
$venueName = $venueData['venue_name'];

/*
|--------------------------------------------------------------------------
| GET ROW PRICE
|--------------------------------------------------------------------------
| Prefer session value from seat.php, fallback to event_row_prices table.
|--------------------------------------------------------------------------
*/

$rowPrice = isset($_SESSION['selected_row_price']) ? (float)$_SESSION['selected_row_price'] : null;

if ($rowPrice === null) {
	$rowPriceQuery = mysqli_query(
		$conn,
		"SELECT price
		 FROM event_row_prices
		 WHERE event_id='$eventId'
		 AND venue_id='$venueId'
		 AND row_name='$selectedRow'
		 LIMIT 1"
	);

	if ($rowPriceQuery && mysqli_num_rows($rowPriceQuery) > 0) {
		$rowPriceData = mysqli_fetch_assoc($rowPriceQuery);
		$rowPrice = (float)$rowPriceData['price'];
	} else {
		$rowPrice = 0.00;
	}
}

/*
|--------------------------------------------------------------------------
| GET PRICE VALUES
|--------------------------------------------------------------------------
*/

$basePrice = (float)$event['ticket_price'];
$serviceFee = (float)$event['service_fee'];

if (isset($_SESSION['selected_total'])) {
	$total = (float)$_SESSION['selected_total'];
} else {
	$total = $basePrice + $serviceFee + $rowPrice;
}

/*
|--------------------------------------------------------------------------
| SAVE EVENT ID AND TOTALS
|--------------------------------------------------------------------------
*/

$_SESSION['event_id'] = $eventId;
$_SESSION['service_fee'] = $serviceFee;
$_SESSION['selected_row_price'] = $rowPrice;
$_SESSION['selected_total'] = $total;

/*
|--------------------------------------------------------------------------
| HANDLE PAYMENT SUBMIT FIRST
|--------------------------------------------------------------------------
*/

if (isset($_POST['next'])) {

	$payment = $_POST['payment'] ?? '';

	if ($payment === '') {
		echo "<script>alert('Please select a payment method.');</script>";
	} else {
		$_SESSION['payment_method'] = $payment;

		header("Location: confirm.php");
		exit();
	}
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
		}

		.main_cont{
			padding: 20px;
		}

		.info-box{
			margin-bottom: 25px;
			padding: 15px;
			border: 1px solid #ddd;
			border-radius: 8px;
			background: #fafafa;
			line-height: 1.8;
		}

		.payment {
			display: inline-block;
			padding: 15px;
			margin: 10px;
			border: 2px solid gray;
			cursor: pointer;
			border-radius: 8px;
			min-width: 100px;
			text-align: center;
			user-select: none;
		}

		.payment.selected {
			background-color: green;
			color: white;
			border-color: green;
		}

		.summary-line{
			margin-top: 4px;
		}

		button{
			padding: 10px 20px;
			cursor: pointer;
			margin-top: 10px;
		}
	</style>

	<script>
		function selectPayment(method) {

			document.getElementById("paymentInput").value = method;

			let options = document.getElementsByClassName("payment");

			for (let opt of options) {
				opt.classList.remove("selected");
			}

			document.getElementById(method).classList.add("selected");

			document.getElementById("nextBtn").disabled = false;
		}
	</script>
</head>

<body>
	<div class="main_cont">
		<h2>Select Payment Method</h2>

		<div class="info-box">
			<div class="summary-line">
				<b>Event Type:</b> <?php echo htmlspecialchars($event['event_type']); ?>
			</div>

			<div class="summary-line">
				<b>Event:</b> <?php echo htmlspecialchars($event['event_name']); ?>
			</div>

			<div class="summary-line">
				<b>Venue:</b> <?php echo htmlspecialchars($venueName); ?>
			</div>

			<div class="summary-line">
				<b>Date:</b> <?php echo htmlspecialchars($event['date']); ?>
			</div>

			<div class="summary-line">
				<b>Selected Row:</b> <?php echo htmlspecialchars($selectedRow); ?>
			</div>

			<div class="summary-line">
				<b>Selected Seat:</b> <?php echo htmlspecialchars($selectedSeat); ?>
			</div>

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
				<b>Total:</b> ₱<?php echo number_format($total, 2); ?>
			</div>
		</div>

		<!-- PAYMENT OPTIONS -->
		<div>
			<div id="GCASH" class="payment" onclick="selectPayment('GCASH')">GCash</div>
			<div id="MAYA" class="payment" onclick="selectPayment('MAYA')">Maya</div>
			<div id="PAYPAL" class="payment" onclick="selectPayment('PAYPAL')">PayPal</div>
			<div id="BANK" class="payment" onclick="selectPayment('BANK')">Bank</div>
		</div>

		<form method="POST">
			<input type="hidden" name="payment" id="paymentInput">
			<br>
			<button type="submit" name="next" id="nextBtn" disabled>
				Proceed
			</button>
		</form>
	</div>
</body>
</html>