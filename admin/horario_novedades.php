<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';

$sectionTitle = 'Horario con novedades';
asegurar_esquema_novedades();
$grados = db_all(
    "SELECT grado FROM (
         SELECT h.id_grado AS grado
         FROM novedades_cambios_horario n
         JOIN horario_semanal h ON h.id_horario = n.id_horario
         WHERE n.fecha IS NOT NULL AND n.estado = 'ACTIVA'
         UNION
         SELECT id_grado AS grado FROM novedades_cambios_horario
         WHERE tipo_cambio = 'ADICION' AND fecha >= CURDATE() AND estado = 'ACTIVA'
     ) grados_con_novedades
     ORDER BY CAST(SUBSTRING_INDEX(grado, '-', 1) AS UNSIGNED),
              CAST(SUBSTRING_INDEX(grado, '-', -1) AS UNSIGNED)"
);
$grado = get('grado', '');
$gradosDisponibles = array_column($grados, 'grado');
if (!$grado && $gradosDisponibles) {
    $grado = (string) $gradosDisponibles[0];
}
if ($grado && !in_array($grado, $gradosDisponibles, true)) {
    $grado = '';
}

$gradoInfo = $grado
    ? db_one('SELECT g.id_grado, g.id_calendario, c.nombre AS calendario FROM grados g JOIN calendarios c ON c.id_calendario = g.id_calendario WHERE g.id_grado = ?', [$grado])
    : null;
$bloques = $gradoInfo
    ? db_all("SELECT id_bloque, numero_bloque, tipo, TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio,
                     TIME_FORMAT(hora_fin, '%H:%i') AS hora_fin
              FROM bloques_horarios WHERE id_calendario = ? ORDER BY hora_inicio, numero_bloque", [$gradoInfo['id_calendario']])
    : [];
$dias = $gradoInfo ? db_all('SELECT id_dia, nombre, orden_dia FROM dias_semana ORDER BY orden_dia') : [];
$grid = [];
if ($gradoInfo) {
    $clasesBase = db_all(
        'SELECT h.id_horario, h.id_bloque, h.id_dia, h.estado,
                m.nombre AS materia, p.nombre_completo AS profesor, s.nombre AS salon
         FROM horario_semanal h
         JOIN materias m ON m.id_materia = h.id_materia
         LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
         LEFT JOIN salones s ON s.id_salon = h.id_salon
         WHERE h.id_grado = ?',
        [$grado]
    );
    foreach ($clasesBase as $claseBase) {
        $grid[(int) $claseBase['id_bloque']][(int) $claseBase['id_dia']] = [
            'base' => $claseBase,
            'novedades' => [],
        ];
    }
    $novedades = db_all(
        "SELECT h.id_horario, d.orden_dia, h.id_bloque,
            TIME_FORMAT(COALESCE(n.nueva_hora_inicio, b.hora_inicio), '%H:%i') AS hora_inicio,
            TIME_FORMAT(COALESCE(n.nueva_hora_fin, b.hora_fin), '%H:%i') AS hora_fin,
            COALESCE(mn.nombre, m.nombre) AS materia,
            COALESCE(pn.nombre_completo, p.nombre_completo) AS profesor,
            COALESCE(sn.nombre, s.nombre) AS salon,
            CASE WHEN n.observacion LIKE '%cancelada%' THEN 'CANCELADA' ELSE 'PROGRAMADA' END AS estado,
            n.fecha AS fecha_novedad, n.tipo_cambio AS tipo_novedad, n.observacion
         FROM novedades_cambios_horario n
         JOIN horario_semanal h ON h.id_horario = n.id_horario
         JOIN dias_semana d ON d.id_dia = h.id_dia
         JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
         JOIN materias m ON m.id_materia = h.id_materia
         LEFT JOIN materias mn ON mn.id_materia = n.nueva_materia
         LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
         LEFT JOIN profesores pn ON pn.id_profesor = n.nuevo_profesor
         LEFT JOIN salones s ON s.id_salon = h.id_salon
         LEFT JOIN salones sn ON sn.id_salon = n.nuevo_salon
         WHERE h.id_grado = ? AND n.fecha IS NOT NULL AND n.estado = 'ACTIVA'
         ORDER BY d.orden_dia, h.id_bloque, n.fecha",
        [$grado]
    );
    foreach ($novedades as $novedad) {
        $bloqueId = (int) $novedad['id_bloque'];
        $diaId = (int) $novedad['orden_dia'];
        if (!isset($grid[$bloqueId][$diaId])) {
            $grid[$bloqueId][$diaId] = ['base' => null, 'novedades' => []];
        }
        $grid[$bloqueId][$diaId]['novedades'][] = $novedad;
    }
    $adiciones = db_all(
        "SELECT n.id_dia, d.orden_dia, n.id_bloque,
                TIME_FORMAT(b.hora_inicio, '%H:%i') AS hora_inicio,
                TIME_FORMAT(b.hora_fin, '%H:%i') AS hora_fin,
                m.nombre AS materia, p.nombre_completo AS profesor, s.nombre AS salon,
                COALESCE(n.nuevo_estado, 'PROGRAMADA') AS estado, n.fecha AS fecha_novedad,
                n.tipo_cambio AS tipo_novedad, n.observacion
         FROM novedades_cambios_horario n
         JOIN dias_semana d ON d.id_dia = n.id_dia
         JOIN bloques_horarios b ON b.id_bloque = n.id_bloque
         JOIN materias m ON m.id_materia = n.nueva_materia
         LEFT JOIN profesores p ON p.id_profesor = n.nuevo_profesor
         LEFT JOIN salones s ON s.id_salon = n.nuevo_salon
         WHERE n.id_grado = ? AND n.tipo_cambio = 'ADICION'
           AND n.fecha >= CURDATE() AND n.estado = 'ACTIVA'
         ORDER BY d.orden_dia, n.id_bloque, n.fecha",
        [$grado]
    );
    foreach ($adiciones as $adicion) {
        $bloqueId = (int) $adicion['id_bloque'];
        $diaId = (int) $adicion['orden_dia'];
        if (!isset($grid[$bloqueId][$diaId])) {
            $grid[$bloqueId][$diaId] = ['base' => null, 'novedades' => []];
        }
        $grid[$bloqueId][$diaId]['novedades'][] = $adicion;
    }
}

require dirname(__DIR__) . '/includes/header.php';
?>
<?php if ($gradoInfo): ?>
<section class="card">
    <div class="card-head">
        <h2>Horario con novedades</h2>
        <form class="toolbar schedule-novelty-filter" method="get">
            <label for="novelty-grade">Grado</label>
            <select class="search" id="novelty-grade" name="grado" onchange="this.form.submit()">
                <?php foreach ($gradosDisponibles as $gradoDisponible): ?>
                    <option value="<?= e($gradoDisponible) ?>" <?= $grado === $gradoDisponible ? 'selected' : '' ?>><?= e($gradoDisponible) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <p class="schedule-novelty-caption">
        <?= e($grado) ?> <span>(<?= e(pretty_name($gradoInfo['calendario'])) ?>)</span>
        <span class="schedule-novelty-legend"><i class="novelty-swatch" aria-hidden="true"></i> Cambio activo</span>
        <span class="schedule-novelty-legend"><i class="novelty-swatch is-cancelled" aria-hidden="true"></i> Cancelación</span>
    </p>
    <div class="table-wrap">
        <table class="schedule">
            <thead>
            <tr>
                <th>Hora</th>
                <?php foreach ($dias as $dia): ?>
                    <th><?= e($dia['nombre']) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($bloques as $bloque): ?>
                <tr>
                    <td>
                        <strong><?= e($bloque['hora_inicio']) ?> - <?= e($bloque['hora_fin']) ?></strong><br>
                        <small><?= e($bloque['tipo'] === 'DESCANSO' ? 'Descanso' : 'Bloque ' . $bloque['numero_bloque']) ?></small>
                    </td>
                    <?php foreach ($dias as $dia): ?>
                        <?php $celda = $grid[(int) $bloque['id_bloque']][(int) $dia['orden_dia']] ?? null; ?>
                        <td>
                            <?php if ($bloque['tipo'] === 'DESCANSO'): ?>
                                <div class="cell-break">Descanso</div>
                            <?php elseif (!$celda): ?>
                                <div class="cell-empty" aria-label="Sin clase"></div>
                            <?php else: ?>
                                <div class="schedule-novelty-stack">
                                    <?php if ($celda['base'] && !$celda['novedades']): ?>
                                        <div class="cell-class">
                                            <strong><?= e($celda['base']['materia']) ?></strong>
                                            <small><?= e($celda['base']['profesor'] ?: 'Sin profesor') ?></small>
                                            <small><?= e($celda['base']['salon'] ?: 'Sin salón') ?></small>
                                            <small><?= e($celda['base']['estado']) ?></small>
                                        </div>
                                    <?php endif; ?>
                                    <?php foreach ($celda['novedades'] as $novedad):
                                        $claseCss = $novedad['estado'] === 'CANCELADA' ? 'is-cancelled' : 'is-modified';
                                        $estadoNovedad = $novedad['estado'] === 'CANCELADA' ? 'CANCELADA' : 'PROGRAMADA';
                                        ?>
                                        <div class="cell-class <?= e($claseCss) ?>">
                                            <strong><?= e($novedad['materia']) ?></strong>
                                            <small><?= e($novedad['profesor'] ?: 'Sin profesor') ?></small>
                                            <small><?= e($novedad['salon'] ?: 'Sin salón') ?></small>
                                            <small><?= e($estadoNovedad) ?></small>
                                            <?php if (($novedad['tipo_novedad'] ?? '') === 'ADICION'): ?><small>Solo <?= e($novedad['fecha_novedad']) ?></small><?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php else: ?>
<section class="empty-state-panel" aria-labelledby="novelty-schedule-empty-title">
    <div class="empty-state-icon" aria-hidden="true">📅</div>
    <p class="empty-state-eyebrow">HORARIO</p>
    <h2 id="novelty-schedule-empty-title">No hay horarios para mostrar</h2>
    <p>Cuando existan clases y novedades activas, aparecerán aquí por grado.</p>
</section>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
