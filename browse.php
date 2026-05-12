<?php include 'session_start.php'; ?>

<!DOCTYPE html>
<html lang="eng">

<head>
    <title>Browse Type</title>
    <link rel="stylesheet" href="general_style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable = no">

</head>

<body>

    <div class="main_cont">
        <h2>Browse Tickets</h2>

        <form method="POST">

            <button type="submit" name="browse_type" value="event">
                Browse by Event
            </button>

            <br><br>

            <button type="submit" name="browse_type" value="date">
                Browse by Date
            </button>

        </form>

        <?php

        if (isset($_POST['browse_type'])) {

            $browseType = $_POST['browse_type'];

            if ($browseType == "event") {
                header("Location: select_event.php");
                exit();
            }

            if ($browseType == "date") {
                header("Location: browse_date.php");
                exit();
            }
        }

        ?>

    </div>



</body>

</html>