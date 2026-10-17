<?php

declare(strict_types=1);

/**
 * The shell of the packages' admin pages inside the sandbox: Bootstrap, which
 * their views are written for, and the way back to the sandbox UI.
 *
 * @var string $content
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sandbox admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous">
</head>
<body>
<nav class="navbar navbar-expand bg-body-tertiary border-bottom mb-4">
    <div class="container">
        <a class="navbar-brand" href="/ui/operator.html">← Sandbox</a>
        <div class="navbar-nav">
            <a class="nav-link" href="/admin/flags">Feature flags</a>
            <a class="nav-link" href="/admin/settings">Settings</a>
        </div>
        <span class="navbar-text small text-body-secondary">yii3-feature-flags-ui · yii3-settings-ui — no authentication, local demo only</span>
    </div>
</nav>
<main class="container">
<?= $content ?>
</main>
</body>
</html>
