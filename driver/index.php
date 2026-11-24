<?php
require_once __DIR__ . '/_boot.php';

if (driver_user()) { header('Location: app.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!db_available()) {
        $error = 'The server database is offline. Try again later.';
    } elseif (login($_POST['email'] ?? '', $_POST['password'] ?? '')) {
        if (driver_user()) { session_regenerate_id(true); header('Location: app.php'); exit; }
        unset($_SESSION['user']);
        $error = 'This account is not set up for deliveries. Use a Warehouse Personnel account.';
    } else {
        $error = 'Wrong email or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<link rel="manifest" href="manifest.webmanifest">
<title>INVTrack Driver</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="login">
<main class="login-card">
  <div class="brand"><span class="logo">IT</span><div><strong>INVTrack Driver</strong><small>PCS Computers deliveries</small></div></div>
  <h1>Sign in</h1>
  <?php if ($error): ?><p class="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <form method="post" class="stack">
    <label>Email<input type="email" name="email" autocomplete="username" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"></label>
    <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn primary big" type="submit">Sign in</button>
  </form>
  <p class="muted small">Use your INVTrack account. Ask an administrator if you don’t have one.</p>
</main>
</body>
</html>
