<?php
include 'db.php';
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if ($username === "" || $password === "") {
        $message = "Username and password cannot be empty.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, email, password, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['email'] = $user['email'];

                authLogWrite($conn, [
                    'user_id' => (int) $user['id'],
                    'username' => (string) $user['username'],
                    'role' => (string) $user['role'],
                    'identity' => (string) $username,
                    'action' => 'login_success',
                    'session_id' => session_id(),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ]);

                switch ($user['role']) {
                    case "admin":
                    case "superadmin":
                        header("Location: admin_dashboard.php");
                        exit;
                    case "assistant":
                    case "librarian":
                        header("Location: admin_dashboard.php");
                        exit;
                    case "faculty":
                    case "student":
                        header("Location: seatmap.php");
                        exit;
                    default:
                        $message = "Undefined role. Contact administrator.";
                }
            } else {
                authLogWrite($conn, [
                    'user_id' => (int) $user['id'],
                    'username' => (string) $user['username'],
                    'role' => (string) $user['role'],
                    'identity' => (string) $username,
                    'action' => 'login_failed',
                    'session_id' => session_id(),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ]);
                $message = "Invalid username or password.";
            }
        } else {
            authLogWrite($conn, [
                'identity' => (string) $username,
                'action' => 'login_failed',
                'session_id' => session_id(),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);
            $message = "Invalid username or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — St. Clare College of Caloocan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;600&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --gold:        #c8a96e;
            --gold-light:  #e2c99a;
            --dark: #1a1f27;
            --panel-bg:    rgba(255, 255, 255, 0.07);
            --input-bg:    rgba(255, 255, 255, 0.09);
            --input-border:rgba(200, 169, 110, 0.35);
            --text-main:   #f0ece4;
            --text-muted:  rgba(240, 236, 228, 0.55);
            --error:       #f28b8b;
            --success:     #7ec8a0;
            --radius:      14px;
            --transition:  0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        html, body {
            height: 100%;
            width: 100%;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--dark);
            overflow: hidden;
        }
        .bg-layer {
            position: fixed;
            inset: 0;
            background-image: url('bg.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            z-index: 0;
            filter: brightness(1.1);
        }
        .overlay {
            position: fixed;
            inset: 0;
            background: linear-gradient(
                to right,
                rgba(10, 14, 20, 0.97) 0%,
                rgba(10, 14, 20, 0.88) 30%,
                rgba(10, 14, 20, 0.55) 58%,
                rgba(10, 14, 20, 0.18) 80%,
                rgba(10, 14, 20, 0.05) 100%
            );
            z-index: 1;
        }
        .page {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: stretch;
            height: 100vh;
            width: 100%;
        }
        .brand-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 64px;
            max-width: 560px;
        }

        .brand-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 36px;
            opacity: 0;
            animation: fadeUp 0.7s 0.1s ease forwards;
        }

        .brand-tag::before {
            content: '';
            display: block;
            width: 28px;
            height: 1px;
            background: var(--gold);
        }

        .brand-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(38px, 5vw, 62px);
            font-weight: 300;
            color: var(--text-main);
            line-height: 1.12;
            opacity: 0;
            animation: fadeUp 0.7s 0.25s ease forwards;
        }

        .brand-title em {
            font-style: italic;
            color: var(--gold-light);
        }

        .brand-sub {
            margin-top: 20px;
            font-size: 14px;
            line-height: 1.75;
            color: var(--text-muted);
            max-width: 340px;
            opacity: 0;
            animation: fadeUp 0.7s 0.4s ease forwards;
        }

        .divider {
            width: 48px;
            height: 1px;
            background: linear-gradient(to right, var(--gold), transparent);
            margin: 32px 0;
            opacity: 0;
            animation: fadeUp 0.7s 0.55s ease forwards;
        }

        .brand-meta {
            font-size: 12px;
            letter-spacing: 1px;
            color: var(--text-muted);
            text-transform: uppercase;
            opacity: 0;
            animation: fadeUp 0.7s 0.65s ease forwards;
        }
        .form-panel {
            width: 420px;
            min-width: 340px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 24px;
            margin-right: 5vw;
        }

        .form-card {
            width: 100%;
            max-width: 380px;
            background: rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(24px) saturate(160%);
            -webkit-backdrop-filter: blur(24px) saturate(160%);
            border: 1px solid rgba(200, 169, 110, 0.18);
            border-radius: 22px;
            padding: 44px 40px 40px;
            box-shadow:
                0 8px 40px rgba(0, 0, 0, 0.45),
                0 1px 0 rgba(255,255,255,0.06) inset;
            opacity: 0;
            transform: translateY(24px);
            animation: cardIn 0.75s 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
        .logo-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 28px;
        }

        .logo-ring {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: rgba(200, 169, 110, 0.1);
            border: 1.5px solid rgba(200, 169, 110, 0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
            box-shadow: 0 0 0 6px rgba(200, 169, 110, 0.06);
        }

        .logo-ring img {
            width: 52px;
            height: 52px;
            object-fit: contain;
            filter: drop-shadow(0 2px 6px rgba(200,169,110,0.3));
        }

        .school-name {
            font-family: 'Cormorant Garamond', serif;
            font-size: 15px;
            font-weight: 600;
            color: var(--gold-light);
            letter-spacing: 0.5px;
            text-align: center;
        }
        .form-heading {
            font-family: 'Cormorant Garamond', serif;
            font-size: 28px;
            font-weight: 300;
            color: var(--text-main);
            text-align: center;
            margin-bottom: 6px;
        }

        .form-subheading {
            font-size: 12.5px;
            color: var(--text-muted);
            text-align: center;
            margin-bottom: 28px;
            letter-spacing: 0.3px;
        }
        .message {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            padding: 11px 14px;
            border-radius: 9px;
            margin-bottom: 20px;
            background: rgba(242, 139, 139, 0.12);
            border: 1px solid rgba(242, 139, 139, 0.28);
            color: var(--error);
        }

        .message.success {
            background: rgba(126, 200, 160, 0.12);
            border-color: rgba(126, 200, 160, 0.28);
            color: var(--success);
        }

        .message::before {
            content: '⚠';
            font-size: 14px;
            flex-shrink: 0;
        }

        .message.success::before {
            content: '✓';
        }
        .field {
            position: relative;
            margin-bottom: 16px;
        }

        .field label {
            display: block;
            font-size: 11.5px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 7px;
            font-weight: 500;
        }

        .field input {
            width: 100%;
            padding: 13px 16px;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 10px;
            color: var(--text-main);
            font-family: 'DM Sans', sans-serif;
            font-size: 14.5px;
            outline: none;
            transition: border-color var(--transition), box-shadow var(--transition), background var(--transition);
        }

        .field input::placeholder {
            color: rgba(240, 236, 228, 0.3);
        }

        .field input:focus {
            border-color: var(--gold);
            background: rgba(255, 255, 255, 0.12);
            box-shadow: 0 0 0 3px rgba(200, 169, 110, 0.15), 0 2px 8px rgba(0,0,0,0.2);
        }
        .field .toggle-pw {
            position: absolute;
            right: 14px;
            bottom: 14px;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 16px;
            line-height: 1;
            transition: color var(--transition);
            user-select: none;
        }

        .field .toggle-pw:hover {
            color: var(--gold);
        }
        .btn-login {
            width: 100%;
            padding: 14px;
            margin-top: 6px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #c8a96e 0%, #a07840 100%);
            color: #1a1208;
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            font-weight: 500;
            letter-spacing: 1px;
            text-transform: uppercase;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform var(--transition), box-shadow var(--transition), filter var(--transition);
            box-shadow: 0 4px 20px rgba(200, 169, 110, 0.3);
        }

        .btn-login::after {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0);
            transition: background var(--transition);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(200, 169, 110, 0.45);
            filter: brightness(1.08);
        }

        .btn-login:active {
            transform: translateY(0);
            box-shadow: 0 3px 12px rgba(200, 169, 110, 0.25);
        }
        .register-link {
            text-align: center;
            margin-top: 22px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .register-link a {
            color: var(--gold-light);
            text-decoration: none;
            font-weight: 500;
            border-bottom: 1px solid transparent;
            transition: border-color var(--transition), color var(--transition);
        }

        .register-link a:hover {
            color: var(--gold);
            border-bottom-color: var(--gold);
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes cardIn {
            to { opacity: 1; transform: translateY(0); }
        }
        @media (max-width: 860px) {
            body { overflow: auto; }

            .overlay {
                background: linear-gradient(
                    to bottom,
                    rgba(10, 14, 20, 0.85) 0%,
                    rgba(10, 14, 20, 0.60) 100%
                );
            }

            .page {
                flex-direction: column;
                justify-content: flex-start;
                align-items: center;
                height: auto;
                min-height: 100vh;
                padding: 40px 20px 48px;
            }

            .brand-panel {
                max-width: 100%;
                padding: 0;
                text-align: center;
                margin-bottom: 32px;
            }

            .brand-tag {
                justify-content: center;
            }

            .brand-tag::before { display: none; }

            .brand-sub {
                max-width: 100%;
            }

            .form-panel {
                width: 100%;
                max-width: 420px;
                margin-right: 0;
                padding: 0;
            }

            .form-card {
                padding: 36px 28px 32px;
            }
        }

        @media (max-width: 400px) {
            .form-card {
                padding: 28px 20px 24px;
                border-radius: 16px;
            }
        }
    </style>
</head>

<body>

    <div class="bg-layer"></div>
    <div class="overlay"></div>

    <div class="page">
        <div class="brand-panel">
            <span class="brand-tag">Library Management System</span>
            <h1 class="brand-title">A Space to<br><em>Learn & Grow</em></h1>
            <p class="brand-sub">
                Access curated resources, reserve your study space,
                and connect with the knowledge you need — all in one place.
            </p>
            <div class="divider"></div>
            <p class="brand-meta">St. Clare College of Caloocan &nbsp;·&nbsp; Est. 1969</p>
        </div>

        <div class="form-panel">
            <div class="form-card">

                <div class="logo-section">
                    <div class="logo-ring">
                        <img src="logo.png" alt="St. Clare College of Caloocan Logo">
                    </div>
                    <p class="school-name">St. Clare College of Caloocan</p>
                </div>

                <h2 class="form-heading">Welcome Back</h2>
                <p class="form-subheading">Sign in to continue to your account</p>

                <?php if (isset($message_admin)) { ?>
                    <div class="message success"><?= htmlspecialchars($message_admin) ?></div>
                <?php } ?>

                <?php if (isset($message)) { ?>
                    <div class="message"><?= htmlspecialchars($message) ?></div>
                <?php } ?>

                <form method="POST" autocomplete="on">

                    <div class="field">
                        <label for="username">Username</label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Enter your username"
                            autocomplete="username"
                            required>
                    </div>

                    <div class="field">
                        <label for="password">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required>
                        <span class="toggle-pw" id="togglePw" title="Show / hide password">👁</span>
                    </div>

                    <button type="submit" name="login" class="btn-login">Sign In</button>
                </form>

                <p class="register-link">
                    Don't have an account? <a href="register.php">Register here</a>
                </p>

            </div>
        </div>

    </div>

    <script>
        const togglePw = document.getElementById('togglePw');
        const pwInput  = document.getElementById('password');

        togglePw.addEventListener('click', () => {
            const isText = pwInput.type === 'text';
            pwInput.type = isText ? 'password' : 'text';
            togglePw.textContent = isText ? '👁' : '🙈';
        });
    </script>

</body>
</html>