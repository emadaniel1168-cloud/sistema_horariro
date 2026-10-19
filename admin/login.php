<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
asegurar_tabla_administrador();

if (!empty($_SESSION['admin_id'])) {
    $existingAdmin = db_one('SELECT id FROM administrador WHERE id = ?', [(int) $_SESSION['admin_id']]);
    if ($existingAdmin !== null) {
        redirect(APP_BASE . '/admin/index.php');
    }
    unset($_SESSION['admin_id'], $_SESSION['admin_nombre'], $_SESSION['admin_correo']);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'La sesión expiró. Recargue la página e inténtelo nuevamente.';
    } else {
        $correoInput = $_POST['correo'] ?? '';
        $contrasenaInput = $_POST['contrasena'] ?? '';
        $correo = is_string($correoInput) ? strtolower(trim($correoInput)) : '';
        $contrasena = is_string($contrasenaInput) ? $contrasenaInput : '';
        $admin = db_one('SELECT id, contrasena FROM administrador WHERE correo = ?', [$correo]);

        if ($admin !== null && password_verify($contrasena, $admin['contrasena'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            redirect(APP_BASE . '/admin/index.php');
        }

        $error = 'El correo o la contraseña no son correctos.';
    }
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión | Colegio Santander</title>
    <link rel="stylesheet" href="<?= e(APP_BASE) ?>/css/style.css?v=<?= (int) filemtime(dirname(__DIR__) . '/css/style.css') ?>-<?= (int) filesize(dirname(__DIR__) . '/css/style.css') ?>">
</head>
<body class="auth-page">
<main class="auth-card">
    <div class="auth-brand-mark" aria-hidden="true">CS</div>
    <p class="auth-eyebrow">Colegio Santander</p>
    <h1>Bienvenido</h1>
    <p class="auth-description">Inicia sesión para continuar al panel de administración.</p>
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e(APP_BASE) ?>/admin/login.php">
        <?= csrf_field() ?>
        <div class="field">
            <label for="correo">Correo electrónico</label>
            <input id="correo" name="correo" type="email" autocomplete="username" maxlength="254" required autofocus>
        </div>
        <div class="field">
            <label for="contrasena">Contraseña</label>
            <input id="contrasena" name="contrasena" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary auth-submit" type="submit">Iniciar sesión</button>
    </form>
    <p class="auth-hint">Acceso inicial: administrador1234@gmail.com · admin123</p>
</main>
</body>
</html>
