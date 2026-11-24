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
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" />
  <style>body{font-family:'Inter',sans-serif;}</style>
</head>
<body class="min-h-screen bg-slate-950 flex items-center justify-center p-4">


  <div class="fixed inset-0 opacity-5 pointer-events-none" style="background-image:linear-gradient(rgba(99,102,241,0.4)1px,transparent 1px),linear-gradient(90deg,rgba(99,102,241,0.4)1px,transparent 1px);background-size:48px 48px"></div>

  <div class="w-full max-w-md relative z-10">

    <div class="flex items-center justify-center gap-3 mb-8">
      <div class="w-11 h-11 bg-indigo-600 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-500/40">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10l2.293-2.293A1 1 0 016 13h4a1 1 0 01.707.293L13 16z"/><path stroke-linecap="round" stroke-linejoin="round" d="M13 6h4l3 5-3 2-2-2H13"/></svg>
      </div>
      <div>
        <div class="text-white font-bold text-xl tracking-tight">INVTrack</div>
        <div class="text-slate-500 text-xs font-mono uppercase tracking-widest">Management Suite</div>
      </div>
    </div>


    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 shadow-2xl">
      <h1 class="text-white text-2xl font-bold mb-1">Welcome back</h1>
      <p class="text-slate-400 text-sm mb-7">Sign in to your INVTrack account</p>

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
          <label class="block text-sm font-medium text-slate-300 mb-1.5">Email</label>
          <input type="email" name="email" required autocomplete="username"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            placeholder="you@company.com"
            class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-1.5">Password</label>
          <input type="password" name="password" required autocomplete="current-password"
            placeholder="••••••••"
            class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition" />
        </div>
        <button type="submit"
          class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold rounded-xl py-3 text-sm transition shadow-lg shadow-indigo-500/30 mt-1">
          Sign In
        </button>
      </form>

      <div class="mt-6 pt-5 border-t border-slate-800 text-center">
        <span class="text-sm text-slate-400">Don't have an account?</span>
        <a href="register.php" class="ml-1 text-sm font-semibold text-indigo-400 hover:text-indigo-300 hover:underline transition">
          Create account
        </a>
      </div>

    </div>
  </div>
</body>
</html>
