<?php
include 'session_start.php';
include 'db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
|--------------------------------------------------------------------------
| LANDING PAGE ONLY
|--------------------------------------------------------------------------
| This page only displays available events.
| Users will go to browse.php to choose how they want to browse.
|--------------------------------------------------------------------------
*/
$events = [];
$eventQuery = $conn->query(
    "SELECT t.id, t.event_type, t.event_name, t.event_date, t.ticket_price, t.service_fee, v.venue_name
     FROM tickets t
     LEFT JOIN venues v ON v.id = t.venue_id
     ORDER BY t.event_date ASC, t.id DESC"
);

if ($eventQuery) {
    while ($row = $eventQuery->fetch_assoc()) {
        $events[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Ticketing System</title>
    <link rel="stylesheet" href="general_style.css">
    <style>
        body{font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px;}
        .main_cont{max-width:1100px;margin:auto;}
        .hero{background:#fff;padding:24px;border-radius:14px;border:1px solid #e5e7eb;margin-bottom:20px;}
        .hero h1{margin-top:0;}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
        .card h3{margin-top:0;margin-bottom:10px;}
        .meta{line-height:1.7;color:#374151;}
        .actions{margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
        a.btn, button{padding:10px 16px;border:none;border-radius:10px;text-decoration:none;cursor:pointer;display:inline-block;}
        button{background:#111827;color:#fff;}
        a.btn{background:#e5e7eb;color:#111827;}
        .muted{color:#6b7280;}
        .empty{background:#fff;border:1px dashed #d1d5db;border-radius:14px;padding:18px;color:#6b7280;}
        @media (max-width: 760px){.grid{grid-template-columns:1fr;}}
    </style>
</head>
<body>
<div class="main_cont">
    <div class="hero">
        <h1>Welcome to Ticketing System</h1>
        <p class="muted">Browse available events, then go to the browse page to choose how you want to search.</p>

        <div class="actions">
            <a class="btn" href="browse.php">Go to Browse Page</a>
        </div>
    </div>

    <div class="grid">
        <?php if (!empty($events)) { ?>
            <?php foreach ($events as $event) { ?>
                <div class="card">
                    <h3><?php echo htmlspecialchars($event['event_name']); ?></h3>
                    <div class="meta">
                        <div><b>Type:</b> <?php echo htmlspecialchars($event['event_type']); ?></div>
                        <div><b>Venue:</b> <?php echo htmlspecialchars($event['venue_name'] ?? '-'); ?></div>
                        <div><b>Date:</b> <?php echo htmlspecialchars($event['event_date']); ?></div>
                        <div><b>Base Ticket:</b> ₱<?php echo number_format((float)$event['ticket_price'], 2); ?></div>
                        <div><b>Service Fee:</b> ₱<?php echo number_format((float)$event['service_fee'], 2); ?></div>
                    </div>
                </div>
            <?php } ?>
        <?php } else { ?>
            <div class="empty">No events available yet.</div>
        <?php } ?>
    </div>
</div>
</body>
</html>