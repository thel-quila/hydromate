<?php
session_start();


if (!isset($_SESSION['user_id']) || $_SESSION['is_admin'] != 1) {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "thel", "helloxampp", "hydro_db");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$adminName = $_SESSION['username'];
$section   = $_GET['section'] ?? 'overview';


if (isset($_GET['delete_user'])) {
    $uid = (int)$_GET['delete_user'];
    $conn->query("DELETE FROM prediction WHERE user_id = $uid");
    $conn->query("DELETE FROM dataset    WHERE user_id = $uid");
    $conn->query("DELETE FROM user_log   WHERE user_id = $uid");
    $conn->query("DELETE FROM user       WHERE user_id = $uid");
    header("Location: admin.php?section=users&msg=deleted");
    exit();
}

if (isset($_GET['toggle_admin'])) {
    $uid     = (int)$_GET['toggle_admin'];
    $current = (int)$_GET['current'];
    $newVal  = $current == 1 ? 0 : 1;
    $conn->query("UPDATE user SET is_admin = $newVal WHERE user_id = $uid");
    header("Location: admin.php?section=users");
    exit();
}

$totalUsers   = $conn->query("SELECT COUNT(*) FROM user WHERE is_admin = 0")->fetch_row()[0];
$totalPreds   = $conn->query("SELECT COUNT(*) FROM prediction")->fetch_row()[0];
$hydrated     = $conn->query("SELECT COUNT(*) FROM prediction WHERE hydration_result = 1")->fetch_row()[0];
$notHydrated  = $conn->query("SELECT COUNT(*) FROM prediction WHERE hydration_result = 0")->fetch_row()[0];
$totalDataset = $conn->query("SELECT COUNT(*) FROM dataset")->fetch_row()[0];

$recentLogs = [];
$rRes = $conn->query(
    "SELECT u.username, ul.action, ul.add_water, ul.timestamp
     FROM user_log ul
     JOIN user u ON u.user_id = ul.user_id
     WHERE ul.action = 'add'
     ORDER BY ul.timestamp DESC LIMIT 5"
);
while ($row = $rRes->fetch_assoc()) $recentLogs[] = $row;


$dailyStats = [];
$dRes = $conn->query(
    "SELECT DATE(d.date_created) as day,
            COUNT(*) as total,
            SUM(p.hydration_result) as good
     FROM prediction p
     JOIN dataset d ON d.dataset_id = p.dataset_id
     WHERE d.date_created >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(d.date_created)
     ORDER BY day ASC"
);
while ($row = $dRes->fetch_assoc()) $dailyStats[] = $row;

$chartDays  = json_encode(array_map(fn($r) => date('M d', strtotime($r['day'])), $dailyStats));
$chartTotal = json_encode(array_column($dailyStats, 'total'));
$chartGood  = json_encode(array_column($dailyStats, 'good'));


$users = [];
$uRes = $conn->query(
    "SELECT u.user_id, u.first_name, u.last_name, u.username, u.email,
            u.gender, u.birthday, u.is_admin,
            COUNT(DISTINCT d.dataset_id) as total_logs,
            COUNT(DISTINCT p.pred_id) as total_preds
     FROM user u
     LEFT JOIN dataset    d ON d.user_id = u.user_id
     LEFT JOIN prediction p ON p.user_id = u.user_id
     GROUP BY u.user_id
     ORDER BY u.user_id DESC"
);
while ($row = $uRes->fetch_assoc()) $users[] = $row;


$predLogs = [];
$pRes = $conn->query(
    "SELECT u.username, u.gender,
            d.age, d.weight, d.activity_level, d.weather, d.date_created,
            ul.add_water, p.hydration_result, p.pred_id
     FROM prediction p
     JOIN dataset  d  ON d.dataset_id  = p.dataset_id
     JOIN user     u  ON u.user_id     = p.user_id
     LEFT JOIN user_log ul ON ul.user_id = p.user_id
                           AND ul.add_age = d.age
                           AND DATE(ul.timestamp) = d.date_created
                           AND ul.action = 'add'
     ORDER BY p.pred_id DESC
     LIMIT 50"
);
while ($row = $pRes->fetch_assoc()) $predLogs[] = $row;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HydroMate — Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <link rel="stylesheet" href="../css/styleadmin.css">
</head>
<body>


<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">💧</div>
        <div class="brand-text">
            <span class="brand-name">HydroMate</span>
            <span class="brand-sub">Admin Panel</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <a href="?section=overview"  class="nav-item <?php echo $section=='overview' ?'active':''; ?>">
            <span class="nav-icon">⊞</span><span>Overview</span>
        </a>
        <a href="?section=users"     class="nav-item <?php echo $section=='users'    ?'active':''; ?>">
            <span class="nav-icon">👥</span><span>Users</span>
        </a>
        <a href="?section=predictions" class="nav-item <?php echo $section=='predictions'?'active':''; ?>">
            <span class="nav-icon">🤖</span><span>Predictions</span>
        </a>
    </nav>
    <div class="sidebar-bottom">
        <div class="admin-chip">
            <div class="user-avatar"><?php echo strtoupper(substr($adminName,0,1)); ?></div>
            <div>
                <span class="admin-name"><?php echo htmlspecialchars($adminName); ?></span>
                <span class="admin-role">Administrator</span>
            </div>
        </div>
        <a href="login.php" class="sidebar-logout"><span>↩</span><span>Logout</span></a>
    </div>
</aside>


<main class="main">

    <?php if ($section === 'overview'): ?>
   
    <div class="page-header">
        <h1>Dashboard Overview</h1>
        <p>Welcome back, <?php echo htmlspecialchars($adminName); ?>! Here's what's happening.</p>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon-wrap blue">💧</div>
            <div class="stat-info">
                <span class="stat-num"><?php echo $totalUsers; ?></span>
                <span class="stat-lbl">Total Users</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon-wrap green">🤖</div>
            <div class="stat-info">
                <span class="stat-num"><?php echo $totalPreds; ?></span>
                <span class="stat-lbl">Total Predictions</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon-wrap teal">✅</div>
            <div class="stat-info">
                <span class="stat-num"><?php echo $hydrated; ?></span>
                <span class="stat-lbl">Hydrated Results</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon-wrap red">❌</div>
            <div class="stat-info">
                <span class="stat-num"><?php echo $notHydrated; ?></span>
                <span class="stat-lbl">Not Hydrated</span>
            </div>
        </div>
    </div>

 
    <div class="overview-row">
        <div class="card card-chart">
            <p class="card-label">📊 7-Day Prediction Activity</p>
            <div id="adminChart"></div>
        </div>

        <div class="card card-recent">
            <p class="card-label">🕐 Recent Activity</p>
            <?php if (count($recentLogs) > 0): ?>
            <ul class="recent-list">
                <?php foreach ($recentLogs as $log): ?>
                <li class="recent-item">
                    <div class="recent-avatar"><?php echo strtoupper(substr($log['username'],0,1)); ?></div>
                    <div class="recent-info">
                        <strong><?php echo htmlspecialchars($log['username']); ?></strong>
                        logged <?php echo $log['add_water']; ?>L
                        <span><?php echo date('M d, h:i A', strtotime($log['timestamp'])); ?></span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <div class="empty-state"><span>📭</span><p>No recent activity</p></div>
            <?php endif; ?>
        </div>
    </div>

 
    <div class="card card-ratio">
        <p class="card-label">💧 Overall Hydration Ratio</p>
        <div class="ratio-wrap">
            <div class="ratio-bar-wrap">
                <div class="ratio-label">
                    <span>✅ Hydrated</span>
                    <span><?php echo $totalPreds > 0 ? round(($hydrated/$totalPreds)*100) : 0; ?>%</span>
                </div>
                <div class="ratio-bar">
                    <div class="ratio-fill green" style="width:<?php echo $totalPreds > 0 ? round(($hydrated/$totalPreds)*100) : 0; ?>%"></div>
                </div>
            </div>
            <div class="ratio-bar-wrap">
                <div class="ratio-label">
                    <span>❌ Not Hydrated</span>
                    <span><?php echo $totalPreds > 0 ? round(($notHydrated/$totalPreds)*100) : 0; ?>%</span>
                </div>
                <div class="ratio-bar">
                    <div class="ratio-fill red" style="width:<?php echo $totalPreds > 0 ? round(($notHydrated/$totalPreds)*100) : 0; ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <?php elseif ($section === 'users'): ?>
  
    <div class="page-header">
        <h1>User Management</h1>
        <p><?php echo count($users); ?> registered accounts</p>
    </div>

    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
    <div class="alert alert-success">User deleted successfully.</div>
    <?php endif; ?>

    <div class="card">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Gender</th>
                    <th>Logs</th>
                    <th>Predictions</th>
                    <th>Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?php echo $u['user_id']; ?></td>
                    <td>
                        <div class="user-row">
                            <div class="tbl-avatar"><?php echo strtoupper(substr($u['first_name'],0,1)); ?></div>
                            <?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($u['username']); ?></td>
                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                    <td><?php echo $u['gender'] == 'M' ? 'Male' : 'Female'; ?></td>
                    <td><?php echo $u['total_logs']; ?></td>
                    <td><?php echo $u['total_preds']; ?></td>
                    <td>
                        <span class="role-badge <?php echo $u['is_admin'] ? 'admin' : 'user'; ?>">
                            <?php echo $u['is_admin'] ? '🛡 Admin' : '👤 User'; ?>
                        </span>
                    </td>
                    <td class="action-btns">
                        <a href="?section=users&toggle_admin=<?php echo $u['user_id']; ?>&current=<?php echo $u['is_admin']; ?>"
                           class="btn-sm btn-toggle"
                           onclick="return confirm('Toggle admin role for this user?')">
                            <?php echo $u['is_admin'] ? 'Revoke Admin' : 'Make Admin'; ?>
                        </a>
                        <?php if ($u['user_id'] != $_SESSION['user_id']): ?>
                        <a href="?section=users&delete_user=<?php echo $u['user_id']; ?>"
                           class="btn-sm btn-delete"
                           onclick="return confirm('Delete this user and all their data? This cannot be undone.')">
                            Delete
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php elseif ($section === 'predictions'): ?>

    <div class="page-header">
        <h1>Prediction Logs</h1>
        <p>Last <?php echo count($predLogs); ?> predictions across all users</p>
    </div>

    <div class="card">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Date</th>
                    <th>Age</th>
                    <th>Weight</th>
                    <th>Consumed</th>
                    <th>Activity</th>
                    <th>Weather</th>
                    <th>🤖 ML Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($predLogs as $p): ?>
                <tr>
                    <td><?php echo $p['pred_id']; ?></td>
                    <td>
                        <div class="user-row">
                            <div class="tbl-avatar"><?php echo strtoupper(substr($p['username'],0,1)); ?></div>
                            <?php echo htmlspecialchars($p['username']); ?>
                        </div>
                    </td>
                    <td><?php echo date('M d, H:i', strtotime($p['date_created'])); ?></td>
                    <td><?php echo $p['age']; ?></td>
                    <td><?php echo $p['weight']; ?>kg</td>
                    <td><?php echo $p['add_water'] ?? '—'; ?>L</td>
                    <td><?php echo ucfirst($p['activity_level']); ?></td>
                    <td><?php echo ucfirst($p['weather']); ?></td>
                    <td>
                        <span class="tbl-badge <?php echo $p['hydration_result'] ? 'hydrated' : 'not-hydrated'; ?>">
                            <?php echo $p['hydration_result'] ? '✅ Hydrated' : '❌ Not Hydrated'; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>

</main>

<script>
<?php if ($section === 'overview' && count($dailyStats) > 0): ?>
const adminChartOpts = {
    series: [
        { name: 'Total Checks', type: 'bar',  data: <?php echo $chartTotal; ?> },
        { name: 'Hydrated',     type: 'line', data: <?php echo $chartGood; ?>  },
    ],
    chart: {
        height: 240, type: 'line',
        background: 'transparent', toolbar: { show: false },
        fontFamily: 'DM Sans, sans-serif',
        animations: { enabled: true, easing: 'easeinout', speed: 800 },
    },
    stroke:  { width: [0, 3], curve: 'smooth' },
    plotOptions: { bar: { borderRadius: 8, columnWidth: '45%' } },
    fill: {
        type: ['gradient', 'solid'],
        gradient: { shade:'light', type:'vertical', opacityFrom:0.85, opacityTo:0.4, gradientToColors:['#b8e8f5'] }
    },
    colors: ['#3ab7d8', '#48d9a4'],
    markers: { size:[0,5], colors:['#48d9a4'], strokeColors:'#fff', strokeWidth:2 },
    xaxis: {
        categories: <?php echo $chartDays; ?>,
        labels: { style:{ colors:'#8ab8c8', fontSize:'11px', fontWeight:600 } },
        axisBorder:{ show:false }, axisTicks:{ show:false },
    },
    yaxis: { labels:{ style:{colors:'#8ab8c8'}, formatter: v => Math.round(v) }, min:0 },
    grid:  { borderColor:'rgba(180,225,240,0.2)', strokeDashArray:4, xaxis:{lines:{show:false}} },
    tooltip:{ shared:true, intersect:false, theme:'light', style:{fontFamily:'DM Sans, sans-serif'} },
    legend: { position:'top', horizontalAlign:'right', labels:{colors:'#4a7a8a'}, markers:{radius:6}, fontWeight:600, fontSize:'12px' },
    dataLabels: { enabled: false },
};
new ApexCharts(document.getElementById('adminChart'), adminChartOpts).render();

// Animate ratio bars
document.querySelectorAll('.ratio-fill').forEach(el => {
    const w = el.style.width; el.style.width = '0%';
    setTimeout(() => { el.style.width = w; }, 200);
});
<?php endif; ?>
</script>

</body>
</html>