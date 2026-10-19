<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin_auth();

$sectionTitle = 'Configuración de cuenta';
$adminId = (int) $_SESSION['admin_id'];
$admin = db_one('SELECT id, correo, nombre, contrasena FROM administrador WHERE id = ?', [$adminId]);
if ($admin === null) {
    redirect(APP_BASE . '/admin/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $campos = ['nombre', 'correo', 'contrasena_actual', 'contrasena_nueva', 'confirmar_contrasena'];
        foreach ($campos as $campo) {
            if (isset($_POST[$campo]) && !is_string($_POST[$campo])) {
                throw new RuntimeException('Los datos enviados no son válidos.');
            }
        }
        $nombre = trim($_POST['nombre'] ?? '');
        $correo = strtolower(trim($_POST['correo'] ?? ''));
        $contrasenaActual = $_POST['contrasena_actual'] ?? '';
        $contrasenaNueva = $_POST['contrasena_nueva'] ?? '';
        $confirmarContrasena = $_POST['confirmar_contrasena'] ?? '';
        $nombreLength = preg_match_all('/./us', $nombre);

        if ($nombreLength === false || $nombreLength === 0 || $nombreLength > 100) {
            throw new RuntimeException('El nombre es obligatorio y debe tener como máximo 100 caracteres.');
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 254) {
            throw new RuntimeException('Ingrese un correo electrónico válido.');
        }
        if (!password_verify($contrasenaActual, $admin['contrasena'])) {
            throw new RuntimeException('La contraseña actual no es correcta.');
        }
        if (db_one('SELECT id FROM administrador WHERE correo = ? AND id <> ?', [$correo, $adminId]) !== null) {
            throw new RuntimeException('Ese correo electrónico ya está en uso.');
        }
        if ($contrasenaNueva !== '' && strlen($contrasenaNueva) < 8) {
            throw new RuntimeException('La nueva contraseña debe tener al menos 8 caracteres.');
        }
        if ($contrasenaNueva !== $confirmarContrasena) {
            throw new RuntimeException('La confirmación de la nueva contraseña no coincide.');
        }

        if ($contrasenaNueva !== '') {
            db_exec(
                'UPDATE administrador SET nombre = ?, correo = ?, contrasena = ? WHERE id = ?',
                [$nombre, $correo, password_hash($contrasenaNueva, PASSWORD_DEFAULT), $adminId]
            );
        } else {
            db_exec('UPDATE administrador SET nombre = ?, correo = ? WHERE id = ?', [$nombre, $correo, $adminId]);
        }

        flash_set('success', 'Los datos de la cuenta se actualizaron correctamente.');
        redirect(APP_BASE . '/admin/configuracion.php');
    } catch (PDOException $e) {
        flash_set('error', 'No se pudieron guardar los cambios. Verifique los datos e inténtelo nuevamente.');
        redirect(APP_BASE . '/admin/configuracion.php');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect(APP_BASE . '/admin/configuracion.php');
    }
}

require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card account-settings">
    <div class="card-head">
        <div>
            <h2>Datos del administrador</h2>
            <p class="muted">Actualiza el nombre, correo o contraseña de acceso.</p>
        </div>
    </div>
    <form method="post" action="<?= e(APP_BASE) ?>/admin/configuracion.php">
        <?= csrf_field() ?>
        <div class="grid-2">
            <div class="field">
                <label for="nombre">Nombre</label>
                <input id="nombre" name="nombre" type="text" maxlength="100" autocomplete="name" value="<?= e($admin['nombre']) ?>" required>
            </div>
            <div class="field">
                <label for="correo">Correo electrónico</label>
                <input id="correo" name="correo" type="email" maxlength="254" autocomplete="email" value="<?= e($admin['correo']) ?>" required>
            </div>
        </div>
        <div class="account-password-section">
            <h3>Cambiar contraseña</h3>
            <p class="muted">Deja en blanco la nueva contraseña si no deseas cambiarla.</p>
            <div class="grid-2">
                <div class="field">
                    <label for="contrasena_nueva">Nueva contraseña</label>
                    <input id="contrasena_nueva" name="contrasena_nueva" type="password" minlength="8" autocomplete="new-password">
                </div>
                <div class="field">
                    <label for="confirmar_contrasena">Confirmar nueva contraseña</label>
                    <input id="confirmar_contrasena" name="confirmar_contrasena" type="password" minlength="8" autocomplete="new-password">
                </div>
            </div>
        </div>
        <div class="field">
            <label for="contrasena_actual">Contraseña actual para guardar cambios</label>
            <input id="contrasena_actual" name="contrasena_actual" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary" type="submit">Guardar cambios</button>
    </form>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
