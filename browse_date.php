<?php
include 'session_start.php';
include 'db.php';
?>

<!DOCTYPE html>
<html>

<head>

    <title>Select Date</title>
    <link rel="stylesheet" href="general_style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable = no">


    <script>
        function selectEvent(eventName, eventDate) {

            document.getElementById("selectedEvent").value = eventName;
            document.getElementById("selectedDate").value = eventDate;

            document.getElementById("nextBtn").disabled = false;
        }

        function checkDate() {

            let selectedDate = document.getElementById("datePicker").value;

            window.location.href = "browse_date.php?date=" + selectedDate;
        }
    </script>

</head>

<body>

    <div class="main_cont">

        <h2>Select Date</h2>

        <p>Choose a date from the calendar.</p>

        <input
            type="date"
            id="datePicker"
            onchange="checkDate()"
            value="<?php echo isset($_GET['date']) ? $_GET['date'] : ''; ?>">

        <br><br>

        <?php

        if (isset($_GET['date'])) {

            $selectedDate = $_GET['date'];

            $sql = "SELECT * FROM tickets WHERE date='$selectedDate'";
            $result = mysqli_query($conn, $sql);

            if (mysqli_num_rows($result) > 0) {

                echo "<h3>Available Events</h3>";

                while ($row = mysqli_fetch_assoc($result)) {

        ?>

                    <button
                        type="button"
                        onclick="selectEvent(
                    '<?php echo $row['event_name']; ?>',
                    '<?php echo $row['date']; ?>'
                )">

                        <?php echo $row['event_name']; ?>
                        -
                        <?php echo $row['venue']; ?>
                        -
                        ₱<?php echo $row['total']; ?>

                    </button>

                    <br><br>

        <?php
                }
            } else {

                echo "<h3>No events available on this date.</h3>";
            }
        }

        ?>

        <form method="POST">

            <input
                type="hidden"
                name="event_name"
                id="selectedEvent">

            <input
                type="hidden"
                name="date"
                id="selectedDate">

            <button
                type="submit"
                name="next"
                id="nextBtn"
                disabled>
                Proceed
            </button>

        </form>

        <?php

        if (isset($_POST['next'])) {

            $_SESSION['event_name'] = $_POST['event_name'];
            $_SESSION['date'] = $_POST['date'];

            header("Location: seat.php");
            exit();
        }

        ?>
    </div>



</body>

</html>