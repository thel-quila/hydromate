<?php
$conn = new mysqli("localhost", "thel", "helloxampp", "hydro_db");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$message = "";
$msgType = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fname    = trim($_POST['fname']);
    $lname    = trim($_POST['lname']);
    $mname    = trim($_POST['mname']);
    $bday     = $_POST['bday'];
    $gender   = $_POST['gender'];
    $email    = trim($_POST['email']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    if ($password !== $confirm) {
        $msgType = "error"; $message = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $msgType = "error"; $message = "Password must be at least 6 characters.";
    } else {
        $check = $conn->prepare("SELECT user_id FROM user WHERE username = ? OR email = ?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $msgType = "error"; $message = "Username or email already taken.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "INSERT INTO user (first_name, last_name, mid_name, birthday, gender, email, username, password)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("ssssssss", $fname, $lname, $mname, $bday, $gender, $email, $username, $hashedPassword);
            if ($stmt->execute()) {
                $msgType = "success"; $message = "Account created successfully! You can now log in. 🎉";
            } else {
                $msgType = "error"; $message = "Error: " . $stmt->error;
            }
            $stmt->close();
        }
        $check->close();
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HydroMate — Register</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/stylesreg.css">
</head>
<body>
<div class="reg-page">

    <!-- LEFT -->
    <div class="reg-left">
        <div class="reg-brand">
            <div class="brand-drop">💧</div>
            <div>
                <h1>HydroMate</h1>
                <p>Your personal hydration tracker</p>
            </div>
        </div>
        <div class="reg-tagline">
            <h2>Start your<br>hydration journey.</h2>
            <p>Create your account and let HydroMate help you stay healthy every single day.</p>
        </div>
        <div class="reg-steps">
            <div class="step-item"><div class="step-num">1</div><span>Create your account</span></div>
            <div class="step-item"><div class="step-num">2</div><span>Log your daily water intake</span></div>
            <div class="step-item"><div class="step-num">3</div><span>Get AI-powered insights</span></div>
        </div>
    </div>

    <!-- RIGHT -->
    <div class="reg-right">
        <div class="reg-card">
            <h2>Create Account</h2>
            <p class="reg-sub">Fill in your details to get started</p>

            <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $msgType; ?>">
                <?php echo htmlspecialchars($message); ?>
                <?php if ($msgType === 'success'): ?>
                <a href="login.php" class="alert-link">Go to Login →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <form action="" method="POST" class="reg-form">

                <div class="form-row">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="fname" placeholder="e.g. Maria" required
                            value="<?php echo isset($_POST['fname']) ? htmlspecialchars($_POST['fname']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="lname" placeholder="e.g. Santos" required
                            value="<?php echo isset($_POST['lname']) ? htmlspecialchars($_POST['lname']) : ''; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Middle Name <span class="optional">(optional)</span></label>
                    <input type="text" name="mname" placeholder="e.g. Cruz"
                        value="<?php echo isset($_POST['mname']) ? htmlspecialchars($_POST['mname']) : ''; ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Birthday</label>
                        <input type="date" name="bday" required
                            value="<?php echo isset($_POST['bday']) ? htmlspecialchars($_POST['bday']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Gender</label>
                        <div class="gender-toggle">
                            <label class="gender-opt">
                                <input type="radio" name="gender" value="F"
                                    <?php echo (isset($_POST['gender']) && $_POST['gender']=='F') ? 'checked' : ''; ?> required>
                                <span>👩 Female</span>
                            </label>
                            <label class="gender-opt">
                                <input type="radio" name="gender" value="M"
                                    <?php echo (isset($_POST['gender']) && $_POST['gender']=='M') ? 'checked' : ''; ?>>
                                <span>👨 Male</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="e.g. maria@email.com" required
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="Choose a username" required
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Password</label>
                        <div class="password-wrap">
                            <input type="password" id="pw1" name="password" placeholder="Min. 6 characters" required>
                            <button type="button" class="toggle-pw" onclick="togglePw('pw1',this)"></button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <div class="password-wrap">
                            <input type="password" id="pw2" name="confirm_password" placeholder="Repeat password" required>
                            <button type="button" class="toggle-pw" onclick="togglePw('pw2',this)"></button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-register">Create Account →</button>
            </form>

            <p class="reg-footer">Already have an account? <a href="login.php">Sign in</a></p>
        </div>
    </div>
</div>

<script>
function togglePw(id, btn) {
    const input = document.getElementById(id);
    if (input.type === 'password') { input.type = 'text';     btn.textContent = '🙈'; }
    else                           { input.type = 'password'; btn.textContent = '👁'; }
}
</script>
</body>
</html>