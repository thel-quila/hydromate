
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "thel", "helloxampp", "hydro_db");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$user_id  = (int)$_SESSION['user_id'];
$username = $_SESSION['username'];
$tab      = $_GET['tab'] ?? 'dashboard';

// Avatar
if (!isset($_SESSION['user_avatar'])) $_SESSION['user_avatar'] = '💧';
$userAvatar = $_SESSION['user_avatar'];

// Handle avatar update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_avatar'])) {
    $newAvatar = $_POST['avatar_emoji'];
    try {
        $updateStmt = $conn->prepare("UPDATE user SET avatar = ? WHERE user_id = ?");
        $updateStmt->bind_param("si", $newAvatar, $user_id);
        $updateStmt->execute();
        $updateStmt->close();
    } catch (Exception $e) { }
    $_SESSION['user_avatar'] = $newAvatar;
    $userAvatar = $newAvatar;
    header("Location: dash.php?tab=" . ($_GET['tab'] ?? 'dashboard') . "&avatar_updated=1");
    exit();
}

// Fetch gender
$uStmt = $conn->prepare("SELECT gender FROM user WHERE user_id = ?");
$uStmt->bind_param("i", $user_id);
$uStmt->execute();
$uStmt->bind_result($userGender);
$uStmt->fetch();
$uStmt->close();
$genderNum = (strtoupper($userGender ?? 'F') === 'M') ? 1 : 0;

// ════════════════════════════════
//  CHECK: Already predicted today?
// ════════════════════════════════
$today = date('Y-m-d');
$todayCheck = $conn->prepare("
    SELECT p.pred_id, p.hydration_result, d.age, d.weight, d.activity_level, d.weather, ul.add_water, d.date_created
    FROM prediction p
    JOIN dataset d ON p.dataset_id = d.dataset_id
    LEFT JOIN user_log ul ON ul.user_id = d.user_id AND ul.add_age = d.age AND DATE(ul.timestamp) = d.date_created AND ul.action = 'add'
    WHERE p.user_id = ? AND d.date_created = ?
    ORDER BY p.pred_id DESC
    LIMIT 1
");
$todayCheck->bind_param("is", $user_id, $today);
$todayCheck->execute();
$todayResult = $todayCheck->get_result()->fetch_assoc();
$todayCheck->close();
$alreadyPredictedToday = ($todayResult !== null);

// Output vars
$result = $recommendation = $statusClass = $mlLabel = "";
$percentage = $baseWater = 0;
$mlPrediction = null;

// ════════════════════════════════
//  FORM SUBMIT — only if not already predicted today
// ════════════════════════════════
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['update_avatar'])) {

    if ($alreadyPredictedToday) {
        // Block: redirect back silently
        header("Location: dash.php?tab=dashboard&blocked=1");
        exit();
    }

    $age       = (int)  $_POST['age'];
    $weight    = (float)$_POST['weight'];
    $activityN = (int)  $_POST['activity'];
    $weatherN  = (int)  $_POST['weather'];
    $consumed  = (float)$_POST['water_intake'];

    $actMap   = [0=>'low', 1=>'moderate', 2=>'active'];
    $wthMap   = [0=>'cold', 1=>'normal',  2=>'hot'];
    $activity = $actMap[$activityN] ?? 'low';
    $weather  = $wthMap[$weatherN]  ?? 'normal';

    $baseWater = $weight * 0.033;
    if ($activity=='moderate') $baseWater += 0.5;
    elseif ($activity=='active') $baseWater += 1;
    if ($weather=='hot')  $baseWater += 0.7;
    elseif ($weather=='cold') $baseWater -= 0.3;
    $baseWater  = max(1.5, round($baseWater, 2));
    $percentage = min(100, round(($consumed / $baseWater) * 100));

    if ($consumed >= $baseWater)           { $result="Well Hydrated";      $recommendation="Excellent! You've hit your daily goal!";                          $statusClass="hydrated"; }
    elseif ($consumed >= $baseWater * 0.6) { $result="Partially Hydrated"; $recommendation="Almost! Drink ".round($baseWater-$consumed,2)."L more today.";   $statusClass="partial"; }
    else                                    { $result="Not Hydrated";        $recommendation="Need ".round($baseWater-$consumed,2)."L more to reach ".$baseWater."L."; $statusClass="not-hydrated"; }

    // ML Model
    $inputData = ["age"=>$age,"gender"=>$genderNum,"weight"=>$weight,"water_intake"=>$consumed,"activity"=>$activityN,"weather"=>$weatherN];
    $inputJson = json_encode($inputData, JSON_FORCE_OBJECT);
    $apiUrl  = "http://localhost:5000/predict";
    $ch      = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $inputJson);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT,        5);
    $pyOut    = curl_exec($ch);
    curl_close($ch);
    $pyResult = json_decode($pyOut, true);

    if ($pyResult && isset($pyResult['prediction'])) {
        $mlPrediction = (int)$pyResult['prediction'];
        // FIX: Model encodes Good=0 (Hydrated), Poor=1 (Not Hydrated)
        // So prediction=0 → Hydrated, prediction=1 → Not Hydrated
        $mlLabel = ($mlPrediction == 0) ? "Hydrated" : "Not Hydrated";
    } else {
        $mlLabel      = "Model unavailable";
        $mlPrediction = ($statusClass=='hydrated') ? 0 : 1;
    }

    $hydrationResult = $mlPrediction; // 0=Hydrated, 1=Not Hydrated (matches model encoding)

    // Save dataset
    $hr = (string)$hydrationResult;
    $ds = $conn->prepare("INSERT INTO dataset (user_id,age,weight,activity_level,weather,hydration_result,date_created) VALUES (?,?,?,?,?,?,CURDATE())");
    $ds->bind_param("iiisss", $user_id, $age, $weight, $activity, $weather, $hr);
    $ds->execute();
    $dataset_id = $conn->insert_id;
    $ds->close();

    // Save user_log
    $lg = $conn->prepare("INSERT INTO user_log (user_id,action,add_water,add_activity,add_weather,add_age,add_gender,add_weight) VALUES (?,'add',?,?,?,?,?,?)");
    $lg->bind_param("idsssid", $user_id, $consumed, $activity, $weather, $age, $userGender, $weight);
    $lg->execute();
    $lg->close();

    // Save prediction
    $pr = $conn->prepare("INSERT INTO prediction (user_id,dataset_id,hydration_result) VALUES (?,?,?)");
    $pr->bind_param("iii", $user_id, $dataset_id, $hydrationResult);
    $pr->execute();
    $pr->close();

    header("Location: dash.php?tab=dashboard&r=1&pct=$percentage&sc=".urlencode($statusClass)."&rec=".urlencode($recommendation)."&goal=$baseWater&ml=".urlencode($mlLabel));
    exit();
}

// Restore from redirect
if (isset($_GET['r'])) {
    $percentage     = (int)$_GET['pct'];
    $statusClass    = $_GET['sc'] ?? '';
    $recommendation = urldecode($_GET['rec'] ?? '');
    $baseWater      = (float)($_GET['goal'] ?? 0);
    $mlLabel        = urldecode($_GET['ml'] ?? '');
    $result         = match($statusClass) { 'hydrated'=>'Well Hydrated','partial'=>'Partially Hydrated','not-hydrated'=>'Not Hydrated',default=>'' };
    $mlPrediction   = str_contains($mlLabel,'Not') ? 1 : 0;
}

// If already predicted today and no redirect result, restore from DB
if ($alreadyPredictedToday && $result == "" && !isset($_GET['r'])) {
    $tr = $todayResult;
    $w  = $tr['weight'] ?? 60;
    $a  = $tr['activity_level'] ?? 'low';
    $wt = $tr['weather'] ?? 'normal';
    $consumed_today = (float)($tr['add_water'] ?? 0);
    $baseWater = max(1.5, round($w*0.033 + ($a=='moderate'?0.5:($a=='active'?1:0)) + ($wt=='hot'?0.7:($wt=='cold'?-0.3:0)), 2));
    $percentage = ($baseWater > 0 && $consumed_today > 0) ? min(100, round(($consumed_today / $baseWater) * 100)) : 0;
    $mlPrediction = (int)$tr['hydration_result'];
    // FIX applied here too: 0=Hydrated, 1=Not Hydrated
    $mlLabel = ($mlPrediction == 0) ? "Hydrated" : "Not Hydrated";

    if ($consumed_today >= $baseWater)             { $result="Well Hydrated";      $recommendation="Excellent! You hit your goal today!";                                      $statusClass="hydrated"; }
    elseif ($consumed_today >= $baseWater * 0.6)   { $result="Partially Hydrated"; $recommendation="Almost! ".round($baseWater-$consumed_today,2)."L more would hit your goal."; $statusClass="partial"; }
    else                                            { $result="Not Hydrated";        $recommendation="You needed ".round($baseWater-$consumed_today,2)."L more today.";            $statusClass="not-hydrated"; }
}

// Load history
$historyRows = [];
$hs = $conn->prepare("SELECT d.dataset_id,d.age,d.weight,d.activity_level,d.weather,d.date_created,p.hydration_result AS ml_result,ul.add_water,ul.timestamp FROM dataset d LEFT JOIN prediction p ON p.dataset_id=d.dataset_id AND p.user_id=d.user_id LEFT JOIN user_log ul ON ul.user_id=d.user_id AND ul.add_age=d.age AND DATE(ul.timestamp)=d.date_created AND ul.action='add' WHERE d.user_id=? ORDER BY d.dataset_id DESC LIMIT 7");
$hs->bind_param("i", $user_id);
$hs->execute();
$hr2 = $hs->get_result();
while ($row = $hr2->fetch_assoc()) {
    $w=$row['weight']??60; $a=$row['activity_level']??'low'; $wt=$row['weather']??'normal'; $con=(float)($row['add_water']??0);
    $rec=max(1.5,round($w*0.033+($a=='moderate'?0.5:($a=='active'?1:0))+($wt=='hot'?0.7:($wt=='cold'?-0.3:0)),2));
    $pct=($rec>0&&$con>0)?min(100,round(($con/$rec)*100)):0;
    $historyRows[]=['date'=>isset($row['timestamp'])?date('M d H:i',strtotime($row['timestamp'])):date('M d',strtotime($row['date_created'])),'consumed'=>$con,'recommended'=>$rec,'percentage'=>$pct,'activity'=>$a,'weather'=>$wt,'ml_result'=>$row['ml_result']];
}
$hs->close();

$ch2           = array_reverse($historyRows);
$chartLabels   = json_encode(array_column($ch2,'date'));
$chartConsumed = json_encode(array_column($ch2,'consumed'));
$chartGoal     = json_encode(array_column($ch2,'recommended'));
$avgConsumed   = count($historyRows)>0?round(array_sum(array_column($historyRows,'consumed'))/count($historyRows),1):0;
$avgGoal       = count($historyRows)>0?round(array_sum(array_column($historyRows,'recommended'))/count($historyRows),1):0;
$bestPct       = count($historyRows)>0?max(array_column($historyRows,'percentage')):0;

// Notifications
$notifRows=[];
$ns=$conn->prepare("SELECT d.date_created,ul.add_water,ul.timestamp FROM prediction p JOIN dataset d ON d.dataset_id=p.dataset_id LEFT JOIN user_log ul ON ul.user_id=p.user_id AND ul.add_age=d.age AND DATE(ul.timestamp)=d.date_created AND ul.action='add' WHERE p.user_id=? AND p.hydration_result=1 ORDER BY d.date_created DESC LIMIT 5");
$ns->bind_param("i",$user_id);
$ns->execute();
$nr=$ns->get_result();
while($row=$nr->fetch_assoc()) $notifRows[]=$row;
$ns->close();
$unread=count($notifRows);
$conn->close();

// Midnight countdown
$midnight = strtotime('tomorrow midnight');
$secondsUntilMidnight = $midnight - time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HydroMate — <?php echo ucfirst($tab); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <link rel="stylesheet" href="../css/dash.css">
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">💧</div>
        <div class="brand-text">
            <span class="brand-name">HydroMate</span>
            <span class="brand-sub">Water Tracker</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <a href="?tab=dashboard" class="nav-item <?php echo $tab=='dashboard'?'active':''; ?>"><span class="nav-icon">⊞</span><span>Dashboard</span></a>
        <a href="?tab=graph"     class="nav-item <?php echo $tab=='graph'    ?'active':''; ?>"><span class="nav-icon">📊</span><span>Graph</span></a>
        <a href="?tab=history"   class="nav-item <?php echo $tab=='history'  ?'active':''; ?>"><span class="nav-icon">📅</span><span>History</span></a>
        <a href="?tab=alerts"    class="nav-item <?php echo $tab=='alerts'   ?'active':''; ?>">
            <span class="nav-icon">🔔</span>
            <span>Alerts <?php if($unread>0) echo '<span class="badge">'.$unread.'</span>'; ?></span>
        </a>
    </nav>
    <a href="login.php" class="sidebar-logout"><span>↩</span><span>Logout</span></a>
</aside>

<main class="main">

<?php if ($tab === 'dashboard'): ?>
<!-- ══ DASHBOARD ══ -->
<header class="topbar">
    <div class="topbar-left">
        <h1 class="topbar-title">Hello, <?php echo htmlspecialchars($username); ?>! 👋</h1>
        <p class="topbar-sub">Track your hydration — stay healthy, stay sharp.</p>
    </div>
    <div class="topbar-right">
        <?php if($unread>0): ?><a href="?tab=alerts" class="notif-bell">🔔<span class="bell-badge"><?php echo $unread; ?></span></a><?php endif; ?>
        <div class="user-chip avatar-wrapper" onclick="openStickerPicker()">
            <div class="user-avatar"><?php echo $userAvatar; ?></div>
            <span><?php echo htmlspecialchars($username); ?></span>
            <div class="edit-overlay">✎</div>
        </div>
    </div>
</header>

<!-- FORM CARD -->
<div class="card card-form <?php echo $alreadyPredictedToday ? 'form-locked' : ''; ?>">
    <?php if ($alreadyPredictedToday): ?>
    <!-- LOCKED STATE -->
    <div class="locked-banner">
        <div class="locked-icon-wrap">🔒</div>
        <div class="locked-text">
            <strong>Daily check-in complete!</strong>
            <span>You've already logged your hydration today. Come back tomorrow for your next check-in!</span>
        </div>
        <div class="locked-countdown">
            <div class="countdown-label">Next check-in in</div>
            <div class="countdown-timer" id="countdownTimer">--:--:--</div>
        </div>
    </div>
    <script>
        // Countdown to midnight
        let remaining = <?php echo $secondsUntilMidnight; ?>;
        function updateCountdown() {
            if (remaining <= 0) { location.reload(); return; }
            const h = String(Math.floor(remaining / 3600)).padStart(2,'0');
            const m = String(Math.floor((remaining % 3600) / 60)).padStart(2,'0');
            const s = String(remaining % 60).padStart(2,'0');
            document.getElementById('countdownTimer').textContent = h + ':' + m + ':' + s;
            remaining--;
        }
        updateCountdown();
        setInterval(updateCountdown, 1000);
    </script>
    <?php else: ?>
    <!-- ACTIVE FORM -->
    <p class="card-label">📝 Log Your Intake</p>
    <form method="POST" action="dash.php" class="form" id="hydrationForm">
        <div class="form-top-row">
            <div class="form-group">
                <label>Age</label>
                <input type="number" name="age" placeholder="e.g. 22" min="1" max="120" required>
            </div>
            <div class="form-group">
                <label>Weight (kg)</label>
                <input type="number" name="weight" placeholder="e.g. 60" min="20" step="0.1" required>
            </div>
            <div class="form-group">
                <label>Activity Level</label>
                <select name="activity">
                    <option value="0">🧘 Low</option>
                    <option value="1">🚶 Moderate</option>
                    <option value="2">🏃 Active</option>
                </select>
            </div>
            <div class="form-group">
                <label>Weather</label>
                <select name="weather">
                    <option value="0">❄️ Cold</option>
                    <option value="1">🌤 Normal</option>
                    <option value="2">☀️ Hot</option>
                </select>
            </div>
            <div class="form-group cup-group">
                <label>Water Consumed <span class="cup-size-note">(1 cup = 250ml)</span></label>
                <div class="cup-counter">
                    <button type="button" class="cup-btn cup-minus" onclick="changeCups(-1)">−</button>
                    <div class="cup-display">
                        <span class="cup-count" id="cupCount">0</span>
                        <span class="cup-unit">cups</span>
                        <span class="cup-liters" id="cupLiters">0.00L</span>
                    </div>
                    <button type="button" class="cup-btn cup-plus" onclick="changeCups(1)">+</button>
                </div>
                <div class="cup-icons" id="cupIcons"></div>
                <input type="hidden" name="water_intake" id="waterIntakeVal" value="0">
            </div>
            <button type="submit" class="btn-check" onclick="return validateCups()">Check →</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<!-- STATUS + STATS -->
<div class="bottom-row">
    <div class="card card-status">
        <p class="card-label">💧 Hydration Status</p>
        <?php if ($result != ""): ?>

        <!-- ML Banner -->
        <div class="ml-banner <?php echo str_contains($mlLabel,'Not')?'ml-dry':'ml-hydrated'; ?>">
            <div class="ml-icon-wrap"><?php echo str_contains($mlLabel,'Not')?'❌':'✅'; ?></div>
            <div class="ml-text">
                <strong>ML: <?php echo htmlspecialchars($mlLabel); ?></strong>
                <small>Random Forest Prediction</small>
            </div>
            <?php if ($alreadyPredictedToday): ?>
            <span class="ml-today-tag">Today's result</span>
            <?php endif; ?>
        </div>

        <!-- Ring -->
        <div class="status-ring-wrap">
            <svg class="ring-svg" viewBox="0 0 120 120">
                <circle class="ring-track" cx="60" cy="60" r="48"/>
                <circle class="ring-fill <?php echo $statusClass; ?>" cx="60" cy="60" r="48" data-pct="<?php echo $percentage; ?>"/>
            </svg>
            <div class="ring-center">
                <span class="ring-pct"><?php echo $percentage; ?>%</span>
                <span class="ring-pct-label">of goal</span>
            </div>
        </div>

        <!-- Badge -->
        <div class="status-badge <?php echo $statusClass; ?>">
            <?php if($statusClass=="hydrated") echo "✅ ".$result; elseif($statusClass=="partial") echo "⚠️ ".$result; else echo "❌ ".$result; ?>
        </div>

        <!-- Recommendation -->
        <p class="status-rec"><?php echo htmlspecialchars($recommendation); ?></p>

        <!-- Progress bar -->
        <div class="bar-wrap">
            <div class="bar-labels">
                <span>Daily Progress</span>
                <span><?php echo $baseWater; ?>L goal</span>
            </div>
            <div class="water-bar">
                <div class="water-fill <?php echo $statusClass; ?>" style="width:<?php echo $percentage; ?>%"></div>
            </div>
        </div>

        <?php else: ?>
        <div class="status-empty">
            <div class="empty-drop">💧</div>
            <p>Fill in the form above<br>to check your hydration</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- STATS CARD -->
    <div class="card card-stats">
        <p class="card-label">📈 Quick Stats</p>
        <div class="stat-list">
            <div class="stat-item"><div class="stat-icon icon-blue">💧</div><div class="stat-info"><div class="stat-label">Avg. Intake</div><div class="stat-val"><?php echo $avgConsumed; ?>L</div></div></div>
            <div class="stat-item"><div class="stat-icon icon-green">🎯</div><div class="stat-info"><div class="stat-label">Avg. Goal</div><div class="stat-val"><?php echo $avgGoal; ?>L</div></div></div>
            <div class="stat-item"><div class="stat-icon icon-orange">📅</div><div class="stat-info"><div class="stat-label">Days Tracked</div><div class="stat-val"><?php echo count($historyRows); ?></div></div></div>
            <div class="stat-item"><div class="stat-icon icon-purple">🏆</div><div class="stat-info"><div class="stat-label">Best Day</div><div class="stat-val"><?php echo $bestPct; ?>%</div></div></div>
            <div class="stat-item"><div class="stat-icon icon-red">⚡</div><div class="stat-info"><div class="stat-label">Today's Goal</div><div class="stat-val"><?php echo ($baseWater>0)?$baseWater.'L':'—'; ?></div></div></div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'graph'): ?>
<!-- ══ GRAPH ══ -->
<header class="topbar">
    <div class="topbar-left"><h1 class="topbar-title">📊 7-Day Graph</h1><p class="topbar-sub">Your hydration trend over the past 7 entries</p></div>
    <div class="topbar-right">
        <div class="user-chip avatar-wrapper" onclick="openStickerPicker()">
            <div class="user-avatar"><?php echo $userAvatar; ?></div>
            <span><?php echo htmlspecialchars($username); ?></span>
            <div class="edit-overlay">✎</div>
        </div>
    </div>
</header>
<div class="graph-summary">
    <div class="card summary-card"><div class="summary-icon si-blue">💧</div><div><div class="summary-val"><?php echo $avgConsumed; ?>L</div><div class="summary-lbl">Avg. Intake</div></div></div>
    <div class="card summary-card"><div class="summary-icon si-green">🎯</div><div><div class="summary-val"><?php echo $avgGoal; ?>L</div><div class="summary-lbl">Avg. Goal</div></div></div>
    <div class="card summary-card"><div class="summary-icon si-orange">🏆</div><div><div class="summary-val"><?php echo $bestPct; ?>%</div><div class="summary-lbl">Best Day</div></div></div>
    <div class="card summary-card"><div class="summary-icon si-purple">📅</div><div><div class="summary-val"><?php echo count($historyRows); ?></div><div class="summary-lbl">Days Tracked</div></div></div>
</div>
<div class="card card-graph-full">
    <?php if(count($historyRows)>0): ?><div id="hydroChart"></div>
    <?php else: ?><div class="empty-graph"><span>📊</span><p>No data yet — go to Dashboard and log your intake!</p></div><?php endif; ?>
</div>

<?php elseif ($tab === 'history'): ?>
<!-- ══ HISTORY ══ -->
<header class="topbar">
    <div class="topbar-left"><h1 class="topbar-title">📅 History Log</h1><p class="topbar-sub">Your last <?php echo count($historyRows); ?> hydration entries</p></div>
    <div class="topbar-right">
        <div class="user-chip avatar-wrapper" onclick="openStickerPicker()">
            <div class="user-avatar"><?php echo $userAvatar; ?></div>
            <span><?php echo htmlspecialchars($username); ?></span>
            <div class="edit-overlay">✎</div>
        </div>
    </div>
</header>
<?php if(count($historyRows)>0): ?>
<div class="history-cards">
    <?php foreach($historyRows as $h):
        $sc=($h['percentage']>=100)?'hydrated':(($h['percentage']>=60)?'partial':'not-hydrated');
        $ml=$h['ml_result'];
        $dateParts=explode(' ',$h['date']);
    ?>
    <div class="card history-entry">
        <div class="history-entry-top">
            <div class="history-date">
                <span class="date-num"><?php echo $dateParts[1]??'—'; ?></span>
                <span class="date-mon"><?php echo $dateParts[0]??'—'; ?></span>
            </div>
            <div class="history-meta">
                <div class="history-badges">
                    <span class="tbl-badge <?php echo $sc; ?>"><?php echo ucwords(str_replace('-',' ',$sc)); ?></span>
                    <?php if($ml!==null):
                        // FIX: 0=Hydrated, 1=Not Hydrated
                        $mlIsHydrated = ($ml == 0);
                    ?><span class="tbl-badge <?php echo $mlIsHydrated?'hydrated':'not-hydrated'; ?>">🤖 <?php echo $mlIsHydrated?'Hydrated':'Not Hydrated'; ?></span><?php endif; ?>
                </div>
                <div class="history-details">
                    <span>💧 <?php echo $h['consumed']; ?>L</span>
                    <span>🎯 <?php echo $h['recommended']; ?>L goal</span>
                    <span>🚶 <?php echo ucfirst($h['activity']); ?></span>
                    <span>🌤 <?php echo ucfirst($h['weather']); ?></span>
                </div>
            </div>
            <div class="history-pct">
                <span class="pct-big <?php echo $sc; ?>"><?php echo $h['percentage']; ?>%</span>
                <span class="pct-lbl">of goal</span>
            </div>
        </div>
        <div class="tbl-bar" style="margin-top:12px">
            <div class="tbl-fill <?php echo $sc; ?>" style="width:<?php echo min(100,$h['percentage']); ?>%"></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card"><div class="empty-graph"><span>📅</span><p>No history yet. Log your first entry from the Dashboard!</p></div></div>
<?php endif; ?>

<?php elseif ($tab === 'alerts'): ?>
<!-- ══ ALERTS ══ -->
<header class="topbar">
    <div class="topbar-left"><h1 class="topbar-title">🔔 Alerts</h1><p class="topbar-sub"><?php echo $unread>0?$unread.' low hydration alert(s)':"No alerts — you're doing great!"; ?></p></div>
    <div class="topbar-right">
        <div class="user-chip avatar-wrapper" onclick="openStickerPicker()">
            <div class="user-avatar"><?php echo $userAvatar; ?></div>
            <span><?php echo htmlspecialchars($username); ?></span>
            <div class="edit-overlay">✎</div>
        </div>
    </div>
</header>
<?php if(count($notifRows)>0): ?>
<div class="alerts-list">
    <?php foreach($notifRows as $n): ?>
    <div class="card alert-entry">
        <div class="alert-icon-wrap">⚠️</div>
        <div class="alert-body">
            <p>Low hydration on <strong><?php echo date('M d, Y',strtotime($n['date_created'])); ?></strong> — only <strong><?php echo $n['add_water']??'—'; ?>L</strong> logged.</p>
            <span class="alert-time"><?php echo isset($n['timestamp'])?date('M d, h:i A',strtotime($n['timestamp'])):date('M d',strtotime($n['date_created'])); ?></span>
        </div>
        <span class="tbl-badge not-hydrated">Not Hydrated</span>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card"><div class="empty-graph"><span>✅</span><p>No alerts — your hydration looks great!</p></div></div>
<?php endif; ?>

<?php endif; ?>
</main>

<!-- STICKER MODAL -->
<div id="stickerModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Choose Your Avatar 💧</h3>
            <button class="modal-close" onclick="closeStickerPicker()">&times;</button>
        </div>
        <form method="POST" action="dash.php?tab=<?php echo $tab; ?>" id="stickerForm">
            <div class="sticker-grid">
                <?php foreach(['💧','💦','🚰','💙','🌊','😊','🥤','🧊','🏊','🚿','☀️','🌈','⛲','🐟','🐋','🏃','🚴','🧘','💪','⭐','🐧','🦭','🐠','🐙','🧜‍♀️'] as $emoji): ?>
                <div class="sticker-option <?php echo $emoji==$userAvatar?'selected':''; ?>" data-emoji="<?php echo $emoji; ?>"><?php echo $emoji; ?></div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="avatar_emoji" id="selectedAvatar" value="<?php echo $userAvatar; ?>">
            <input type="hidden" name="update_avatar" value="1">
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeStickerPicker()">Cancel</button>
                <button type="submit" class="btn-primary">Save Avatar</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {

    // Sticker picker
    const stickers      = document.querySelectorAll('.sticker-option');
    const selectedInput = document.getElementById('selectedAvatar');
    stickers.forEach(sticker => {
        sticker.addEventListener('click', () => {
            stickers.forEach(s => s.classList.remove('selected'));
            sticker.classList.add('selected');
            selectedInput.value = sticker.getAttribute('data-emoji');
        });
    });

    // Ring animation
    const ring = document.querySelector('.ring-fill');
    if (ring) {
        const pct  = parseFloat(ring.getAttribute('data-pct')) || 0;
        const circ = 2 * Math.PI * 48;
        ring.style.strokeDasharray  = circ;
        ring.style.strokeDashoffset = circ;
        setTimeout(() => { ring.style.strokeDashoffset = circ - (pct / 100) * circ; }, 120);
    }

    // Bar animations
    document.querySelectorAll('.water-fill, .tbl-fill').forEach(el => {
        const w = el.style.width;
        el.style.width = '0%';
        setTimeout(() => { el.style.width = w; }, 150);
    });

    // ApexCharts
    const chartEl = document.getElementById('hydroChart');
    if (chartEl) {
        new ApexCharts(chartEl, {
            series: [
                { name: 'Consumed (L)', type: 'bar',  data: <?php echo $chartConsumed; ?> },
                { name: 'Goal (L)',     type: 'line', data: <?php echo $chartGoal; ?>     }
            ],
            chart: {
                height: 380, type: 'line',
                background: 'transparent',
                toolbar: { show: false },
                fontFamily: 'Nunito, sans-serif',
                animations: { enabled: true, easing: 'easeinout', speed: 900 }
            },
            stroke:      { width: [0, 3], curve: 'smooth' },
            plotOptions: { bar: { borderRadius: 10, columnWidth: '45%' } },
            fill: {
                type: ['gradient', 'solid'],
                gradient: { shade: 'light', type: 'vertical', opacityFrom: 0.9, opacityTo: 0.4, gradientToColors: ['#7ECCEF'] }
            },
            colors:  ['#53B8E8', '#239DDA'],
            markers: { size: [0, 5], colors: ['#239DDA'], strokeColors: '#fff', strokeWidth: 2 },
            xaxis: {
                categories: <?php echo $chartLabels; ?>,
                labels: { style: { colors: '#6B9AB0', fontSize: '11px', fontWeight: 600 }, rotate: -30 },
                axisBorder: { show: false }, axisTicks: { show: false }
            },
            yaxis: { labels: { style: { colors: '#6B9AB0' }, formatter: v => v.toFixed(1) + 'L' }, min: 0 },
            grid:  { borderColor: 'rgba(83,184,232,0.2)', strokeDashArray: 4, xaxis: { lines: { show: false } } },
            tooltip: {
                shared: true, intersect: false, theme: 'light',
                y: { formatter: v => v + 'L' },
                style: { fontFamily: 'Nunito, sans-serif' }
            },
            legend: {
                position: 'top', horizontalAlign: 'right',
                labels: { colors: '#2C5F7C' },
                markers: { radius: 6 },
                fontWeight: 600, fontSize: '12px'
            },
            dataLabels: { enabled: false }
        }).render();
    }
});

// ── Cup Counter ──
const CUP_ML   = 250;
const MAX_CUPS = 20;
let cups = 0;

function changeCups(delta) {
    cups = Math.max(0, Math.min(MAX_CUPS, cups + delta));
    updateCupDisplay();
}

function updateCupDisplay() {
    const liters = (cups * CUP_ML / 1000).toFixed(2);
    document.getElementById("cupCount").textContent  = cups;
    document.getElementById("cupLiters").textContent = liters + "L";
    document.getElementById("waterIntakeVal").value  = liters;
    const iconBox = document.getElementById("cupIcons");
    if (iconBox) {
        iconBox.innerHTML = "";
        for (let i = 0; i < Math.min(cups, 12); i++) {
            const s = document.createElement("span");
            s.className = "cup-icon"; s.textContent = "🥤";
            iconBox.appendChild(s);
        }
        if (cups > 12) {
            const m = document.createElement("span");
            m.className = "cup-more"; m.textContent = "+" + (cups - 12) + " more";
            iconBox.appendChild(m);
        }
    }
    const countEl = document.getElementById("cupCount");
    if (countEl) {
        countEl.style.color = cups === 0 ? "#A8C8D8" :
                              cups <= 4  ? "#E74C3C" :
                              cups <= 8  ? "#F39C12" : "#2ECC71";
    }
}

function validateCups() {
    if (cups === 0) { alert("Please add at least 1 cup of water! 💧"); return false; }
    return true;
}

function openStickerPicker()  { document.getElementById('stickerModal').classList.add('active'); }
function closeStickerPicker() { document.getElementById('stickerModal').classList.remove('active'); }
window.onclick = function(e) {
    const modal = document.getElementById('stickerModal');
    if (e.target === modal) closeStickerPicker();
}
</script>
</body>
</html>