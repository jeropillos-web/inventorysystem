<?php
require_once __DIR__ . '/config/auth.php';
if (auth_user()) { header('Location: dashboard.php'); exit; }

$error = '';
$registered = isset($_GET['registered']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (login($email, $password)) {
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Invalid email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>INVTrack — Sign In</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="assets/theme.js"></script>
  <link rel="icon" type="image/png" href="assets/pcs-icon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" />
  <style>body{font-family:'Inter',sans-serif;}</style>
</head>
<body class="min-h-screen bg-pcs-950 flex items-center justify-center p-4">


  <div class="fixed inset-0 opacity-5 pointer-events-none" style="background-image:linear-gradient(rgba(82,177,106,0.5)1px,transparent 1px),linear-gradient(90deg,rgba(82,177,106,0.5)1px,transparent 1px);background-size:48px 48px"></div>

  <div class="w-full max-w-md relative z-10">

    <div class="flex flex-col items-center mb-8">
      <img src="assets/pcs-logo.png" alt="PCS Computers" class="w-56 rounded-lg" />
      <div class="mt-4 text-white font-extrabold text-2xl tracking-tight leading-none">INV<span class="text-indigo-400">Track</span></div>
      <div class="text-pcs-300 text-xs mt-1">Inventory Management &amp; Logistics System</div>
    </div>

    <div class="bg-pcs-900 border border-pcs-800 rounded-2xl p-8 shadow-2xl">
      <h1 class="text-white text-2xl font-bold mb-1">Welcome back</h1>
      <p class="text-pcs-300 text-sm mb-7">Sign in to your INVTrack account</p>

      <?php if ($registered): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm rounded-xl px-4 py-3 mb-5">
          Account created successfully. You can now sign in.
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm rounded-xl px-4 py-3 mb-5 flex items-center gap-2">
          <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="flex flex-col gap-5">
        <div>
          <label class="block text-sm font-medium text-pcs-100 mb-1.5">Email</label>
          <input type="email" name="email" required autocomplete="username"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            placeholder="you@company.com"
            class="w-full bg-pcs-800 border border-pcs-700 rounded-xl px-4 py-3 text-white placeholder-pcs-400 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition" />
        </div>
        <div>
          <label class="block text-sm font-medium text-pcs-100 mb-1.5">Password</label>
          <input type="password" name="password" required autocomplete="current-password"
            placeholder="••••••••"
            class="w-full bg-pcs-800 border border-pcs-700 rounded-xl px-4 py-3 text-white placeholder-pcs-400 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition" />
        </div>
        <button type="submit"
          class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold rounded-xl py-3 text-sm transition shadow-lg shadow-indigo-500/30 mt-1">
          Sign In
        </button>
      </form>

      <div class="mt-6 pt-5 border-t border-pcs-800 text-center">
        <span class="text-sm text-pcs-300">Don't have an account?</span>
        <a href="register.php" class="ml-1 text-sm font-semibold text-indigo-400 hover:text-indigo-300 hover:underline transition">
          Create account
        </a>
      </div>

    </div>
  </div>
</body>
</html>
