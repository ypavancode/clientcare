<?php /** Friendly error page (variables: $detail when APP_ENV=development) */ ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Something went wrong</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>body{background:#0b0b0b;color:#e8e8e8;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif}.card{max-width:520px;border:1px solid rgba(252,175,23,.35);border-radius:16px;background:#141414;color:#e8e8e8;box-shadow:0 30px 80px -30px rgba(0,0,0,.8)}.brand{height:4px;background:#FCAF17;border-radius:16px 16px 0 0}.text-muted{color:#9a9a9a!important}pre{background:#1a1a1a!important;color:#d0d0d0;border-color:rgba(255,255,255,.1)!important}.btn-dark{background:#FCAF17;border-color:#FCAF17;color:#111}.btn-outline-dark{border-color:rgba(255,255,255,.2);color:#fff}</style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 p-3">
<div class="card w-100">
  <div class="brand"></div>
  <div class="card-body p-4 text-center">
    <div class="display-6 mb-2">⚠️</div>
    <h1 class="h4 fw-bold mb-2">Something went wrong</h1>
    <p class="text-muted mb-3">Please try again or contact the administrator. The error has been logged.</p>
    <?php if (!empty($detail)): ?><pre class="text-start small bg-light p-2 rounded border"><?= htmlspecialchars($detail) ?></pre><?php endif; ?>
    <a href="javascript:history.back()" class="btn btn-outline-dark btn-sm me-2">Go back</a>
    <a href="<?= defined('BASE_URL') ? BASE_URL . '/dashboard/index.php' : '/' ?>" class="btn btn-dark btn-sm">Dashboard</a>
  </div>
</div>
</body>
</html>
