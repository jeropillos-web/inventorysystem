<?php
require_once __DIR__ . '/config/auth.php';
if (auth_user()) { header('Location: dashboard.php'); exit; }

$error = '';
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$role = $_POST['role'] ?? 'staff';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        [$created, $message] = register_user($name, $email, $password, $role);
        if ($created) {
            header('Location: index.php?registered=1');
            exit;
        }
        $error = $message;
    }
}

$roles = [
    'admin' => ['Administrator', 'Full access; approves returns, disposals and transfers'],
    'staff' => ['Staff', 'Inventory, procurement, returns, transfer requests and reports'],
    'warehouse' => ['Warehouse / Delivery Staff', 'Shop orders, transfers, returns, tracking updates and the delivery app'],
];
// After the first administrator exists, the public sign-up page no longer offers the Administrator role.
if (!admin_signup_open()) unset($roles['admin']);
if (!isset($roles[$role])) $role = 'staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>INVTrack — Create Account</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="assets/theme.js"></script>
  <link rel="icon" type="image/png" href="assets/pcs-icon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" />
  <style>body{font-family:'Inter',sans-serif;}</style>
</head>
<body class="min-h-screen bg-pcs-950 flex items-center justify-center p-4 py-10">
  <div class="fixed inset-0 opacity-5 pointer-events-none" style="background-image:linear-gradient(rgba(82,177,106,0.5)1px,transparent 1px),linear-gradient(90deg,rgba(82,177,106,0.5)1px,transparent 1px);background-size:48px 48px"></div>

  <div class="w-full max-w-lg relative z-10">
    <div class="flex flex-col items-center mb-8">
      <img src="assets/pcs-logo.png" alt="PCS Computers" class="w-56 rounded-lg" />
      <div class="mt-4 text-white font-extrabold text-2xl tracking-tight leading-none">INV<span class="text-indigo-400">Track</span></div>
      <div class="text-pcs-300 text-xs mt-1">Inventory Management &amp; Logistics System</div>
    </div>

    <div class="bg-pcs-900 border border-pcs-800 rounded-2xl p-6 sm:p-8 shadow-2xl">
      <h1 class="text-white text-2xl font-bold mb-1">Create your account</h1>
      <p class="text-pcs-300 text-sm mb-7">Enter your details and select your operational role</p>

      <?php if ($error): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm rounded-xl px-4 py-3 mb-5">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="flex flex-col gap-5">
        <div>
          <label class="block text-sm font-medium text-pcs-100 mb-1.5">Full name</label>
          <input type="text" name="name" required autocomplete="name" value="<?= htmlspecialchars($name) ?>" placeholder="Juan Dela Cruz"
            class="w-full bg-pcs-800 border border-pcs-700 rounded-xl px-4 py-3 text-white placeholder-pcs-400 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition" />
        </div>

        <div>
          <label class="block text-sm font-medium text-pcs-100 mb-1.5">Email</label>
          <input type="email" name="email" required autocomplete="email" value="<?= htmlspecialchars($email) ?>" placeholder="you@company.com"
            class="w-full bg-pcs-800 border border-pcs-700 rounded-xl px-4 py-3 text-white placeholder-pcs-400 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition" />
        </div>

        <fieldset>
          <legend class="block text-sm font-medium text-pcs-100 mb-2">Account role</legend>
          <div class="grid gap-2">
            <?php foreach ($roles as $value => [$label, $description]): ?>
            <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 transition <?= $role === $value ? 'border-indigo-500 bg-indigo-500/10' : 'border-pcs-700 bg-pcs-800 hover:border-pcs-600' ?>">
              <input type="radio" name="role" value="<?= $value ?>" <?= $role === $value ? 'checked' : '' ?> class="mt-1 accent-indigo-500" />
              <span>
                <span class="block text-sm font-semibold text-white"><?= htmlspecialchars($label) ?></span>
                <span class="mt-0.5 block text-xs text-pcs-300"><?= htmlspecialchars($description) ?></span>
              </span>
            </label>
            <?php endforeach; ?>
          </div>
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-pcs-100 mb-1.5">Password</label>
            <input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters"
              class="w-full bg-pcs-800 border border-pcs-700 rounded-xl px-4 py-3 text-white placeholder-pcs-400 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition" />
          </div>
          <div>
            <label class="block text-sm font-medium text-pcs-100 mb-1.5">Confirm password</label>
            <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Repeat password"
              class="w-full bg-pcs-800 border border-pcs-700 rounded-xl px-4 py-3 text-white placeholder-pcs-400 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition" />
          </div>
        </div>

        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold rounded-xl py-3 text-sm transition shadow-lg shadow-indigo-500/30 mt-1">
          Create Account
        </button>
      </form>

      <div class="mt-6 pt-5 border-t border-pcs-800 text-center">
        <span class="text-sm text-pcs-300">Already have an account?</span>
        <a href="index.php" class="ml-1 text-sm font-semibold text-indigo-400 hover:text-indigo-300 hover:underline transition">Sign in</a>
      </div>
    </div>
  </div>
</body>
</html>
