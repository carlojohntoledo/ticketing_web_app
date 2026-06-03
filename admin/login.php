<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$adminUser = "admin";
$adminPass = "1234";

if (isset($_POST['login'])) {

	$user = $_POST['username'];
	$pass = $_POST['password'];

	if ($user === $adminUser && $pass === $adminPass) {
		$_SESSION['admin'] = true;
		header("Location: admin_dashboard.php");
		exit();
	} else {
		$error = "Invalid credentials";
	}
}
?>

<!DOCTYPE html>
<html>
<body>

<h2>Admin Login</h2>

<?php if(isset($error)) echo "<p style='color:red;'>$error</p>"; ?>

<form method="POST">
	<input type="text" name="username" placeholder="Username"><br><br>
	<input type="password" name="password" placeholder="Password"><br><br>
	<button type="submit" name="login">Login</button>
</form>

</body>
</html>