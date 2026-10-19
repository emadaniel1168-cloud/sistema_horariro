<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin_auth();

$sectionTitle = 'Novedades de horario';
$calendarios = db_all('SELECT id_calendario, nombre FROM calendarios ORDER BY id_calendario');
$bloquesCalendario = db_all(
    "SELECT id_bloque, id_calendario, numero_bloque, descripcion,
            TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio,
            TIME_FORMAT(hora_fin, '%H:%i') AS hora_fin
     FROM bloques_horarios WHERE tipo = 'CLASE' ORDER BY id_calendario, hora_inicio, hora_fin, id_bloque"
);
$grados = db_all('SELECT id_grado, id_calendario FROM grados ORDER BY CAST(SUBSTRING_INDEX(id_grado, \'-\', 1) AS UNSIGNED), CAST(SUBSTRING_INDEX(id_grado, \'-\', -1) AS UNSIGNED)');
$profesores = db_all('SELECT id_profesor, nombre_completo FROM profesores WHERE activo = 1 ORDER BY nombre_completo');
$salones = db_all("SELECT id_salon, nombre FROM salones WHERE activo = 1 ORDER BY CASE WHEN nombre REGEXP '[0-9]+$' THEN 0 ELSE 1 END, CAST(SUBSTRING_INDEX(nombre, ' ', -1) AS UNSIGNED), nombre");
$materias = db_all(
    'SELECT m.id_materia, m.nombre,
            GROUP_CONCAT(DISTINCT h.id_profesor ORDER BY h.id_profesor) AS profesores,
            GROUP_CONCAT(DISTINCT CONCAT(hc.id_profesor, \':\', hc.total) ORDER BY hc.id_profesor) AS conteo_profesores
     FROM materias m
     LEFT JOIN horario_semanal h ON h.id_materia = m.id_materia AND h.id_profesor IS NOT NULL
     LEFT JOIN (
         SELECT id_materia, id_profesor, COUNT(*) AS total
         FROM horario_semanal
         WHERE id_profesor IS NOT NULL AND estado = \'PROGRAMADA\'
         GROUP BY id_materia, id_profesor
     ) hc ON hc.id_materia = m.id_materia AND hc.id_profesor = h.id_profesor
     GROUP BY m.id_materia, m.nombre ORDER BY m.nombre'
);
asegurar_esquema_novedades();
limpiar_avisos_temporales_eliminacion();
asegurar_tabla_novedades_temporales();
asegurar_tabla_horario_original_novedad();
sincronizar_novedades_con_horario();
agrupar_novedades_activas_por_grado_fecha();

function observacion_sin_flechas(string $observacion): string
{
    return preg_replace('/\s*→\s*/u', ', ', $observacion) ?? $observacion;
}

function siguiente_clase_programada_grupo(array $clases): ?array
{
    $grado = trim((string) ($clases[0]['id_grado'] ?? ''));
    if ($grado === '') {
        return null;
    }

    $dia = null;
    $maxBloque = null;
    foreach ($clases as $detalle) {
        if (!empty($detalle['id_dia'])) {
            $dia = (int) $detalle['id_dia'];
        }
        if (!empty($detalle['id_bloque'])) {
            $bloqueActual = (int) $detalle['id_bloque'];
            if ($maxBloque === null || $bloqueActual > $maxBloque) {
                $maxBloque = $bloqueActual;
            }
        }
    }

    if ($dia === null || $maxBloque === null) {
        return null;
    }

    return db_one(
        "SELECT h.id_horario, h.id_grado, h.id_dia, h.id_bloque, h.id_materia, h.id_salon, h.estado,
                b.hora_inicio, b.hora_fin,
                m.nombre AS materia, s.nombre AS salon
         FROM horario_semanal h
         JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
         JOIN materias m ON m.id_materia = h.id_materia
         LEFT JOIN salones s ON s.id_salon = h.id_salon
         WHERE h.id_grado = ? AND h.id_dia = ? AND h.estado = 'PROGRAMADA' AND h.id_bloque > ?
         ORDER BY b.hora_inicio ASC, h.id_horario ASC
         LIMIT 1",
        [$grado, $dia, $maxBloque]
    );
}

function resumir_observaciones_grupo(array $clases): string
{
    $clases = array_values(array_filter($clases, static fn (array $detalle): bool => !empty($detalle['id_grado']) || !empty($detalle['materia']) || !empty($detalle['hora_inicio']) || !empty($detalle['observacion']) || !empty($detalle['hora_nueva_inicio']) || !empty($detalle['materia_nueva']) || !empty($detalle['salon_nuevo'])));
    if ($clases === []) {
        return '';
    }

    $horasEntradaAjuste = array_values(array_unique(array_filter(array_map(
        static fn(array $clase): string => trim((string) ($clase['hora_entrada_ajuste'] ?? '')),
        $clases
    ), static fn(string $hora): bool => preg_match('/^\d{2}:\d{2}$/', $hora) === 1)));
    if (count($horasEntradaAjuste) === 1) {
        return 'Todos los estudiantes entran a las ' . $horasEntradaAjuste[0] . '.';
    }

    $gradosGrupo = array_values(array_unique(array_filter(array_map(
        static fn(array $clase): string => trim((string) ($clase['id_grado'] ?? '')),
        $clases
    ))));
    $esCancelacionGeneralAnterior = count($gradosGrupo) > 1 && count(array_filter(
        $clases,
        static fn(array $clase): bool => ($clase['tipo_cambio'] ?? '') !== 'NOVEDAD'
            || trim((string) ($clase['observacion'] ?? '')) !== 'Clase cancelada desde el horario semanal.'
    )) === 0;
    if ($esCancelacionGeneralAnterior) {
        $clasesPrimerGrado = array_values(array_filter(
            $clases,
            static fn(array $clase): bool => (string) ($clase['id_grado'] ?? '') === $gradosGrupo[0]
        ));
        $proximaClaseGeneral = siguiente_clase_programada_grupo($clasesPrimerGrado);
        $horaEntradaGeneral = trim((string) ($proximaClaseGeneral['hora_inicio'] ?? ''));
        if ($horaEntradaGeneral !== '') {
            return 'Todos los estudiantes entran a las ' . $horaEntradaGeneral . '.';
        }
    }

    $grado = trim((string) ($clases[0]['id_grado'] ?? ''));
    $clasesExtraDescanso = [];
    foreach ($clases as $clase) {
        $idBloque = (int) ($clase['id_bloque'] ?? 0);
        if (!$idBloque || !db_one(
            "SELECT id_bloque_agregado FROM bloques_descansos_reemplazados
             WHERE id_bloque_agregado = ? AND accion IN ('PARCIAL', 'REEMPLAZADO')",
            [$idBloque]
        )) {
            continue;
        }
        $inicio = trim((string) ($clase['hora_inicio'] ?? ''));
        $fin = trim((string) ($clase['hora_fin'] ?? ''));
        if ($inicio !== '' && $fin !== '') {
            $dia = trim((string) ($clase['dia'] ?? ''));
            $clasesExtraDescanso[] = ($dia !== '' ? $dia . ' ' : '') . 'de ' . $inicio . ' a ' . $fin;
        }
    }
    $clasesExtraDescanso = array_values(array_unique($clasesExtraDescanso));
    if ($grado !== '' && $clasesExtraDescanso !== []) {
        return 'El grado ' . $grado . ' tendrá una clase extra durante el descanso: ' .
            implode(' y ', $clasesExtraDescanso) . '.';
    }

    $proximaClase = siguiente_clase_programada_grupo($clases);

    if ($proximaClase) {
        $horaEntrada = trim((string) ($proximaClase['hora_inicio'] ?? ''));
        $materia = trim((string) ($proximaClase['materia'] ?? ''));
        $salon = trim((string) ($proximaClase['salon'] ?? ''));

        if ($grado !== '' && $horaEntrada !== '') {
            $resumen = 'El grado ' . $grado . ' entrarán a las ' . $horaEntrada;
            if ($materia !== '') {
                $resumen .= ' con la clase de ' . $materia;
            }
            if ($salon !== '') {
                $resumen .= ' en el salón ' . $salon;
            }
            $resumen .= ' y horario normal.';
            return $resumen;
        }
    }

    $horaEntrada = '';
    $materia = '';
    $salon = '';

    $clasesOrdenadas = $clases;
    usort($clasesOrdenadas, static function (array $a, array $b): int {
        $horaA = trim((string) ($a['hora_fin'] ?? $a['hora_nueva_fin'] ?? $a['hora_nueva_inicio'] ?? $a['hora_inicio'] ?? ''));
        $horaB = trim((string) ($b['hora_fin'] ?? $b['hora_nueva_fin'] ?? $b['hora_nueva_inicio'] ?? $b['hora_inicio'] ?? ''));

        if ($horaA === '' && $horaB === '') {
            return 0;
        }
        if ($horaA === '') {
            return 1;
        }
        if ($horaB === '') {
            return -1;
        }

        return strcmp($horaA, $horaB);
    });

    foreach ($clasesOrdenadas as $detalle) {
        $horaFin = trim((string) ($detalle['hora_fin'] ?? $detalle['hora_nueva_fin'] ?? ''));
        $horaInicio = trim((string) ($detalle['hora_nueva_inicio'] ?? $detalle['hora_inicio'] ?? ''));

        if ($horaFin !== '' && ($horaEntrada === '' || strcmp($horaFin, $horaEntrada) > 0)) {
            $horaEntrada = $horaFin;
        }

        if ($horaEntrada === '' && $horaInicio !== '') {
            $horaEntrada = $horaInicio;
        }

        $materiaDetalle = trim((string) ($detalle['materia_nueva'] ?? $detalle['materia'] ?? ''));
        if ($materia === '' && $materiaDetalle !== '') {
            $materia = $materiaDetalle;
        }

        $salonDetalle = trim((string) ($detalle['salon_nuevo'] ?? $detalle['salon_actual'] ?? ''));
        if ($salon === '' && $salonDetalle !== '') {
            $salon = $salonDetalle;
        }
    }

    if ($grado !== '' && $horaEntrada !== '') {
        $resumen = 'El grado ' . $grado . ' entrarán a las ' . $horaEntrada;
        if ($materia !== '') {
            $resumen .= ' con la clase de ' . $materia;
        }
        if ($salon !== '') {
            $resumen .= ' en el salón ' . $salon;
        }
        $resumen .= ' y horario normal.';
        return $resumen;
    }

    if ($grado !== '' && $materia !== '' && $salon !== '') {
        $resumen = 'El grado ' . $grado . ' entrarán a las ' . ($horaEntrada !== '' ? $horaEntrada : 'la hora programada');
        $resumen .= ' con la clase de ' . $materia;
        $resumen .= ' en el salón ' . $salon . ' y horario normal.';
        return $resumen;
    }

    $observaciones = [];
    foreach ($clases as $detalle) {
        $texto = trim((string) ($detalle['observacion'] ?? ''));
        if ($texto !== '') {
            $observaciones[] = $texto;
        }
    }

    $observaciones = array_values(array_unique($observaciones));
    if ($observaciones === []) {
        return '';
    }

    return implode(' | ', $observaciones);
}

function profesor_anterior_novedad(array $novedad): string
{
    $observacion = (string) ($novedad['observacion'] ?? '');
    if (preg_match('/(?:^|\n)Profesor:\s*(.+?)\s*→\s*.+$/mu', $observacion, $matches) === 1) {
        return trim($matches[1]);
    }
    if (preg_match('/(?:^|\n)Profesor:\s*([^,\n]+),\s*.+$/u', $observacion, $matches) === 1) {
        return trim($matches[1]);
    }
    return trim((string) ($novedad['profesor_actual'] ?? '')) ?: 'Sin profesor';
}

function grupo_novedad_para_grado_fecha(?string $idGrado, ?string $fecha): string
{
    return 'grado:' . trim((string) ($idGrado ?? '')) . ':' . trim((string) ($fecha ?? ''));
}

function parse_grupo_novedad(string $grupo): ?array
{
    if (preg_match('/^[a-f0-9]{32}$/', $grupo) === 1) {
        return ['tipo' => 'grupo', 'valor' => $grupo];
    }
    if (preg_match('/^grado:(.+):(\d{4}-\d{2}-\d{2})$/', $grupo, $matches) === 1) {
        return ['tipo' => 'grado_fecha', 'grado' => $matches[1], 'fecha' => $matches[2]];
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action', '');
    try {
        if ($action === 'create' || $action === 'update') {
            $idHorario = (int) post('id_horario', '0');
            $idCalendario = (int) post('id_calendario', '0');
            $fecha = post('fecha');
            $nuevoProfesor = (string) post('nuevo_profesor', '');
            $nuevaMateria = (string) post('nueva_materia', '');
            $nuevoSalon = (string) post('nuevo_salon', '');
            $observacion = trim(observacion_sin_flechas((string) post('observacion', '')));
            $estado = post('estado', 'ACTIVA');

            if (!$idHorario || !$fecha || !$idCalendario) {
                throw new RuntimeException('Debe seleccionar calendario, clase y fecha de aplicación.');
            }
            if (!in_array($estado, ['ACTIVA', 'CANCELADA'], true)) {
                throw new RuntimeException('El estado no es válido.');
            }
            if (!exists_id('horario_semanal', 'id_horario', $idHorario)) {
                throw new RuntimeException('El horario seleccionado no existe.');
            }
            $horarioActual = db_one(
                'SELECT h.id_horario, h.id_grado, h.id_dia, h.id_profesor, h.id_materia, h.id_salon, b.id_calendario, d.nombre AS dia,
                        b.hora_inicio, b.hora_fin, m.nombre AS materia
                 FROM horario_semanal h
                 JOIN dias_semana d ON d.id_dia = h.id_dia
                 JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                 JOIN materias m ON m.id_materia = h.id_materia
                 WHERE h.id_horario = ?',
                [$idHorario]
            );
            if (!$horarioActual) {
                throw new RuntimeException('No se encontró la clase asociada a la novedad.');
            }
            if ((int) $horarioActual['id_calendario'] !== $idCalendario) {
                throw new RuntimeException('La clase no pertenece al calendario seleccionado.');
            }
            $profesorActualId = $horarioActual['id_profesor'] ? (int) $horarioActual['id_profesor'] : null;
            $materiaActualId = (int) $horarioActual['id_materia'];
            $salonActualId = $horarioActual['id_salon'] ? (int) $horarioActual['id_salon'] : null;
            $profesorId = $nuevoProfesor !== '' ? (int) $nuevoProfesor : $profesorActualId;
            $materiaId = $nuevaMateria !== '' ? (int) $nuevaMateria : $materiaActualId;
            $salonId = $nuevoSalon !== '' ? (int) $nuevoSalon : $salonActualId;
            if ($profesorId && !exists_id('profesores', 'id_profesor', $profesorId)) {
                throw new RuntimeException('El profesor seleccionado no existe.');
            }
            if ($salonId && !exists_id('salones', 'id_salon', $salonId)) {
                throw new RuntimeException('El salón seleccionado no existe.');
            }
            if (!$materiaId || !exists_id('materias', 'id_materia', $materiaId)) {
                throw new RuntimeException('La materia seleccionada no existe.');
            }
            $profesorCambio = $profesorId !== $profesorActualId;
            $materiaCambio = $materiaId !== $materiaActualId;
            $salonCambio = $salonId !== $salonActualId;
            $cambiosRealizados = (int) $profesorCambio + (int) $materiaCambio + (int) $salonCambio;
            if ($cambiosRealizados === 0 && $action === 'create') {
                throw new RuntimeException('Modifica al menos un dato para registrar la novedad.');
            }
            if ($action === 'create' && $profesorId !== null && ($profesorCambio || $materiaCambio) && db_count('SELECT COUNT(*) AS total FROM horario_semanal WHERE id_profesor = ? AND id_materia = ?', [$profesorId, $materiaId]) === 0) {
                throw new RuntimeException('La materia seleccionada no está asignada a ese profesor.');
            }
            $profesorGuardado = $profesorCambio ? $profesorId : null;
            $materiaGuardada = $materiaCambio ? $materiaId : null;
            $salonGuardado = $salonCambio ? $salonId : null;
            $fechaValida = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
            if (!$fechaValida || $fechaValida->format('Y-m-d') !== $fecha) {
                throw new RuntimeException('La fecha seleccionada no es válida.');
            }
            if ((int) $fechaValida->format('N') !== (int) $horarioActual['id_dia']) {
                throw new RuntimeException('La fecha debe corresponder al día semanal de la clase: ' . $horarioActual['dia'] . '.');
            }
            if ($estado === 'ACTIVA' && $cambiosRealizados > 0) {
                validar_disponibilidad_horario(
                    (int) $fechaValida->format('N'),
                    $horarioActual['hora_inicio'],
                    $horarioActual['hora_fin'],
                    $profesorId,
                    $salonId,
                    $idHorario,
                    $fecha
                );
            }

            if ($action === 'create') {
                db_exec(
                    'INSERT INTO novedades_cambios_horario (tipo_cambio, id_horario, fecha, nuevo_profesor, nueva_materia, nuevo_salon, observacion, estado)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    ['NOVEDAD', $idHorario, $fecha, $profesorGuardado, $materiaGuardada, $salonGuardado, $observacion, $estado]
                );
                sincronizar_observacion_horario_novedad($idHorario, $observacion);
                flash_set('success', 'Novedad registrada para ' . $fecha . ': grado ' . $horarioActual['id_grado'] . ', ' . $horarioActual['materia'] . '.');
            } else {
                $id = (int) post('id_novedad', '0');
                $novedadAnterior = db_one('SELECT id_horario FROM novedades_cambios_horario WHERE id_cambio = ? AND fecha IS NOT NULL', [$id]);
                db_exec(
                    'UPDATE novedades_cambios_horario
                     SET id_horario = ?, fecha = ?, nuevo_profesor = ?, nueva_materia = ?, nuevo_salon = ?, observacion = ?, estado = ?
                     WHERE id_cambio = ? AND fecha IS NOT NULL',
                    [$idHorario, $fecha, $profesorGuardado, $materiaGuardada, $salonGuardado, $observacion, $estado, $id]
                );
                sincronizar_observacion_horario_novedad($idHorario, $observacion);
                if ($estado === 'CANCELADA') {
                    foreach (array_unique(array_filter([$idHorario, (int) ($novedadAnterior['id_horario'] ?? 0)])) as $horarioAfectado) {
                        restaurar_horario_original_si_no_hay_novedades((int) $horarioAfectado);
                    }
                }
                flash_set('success', 'Novedad actualizada para ' . $fecha . ': grado ' . $horarioActual['id_grado'] . ', ' . $horarioActual['materia'] . '.');
            }
        } elseif ($action === 'update_observations') {
            $grupo = post('grupo_novedad', '');
            $grupoInfo = parse_grupo_novedad($grupo);
            $idsNovedad = $_POST['ids_novedad'] ?? [];
            $idsTemporales = $_POST['ids_temporales'] ?? [];
            $observaciones = $_POST['observaciones'] ?? [];
            $observacionGeneral = trim((string) (post('observacion_general', '') ?: ''));
            if (($grupo !== '' && $grupoInfo === null)
                || !is_array($idsNovedad) || !is_array($idsTemporales) || !is_array($observaciones)) {
                throw new RuntimeException('Los datos para actualizar las observaciones no son válidos.');
            }

            $horariosAfectados = [];
            foreach ($idsNovedad as $idNovedad) {
                $idNovedad = (int) $idNovedad;
                $novedad = db_one(
                    'SELECT n.grupo_novedad, COALESCE(h.id_grado, n.id_grado) AS id_grado, n.fecha, n.id_horario
                     FROM novedades_cambios_horario n
                     LEFT JOIN horario_semanal h ON h.id_horario = n.id_horario
                     WHERE n.id_cambio = ?',
                    [$idNovedad]
                );
                if (!$novedad) {
                    throw new RuntimeException('Una de las novedades ya no existe.');
                }
                $coincideGrupo = $grupo === ''
                    || (($grupoInfo['tipo'] ?? '') === 'grupo'
                        ? (string) ($novedad['grupo_novedad'] ?? '') === $grupo
                        : ((string) ($novedad['id_grado'] ?? '') === (string) ($grupoInfo['grado'] ?? '')
                            && (string) ($novedad['fecha'] ?? '') === (string) ($grupoInfo['fecha'] ?? '')));
                if (!$coincideGrupo) {
                    throw new RuntimeException('Una de las novedades ya no pertenece a este grupo.');
                }
                $observacionActualizada = $observacionGeneral !== ''
                    ? $observacionGeneral
                    : trim((string) ($observaciones[$idNovedad] ?? ''));
                if (!empty($novedad['id_horario'])) {
                    $horariosAfectados[(int) $novedad['id_horario']] = $observacionActualizada;
                }
                db_exec(
                    'UPDATE novedades_cambios_horario SET observacion = ? WHERE id_cambio = ?',
                    [$observacionActualizada, $idNovedad]
                );
            }

            foreach ($idsTemporales as $idTemporal) {
                $idTemporal = (int) $idTemporal;
                $novedadTemporal = db_one(
                    'SELECT t.grupo_novedad, h.id_grado, DATE(t.creado_en) AS fecha, t.id_horario
                     FROM novedades_temporales t
                     JOIN horario_semanal h ON h.id_horario = t.id_horario
                     WHERE t.id_temporal = ? AND t.expira_en > NOW()',
                    [$idTemporal]
                );
                if (!$novedadTemporal || $grupo === '') {
                    throw new RuntimeException('Una de las novedades temporales ya no pertenece a este grupo.');
                }
                $coincideGrupo = ($grupoInfo['tipo'] ?? '') === 'grupo'
                    ? (string) ($novedadTemporal['grupo_novedad'] ?? '') === $grupo
                    : ((string) ($novedadTemporal['id_grado'] ?? '') === (string) ($grupoInfo['grado'] ?? '')
                        && (string) ($novedadTemporal['fecha'] ?? '') === (string) ($grupoInfo['fecha'] ?? ''));
                if (!$coincideGrupo) {
                    throw new RuntimeException('Una de las novedades temporales ya no pertenece a este grupo.');
                }
                $observacionActualizada = $observacionGeneral !== ''
                    ? $observacionGeneral
                    : trim((string) ($observaciones['temporal_' . $idTemporal] ?? ''));
                $horariosAfectados[(int) $novedadTemporal['id_horario']] = $observacionActualizada;
                db_exec(
                    'UPDATE novedades_temporales SET observacion = ? WHERE id_temporal = ?',
                    [$observacionActualizada, $idTemporal]
                );
            }

            foreach ($horariosAfectados as $idHorario => $observacionActualizada) {
                sincronizar_observacion_horario_novedad((int) $idHorario, (string) $observacionActualizada);
            }
            flash_set('success', 'Observaciones actualizadas correctamente.');
        } elseif ($action === 'toggle') {
            $id = (int) post('id_novedad', '0');
            $grupo = post('grupo_novedad', '');
            $grupoInfo = parse_grupo_novedad($grupo);
            $seDesactivo = false;
            db()->beginTransaction();
            try {
                if ($grupo !== '') {
                    if ($grupoInfo === null) {
                        throw new RuntimeException('El grupo de novedades no es válido.');
                    }
                    if ($grupoInfo['tipo'] === 'grupo') {
                        $activas = db_count("SELECT COUNT(*) AS total FROM novedades_cambios_horario WHERE grupo_novedad = ? AND estado = 'ACTIVA'", [$grupo]);
                        if (db_count('SELECT COUNT(*) AS total FROM novedades_cambios_horario WHERE grupo_novedad = ?', [$grupo]) === 0) {
                            throw new RuntimeException('La novedad no existe.');
                        }
                        if ($activas > 0) {
                            $seDesactivo = true;
                            $horariosAfectados = db_all('SELECT DISTINCT id_horario FROM novedades_cambios_horario WHERE grupo_novedad = ?', [$grupo]);
                            db_exec("UPDATE novedades_cambios_horario SET estado = 'CANCELADA' WHERE grupo_novedad = ? AND estado = 'ACTIVA'", [$grupo]);
                            foreach ($horariosAfectados as $horarioAfectado) {
                                if (!empty($horarioAfectado['id_horario'])) {
                                    restaurar_horario_original_si_no_hay_novedades((int) $horarioAfectado['id_horario']);
                                }
                            }
                        } else {
                            db_exec("UPDATE novedades_cambios_horario SET estado = 'ACTIVA' WHERE grupo_novedad = ? AND estado = 'CANCELADA'", [$grupo]);
                        }
                    } else {
                        $activas = db_count(
                            "SELECT COUNT(*) AS total FROM novedades_cambios_horario n JOIN horario_semanal h ON h.id_horario = n.id_horario WHERE h.id_grado = ? AND n.fecha = ? AND n.estado = 'ACTIVA'",
                            [$grupoInfo['grado'], $grupoInfo['fecha']]
                        );
                        $total = db_count(
                            'SELECT COUNT(*) AS total FROM novedades_cambios_horario n JOIN horario_semanal h ON h.id_horario = n.id_horario WHERE h.id_grado = ? AND n.fecha = ? AND n.fecha IS NOT NULL',
                            [$grupoInfo['grado'], $grupoInfo['fecha']]
                        );
                        if ($total === 0) {
                            throw new RuntimeException('La novedad no existe.');
                        }
                        if ($activas > 0) {
                            $seDesactivo = true;
                            $horariosAfectados = db_all(
                                'SELECT DISTINCT n.id_horario FROM novedades_cambios_horario n JOIN horario_semanal h ON h.id_horario = n.id_horario WHERE h.id_grado = ? AND n.fecha = ? AND n.fecha IS NOT NULL',
                                [$grupoInfo['grado'], $grupoInfo['fecha']]
                            );
                            db_exec(
                                "UPDATE novedades_cambios_horario n JOIN horario_semanal h ON h.id_horario = n.id_horario SET n.estado = 'CANCELADA' WHERE h.id_grado = ? AND n.fecha = ? AND n.fecha IS NOT NULL AND n.estado = 'ACTIVA'",
                                [$grupoInfo['grado'], $grupoInfo['fecha']]
                            );
                            foreach ($horariosAfectados as $horarioAfectado) {
                                if (!empty($horarioAfectado['id_horario'])) {
                                    restaurar_horario_original_si_no_hay_novedades((int) $horarioAfectado['id_horario']);
                                }
                            }
                        } else {
                            db_exec(
                                "UPDATE novedades_cambios_horario n JOIN horario_semanal h ON h.id_horario = n.id_horario SET n.estado = 'ACTIVA' WHERE h.id_grado = ? AND n.fecha = ? AND n.fecha IS NOT NULL AND n.estado = 'CANCELADA'",
                                [$grupoInfo['grado'], $grupoInfo['fecha']]
                            );
                        }
                    }
                } else {
                    $row = db_one('SELECT estado, id_horario FROM novedades_cambios_horario WHERE id_cambio = ?', [$id]);
                    if (!$row) {
                        throw new RuntimeException('La novedad no existe.');
                    }
                    if ($row['estado'] === 'ACTIVA') {
                        $seDesactivo = true;
                        db_exec("UPDATE novedades_cambios_horario SET estado = 'CANCELADA' WHERE id_cambio = ?", [$id]);
                        if (!empty($row['id_horario'])) {
                            restaurar_horario_original_si_no_hay_novedades((int) $row['id_horario']);
                        }
                    } else {
                        db_exec("UPDATE novedades_cambios_horario SET estado = 'ACTIVA' WHERE id_cambio = ? AND estado = 'CANCELADA'", [$id]);
                    }
                }
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
            flash_set('success', $seDesactivo
                ? 'La novedad se desactivó y quedó guardada; los horarios sin otras novedades activas volvieron a sus valores originales.'
                : 'La novedad se activó nuevamente.');
        } elseif ($action === 'delete') {
            $id = (int) post('id_novedad', '0');
            $grupo = post('grupo_novedad', '');
            $grupoInfo = parse_grupo_novedad($grupo);
            if ($grupo !== '') {
                if ($grupoInfo === null) {
                    throw new RuntimeException('El grupo de novedades no es válido.');
                }
                if ($grupoInfo['tipo'] === 'grupo') {
                    $horariosAfectados = db_all('SELECT DISTINCT id_horario FROM novedades_cambios_horario WHERE grupo_novedad = ?', [$grupo]);
                    db_exec('DELETE FROM novedades_cambios_horario WHERE grupo_novedad = ?', [$grupo]);
                    foreach ($horariosAfectados as $horarioAfectado) {
                        if (!empty($horarioAfectado['id_horario'])) {
                            restaurar_horario_original_si_no_hay_novedades((int) $horarioAfectado['id_horario']);
                        }
                    }
                } else {
                    $horariosAfectados = db_all(
                        'SELECT DISTINCT n.id_horario FROM novedades_cambios_horario n JOIN horario_semanal h ON h.id_horario = n.id_horario WHERE h.id_grado = ? AND n.fecha = ? AND n.fecha IS NOT NULL',
                        [$grupoInfo['grado'], $grupoInfo['fecha']]
                    );
                    db_exec(
                        'DELETE n FROM novedades_cambios_horario n JOIN horario_semanal h ON h.id_horario = n.id_horario WHERE h.id_grado = ? AND n.fecha = ? AND n.fecha IS NOT NULL',
                        [$grupoInfo['grado'], $grupoInfo['fecha']]
                    );
                    foreach ($horariosAfectados as $horarioAfectado) {
                        if (!empty($horarioAfectado['id_horario'])) {
                            restaurar_horario_original_si_no_hay_novedades((int) $horarioAfectado['id_horario']);
                        }
                    }
                }
            } else {
                $novedad = db_one('SELECT id_horario FROM novedades_cambios_horario WHERE id_cambio = ?', [$id]);
                db_exec('DELETE FROM novedades_cambios_horario WHERE id_cambio = ?', [$id]);
                if (!empty($novedad['id_horario'])) {
                    restaurar_horario_original_si_no_hay_novedades((int) $novedad['id_horario']);
                }
            }
            flash_set('success', 'Registro eliminado correctamente.');
        } elseif ($action === 'delete_all') {
            $horariosAfectados = db_all('SELECT DISTINCT id_horario FROM novedades_cambios_horario WHERE id_horario IS NOT NULL');
            db_exec('DELETE FROM novedades_cambios_horario');
            db_exec('DELETE FROM novedades_temporales');
            foreach ($horariosAfectados as $horarioAfectado) {
                if (!empty($horarioAfectado['id_horario'])) {
                    restaurar_horario_original_si_no_hay_novedades((int) $horarioAfectado['id_horario']);
                }
            }
            flash_set('success', 'Se eliminaron todas las novedades correctamente.');
        } elseif ($action === 'delete_temporary') {
            $grupo = post('grupo_novedad', '');
            if (!preg_match('/^[a-f0-9]{32}$/', $grupo)) {
                throw new RuntimeException('El grupo de novedades temporales no es válido.');
            }
            db_exec('DELETE FROM novedades_temporales WHERE grupo_novedad = ?', [$grupo]);
            flash_set('success', 'Novedad temporal eliminada correctamente.');
        } elseif ($action === 'dismiss_deleted') {
            $id = (int) post('id_novedad', '0');
            $aviso = db_one(
                "SELECT id_cambio FROM novedades_cambios_horario
                 WHERE id_cambio = ? AND tipo_cambio = 'ELIMINACION'
                   AND creado_en > DATE_SUB(NOW(), INTERVAL 3 MINUTE)",
                [$id]
            );
            if (!$aviso) {
                throw new RuntimeException('El aviso temporal ya no está disponible.');
            }
            db_exec('DELETE FROM novedades_cambios_horario WHERE id_cambio = ?', [$id]);
            flash_set('success', 'Aviso eliminado de la tabla. La clase permanece eliminada del horario.');
        }
    } catch (PDOException $e) {
        flash_set('error', unique_error($e, 'No se pudo guardar la información. Verifique los datos e inténtelo nuevamente.'));
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(APP_BASE . '/admin/novedades.php');
}

$q = get('q', '');
$estadoF = get('estado');
$rows = db_all(
        "SELECT n.id_cambio AS id_novedad, n.id_horario, n.tipo_cambio, n.grupo_novedad, n.fecha,
            n.nuevo_profesor, n.nueva_materia, n.nuevo_salon, n.nueva_hora_inicio, n.nueva_hora_fin,
                        CASE WHEN n.detalle LIKE 'AJUSTE_GENERAL_ENTRADA=%' THEN SUBSTRING_INDEX(n.detalle, '=', -1) END AS hora_entrada_ajuste,
              CASE WHEN n.tipo_cambio = 'ELIMINACION' AND n.estado <> 'ARCHIVADO'
                      AND n.creado_en > DATE_SUB(NOW(), INTERVAL 3 MINUTE) THEN 'ELIMINADA'
                  ELSE n.estado END AS estado,
              n.creado_en,
            COALESCE(NULLIF(n.observacion, ''), n.detalle, '') AS observacion,
            COALESCE(h.id_grado, n.id_grado) AS id_grado,
            COALESCE(h.id_dia, n.id_dia) AS id_dia,
            COALESCE(h.id_bloque, n.id_bloque) AS id_bloque,
            COALESCE(b.id_calendario, g.id_calendario) AS id_calendario,
            COALESCE(d.nombre, n.dia, 'Horario semanal') AS dia,
            COALESCE(m.nombre, n.materia, 'Sin materia') AS materia,
            mn.nombre AS materia_nueva,
            TIME_FORMAT(COALESCE(b.hora_inicio, n.hora_inicio), '%H:%i') AS hora_inicio,
            TIME_FORMAT(COALESCE(b.hora_fin, n.hora_fin), '%H:%i') AS hora_fin,
            COALESCE(p.nombre_completo, n.profesor) AS profesor_actual,
            COALESCE(s.nombre, n.salon) AS salon_actual,
            pn.nombre_completo AS profesor_nuevo, sn.nombre AS salon_nuevo,
            TIME_FORMAT(n.nueva_hora_inicio, '%H:%i') AS hora_nueva_inicio,
            TIME_FORMAT(n.nueva_hora_fin, '%H:%i') AS hora_nueva_fin,
            CASE WHEN n.tipo_cambio = 'ELIMINACION' AND n.estado <> 'ARCHIVADO'
                      AND n.creado_en > DATE_SUB(NOW(), INTERVAL 3 MINUTE) THEN 1 ELSE 0 END AS eliminada,
            CASE WHEN n.tipo_cambio = 'ELIMINACION' AND n.estado <> 'ARCHIVADO'
                      AND n.creado_en > DATE_SUB(NOW(), INTERVAL 3 MINUTE) THEN 1 ELSE 0 END AS temporal,
            CASE WHEN n.tipo_cambio = 'ELIMINACION' AND n.estado <> 'ARCHIVADO'
                      AND n.creado_en > DATE_SUB(NOW(), INTERVAL 3 MINUTE)
                 THEN DATE_ADD(n.creado_en, INTERVAL 3 MINUTE) END AS expira_en,
            CASE WHEN n.tipo_cambio = 'ELIMINACION' AND n.estado <> 'ARCHIVADO'
                      AND n.creado_en > DATE_SUB(NOW(), INTERVAL 3 MINUTE)
                 THEN UNIX_TIMESTAMP(DATE_ADD(n.creado_en, INTERVAL 3 MINUTE)) END AS expira_timestamp
    FROM novedades_cambios_horario n
     LEFT JOIN horario_semanal h ON h.id_horario = n.id_horario
     LEFT JOIN grados g ON g.id_grado = COALESCE(h.id_grado, n.id_grado)
     LEFT JOIN dias_semana d ON d.id_dia = COALESCE(h.id_dia, n.id_dia)
     LEFT JOIN materias m ON m.id_materia = COALESCE(h.id_materia, n.nueva_materia)
     LEFT JOIN bloques_horarios b ON b.id_bloque = COALESCE(h.id_bloque, n.id_bloque)
    LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
    LEFT JOIN salones s ON s.id_salon = h.id_salon
     LEFT JOIN profesores pn ON pn.id_profesor = n.nuevo_profesor
     LEFT JOIN salones sn ON sn.id_salon = n.nuevo_salon
    LEFT JOIN materias mn ON mn.id_materia = n.nueva_materia
    WHERE n.tipo_cambio NOT IN ('ADICION', 'BLOQUE')
    ORDER BY COALESCE(n.fecha, DATE(n.creado_en)) DESC, n.id_cambio DESC",
);
$novedadesAdicion = db_all(
    "SELECT n.id_cambio AS id_novedad, n.id_horario, n.tipo_cambio, n.grupo_novedad, n.fecha,
            n.nuevo_profesor, n.nueva_materia, n.nuevo_salon, n.observacion, n.estado,
            n.id_grado, n.id_dia, n.id_bloque, n.dia, n.materia, n.nueva_materia AS id_materia,
            NULL AS materia_nueva,
            TIME_FORMAT(n.hora_inicio, '%H:%i') AS hora_inicio,
            TIME_FORMAT(n.hora_fin, '%H:%i') AS hora_fin,
            n.profesor AS profesor_actual, NULL AS profesor_nuevo,
            n.salon AS salon_actual, NULL AS salon_nuevo,
            NULL AS hora_nueva_inicio, NULL AS hora_nueva_fin, 1 AS adicion
     FROM novedades_cambios_horario n
    WHERE n.tipo_cambio = 'ADICION'
     ORDER BY n.fecha DESC, n.id_cambio DESC"
);
array_push($rows, ...$novedadesAdicion);
$novedadesBloques = db_all(
    "SELECT n.id_cambio AS id_novedad, n.id_horario, n.tipo_cambio, n.grupo_novedad, n.fecha,
            n.estado, n.id_grado, n.id_bloque, b.numero_bloque, b.tipo AS tipo_bloque,
            TIME_FORMAT(b.hora_inicio, '%H:%i') AS hora_inicio,
            TIME_FORMAT(b.hora_fin, '%H:%i') AS hora_fin,
            'Horario semanal' AS dia, CONCAT('Bloque ', b.numero_bloque) AS materia,
            NULL AS materia_nueva, NULL AS profesor_actual, NULL AS profesor_nuevo,
            NULL AS salon_actual, NULL AS salon_nuevo,
            NULL AS hora_nueva_inicio, NULL AS hora_nueva_fin,
            n.observacion, 1 AS bloque_agregado
     FROM novedades_cambios_horario n
    LEFT JOIN bloques_horarios b ON b.id_bloque = n.id_bloque
     WHERE n.tipo_cambio = 'BLOQUE' AND b.tipo = 'DESCANSO'
     ORDER BY n.fecha DESC, n.id_cambio DESC"
);
array_push($rows, ...$novedadesBloques);
$novedadesTemporales = $estadoF && $estadoF !== 'ACTIVA' ? [] : db_all(
        'SELECT t.id_temporal, t.grupo_novedad, t.id_horario, t.expira_en,
            UNIX_TIMESTAMP(t.expira_en) AS expira_timestamp,
            t.observacion, DATE(t.creado_en) AS fecha, \'ACTIVA\' AS estado,
            h.id_grado, h.id_dia, h.id_profesor, h.id_materia, h.id_salon, h.id_bloque,
            d.nombre AS dia, m.nombre AS materia, NULL AS materia_nueva,
            TIME_FORMAT(b.hora_inicio, \'%H:%i\') AS hora_inicio, TIME_FORMAT(b.hora_fin, \'%H:%i\') AS hora_fin,
            p.nombre_completo AS profesor_actual, p.nombre_completo AS profesor_nuevo,
            s.nombre AS salon_actual, NULL AS salon_nuevo,
            NULL AS hora_nueva_inicio, NULL AS hora_nueva_fin
     FROM novedades_temporales t
     JOIN horario_semanal h ON h.id_horario = t.id_horario
     JOIN dias_semana d ON d.id_dia = h.id_dia
     JOIN materias m ON m.id_materia = h.id_materia
     JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
     LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
     LEFT JOIN salones s ON s.id_salon = h.id_salon
     WHERE t.expira_en > NOW()
     ORDER BY t.creado_en DESC'
);
foreach ($novedadesTemporales as $novedadTemporal) {
    $novedadTemporal['temporal'] = true;
    $novedadTemporal['id_novedad'] = 0;
    $rows[] = $novedadTemporal;
}
$filasAgrupadas = [];
foreach ($rows as $row) {
    $gradoKey = (string) ($row['id_grado'] ?? 'sin-grado');
    $fechaKey = (string) ($row['fecha'] ?? 'sin-fecha');
    if (!empty($row['eliminada'])) {
        $claveGrupo = 'eliminada-' . (int) $row['id_novedad'];
    } elseif (!empty($row['adicion'])) {
        $claveGrupo = 'adicion-' . (int) $row['id_novedad'];
    } elseif (!empty($row['bloque_agregado'])) {
        $claveGrupo = 'bloque-' . (string) ($row['grupo_novedad'] ?? $row['id_novedad']);
    } elseif (!empty($row['temporal'])) {
        $claveGrupo = 'temporal-' . $gradoKey . '-' . $fechaKey;
    } elseif (!empty($row['grupo_novedad'])) {
        $claveGrupo = 'grupo-id-' . (string) $row['grupo_novedad'];
    } elseif (empty($row['fecha'])) {
        $claveGrupo = 'registro-' . (int) $row['id_novedad'];
    } else {
        $claveGrupo = 'grupo-' . $gradoKey . '-' . $fechaKey;
    }

    if (!isset($filasAgrupadas[$claveGrupo])) {
        $filasAgrupadas[$claveGrupo] = $row;
        $filasAgrupadas[$claveGrupo]['clases_afectadas'] = [];
    }
    $filasAgrupadas[$claveGrupo]['clases_afectadas'][] = $row;
}

foreach ($filasAgrupadas as &$grupo) {
    $grupo['observacion_general'] = !empty($grupo['eliminada'])
        ? 'Clase eliminada permanentemente del horario semanal.'
        : (!empty($grupo['adicion'])
            ? 'Clase añadida únicamente para la fecha seleccionada.'
            : (!empty($grupo['bloque_agregado'])
                ? (string) ($grupo['observacion'] ?? '')
                    : (empty($grupo['fecha']) && !empty($grupo['observacion'])
                        ? (string) $grupo['observacion']
                        : resumir_observaciones_grupo($grupo['clases_afectadas']))));
    usort($grupo['clases_afectadas'], static function (array $a, array $b): int {
        $horaA = (string) ($a['hora_inicio'] ?? '23:59');
        $horaB = (string) ($b['hora_inicio'] ?? '23:59');
        return strcmp($horaA, $horaB);
    });
}
unset($grupo);

$rows = array_values(array_filter($filasAgrupadas, static function (array $grupo) use ($q, $estadoF): bool {
    foreach ($grupo['clases_afectadas'] as $detalle) {
        $estadoDetalle = (string) ($detalle['estado'] ?? '');
        if ($estadoF === 'HISTORICO' && !in_array($estadoDetalle, ['HISTORICO', 'ARCHIVADO'], true)) {
            continue;
        }
        if ($estadoF && $estadoF !== 'HISTORICO' && $estadoDetalle !== $estadoF) {
            continue;
        }
        if ($q !== '' && stripos(implode(' ', array_filter([
            $detalle['id_grado'] ?? '', $detalle['materia'] ?? '', $detalle['profesor_actual'] ?? '',
            $detalle['profesor_nuevo'] ?? '', $detalle['salon_actual'] ?? '', $detalle['salon_nuevo'] ?? '',
            $detalle['observacion'] ?? '',
        ])), $q) === false) {
            continue;
        }
        return true;
    }
    return false;
}));

usort($rows, static function (array $a, array $b): int {
    $historicaA = in_array((string) ($a['estado'] ?? ''), ['HISTORICO', 'ARCHIVADO'], true);
    $historicaB = in_array((string) ($b['estado'] ?? ''), ['HISTORICO', 'ARCHIVADO'], true);
    if ($historicaA !== $historicaB) {
        return $historicaA <=> $historicaB;
    }

    $horaA = (string) ($a['clases_afectadas'][0]['hora_inicio'] ?? '23:59');
    $horaB = (string) ($b['clases_afectadas'][0]['hora_inicio'] ?? '23:59');
    $fechaA = (string) ($a['fecha'] ?? '9999-12-31');
    $fechaB = (string) ($b['fecha'] ?? '9999-12-31');

    $cmpFecha = strcmp($fechaA, $fechaB);
    if ($cmpFecha !== 0) {
        return $cmpFecha;
    }

    return strcmp($horaA, $horaB);
});

function novedad_form(array $calendarios, array $bloquesCalendario, array $grados, array $materias, array $profesores, array $salones, ?array $row = null): void
{
    $editing = isset($row['id_novedad']);
    ?>
    <form method="post" data-novelty-form data-search-url="<?= e(APP_BASE) ?>/admin/horarios.php?api=buscar-clase" data-availability-url="<?= e(APP_BASE) ?>/admin/horarios.php?api=disponibilidad"
        data-initial-teacher="<?= e((string) ($row['nuevo_profesor'] ?? $row['id_profesor'] ?? '')) ?>"
        data-initial-subject="<?= e((string) ($row['nueva_materia'] ?? $row['id_materia'] ?? '')) ?>"
        data-initial-room="<?= e((string) ($row['nuevo_salon'] ?? $row['id_salon'] ?? '')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <input type="hidden" name="id_horario" data-selected-schedule value="<?= (int) ($row['id_horario'] ?? 0) ?>">
        <?php if ($editing): ?>
            <input type="hidden" name="id_novedad" value="<?= (int) $row['id_novedad'] ?>">
        <?php endif; ?>
        <section class="novelty-section">
            <h3>La hora de clase</h3>
            <div class="grid-2">
                <div class="field">
                    <label>Calendario</label>
                    <select name="id_calendario" data-calendar-select required>
                        <option value="">Selecciona un calendario</option>
                        <?php foreach ($calendarios as $calendario): ?>
                            <?php
                            $idCalendario = (int) $calendario['id_calendario'];
                            $etiquetaCalendario = match ($idCalendario) {
                                1 => 'Calendario 1 (6-9)',
                                2 => 'Calendario 2 (10-11)',
                                default => 'Calendario ' . $idCalendario . ' (' . str_replace('_a_', '-', $calendario['nombre']) . ')',
                            };
                            ?>
                            <option value="<?= $idCalendario ?>" <?= (int) ($row['id_calendario'] ?? 0) === $idCalendario ? 'selected' : '' ?>><?= e($etiquetaCalendario) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Fecha de aplicación</label>
                    <input type="date" name="fecha" data-application-date required value="<?= e((string) ($row['fecha'] ?? '')) ?>">
                    <small class="date-feedback" data-date-feedback></small>
                </div>
            </div>
            <div class="grid-2">
                <div class="field">
                    <label>Inicio</label>
                    <select name="hora_busqueda_inicio" data-search-start required disabled>
                        <option value="">Selecciona un calendario primero</option>
                        <?php foreach ($bloquesCalendario as $bloque): ?>
                            <?php $nombreBloque = $bloque['descripcion'] ?: 'Bloque ' . $bloque['numero_bloque']; ?>
                            <option value="<?= (int) $bloque['id_bloque'] ?>" data-calendar="<?= (int) $bloque['id_calendario'] ?>" data-time="<?= e($bloque['hora_inicio']) ?>" <?= (int) ($row['id_calendario'] ?? 0) === (int) $bloque['id_calendario'] && (int) ($row['id_bloque'] ?? 0) === (int) $bloque['id_bloque'] ? 'selected' : '' ?>><?= e($nombreBloque . ' · ' . $bloque['hora_inicio']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Fin</label>
                    <select name="hora_busqueda_fin" data-search-end required disabled>
                        <option value="">Selecciona un calendario primero</option>
                        <?php foreach ($bloquesCalendario as $bloque): ?>
                            <?php $nombreBloque = $bloque['descripcion'] ?: 'Bloque ' . $bloque['numero_bloque']; ?>
                            <option value="<?= (int) $bloque['id_bloque'] ?>" data-calendar="<?= (int) $bloque['id_calendario'] ?>" data-time="<?= e($bloque['hora_fin']) ?>" <?= (int) ($row['id_calendario'] ?? 0) === (int) $bloque['id_calendario'] && (int) ($row['id_bloque'] ?? 0) === (int) $bloque['id_bloque'] ? 'selected' : '' ?>><?= e($nombreBloque . ' · ' . $bloque['hora_fin']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </section>

        <section class="novelty-section">
            <h3>Método de búsqueda</h3>
            <div class="search-method-row">
                <div class="field">
                    <label>Buscar por</label>
                    <select data-primary-filter>
                        <option value="grado">Grado</option>
                    </select>
                </div>
                <button class="btn btn-secondary" type="button" data-toggle-filters>+ Filtros</button>
            </div>
            <div class="field">
                <label>Grado</label>
                <select data-filter-grade required>
                    <option value="">Selecciona un grado</option>
                    <?php foreach ($grados as $grado): ?>
                        <option value="<?= e($grado['id_grado']) ?>" data-calendar="<?= (int) $grado['id_calendario'] ?>" <?= ($row['id_grado'] ?? '') === $grado['id_grado'] ? 'selected' : '' ?>><?= e($grado['id_grado']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid-3 optional-filters" data-optional-filters hidden>
                <div class="field"><label>Profesor</label><select data-filter-teacher><option value="">Cualquier profesor</option><?= options_html($profesores, 'id_profesor', 'nombre_completo', null, false) ?></select></div>
                <div class="field"><label>Materia</label><select data-filter-subject><option value="">Cualquier materia</option><?= options_html($materias, 'id_materia', 'nombre', null, false) ?></select></div>
                <div class="field"><label>Salón</label><select data-filter-room><option value="">Cualquier salón</option><?= options_html($salones, 'id_salon', 'nombre', null, false) ?></select></div>
            </div>
            <button class="btn btn-primary" type="button" data-find-schedules>Buscar horarios</button>
            <div class="resource-search-status muted" data-schedule-search-status aria-live="polite">Indica fecha, calendario, bloque y grado para encontrar la clase.</div>
        </section>

        <section class="novelty-section schedule-change-section" data-schedule-details <?= $editing ? '' : 'hidden' ?>>
            <h3>Horario a cambiar</h3>
            <div class="schedule-summary" data-schedule-summary>
                <div><span>Grado</span><strong data-summary-grade><?= e((string) ($row['id_grado'] ?? '—')) ?></strong></div>
                <div><span>Día</span><strong data-summary-day><?= e((string) ($row['dia'] ?? '—')) ?></strong></div>
                <div><span>Materia actual</span><strong data-summary-subject><?= e((string) ($row['materia'] ?? '—')) ?></strong></div>
                <div><span>Hora</span><strong data-summary-time><?= e(isset($row['hora_inicio']) ? $row['hora_inicio'] . ' - ' . $row['hora_fin'] : '—') ?></strong></div>
                <div><span>Profesor actual</span><strong data-summary-teacher><?= e((string) ($row['profesor_actual'] ?? 'Sin profesor')) ?></strong></div>
                <div><span>Salón actual</span><strong data-summary-room><?= e((string) ($row['salon_actual'] ?? 'Sin salón')) ?></strong></div>
            </div>
            <div class="grid-3">
                <div class="field"><label>Profesor disponible</label><select name="nuevo_profesor" data-change-teacher required><option value="">Selecciona un horario primero</option></select><small class="muted" data-teacher-availability>Se mostrarán opciones libres para este día y hora.</small></div>
                <div class="field"><label>Materia</label><select name="nueva_materia" data-change-subject required>
                    <?php foreach ($materias as $materia): ?>
                        <option value="<?= (int) $materia['id_materia'] ?>" data-teachers="<?= e((string) ($materia['profesores'] ?? '')) ?>" data-teacher-counts="<?= e((string) ($materia['conteo_profesores'] ?? '')) ?>" <?= (int) ($row['nueva_materia'] ?? $row['id_materia'] ?? 0) === (int) $materia['id_materia'] ? 'selected' : '' ?>><?= e($materia['nombre']) ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div class="field"><label>Salón disponible</label><select name="nuevo_salon" data-change-room><option value="">Selecciona un horario primero</option></select><small class="muted" data-room-availability>Se mostrarán opciones libres para este día y hora.</small></div>
            </div>
        </section>

        <section class="novelty-section">
            <h3>Observación</h3>
            <div class="field">
                <label>Detalle de los cambios</label>
                <textarea name="observacion" data-observation rows="3"><?= e(observacion_sin_flechas((string) ($row['observacion'] ?? ''))) ?></textarea>
            </div>
            <div class="field">
                <label>Estado</label>
                <select name="estado">
                    <option value="ACTIVA" <?= ($row['estado'] ?? 'ACTIVA') === 'ACTIVA' ? 'selected' : '' ?>>ACTIVA</option>
                    <option value="CANCELADA" <?= ($row['estado'] ?? '') === 'CANCELADA' ? 'selected' : '' ?>>CANCELADA</option>
                </select>
            </div>
        </section>
        <div class="actions">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Guardar cambios' : 'Guardar novedad' ?></button>
        </div>
    </form>
    <?php
}

require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card">
    <div class="card-head">
        <h2>Novedades de horario</h2>
        <div class="card-actions">
            <form method="post" data-confirm="¿En serio quieres eliminar todas las novedades?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_all">
                <button class="btn btn-danger" type="submit">🗑️ Eliminar todas</button>
            </form>
            <button class="btn btn-primary" data-open-modal="tpl-create" data-title="Nueva novedad">🟢 Nuevo</button>
        </div>
    </div>
    <form class="toolbar" method="get">
        <input class="search" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar grado, materia o profesor">
        <select name="estado">
            <option value="">Todos los estados</option>
            <option value="ACTIVA" <?= $estadoF === 'ACTIVA' ? 'selected' : '' ?>>Activas</option>
            <option value="CANCELADA" <?= $estadoF === 'CANCELADA' ? 'selected' : '' ?>>No activas</option>
            <option value="HISTORICO" <?= $estadoF === 'HISTORICO' ? 'selected' : '' ?>>Históricas</option>
        </select>
        <button class="btn btn-secondary" type="submit">Filtrar</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Fecha</th>
                <th>Horario</th>
                <th>Cambio</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php $separadorHistoricoMostrado = false; ?>
            <?php foreach ($rows as $row): ?>
                <?php $esRegistroHistorico = in_array((string) ($row['estado'] ?? ''), ['HISTORICO', 'ARCHIVADO'], true); ?>
                <?php if ($esRegistroHistorico && !$separadorHistoricoMostrado): ?>
                    <tr class="novelty-history-divider"><th colspan="5">Registros históricos · solo consulta</th></tr>
                    <?php $separadorHistoricoMostrado = true; ?>
                <?php endif; ?>
                <?php $profesorAnterior = profesor_anterior_novedad($row); ?>
                <?php $gradosAfectados = array_values(array_unique(array_column($row['clases_afectadas'], 'id_grado'))); ?>
                <tr <?= !empty($row['temporal']) ? 'data-expire-at="' . (int) $row['expira_timestamp'] * 1000 . '"' : '' ?>>
                    <td><?= e((string) ($row['fecha'] ?? '')) ?: 'Histórico' ?></td>
                    <td>
                        <?php if (!empty($row['eliminada'])): ?>
                            <strong><?= e($row['id_grado']) ?> · Eliminación temporal</strong><br>
                            <small><?= e($row['dia']) ?> · <?= e($row['materia']) ?> · <?= e($row['hora_inicio']) ?>-<?= e($row['hora_fin']) ?></small><br>
                            <small>Visible hasta <?= e($row['expira_en']) ?></small>
                        <?php elseif (!empty($row['adicion'])): ?>
                            <strong>Novedad de una fecha</strong><br>
                            <small>Grado <?= e($row['id_grado']) ?> · <?= e($row['dia']) ?> · <?= e($row['materia']) ?> · <?= e($row['hora_inicio']) ?>-<?= e($row['hora_fin']) ?></small>
                        <?php elseif (!empty($row['bloque_agregado'])): ?>
                            <strong>Grado <?= e($row['id_grado']) ?> · Bloque agregado</strong><br>
                            <small><?= count($row['clases_afectadas']) > 1
                                ? count($row['clases_afectadas']) . ' bloques agregados'
                                : 'Bloque ' . (int) $row['numero_bloque'] . ' · ' . e($row['hora_inicio']) . '-' . e($row['hora_fin']) ?></small>
                        <?php elseif (!empty($row['temporal'])): ?>
                            <strong><?= count($row['clases_afectadas']) ?> clases · temporal</strong><br>
                            <small>Visible hasta <?= e($row['expira_en']) ?></small>
                        <?php elseif (count($row['clases_afectadas']) > 1): ?>
                            <strong><?= count($row['clases_afectadas']) ?> clases afectadas</strong><br>
                            <small><?= count($gradosAfectados) === 1 ? 'Grado ' . e((string) $gradosAfectados[0]) : count($gradosAfectados) . ' grados' ?></small>
                        <?php else: ?>
                            <strong><?= e($row['id_grado']) ?></strong><br>
                            <small><?= e($row['dia']) ?> · <?= e($row['materia']) ?> · <?= e($row['hora_inicio']) ?>-<?= e($row['hora_fin']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                    <?php if (!empty($row['adicion'])): ?>
                        <span class="change-highlight">NOVEDAD NORMAL · UNA FECHA</span><br>
                        <small><?= e((string) ($row['observacion'] ?? 'Clase añadida para una sola fecha.')) ?></small>
                    <?php elseif (!empty($row['bloque_agregado'])): ?>
                        <span class="change-highlight"><?= $esRegistroHistorico ? 'REGISTRO HISTÓRICO · BLOQUE' : 'NOVEDAD NORMAL · BLOQUE' ?></span><br>
                        <small><?= e((string) ($row['observacion_general'] ?? $row['observacion'] ?? 'Bloque agregado al horario semanal.')) ?></small>
                    <?php elseif (!empty($row['eliminada'])): ?>
                        <span class="temporary-novelty-badge">CLASE ELIMINADA · TEMPORAL 3 MIN</span><br>
                        <small><?= e((string) ($row['observacion'] ?? 'Clase eliminada permanentemente.')) ?></small>
                    <?php elseif (!empty($row['temporal'])): ?><span class="temporary-novelty-badge">CAMBIO TEMPORAL · 3 MIN</span><br><?php endif; ?>
                    <?php if (!empty($row['eliminada']) || !empty($row['adicion']) || !empty($row['bloque_agregado'])): ?>
                    <?php elseif (!empty($row['temporal'])): ?><span class="temporary-novelty-text"><?= e(observacion_sin_flechas((string) $row['observacion'])) ?></span><br>
                    <?php elseif ($row['profesor_nuevo']): ?><span class="change-highlight">Profesor: <?= e($profesorAnterior) ?> → <?= e($row['profesor_nuevo']) ?></span><br><?php endif; ?>
                        <?php if ($row['materia_nueva']): ?><span class="change-highlight">Materia: <?= e($row['materia']) ?>, <?= e($row['materia_nueva']) ?></span><br><?php endif; ?>
                        <?php if ($row['salon_nuevo']): ?><span class="change-highlight">Salón: <?= e($row['salon_actual'] ?: 'sin salón') ?>, <?= e($row['salon_nuevo']) ?></span><br><?php endif; ?>
                        <?php if ($row['hora_nueva_inicio']): ?>Hora: <?= e(format_time($row['hora_nueva_inicio'])) ?> - <?= e(format_time($row['hora_nueva_fin'])) ?><br><?php endif; ?>
                        <?php if (empty($row['eliminada']) && empty($row['adicion']) && empty($row['bloque_agregado']) && !empty($row['observacion_general'])): ?>
                            <small class="change-highlight">Resumen: <?= e(observacion_sin_flechas((string) $row['observacion_general'])) ?></small>
                        <?php elseif (empty($row['temporal']) && empty($row['eliminada']) && empty($row['adicion']) && empty($row['bloque_agregado'])): ?>
                            <small><?= e(observacion_sin_flechas((string) ($row['observacion'] ?: ''))) ?></small>
                        <?php endif; ?>
                        <?php if (count($row['clases_afectadas']) > 1): ?><br><small><?= count($row['clases_afectadas']) ?> <?= !empty($row['bloque_agregado']) ? 'bloques incluidos' : 'horarios incluidos' ?></small><?php endif; ?>
                    </td>
                    <td><?= !empty($row['eliminada'])
                        ? '<span class="temporary-novelty-badge">TEMPORAL</span>'
                        : (!empty($row['temporal'])
                            ? '<span class="temporary-novelty-badge">TEMPORAL</span>'
                            : ($esRegistroHistorico
                                ? '<span class="muted">REGISTRO HISTÓRICO</span>'
                                : badge_estado($row['estado']))) ?></td>
                    <td class="actions novelty-actions">
                        <?php $viewKey = !empty($row['eliminada']) ? 'eliminada-' . (int) $row['id_novedad'] : (!empty($row['temporal']) ? 'temporal-' . $row['grupo_novedad'] : (string) ($row['grupo_novedad'] ?? $row['id_novedad'])); ?>
                        <?php if (!empty($row['eliminada'])): ?>
                            <button class="btn btn-sm btn-secondary" data-edit="tpl-view-<?= e($viewKey) ?>" data-title="Ver clase eliminada">Ver</button>
                            <form method="post" data-confirm="¿Eliminar este aviso temporal? La clase ya fue eliminada del horario y no se restaurará.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="dismiss_deleted">
                                <input type="hidden" name="id_novedad" value="<?= (int) $row['id_novedad'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Eliminar</button>
                            </form>
                        <?php else: ?>
                        <button class="btn btn-sm btn-secondary" data-edit="tpl-view-<?= e($viewKey) ?>" data-title="Ver novedades">Ver</button>
                        <?php if (empty($row['temporal']) && !in_array((string) ($row['estado'] ?? ''), ['HISTORICO', 'ARCHIVADO'], true)): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id_novedad" value="<?= (int) $row['id_novedad'] ?>">
                                <?php if ($row['grupo_novedad'] !== null): ?><input type="hidden" name="grupo_novedad" value="<?= e($row['grupo_novedad']) ?>"><?php endif; ?>
                                <button class="btn btn-sm btn-toggle <?= $row['estado'] === 'ACTIVA' ? 'is-active' : 'is-inactive' ?>" type="submit"><?= $row['estado'] === 'ACTIVA' ? 'Desactivar' : 'Activar' ?></button>
                            </form>
                        <?php endif; ?>
                        <form method="post" data-confirm="<?= e(!empty($row['bloque_agregado'])
                            ? '¿Eliminar esta novedad de bloque? El bloque seguirá en el horario.'
                            : (in_array((string) ($row['estado'] ?? ''), ['HISTORICO', 'ARCHIVADO'], true)
                                ? '¿Eliminar este registro histórico de novedades?'
                                : '¿Eliminar esta novedad y sus ' . count($row['clases_afectadas']) . ' clase(s) asociada(s)?')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= !empty($row['temporal']) ? 'delete_temporary' : 'delete' ?>">
                            <input type="hidden" name="id_novedad" value="<?= (int) $row['id_novedad'] ?>">
                            <?php if ($row['grupo_novedad'] !== null): ?><input type="hidden" name="grupo_novedad" value="<?= e($row['grupo_novedad']) ?>"><?php endif; ?>
                            <button class="btn btn-sm btn-danger" type="submit">Eliminar</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($row['eliminada'])): ?>
                <template id="tpl-view-<?= e($viewKey) ?>">
                    <section class="novelty-section">
                        <h3>Clase eliminada del horario semanal</h3>
                        <div class="grid-2">
                            <div><strong>Grado</strong><br><?= e($row['id_grado']) ?></div>
                            <div><strong>Fecha</strong><br><?= e($row['fecha']) ?></div>
                            <div><strong>Día y clase</strong><br><?= e($row['dia']) ?> · <?= e($row['materia']) ?></div>
                            <div><strong>Horario</strong><br><?= e($row['hora_inicio']) ?>-<?= e($row['hora_fin']) ?></div>
                            <div><strong>Profesor</strong><br><?= e($row['profesor_actual'] ?: 'Sin profesor') ?></div>
                            <div><strong>Salón</strong><br><?= e($row['salon_actual'] ?: 'Sin salón') ?></div>
                        </div>
                        <p><?= e((string) ($row['observacion'] ?? 'Clase eliminada permanentemente.')) ?></p>
                    </section>
                </template>
                <?php elseif (!empty($row['bloque_agregado'])): ?>
                <template id="tpl-view-<?= e($viewKey) ?>">
                    <section class="novelty-section">
                        <h3><?= $esRegistroHistorico ? 'Registro histórico de bloques creados' : 'Novedad normal · Bloques agregados' ?></h3>
                        <p>Grado <?= e($row['id_grado']) ?> · <?= $esRegistroHistorico ? 'Registrado el ' : 'Creado el ' ?><?= e((string) ($row['fecha'] ?? '')) ?></p>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Bloque</th><th>Tipo</th><th>Horario</th></tr></thead>
                                <tbody>
                                <?php foreach ($row['clases_afectadas'] as $detalle): ?>
                                    <tr>
                                        <td><?= e((string) ($detalle['numero_bloque'] ?? '')) ?></td>
                                        <td><?= ($detalle['tipo_bloque'] ?? '') === 'DESCANSO' ? 'Descanso' : 'Clase' ?></td>
                                        <td><?= e((string) $detalle['hora_inicio']) ?>-<?= e((string) $detalle['hora_fin']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p><?= e((string) ($row['observacion_general'] ?? '')) ?></p>
                    </section>
                </template>
                <?php elseif ($esRegistroHistorico): ?>
                <template id="tpl-view-<?= e($viewKey) ?>">
                    <section class="novelty-section">
                        <h3>Registro histórico de auditoría</h3>
                        <div class="grid-2">
                            <div><strong>Tipo de cambio</strong><br><?= e((string) ($row['tipo_cambio'] ?? '')) ?></div>
                            <div><strong>Grado</strong><br><?= e((string) ($row['id_grado'] ?? 'Sin grado asociado')) ?></div>
                            <div><strong>Fecha</strong><br><?= e((string) ($row['fecha'] ?? '')) ?: 'Sin fecha de aplicación' ?></div>
                            <div><strong>Horario</strong><br><?= e((string) ($row['hora_inicio'] ?? '')) ?>-<?= e((string) ($row['hora_fin'] ?? '')) ?></div>
                        </div>
                        <p><?= e((string) ($row['observacion'] ?? '')) ?></p>
                    </section>
                </template>
                <?php elseif (empty($row['adicion'])): ?>
                <template id="tpl-view-<?= e($viewKey) ?>">
                    <?php $observationsFormId = 'observations-' . $viewKey; ?>
                    <form id="<?= e($observationsFormId) ?>" method="post" class="novelty-observations-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_observations">
                        <?php if (!empty($row['grupo_novedad'])): ?>
                            <input type="hidden" name="grupo_novedad" value="<?= e((string) $row['grupo_novedad']) ?>">
                        <?php elseif (!empty($row['id_grado']) && !empty($row['fecha'])): ?>
                            <input type="hidden" name="grupo_novedad" value="<?= e(grupo_novedad_para_grado_fecha((string) $row['id_grado'], (string) $row['fecha'])) ?>">
                        <?php endif; ?>
                        <section class="novelty-section">
                        <h3><?= count($row['clases_afectadas']) ?> registro(s)<?= count($gradosAfectados) === 1 ? ' · Grado ' . e((string) $gradosAfectados[0]) : '' ?> · <?= e((string) ($row['fecha'] ?? '')) ?: 'Histórico' ?><?= !empty($row['temporal']) ? ' · Temporal hasta ' . e($row['expira_en']) : '' ?></h3>
                        <?php $observacionGeneralEsAjuste = !empty($row['hora_entrada_ajuste']) || str_starts_with((string) ($row['observacion_general'] ?? ''), 'Todos los estudiantes entran a las '); ?>
                        <?php if ($observacionGeneralEsAjuste): ?>
                            <div><strong>Observación general</strong><p><?= e(observacion_sin_flechas((string) ($row['observacion_general'] ?? ''))) ?></p></div>
                        <?php else: ?>
                        <div class="field">
                            <label>Observación general</label>
                            <textarea class="novelty-observation-input" name="observacion_general" rows="2"><?= e(observacion_sin_flechas((string) ($row['observacion_general'] ?? ''))) ?></textarea>
                        </div>
                        <?php foreach ($row['clases_afectadas'] as $detalle): ?>
                            <?php if (!empty($detalle['temporal'])): ?>
                                <input type="hidden" form="<?= e($observationsFormId) ?>" name="ids_temporales[]" value="<?= (int) $detalle['id_temporal'] ?>">
                                <input type="hidden" form="<?= e($observationsFormId) ?>" name="observaciones[temporal_<?= (int) $detalle['id_temporal'] ?>]" value="<?= e((string) ($detalle['observacion'] ?? '')) ?>">
                            <?php else: ?>
                                <input type="hidden" form="<?= e($observationsFormId) ?>" name="ids_novedad[]" value="<?= (int) $detalle['id_novedad'] ?>">
                                <input type="hidden" form="<?= e($observationsFormId) ?>" name="observaciones[<?= (int) $detalle['id_novedad'] ?>]" value="<?= e((string) ($detalle['observacion'] ?? '')) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </form>
                    <section class="novelty-section">
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Grado</th><th>Clase</th><th>Horario</th><th>Estado</th><th>Acciones</th></tr></thead>
                                <tbody>
                                <?php foreach ($row['clases_afectadas'] as $detalle): ?>
                                    <tr>
                                        <td><?= e($detalle['id_grado']) ?></td>
                                        <td><?= e($detalle['dia']) ?> · <?= e($detalle['materia']) ?></td>
                                        <td><?= e($detalle['hora_inicio']) ?>-<?= e($detalle['hora_fin']) ?></td>
                                        <td><?= badge_estado((string) ($detalle['estado'] ?? 'ACTIVA')) ?></td>
                                        <td class="actions novelty-subactions">
                                            <?php
                                            $observationTemplateId = 'tpl-observation-' . (!empty($detalle['temporal'])
                                                ? 'temporal-' . (int) $detalle['id_temporal']
                                                : 'novelty-' . (int) $detalle['id_novedad']);
                                            $grupoObservacion = (string) ($detalle['grupo_novedad'] ?? '');
                                            if ($grupoObservacion === '' && !empty($detalle['id_grado']) && !empty($detalle['fecha'])) {
                                                $grupoObservacion = grupo_novedad_para_grado_fecha((string) $detalle['id_grado'], (string) $detalle['fecha']);
                                            }
                                            ?>
                                            <button class="btn btn-sm btn-secondary" type="button" data-edit="<?= e($observationTemplateId) ?>" data-title="Observación de la clase">Ver</button>
                                            <template id="<?= e($observationTemplateId) ?>">
                                                <form method="post" class="novelty-observations-form">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="update_observations">
                                                    <input type="hidden" name="grupo_novedad" value="<?= e($grupoObservacion) ?>">
                                                    <?php if (!empty($detalle['temporal'])): ?>
                                                        <input type="hidden" name="ids_temporales[]" value="<?= (int) $detalle['id_temporal'] ?>">
                                                        <div class="field"><label>Observación de esta clase</label><textarea class="novelty-observation-input" name="observaciones[temporal_<?= (int) $detalle['id_temporal'] ?>]" rows="4"><?= e((string) ($detalle['observacion'] ?? '')) ?></textarea></div>
                                                    <?php else: ?>
                                                        <input type="hidden" name="ids_novedad[]" value="<?= (int) $detalle['id_novedad'] ?>">
                                                        <div class="field"><label>Observación de esta clase</label><textarea class="novelty-observation-input" name="observaciones[<?= (int) $detalle['id_novedad'] ?>]" rows="4"><?= e((string) ($detalle['observacion'] ?? '')) ?></textarea></div>
                                                    <?php endif; ?>
                                                    <button class="btn btn-primary" type="submit">Guardar observación</button>
                                                </form>
                                            </template>
                                            <?php if (!empty($detalle['temporal'])): ?>
                                                <form method="post" data-confirm="¿Eliminar este aviso temporal?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_temporary"><input type="hidden" name="grupo_novedad" value="<?= e((string) $detalle['grupo_novedad']) ?>"><button class="btn btn-sm btn-danger" type="submit">Eliminar</button></form>
                                            <?php elseif (!in_array((string) ($detalle['estado'] ?? ''), ['HISTORICO', 'ARCHIVADO'], true)): ?>
                                                <form method="post" data-confirm="¿<?= ($detalle['estado'] ?? '') === 'ACTIVA' ? 'Desactivar' : 'Activar' ?> esta novedad?"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id_novedad" value="<?= (int) $detalle['id_novedad'] ?>"><button class="btn btn-sm btn-toggle <?= ($detalle['estado'] ?? '') === 'ACTIVA' ? 'is-active' : 'is-inactive' ?>" type="submit"><?= ($detalle['estado'] ?? '') === 'ACTIVA' ? 'Desactivar' : 'Activar' ?></button></form>
                                                <form method="post" data-confirm="¿Eliminar solo esta novedad?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id_novedad" value="<?= (int) $detalle['id_novedad'] ?>"><button class="btn btn-sm btn-danger" type="submit">Eliminar</button></form>
                                            <?php else: ?>
                                                <span class="muted">Solo consulta</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!$observacionGeneralEsAjuste): ?>
                            <button form="<?= e($observationsFormId) ?>" class="btn btn-primary" type="submit">Guardar observación general</button>
                        <?php endif; ?>
                    </section>
                </template>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="5">No hay novedades registradas.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<template id="tpl-create">
    <?php novedad_form($calendarios, $bloquesCalendario, $grados, $materias, $profesores, $salones); ?>
</template>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
