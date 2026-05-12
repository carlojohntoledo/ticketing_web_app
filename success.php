<!DOCTYPE html>
<html>
<body>

<h2>Booking Successful!</h2>

<?php
if (isset($_GET['ticket'])) {
	echo "Your Ticket Number: <b>" . $_GET['ticket'] . "</b>";
}
?>

</body>
</html>