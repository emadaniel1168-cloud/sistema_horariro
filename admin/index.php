<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin_auth();

$sectionTitle = 'Inicio';
asegurar_esquema_novedades();
asegurar_tabla_novedades_temporales();
$stats = [
    ['Horarios', db_count('SELECT COUNT(*) AS total FROM horario_semanal'), 'horarios.php', '▦'],
    ['Profesores', db_count('SELECT COUNT(*) AS total FROM profesores'), 'profesores.php', '♙'],
    ['Grados', db_count('SELECT COUNT(*) AS total FROM grados'), 'grados.php', '▤'],
    ['Lugares', db_count('SELECT COUNT(*) AS total FROM lugares'), 'lugares.php', '⌖'],
];
$activeNoveltyCount = db_count("SELECT COUNT(*) AS total FROM (
        SELECT CONCAT('P:', COALESCE(grupo_novedad, id_cambio)) AS grupo
        FROM novedades_cambios_horario WHERE fecha IS NOT NULL AND estado = 'ACTIVA'
        UNION
        SELECT CONCAT('T:', grupo_novedad) AS grupo
        FROM novedades_temporales WHERE expira_en > NOW()
    ) novedades_activas");
$quickActions = [
    ['Crear o revisar horarios', 'horarios.php', '▦'],
    ['Actualizar profesores', 'profesores.php', '♙'],
    ['Administrar grados', 'grados.php', '▤'],
    ['Administrar lugares', 'lugares.php', '⌖'],
    ['Registrar una novedad', 'novedades.php', '!'],
    [$activeNoveltyCount > 0 ? 'Hay novedades (' . $activeNoveltyCount . ')' : 'No hay novedades', 'novedades.php', '!'],
];

require dirname(__DIR__) . '/includes/header.php';
?>
<section class="dashboard-intro">
    <div>
        <p class="dashboard-eyebrow">Centro de control</p>
        <h2>El colegio, en orden.</h2>
        <p>Administra la operación académica desde un solo lugar: horarios, personas, espacios y catálogos.</p>
    </div>
    <a class="btn btn-primary dashboard-create" href="<?= e(APP_BASE) ?>/admin/horarios.php">＋ Crear horario</a>
</section>

<section class="stats dashboard-stats" aria-label="Resumen de registros">
    <?php foreach ($stats as [$label, $value, $url, $icon]): ?>
        <a class="stat stat-link" href="<?= e(APP_BASE) ?>/admin/<?= e($url) ?>">
            <span class="label"><?= e($label) ?></span>
            <span class="value"><?= (int) $value ?></span>
            <span class="stat-caption">Ver y administrar registros <span aria-hidden="true">→</span></span>
            <span class="stat-icon" aria-hidden="true"><?= $icon ?></span>
        </a>
    <?php endforeach; ?>
</section>

<section class="dashboard-panels">
    <article class="card dashboard-panel">
        <div class="dashboard-panel-head">
            <h2>Acciones frecuentes</h2>
            <p>Atajos para las tareas del día.</p>
        </div>
        <div class="quick-actions">
            <?php foreach ($quickActions as [$label, $url, $icon]): ?>
                <a class="quick-action" href="<?= e(APP_BASE) ?>/admin/<?= e($url) ?>">
                    <span class="quick-action-icon" aria-hidden="true"><?= $icon ?></span>
                    <span><?= e($label) ?></span>
                    <span class="quick-action-arrow" aria-hidden="true">→</span>
                </a>
            <?php endforeach; ?>
        </div>
    </article>

</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
