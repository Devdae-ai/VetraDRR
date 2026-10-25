<?php
require_once "config.php";
require_once "helpers.php";
require_login();

$user = current_user();
$isAdmin = ($user['role'] ?? '') === 'admin';
$isCivilian = ($user['role'] ?? '') === 'civilian';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isCivilian && isset($_POST['sos_submit'])) {
    $latitude = trim((string)($_POST['latitude'] ?? ''));
    $longitude = trim((string)($_POST['longitude'] ?? ''));
    $dangerLevel = trim((string)($_POST['danger_level'] ?? 'Moderate'));
    $reportText = trim((string)($_POST['report_text'] ?? ''));
    $photoPath = '';

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $safeExt = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'jpg';
        $filename = 'sos_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $safeExt;
        $target = $uploadDir . '/' . $filename;

        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
            $photoPath = 'uploads/' . $filename;
        }
    }

    $sosSaved = false;
    if ($conn && $conn->ping()) {
        $stmt = $conn->prepare("INSERT INTO civilian_reports (user_id, user_name, user_email, latitude, longitude, danger_level, report_text, photo_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'New')");
        if ($stmt) {
            $preparedUserId = $user['id'] ?? null;
            $userName = (string)($user['name'] ?? '');
            $userEmail = (string)($user['email'] ?? '');
            $sosSaved = $stmt->bind_param('issddsss', $preparedUserId, $userName, $userEmail, $latitude, $longitude, $dangerLevel, $reportText, $photoPath)
                && $stmt->execute();
            $stmt->close();
        }
    }

    $_SESSION['flash'] = $sosSaved
        ? 'SOS alert sent. Emergency services have your location and report.'
        : 'Unable to send your SOS alert right now. Please try again.';
    header('Location: index.php');
    exit;
}

$householdFallback = [
    ['family_code'=>'HH-1001','head_name'=>'Maria Santos','purok'=>'Purok 1','latitude'=>10.3157,'longitude'=>123.8854,'risk_level'=>'Danger','members'=>5],
    ['family_code'=>'HH-1002','head_name'=>'Juan Dela Cruz','purok'=>'Purok 3','latitude'=>10.3212,'longitude'=>123.8971,'risk_level'=>'Caution','members'=>4],
    ['family_code'=>'HH-1003','head_name'=>'Ana Reyes','purok'=>'Purok 5','latitude'=>10.3075,'longitude'=>123.8722,'risk_level'=>'Safe','members'=>3],
];
$centerFallback = [
    ['name'=>'Barangay Hall Gym','barangay'=>'Barangay Central','latitude'=>10.3180,'longitude'=>123.8900,'capacity'=>200,'current_occupancy'=>188,'status'=>'Warning'],
    ['name'=>'East Central School','barangay'=>'Barangay East','latitude'=>10.3260,'longitude'=>123.9020,'capacity'=>350,'current_occupancy'=>126,'status'=>'Open'],
    ['name'=>'West Community Center','barangay'=>'Barangay West','latitude'=>10.3040,'longitude'=>123.8750,'capacity'=>150,'current_occupancy'=>150,'status'=>'Full'],
];
$inventoryFallback = [
    ['item_name'=>'Family Relief Pack','category'=>'Food','quantity_received'=>500,'quantity_distributed'=>312,'unit'=>'packs'],
    ['item_name'=>'Drinking Water','category'=>'Water','quantity_received'=>1000,'quantity_distributed'=>645,'unit'=>'bottles'],
    ['item_name'=>'Hygiene Kit','category'=>'Hygiene','quantity_received'=>300,'quantity_distributed'=>97,'unit'=>'kits'],
];

$households = db_rows($conn, "SELECT * FROM households ORDER BY FIELD(risk_level,'Danger','Caution','Safe')", $householdFallback);
$centers = db_rows($conn, "SELECT * FROM evacuation_centers ORDER BY current_occupancy/capacity DESC", $centerFallback);
$inventory = db_rows($conn, "SELECT * FROM relief_inventory ORDER BY quantity_received-quantity_distributed ASC", $inventoryFallback);

$recentReports = [];
if ($conn && $conn->ping()) {
    $recentReports = db_rows(
        $conn,
        "SELECT * FROM civilian_reports WHERE user_email = ? OR user_name = ? ORDER BY created_at DESC LIMIT 5",
        [],
        [
            $user['email'] ?? '',
            $user['name'] ?? '',
        ]
    );
}

$civilianMapLocation = null;
if ($isCivilian) {
    foreach ($recentReports as $report) {
        if (!is_numeric($report['latitude'] ?? null) || !is_numeric($report['longitude'] ?? null)) {
            continue;
        }

        $latitude = (float)$report['latitude'];
        $longitude = (float)$report['longitude'];
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            continue;
        }

        $civilianMapLocation = [
            'latitude' => number_format($latitude, 5, '.', ''),
            'longitude' => number_format($longitude, 5, '.', ''),
        ];
        break;
    }
}

$zoomEarthUrl = 'https://zoom.earth/places/philippines/';
if ($civilianMapLocation) {
    $zoomEarthUrl .= '#view=' . $civilianMapLocation['latitude'] . ',' . $civilianMapLocation['longitude'] . ',10z';
}
$mapPreviewLocation = $civilianMapLocation ?? [
    'latitude' => '12.8797',
    'longitude' => '121.7740',
];

$totalHouseholds = count($households);
$dangerHouseholds = count(array_filter($households, fn($h) => ($h['risk_level'] ?? '') === 'Danger'));
$totalCapacity = array_sum(array_map(fn($c) => (int)$c['capacity'], $centers));
$totalOccupancy = array_sum(array_map(fn($c) => (int)$c['current_occupancy'], $centers));
$capacityPct = $totalCapacity ? round(($totalOccupancy/$totalCapacity)*100) : 0;
$reliefReceived = array_sum(array_map(fn($i) => (int)$i['quantity_received'], $inventory));
$reliefDistributed = array_sum(array_map(fn($i) => (int)$i['quantity_distributed'], $inventory));
$flash = flash_message('flash');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>VetraDRR — Integrated Disaster Response</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="app.css?v=20261026">
<script>
tailwind.config = {
  theme: {
    extend: {
      colors: { ink:'#090909', panel:'#111111', line:'#242424', ember:'#ff6b2c', ember2:'#ff8a52' }
    }
  }
}
</script>
</head>
<body class="bg-ink text-white min-h-screen" data-user-role="<?= e($user['role'] ?? 'civilian') ?>">
<div class="ambient"></div>
<div class="max-w-[1500px] mx-auto px-5 lg:px-8 py-5 relative z-10">
<header class="nav glass">
  <a class="brand" href="index.php"><span class="brandmark">✦</span><span>Vetra<span class="orange">DRR</span></span></a>
  <nav class="hidden md:flex gap-7 text-sm text-gray-400">
    <a href="#overview">Overview</a>
    <?php if ($isCivilian): ?>
      <a href="#map">Geo Map</a><a href="#requests">Emergency request</a><a href="#chat">Command chat</a>
    <?php else: ?>
      <a href="#map">Geo Map</a><a href="#evacuation">Evacuation</a><a href="#relief">Relief Ledger</a>
    <?php endif; ?>
    <?php if ($isAdmin): ?><a href="sms.php">SMS Console</a><?php endif; ?>
  </nav>
  <div class="flex items-center gap-3">
    <div class="hidden sm:block text-right text-[11px] text-gray-400">
      <div class="font-semibold text-white"><?= e(user_display_name()) ?></div>
      <div><?= e(user_role_label()) ?></div>
    </div>
    <span class="live-dot"><i></i> System online</span>
    <a class="btn btn-ghost" href="logout.php">Logout</a>
  </div>
</header>

<?php if ($flash): ?>
<div class="mt-4 rounded-xl border border-orange-500/40 bg-orange-500/10 px-4 py-3 text-sm text-orange-100"><?= e($flash) ?></div>
<?php endif; ?>

<?php if ($isCivilian): ?>
<section id="overview" class="hero pb-8">
  <div class="hero-pill">Civilian emergency assistance</div>
  <h1>Request help<br><span>right where you are.</span></h1>
  <p>Send your location, danger level, photo evidence, and emergency note to the response team in one click.</p>
  <div class="flex flex-wrap gap-3 mt-7">
    <button type="button" id="sosTrigger" class="btn btn-orange">SOS now</button>
    <button type="button" id="locateMe" class="btn btn-ghost">Use my GPS</button>
  </div>
</section>

<section id="requests" class="grid lg:grid-cols-[1.2fr_.8fr] gap-4 mb-4">
  <div class="panel p-5">
    <div class="panel-head no-border"><div><h2>Emergency request</h2><p>GPS, danger level, and photo upload are saved with each SOS report.</p></div></div>
    <form method="post" enctype="multipart/form-data" class="space-y-4">
      <input type="hidden" name="sos_submit" value="1">
      <input type="hidden" name="latitude" id="latitude" value="">
      <input type="hidden" name="longitude" id="longitude" value="">

      <div>
        <label class="auth-label" for="danger_level">Current danger level</label>
        <select id="danger_level" name="danger_level" class="auth-input">
          <option value="Low">Low</option>
          <option value="Moderate" selected>Moderate</option>
          <option value="High">High</option>
          <option value="Critical">Critical</option>
        </select>
      </div>

      <div>
        <label class="auth-label" for="report_text">Describe the situation</label>
        <textarea id="report_text" name="report_text" rows="5" class="auth-input" placeholder="Example: Flooding at the corner of Purok 2, power lines down, children trapped nearby." required></textarea>
      </div>

      <div>
        <label class="auth-label" for="photo">Upload photo of the situation</label>
        <input id="photo" name="photo" type="file" accept="image/*" class="auth-input">
      </div>

      <div class="grid sm:grid-cols-2 gap-3">
        <div class="mini-card">
          <span>Latitude</span>
          <b id="latValue">--</b>
        </div>
        <div class="mini-card">
          <span>Longitude</span>
          <b id="lngValue">--</b>
        </div>
      </div>

      <button type="submit" class="btn btn-orange w-full">Send SOS alert</button>
    </form>
  </div>

  <div class="panel p-5">
    <div class="panel-head no-border"><div><h2>Recent SOS reports</h2><p>Your recent emergency submissions.</p></div></div>
    <div class="space-y-3">
      <?php if (empty($recentReports)): ?>
        <p class="muted">No SOS reports yet. Use the form to send your first alert.</p>
      <?php else: ?>
        <?php foreach ($recentReports as $report): ?>
          <div class="center-row">
            <div class="flex justify-between"><b><?= e($report['danger_level'] ?? 'Moderate') ?></b><span><?= e($report['status'] ?? 'New') ?></span></div>
            <p class="muted mt-2"><?= e($report['report_text'] ?? '') ?></p>
            <div class="mt-2 text-xs text-gray-400"><?= e($report['latitude'] ?? '--') ?>, <?= e($report['longitude'] ?? '--') ?></div>
            <?php if (!empty($report['photo_path'])): ?>
              <img src="<?= e($report['photo_path']) ?>" alt="Emergency snapshot" class="mt-3 rounded-xl border border-gray-800 w-full object-cover max-h-48">
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php else: ?>
<section id="overview" class="hero">
  <div class="hero-pill"><?= $isAdmin ? 'Admin Command Console' : 'Civilian Situation View' ?></div>
  <h1>See risk before<br><span>it becomes crisis.</span></h1>
  <p>One command center for household vulnerability, evacuation capacity, emergency SMS reports, resource deployment, and live weather intelligence.</p>
  <div class="flex flex-wrap gap-3 mt-7">
    <a href="#map" class="btn btn-orange">Open live risk map →</a>
    <a href="<?= e($zoomEarthUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-ghost">View satellite & radar</a>
  </div>
</section>

<section class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
  <div class="stat glass"><span>Households mapped</span><strong><?= $totalHouseholds ?></strong><small>pre-disaster registry</small></div>
  <div class="stat glass"><span>Danger-zone households</span><strong class="orange"><?= $dangerHouseholds ?></strong><small>priority for evacuation</small></div>
  <div class="stat glass"><span>Evacuation occupancy</span><strong><?= $capacityPct ?>%</strong><small><?= $totalOccupancy ?> / <?= $totalCapacity ?> people</small></div>
  <div class="stat glass"><span>Relief distributed</span><strong><?= number_format($reliefDistributed) ?></strong><small>of <?= number_format($reliefReceived) ?> tracked units</small></div>
</section>
<?php endif; ?>

<section id="map" class="grid lg:grid-cols-[1.55fr_.75fr] gap-4 mb-4">
  <div class="panel overflow-hidden">
    <div class="panel-head">
      <div><h2><?= $isAdmin ? 'Geo Risk Command Map' : 'Your Location Preview' ?></h2><p><?= $isAdmin ? 'Household risk zones and evacuation centers' : ($civilianMapLocation ? 'Showing your latest reported GPS location' : 'Use GPS to preview your current location') ?></p></div>
      <?php if ($isCivilian): ?>
        <button type="button" id="locateMap" class="mini">Use my GPS</button>
      <?php else: ?>
        <button type="button" id="locateMap" class="mini">Locate</button>
      <?php endif; ?>
    </div>
    <div id="mapCanvas" role="img" aria-label="Map preview of your location"></div>
    <div class="map-preview-actions">
      <a id="zoomEarthLink" class="btn btn-orange" target="_blank" rel="noopener noreferrer" href="<?= e($zoomEarthUrl) ?>">View satellite &amp; radar</a>
      <span><?= $isCivilian ? 'Your reported or GPS location is marked on the preview.' : 'Open Zoom Earth for live satellite and radar imagery.' ?></span>
    </div>
    <?php if ($isAdmin): ?>
      <div class="map-note"><span class="legend danger"></span> Danger <span class="legend caution"></span> Caution <span class="legend safe"></span> Safe <span class="legend center"></span> Evacuation center</div>
    <?php else: ?>
      <div class="map-note"><span class="legend user-location"></span> Your location</div>
    <?php endif; ?>
  </div>
  <aside class="panel p-5">
    <div class="section-label"><?= $isAdmin ? 'MAP LEGEND' : 'LOCATION PREVIEW' ?></div>
    <h3 class="side-title"><?= $isAdmin ? 'Operational map' : 'Your reported location' ?></h3>
    <p class="muted"><?= $isAdmin ? 'Household markers show risk levels. Evacuation centers are marked separately.' : ($civilianMapLocation ? 'This preview is centered on the GPS coordinates from your latest SOS report.' : 'Your map starts centered on the Philippines. Use GPS to center it on your current location.') ?></p>
    <div class="alert-card"><span class="pulse"></span><div><b>Safety note</b><p>For live satellite imagery and radar, use the View satellite &amp; radar button below the map.</p></div></div>
  </aside>
</section>

<?php
$liveReports = [];
if ($conn && $conn->ping()) {
    $liveReports = db_rows($conn, "SELECT * FROM civilian_reports ORDER BY created_at DESC LIMIT 8", []);
}
$dangerSummary = ['Low' => 0, 'Moderate' => 0, 'High' => 0, 'Critical' => 0];
foreach ($liveReports as $report) {
    $level = $report['danger_level'] ?? 'Moderate';
    if (isset($dangerSummary[$level])) {
        $dangerSummary[$level]++;
    }
}
?>
<section class="grid lg:grid-cols-[1.15fr_.85fr] gap-4 mb-4">
  <div class="panel p-5">
    <div class="panel-head no-border"><div><h2>Live SOS alert feed</h2><p>Incoming civilian alerts are automatically routed into the command center.</p></div><span class="badge orange-badge">LIVE</span></div>
    <div class="grid sm:grid-cols-4 gap-2 mb-4">
      <div class="mini-card"><span>Low</span><b><?= $dangerSummary['Low'] ?></b></div>
      <div class="mini-card"><span>Moderate</span><b><?= $dangerSummary['Moderate'] ?></b></div>
      <div class="mini-card"><span>High</span><b><?= $dangerSummary['High'] ?></b></div>
      <div class="mini-card"><span>Critical</span><b><?= $dangerSummary['Critical'] ?></b></div>
    </div>
    <div class="space-y-3">
      <?php if (empty($liveReports)): ?>
        <p class="muted">No SOS alerts have been received yet.</p>
      <?php else: ?>
        <?php foreach ($liveReports as $report): ?>
          <div class="center-row">
            <div class="flex justify-between gap-3 items-start">
              <div>
                <b><?= e($report['user_name'] ?? 'Unknown citizen') ?></b>
                <span><?= e($report['created_at'] ?? '') ?></span>
              </div>
              <div class="flex flex-col items-end gap-2">
                <?php if (($report['danger_level'] ?? 'Moderate') === 'Critical'): ?>
                  <span class="alert-badge critical">Admin alert</span>
                <?php endif; ?>
                <span class="risk <?= e($report['danger_level'] ?? 'Moderate') ?>"><?= e($report['danger_level'] ?? 'Moderate') ?></span>
              </div>
            </div>
            <p class="muted mt-2"><?= e($report['report_text'] ?? '') ?></p>
            <div class="mt-2 flex justify-between text-xs text-gray-400">
              <span><?= e($report['latitude'] ?? '--') ?>, <?= e($report['longitude'] ?? '--') ?></span>
              <span class="report-current-status"><?= e($report['status'] ?? 'New') ?></span>
            </div>
            <?php if ($isAdmin): ?>
              <div class="mt-3 space-y-2">
                <div class="flex items-center gap-2">
                  <select class="auth-input report-status" data-report-id="<?= (int)($report['id'] ?? 0) ?>" aria-label="Update status">
                    <option value="New" <?= (($report['status'] ?? 'New') === 'New') ? 'selected' : '' ?>>New</option>
                    <option value="Approved" <?= (($report['status'] ?? 'New') === 'Approved') ? 'selected' : '' ?>>Approved</option>
                    <option value="In Progress" <?= (($report['status'] ?? 'New') === 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                    <option value="Reassigned" <?= (($report['status'] ?? 'New') === 'Reassigned') ? 'selected' : '' ?>>Reassigned</option>
                    <option value="Resolved" <?= (($report['status'] ?? 'New') === 'Resolved') ? 'selected' : '' ?>>Resolved</option>
                  </select>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                  <input type="text" class="auth-input report-assignee" data-report-id="<?= (int)($report['id'] ?? 0) ?>" value="<?= e($report['assigned_to'] ?? '') ?>" placeholder="Responder name">
                  <select class="auth-input report-team" data-report-id="<?= (int)($report['id'] ?? 0) ?>">
                    <option value="" <?= empty($report['assigned_team'] ?? '') ? 'selected' : '' ?>>Assign team</option>
                    <option value="Medical" <?= (($report['assigned_team'] ?? '') === 'Medical') ? 'selected' : '' ?>>Medical</option>
                    <option value="Fire" <?= (($report['assigned_team'] ?? '') === 'Fire') ? 'selected' : '' ?>>Fire</option>
                    <option value="Rescue" <?= (($report['assigned_team'] ?? '') === 'Rescue') ? 'selected' : '' ?>>Rescue</option>
                    <option value="Logistics" <?= (($report['assigned_team'] ?? '') === 'Logistics') ? 'selected' : '' ?>>Logistics</option>
                  </select>
                </div>
                <select class="auth-input report-escalation" data-report-id="<?= (int)($report['id'] ?? 0) ?>">
                  <option value="Monitoring" <?= (($report['escalation_level'] ?? 'Monitoring') === 'Monitoring') ? 'selected' : '' ?>>Monitoring</option>
                  <option value="Dispatch" <?= (($report['escalation_level'] ?? 'Monitoring') === 'Dispatch') ? 'selected' : '' ?>>Dispatch</option>
                  <option value="Escalated" <?= (($report['escalation_level'] ?? 'Monitoring') === 'Escalated') ? 'selected' : '' ?>>Escalated</option>
                </select>
                <div class="flex items-center gap-3">
                  <button type="button" class="btn btn-orange report-save">Submit update</button>
                  <span class="report-save-state text-xs text-gray-400" aria-live="polite"></span>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="panel p-5">
    <div class="panel-head no-border"><div><h2>Live weather & situation</h2><p>Current conditions and operational risk from the mapped area.</p></div><span class="badge orange-badge">AUTO</span></div>
    <div class="weather-card" id="weatherCard">
      <div class="weather-grid"></div>
      <div class="weather-value" id="weatherTemp">--°C</div>
      <div class="weather-copy">
        <strong id="weatherSummary">Checking weather…</strong>
        <span id="weatherMeta">Satellite + rain + storm check</span>
      </div>
      <div class="weather-icon" id="weatherIcon">☀️</div>
    </div>
    <div class="alert-card mt-4">
      <span class="pulse"></span>
      <div>
        <b id="riskAlertTitle">Monitoring</b>
        <p id="riskAlertText">No immediate calamity alert detected. Continue monitoring the live feed.</p>
      </div>
    </div>
  </div>
</section>

<section id="chat" class="panel p-5 mb-4">
  <div class="panel-head no-border"><div><h2>Command chat</h2><p>Direct communication between civilian and admin teams.</p></div><span class="badge orange-badge">LIVE</span></div>
  <div id="chatMessages" class="space-y-3 max-h-[360px] overflow-y-auto pr-2"></div>
  <form id="chatForm" class="mt-4 flex gap-2">
    <input id="chatInput" type="text" class="auth-input" placeholder="Send a message..." maxlength="255" required>
    <button type="submit" class="btn btn-orange">Send</button>
  </form>
</section>

<?php if (!$isCivilian): ?>
<section id="evacuation" class="grid lg:grid-cols-2 gap-4 mb-4">
  <div class="panel p-5">
    <div class="panel-head no-border"><div><h2>Evacuation Capacity Matrix</h2><p>Redirect arrivals when thresholds are reached.</p></div><span class="badge orange-badge">LIVE</span></div>
    <div class="space-y-3">
      <?php foreach ($centers as $c):
        $pct = $c['capacity'] ? round(($c['current_occupancy']/$c['capacity'])*100) : 0;
        $bar = $pct >= 90 ? 'bg-red-500' : ($pct >= 75 ? 'bg-orange-400' : 'bg-emerald-400');
      ?>
      <div class="center-row">
        <div class="flex justify-between gap-3"><div><b><?=e($c['name'])?></b><span><?=e($c['barangay'])?></span></div><strong><?=$pct?>%</strong></div>
        <div class="progress"><div class="<?=$bar?>" style="width:<?=min($pct,100)?>%"></div></div>
        <div class="flex justify-between text-xs text-gray-500"><span><?=$c['current_occupancy']?> / <?=$c['capacity']?> occupants</span><span><?=e($c['status'])?></span></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="panel p-5">
    <div class="panel-head no-border"><div><h2>Priority Household Queue</h2><p>Generated from risk + vulnerability fields.</p></div></div>
    <div class="table-wrap">
      <table><thead><tr><th>Family</th><th>Purok</th><th>Risk</th><th>Members</th><th>Priority</th></tr></thead>
      <tbody>
      <?php foreach ($households as $h):
        $priority = ($h['risk_level']==='Danger' && ($h['seniors']??0)+($h['infants']??0)+($h['pwd_count']??0)+($h['pregnant']??0)>0) ? 'URGENT' : (($h['risk_level']==='Danger')?'HIGH':'NORMAL');
      ?>
      <tr><td><b><?=e($h['family_code'])?></b><small><?=e($h['head_name'])?></small></td><td><?=e($h['purok'])?></td><td><span class="risk <?=$h['risk_level']?>"><?=$h['risk_level']?></span></td><td><?=$h['members']?></td><td><span class="priority <?=$priority?>"><?=$priority?></span></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!$isCivilian): ?>
<section class="grid lg:grid-cols-[.85fr_1.15fr] gap-4 mb-4">
  <div class="panel p-5">
    <div class="section-label">OFFLINE-FIRST SMS</div>
    <h2 class="big-section">SOS ingestion</h2>
    <p class="muted">A GSM gateway or SMS bridge can POST inbound messages to <code>sms_ingest.php</code>. The parser stores the event even when citizens have no internet access.</p>
    <div class="codebox">SOS Maria Santos | Purok 1 | MEDICAL: insulin</div>
    <div class="flex gap-2 mt-4">
      <?php if ($isAdmin): ?><a href="sms.php" class="btn btn-orange">Open SMS console</a><?php endif; ?>
      <a href="sms_ingest.php?demo=1" target="_blank" class="btn btn-ghost">Demo endpoint</a>
    </div>
  </div>
  <div class="panel p-5">
    <div class="panel-head no-border"><div><h2>Relief & Donation Ledger</h2><p>Track received, distributed and remaining units.</p></div><a href="relief.php" class="mini">View ledger</a></div>
    <div class="table-wrap"><table><thead><tr><th>Item</th><th>Received</th><th>Distributed</th><th>Remaining</th></tr></thead><tbody>
    <?php foreach($inventory as $i): $remaining=(int)$i['quantity_received']-(int)$i['quantity_distributed']; ?>
      <tr><td><b><?=e($i['item_name'])?></b><small><?=e($i['category'])?></small></td><td><?=number_format($i['quantity_received'])?></td><td><?=number_format($i['quantity_distributed'])?></td><td><span class="remaining"><?=$remaining?></span></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </div>
</section>
<?php endif; ?>

<?php if (!$isCivilian): ?>
<section id="relief" class="panel p-5 mb-8">
  <div class="flex flex-col md:flex-row justify-between gap-4 mb-5"><div><div class="section-label">PUBLIC ACCOUNTABILITY</div><h2 class="big-section">Transparent distribution trail</h2><p class="muted">Every issue can be linked to a family code and digital receipt.</p></div>
    <?php if ($isAdmin): ?>
      <a href="relief.php" class="btn btn-orange">Manage ledger →</a>
    <?php else: ?>
      <a href="relief.php" class="btn btn-ghost">View public ledger →</a>
    <?php endif; ?>
  </div>
  <div class="grid md:grid-cols-3 gap-3">
    <div class="mini-card"><span>Incoming batches</span><b><?=count($inventory)?></b></div>
    <div class="mini-card"><span>Tracked received units</span><b><?=number_format($reliefReceived)?></b></div>
    <div class="mini-card"><span>Remaining units</span><b><?=number_format($reliefReceived-$reliefDistributed)?></b></div>
  </div>
</section>
<?php endif; ?>

<footer>VetraDRR prototype • PHP + MySQL • For demonstration; verify official warnings before emergency use.</footer>
</div>
<script>
window.dashboardMapConfig = <?= json_encode([
    'isAdmin' => $isAdmin,
    'hasReportedLocation' => $civilianMapLocation !== null,
    'initialLocation' => [
        (float)$mapPreviewLocation['latitude'],
        (float)$mapPreviewLocation['longitude'],
    ],
    'households' => $isAdmin ? $households : [],
    'centers' => $isAdmin ? $centers : [],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="app.js?v=20261026"></script>
<script>
  const sosTriggerBtn = document.getElementById('sosTrigger');
  const locateMeBtn = document.getElementById('locateMe');
  const latValue = document.getElementById('latValue');
  const lngValue = document.getElementById('lngValue');
  const latitudeField = document.getElementById('latitude');
  const longitudeField = document.getElementById('longitude');
  const locateMapBtn = document.getElementById('locateMap');

  const setLocationValue = (latitude, longitude) => {
    if (!Number.isFinite(Number(latitude)) || !Number.isFinite(Number(longitude))) {
      return;
    }
    latitudeField.value = latitude;
    longitudeField.value = longitude;
    latValue.textContent = Number(latitude).toFixed(5);
    lngValue.textContent = Number(longitude).toFixed(5);
    if (window.updateLocationPreview) {
      window.updateLocationPreview(Number(latitude), Number(longitude));
    }
  };

  const getUserLocation = () => {
    if (!navigator.geolocation) {
      alert('GPS is not supported in this browser.');
      return;
    }

    navigator.geolocation.getCurrentPosition((position) => {
      setLocationValue(position.coords.latitude, position.coords.longitude);
    }, () => {
      alert('Location access was denied. Please allow GPS access to send your SOS report.');
    }, { enableHighAccuracy: true, timeout: 15000 });
  };

  if (locateMeBtn) {
    locateMeBtn.addEventListener('click', getUserLocation);
  }
  if (locateMapBtn) {
    locateMapBtn.addEventListener('click', () => {
      if (locateMeBtn) {
        getUserLocation();
      } else if (window.locateMap) {
        window.locateMap();
      }
    });
  }

  if (sosTriggerBtn) {
    sosTriggerBtn.addEventListener('click', () => {
      getUserLocation();
      document.getElementById('report_text').focus();
    });
  }
</script>
</body>
</html>
