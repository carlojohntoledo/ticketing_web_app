<?php
session_start();
include '../db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getOrCreateVenueId(mysqli $conn, string $venueName): int
{
    $venueName = trim($venueName);
    if ($venueName === '') {
        return 0;
    }

    $stmt = $conn->prepare("SELECT id FROM venues WHERE venue_name = ? LIMIT 1");
    $stmt->bind_param("s", $venueName);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return (int)$row['id'];
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO venues (venue_name) VALUES (?)");
    $stmt->bind_param("s", $venueName);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    return (int)$newId;
}

/*
|--------------------------------------------------------------------------
| ADD / EDIT / DELETE EVENT
|--------------------------------------------------------------------------
*/
$editEvent = null;
$editGroups = [];

if (isset($_GET['edit_event'])) {
    $editId = (int)$_GET['edit_event'];

    $stmt = $conn->prepare(
        "SELECT t.*, v.venue_name
         FROM tickets t
         LEFT JOIN venues v ON v.id = t.venue_id
         WHERE t.id = ?"
    );
    $stmt->bind_param("i", $editId);
    $stmt->execute();
    $editEvent = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($editEvent) {
        $stmt = $conn->prepare("SELECT * FROM event_seat_groups WHERE event_id = ? ORDER BY id ASC");
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $groupsResult = $stmt->get_result();

        while ($group = $groupsResult->fetch_assoc()) {
            $groupId = (int)$group['id'];
            $seatStmt = $conn->prepare("SELECT seat_number, status FROM event_group_seats WHERE group_id = ? ORDER BY seat_number ASC");
            $seatStmt->bind_param("i", $groupId);
            $seatStmt->execute();
            $seatResult = $seatStmt->get_result();

            $group['unavailable_seats'] = [];
            while ($seat = $seatResult->fetch_assoc()) {
                if ($seat['status'] === 'unavailable') {
                    $group['unavailable_seats'][] = (string)$seat['seat_number'];
                }
            }

            $seatStmt->close();
            $editGroups[] = $group;
        }

        $stmt->close();
    }
}

if (isset($_POST['save_event'])) {
    $eventId      = (int)($_POST['id'] ?? 0);
    $eventType    = trim($_POST['event_type'] ?? '');
    $eventName    = trim($_POST['event_name'] ?? '');
    $venueName    = trim($_POST['venue_name'] ?? '');
    $eventDate    = trim($_POST['event_date'] ?? '');
    $ticketPrice  = (float)($_POST['ticket_price'] ?? 0);
    $serviceFee   = (float)($_POST['service_fee'] ?? 0);
    $totalPrice   = $ticketPrice + $serviceFee;

    $groupNames   = $_POST['group_name'] ?? [];
    $groupColors  = $_POST['group_color'] ?? [];
    $seatCounts   = $_POST['seat_count'] ?? [];
    $groupPrices  = $_POST['group_price'] ?? [];
    $unavailableM = $_POST['group_unavailable'] ?? [];

    $venueId = getOrCreateVenueId($conn, $venueName);

    if ($venueId <= 0) {
        die('Venue name is required.');
    }

    if ($eventId > 0) {
        $stmt = $conn->prepare(
            "UPDATE tickets SET
                event_type = ?,
                event_name = ?,
                venue_id = ?,
                event_date = ?,
                ticket_price = ?,
                service_fee = ?,
                total = ?
             WHERE id = ?"
        );
        $stmt->bind_param(
            "ssisdddi",
            $eventType,
            $eventName,
            $venueId,
            $eventDate,
            $ticketPrice,
            $serviceFee,
            $totalPrice,
            $eventId
        );
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT id FROM event_seat_groups WHERE event_id = ?");
        $stmt->bind_param("i", $eventId);
        $stmt->execute();
        $oldGroups = $stmt->get_result();
        while ($oldGroup = $oldGroups->fetch_assoc()) {
            $oldGroupId = (int)$oldGroup['id'];
            $conn->query("DELETE FROM event_group_seats WHERE group_id = '$oldGroupId'");
        }
        $stmt->close();
        $conn->query("DELETE FROM event_seat_groups WHERE event_id = '$eventId'");
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO tickets
                (event_type, event_name, venue_id, event_date, ticket_price, service_fee, total)
             VALUES
                (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "ssisddd",
            $eventType,
            $eventName,
            $venueId,
            $eventDate,
            $ticketPrice,
            $serviceFee,
            $totalPrice
        );
        $stmt->execute();
        $eventId = $stmt->insert_id;
        $stmt->close();
    }

    if ($eventId > 0 && is_array($groupNames)) {
        for ($i = 0; $i < count($groupNames); $i++) {
            $groupName = trim($groupNames[$i] ?? '');
            $groupColor = trim($groupColors[$i] ?? '#3b82f6');
            $seatCount = (int)($seatCounts[$i] ?? 0);
            $groupPrice = (float)($groupPrices[$i] ?? 0);
            $unavailableSeats = $unavailableM[$i] ?? [];

            if ($groupName === '' || $seatCount <= 0) {
                continue;
            }

            $stmt = $conn->prepare(
                "INSERT INTO event_seat_groups
                    (event_id, group_name, group_color, seat_count, group_price)
                 VALUES
                    (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("issid", $eventId, $groupName, $groupColor, $seatCount, $groupPrice);
            $stmt->execute();
            $groupId = $stmt->insert_id;
            $stmt->close();

            $unavailableLookup = [];
            if (is_array($unavailableSeats)) {
                foreach ($unavailableSeats as $seatNo) {
                    $seatNo = (int)$seatNo;
                    if ($seatNo > 0) {
                        $unavailableLookup[$seatNo] = true;
                    }
                }
            }

            for ($seat = 1; $seat <= $seatCount; $seat++) {
                $status = isset($unavailableLookup[$seat]) ? 'unavailable' : 'available';
                $seatStmt = $conn->prepare(
                    "INSERT INTO event_group_seats (group_id, seat_number, status)
                     VALUES (?, ?, ?)"
                );
                $seatStmt->bind_param("iis", $groupId, $seat, $status);
                $seatStmt->execute();
                $seatStmt->close();
            }
        }
    }

    header("Location: admin_dashboard.php#events");
    exit();
}

if (isset($_GET['delete_event'])) {
    $eventId = (int)$_GET['delete_event'];

    $stmt = $conn->prepare("SELECT id FROM event_seat_groups WHERE event_id = ?");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $groupResult = $stmt->get_result();
    while ($group = $groupResult->fetch_assoc()) {
        $groupId = (int)$group['id'];
        $conn->query("DELETE FROM event_group_seats WHERE group_id = '$groupId'");
    }
    $stmt->close();

    $conn->query("DELETE FROM event_seat_groups WHERE event_id = '$eventId'");
    $conn->query("DELETE FROM tickets WHERE id = '$eventId'");

    header("Location: admin_dashboard.php#events");
    exit();
}

/*
|--------------------------------------------------------------------------
| EVENT LIST / OVERVIEW DATA
|--------------------------------------------------------------------------
*/
$events = [];
$eventQuery = $conn->query(
    "SELECT t.*, v.venue_name
     FROM tickets t
     LEFT JOIN venues v ON v.id = t.venue_id
     ORDER BY t.id DESC"
);
if ($eventQuery) {
    while ($row = $eventQuery->fetch_assoc()) {
        $events[] = $row;
    }
}

$eventGroups = [];
$groupQuery = $conn->query(
    "SELECT g.*, t.event_name, t.event_type, t.event_date, v.venue_name
     FROM event_seat_groups g
     LEFT JOIN tickets t ON t.id = g.event_id
     LEFT JOIN venues v ON v.id = t.venue_id
     ORDER BY g.id ASC"
);
if ($groupQuery) {
    while ($group = $groupQuery->fetch_assoc()) {
        $groupId = (int)$group['id'];
        $seatQuery = $conn->query("SELECT seat_number, status FROM event_group_seats WHERE group_id = '$groupId' ORDER BY seat_number ASC");

        $group['seats'] = [];
        $group['counts'] = [
            'available' => 0,
            'unavailable' => 0,
            'booked' => 0
        ];

        if ($seatQuery) {
            while ($seat = $seatQuery->fetch_assoc()) {
                $group['seats'][] = $seat;
                if (isset($group['counts'][$seat['status']])) {
                    $group['counts'][$seat['status']]++;
                }
            }
        }

        $eventGroups[(int)$group['event_id']][] = $group;
    }
}

$editGroupsJson = [];
foreach ($editGroups as $group) {
    $editGroupsJson[] = [
        'group_name' => $group['group_name'],
        'group_color' => $group['group_color'],
        'seat_count' => (int)$group['seat_count'],
        'group_price' => $group['group_price'],
        'unavailable_seats' => $group['unavailable_seats'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f6f7fb;color:#222;padding:20px;}
        .wrap{max-width:1450px;margin:0 auto;}
        .topbar{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px;}
        .nav a{margin-right:12px;text-decoration:none;color:#2563eb;}
        .section{background:#fff;border:1px solid #ddd;border-radius:12px;padding:18px;margin-bottom:20px;}
        h2,h3,h4{margin-top:0;}
        input,select,button{padding:9px 10px;margin:6px 0;box-sizing:border-box;border:1px solid #cfcfcf;border-radius:8px;}
        input,select{width:100%;max-width:360px;}
        button{cursor:pointer;background:#111827;color:#fff;border:none;}
        button.secondary{background:#6b7280;}
        button.danger{background:#b91c1c;}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;}
        .group-card{border:1px solid #e3e3e3;border-radius:12px;padding:14px;background:#fafafa;margin-top:12px;}
        .group-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;}
        .group-row{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;align-items:start;}
        .seat-select{width:100%;min-height:130px;max-width:100%;}
        .inline{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
        .swatch{display:inline-block;width:14px;height:14px;border-radius:50%;vertical-align:middle;margin-right:6px;border:1px solid #999;}
        table{width:100%;border-collapse:collapse;margin-top:12px;}
        th,td{border:1px solid #d6d6d6;padding:10px;vertical-align:top;text-align:left;}
        .badge{display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid #ddd;margin:3px 4px 3px 0;font-size:12px;}
        .available{background:#dcfce7;border-color:#86efac;}
        .unavailable{background:#fef9c3;border-color:#fde047;}
        .booked{background:#fee2e2;border-color:#fca5a5;}
        .muted{color:#6b7280;}
        .event-box{border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#fff;margin-top:14px;}
        .actions a{margin-right:10px;text-decoration:none;color:#2563eb;}
        .actions a.delete{color:#b91c1c;}
        .small{font-size:12px;}
        .group-seats{margin-top:8px;}
        .group-seats .badge{min-width:76px;text-align:center;}
        .section label{font-weight:bold;display:inline-block;margin-top:2px;}
        @media (max-width: 980px){
            .grid,.group-row{grid-template-columns:1fr;}
            input,select{max-width:100%;}
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <h2>Admin Dashboard</h2>
        <div class="nav">
            <a href="#events">Events</a>
            <a href="#event-list">Added Events</a>
            <a href="#seat-overview">Seats Overview</a>
        </div>
    </div>

    <section id="events" class="section">
        <h3><?php echo $editEvent ? 'Edit Event' : 'Add Event'; ?></h3>

        <form method="POST" id="eventForm">
            <?php if ($editEvent) { ?>
                <input type="hidden" name="id" value="<?php echo (int)$editEvent['id']; ?>">
            <?php } ?>

            <div class="grid">
                <div>
                    <label>Event Type</label><br>
                    <select name="event_type" required>
                        <?php
                        $types = ['Concert', 'Sports', 'Theatre', 'Conference', 'Other'];
                        foreach ($types as $type) {
                            $selected = ($editEvent && $editEvent['event_type'] === $type) ? 'selected' : '';
                            echo '<option value="' . h($type) . '" ' . $selected . '>' . h($type) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <div>
                    <label>Event Name</label><br>
                    <input type="text" name="event_name" value="<?php echo h($editEvent['event_name'] ?? ''); ?>" placeholder="Event Name" required>
                </div>

                <div>
                    <label>Venue Name</label><br>
                    <input type="text" name="venue_name" value="<?php echo h($editEvent['venue_name'] ?? ''); ?>" placeholder="Type venue name here" required>
                </div>

                <div>
                    <label>Event Date</label><br>
                    <input type="date" name="event_date" value="<?php echo h($editEvent['event_date'] ?? ''); ?>" required>
                </div>

                <div>
                    <label>Event Ticket Price</label><br>
                    <input type="number" step="0.01" name="ticket_price" value="<?php echo h($editEvent['ticket_price'] ?? ''); ?>" placeholder="Ticket Price" required>
                </div>

                <div>
                    <label>Event Service Charge / Fee</label><br>
                    <input type="number" step="0.01" name="service_fee" value="<?php echo h($editEvent['service_fee'] ?? ''); ?>" placeholder="Service Fee" required>
                </div>
            </div>

            <hr>

            <div class="inline" style="justify-content:space-between;">
                <h4 style="margin:0;">Seat Groups for this Event Venue</h4>
                <button type="button" class="secondary" onclick="addGroup()">+ Add Another Group</button>
            </div>

            <div id="groupsWrap"></div>

            <hr>
            <button type="submit" name="save_event"><?php echo $editEvent ? 'Apply Changes' : 'Add Event'; ?></button>
            <?php if ($editEvent) { ?>
                <a href="admin_dashboard.php#events" style="margin-left:10px;">Cancel</a>
            <?php } ?>
        </form>
    </section>

    <section id="event-list" class="section">
        <h3>Added Events</h3>

        <table>
            <tr>
                <th>Event ID</th>
                <th>Event Type</th>
                <th>Event Name</th>
                <th>Event Date</th>
                <th>Event Venue</th>
                <th>Event Venue Seat Groups</th>
                <th>Event Venue Seat Group Color</th>
                <th>Event Seat Group Price</th>
                <th>Event Ticket Price</th>
                <th>Event Service Charge/Fee</th>
                <th>Total Event Price</th>
                <th>Action</th>
            </tr>
            <?php foreach ($events as $event) { ?>
                <tr>
                    <td><?php echo (int)$event['id']; ?></td>
                    <td><?php echo h($event['event_type']); ?></td>
                    <td><?php echo h($event['event_name']); ?></td>
                    <td><?php echo h($event['event_date']); ?></td>
                    <td><?php echo h($event['venue_name'] ?? '-'); ?></td>
                    <td>
                        <?php
                        $eid = (int)$event['id'];
                        if (!empty($eventGroups[$eid])) {
                            foreach ($eventGroups[$eid] as $group) {
                                echo h($group['group_name']) . '<br>';
                            }
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        if (!empty($eventGroups[$eid])) {
                            foreach ($eventGroups[$eid] as $group) {
                                echo '<span class="swatch" style="background:' . h($group['group_color']) . '"></span>' . h($group['group_color']) . '<br>';
                            }
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        if (!empty($eventGroups[$eid])) {
                            foreach ($eventGroups[$eid] as $group) {
                                echo '₱' . h($group['group_price']) . '<br>';
                            }
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td>₱<?php echo h($event['ticket_price']); ?></td>
                    <td>₱<?php echo h($event['service_fee']); ?></td>
                    <td>₱<?php echo h($event['total']); ?></td>
                    <td class="actions">
                        <a href="?edit_event=<?php echo $eid; ?>#events">Edit</a>
                        <a href="?delete_event=<?php echo $eid; ?>#events" class="delete" onclick="return confirm('Delete this event and all its groups?')">Delete</a>
                    </td>
                </tr>
            <?php } ?>
        </table>
    </section>

    <section id="seat-overview" class="section">
        <h3>Seats Overview of Each Added Event</h3>
        <p class="muted small">Green = available, Yellow = not available, Red = booked.</p>

        <?php foreach ($events as $event) { ?>
            <div class="event-box">
                <h4>Event #<?php echo (int)$event['id']; ?> — <?php echo h($event['event_name']); ?></h4>
                <div class="muted">Venue: <?php echo h($event['venue_name'] ?? '-'); ?> | Date: <?php echo h($event['event_date']); ?></div>

                <?php if (!empty($eventGroups[(int)$event['id']])) { ?>
                    <?php foreach ($eventGroups[(int)$event['id']] as $group) { ?>
                        <div style="margin-top:14px;">
                            <div class="inline">
                                <strong><?php echo h($group['group_name']); ?></strong>
                                <span class="swatch" style="background:<?php echo h($group['group_color']); ?>"></span>
                                <span class="muted">Color: <?php echo h($group['group_color']); ?></span>
                                <span class="muted">Price: ₱<?php echo h($group['group_price']); ?></span>
                                <span class="badge available">Available: <?php echo (int)$group['counts']['available']; ?></span>
                                <span class="badge unavailable">Not Available: <?php echo (int)$group['counts']['unavailable']; ?></span>
                                <span class="badge booked">Booked: <?php echo (int)$group['counts']['booked']; ?></span>
                            </div>

                            <div class="group-seats">
                                <?php foreach ($group['seats'] as $seat) {
                                    $statusClass = $seat['status'];
                                    $seatLabel = $group['group_name'] . $seat['seat_number'];
                                    echo '<span class="badge ' . h($statusClass) . '">' . h($seatLabel) . '</span>';
                                } ?>
                            </div>
                        </div>
                    <?php } ?>
                <?php } else { ?>
                    <p class="muted">No seat groups yet for this event.</p>
                <?php } ?>
            </div>
        <?php } ?>
    </section>
</div>

<script>
const editGroups = <?php echo json_encode($editGroupsJson); ?>;
let groupIndex = 0;

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}

function seatOptions(count, selected = []) {
    let html = '';
    for (let i = 1; i <= count; i++) {
        const sel = selected.includes(String(i)) ? 'selected' : '';
        html += `<option value="${i}" ${sel}>Seat ${i}</option>`;
    }
    return html;
}

function updateUnavailableSummary(select) {
    const card = select.closest('.group-card');
    const summary = card.querySelector('.unavailable-summary');
    const selected = Array.from(select.selectedOptions).map(opt => opt.value);

    summary.textContent = selected.length
        ? 'Selected unavailable seats: ' + selected.join(', ')
        : 'Selected unavailable seats: none';
}

function refreshGroupSeats(input) {
    const card = input.closest('.group-card');
    const count = parseInt(input.value, 10) || 0;
    const select = card.querySelector('select.seat-select');
    const previouslySelected = Array.from(select.selectedOptions).map(opt => opt.value);

    select.innerHTML = seatOptions(count, previouslySelected.filter(v => parseInt(v, 10) <= count));
    updateUnavailableSummary(select);
}

function groupTemplate(index, data = null) {
    const groupName = data?.group_name ?? '';
    const groupColor = data?.group_color ?? '#3b82f6';
    const seatCount = parseInt(data?.seat_count ?? 10, 10);
    const groupPrice = data?.group_price ?? '';
    const unavailable = data?.unavailable_seats ?? [];

    return `
        <div class="group-card" data-index="${index}">
            <div class="group-head">
                <strong>Group ${index + 1}</strong>
                <button type="button" class="danger" onclick="removeGroup(this)">Remove</button>
            </div>

            <div class="group-row">
                <div>
                    <label>Group Name (Row)</label><br>
                    <input type="text" name="group_name[]" value="${escapeHtml(groupName)}" placeholder="VIP, A, B, GENAD" required>
                </div>

                <div>
                    <label>Seat Group Color</label><br>
                    <input type="color" name="group_color[]" value="${groupColor}" required>
                </div>

                <div>
                    <label>Add seats</label><br>
                    <input type="number" min="1" name="seat_count[]" value="${seatCount}" onchange="refreshGroupSeats(this)" onkeyup="refreshGroupSeats(this)" required>
                </div>

                <div>
                    <label>Add price</label><br>
                    <input type="number" step="0.01" name="group_price[]" value="${groupPrice}" placeholder="Price for this group" required>
                </div>

                <div>
                    <label>Seat that is not available</label><br>
                    <select class="seat-select" name="group_unavailable[${index}][]" multiple onchange="updateUnavailableSummary(this)">
                        ${seatOptions(seatCount, unavailable)}
                    </select>
                    <div class="muted small unavailable-summary" style="margin-top:6px;">
                        Selected unavailable seats: ${unavailable.length ? unavailable.join(', ') : 'none'}
                    </div>
                    <div class="muted small">Hold Ctrl on Windows or Command on Mac to choose multiple seats.</div>
                </div>
            </div>
        </div>
    `;
}

function rebuildIndexes() {
    const cards = document.querySelectorAll('.group-card');
    cards.forEach((card, idx) => {
        card.dataset.index = idx;
        const strong = card.querySelector('.group-head strong');
        if (strong) strong.textContent = `Group ${idx + 1}`;

        const select = card.querySelector('select.seat-select');
        if (select) {
            select.name = `group_unavailable[${idx}][]`;
        }
    });
    groupIndex = cards.length;
}

function addGroup(data = null) {
    const wrap = document.getElementById('groupsWrap');
    wrap.insertAdjacentHTML('beforeend', groupTemplate(groupIndex, data));
    groupIndex++;
    rebuildIndexes();
}

function removeGroup(btn) {
    const card = btn.closest('.group-card');
    if (card) {
        card.remove();
        rebuildIndexes();
        if (document.querySelectorAll('.group-card').length === 0) {
            addGroup();
        }
    }
}

window.addEventListener('load', function () {
    if (editGroups.length > 0) {
        editGroups.forEach(g => addGroup(g));
    } else {
        addGroup();
    }
});
</script>
</body>
</html>

<?php
/*
|--------------------------------------------------------------------------
| REQUIRED TABLES
|--------------------------------------------------------------------------
| venues:
|   id, venue_name, created_at
|
| tickets:
|   id, event_type, event_name, venue_id, event_date, ticket_price, service_fee, total, created_at
|
| event_seat_groups:
|   id, event_id, group_name, group_color, seat_count, group_price, created_at
|
| event_group_seats:
|   id, group_id, seat_number, status, created_at
|
| Important:
| - Venue name is now a text input.
| - The system will automatically find an existing venue by name or create a new one.
| - "Booked" seats should be updated later by your booking flow.
*/
?>