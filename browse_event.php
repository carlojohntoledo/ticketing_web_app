<?php
include 'session_start.php';
include 'db.php'; // database connection
?>

<!DOCTYPE html>
<html>

<head>
    <title>Select Event</title>
    <link rel="stylesheet" href="general_style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable = no">


    <script>
        function selectEvent(eventName) {
            document.getElementById("selectedEvent").value = eventName;
            document.getElementById("nextBtn").disabled = false;
        }
    </script>
</head>

<body>
    <div class="main_cont">
        <h2>Select Event</h2>

        <p>
            Category:
            <b><?php echo $_SESSION['event']; ?></b>
        </p>

        <?php
        $eventType = $_SESSION['event'];

        // Get events based on selected category
        $sql = "SELECT * FROM tickets WHERE event_type='$eventType'";
        $result = mysqli_query($conn, $sql);

        if (mysqli_num_rows($result) > 0) {

            while ($row = mysqli_fetch_assoc($result)) {
        ?>

                <div style="margin-bottom:20px;">
                    <button
                        type="button"
                        onclick="selectEvent('<?php echo $row['event_name']; ?>')">

                        <?php echo $row['event_name']; ?>
                        -
                        <?php echo $row['date']; ?>
                        -
                        <?php echo $row['venue']; ?>

                    </button>
                </div>

        <?php
            }
        } else {
            echo "No events found.";
        }
        ?>

        <form method="POST">
            <input type="hidden" name="event_name" id="selectedEvent">

            <button type="submit" name="next" id="nextBtn" disabled>
                Proceed
            </button>
        </form>

        <?php
        if (isset($_POST['next'])) {

            $_SESSION['event_name'] = $_POST['event_name'];

            header("Location: seat.php");
            exit();
        }
        ?>
    </div>



</body>

</html>