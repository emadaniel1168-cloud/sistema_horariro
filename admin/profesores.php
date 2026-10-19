<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin_auth();

$sectionTitle = 'Profesores';
asegurar_esquema_novedades();
asegurar_tabla_materia_profesores();
asegurar_tabla_salon_profesores();

function profesor_fecha_proxima_dia(int $dia): string
{
    $hoy = new DateTimeImmutable('today');
    $diasHastaClase = ($dia - (int) $hoy->format('N') + 7) % 7;
    return $hoy->modify('+' . $diasHastaClase . ' days')->format('Y-m-d');
}

if (get('api') === 'clases_reemplazo_profesor') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $idProfesor = (int) get('id_profesor', '0');
        if (!$idProfesor || !exists_id('profesores', 'id_profesor', $idProfesor)) {
            throw new RuntimeException('Seleccione un profesor válido.');
        }
        $idDia = (int) get('id_dia', '0');
        if ($idDia && !exists_id('dias_semana', 'id_dia', $idDia)) {
            throw new RuntimeException('Seleccione un día válido.');
        }
        $fechaOperacion = $idDia ? profesor_fecha_proxima_dia($idDia) : null;
        $filtroDiaSql = $idDia ? ' AND h.id_dia = ?' : '';
        $parametrosClases = [$fechaOperacion, $idProfesor];
        if ($idDia) {
            $parametrosClases[] = $idDia;
        }
        $clases = db_all(
            "SELECT h.id_horario, h.id_grado, h.id_dia, h.id_materia, h.id_salon, h.estado AS estado_semanal,
                    n.id_cambio AS id_novedad_fecha,
                    m.nombre AS materia, d.nombre AS dia,
                    TIME_FORMAT(b.hora_inicio, '%H:%i') AS hora_inicio,
                    TIME_FORMAT(b.hora_fin, '%H:%i') AS hora_fin,
                    p.nombre_completo AS profesor, COALESCE(s.nombre, 'Sin salón') AS salon
             FROM horario_semanal h
             JOIN grados g ON g.id_grado = h.id_grado AND g.activo = 1
             JOIN materias m ON m.id_materia = h.id_materia
             JOIN dias_semana d ON d.id_dia = h.id_dia
             JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
             LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
             LEFT JOIN salones s ON s.id_salon = h.id_salon
               LEFT JOIN novedades_cambios_horario n ON n.id_horario = h.id_horario
                   AND h.estado = 'CANCELADA' AND n.fecha = ? AND n.estado = 'ACTIVA'
                   AND COALESCE(n.nuevo_estado, 'PROGRAMADA') = 'PROGRAMADA'
               WHERE h.id_profesor = ? AND (h.estado = 'PROGRAMADA' OR n.id_cambio IS NOT NULL)" . $filtroDiaSql . "
             ORDER BY h.id_grado, d.orden_dia, b.hora_inicio",
            $parametrosClases
        );
        $gruposDeClases = [];
        foreach ($clases as $clase) {
            $clave = (string) $clase['id_grado'] . '|' . (int) $clase['id_dia'];
            $gruposDeClases[$clave][(int) $clase['id_horario']] = true;
        }
        $clasesCancelables = [];
        foreach ($gruposDeClases as $clave => $idsClases) {
            [$grado, $dia] = explode('|', $clave, 2);
            $bloquesDelDia = db_all(
                "SELECT h.id_horario,
                        CASE WHEN n.id_cambio IS NOT NULL THEN COALESCE(n.nuevo_estado, 'PROGRAMADA') ELSE h.estado END AS estado
                 FROM horario_semanal h
                 JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                 LEFT JOIN novedades_cambios_horario n ON n.id_horario = h.id_horario
                      AND n.fecha = ? AND n.estado = 'ACTIVA'
                 WHERE h.id_grado = ? AND h.id_dia = ? AND b.tipo = 'CLASE'
                 ORDER BY b.hora_inicio, b.numero_bloque",
                [$fechaOperacion, $grado, (int) $dia]
            );
            $cadenaInicio = [];
            foreach ($bloquesDelDia as $bloque) {
                if ((string) $bloque['estado'] === 'CANCELADA') {
                    continue;
                }
                $idHorario = (int) $bloque['id_horario'];
                if (!isset($idsClases[$idHorario])) {
                    break;
                }
                $cadenaInicio[] = $idHorario;
            }
            $cadenaFin = [];
            foreach (array_reverse($bloquesDelDia) as $bloque) {
                if ((string) $bloque['estado'] === 'CANCELADA') {
                    continue;
                }
                $idHorario = (int) $bloque['id_horario'];
                if (!isset($idsClases[$idHorario])) {
                    break;
                }
                $cadenaFin[] = $idHorario;
            }
            $cadenaElegida = count($cadenaInicio) >= count($cadenaFin) ? $cadenaInicio : $cadenaFin;
            foreach ($cadenaElegida as $idHorario) {
                $clasesCancelables[$idHorario] = true;
            }
            $clasesProgramadas = [];
            foreach ($bloquesDelDia as $bloque) {
                if ((string) $bloque['estado'] === 'PROGRAMADA') {
                    $clasesProgramadas[] = (int) $bloque['id_horario'];
                }
            }
            $ultimaPosicion = count($clasesProgramadas) - 1;
            foreach ($clasesProgramadas as $posicion => $idHorario) {
                if ($posicion > 0 && $posicion < $ultimaPosicion) {
                    $clasesIntermedias[$idHorario] = true;
                }
            }
        }
        $candidatos = db_all(
            'SELECT id_profesor, nombre_completo FROM profesores
             WHERE activo = 1 AND id_profesor <> ? ORDER BY nombre_completo',
            [$idProfesor]
        );
        $asignaciones = [];
        $materiasPorProfesor = [];
        foreach (db_all(
            'SELECT id_profesor, id_materia FROM materia_profesores
             UNION SELECT id_profesor, id_materia FROM horario_semanal WHERE id_profesor IS NOT NULL'
        ) as $asignacion) {
            $idProfesorAsignado = (int) $asignacion['id_profesor'];
            $idMateriaAsignada = (int) $asignacion['id_materia'];
            $asignaciones[$idMateriaAsignada][] = $idProfesorAsignado;
            $materiasPorProfesor[$idProfesorAsignado][] = $idMateriaAsignada;
        }
        $salonesPorProfesor = [];
        $profesoresPorSalon = [];
        foreach (db_all(
            'SELECT id_profesor, id_salon FROM salon_profesores
             UNION SELECT id_profesor, id_salon FROM horario_semanal
             WHERE id_profesor IS NOT NULL AND id_salon IS NOT NULL'
        ) as $asignacionSalon) {
            $idProfesorSalon = (int) $asignacionSalon['id_profesor'];
            $idSalonAsignado = (int) $asignacionSalon['id_salon'];
            $salonesPorProfesor[$idProfesorSalon][] = $idSalonAsignado;
            $profesoresPorSalon[$idSalonAsignado][] = $idProfesorSalon;
        }
        $materiasPorSalon = [];
        $salonesPorMateria = [];
        foreach (db_all(
            "SELECT DISTINCT h.id_materia, h.id_salon
             FROM horario_semanal h
             JOIN grados g ON g.id_grado = h.id_grado AND g.activo = 1
             WHERE h.id_salon IS NOT NULL AND h.estado = 'PROGRAMADA'"
        ) as $asignacionMateriaSalon) {
            $idMateriaSalon = (int) $asignacionMateriaSalon['id_materia'];
            $idSalonMateria = (int) $asignacionMateriaSalon['id_salon'];
            $materiasPorSalon[$idSalonMateria][] = $idMateriaSalon;
            $salonesPorMateria[$idMateriaSalon][] = $idSalonMateria;
        }
        $materiasActivas = db_all('SELECT id_materia, nombre FROM materias WHERE activa = 1 ORDER BY nombre');
        $salonesActivos = db_all('SELECT id_salon, nombre FROM salones WHERE activo = 1 ORDER BY nombre');
        foreach ($clases as &$clase) {
            $clase['cancelable'] = isset($clasesCancelables[(int) $clase['id_horario']]);
            $clase['media_jornada'] = isset($clasesIntermedias[(int) $clase['id_horario']]);
            $clase['solo_cancelacion'] = !empty($clase['id_novedad_fecha']);
            $ocupaciones = ocupaciones_horario(
                (int) $clase['id_dia'],
                (string) $clase['hora_inicio'],
                (string) $clase['hora_fin'],
                null,
                (int) $clase['id_horario']
            );
            $profesoresOcupados = [];
            $salonesOcupados = [];
            foreach ($ocupaciones as $ocupacion) {
                if (!empty($ocupacion['id_profesor'])) {
                    $profesoresOcupados[(int) $ocupacion['id_profesor']] = $ocupacion;
                }
                if (!empty($ocupacion['id_salon'])) {
                    $salonesOcupados[(int) $ocupacion['id_salon']] = $ocupacion;
                }
            }
            $clase['profesores_candidatos'] = array_map(
                static function (array $candidato) use ($materiasPorProfesor, $salonesPorProfesor, $profesoresOcupados): array {
                    $idCandidato = (int) $candidato['id_profesor'];
                    return [
                        'id_profesor' => $idCandidato,
                        'nombre_completo' => $candidato['nombre_completo'],
                        'materias' => array_values(array_unique($materiasPorProfesor[$idCandidato] ?? [])),
                        'salones' => array_values(array_unique($salonesPorProfesor[$idCandidato] ?? [])),
                        'ocupacion' => $profesoresOcupados[$idCandidato] ?? null,
                    ];
                },
                $candidatos
            );
            $clase['materias_disponibles'] = [];
            foreach ($materiasActivas as $materia) {
                $idMateria = (int) $materia['id_materia'];
                $profesoresMateria = [];
                foreach ($asignaciones[$idMateria] ?? [] as $idProfesorMateria) {
                    foreach ($candidatos as $candidato) {
                        if ((int) $candidato['id_profesor'] === $idProfesorMateria) {
                            $profesoresMateria[] = [
                                'id_profesor' => $idProfesorMateria,
                                'nombre_completo' => $candidato['nombre_completo'],
                                'ocupacion' => $profesoresOcupados[$idProfesorMateria] ?? null,
                            ];
                            break;
                        }
                    }
                }
                $clase['materias_disponibles'][] = [
                    'id_materia' => $idMateria,
                    'nombre' => $materia['nombre'],
                    'profesores' => $profesoresMateria,
                    'salones' => array_values(array_unique($salonesPorMateria[$idMateria] ?? [])),
                ];
            }
            $clase['salones_disponibles'] = array_map(
                static function (array $salon) use ($profesoresPorSalon, $materiasPorSalon, $salonesOcupados): array {
                    $idSalon = (int) $salon['id_salon'];
                    return [
                        'id_salon' => $idSalon,
                        'nombre' => $salon['nombre'],
                        'profesores' => array_values(array_unique($profesoresPorSalon[$idSalon] ?? [])),
                        'materias' => array_values(array_unique($materiasPorSalon[$idSalon] ?? [])),
                        'ocupacion' => $salonesOcupados[$idSalon] ?? null,
                    ];
                },
                $salonesActivos
            );
        }
        unset($clase);
        echo json_encode(['clases' => $clases], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function profesor_clase_es_intermedia(int $idHorario): bool
{
    $clase = db_one(
        'SELECT id_grado, id_dia FROM horario_semanal WHERE id_horario = ?',
        [$idHorario]
    );
    if (!$clase) {
        return false;
    }
    $clasesDelDia = db_all(
        "SELECT h.id_horario
         FROM horario_semanal h
         JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
         WHERE h.id_grado = ? AND h.id_dia = ? AND h.estado = 'PROGRAMADA' AND b.tipo = 'CLASE'
         ORDER BY b.hora_inicio, b.hora_fin, h.id_horario",
        [$clase['id_grado'], $clase['id_dia']]
    );
    $posicion = array_search($idHorario, array_map('intval', array_column($clasesDelDia, 'id_horario')), true);
    return $posicion !== false && $posicion > 0 && $posicion < count($clasesDelDia) - 1;
}

function profesor_asignado_a_materia(int $idProfesor, int $idMateria): bool
{
    return db_count(
        'SELECT COUNT(*) AS total FROM materia_profesores WHERE id_profesor = ? AND id_materia = ?',
        [$idProfesor, $idMateria]
    ) + db_count(
        'SELECT COUNT(*) AS total FROM horario_semanal WHERE id_profesor = ? AND id_materia = ?',
        [$idProfesor, $idMateria]
    ) > 0;
}

function profesor_clases_intermedias(array $clases, ?string $fecha = null): array
{
    $grupos = [];
    foreach ($clases as $clase) {
        $clave = (string) $clase['id_grado'] . '|' . (int) $clase['id_dia'];
        $grupos[$clave] = [(string) $clase['id_grado'], (int) $clase['id_dia']];
    }

    $intermedias = [];
    foreach ($grupos as [$grado, $dia]) {
        $clasesDelDia = db_all(
            "SELECT h.id_horario,
                    CASE WHEN n.id_cambio IS NOT NULL THEN COALESCE(n.nuevo_estado, 'PROGRAMADA') ELSE h.estado END AS estado
             FROM horario_semanal h
             JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
             LEFT JOIN novedades_cambios_horario n ON n.id_horario = h.id_horario
                  AND n.fecha = ? AND n.estado = 'ACTIVA'
             WHERE h.id_grado = ? AND h.id_dia = ? AND b.tipo = 'CLASE'
             ORDER BY b.hora_inicio, b.numero_bloque",
            [$fecha, $grado, $dia]
        );
        $clasesProgramadas = array_values(array_filter(
            $clasesDelDia,
            static fn(array $clase): bool => (string) $clase['estado'] === 'PROGRAMADA'
        ));
        $ultimaPosicion = count($clasesProgramadas) - 1;
        foreach ($clasesProgramadas as $posicion => $clase) {
            if ($posicion > 0 && $posicion < $ultimaPosicion) {
                $intermedias[(int) $clase['id_horario']] = true;
            }
        }
    }
    return $intermedias;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action', '');

    try {
        if ($action === 'create') {
            $nombre = post('nombre_completo');
            if (!$nombre) {
                throw new RuntimeException('El nombre del profesor es obligatorio.');
            }
            db_exec('INSERT INTO profesores (nombre_completo, activo) VALUES (?, 1)', [$nombre]);
            flash_set('success', 'Profesor creado correctamente.');
        } elseif ($action === 'create_class') {
            $profesorId = (int) post('id_profesor', '0');
            $grado = post('id_grado');
            $dia = (int) post('id_dia', '0');
            $bloque = (int) post('id_bloque', '0');
            $materia = (int) post('id_materia', '0');
            $salon = post('id_salon');
            $estado = post('estado', 'PROGRAMADA');
            $observacion = post('observacion');
            $horaInicio = post('hora_inicio');
            $horaFin = post('hora_fin');

            $gradoRow = $grado ? db_one('SELECT id_calendario FROM grados WHERE id_grado = ?', [$grado]) : null;
            $bloqueRow = $bloque ? db_one('SELECT id_calendario, tipo, hora_inicio, hora_fin FROM bloques_horarios WHERE id_bloque = ?', [$bloque]) : null;
            if (!$profesorId || !exists_id('profesores', 'id_profesor', $profesorId) || !$gradoRow || !$dia || !exists_id('dias_semana', 'id_dia', $dia) || !$materia || !exists_id('materias', 'id_materia', $materia)) {
                throw new RuntimeException('Complete profesor, grado, día y materia con opciones válidas.');
            }
            if (!$bloqueRow || $bloqueRow['tipo'] !== 'CLASE' || (int) $bloqueRow['id_calendario'] !== (int) $gradoRow['id_calendario'] || $bloqueRow['hora_inicio'] !== $horaInicio || $bloqueRow['hora_fin'] !== $horaFin) {
                throw new RuntimeException('El bloque no corresponde a la hora seleccionada y al calendario del grado.');
            }
            $materiaAsignada = db_count(
                'SELECT COUNT(*) AS total FROM materia_profesores WHERE id_materia = ? AND id_profesor = ?',
                [$materia, $profesorId]
            ) + db_count(
                'SELECT COUNT(*) AS total FROM horario_semanal WHERE id_materia = ? AND id_profesor = ?',
                [$materia, $profesorId]
            );
            if ($materiaAsignada === 0) {
                throw new RuntimeException('La materia seleccionada no está asignada a este profesor.');
            }
            if (db_count('SELECT COUNT(*) AS total FROM horario_semanal WHERE id_grado = ? AND id_dia = ? AND id_bloque = ?', [$grado, $dia, $bloque])) {
                throw new RuntimeException('El grado ' . $grado . ' ya tiene una clase asignada en ese día y horario.');
            }
            if (!in_array($estado, ['PROGRAMADA', 'CANCELADA'], true)) {
                throw new RuntimeException('El estado seleccionado no es válido.');
            }
            $salonId = $salon ? (int) $salon : null;
            if ($salonId && !exists_id('salones', 'id_salon', $salonId)) {
                throw new RuntimeException('El salón seleccionado no existe.');
            }
            if ($salonId) {
                $salonAsignado = db_count(
                    'SELECT COUNT(*) AS total FROM salon_profesores WHERE id_salon = ? AND id_profesor = ?',
                    [$salonId, $profesorId]
                ) + db_count(
                    'SELECT COUNT(*) AS total FROM horario_semanal WHERE id_salon = ? AND id_profesor = ?',
                    [$salonId, $profesorId]
                );
                if ($salonAsignado === 0) {
                    throw new RuntimeException('El salón seleccionado no está asignado a este profesor.');
                }
            }
            if ($estado === 'PROGRAMADA') {
                validar_disponibilidad_horario($dia, $horaInicio, $horaFin, $profesorId, $salonId);
            }

            asegurar_tabla_cambios_horario();
            db()->beginTransaction();
            try {
                db_exec(
                'INSERT INTO horario_semanal (id_grado, id_dia, id_bloque, id_materia, id_profesor, id_salon, estado, observacion)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$grado, $dia, $bloque, $materia, $profesorId, $salonId, $estado, $observacion]
                );
                $idCreado = (int) db()->lastInsertId();
                registrar_cambio_horario($idCreado, 'CREACION', 'Clase creada desde el horario del profesor.');
                db()->commit();
                $resumenClase = resumen_clase_horario($idCreado);
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
            flash_set('success', 'Clase asignada: ' . $resumenClase);
        } elseif ($action === 'delete_class') {
            $profesorId = (int) post('id_profesor', '0');
            $horarioId = (int) post('id_horario', '0');
            if (!$profesorId || !$horarioId) {
                throw new RuntimeException('No se encontró la clase que desea eliminar.');
            }
            $clase = db_one(
                'SELECT h.id_grado, h.id_dia, h.id_bloque, h.id_materia, h.id_profesor, h.id_salon,
                        b.hora_inicio, b.hora_fin
                 FROM horario_semanal h
                 JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                 WHERE h.id_horario = ? AND h.id_profesor = ?',
                [$horarioId, $profesorId]
            );
            if (!$clase) {
                throw new RuntimeException('La clase no pertenece al profesor seleccionado.');
            }
            $resumenClase = resumen_clase_horario($horarioId);
            asegurar_tabla_cambios_horario();
            db()->beginTransaction();
            try {
                if (profesor_clase_es_intermedia($horarioId)) {
                    $profesorReemplazo = (int) post('profesor_reemplazo', '0');
                    if (!$profesorReemplazo || $profesorReemplazo === $profesorId
                        || db_count('SELECT COUNT(*) AS total FROM profesores WHERE id_profesor = ? AND activo = 1', [$profesorReemplazo]) === 0
                        || !profesor_asignado_a_materia($profesorReemplazo, (int) $clase['id_materia'])) {
                        throw new RuntimeException('Esta clase está en medio de la jornada. Seleccione un profesor activo que dé esta materia para reemplazarla.');
                    }
                    validar_disponibilidad_horario(
                        (int) $clase['id_dia'],
                        $clase['hora_inicio'],
                        $clase['hora_fin'],
                        $profesorReemplazo,
                        $clase['id_salon'] !== null ? (int) $clase['id_salon'] : null,
                        $horarioId
                    );
                    $nombreReemplazo = db_one('SELECT nombre_completo FROM profesores WHERE id_profesor = ?', [$profesorReemplazo]);
                    registrar_cambio_horario($horarioId, 'CAMBIO', 'Profesor reemplazado desde el horario del profesor.');
                    db_exec(
                        'UPDATE horario_semanal SET id_profesor = ?, estado = \'PROGRAMADA\'
                         WHERE id_horario = ? AND id_profesor = ?',
                        [$profesorReemplazo, $horarioId, $profesorId]
                    );
                    flash_set('success', 'La clase se conservó con ' . ($nombreReemplazo['nombre_completo'] ?? 'el profesor sustituto') . ': ' . $resumenClase);
                } else {
                    registrar_cambio_horario($horarioId, 'ELIMINACION', 'Clase eliminada desde el horario del profesor.');
                    db_exec('DELETE FROM horario_semanal WHERE id_horario = ? AND id_profesor = ?', [$horarioId, $profesorId]);
                    flash_set('success', 'Clase eliminada: ' . $resumenClase);
                }
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
        } elseif ($action === 'update_class') {
            $profesorActual = (int) post('id_profesor', '0');
            $horarioId = (int) post('id_horario', '0');
            $claseActual = db_one(
                'SELECT g.id_calendario
                 FROM horario_semanal h
                 JOIN grados g ON g.id_grado = h.id_grado
                 WHERE h.id_horario = ? AND h.id_profesor = ? AND g.activo = 1',
                [$horarioId, $profesorActual]
            );
            if (!$profesorActual || !$horarioId || !$claseActual) {
                throw new RuntimeException('No se encontró la clase de este profesor.');
            }

            $dia = (int) post('id_dia', '0');
            $bloque = (int) post('id_bloque', '0');
            $materia = (int) post('id_materia', '0');
            $profesorNuevo = post('nuevo_profesor');
            $salon = post('id_salon');
            $estado = post('estado', 'PROGRAMADA');
            $observacion = post('observacion');
            $bloqueRow = db_one('SELECT id_calendario, tipo, hora_inicio, hora_fin FROM bloques_horarios WHERE id_bloque = ?', [$bloque]);

            if (!$dia || !$bloque || !$materia || !exists_id('dias_semana', 'id_dia', $dia) || !exists_id('materias', 'id_materia', $materia)) {
                throw new RuntimeException('Seleccione un día, bloque y materia válidos.');
            }
            if (!$bloqueRow || (int) $bloqueRow['id_calendario'] !== (int) $claseActual['id_calendario'] || $bloqueRow['tipo'] !== 'CLASE') {
                throw new RuntimeException('El bloque debe ser de clase y pertenecer al calendario del grado.');
            }
            if (!in_array($estado, ['PROGRAMADA', 'DESCANSO', 'CANCELADA'], true)) {
                throw new RuntimeException('El estado seleccionado no es válido.');
            }
            $profesorNuevoId = $profesorNuevo ? (int) $profesorNuevo : null;
            $salonId = $salon ? (int) $salon : null;
            $reemplazoCancelacionIntermedia = $estado === 'CANCELADA' && profesor_clase_es_intermedia($horarioId);
            if ($reemplazoCancelacionIntermedia) {
                if (!$profesorNuevoId || $profesorNuevoId === $profesorActual
                    || db_count('SELECT COUNT(*) AS total FROM profesores WHERE id_profesor = ? AND activo = 1', [$profesorNuevoId]) === 0
                    || !profesor_asignado_a_materia($profesorNuevoId, $materia)) {
                    throw new RuntimeException('Esta clase está en medio de la jornada. Seleccione otro profesor activo que dé esta materia; la clase quedará PROGRAMADA con su reemplazo.');
                }
                $estado = 'PROGRAMADA';
            }
            if ($profesorNuevoId && !exists_id('profesores', 'id_profesor', $profesorNuevoId)) {
                throw new RuntimeException('El profesor seleccionado no existe.');
            }
            if ($salonId && !exists_id('salones', 'id_salon', $salonId)) {
                throw new RuntimeException('El salón seleccionado no existe.');
            }
            if ($estado === 'PROGRAMADA') {
                validar_disponibilidad_horario(
                    $dia,
                    $bloqueRow['hora_inicio'],
                    $bloqueRow['hora_fin'],
                    $profesorNuevoId,
                    $salonId,
                    $horarioId
                );
            }

            asegurar_tabla_cambios_horario();
            db()->beginTransaction();
            try {
                db_exec(
                'UPDATE horario_semanal
                 SET id_dia = ?, id_bloque = ?, id_materia = ?, id_profesor = ?, id_salon = ?, estado = ?, observacion = ?
                 WHERE id_horario = ? AND id_profesor = ?',
                [$dia, $bloque, $materia, $profesorNuevoId, $salonId, $estado, $observacion, $horarioId, $profesorActual]
                );
                registrar_cambio_horario($horarioId, 'CAMBIO', 'Clase modificada desde el horario del profesor.');
                db()->commit();
                $resumenClase = resumen_clase_horario($horarioId);
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
            flash_set('success', 'Horario actualizado: ' . $resumenClase);
        } elseif ($action === 'update') {
            $id = (int) post('id_profesor', '0');
            $nombre = post('nombre_completo');
            $activo = post('activo') === '1' ? 1 : 0;
            if (!$id || !$nombre) {
                throw new RuntimeException('Debe indicar el nombre del profesor.');
            }
            db_exec('UPDATE profesores SET nombre_completo = ?, activo = ? WHERE id_profesor = ?', [$nombre, $activo, $id]);
            flash_set('success', 'Información actualizada correctamente.');
        } elseif ($action === 'toggle') {
            $id = (int) post('id_profesor', '0');
            $row = db_one('SELECT activo, nombre_completo FROM profesores WHERE id_profesor = ?', [$id]);
            if (!$row) {
                throw new RuntimeException('El profesor no existe.');
            }
            $next = ((int) $row['activo'] === 1) ? 0 : 1;
            asegurar_tabla_horario_original_novedad();
            db()->beginTransaction();
            if ($next === 0) {
                $modo = post('modo_cancelacion', 'cancel');
                $idDia = (int) post('id_dia', '0');
                if ($idDia && !exists_id('dias_semana', 'id_dia', $idDia)) {
                    throw new RuntimeException('Seleccione un día válido.');
                }
                $fechaOperacion = $idDia ? profesor_fecha_proxima_dia($idDia) : null;
                if (!in_array($modo, ['cancel', 'replace'], true)) {
                    throw new RuntimeException('La acción seleccionada para las clases no es válida.');
                }
                $reemplazos = $_POST['reemplazos'] ?? [];
                if ($modo === 'replace' && !is_array($reemplazos)) {
                    throw new RuntimeException('Debe indicar una acción para cada clase.');
                }
                $clases = db_all(
                    'SELECT h.id_horario, h.id_grado, h.id_dia, h.id_bloque, h.id_materia, h.id_salon, h.estado,
                           b.hora_inicio, b.hora_fin, n.id_cambio AS id_novedad_fecha
                     FROM horario_semanal h
                     JOIN grados g ON g.id_grado = h.id_grado
                     JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                     LEFT JOIN novedades_cambios_horario n ON n.id_horario = h.id_horario
                         AND h.estado = "CANCELADA" AND n.fecha = ? AND n.estado = "ACTIVA"
                         AND COALESCE(n.nuevo_estado, "PROGRAMADA") = "PROGRAMADA"
                     WHERE h.id_profesor = ? AND (h.estado = "PROGRAMADA" OR n.id_cambio IS NOT NULL) AND g.activo = 1
                              AND (? = 0 OR h.id_dia = ?)
                     ORDER BY h.id_dia, h.id_bloque
                     FOR UPDATE',
                         [$fechaOperacion, $id, $idDia, $idDia]
                );
                 $clasesIntermedias = profesor_clases_intermedias($clases, $fechaOperacion);
                if ($modo === 'replace') {
                    $claseIds = array_fill_keys(array_map('intval', array_column($clases, 'id_horario')), true);
                    $reemplazadas = 0;
                    $canceladas = 0;
                    foreach ($clases as $clase) {
                        $horarioId = (int) $clase['id_horario'];
                        if (!array_key_exists($horarioId, $reemplazos) && !array_key_exists((string) $horarioId, $reemplazos)) {
                            throw new RuntimeException('Seleccione reemplazar o cancelar para cada clase.');
                        }
                        $decision = $reemplazos[$horarioId] ?? $reemplazos[(string) $horarioId];
                        if (!is_array($decision)) {
                            throw new RuntimeException('La decisión enviada para una clase no es válida.');
                        }
                        $accion = strtolower((string) ($decision['accion'] ?? ''));
                        if ($accion === 'cancelar') {
                            if (!empty($clase['id_novedad_fecha'])) {
                                $observacionCancelacion = 'Clase cancelada por desactivación del profesor.';
                                if (isset($clasesIntermedias[$horarioId])) {
                                    $observacionCancelacion .= ' Cancelación intermedia por desactivación del profesor.';
                                }
                                $actualizadas = db_exec(
                                    "UPDATE novedades_cambios_horario
                                     SET nuevo_estado = 'CANCELADA',
                                         observacion = CONCAT_WS(' | ', NULLIF(observacion, ''), ?)
                                     WHERE id_cambio = ? AND id_horario = ? AND estado = 'ACTIVA'
                                       AND COALESCE(nuevo_estado, 'PROGRAMADA') = 'PROGRAMADA'",
                                    [$observacionCancelacion, (int) $clase['id_novedad_fecha'], $horarioId]
                                );
                                if ($actualizadas === 0) {
                                    throw new RuntimeException('La novedad de esta clase ya cambió. Vuelve a cargar las clases del profesor.');
                                }
                                $canceladas++;
                                continue;
                            }
                            guardar_horario_original_novedad($horarioId);
                            if (isset($clasesIntermedias[$horarioId])) {
                                db_exec(
                                    "UPDATE horario_semanal
                                     SET estado = 'CANCELADA', observacion = CASE
                                         WHEN COALESCE(observacion, '') = '' THEN ?
                                         ELSE CONCAT(observacion, ' | ', ?)
                                     END
                                     WHERE id_horario = ? AND id_profesor = ?",
                                    ['Cancelación intermedia por desactivación del profesor', 'Cancelación intermedia por desactivación del profesor', $horarioId, $id]
                                );
                            } else {
                                db_exec(
                                    'UPDATE horario_semanal SET estado = "CANCELADA"
                                     WHERE id_horario = ? AND id_profesor = ?',
                                    [$horarioId, $id]
                                );
                            }
                            $detalleNovedad = isset($clasesIntermedias[$horarioId])
                                ? 'Aviso: se canceló una clase de media jornada por desactivación del profesor.'
                                : 'Clase cancelada por desactivación del profesor; decisión por clase.';
                            registrar_cambio_horario($horarioId, 'CANCELACION', $detalleNovedad);
                            $canceladas++;
                            continue;
                        }
                        if ($accion !== 'reemplazar') {
                            throw new RuntimeException('Seleccione reemplazar o cancelar para cada clase.');
                        }
                        if (!empty($clase['id_novedad_fecha'])) {
                            throw new RuntimeException('Esta clase solo está programada para la fecha seleccionada y solo se puede cancelar desde esta operación.');
                        }
                        $reemplazo = (int) ($decision['profesor'] ?? 0);
                        $materiaNueva = (int) ($decision['materia'] ?? 0);
                        $salonRaw = trim((string) ($decision['salon'] ?? ''));
                        $salonNuevo = $salonRaw !== '' ? (int) $salonRaw : null;
                        if (!$reemplazo || $reemplazo === $id
                            || db_count('SELECT COUNT(*) AS total FROM profesores WHERE id_profesor = ? AND activo = 1', [$reemplazo]) === 0) {
                            throw new RuntimeException('Seleccione un profesor activo para cada clase que desea reemplazar.');
                        }
                        if (!$materiaNueva || db_count('SELECT COUNT(*) AS total FROM materias WHERE id_materia = ? AND activa = 1', [$materiaNueva]) === 0) {
                            throw new RuntimeException('Seleccione una materia activa para cada clase que desea reemplazar.');
                        }
                        if ($salonNuevo !== null && db_count('SELECT COUNT(*) AS total FROM salones WHERE id_salon = ? AND activo = 1', [$salonNuevo]) === 0) {
                            throw new RuntimeException('Seleccione un salón activo para cada clase.');
                        }
                        validar_disponibilidad_horario(
                            (int) $clase['id_dia'],
                            (string) $clase['hora_inicio'],
                            (string) $clase['hora_fin'],
                            $reemplazo,
                            $salonNuevo,
                            $horarioId
                        );
                        guardar_horario_original_novedad($horarioId);
                        db_exec(
                            'UPDATE horario_semanal
                             SET id_profesor = ?, id_materia = ?, id_salon = ?, estado = "PROGRAMADA",
                                 observacion = COALESCE(CONCAT(observacion, " | Reemplazo temporal asignado"), "Reemplazo temporal asignado")
                             WHERE id_horario = ? AND id_profesor = ?',
                            [$reemplazo, $materiaNueva, $salonNuevo, $horarioId, $id]
                        );
                        registrar_cambio_horario($horarioId, 'CAMBIO', 'Clase reasignada a profesor de reemplazo por desactivación del docente original.');
                        $reemplazadas++;
                    }
                    foreach (array_keys($reemplazos) as $horarioId) {
                        if (!isset($claseIds[(int) $horarioId])) {
                            throw new RuntimeException('La lista incluye una clase que no pertenece a esta operación.');
                        }
                    }
                    flash_set('success', 'Profesor desactivado. ' . $reemplazadas . ' clase(s) reemplazada(s) y ' . $canceladas . ' cancelada(s).');
                } else {
                    foreach ($clases as $clase) {
                        $horarioId = (int) $clase['id_horario'];
                        if (!empty($clase['id_novedad_fecha'])) {
                            $observacionCancelacion = 'Clase cancelada por desactivación del profesor.';
                            if (isset($clasesIntermedias[$horarioId])) {
                                $observacionCancelacion .= ' Cancelación intermedia por desactivación del profesor.';
                            }
                            db_exec(
                                "UPDATE novedades_cambios_horario
                                 SET nuevo_estado = 'CANCELADA',
                                     observacion = CONCAT_WS(' | ', NULLIF(observacion, ''), ?)
                                 WHERE id_cambio = ? AND id_horario = ? AND estado = 'ACTIVA'
                                   AND COALESCE(nuevo_estado, 'PROGRAMADA') = 'PROGRAMADA'",
                                [$observacionCancelacion, (int) $clase['id_novedad_fecha'], $horarioId]
                            );
                            continue;
                        }
                        guardar_horario_original_novedad($horarioId);
                        $observacionCancelacion = isset($clasesIntermedias[$horarioId])
                            ? 'Cancelada por inactividad del profesor | Cancelación intermedia por desactivación del profesor'
                            : 'Cancelada por inactividad del profesor';
                        db_exec(
                            'UPDATE horario_semanal
                             SET estado = "CANCELADA", observacion = CONCAT_WS(" | ", NULLIF(observacion, ""), ?)
                             WHERE id_horario = ? AND id_profesor = ?',
                            [$observacionCancelacion, $horarioId, $id]
                        );
                        registrar_cambio_horario($horarioId, 'CANCELACION', 'Clase cancelada por la desactivación temporal del profesor.');
                    }
                    flash_set('success', 'Profesor desactivado. Las clases quedaron canceladas y conservadas como programación original.');
                }
            } else {
                $clases = db_all(
                    'SELECT DISTINCT h.id_horario
                     FROM horario_semanal h
                     LEFT JOIN horario_original_novedad o ON o.id_horario = h.id_horario
                     WHERE (h.id_profesor = ? AND h.estado = "CANCELADA")
                        OR (o.id_profesor = ? AND EXISTS (
                            SELECT 1 FROM novedades_cambios_horario n
                            WHERE n.id_horario = h.id_horario AND n.tipo_cambio = "CAMBIO"
                              AND n.detalle LIKE "%por desactivación del docente original%"
                        ))',
                    [$id, $id]
                );
                foreach ($clases as $clase) {
                    $original = db_one(
                        'SELECT id_bloque, id_materia, id_profesor, id_salon, estado, observacion
                         FROM horario_original_novedad WHERE id_horario = ?',
                        [(int) $clase['id_horario']]
                    );
                    if ($original) {
                        db_exec(
                            'UPDATE horario_semanal
                             SET id_bloque = ?, id_materia = ?, id_profesor = ?, id_salon = ?, estado = ?, observacion = ?
                             WHERE id_horario = ?',
                            [
                                $original['id_bloque'],
                                $original['id_materia'],
                                $original['id_profesor'],
                                $original['id_salon'],
                                $original['estado'],
                                $original['observacion'],
                                (int) $clase['id_horario'],
                            ]
                        );
                        db_exec('DELETE FROM horario_original_novedad WHERE id_horario = ?', [(int) $clase['id_horario']]);
                    } else {
                        db_exec(
                            'UPDATE horario_semanal SET estado = "PROGRAMADA", observacion = "" WHERE id_horario = ?',
                            [(int) $clase['id_horario']]
                        );
                    }
                }
                flash_set('success', 'Profesor activado correctamente y la programación original fue restaurada cuando correspondía.');
            }
            db_exec('UPDATE profesores SET activo = ? WHERE id_profesor = ?', [$next, $id]);
            db()->commit();
        } elseif ($action === 'delete') {
            $id = (int) post('id_profesor', '0');
            $used = db_count('SELECT COUNT(*) AS total FROM horario_semanal WHERE id_profesor = ?', [$id])
                + db_count('SELECT COUNT(*) AS total FROM disponibilidad_profesores WHERE id_profesor = ?', [$id])
                + db_count('SELECT COUNT(*) AS total FROM novedades_cambios_horario WHERE nuevo_profesor = ? AND fecha IS NOT NULL', [$id]);
            if ($used > 0) {
                db_exec('UPDATE profesores SET activo = 0 WHERE id_profesor = ?', [$id]);
                flash_set('error', 'No se puede eliminar porque está relacionado con horarios o disponibilidad. El registro fue desactivado.');
            } else {
                db_exec('DELETE FROM profesores WHERE id_profesor = ?', [$id]);
                flash_set('success', 'Registro eliminado correctamente.');
            }
        }
    } catch (PDOException $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash_set('error', unique_error($e, 'No se pudo guardar la información. Verifique los datos e inténtelo nuevamente.'));
    } catch (RuntimeException $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash_set('error', $e->getMessage());
    }

    $redirectUrl = APP_BASE . '/admin/profesores.php';
    $redirectProfesor = post('horario_profesor');
    if ($action === 'update_class') {
        $redirectProfesor = post('nuevo_profesor') ?: post('id_profesor', '0');
    } elseif ($action === 'create_class') {
        $redirectProfesor = post('id_profesor', '0');
    }
    if ($redirectProfesor) {
        $redirectUrl .= '?horario_profesor=' . urlencode($redirectProfesor);
    }
    redirect($redirectUrl);
}

$ordenProfesores = get('orden_profesores', 'asc');
if (!in_array($ordenProfesores, ['asc', 'desc', 'clases_desc', 'clases_asc'], true)) {
    $ordenProfesores = 'asc';
}

$profesoresHorario = db_all(
    'SELECT p.id_profesor, p.nombre_completo, p.activo,
            COUNT(h.id_horario) AS total_clases
     FROM profesores p
      LEFT JOIN horario_semanal h ON h.id_profesor = p.id_profesor AND h.estado = "PROGRAMADA"
          AND EXISTS (SELECT 1 FROM grados g WHERE g.id_grado = h.id_grado AND g.activo = 1)
     GROUP BY p.id_profesor, p.nombre_completo, p.activo
     ORDER BY ' . (
        $ordenProfesores === 'desc' ? 'p.nombre_completo DESC' :
        ($ordenProfesores === 'clases_desc' ? 'total_clases DESC, p.nombre_completo ASC' :
        ($ordenProfesores === 'clases_asc' ? 'total_clases ASC, p.nombre_completo ASC' : 'p.nombre_completo ASC'))
    )
);
$profesorHorarioId = get('horario_profesor', '');
$profesorNombreBuscado = get('profesor_nombre', '');
$filtroDia = (int) get('filtro_dia', '0');
$filtroHoraInicio = get('filtro_hora_inicio', '');
$filtroHoraFin = get('filtro_hora_fin', '');
$filtroMateria = (int) get('filtro_materia', '0');
$filtroGrado = get('filtro_grado', '');
$soloLibres = get('solo_libres', '') === '1';
foreach (['filtroHoraInicio', 'filtroHoraFin'] as $filtroHora) {
    if ($$filtroHora !== '' && !preg_match('/^\d{2}:\d{2}$/', $$filtroHora)) {
        $$filtroHora = '';
    }
}
if ($filtroHoraInicio !== '' && $filtroHoraFin !== '' && $filtroHoraInicio >= $filtroHoraFin) {
    $filtroHoraInicio = '';
    $filtroHoraFin = '';
}
$diasFiltro = db_all('SELECT id_dia, nombre FROM dias_semana ORDER BY orden_dia');
$materiasFiltro = [];
$gradosFiltro = [];
if ($profesorHorarioId === '' && $profesorNombreBuscado !== '') {
    $profesorEncontrado = db_one('SELECT id_profesor FROM profesores WHERE nombre_completo = ? LIMIT 1', [$profesorNombreBuscado]);
    $profesorHorarioId = $profesorEncontrado ? (string) $profesorEncontrado['id_profesor'] : '';
}
$profesorHorario = null;
$clasesCanceladasProfesor = [];
$diasHorario = [];
$diasHorarioTabla = [];
$clasesProfesor = [];
$bloquesProfesor = [];
$bloquesProfesorTabla = [];
$gridProfesor = [];
$materiasEdicion = [];
$salonesEdicion = [];
$bloquesEdicion = [];
$gradosEdicion = [];
$gradosPorHorario = [];
$slotsOcupados = [];
$materiasProfesorIds = [];

if ($profesorHorarioId !== '') {
    $profesorHorario = db_one('SELECT id_profesor, nombre_completo, activo FROM profesores WHERE id_profesor = ?', [$profesorHorarioId]);
    if ($profesorHorario) {
        $clasesCanceladasProfesor = obtener_clases_canceladas_profesor((int) $profesorHorario['id_profesor']);
        $diasHorario = db_all('SELECT * FROM dias_semana ORDER BY orden_dia');
        $diasHorarioTabla = $filtroDia > 0
            ? array_values(array_filter($diasHorario, static fn($dia) => (int) $dia['id_dia'] === $filtroDia))
            : $diasHorario;
        $materiasProfesorIds = array_map('intval', array_column(db_all(
            'SELECT h.id_materia FROM horario_semanal h
             JOIN grados g ON g.id_grado = h.id_grado AND g.activo = 1
             WHERE h.id_profesor = ?
             UNION SELECT id_materia FROM materia_profesores WHERE id_profesor = ?',
            [$profesorHorarioId, $profesorHorarioId]
        ), 'id_materia'));
        $materiasFiltro = db_all(
            'SELECT m.id_materia, m.nombre
             FROM materias m
             WHERE EXISTS (
                 SELECT 1 FROM horario_semanal h
                 JOIN grados g ON g.id_grado = h.id_grado AND g.activo = 1
                 WHERE h.id_materia = m.id_materia AND h.id_profesor = ?
             ) OR EXISTS (
                 SELECT 1 FROM materia_profesores mp
                 WHERE mp.id_materia = m.id_materia AND mp.id_profesor = ?
             )
             ORDER BY m.nombre',
            [$profesorHorarioId, $profesorHorarioId]
        );
        $salonesFiltro = db_all(
            'SELECT s.id_salon, s.nombre
             FROM salones s
             WHERE s.activo = 1 AND (
                 EXISTS (
                     SELECT 1 FROM horario_semanal h
                     JOIN grados g ON g.id_grado = h.id_grado AND g.activo = 1
                     WHERE h.id_salon = s.id_salon AND h.id_profesor = ? AND h.estado = \'PROGRAMADA\'
                 ) OR EXISTS (
                     SELECT 1 FROM salon_profesores sp
                     WHERE sp.id_salon = s.id_salon AND sp.id_profesor = ?
                 )
             )
             ORDER BY s.nombre',
            [$profesorHorarioId, $profesorHorarioId]
        );
        $gradosFiltro = db_all(
            'SELECT DISTINCT g.id_grado
             FROM grados g
             JOIN horario_semanal h ON h.id_grado = g.id_grado
             WHERE h.id_profesor = ? AND g.activo = 1
             ORDER BY CAST(SUBSTRING_INDEX(g.id_grado, \'-\', 1) AS UNSIGNED),
                      CAST(SUBSTRING_INDEX(g.id_grado, \'-\', -1) AS UNSIGNED)',
            [$profesorHorarioId]
        );
        $gradosEdicion = db_all(
            'SELECT id_grado, id_calendario FROM grados WHERE activo = 1
             ORDER BY CAST(SUBSTRING_INDEX(id_grado, \'-\', 1) AS UNSIGNED), CAST(SUBSTRING_INDEX(id_grado, \'-\', -1) AS UNSIGNED)'
        );
        foreach (db_all('SELECT id_grado, id_dia, id_bloque FROM horario_semanal') as $slotOcupado) {
            $slotsOcupados[$slotOcupado['id_grado'] . '|' . $slotOcupado['id_bloque'] . '|' . $slotOcupado['id_dia']] = true;
        }
        $materiasEdicion = db_all('SELECT id_materia, nombre FROM materias ORDER BY nombre');
        $salonesEdicion = db_all('SELECT id_salon, nombre FROM salones ORDER BY nombre');
        $bloquesEdicion = db_all(
            'SELECT id_bloque, id_calendario, numero_bloque, hora_inicio, hora_fin
             FROM bloques_horarios WHERE tipo = \'CLASE\' ORDER BY hora_inicio, numero_bloque'
        );
        $bloquesPorHorarioCalendario = [];
        foreach ($bloquesEdicion as $bloqueEdicion) {
            $horarioKey = $bloqueEdicion['hora_inicio'] . '|' . $bloqueEdicion['hora_fin'];
            $bloquesPorHorarioCalendario[$horarioKey][(int) $bloqueEdicion['id_calendario']][] = $bloqueEdicion;
        }
        foreach ($gradosEdicion as $gradoEdicion) {
            foreach ($bloquesPorHorarioCalendario as $horarioKey => $calendariosPorHorario) {
                $opcionesBloque = $calendariosPorHorario[(int) $gradoEdicion['id_calendario']] ?? [];
                if ($opcionesBloque) {
                    $gradosPorHorario[$horarioKey][] = [
                        'id_grado' => $gradoEdicion['id_grado'],
                        'id_bloque' => $opcionesBloque[0]['id_bloque'],
                    ];
                }
            }
        }
        $bloques = $soloLibres
            ? db_all(
                'SELECT DISTINCT hora_inicio, hora_fin, tipo
                 FROM bloques_horarios WHERE tipo = \'CLASE\'
                 ORDER BY hora_inicio, hora_fin'
            )
            : db_all(
            'SELECT DISTINCT b.hora_inicio, b.hora_fin, b.tipo
             FROM horario_semanal h
             JOIN grados g ON g.id_grado = h.id_grado
             JOIN bloques_horarios b ON b.id_calendario = g.id_calendario
             WHERE h.id_profesor = ? AND g.activo = 1
             ORDER BY b.hora_inicio, b.hora_fin',
            [$profesorHorarioId]
        );
        foreach ($bloques as $bloque) {
            $key = $bloque['hora_inicio'] . '|' . $bloque['hora_fin'];
            if (!isset($bloquesProfesor[$key]) || $bloque['tipo'] === 'CLASE') {
                $bloquesProfesor[$key] = $bloque;
            }
        }
        $bloquesProfesorTabla = array_filter($bloquesProfesor, static function (array $bloque) use ($filtroHoraInicio, $filtroHoraFin): bool {
            $horaInicio = format_time($bloque['hora_inicio']);
            $horaFin = format_time($bloque['hora_fin']);
            return (!$filtroHoraInicio || $horaFin > $filtroHoraInicio)
                && (!$filtroHoraFin || $horaInicio < $filtroHoraFin);
        });

        $clasesProfesor = db_all(
                'SELECT h.*, g.id_grado, g.id_calendario, m.nombre AS materia, s.nombre AS salon,
                    b.hora_inicio, b.hora_fin
             FROM horario_semanal h
             JOIN grados g ON g.id_grado = h.id_grado
             JOIN materias m ON m.id_materia = h.id_materia
             JOIN dias_semana d ON d.id_dia = h.id_dia
             JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
             LEFT JOIN salones s ON s.id_salon = h.id_salon
             WHERE h.id_profesor = ? AND g.activo = 1
             ORDER BY b.hora_inicio, d.orden_dia, g.id_grado',
            [$profesorHorarioId]
        );
        $clasesOcultasProfesor = [];
        foreach ($clasesProfesor as $clase) {
            $key = $clase['hora_inicio'] . '|' . $clase['hora_fin'];
            if ($soloLibres
                || ($filtroMateria > 0 && (int) $clase['id_materia'] !== $filtroMateria)
                || ($filtroGrado !== '' && $clase['id_grado'] !== $filtroGrado)) {
                $clasesOcultasProfesor[$key][(int) $clase['id_dia']] = true;
                continue;
            }
            $gridProfesor[$key][(int) $clase['id_dia']][] = $clase;
        }
    } else {
        $profesorHorarioId = '';
    }
}

require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card">
    <div class="card-head">
        <h2><?= $profesorHorario ? 'Horario de ' . e($profesorHorario['nombre_completo']) : 'Seleccione un profesor' ?></h2>
        <button class="btn btn-primary" type="button" data-open-modal="tpl-create" data-title="Nuevo profesor">🟢 Nuevo profesor</button>
    </div>
    <form class="toolbar" method="get">
        <div class="teacher-picker" id="teacher-picker">
            <input class="search" type="search" id="profesor-search" name="profesor_nombre" value="<?= e($profesorHorario['nombre_completo'] ?? $profesorNombreBuscado) ?>" placeholder="Escriba el nombre del profesor" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="profesor-options">
            <button class="teacher-picker-toggle" type="button" aria-label="Mostrar profesores" aria-expanded="false">▾</button>
            <div class="teacher-options" id="profesor-options" role="listbox" hidden>
            <?php foreach ($profesoresHorario as $profesor): ?>
                <button class="teacher-option" type="button" role="option" data-id="<?= (int) $profesor['id_profesor'] ?>" data-name="<?= e($profesor['nombre_completo']) ?>">
                    <?= e($profesor['nombre_completo']) ?>
                </button>
            <?php endforeach; ?>
                <p class="teacher-no-results" hidden>No hay profesores que coincidan.</p>
            </div>
        </div>
        <input type="hidden" id="profesor-id" name="horario_profesor" value="<?= e($profesorHorarioId) ?>">
        <button class="btn btn-secondary" id="profesor-submit" type="submit" <?= $profesorHorario ? '' : 'disabled' ?>>Ver horario</button>
        <button class="btn btn-ghost" type="button" data-toggle-schedule-filters="profesor-filters" aria-controls="profesor-filters" aria-expanded="<?= !$profesorHorario || $filtroDia || $filtroHoraInicio || $filtroHoraFin || $filtroMateria || $filtroGrado !== '' || $soloLibres || $ordenProfesores !== 'asc' ? 'true' : 'false' ?>">Filtros</button>
        <div class="schedule-filter-panel" id="profesor-filters" <?= $profesorHorario && !$filtroDia && !$filtroHoraInicio && !$filtroHoraFin && !$filtroMateria && $filtroGrado === '' && !$soloLibres && $ordenProfesores === 'asc' ? 'hidden' : '' ?>>
            <div class="field">
                <label for="orden-profesores">Ordenar profesores</label>
                <select id="orden-profesores" name="orden_profesores" onchange="this.form.submit()">
                    <option value="asc" <?= $ordenProfesores === 'asc' ? 'selected' : '' ?>>A-Z</option>
                    <option value="desc" <?= $ordenProfesores === 'desc' ? 'selected' : '' ?>>Z-A</option>
                    <option value="clases_desc" <?= $ordenProfesores === 'clases_desc' ? 'selected' : '' ?>>Más clases primero</option>
                    <option value="clases_asc" <?= $ordenProfesores === 'clases_asc' ? 'selected' : '' ?>>Menos clases primero</option>
                </select>
            </div>
            <div class="field">
                <label>Día</label>
                <select name="filtro_dia">
                    <option value="0">Todos los días</option>
                    <?php foreach ($diasFiltro as $dia): ?>
                        <option value="<?= (int) $dia['id_dia'] ?>" <?= $filtroDia === (int) $dia['id_dia'] ? 'selected' : '' ?>><?= e($dia['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Hora inicio</label>
                <input type="time" name="filtro_hora_inicio" value="<?= e($filtroHoraInicio) ?>">
            </div>
            <div class="field">
                <label>Hora fin</label>
                <input type="time" name="filtro_hora_fin" value="<?= e($filtroHoraFin) ?>">
            </div>
            <div class="field">
                <label>Materia</label>
                <select name="filtro_materia">
                    <option value="0">Todas las materias</option>
                    <?php foreach ($materiasFiltro as $materia): ?>
                        <option value="<?= (int) $materia['id_materia'] ?>" <?= $filtroMateria === (int) $materia['id_materia'] ? 'selected' : '' ?>><?= e($materia['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Grado</label>
                <select name="filtro_grado">
                    <option value="">Todos los grados</option>
                    <?php foreach ($gradosFiltro as $gradoFiltro): ?>
                        <option value="<?= e($gradoFiltro['id_grado']) ?>" <?= $filtroGrado === $gradoFiltro['id_grado'] ? 'selected' : '' ?>><?= e($gradoFiltro['id_grado']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="schedule-filter-actions">
                <label class="schedule-filter-check"><input type="checkbox" name="solo_libres" value="1" <?= $soloLibres ? 'checked' : '' ?>> Solo espacios libres</label>
                <button class="btn btn-secondary" type="submit" <?= $profesorHorario ? '' : 'disabled' ?>>Aplicar filtros</button>
            </div>
        </div>
    </form>
    <?php if ($profesorHorario): ?>
        <div class="toolbar" style="justify-content: space-between; align-items: center; gap: 1rem;">
            <span class="status-badge <?= (int) $profesorHorario['activo'] === 1 ? 'success' : 'muted' ?>">
                <?= (int) $profesorHorario['activo'] === 1 ? 'Activo' : 'Inactivo' ?>
            </span>
            <div class="actions">
                <?php if ((int) $profesorHorario['activo'] === 1): ?>
                    <button class="btn btn-secondary" type="button" data-open-modal="tpl-profesor-status" data-title="Desactivar profesor">
                        Desactivar profesor
                    </button>
                <?php else: ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id_profesor" value="<?= (int) $profesorHorario['id_profesor'] ?>">
                        <input type="hidden" name="horario_profesor" value="<?= (int) $profesorHorario['id_profesor'] ?>">
                        <button class="btn btn-secondary" type="submit">Activar profesor</button>
                    </form>
                <?php endif; ?>
                <button class="btn btn-ghost" type="button" data-open-modal="tpl-clases-canceladas" data-title="Clases canceladas">
                    Ver clases canceladas
                </button>
            </div>
        </div>
        <div class="table-wrap">
            <table class="schedule">
                <thead>
                <tr>
                    <th>Hora</th>
                    <?php foreach ($diasHorarioTabla as $dia): ?>
                        <th>
                            <?php if ((int) $profesorHorario['activo'] === 1): ?>
                                <button class="schedule-day-action" type="button" data-professor-day-cancel data-day-id="<?= (int) $dia['id_dia'] ?>" data-day-name="<?= e($dia['nombre']) ?>" aria-label="Cancelar clases del día <?= e($dia['nombre']) ?>">
                                    <?= e($dia['nombre']) ?>
                                </button>
                            <?php else: ?>
                                <?= e($dia['nombre']) ?>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($bloquesProfesorTabla as $key => $bloque): ?>
                    <tr>
                        <td><strong><?= e(format_time($bloque['hora_inicio'])) ?> - <?= e(format_time($bloque['hora_fin'])) ?></strong></td>
                        <?php foreach ($diasHorarioTabla as $dia): ?>
                            <td>
                                <?php if ($bloque['tipo'] === 'DESCANSO'): ?>
                                    <div class="cell-break">Descanso</div>
                                <?php else: ?>
                                    <?php $clasesCelda = $gridProfesor[$key][(int) $dia['id_dia']] ?? []; ?>
                                    <?php if ($clasesCelda): ?>
                                        <?php foreach ($clasesCelda as $clase): ?>
                                            <div class="cell-class" data-edit="tpl-edit-class-<?= (int) $clase['id_horario'] ?>" data-title="Editar clase" role="button" tabindex="0">
                                                <strong><?= e($clase['id_grado']) ?></strong>
                                                <small><?= e($clase['materia']) ?></small>
                                                <small><?= e($clase['salon'] ?: 'Sin salón') ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php elseif (!empty($clasesOcultasProfesor[$key][(int) $dia['id_dia']])): ?>
                                        <div class="cell-class is-filtered-out" aria-hidden="true"></div>
                                    <?php else: ?>
                                        <?php
                                        $gradosCompatibles = array_values(array_filter(
                                            $gradosPorHorario[$key] ?? [],
                                            static function (array $opcion) use ($slotsOcupados, $filtroGrado, $filtroMateria, $materiasProfesorIds, $dia): bool {
                                                return !isset($slotsOcupados[$opcion['id_grado'] . '|' . $opcion['id_bloque'] . '|' . $dia['id_dia']])
                                                    && ($filtroGrado === '' || $opcion['id_grado'] === $filtroGrado)
                                                    && ($filtroMateria === 0 || in_array($filtroMateria, $materiasProfesorIds, true));
                                            }
                                        ));
                                        ?>
                                        <?php if ($gradosCompatibles): ?>
                                            <?php $templateKey = 'tpl-create-class-' . str_replace([':', '|'], '-', $key) . '-' . (int) $dia['id_dia']; ?>
                                            <div class="cell-empty" data-open-modal="<?= e($templateKey) ?>" data-title="Nueva clase">+</div>
                                        <?php else: ?>
                                            <div class="cell-empty">—</div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$bloquesProfesor): ?>
            <p class="muted">Este profesor todavía no tiene clases asignadas.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php if (!$profesorHorario): ?>
    <section class="empty-state-panel" aria-labelledby="profesor-empty-title">
        <div class="empty-state-icon" aria-hidden="true">📅</div>
        <p class="empty-state-eyebrow">HORARIO DOCENTE</p>
        <h2 id="profesor-empty-title">Elige un profesor</h2>
        <p>Busca su nombre para consultar las clases asignadas durante la semana.</p>
    </section>
<?php endif; ?>
<template id="tpl-create">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="field">
            <label>Nombre completo</label>
            <input name="nombre_completo" required>
        </div>
        <button class="btn btn-primary" type="submit">Guardar</button>
    </form>
</template>
<template id="tpl-profesor-status">
    <form method="post" data-professor-status data-classes-url="<?= e(APP_BASE) ?>/admin/profesores.php?api=clases_reemplazo_profesor" data-professor-name="<?= e($profesorHorario['nombre_completo']) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="id_profesor" value="<?= (int) $profesorHorario['id_profesor'] ?>">
        <input type="hidden" name="horario_profesor" value="<?= (int) $profesorHorario['id_profesor'] ?>">
        <div class="field">
            <label>¿Qué desea hacer con las clases asignadas?</label>
            <select name="modo_cancelacion">
                <option value="cancel">Cancelar clases</option>
                <option value="replace">Reemplazar clases</option>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">Continuar</button>
    </form>
</template>
<template id="tpl-clases-canceladas">
    <div class="table-wrap">
        <?php if (!empty($clasesCanceladasProfesor)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Grado</th>
                        <th>Asignatura</th>
                        <th>Día</th>
                        <th>Horario</th>
                        <th>Estado</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clasesCanceladasProfesor as $claseCancelada): ?>
                        <tr>
                            <td><?= e((string) $claseCancelada['id_grado']) ?></td>
                            <td><?= e((string) $claseCancelada['materia']) ?></td>
                            <td><?= e((string) $claseCancelada['dia']) ?></td>
                            <td><?= e((string) $claseCancelada['hora_inicio']) ?> - <?= e((string) $claseCancelada['hora_fin']) ?></td>
                            <td><?= e((string) $claseCancelada['estado_actual']) ?></td>
                            <td><?= e((string) ($claseCancelada['motivo_cancelacion'] ?: $claseCancelada['observacion'] ?: 'Sin motivo registrado')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted">No existen clases canceladas para este profesor.</p>
        <?php endif; ?>
    </div>
</template>
<?php foreach ($clasesProfesor as $clase): ?>
    <?php $claseIntermedia = profesor_clase_es_intermedia((int) $clase['id_horario']); ?>
    <template id="tpl-edit-class-<?= (int) $clase['id_horario'] ?>">
        <form method="post" data-professor-delete-class data-middle-class="<?= $claseIntermedia ? 'true' : 'false' ?>" data-current-teacher="<?= (int) $clase['id_profesor'] ?>" data-confirm="¿Eliminar esta clase? También se quitará del horario del grado.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_class">
            <input type="hidden" name="id_profesor" value="<?= (int) $profesorHorario['id_profesor'] ?>">
            <input type="hidden" name="id_horario" value="<?= (int) $clase['id_horario'] ?>">
            <input type="hidden" name="horario_profesor" value="<?= (int) $profesorHorario['id_profesor'] ?>">
            <?php if ($claseIntermedia): ?>
                <div class="field">
                    <label>Profesor para reemplazar esta clase</label>
                    <select name="profesor_reemplazo" data-professor-replacement>
                        <option value="">Elige un profesor</option>
                        <?php foreach ($profesores as $profesorReemplazo): ?>
                            <?php if ((int) $profesorReemplazo['id_profesor'] === (int) $clase['id_profesor']) continue; ?>
                            <option value="<?= (int) $profesorReemplazo['id_profesor'] ?>"><?= e($profesorReemplazo['nombre_completo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <button class="btn btn-danger" type="submit">Eliminar clase</button>
        </form>
        <form method="post" data-room-availability data-professor-class-edit data-middle-class="<?= $claseIntermedia ? 'true' : 'false' ?>" data-current-teacher="<?= (int) $clase['id_profesor'] ?>" data-availability-url="<?= e(APP_BASE) ?>/admin/horarios.php?api=salones">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_class">
            <input type="hidden" name="id_horario" value="<?= (int) $clase['id_horario'] ?>">
            <input type="hidden" name="id_profesor" value="<?= (int) $profesorHorario['id_profesor'] ?>">
            <input type="hidden" name="horario_profesor" value="<?= (int) $profesorHorario['id_profesor'] ?>">
            <div class="field">
                <label>Grado</label>
                <input value="<?= e($clase['id_grado']) ?>" disabled>
            </div>
            <div class="grid-2">
                <div class="field">
                    <label>Día</label>
                    <select name="id_dia" required><?= options_html($diasHorario, 'id_dia', 'nombre', $clase['id_dia']) ?></select>
                </div>
                <div class="field">
                    <label>Rango de la clase</label>
                    <select name="id_bloque" required>
                        <?php foreach ($bloquesEdicion as $bloqueEdicion): ?>
                            <?php if ((int) $bloqueEdicion['id_calendario'] !== (int) $clase['id_calendario']) continue; ?>
                            <option value="<?= (int) $bloqueEdicion['id_bloque'] ?>" <?= (int) $clase['id_bloque'] === (int) $bloqueEdicion['id_bloque'] ? 'selected' : '' ?>>
                                <?= e(format_time($bloqueEdicion['hora_inicio'])) ?> - <?= e(format_time($bloqueEdicion['hora_fin'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label>Materia</label>
                <select name="id_materia" required><?= options_html($materiasEdicion, 'id_materia', 'nombre', $clase['id_materia'], false) ?></select>
            </div>
            <div class="grid-2">
                <div class="field">
                    <label>Profesor</label>
                    <select name="nuevo_profesor">
                        <option value="">Sin profesor</option>
                        <?= options_html($profesoresHorario, 'id_profesor', 'nombre_completo', $clase['id_profesor'], false) ?>
                    </select>
                </div>
                <div class="field">
                    <label>Salón</label>
                    <select name="id_salon">
                        <option value="">Sin salón</option>
                        <?= options_html($salonesEdicion, 'id_salon', 'nombre', $clase['id_salon'], false) ?>
                    </select>
                </div>
            </div>
            <div class="availability-status muted" aria-live="polite">Consultando salones disponibles...</div>
            <div class="grid-2">
                <div class="field">
                    <label>Estado</label>
                    <select name="estado">
                        <?php foreach (['PROGRAMADA', 'DESCANSO', 'CANCELADA'] as $estado): ?>
                            <option value="<?= e($estado) ?>" <?= $clase['estado'] === $estado ? 'selected' : '' ?>><?= e($estado) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Observación</label>
                    <input name="observacion" value="<?= e((string) $clase['observacion']) ?>">
                </div>
            </div>
            <button class="btn btn-primary" type="submit">Guardar cambios</button>
        </form>
    </template>
<?php endforeach; ?>
<?php foreach ($bloquesProfesor as $key => $bloque): ?>
    <?php if ($bloque['tipo'] !== 'CLASE' || empty($gradosPorHorario[$key])) continue; ?>
    <?php foreach ($diasHorario as $dia): ?>
        <?php
        $gradosCompatibles = array_values(array_filter(
            $gradosPorHorario[$key],
            static fn($opcion) => !isset($slotsOcupados[$opcion['id_grado'] . '|' . $opcion['id_bloque'] . '|' . $dia['id_dia']])
        ));
        if (!$gradosCompatibles) continue;
        ?>
        <?php $templateKey = 'tpl-create-class-' . str_replace([':', '|'], '-', $key) . '-' . (int) $dia['id_dia']; ?>
        <template id="<?= e($templateKey) ?>">
            <form method="post" data-room-availability data-availability-url="<?= e(APP_BASE) ?>/admin/horarios.php?api=salones">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_class">
                <input type="hidden" name="id_profesor" value="<?= (int) $profesorHorario['id_profesor'] ?>">
                <input type="hidden" name="id_dia" value="<?= (int) $dia['id_dia'] ?>">
                <input type="hidden" name="id_bloque" value="">
                <input type="hidden" name="hora_inicio" value="<?= e($bloque['hora_inicio']) ?>">
                <input type="hidden" name="hora_fin" value="<?= e($bloque['hora_fin']) ?>">
                <div class="field">
                    <label>Horario</label>
                    <input value="<?= e($dia['nombre']) ?>, <?= e(format_time($bloque['hora_inicio'])) ?> - <?= e(format_time($bloque['hora_fin'])) ?>" disabled>
                </div>
                <div class="field">
                    <label>Grado</label>
                    <select name="id_grado" data-grade-block required>
                        <option value="">Seleccione un grado</option>
                        <?php foreach ($gradosCompatibles as $gradoOpcion): ?>
                            <option value="<?= e($gradoOpcion['id_grado']) ?>" data-block-id="<?= (int) $gradoOpcion['id_bloque'] ?>"><?= e($gradoOpcion['id_grado']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Materia</label>
                    <select name="id_materia" required>
                        <option value="">Elige una materia</option>
                        <?= options_html($materiasFiltro, 'id_materia', 'nombre', null, false) ?>
                    </select>
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label>Salón</label>
                        <select name="id_salon">
                            <option value="">Sin salón</option>
                            <?= options_html($salonesFiltro, 'id_salon', 'nombre', null, false) ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Estado</label>
                        <select name="estado">
                            <option value="PROGRAMADA">PROGRAMADA</option>
                            <option value="CANCELADA">CANCELADA</option>
                        </select>
                    </div>
                </div>
                <div class="availability-status muted" aria-live="polite">Seleccione grado para consultar salones disponibles.</div>
                <div class="field">
                    <label>Observación</label>
                    <input name="observacion">
                </div>
                <button class="btn btn-primary" type="submit">Guardar clase</button>
            </form>
        </template>
    <?php endforeach; ?>
<?php endforeach; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
