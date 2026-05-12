<?php

$servername = "localhost";
$username = "root";
$password = "";
$database = "ticketing_system";

$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
	die("Connection failed: " . $conn->connect_error);
}

?>  