<?php include 'session_start.php'; ?>

<!DOCTYPE html>
<html>

<head>

	<link rel="stylesheet" href="general_style.css">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable = no">

	<script>
		function enableButton() {
			document.getElementById("nextBtn").disabled = false;
		}
	</script>
</head>

<body>

	<div class="main_cont">
		<h2>Select Event Type</h2>

		<form method="POST">

			<input type="radio" name="event" value="Concert" onclick="enableButton()"> Concert<br>
			<input type="radio" name="event" value="Theatre" onclick="enableButton()"> Theatre<br>
			<input type="radio" name="event" value="Comedy" onclick="enableButton()"> Comedy<br>
			<input type="radio" name="event" value="Sports" onclick="enableButton()"> Sports<br><br>

			<button type="submit" name="next" id="nextBtn" disabled>Proceed</button>

		</form>

		<?php
		if (isset($_POST['next'])) {
			$_SESSION['event'] = $_POST['event'];
			header("Location: browse_event.php");
			exit();
		}
		?>


	</div>


</body>

</html>