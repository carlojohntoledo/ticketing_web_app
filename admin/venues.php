<?php
include '../db.php';
session_start();

if (!isset($_SESSION['admin'])) {
	header("Location: login.php");
	exit();
}

/*
|--------------------------------------------------------------------------
| ADD VENUE
|--------------------------------------------------------------------------
*/

if (isset($_POST['add'])) {

	$name = $_POST['venue_name'];

	mysqli_query($conn, "INSERT INTO venues (venue_name) VALUES ('$name')");
}

/*
|--------------------------------------------------------------------------
| DELETE VENUE
|--------------------------------------------------------------------------
*/

if (isset($_GET['delete'])) {

	$id = $_GET['delete'];

	mysqli_query($conn, "DELETE FROM venues WHERE id='$id'");
}

$result = mysqli_query($conn, "SELECT * FROM venues");
?>

<h2>Venues</h2>

<form method="POST">
	<input type="text" name="venue_name" placeholder="Venue Name">
	<button name="add">Add</button>
</form>

<hr>

<?php while($row = mysqli_fetch_assoc($result)) { ?>

	<p>
		<?php echo $row['venue_name']; ?>
		<a href="?delete=<?php echo $row['id']; ?>">Delete</a>
	</p>

<?php } ?>