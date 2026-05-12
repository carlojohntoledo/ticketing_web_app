<?php
include '../db.php';
session_start();

if (!isset($_SESSION['admin'])) {
	header("Location: login.php");
	exit();
}

/*
|--------------------------------------------------------------------------
| DELETE WHOLE VENUE
|--------------------------------------------------------------------------
*/

if (isset($_GET['delete_venue'])) {

	$venue_id = (int)$_GET['delete_venue'];

	mysqli_query(
		$conn,
		"DELETE FROM venue_seats
		 WHERE venue_id='$venue_id'"
	);

	mysqli_query(
		$conn,
		"DELETE FROM venue_rows
		 WHERE venue_id='$venue_id'"
	);

	echo "
	<script>
		alert('Venue rows and seats deleted successfully');
		window.location='seats.php';
	</script>
	";
	exit();
}

/*
|--------------------------------------------------------------------------
| DELETE SPECIFIC ROW
|--------------------------------------------------------------------------
*/

if (isset($_GET['delete_row']) && isset($_GET['venue_id'])) {

	$venue_id = (int)$_GET['venue_id'];
	$row_name = mysqli_real_escape_string(
		$conn,
		$_GET['delete_row']
	);

	/*
	|--------------------------------------------------------------------------
	| DELETE SEATS UNDER ROW
	|--------------------------------------------------------------------------
	*/

	mysqli_query(
		$conn,
		"DELETE FROM venue_seats
		 WHERE venue_id='$venue_id'
		 AND row_name='$row_name'"
	);

	/*
	|--------------------------------------------------------------------------
	| DELETE ROW
	|--------------------------------------------------------------------------
	*/

	mysqli_query(
		$conn,
		"DELETE FROM venue_rows
		 WHERE venue_id='$venue_id'
		 AND row_name='$row_name'"
	);

	echo "
	<script>
		alert('Row deleted successfully');
		window.location='seats.php';
	</script>
	";
	exit();
}

/*
|--------------------------------------------------------------------------
| CREATE ROW
|--------------------------------------------------------------------------
*/

if (isset($_POST['add_row'])) {

	$venue_id = (int)$_POST['venue_id'];
	$row_name = strtoupper(trim($_POST['row_name']));

	if ($row_name != "") {

		$check = mysqli_query(
			$conn,
			"SELECT *
			 FROM venue_rows
			 WHERE venue_id='$venue_id'
			 AND row_name='$row_name'"
		);

		if (mysqli_num_rows($check) == 0) {

			mysqli_query(
				$conn,
				"INSERT INTO venue_rows
				(venue_id, row_name)
				VALUES
				('$venue_id','$row_name')"
			);

			echo "
			<script>
				alert('Row created successfully');
				window.location='seats.php';
			</script>
			";
			exit();

		} else {

			echo "
			<script>
				alert('Row already exists for this venue');
			</script>
			";
		}
	}
}

/*
|--------------------------------------------------------------------------
| ADD SEATS
|--------------------------------------------------------------------------
*/

if (isset($_POST['add_seats'])) {

	$venue_id = (int)$_POST['venue_id'];
	$row_name = strtoupper(trim($_POST['row_name']));
	$seat_count = (int)$_POST['seat_count'];

	if ($seat_count > 0) {

		/*
		|--------------------------------------------------------------------------
		| GET LAST SEAT NUMBER
		|--------------------------------------------------------------------------
		*/

		$lastSeatQuery = mysqli_query(
			$conn,
			"SELECT MAX(seat_number) as last_number
			 FROM venue_seats
			 WHERE venue_id='$venue_id'
			 AND row_name='$row_name'"
		);

		$lastSeatData = mysqli_fetch_assoc($lastSeatQuery);

		$start = (int)$lastSeatData['last_number'] + 1;

		/*
		|--------------------------------------------------------------------------
		| INSERT NEW CONTINUOUS SEATS
		|--------------------------------------------------------------------------
		*/

		for ($i = 0; $i < $seat_count; $i++) {

			$newSeatNumber = $start + $i;

			mysqli_query(
				$conn,
				"INSERT INTO venue_seats
				(venue_id, row_name, seat_number)
				VALUES
				('$venue_id','$row_name','$newSeatNumber')"
			);
		}

		echo "
		<script>
			alert('Seats added successfully');
			window.location='seats.php';
		</script>
		";
		exit();
	}
}

/*
|--------------------------------------------------------------------------
| GET VENUES
|--------------------------------------------------------------------------
*/

$venues_result = mysqli_query(
	$conn,
	"SELECT *
	 FROM venues
	 ORDER BY venue_name"
);

$venues = [];

while ($v = mysqli_fetch_assoc($venues_result)) {
	$venues[] = $v;
}

/*
|--------------------------------------------------------------------------
| GET ROWS
|--------------------------------------------------------------------------
*/

$rows_result = mysqli_query(
	$conn,
	"SELECT *
	 FROM venue_rows
	 ORDER BY venue_id, row_name"
);

?>

<!DOCTYPE html>
<html>

<head>

	<title>Seat Management</title>

	<style>

		body{
			font-family:Arial;
			padding:20px;
		}

		.section{
			border:1px solid #ddd;
			padding:20px;
			margin-bottom:30px;
			border-radius:8px;
		}

		input,
		select{
			padding:8px;
			width:260px;
			margin:5px 0;
		}

		button{
			padding:8px 15px;
			cursor:pointer;
		}

		.badge{
			display:inline-block;
			padding:5px 10px;
			margin:3px;
			border:1px solid #ccc;
			border-radius:5px;
			background:#f5f5f5;
		}

		.venue-box{
			border:1px solid #ddd;
			padding:15px;
			margin-bottom:20px;
			border-radius:8px;
		}

		.row-title{
			margin-top:15px;
			margin-bottom:5px;
		}

		a{
			text-decoration:none;
		}

	</style>

</head>

<body>

<h2>Seat Management</h2>

<!-- ================================================= -->
<!-- CREATE ROW -->
<!-- ================================================= -->

<div class="section">

	<h3>Create Row</h3>

	<form method="POST">

		<select name="venue_id" required>

			<option value="">Select Venue</option>

			<?php foreach($venues as $v){ ?>

				<option value="<?php echo $v['id']; ?>">
					<?php echo $v['venue_name']; ?>
				</option>

			<?php } ?>

		</select>

		<br>

		<input
			type="text"
			name="row_name"
			placeholder="Row Name (VIP, GENAD, A, B)"
			required
		>

		<br>

		<button type="submit" name="add_row">
			Create Row
		</button>

	</form>

</div>

<!-- ================================================= -->
<!-- ADD SEATS -->
<!-- ================================================= -->

<div class="section">

	<h3>Add Seats</h3>

	<form method="POST">

		<!-- VENUE -->

		<select
			name="venue_id"
			id="venueSelect"
			required
			onchange="filterRows()"
		>

			<option value="">Select Venue</option>

			<?php foreach($venues as $v){ ?>

				<option value="<?php echo $v['id']; ?>">
					<?php echo $v['venue_name']; ?>
				</option>

			<?php } ?>

		</select>

		<br>

		<!-- ROW -->

		<select
			name="row_name"
			id="rowSelect"
			required
		>

			<option value="">Select Row</option>

			<?php
			while($row = mysqli_fetch_assoc($rows_result)){
			?>

				<option
					value="<?php echo $row['row_name']; ?>"
					data-venue="<?php echo $row['venue_id']; ?>"
					style="display:none;"
				>
					<?php echo $row['row_name']; ?>
				</option>

			<?php } ?>

		</select>

		<br>

		<input
			type="number"
			name="seat_count"
			placeholder="Number of Seats"
			min="1"
			required
		>

		<br>

		<button type="submit" name="add_seats">
			Add Seats
		</button>

	</form>

</div>

<!-- ================================================= -->
<!-- DISPLAY -->
<!-- ================================================= -->

<div class="section">

	<h3>Seat Overview</h3>

	<?php

	$venueDisplay = mysqli_query(
		$conn,
		"SELECT *
		 FROM venues
		 ORDER BY venue_name"
	);

	while($venue = mysqli_fetch_assoc($venueDisplay)){

		$venue_id = $venue['id'];

		echo "<div class='venue-box'>";

		echo "
		<h3>
			🏟 ".$venue['venue_name']."

			<a
				href='?delete_venue=".$venue_id."'
				style='color:red; margin-left:10px; font-size:14px;'
				onclick='return confirm(\"Delete all rows and seats of this venue?\")'
			>
				[Delete Venue]
			</a>
		</h3>
		";

		/*
		|--------------------------------------------------------------------------
		| GET VENUE ROWS
		|--------------------------------------------------------------------------
		*/

		$venueRows = mysqli_query(
			$conn,
			"SELECT *
			 FROM venue_rows
			 WHERE venue_id='$venue_id'
			 ORDER BY row_name"
		);

		while($r = mysqli_fetch_assoc($venueRows)){

			$row_name = $r['row_name'];

			echo "
			<div class='row-title'>

				<b>Row ".$row_name."</b>

				<a
					href='?delete_row=".$row_name."&venue_id=".$venue_id."'
					style='color:red; margin-left:10px; font-size:13px;'
					onclick='return confirm(\"Delete this row and all seats?\")'
				>
					[Delete Row]
				</a>

			</div>
			";

			/*
			|--------------------------------------------------------------------------
			| GET SEATS OF ROW
			|--------------------------------------------------------------------------
			*/

			$seats = mysqli_query(
				$conn,
				"SELECT *
				 FROM venue_seats
				 WHERE venue_id='$venue_id'
				 AND row_name='$row_name'
				 ORDER BY seat_number"
			);

			while($seat = mysqli_fetch_assoc($seats)){

				echo "
				<span class='badge'>
					".$seat['row_name'].$seat['seat_number']."
				</span>
				";
			}
		}

		echo "</div>";
	}

	?>

</div>

<script>

function filterRows(){

	let venueId = document.getElementById("venueSelect").value;
	let rowSelect = document.getElementById("rowSelect");
	let options = rowSelect.options;

	rowSelect.selectedIndex = 0;

	for(let i = 0; i < options.length; i++){

		let option = options[i];

		if(option.value == ""){
			option.style.display = "block";
			continue;
		}

		if(option.getAttribute("data-venue") == venueId){

			option.style.display = "block";

		}else{

			option.style.display = "none";
		}
	}
}

</script>

</body>
</html>