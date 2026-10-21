<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin_auth();
asegurar_esquema_novedades();
asegurar_tabla_descansos_por_dia();
asegurar_tabla_novedades_temporales();

$sectionTitle = 'Salones';

function salon_abreviar_dia(string $nombre): string
{
    $mapa = [
        'lunes' => 'Lu',
        'martes' => 'Ma',
        'miércoles' => 'Mi',
        'miercoles' => 'Mi',
        'jueves' => 'Ju',
        'viernes' => 'Vi',
        'sábado' => 'Sa',
        'sabado' => 'Sa',
        'domingo' => 'Do',
    ];
    $clave = mb_strtolower(trim($nombre), 'UTF-8');
    return $mapa[$clave] ?? mb_substr($nombre, 0, 2, 'UTF-8');
}

function salon_color_materia(string $nombre): int
{
    return abs((int) crc32(mb_strtolower($nombre, 'UTF-8'))) % 6;
}

function salon_es_almuerzo(array $bloque): bool
{
    $texto = mb_strtolower(trim((string) ($bloque['descripcion'] ?? '')), 'UTF-8');
    if (str_contains($texto, 'almuerzo')) {
        return true;
    }
    $inicio = format_time((string) ($bloque['hora_inicio'] ?? ''));
    return ($bloque['tipo'] ?? '') === 'DESCANSO' && $inicio >= '11:45' && $inicio <= '13:30';
}

function salon_etiqueta_periodo(array $bloque, int $numeroClase): array
{
    if (($bloque['tipo'] ?? 'CLASE') === 'DESCANSO' || salon_es_almuerzo($bloque)) {
        if (salon_es_almuerzo($bloque)) {
            return ['kind' => 'lunch', 'label' => 'ALMUERZO'];
        }
        return ['kind' => 'break', 'label' => 'DESCANSO'];
    }
    return ['kind' => 'class', 'label' => (string) $numeroClase];
}

function salon_clave_bloque(array $bloque): string
{
    return format_time((string) $bloque['hora_inicio']) . '|' . format_time((string) $bloque['hora_fin']);
}

function salon_fecha_proxima_dia(int $dia): string
{
    $hoy = new DateTimeImmutable('today');
    $diasHastaFecha = ($dia - (int) $hoy->format('N') + 7) % 7;
    return $hoy->modify('+' . $diasHastaFecha . ' days')->format('Y-m-d');
}

$idSalon = (int) get('salon', '0');
$fecha = trim((string) get('fecha', ''));
$fechaValida = $fecha !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $fecha) : false;
if ($fecha !== '' && (!$fechaValida || $fechaValida->format('Y-m-d') !== $fecha)) {
    $fecha = '';
    $fechaValida = false;
}
$marcaEliminacionSalon = $_SESSION['salon_celda_eliminada_temporal'] ?? null;
if (is_array($marcaEliminacionSalon) && (int) ($marcaEliminacionSalon['expira_en'] ?? 0) <= time()) {
    unset($_SESSION['salon_celda_eliminada_temporal']);
    $marcaEliminacionSalon = null;
}

$salones = db_all(
    "SELECT id_salon, nombre FROM salones WHERE activo = 1
     ORDER BY CASE WHEN nombre REGEXP '[0-9]+$' THEN 0 ELSE 1 END,
              CAST(SUBSTRING_INDEX(nombre, ' ', -1) AS UNSIGNED), nombre"
);
$salonSeleccionado = $idSalon > 0
    ? db_one('SELECT id_salon, nombre FROM salones WHERE id_salon = ?', [$idSalon])
    : null;
if (!$salonSeleccionado) {
    $idSalon = 0;
}

$diasSemana = db_all('SELECT id_dia, nombre, orden_dia FROM dias_semana ORDER BY orden_dia, id_dia');
$diasTabla = $diasSemana;
$avisoFecha = '';
$idDiaFecha = 0;

if ($fechaValida instanceof DateTimeImmutable) {
    $ordenIso = (int) $fechaValida->format('N');
    $diaFecha = null;
    foreach ($diasSemana as $dia) {
        if ((int) $dia['orden_dia'] === $ordenIso || (int) $dia['id_dia'] === $ordenIso) {
            $diaFecha = $dia;
            break;
        }
    }
    if ($diaFecha === null) {
        $avisoFecha = 'No hay jornada académica configurada para esa fecha.';
        $diasTabla = [];
    } else {
        $idDiaFecha = (int) $diaFecha['id_dia'];
        $diasTabla = [$diaFecha];
    }
}

$bloquesSalon = [];
$gridSalon = [];
$bloquesCompatiblesPorHora = [];
$descansosCompatiblesPorHora = [];
$ocupacionesHorario = [];
$horariosExistentes = [];
$descansosMarcados = [];
$materiasActivas = [];
$profesoresActivos = [];

if ($salonSeleccionado && $diasTabla !== []) {
    $bloquesCrudos = db_all(
        "SELECT DISTINCT TIME_FORMAT(b.hora_inicio, '%H:%i') AS hora_inicio,
                TIME_FORMAT(b.hora_fin, '%H:%i') AS hora_fin,
                b.tipo, COALESCE(b.descripcion, '') AS descripcion
         FROM bloques_horarios b
         WHERE b.id_calendario IN (
            SELECT DISTINCT id_calendario FROM grados WHERE activo = 1
         )
         ORDER BY hora_inicio, hora_fin, b.tipo, b.descripcion"
    );
    foreach ($bloquesCrudos as $bloque) {
        $clave = salon_clave_bloque($bloque);
        if (!isset($bloquesSalon[$clave]) || ($bloque['tipo'] ?? '') === 'DESCANSO') {
            $bloquesSalon[$clave] = $bloque;
        }
    }

    $bloquesCompatibles = db_all(
        "SELECT g.id_grado, b.id_bloque, b.hora_inicio, b.hora_fin
         FROM grados g
         JOIN bloques_horarios b ON b.id_calendario = g.id_calendario AND b.tipo = 'CLASE'
         WHERE g.activo = 1
         ORDER BY g.id_grado, b.hora_inicio, b.id_bloque"
    );
    foreach ($bloquesCompatibles as $bloqueCompatible) {
        $claveHora = salon_clave_bloque($bloqueCompatible);
        $bloquesCompatiblesPorHora[$claveHora][] = $bloqueCompatible;
    }
    $descansosCompatibles = db_all(
        "SELECT g.id_grado, b.id_bloque, b.hora_inicio, b.hora_fin
         FROM grados g
         JOIN bloques_horarios b ON b.id_calendario = g.id_calendario AND b.tipo = 'DESCANSO'
         WHERE g.activo = 1
         ORDER BY g.id_grado, b.hora_inicio, b.id_bloque"
    );
    foreach ($descansosCompatibles as $descansoCompatible) {
        $claveHora = salon_clave_bloque($descansoCompatible);
        $descansosCompatiblesPorHora[$claveHora][] = $descansoCompatible;
    }
    foreach (db_all('SELECT id_grado, id_dia, id_bloque, estado FROM horario_semanal') as $horarioExistente) {
        $claveOcupacion = (string) $horarioExistente['id_grado'] . '|' .
            (int) $horarioExistente['id_dia'] . '|' .
            (int) $horarioExistente['id_bloque'];
        $horariosExistentes[$claveOcupacion] = true;
        if ((string) $horarioExistente['estado'] === 'PROGRAMADA') {
            $ocupacionesHorario[$claveOcupacion] = true;
        }
    }
    foreach (db_all('SELECT id_grado, id_dia, id_bloque FROM descansos_por_dia') as $descansoMarcado) {
        $descansosMarcados[
            (string) $descansoMarcado['id_grado'] . '|' .
            (int) $descansoMarcado['id_dia'] . '|' .
            (int) $descansoMarcado['id_bloque']
        ] = true;
    }
    $materiasActivas = db_all('SELECT id_materia, nombre FROM materias WHERE activa = 1 ORDER BY nombre');
    $profesoresActivos = db_all(
        "SELECT p.id_profesor, p.nombre_completo,
                GROUP_CONCAT(DISTINCT h.id_materia ORDER BY h.id_materia) AS materias,
                GROUP_CONCAT(DISTINCT mp.id_materia ORDER BY mp.id_materia) AS materias_permanentes,
                GROUP_CONCAT(DISTINCT CONCAT(h.id_materia, ':', h.total) ORDER BY h.total DESC, h.id_materia) AS conteo_materias
         FROM profesores p
         LEFT JOIN (
            SELECT id_profesor, id_materia, COUNT(*) AS total
            FROM horario_semanal
            WHERE estado = 'PROGRAMADA'
            GROUP BY id_profesor, id_materia
         ) h ON h.id_profesor = p.id_profesor
         LEFT JOIN materia_profesores mp ON mp.id_profesor = p.id_profesor
         WHERE p.activo = 1
         GROUP BY p.id_profesor, p.nombre_completo
         ORDER BY p.nombre_completo"
    );
    foreach ($profesoresActivos as &$profesorActivoRow) {
        $profesorActivoRow['materias_todas'] = implode(',', array_unique(array_filter(array_merge(
            explode(',', (string) ($profesorActivoRow['materias'] ?? '')),
            explode(',', (string) ($profesorActivoRow['materias_permanentes'] ?? ''))
        ))));
    }
    unset($profesorActivoRow);

    if ($fechaValida instanceof DateTimeImmutable) {
        $clases = db_all(
            "SELECT h.id_horario, h.id_grado, h.id_dia, h.id_bloque,
                    COALESCE(n.nueva_materia, h.id_materia) AS id_materia,
                    COALESCE(n.nuevo_profesor, h.id_profesor) AS id_profesor,
                    COALESCE(n.nuevo_salon, h.id_salon) AS id_salon,
                    COALESCE(n.nuevo_estado, h.estado) AS estado,
                    COALESCE(n.observacion, h.observacion, '') AS observacion,
                    IF(n.id_cambio IS NULL, 0, 1) AS es_novedad,
                    UNIX_TIMESTAMP(t.expira_en) AS expira_timestamp,
                    TIME_FORMAT(COALESCE(n.nueva_hora_inicio, b.hora_inicio), '%H:%i') AS hora_inicio,
                    TIME_FORMAT(COALESCE(n.nueva_hora_fin, b.hora_fin), '%H:%i') AS hora_fin,
                    COALESCE(mn.nombre, m.nombre) AS materia,
                    COALESCE(pn.nombre_completo, p.nombre_completo) AS profesor,
                    COALESCE(sn.nombre, s.nombre) AS salon
             FROM horario_semanal h
             JOIN materias m ON m.id_materia = h.id_materia
             JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
             LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
             LEFT JOIN salones s ON s.id_salon = h.id_salon
             LEFT JOIN novedades_cambios_horario n
                    ON n.id_horario = h.id_horario AND n.fecha = ? AND n.estado = 'ACTIVA'
             LEFT JOIN profesores pn ON pn.id_profesor = n.nuevo_profesor
             LEFT JOIN materias mn ON mn.id_materia = n.nueva_materia
             LEFT JOIN salones sn ON sn.id_salon = n.nuevo_salon
             LEFT JOIN (
                SELECT id_horario, MAX(expira_en) AS expira_en
                FROM novedades_temporales
                WHERE expira_en > NOW()
                GROUP BY id_horario
             ) t ON t.id_horario = h.id_horario
             WHERE h.id_dia = ?
               AND COALESCE(n.nuevo_estado, h.estado) IN ('PROGRAMADA', 'CANCELADA')
               AND COALESCE(n.nuevo_salon, h.id_salon) = ?",
            [$fecha, $idDiaFecha, $idSalon]
        );
        $adiciones = db_all(
            "SELECT n.id_cambio AS id_novedad, n.id_grado, n.id_dia, n.id_bloque,
                    n.nueva_materia AS id_materia, n.nuevo_profesor AS id_profesor,
                    n.nuevo_salon AS id_salon, COALESCE(n.nuevo_estado, 'PROGRAMADA') AS estado,
                    n.observacion, 1 AS es_novedad, NULL AS expira_timestamp,
                    TIME_FORMAT(COALESCE(n.nueva_hora_inicio, b.hora_inicio), '%H:%i') AS hora_inicio,
                    TIME_FORMAT(COALESCE(n.nueva_hora_fin, b.hora_fin), '%H:%i') AS hora_fin,
                    m.nombre AS materia, p.nombre_completo AS profesor
             FROM novedades_cambios_horario n
             JOIN bloques_horarios b ON b.id_bloque = n.id_bloque
             JOIN materias m ON m.id_materia = n.nueva_materia
             LEFT JOIN profesores p ON p.id_profesor = n.nuevo_profesor
             WHERE n.tipo_cambio = 'ADICION' AND n.fecha = ? AND n.estado = 'ACTIVA'
               AND COALESCE(n.nuevo_estado, 'PROGRAMADA') IN ('PROGRAMADA', 'CANCELADA')
               AND n.nuevo_salon = ? AND n.id_dia = ?",
            [$fecha, $idSalon, $idDiaFecha]
        );
        $clases = array_merge($clases, $adiciones);
    } else {
        $clases = db_all(
            "SELECT h.id_horario, h.id_grado, h.id_dia, h.id_bloque,
                    h.id_materia, h.id_profesor, h.id_salon, h.estado,
                    COALESCE(h.observacion, '') AS observacion,
                    0 AS es_novedad, UNIX_TIMESTAMP(t.expira_en) AS expira_timestamp,
                    TIME_FORMAT(b.hora_inicio, '%H:%i') AS hora_inicio,
                    TIME_FORMAT(b.hora_fin, '%H:%i') AS hora_fin,
                    m.nombre AS materia, p.nombre_completo AS profesor, s.nombre AS salon
             FROM horario_semanal h
             JOIN materias m ON m.id_materia = h.id_materia
             JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
             LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
             LEFT JOIN salones s ON s.id_salon = h.id_salon
             LEFT JOIN (
                SELECT id_horario, MAX(expira_en) AS expira_en
                FROM novedades_temporales
                WHERE expira_en > NOW()
                GROUP BY id_horario
             ) t ON t.id_horario = h.id_horario
             JOIN grados g ON g.id_grado = h.id_grado AND g.activo = 1
             WHERE h.id_salon = ? AND h.estado IN ('PROGRAMADA', 'CANCELADA')
             ORDER BY b.hora_inicio, h.id_dia, h.id_grado",
            [$idSalon]
        );
        $fechasPorDia = [];
        $condicionesAdiciones = [];
        $parametrosAdiciones = [];
        foreach ($diasTabla as $dia) {
            $idDia = (int) $dia['id_dia'];
            $fechaDia = salon_fecha_proxima_dia((int) $dia['orden_dia']);
            $fechasPorDia[$idDia] = $fechaDia;
            $condicionesAdiciones[] = '(n.id_dia = ? AND n.fecha = ?)';
            $parametrosAdiciones[] = $idDia;
            $parametrosAdiciones[] = $fechaDia;
        }
        if ($condicionesAdiciones !== []) {
            $adicionesSemanales = db_all(
                "SELECT n.id_cambio AS id_novedad, n.id_grado, n.id_dia, n.id_bloque,
                        n.nueva_materia AS id_materia, n.nuevo_profesor AS id_profesor,
                        n.nuevo_salon AS id_salon, COALESCE(n.nuevo_estado, 'PROGRAMADA') AS estado,
                        n.observacion, 1 AS es_novedad, NULL AS expira_timestamp,
                        TIME_FORMAT(COALESCE(n.nueva_hora_inicio, b.hora_inicio), '%H:%i') AS hora_inicio,
                        TIME_FORMAT(COALESCE(n.nueva_hora_fin, b.hora_fin), '%H:%i') AS hora_fin,
                        m.nombre AS materia, p.nombre_completo AS profesor, s.nombre AS salon
                 FROM novedades_cambios_horario n
                 JOIN bloques_horarios b ON b.id_bloque = n.id_bloque
                 JOIN materias m ON m.id_materia = n.nueva_materia
                 LEFT JOIN profesores p ON p.id_profesor = n.nuevo_profesor
                 LEFT JOIN salones s ON s.id_salon = n.nuevo_salon
                 WHERE n.tipo_cambio = 'ADICION' AND n.estado = 'ACTIVA'
                   AND COALESCE(n.nuevo_estado, 'PROGRAMADA') IN ('PROGRAMADA', 'CANCELADA')
                   AND n.nuevo_salon = ?
                   AND (" . implode(' OR ', $condicionesAdiciones) . ')
                 ORDER BY b.hora_inicio, n.id_dia, n.id_grado',
                [$idSalon, ...$parametrosAdiciones]
            );
            foreach ($adicionesSemanales as &$adicionSemanal) {
                $adicionSemanal['fecha'] = $fechasPorDia[(int) $adicionSemanal['id_dia']];
            }
            unset($adicionSemanal);
            $clases = array_merge($clases, $adicionesSemanales);
        }
    }

    foreach ($clases as $clase) {
        $clave = salon_clave_bloque($clase);
        if (!isset($bloquesSalon[$clave])) {
            $bloquesSalon[$clave] = [
                'hora_inicio' => $clase['hora_inicio'],
                'hora_fin' => $clase['hora_fin'],
                'tipo' => 'CLASE',
                'descripcion' => '',
            ];
        }
        $gridSalon[$clave][(int) $clase['id_dia']][] = $clase;
    }
}

uasort($bloquesSalon, static function (array $a, array $b): int {
    return [$a['hora_inicio'], $a['hora_fin']] <=> [$b['hora_inicio'], $b['hora_fin']];
});

require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card">
    <div class="card-head">
        <div>
            <h2>Horario de salones</h2>
            <p class="muted">Consulte las clases programadas en cada aula, por semana o por fecha.</p>
        </div>
    </div>
    <form class="toolbar room-filters" method="get">
        <div class="field">
            <label for="salon">Salón</label>
            <select id="salon" name="salon" required>
                <option value="">Seleccione un salón</option>
                <?php foreach ($salones as $salon): ?>
                    <option value="<?= (int) $salon['id_salon'] ?>" <?= $idSalon === (int) $salon['id_salon'] ? 'selected' : '' ?>>
                        <?= e($salon['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="fecha">Fecha (opcional)</label>
            <input id="fecha" type="date" name="fecha" value="<?= e($fecha) ?>">
        </div>
        <div class="schedule-filter-actions room-filter-actions">
            <button class="btn btn-primary" type="submit">Consultar</button>
            <?php if ($fecha !== '' && $idSalon > 0): ?>
                <a class="btn btn-secondary" href="<?= e(APP_BASE) ?>/admin/horario_salones.php?salon=<?= (int) $idSalon ?>">Ver semana</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php if (!$salonSeleccionado): ?>
    <section class="empty-state-panel" aria-labelledby="salon-empty-title">
        <div class="empty-state-icon" aria-hidden="true">▢</div>
        <p class="empty-state-eyebrow">Consulta de aulas</p>
        <h2 id="salon-empty-title">Elija un salón</h2>
        <p>Seleccione cualquier aula registrada para ver su horario semanal, o combine salón y fecha para un día concreto.</p>
    </section>
<?php elseif ($avisoFecha !== ''): ?>
    <section class="card">
        <p class="muted"><?= e($avisoFecha) ?></p>
    </section>
<?php else: ?>
    <?php
    $numeroClase = 0;
    $tituloFecha = $fechaValida instanceof DateTimeImmutable
        ? ' · ' . $fechaValida->format('d/m/Y')
        : '';
    ?>
    <section class="room-board" aria-label="Horario del salón">
        <header class="room-board-head">
            <p class="room-board-school"><?= e(APP_NAME) ?></p>
            <h2>Salón <?= e($salonSeleccionado['nombre']) ?><?= e($tituloFecha) ?></h2>
        </header>
        <div class="table-wrap room-board-scroll">
            <table class="room-timetable">
                <thead>
                <tr>
                    <th scope="col"> </th>
                    <?php foreach ($diasTabla as $dia): ?>
                        <th scope="col"><?= e(salon_abreviar_dia((string) $dia['nombre'])) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($bloquesSalon as $clave => $bloque): ?>
                    <?php
                    $meta = salon_etiqueta_periodo($bloque, $numeroClase + 1);
                    if ($meta['kind'] === 'class') {
                        $numeroClase++;
                    }
                    ?>
                    <tr>
                        <th scope="row" class="room-period">
                            <span class="room-period-label"><?= e($meta['label']) ?></span>
                            <small><?= e(format_time((string) $bloque['hora_inicio'])) ?> - <?= e(format_time((string) $bloque['hora_fin'])) ?></small>
                        </th>
                        <?php foreach ($diasTabla as $dia): ?>
                            <td>
                                <?php $clasesCelda = $gridSalon[$clave][(int) $dia['id_dia']] ?? []; ?>
                                <?php if ($clasesCelda): ?>
                                    <?php foreach ($clasesCelda as $clase): ?>
                                        <?php
                                        $fechaEdicion = $fechaValida instanceof DateTimeImmutable
                                            ? $fechaValida->format('Y-m-d')
                                            : salon_fecha_proxima_dia((int) $dia['orden_dia']);
                                        $esAdicion = empty($clase['id_horario']) && !empty($clase['id_novedad']);
                                        $edicionPermitida = $fechaEdicion >= (new DateTimeImmutable('today'))->format('Y-m-d')
                                            && (!empty($clase['id_horario']) || $esAdicion);
                                        $templateEdicion = 'tpl-room-edit-' . ($esAdicion ? 'addition-' . (int) $clase['id_novedad'] : (int) ($clase['id_horario'] ?? 0));
                                        $esTemporal = !empty($clase['expira_timestamp']);
                                        $claseCss = ($clase['estado'] ?? '') === 'CANCELADA'
                                            ? ($esTemporal ? 'is-temporary' : 'is-cancelled')
                                            : ($esTemporal ? 'is-temporary' : (!empty($clase['es_novedad']) ? 'is-modified' : ''));
                                        $observacionEdicion = (string) ($clase['observacion'] ?? '');
                                        if ($esAdicion && preg_match('/(?:^|\n)Observación:\s*(.*)$/su', $observacionEdicion, $observacionMatch) === 1) {
                                            $observacionEdicion = trim($observacionMatch[1]);
                                        }
                                        $materiaActiva = false;
                                        foreach ($materiasActivas as $materiaActivaRow) {
                                            if ((int) $materiaActivaRow['id_materia'] === (int) $clase['id_materia']) {
                                                $materiaActiva = true;
                                                break;
                                            }
                                        }
                                        $profesorActivo = empty($clase['id_profesor']);
                                        foreach ($profesoresActivos as $profesorActivoRow) {
                                            if ((int) $profesorActivoRow['id_profesor'] === (int) ($clase['id_profesor'] ?? 0)) {
                                                $profesorActivo = true;
                                                break;
                                            }
                                        }
                                        $salonActivo = empty($clase['id_salon']);
                                        foreach ($salones as $salonActivoRow) {
                                            if ((int) $salonActivoRow['id_salon'] === (int) ($clase['id_salon'] ?? 0)) {
                                                $salonActivo = true;
                                                break;
                                            }
                                        }
                                        ?>
                                        <?php if ($edicionPermitida): ?>
                                            <button class="room-class room-class-action c<?= salon_color_materia((string) $clase['materia']) ?> <?= e($claseCss) ?>"
                                                type="button" data-edit="<?= e($templateEdicion) ?>" data-title="Editar clase"
                                                <?= $esTemporal ? 'data-expire-at="' . (int) $clase['expira_timestamp'] * 1000 . '"' : '' ?>>
                                                <strong><?= e((string) $clase['materia']) ?></strong>
                                                <span><?= e((string) $clase['id_grado']) ?></span>
                                                <small><?= e((string) ($clase['profesor'] ?: 'Sin docente')) ?></small>
                                                <?php if (($clase['estado'] ?? '') === 'CANCELADA'): ?><small>CLASE CANCELADA</small><?php endif; ?>
                                            </button>
                                            <template id="<?= e($templateEdicion) ?>">
                                                <form method="post" action="<?= e(APP_BASE) ?>/admin/horarios.php"
                                                    data-room-class-editor
                                                    data-availability-url="<?= e(APP_BASE) ?>/admin/horarios.php?api=salones"
                                                    data-availability-date="<?= e($fechaEdicion) ?>"
                                                    data-availability-day="<?= (int) $clase['id_dia'] ?>"
                                                    data-availability-start="<?= e((string) $clase['hora_inicio']) ?>"
                                                    data-availability-end="<?= e((string) $clase['hora_fin']) ?>"
                                                    data-availability-addition="<?= $esAdicion ? (int) $clase['id_novedad'] : 0 ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="<?= $esAdicion ? 'update_addition' : 'update' ?>">
                                                    <input type="hidden" name="edicion_desde_salon" value="1">
                                                    <?php if ($esAdicion): ?>
                                                        <input type="hidden" name="id_novedad" value="<?= (int) $clase['id_novedad'] ?>">
                                                    <?php else: ?>
                                                        <input type="hidden" name="id_horario" value="<?= (int) $clase['id_horario'] ?>">
                                                    <?php endif; ?>
                                                    <input type="hidden" name="id_grado" value="<?= e((string) $clase['id_grado']) ?>">
                                                    <input type="hidden" name="id_dia" value="<?= (int) $clase['id_dia'] ?>">
                                                    <input type="hidden" name="id_bloque" value="<?= (int) $clase['id_bloque'] ?>">
                                                    <input type="hidden" name="fecha" value="<?= e($fechaEdicion) ?>">
                                                    <input type="hidden" name="volver_a_salon" value="1">
                                                    <input type="hidden" name="salon_regreso" value="<?= (int) $idSalon ?>">
                                                    <input type="hidden" name="guardar_permanente" value="0">
                                                    <input type="hidden" name="reprogramar_siguientes" value="0">
                                                    <input type="hidden" name="restaurar_original" value="0">
                                                    <div class="field">
                                                        <label>Horario</label>
                                                        <input value="<?= e($dia['nombre'] . ' · ' . format_time((string) $clase['hora_inicio']) . ' - ' . format_time((string) $clase['hora_fin'])) ?>" disabled>
                                                    </div>
                                                    <div class="field">
                                                        <label>Grado</label>
                                                        <input value="<?= e((string) $clase['id_grado']) ?>" disabled>
                                                    </div>
                                                    <div class="field">
                                                        <label>Materia</label>
                                                        <select name="id_materia" required>
                                                            <option value="">Seleccione una materia</option>
                                                            <?= options_html($materiasActivas, 'id_materia', 'nombre', $clase['id_materia'], false) ?>
                                                            <?php if (!$materiaActiva): ?>
                                                                <option value="<?= (int) $clase['id_materia'] ?>" selected><?= e((string) $clase['materia']) ?> (inactiva)</option>
                                                            <?php endif; ?>
                                                        </select>
                                                    </div>
                                                    <div class="field">
                                                        <label>Profesor sustituto</label>
                                                        <select name="id_profesor">
                                                            <option value="" <?= empty($clase['id_profesor']) ? 'selected' : '' ?>>Sin profesor</option>
                                                            <?php foreach ($profesoresActivos as $profesorOpcion): ?>
                                                                <option value="<?= (int) $profesorOpcion['id_profesor'] ?>"
                                                                    data-subjects="<?= e((string) ($profesorOpcion['materias_todas'] ?? '')) ?>"
                                                                    data-permanent-subjects="<?= e((string) ($profesorOpcion['materias_permanentes'] ?? '')) ?>"
                                                                    data-subject-counts="<?= e((string) ($profesorOpcion['conteo_materias'] ?? '')) ?>"
                                                                    <?= (int) ($clase['id_profesor'] ?? 0) === (int) $profesorOpcion['id_profesor'] ? 'selected' : '' ?>>
                                                                    <?= e((string) $profesorOpcion['nombre_completo']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                            <?php if (!$profesorActivo): ?>
                                                                <option value="<?= (int) $clase['id_profesor'] ?>" selected><?= e((string) ($clase['profesor'] ?: 'Profesor')) ?> (inactivo)</option>
                                                            <?php endif; ?>
                                                        </select>
                                                        <small class="muted">Elige un docente disponible para cubrir esta clase.</small>
                                                    </div>
                                                    <div class="field">
                                                        <label>Salón</label>
                                                        <input value="<?= e((string) ($clase['salon'] ?: $salonSeleccionado['nombre'])) ?>" disabled>
                                                        <input type="hidden" name="id_salon" value="<?= (int) $idSalon ?>">
                                                    </div>
                                                    <small class="muted" data-room-availability-status aria-live="polite">Consultando disponibilidad...</small>
                                                    <div class="field">
                                                        <label>Estado</label>
                                                        <select name="estado">
                                                            <option value="PROGRAMADA" <?= ($clase['estado'] ?? '') === 'PROGRAMADA' ? 'selected' : '' ?>>PROGRAMADA</option>
                                                            <option value="CANCELADA" <?= ($clase['estado'] ?? '') === 'CANCELADA' ? 'selected' : '' ?>>CANCELADA</option>
                                                        </select>
                                                    </div>
                                                    <div class="field">
                                                        <label>Observación</label>
                                                        <textarea name="observacion" rows="4"><?= e($observacionEdicion) ?></textarea>
                                                    </div>
                                                    <div class="actions">
                                                        <button class="btn btn-primary" type="submit">Guardar cambio para este día</button>
                                                        <button class="btn btn-secondary" type="submit" data-save-permanently data-confirm="El cambio se aplicará permanentemente al horario semanal. Si cancela la clase, se eliminará y el espacio quedará marcado en verde durante 20 segundos. ¿Desea continuar?">Guardar permanentemente</button>
                                                    </div>
                                                </form>
                                            </template>
                                        <?php else: ?>
                                            <article class="room-class c<?= salon_color_materia((string) $clase['materia']) ?> <?= e($claseCss) ?>"
                                                <?= $esTemporal ? 'data-expire-at="' . (int) $clase['expira_timestamp'] * 1000 . '"' : '' ?>>
                                                <strong><?= e((string) $clase['materia']) ?></strong>
                                                <span><?= e((string) $clase['id_grado']) ?></span>
                                                <small><?= e((string) ($clase['profesor'] ?: 'Sin docente')) ?></small>
                                                <?php if (($clase['estado'] ?? '') === 'CANCELADA'): ?><small>CLASE CANCELADA</small><?php endif; ?>
                                            </article>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php
                                    $claveHora = salon_clave_bloque($bloque);
                                    $gradosCompatibles = array_values(array_filter(
                                        $bloquesCompatiblesPorHora[$claveHora] ?? [],
                                        static function (array $opcion) use ($dia, $ocupacionesHorario, $descansosMarcados): bool {
                                            $claveOcupacion = (string) $opcion['id_grado'] . '|' . (int) $dia['id_dia'] . '|' . (int) $opcion['id_bloque'];
                                            return !isset($ocupacionesHorario[$claveOcupacion])
                                                && !isset($descansosMarcados[$claveOcupacion]);
                                        }
                                    ));
                                    $descansosCompatibles = array_values(array_filter(
                                        $descansosCompatiblesPorHora[$claveHora] ?? [],
                                        static function (array $opcion) use ($dia, $horariosExistentes, $descansosMarcados): bool {
                                            $claveOcupacion = (string) $opcion['id_grado'] . '|' . (int) $dia['id_dia'] . '|' . (int) $opcion['id_bloque'];
                                            return !isset($horariosExistentes[$claveOcupacion])
                                                && !isset($descansosMarcados[$claveOcupacion]);
                                        }
                                    ));
                                    $esBloqueDescanso = in_array($meta['kind'], ['break', 'lunch'], true);
                                    $opcionesHorario = $esBloqueDescanso ? $descansosCompatibles : $gradosCompatibles;
                                    $claveModal = preg_replace('/[^a-zA-Z0-9_-]/', '-', $clave . '-' . (int) $dia['id_dia']);
                                    $templateId = 'tpl-room-add-' . $claveModal;
                                    $fechaAsignacion = $fechaValida instanceof DateTimeImmutable
                                        ? $fechaValida->format('Y-m-d')
                                        : salon_fecha_proxima_dia((int) $dia['orden_dia']);
                                    $marcaEliminacionVisible = is_array($marcaEliminacionSalon)
                                        && (int) ($marcaEliminacionSalon['id_salon'] ?? 0) === $idSalon
                                        && (int) ($marcaEliminacionSalon['id_dia'] ?? 0) === (int) $dia['id_dia']
                                        && (string) ($marcaEliminacionSalon['fecha'] ?? '') === $fechaAsignacion
                                        && (string) ($marcaEliminacionSalon['hora_inicio'] ?? '') === format_time((string) $bloque['hora_inicio'])
                                        && (string) ($marcaEliminacionSalon['hora_fin'] ?? '') === format_time((string) $bloque['hora_fin']);
                                    $expiraMarcaEliminacion = $marcaEliminacionVisible
                                        ? (int) $marcaEliminacionSalon['expira_en'] * 1000
                                        : null;
                                    $fechaEditable = !($fechaValida instanceof DateTimeImmutable)
                                        || $fechaValida >= new DateTimeImmutable('today');
                                    $puedeAsignar = $opcionesHorario && $materiasActivas && $profesoresActivos && $fechaEditable;
                                    if (in_array($meta['kind'], ['break', 'lunch'], true) && !$marcaEliminacionVisible):
                                    ?>
                                        <?php if ($puedeAsignar): ?>
                                            <button class="room-break-cell room-break-action" type="button" data-edit="<?= e($templateId) ?>" data-title="Asignar clase durante el <?= $meta['kind'] === 'lunch' ? 'almuerzo' : 'descanso' ?>" aria-label="Asignar clase durante el <?= $meta['kind'] === 'lunch' ? 'almuerzo' : 'descanso' ?>">
                                                <span class="room-span room-span-<?= e($meta['kind']) ?>"><?= e($meta['label']) ?></span>
                                            </button>
                                        <?php else: ?>
                                            <div class="room-break-cell">
                                                <div class="room-span room-span-<?= e($meta['kind']) ?>"><?= e($meta['label']) ?></div>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($puedeAsignar): ?>
                                            <button class="room-empty room-add-class <?= $marcaEliminacionVisible ? 'is-temporary' : '' ?>" type="button"
                                                <?= $expiraMarcaEliminacion ? 'data-expire-at="' . $expiraMarcaEliminacion . '"' : '' ?>
                                                data-edit="<?= e($templateId) ?>" data-title="Asignar clase"
                                                aria-label="Asignar clase el <?= e((string) $dia['nombre']) ?> de <?= e(format_time((string) $bloque['hora_inicio'])) ?> a <?= e(format_time((string) $bloque['hora_fin'])) ?>">
                                                <span aria-hidden="true">+</span>
                                                <span>Asignar clase</span>
                                            </button>
                                    <?php else: ?>
                                        <div class="room-empty <?= $marcaEliminacionVisible ? 'is-temporary' : '' ?>"
                                            <?= $expiraMarcaEliminacion ? 'data-expire-at="' . $expiraMarcaEliminacion . '"' : '' ?>>Disponible</div>
                                    <?php endif; ?>
                                    <?php if ($puedeAsignar): ?>
                                            <template id="<?= e($templateId) ?>">
                                                <form method="post" action="<?= e(APP_BASE) ?>/admin/horarios.php"
                                                    data-room-create-editor
                                                    data-availability-url="<?= e(APP_BASE) ?>/admin/horarios.php?api=salones"
                                                    data-availability-date="<?= e($fechaAsignacion) ?>"
                                                    data-availability-day="<?= (int) $dia['id_dia'] ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="<?= $esBloqueDescanso ? 'create_break_addition' : 'create' ?>">
                                                    <input type="hidden" name="guardar_permanente" value="0">
                                                    <input type="hidden" name="fecha" value="<?= e($fechaAsignacion) ?>">
                                                    <input type="hidden" name="volver_a_salon" value="1">
                                                    <input type="hidden" name="salon_regreso" value="<?= (int) $idSalon ?>">
                                                    <input type="hidden" name="id_dia" value="<?= (int) $dia['id_dia'] ?>">
                                                    <input type="hidden" name="id_bloque" value="">
                                                    <input type="hidden" name="estado" value="PROGRAMADA">
                                                    <div class="field">
                                                        <label>Horario</label>
                                                        <input value="<?= e($dia['nombre']) ?> · <?= e(format_time((string) $bloque['hora_inicio'])) ?> - <?= e(format_time((string) $bloque['hora_fin'])) ?>" disabled>
                                                    </div>
                                                    <div class="field">
                                                        <label>Grado</label>
                                                        <select name="id_grado" data-grade-block required>
                                                            <option value="">Seleccione un grado</option>
                                                            <?php foreach ($opcionesHorario as $opcion): ?>
                                                                <option value="<?= e((string) $opcion['id_grado']) ?>" data-block-id="<?= (int) $opcion['id_bloque'] ?>"><?= e((string) $opcion['id_grado']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="field">
                                                        <label>Materia</label>
                                                        <select name="id_materia" required>
                                                            <option value="">Seleccione una materia</option>
                                                            <?php foreach ($materiasActivas as $materiaOpcion): ?>
                                                                <option value="<?= (int) $materiaOpcion['id_materia'] ?>"><?= e((string) $materiaOpcion['nombre']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="field">
                                                        <label>Profesor</label>
                                                        <select name="id_profesor" required>
                                                            <option value="">Seleccione un profesor</option>
                                                            <?php foreach ($profesoresActivos as $profesorOpcion): ?>
                                                                <option value="<?= (int) $profesorOpcion['id_profesor'] ?>"
                                                                    data-subjects="<?= e((string) ($profesorOpcion['materias_todas'] ?? '')) ?>"
                                                                    data-permanent-subjects="<?= e((string) ($profesorOpcion['materias_permanentes'] ?? '')) ?>"
                                                                    data-subject-counts="<?= e((string) ($profesorOpcion['conteo_materias'] ?? '')) ?>">
                                                                    <?= e((string) $profesorOpcion['nombre_completo']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="field">
                                                        <label>Salón</label>
                                                        <input value="<?= e((string) $salonSeleccionado['nombre']) ?>" disabled>
                                                        <input type="hidden" name="id_salon" value="<?= (int) $idSalon ?>">
                                                    </div>
                                                    <div class="field">
                                                        <label>Observación</label>
                                                        <input name="observacion">
                                                    </div>
                                                    <small class="muted" data-room-create-status aria-live="polite">Seleccione grado para cargar profesores disponibles en este horario.</small>
                                                    <div class="actions">
                                                        <button class="btn btn-primary" type="submit">
                                                            Guardar novedad para este día
                                                        </button>
                                                        <button class="btn btn-secondary" type="submit" data-save-permanently
                                                        data-confirm="La clase se guardará permanentemente en el horario semanal. ¿Desea continuar?">
                                                            Guardar permanentemente
                                                        </button>
                                                    </div>
                                                </form>
                                            </template>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($bloquesSalon === []): ?>
                    <tr>
                        <td colspan="<?= count($diasTabla) + 1 ?>">Este salón no tiene bloques de horario asociados.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
