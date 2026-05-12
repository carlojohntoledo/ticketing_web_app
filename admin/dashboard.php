<?php
session_start();

if (!isset($_SESSION['admin'])) {
	header("Location: login.php");
	exit();
}
?>

<!DOCTYPE html>
<html>
<body>

<h2>Admin Dashboard</h2>

<ul>
	<li><a href="venues.php">Manage Venues</a></li>
	<li><a href="events.php">Manage Events</a></li>
	<li><a href="seats.php">Manage Seats</a></li>
</ul>

</body>
</html>