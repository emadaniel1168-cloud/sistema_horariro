<?php
$navItems = [
    ['index.php', '⌂', 'Inicio'],
    ['horarios.php', '▦', 'Horario semanal'],
    ['ajustes_horario.php', '◷', 'Ajustes generales'],
    ['novedades.php', '!', 'Novedades'],
    ['grados.php', '▤', 'Grados'],
    ['profesores.php', '♙', 'Profesores'],
    ['lugares.php', '⌖', 'Lugares'],
    ['materias.php', '◇', 'Materias'],
];
?>
<aside class="sidebar" id="sidebar">
    <nav>
        <?php foreach ($navItems as [$file, $icon, $label]): ?>
            <a class="<?= is_active($file) ?>" href="<?= e(APP_BASE) ?>/admin/<?= e($file) ?>">
                <span class="nav-icon" aria-hidden="true"><?= $icon ?></span>
                <span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-account">
        <a class="<?= is_active('configuracion.php') ?>" href="<?= e(APP_BASE) ?>/admin/configuracion.php">
            <span class="nav-icon" aria-hidden="true">⚙</span>
            <span>Configuración de cuenta</span>
        </a>
        <form method="post" action="<?= e(APP_BASE) ?>/admin/logout.php">
            <?= csrf_field() ?>
            <button type="submit"><span class="nav-icon" aria-hidden="true">↪</span><span>Cerrar sesión</span></button>
        </form>
    </div>
</aside>
<div class="sidebar-backdrop" data-menu-toggle></div>
