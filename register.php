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
    'admin' => ['Administrator', 'Full system access'],
    'staff' => ['Staff', 'Inventory, procurement, and reports'],
    'warehouse' => ['Warehouse / Delivery Staff', 'Available products and shop orders'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>INVTrack — Create Account</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" />
  <style>body{font-family:'Inter',sans-serif;}</style>
</head>
<body class="min-h-screen bg-slate-950 flex items-center justify-center p-4 py-10">
  <div class="fixed inset-0 opacity-5 pointer-events-none" style="background-image:linear-gradient(rgba(99,102,241,0.4)1px,transparent 1px),linear-gradient(90deg,rgba(99,102,241,0.4)1px,transparent 1px);background-size:48px 48px"></div>

  <div class="w-full max-w-lg relative z-10">
    <div class="flex items-center justify-center gap-3 mb-8">
      <div class="w-11 h-11 bg-indigo-600 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-500/40">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10l2.293-2.293A1 1 0 016 13h4a1 1 0 01.707.293L13 16z"/><path stroke-linecap="round" stroke-linejoin="round" d="M13 6h4l3 5-3 2-2-2H13"/></svg>
      </div>
      <div>
        <div class="text-white font-bold text-xl tracking-tight">INVTrack</div>
        <div class="text-slate-500 text-xs font-mono uppercase tracking-widest">Management Suite</div>
      </div>
    </div>

    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-2xl">
      <h1 class="text-white text-2xl font-bold mb-1">Create your account</h1>
      <p class="text-slate-400 text-sm mb-7">Enter your details and select your operational role</p>

      <?php if ($error): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm rounded-xl px-4 py-3 mb-5">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="flex flex-col gap-5">
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-1.5">Full name</label>
          <input type="text" name="name" required autocomplete="name" value="<?= htmlspecialchars($name) ?>" placeholder="Juan Dela Cruz"
            class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition" />
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-300 mb-1.5">Email</label>
          <input type="email" name="email" required autocomplete="email" value="<?= htmlspecialchars($email) ?>" placeholder="you@company.com"
            class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition" />
        </div>

        <fieldset>
          <legend class="block text-sm font-medium text-slate-300 mb-2">Account role</legend>
          <div class="grid gap-2">
            <?php foreach ($roles as $value => [$label, $description]): ?>
            <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 transition <?= $role === $value ? 'border-indigo-500 bg-indigo-500/10' : 'border-slate-700 bg-slate-800 hover:border-slate-600' ?>">
              <input type="radio" name="role" value="<?= $value ?>" <?= $role === $value ? 'checked' : '' ?> class="mt-1 accent-indigo-500" />
              <span>
                <span class="block text-sm font-semibold text-white"><?= htmlspecialchars($label) ?></span>
                <span class="mt-0.5 block text-xs text-slate-400"><?= htmlspecialchars($description) ?></span>
              </span>
            </label>
            <?php endforeach; ?>
          </div>
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Password</label>
            <input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters"
              class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Confirm password</label>
            <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Repeat password"
              class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition" />
          </div>
        </div>

        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold rounded-xl py-3 text-sm transition shadow-lg shadow-indigo-500/30 mt-1">
          Create Account
        </button>
      </form>

      <div class="mt-6 pt-5 border-t border-slate-800 text-center">
        <span class="text-sm text-slate-400">Already have an account?</span>
        <a href="index.php" class="ml-1 text-sm font-semibold text-indigo-400 hover:text-indigo-300 hover:underline transition">Sign in</a>
      </div>
    </div>
  </div>
</body>
</html>
