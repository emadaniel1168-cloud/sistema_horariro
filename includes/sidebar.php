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
</aside>
<div class="sidebar-backdrop" data-menu-toggle></div>
