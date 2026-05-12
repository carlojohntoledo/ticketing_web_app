<?php include 'session_start.php'; ?>

<!DOCTYPE html>
<html lang="eng">

<head>
	<link rel="stylesheet" href="general_style.css">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable = no">
</head>

<body>
	<div class="main_cont">
		<h1>Welcome to Ticketing System</h1>

		<p>Available Events:</p>
		<ul>
			<li>Concert</li>
			<li>Theatre</li>
			<li>Comedy</li>
			<li>Sports</li>
		</ul>

		<form action="browse.php">
			<button type="submit">Proceed</button>
		</form>

	</div>



</body>

</html>