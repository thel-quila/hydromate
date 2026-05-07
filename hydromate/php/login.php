<?php
session_start();

$conn = new mysqli("localhost", "thel", "helloxampp", "hydro_db");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$message  = "";
$msgType  = "";
$loginMode = $_POST['login_mode'] ?? $_GET['mode'] ?? 'user'; // 'user' or 'admin'

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $username  = trim($_POST['username']);
    $password  = $_POST['password'];
    $loginMode = $_POST['login_mode'] ?? 'user';

    if ($loginMode === 'admin') {
        // ── ADMIN LOGIN: username + password only, must be is_admin = 1 ──
        $stmt = $conn->prepare("SELECT * FROM user WHERE username = ? AND is_admin = 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $passwordOk = password_verify($password, $user['password']) || $password === $user['password'];

            if ($passwordOk) {
                $_SESSION['user_id']  = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_admin'] = 1;
                header("Location: admin.php");
                exit();
            } else {
                $msgType = "error"; $message = "Incorrect password.";
            }
        } else {
            $msgType = "error"; $message = "Admin account not found.";
        }
        $stmt->close();

    } else {
        // ── USER LOGIN: username + email + password ──
        $email = trim($_POST['email'] ?? '');
        $stmt  = $conn->prepare("SELECT * FROM user WHERE username = ? AND email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $passwordOk = password_verify($password, $user['password']) || $password === $user['password'];

            if ($passwordOk) {
                $_SESSION['user_id']  = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_admin'] = $user['is_admin'] ?? 0;
                header("Location: dash.php");
                exit();
            } else {
                $msgType = "error"; $message = "Incorrect password.";
            }
        } else {
            $msgType = "error"; $message = "User not found. Check your username or email.";
        }
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HydroMate — Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/styleslogin.css">
</head>
<body>

<div class="login-page">

    <!-- LEFT PANEL -->
    <div class="login-left">
        <div class="login-brand">
            <div class="brand-drop">💧</div>
            <div>
                <h1>HydroMate</h1>
                <p>Your personal hydration tracker</p>
            </div>
        </div>
        <div class="login-tagline">
            <h2>Stay hydrated,<br>stay healthy.</h2>
            <p>Track your daily water intake, get personalized recommendations, and let AI predict your hydration status.</p>
        </div>
        <div class="login-features">
            <div class="feat-item"><span>💧</span> Daily intake tracking</div>
            <div class="feat-item"><span>🤖</span> ML hydration prediction</div>
            <div class="feat-item"><span>📊</span> 7-day history graph</div>
            <div class="feat-item"><span>🔔</span> Smart alerts</div>
        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="login-right">
        <div class="login-card">

            <!-- ── TABS ── -->
            <div class="login-tabs">
                <button class="tab-btn <?php echo $loginMode === 'user'  ? 'active' : ''; ?>"
                    onclick="switchTab('user')">👤 User</button>
                <button class="tab-btn <?php echo $loginMode === 'admin' ? 'active' : ''; ?>"
                    onclick="switchTab('admin')">🛡 Admin</button>
            </div>

            <div id="tab-title">
                <h2 id="login-title"><?php echo $loginMode === 'admin' ? 'Admin Login' : 'Welcome back!'; ?></h2>
                <p class="login-sub" id="login-sub"><?php echo $loginMode === 'admin' ? 'Sign in to the admin panel' : 'Sign in to your account'; ?></p>
            </div>

            <!-- ALERT -->
            <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $msgType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <!-- FORM -->
            <form action="login.php" method="POST" class="login-form" id="loginForm">
                <input type="hidden" name="login_mode" id="login_mode" value="<?php echo htmlspecialchars($loginMode); ?>">

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                        placeholder="Enter your username" required
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>

                <!-- Email field — hidden for admin -->
                <div class="form-group" id="email-group" style="<?php echo $loginMode === 'admin' ? 'display:none' : ''; ?>">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email"
                        placeholder="Enter your email"
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrap">
                        <input type="password" id="password" name="password"
                            placeholder="Enter your password" required>
                        <button type="button" class="toggle-pw" onclick="togglePassword()"></button>
                    </div>
                </div>

                <button type="submit" class="btn-login" id="login-btn">
                    <?php echo $loginMode === 'admin' ? '🛡 Sign In as Admin →' : 'Sign In →'; ?>
                </button>
            </form>

            <p class="login-footer" id="signup-link" style="<?php echo $loginMode === 'admin' ? 'display:none' : ''; ?>">
                Don't have an account? <a href="registration.php">Sign up</a>
            </p>

        </div>
    </div>
</div>

<script>
function switchTab(mode) {
    document.getElementById('login_mode').value = mode;

    // Update tabs
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    event.target.classList.add('active');

    const isAdmin = mode === 'admin';

    // Update titles
    document.getElementById('login-title').textContent = isAdmin ? 'Admin Login'        : 'Welcome back!';
    document.getElementById('login-sub').textContent   = isAdmin ? 'Sign in to the admin panel' : 'Sign in to your account';

    // Toggle email field
    document.getElementById('email-group').style.display = isAdmin ? 'none' : '';
    document.getElementById('email').required             = !isAdmin;

    // Toggle button text
    document.getElementById('login-btn').textContent = isAdmin ? '🛡 Sign In as Admin →' : 'Sign In →';

    // Toggle signup link
    document.getElementById('signup-link').style.display = isAdmin ? 'none' : '';
}

function togglePassword() {
    const pw  = document.getElementById("password");
    const btn = document.querySelector(".toggle-pw");
    if (pw.type === "password") { pw.type = "text";     btn.textContent = ""; }
    else                        { pw.type = "password"; btn.textContent = ""; }
}
</script>
</body>
</html>