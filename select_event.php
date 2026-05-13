<?php
include 'session_start.php';
include 'db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
|--------------------------------------------------------------------------
| GET AVAILABLE EVENT TYPES FROM DATABASE
|--------------------------------------------------------------------------
*/

$eventTypes = [];

$typeQuery = mysqli_query(
    $conn,
    "SELECT DISTINCT event_type
     FROM tickets
     ORDER BY event_type ASC"
);

if ($typeQuery) {
    while ($row = mysqli_fetch_assoc($typeQuery)) {
        $eventTypes[] = $row['event_type'];
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE SUBMIT
|--------------------------------------------------------------------------
*/

if (isset($_POST['next'])) {

    $eventType = trim($_POST['event_type'] ?? '');

    if ($eventType == '') {
        $error = "Please select an event type.";
    } else {

        $_SESSION['selected_event_type'] = $eventType;

        header("Location: browse_event.php");
        exit();
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <title>Select Event Type</title>

    <link rel="stylesheet" href="general_style.css">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, user-scalable=no"
    >

    <style>

        body{
            font-family:Arial,sans-serif;
            background:#f5f5f5;
            margin:0;
            padding:20px;
        }

        .main_cont{
            max-width:650px;
            margin:auto;
            background:#fff;
            padding:25px;
            border-radius:14px;
            border:1px solid #e5e7eb;
        }

        h2{
            margin-top:0;
            margin-bottom:20px;
        }

        .type-card{
            border:1px solid #d1d5db;
            border-radius:12px;
            padding:16px;
            margin-bottom:12px;
            cursor:pointer;
            transition:.2s;
        }

        .type-card:hover{
            border-color:#111827;
            background:#fafafa;
        }

        .type-card input{
            margin-right:10px;
        }

        .error-box{
            background:#fff2f2;
            color:#b91c1c;
            border:1px solid #fecaca;
            padding:12px;
            border-radius:10px;
            margin-bottom:15px;
        }

        button{
            padding:12px 18px;
            border:none;
            border-radius:10px;
            background:#111827;
            color:#fff;
            cursor:pointer;
            width:100%;
            margin-top:10px;
        }

        button:disabled{
            opacity:.5;
            cursor:not-allowed;
        }

        .empty{
            padding:15px;
            border:1px dashed #ccc;
            border-radius:10px;
            color:#666;
        }

    </style>

    <script>

        function enableButton() {
            document.getElementById("nextBtn").disabled = false;
        }

    </script>

</head>

<body>

<div class="main_cont">

    <h2>Select Event Type</h2>

    <?php if (!empty($error)) { ?>
        <div class="error-box">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php } ?>

    <?php if (!empty($eventTypes)) { ?>

        <form method="POST">

            <?php foreach ($eventTypes as $type) { ?>

                <label class="type-card">

                    <input
                        type="radio"
                        name="event_type"
                        value="<?php echo htmlspecialchars($type); ?>"
                        onclick="enableButton()"
                    >

                    <?php echo htmlspecialchars($type); ?>

                </label>

            <?php } ?>

            <button
                type="submit"
                name="next"
                id="nextBtn"
                disabled
            >
                Proceed
            </button>

        </form>

    <?php } else { ?>

        <div class="empty">
            No available event types found.
        </div>

    <?php } ?>

</div>

</body>
</html>