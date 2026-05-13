<?php
include 'session_start.php';

if (isset($_POST['browse_type'])) {
    $browseType = $_POST['browse_type'];

    if ($browseType === "event") {
        header("Location: select_event.php");
        exit();
    }

    if ($browseType === "date") {
        header("Location: browse_date.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Browse Type</title>
    <link rel="stylesheet" href="general_style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <style>
        body{font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px;}
        .main_cont{max-width:600px;margin:auto;background:#fff;padding:24px;border-radius:14px;border:1px solid #e5e7eb;}
        button{padding:12px 18px;border:none;border-radius:10px;background:#111827;color:#fff;cursor:pointer;width:100%;margin-bottom:12px;}
    </style>
</head>
<body>
    <div class="main_cont">
        <h2>Browse Tickets</h2>

        <form method="POST">
            <button type="submit" name="browse_type" value="event">Browse by Event</button>
            <button type="submit" name="browse_type" value="date">Browse by Date</button>
        </form>
    </div>
</body>
</html>