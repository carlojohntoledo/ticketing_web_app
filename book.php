<?php
include 'db.php';

$name = $_POST['name'];
$event = $_POST['event'];

// Generate ticket number
$ticket_number = "TICK-" . rand(10000, 99999);

// Insert into database
$sql = "INSERT INTO tickets (name, event, ticket_number)
VALUES ('$name', '$event', '$ticket_number')";

if ($conn->query($sql) === TRUE) {
	header("Location: success.php?ticket=$ticket_number");
} else {
	echo "Error: " . $conn->error;
}

$conn->close();
?>