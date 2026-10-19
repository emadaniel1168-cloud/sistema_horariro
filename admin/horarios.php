<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin_auth();
asegurar_columna_bloque_agregado_horario();
asegurar_esquema_novedades();
limpiar_avisos_temporales_eliminacion();
sincronizar_novedades_con_horario();
asegurar_tabla_huecos_horario_temporales();
asegurar_tabla_descansos_reemplazados();
asegurar_tabla_descansos_por_dia();

$sectionTitle = 'Horario semanal';
if (get('api') === 'buscar-clase') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $dia = (int) get('dia', '0');
        $horaInicio = get('hora_inicio');
        $horaFin = get('hora_fin');
        $calendario = (int) get('id_calendario', '0');
        $bloqueId = (int) get('id_bloque', '0');
        $grado = get('grado');
        $profesor = get('profesor');
        $materia = get('materia');
        $salon = get('salon');
        if (!$dia || !$horaInicio || !$horaFin || $horaInicio >= $horaFin || !$grado || !$calendario || !$bloqueId) {
            throw new RuntimeException('Indique calendario, fecha, bloque y grado para buscar.');
        }
        $gradoRow = db_one('SELECT id_calendario FROM grados WHERE id_grado = ?', [$grado]);
        $bloqueRow = db_one('SELECT id_calendario, hora_inicio, hora_fin FROM bloques_horarios WHERE id_bloque = ? AND tipo = \'CLASE\'', [$bloqueId]);
        if (!$gradoRow || (int) $gradoRow['id_calendario'] !== $calendario) {
            throw new RuntimeException('El grado seleccionado no pertenece a este calendario. Seleccione el grado nuevamente.');
        }
        if (!$bloqueRow || (int) $bloqueRow['id_calendario'] !== $calendario
            || format_time($bloqueRow['hora_inicio']) !== $horaInicio
            || format_time($bloqueRow['hora_fin']) !== $horaFin) {
            throw new RuntimeException('El bloque seleccionado no corresponde al calendario y las horas indicadas.');
        }

        $sql = "SELECT h.id_horario, h.id_grado, h.id_dia, h.id_bloque, h.id_profesor, h.id_materia, h.id_salon,
                       d.nombre AS dia, m.nombre AS materia, p.nombre_completo AS profesor, s.nombre AS salon,
                       TIME_FORMAT(b.hora_inicio, '%H:%i') AS hora_inicio,
                       TIME_FORMAT(b.hora_fin, '%H:%i') AS hora_fin
                FROM horario_semanal h
                JOIN dias_semana d ON d.id_dia = h.id_dia
                JOIN materias m ON m.id_materia = h.id_materia
                JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
                LEFT JOIN salones s ON s.id_salon = h.id_salon
                WHERE h.estado = 'PROGRAMADA' AND h.id_dia = ? AND h.id_grado = ?
                                    AND h.id_bloque = ?";
                $params = [$dia, $grado, $bloqueId];
        foreach (['profesor' => 'h.id_profesor', 'materia' => 'h.id_materia', 'salon' => 'h.id_salon'] as $filter => $column) {
            $value = get($filter);
            if ($value !== null) {
                $sql .= ' AND ' . $column . ' = ?';
                $params[] = (int) $value;
            }
        }
        $sql .= ' ORDER BY h.id_grado, m.nombre';
        echo json_encode(['clases' => db_all($sql, $params)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if (get('api') === 'disponibilidad') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $grado = get('grado');
        $fecha = get('fecha');
        $bloqueId = (int) get('id_bloque', '0');
        $tipoRecurso = get('recurso');
        $horarioId = (int) get('id_horario', '0');
        $horaInicio = get('hora_inicio');
        $horaFin = get('hora_fin');
        $fechaValida = $fecha ? DateTimeImmutable::createFromFormat('!Y-m-d', $fecha) : false;
        if (!$grado || !$fechaValida || $fechaValida->format('Y-m-d') !== $fecha || !$bloqueId || !in_array($tipoRecurso, ['profesor', 'salon', 'hora'], true)) {
            throw new RuntimeException('Seleccione grado, fecha, hora y recurso para consultar disponibilidad.');
        }
        $gradoRow = db_one('SELECT id_calendario FROM grados WHERE id_grado = ?', [$grado]);
        $bloqueRow = db_one('SELECT id_calendario, tipo, hora_inicio, hora_fin FROM bloques_horarios WHERE id_bloque = ?', [$bloqueId]);
        if (!$gradoRow || !$bloqueRow || $bloqueRow['tipo'] !== 'CLASE' || (int) $gradoRow['id_calendario'] !== (int) $bloqueRow['id_calendario']) {
            throw new RuntimeException('El bloque no corresponde al calendario del grado seleccionado.');
        }
        $dia = (int) $fechaValida->format('N');
        $horarioBase = $horarioId ? db_one('SELECT id_grado, id_dia, id_profesor, id_salon FROM horario_semanal WHERE id_horario = ?', [$horarioId]) : null;
        if ($horarioId && (!$horarioBase || $horarioBase['id_grado'] !== $grado || (int) $horarioBase['id_dia'] !== $dia)) {
            throw new RuntimeException('La fecha debe corresponder al día de la semana del horario seleccionado.');
        }
        if ($tipoRecurso === 'hora' && (!preg_match('/^\d{2}:\d{2}$/', (string) $horaInicio) || !preg_match('/^\d{2}:\d{2}$/', (string) $horaFin))) {
            throw new RuntimeException('Seleccione una hora inicial y final válidas.');
        }
        $horaInicio = $horaInicio ?: $bloqueRow['hora_inicio'];
        $horaFin = $horaFin ?: $bloqueRow['hora_fin'];
        if ($horaInicio >= $horaFin) {
            throw new RuntimeException('La hora inicial debe ser anterior a la hora final.');
        }
        $diaNombre = db_one('SELECT nombre FROM dias_semana WHERE orden_dia = ?', [$dia])['nombre'] ?? '';
        $ocupaciones = ocupaciones_horario($dia, $horaInicio, $horaFin, $fecha, $horarioId ?: null);
        $idKey = $tipoRecurso === 'profesor' ? 'id_profesor' : 'id_salon';
        $catalogo = $tipoRecurso === 'profesor'
            ? db_all('SELECT id_profesor AS id, nombre_completo AS nombre FROM profesores WHERE activo = 1 ORDER BY nombre_completo')
            : ($tipoRecurso === 'salon' ? db_all("SELECT id_salon AS id, nombre FROM salones WHERE activo = 1 ORDER BY CASE WHEN nombre REGEXP '[0-9]+$' THEN 0 ELSE 1 END, CAST(SUBSTRING_INDEX(nombre, ' ', -1) AS UNSIGNED), nombre") : []);
        $conflictos = [];
        foreach ($ocupaciones as $ocupacion) {
            $idRecurso = (int) ($ocupacion[$idKey] ?? 0);
            if (!$idRecurso) continue;
            $conflictos[$idRecurso][] = $ocupacion['id_grado'] . ' · ' . $ocupacion['materia'] . ' · ' .
                ($ocupacion['profesor'] ?: 'sin profesor') . ' · ' . ($ocupacion['salon'] ?: 'sin salón') . ' · ' .
                $ocupacion['hora_inicio'] . '-' . $ocupacion['hora_fin'];
        }
        $disponibles = [];
        foreach ($catalogo as $recurso) {
            if (!isset($conflictos[(int) $recurso['id']])) {
                $disponibles[] = ['id' => (int) $recurso['id'], 'nombre' => $recurso['nombre']];
            }
        }
        $conflictosHora = [];
        if ($tipoRecurso === 'hora' && $horarioBase) {
            foreach ($ocupaciones as $ocupacion) {
                $razones = [];
                if ($horarioBase['id_grado'] === $ocupacion['id_grado']) $razones[] = 'el grado ya tiene ' . $ocupacion['materia'];
                if ($horarioBase['id_profesor'] && (int) $horarioBase['id_profesor'] === (int) ($ocupacion['id_profesor'] ?? 0)) $razones[] = 'el profesor ' . $ocupacion['profesor'] . ' ya tiene clase';
                if ($horarioBase['id_salon'] && (int) $horarioBase['id_salon'] === (int) ($ocupacion['id_salon'] ?? 0)) $razones[] = 'el salón ' . $ocupacion['salon'] . ' ya está ocupado';
                if ($razones) {
                    $conflictosHora[] = implode(', ', $razones) . ' con ' . $ocupacion['id_grado'] . ', ' . $ocupacion['materia'] . ', ' . ($ocupacion['profesor'] ?: 'sin profesor') . ', salón ' . ($ocupacion['salon'] ?: 'sin salón') . ' de ' . $ocupacion['hora_inicio'] . '-' . $ocupacion['hora_fin'] . '.';
                }
            }
        }
        echo json_encode([
            'recurso' => $tipoRecurso,
            'fecha' => $fecha,
            'diaName' => $diaNombre,
            'hora_inicio' => format_time($horaInicio),
            'hora_fin' => format_time($horaFin),
            'disponibles' => $disponibles,
            'conflictos_hora' => $conflictosHora,
            'ocupados' => array_map(static fn($recurso) => [
                'nombre' => $recurso['nombre'],
                'detalle' => implode('; ', $conflictos[(int) $recurso['id']]),
            ], array_filter($catalogo, static fn($recurso) => isset($conflictos[(int) $recurso['id']]))),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if (get('api') === 'salones') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $fecha = get('fecha');
        $horarioId = (int) get('id_horario', '0');
        $ignorarHorario = (int) get('ignorar_horario', '0');
        $dia = (int) get('dia', '0');
        $horaInicio = get('hora_inicio');
        $horaFin = get('hora_fin');

        if ($fecha !== null) {
            $fechaValida = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
            if (!$fechaValida || $fechaValida->format('Y-m-d') !== $fecha) {
                throw new RuntimeException('Seleccione una fecha válida para buscar salones.');
            }
            $dia = (int) $fechaValida->format('N');
            if ((!$horaInicio || !$horaFin) && $horarioId) {
                $claseBase = db_one(
                    'SELECT b.hora_inicio, b.hora_fin FROM horario_semanal h JOIN bloques_horarios b ON b.id_bloque = h.id_bloque WHERE h.id_horario = ?',
                    [$horarioId]
                );
                $horaInicio = $claseBase['hora_inicio'] ?? null;
                $horaFin = $claseBase['hora_fin'] ?? null;
            }
            $ignorarHorario = $horarioId ?: $ignorarHorario;
        } else {
            $dia = $dia ?: (int) get('id_dia', '0');
            $bloqueId = (int) get('id_bloque', '0');
            $bloqueBase = $bloqueId ? db_one('SELECT hora_inicio, hora_fin FROM bloques_horarios WHERE id_bloque = ? AND tipo = \'CLASE\'', [$bloqueId]) : null;
            $horaInicio = $bloqueBase['hora_inicio'] ?? null;
            $horaFin = $bloqueBase['hora_fin'] ?? null;
            $ignorarHorario = $horarioId ?: $ignorarHorario;
        }

        if (!$dia || !$horaInicio || !$horaFin || $horaInicio >= $horaFin) {
            throw new RuntimeException('Seleccione día y hora para consultar salones.');
        }

        $ocupaciones = ocupaciones_horario($dia, $horaInicio, $horaFin, $fecha, $ignorarHorario ?: null);
        $salones = db_all("SELECT id_salon, nombre FROM salones WHERE activo = 1 ORDER BY CASE WHEN nombre REGEXP '[0-9]+$' THEN 0 ELSE 1 END, CAST(SUBSTRING_INDEX(nombre, ' ', -1) AS UNSIGNED), nombre");
        $profesores = db_all('SELECT id_profesor, nombre_completo FROM profesores WHERE activo = 1 ORDER BY nombre_completo');
        $profesorId = (int) (get('id_profesor') ?: get('nuevo_profesor', '0'));
        $conflictoProfesor = null;
        $resultado = [];
        $profesoresOcupados = [];
        foreach ($ocupaciones as $ocupacion) {
            if (!empty($ocupacion['id_profesor'])) {
                $profesoresOcupados[(int) $ocupacion['id_profesor']] = $ocupacion;
            }
        }
        $resultadoProfesores = array_map(static function (array $profesor) use ($profesoresOcupados): array {
            $ocupacion = $profesoresOcupados[(int) $profesor['id_profesor']] ?? null;
            return [
                'id' => (int) $profesor['id_profesor'],
                'nombre' => $profesor['nombre_completo'],
                'disponible' => $ocupacion === null,
                'conflicto' => $ocupacion ? $ocupacion['id_grado'] . ' · ' . $ocupacion['materia'] : null,
            ];
        }, $profesores);
        foreach ($salones as $salon) {
            $conflicto = null;
            foreach ($ocupaciones as $ocupacion) {
                if ((int) ($ocupacion['id_salon'] ?? 0) === (int) $salon['id_salon']) {
                    $conflicto = $ocupacion['id_grado'] . ' · ' . $ocupacion['materia'] . ' · ' .
                        ($ocupacion['profesor'] ?: 'sin profesor') . ' · ' .
                        $ocupacion['hora_inicio'] . '-' . $ocupacion['hora_fin'];
                    break;
                }
            }
            $resultado[] = [
                'id' => (int) $salon['id_salon'],
                'nombre' => $salon['nombre'],
                'disponible' => $conflicto === null,
                'conflicto' => $conflicto,
            ];
        }
        if ($profesorId) {
            foreach ($ocupaciones as $ocupacion) {
                if ((int) ($ocupacion['id_profesor'] ?? 0) === $profesorId) {
                    $conflictoProfesor = 'El profesor ya tiene clase de ' . $ocupacion['materia'] . ' con ' .
                        $ocupacion['id_grado'] . ' en el salón ' . ($ocupacion['salon'] ?: 'sin salón') .
                        ' de ' . $ocupacion['hora_inicio'] . '-' . $ocupacion['hora_fin'] . '.';
                    break;
                }
            }
        }

        echo json_encode(['salones' => $resultado, 'profesores' => $resultadoProfesores, 'conflicto_profesor' => $conflictoProfesor], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
asegurar_tabla_materia_profesores();
asegurar_tabla_salon_profesores();
asegurar_tabla_novedades_temporales();
asegurar_tabla_horario_original_novedad();
$grados = db_all('SELECT g.id_grado, c.nombre AS calendario FROM grados g JOIN calendarios c ON c.id_calendario = g.id_calendario WHERE g.activo = 1 ORDER BY CAST(SUBSTRING_INDEX(g.id_grado, \'-\', 1) AS UNSIGNED), CAST(SUBSTRING_INDEX(g.id_grado, \'-\', -1) AS UNSIGNED)');
$gradosSinHorario = db_all(
    'SELECT g.id_grado, g.id_calendario
     FROM grados g
     LEFT JOIN horario_semanal h ON h.id_grado = g.id_grado
     WHERE g.activo = 1 AND h.id_horario IS NULL
     ORDER BY CAST(SUBSTRING_INDEX(g.id_grado, \'-\', 1) AS UNSIGNED), CAST(SUBSTRING_INDEX(g.id_grado, \'-\', -1) AS UNSIGNED)'
);
$crearHorarioNuevo = get('crear_horario', '') === '1';
if (array_key_exists('grado', $_GET)) {
    $grado = get('grado');
    if (!$grado || !in_array($grado, array_column($grados, 'id_grado'), true)) {
        unset($_SESSION['horario_grado']);
        $grado = null;
    } else {
        $_SESSION['horario_grado'] = $grado;
    }
} else {
    $grado = $_SESSION['horario_grado'] ?? null;
    if ($grado && !in_array($grado, array_column($grados, 'id_grado'), true)) {
        unset($_SESSION['horario_grado']);
        $grado = null;
    }
}
if ($crearHorarioNuevo && $grado && db_count('SELECT COUNT(*) AS total FROM horario_semanal WHERE id_grado = ?', [$grado]) > 0) {
    flash_set('error', 'Solo se puede iniciar un horario nuevo para un grado que todavía no tenga clases.');
    unset($_SESSION['horario_grado']);
    $grado = null;
    $crearHorarioNuevo = false;
}
$filtroDia = (int) get('filtro_dia', '0');
$filtroHoraInicio = get('filtro_hora_inicio', '');
$filtroHoraFin = get('filtro_hora_fin', '');
$filtroMateria = (int) get('filtro_materia', '0');
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
$dias = db_all('SELECT * FROM dias_semana ORDER BY orden_dia');
$materias = db_all(
    'SELECT m.id_materia, m.nombre,
            GROUP_CONCAT(DISTINCT h.id_profesor ORDER BY h.id_profesor) AS profesores,
        GROUP_CONCAT(DISTINCT mp.id_profesor ORDER BY mp.id_profesor) AS profesores_permanentes,
            GROUP_CONCAT(DISTINCT h.id_salon ORDER BY h.id_salon) AS salones,
            GROUP_CONCAT(DISTINCT CONCAT(hc.id_profesor, CHAR(58), hc.total) ORDER BY hc.id_profesor) AS conteo_profesores
    FROM materias m LEFT JOIN horario_semanal h ON h.id_materia = m.id_materia AND h.estado = \'PROGRAMADA\'
    LEFT JOIN materia_profesores mp ON mp.id_materia = m.id_materia
    LEFT JOIN (
        SELECT id_materia, id_profesor, COUNT(*) AS total
        FROM horario_semanal
        WHERE estado = \'PROGRAMADA\'
        GROUP BY id_materia, id_profesor
    ) hc ON hc.id_materia = m.id_materia
     WHERE m.activa = 1 GROUP BY m.id_materia, m.nombre ORDER BY m.nombre'
);
foreach ($materias as &$materia) {
    $materia['todos_profesores'] = implode(',', array_unique(array_filter(array_merge(
        explode(',', (string) ($materia['profesores'] ?? '')),
        explode(',', (string) ($materia['profesores_permanentes'] ?? ''))
    ))));
}
unset($materia);
$profesores = db_all(
    "SELECT p.id_profesor, p.nombre_completo,
            GROUP_CONCAT(DISTINCT h.id_materia ORDER BY h.id_materia) AS materias,
            GROUP_CONCAT(DISTINCT h.id_salon ORDER BY h.id_salon) AS salones,
            GROUP_CONCAT(DISTINCT CASE WHEN h.permanente = 1 THEN h.id_materia END ORDER BY h.id_materia) AS materias_permanentes,
            GROUP_CONCAT(DISTINCT CASE WHEN h.permanente_salon = 1 THEN h.id_salon END ORDER BY h.id_salon) AS salones_permanentes,
            GROUP_CONCAT(DISTINCT CONCAT(hs.id_salon, ':', hs.total)) AS conteo_salones
    FROM profesores p LEFT JOIN (
        SELECT id_profesor, id_materia, id_salon, 0 AS permanente, 0 AS permanente_salon
        FROM horario_semanal WHERE estado = 'PROGRAMADA'
        UNION ALL
        SELECT id_profesor, id_materia, NULL AS id_salon, 1 AS permanente, 0 AS permanente_salon
        FROM materia_profesores
        UNION ALL
        SELECT id_profesor, NULL AS id_materia, id_salon, 0 AS permanente, 1 AS permanente_salon
        FROM salon_profesores
    ) h ON h.id_profesor = p.id_profesor
    LEFT JOIN (
        SELECT id_profesor, id_salon, COUNT(*) AS total
        FROM horario_semanal
        WHERE estado = 'PROGRAMADA' AND id_salon IS NOT NULL
        GROUP BY id_profesor, id_salon
    ) hs ON hs.id_profesor = p.id_profesor
     WHERE p.activo = 1 GROUP BY p.id_profesor, p.nombre_completo ORDER BY p.nombre_completo"
);
foreach ($profesores as &$profesor) {
    $profesor['materias_todas'] = implode(',', array_unique(array_filter(array_merge(
        explode(',', (string) ($profesor['materias'] ?? '')),
        explode(',', (string) ($profesor['materias_permanentes'] ?? ''))
    ))));
    $profesor['salones_todos'] = implode(',', array_unique(array_filter(array_merge(
        explode(',', (string) ($profesor['salones'] ?? '')),
        explode(',', (string) ($profesor['salones_permanentes'] ?? ''))
    ))));
}
unset($profesor);
$salones = db_all("SELECT id_salon, nombre FROM salones WHERE activo = 1 ORDER BY CASE WHEN nombre REGEXP '[0-9]+$' THEN 0 ELSE 1 END, CAST(SUBSTRING_INDEX(nombre, ' ', -1) AS UNSIGNED), nombre");

function horario_redirect(?string $grado): never
{
    $url = APP_BASE . '/admin/horarios.php';
    if ($grado) {
        $url .= '?grado=' . urlencode($grado);
    }
    redirect($url);
}

function calendario_base_si_es_personalizado(string $idGrado, int $idCalendarioActual): ?int
{
    $calendarioActual = db_one(
        'SELECT c.nombre FROM calendarios c WHERE c.id_calendario = ?',
        [$idCalendarioActual]
    );
    $nombreActual = trim((string) ($calendarioActual['nombre'] ?? ''));
    if ($nombreActual === '') {
        return null;
    }

    $patron = '/\s+-\s*' . preg_quote($idGrado, '/') . '$/';
    if (preg_match($patron, $nombreActual) !== 1) {
        return null;
    }

    $nombreBase = preg_replace($patron, '', $nombreActual);
    if ($nombreBase === '') {
        return null;
    }

    $calendarioBase = db_one(
        'SELECT id_calendario FROM calendarios WHERE nombre = ? AND id_calendario <> ? ORDER BY id_calendario LIMIT 1',
        [$nombreBase, $idCalendarioActual]
    );

    return $calendarioBase ? (int) $calendarioBase['id_calendario'] : null;
}

function eliminar_calendario_si_esta_vacio(int $idCalendario): void
{
    $hayBloques = db_count('SELECT COUNT(*) AS total FROM bloques_horarios WHERE id_calendario = ?', [$idCalendario]);
    if ($hayBloques > 0) {
        return;
    }

    $hayClases = db_count(
        'SELECT COUNT(*) AS total FROM horario_semanal h
         JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
         WHERE b.id_calendario = ?',
        [$idCalendario]
    );
    if ($hayClases > 0) {
        return;
    }

    $gradoAsignado = db_one(
        'SELECT id_grado FROM grados WHERE id_calendario = ? AND activo = 1 LIMIT 1',
        [$idCalendario]
    );
    if ($gradoAsignado) {
        $idCalendarioBase = calendario_base_si_es_personalizado((string) $gradoAsignado['id_grado'], $idCalendario);
        if ($idCalendarioBase !== null) {
            db_exec('UPDATE grados SET id_calendario = ? WHERE id_grado = ? AND activo = 1', [$idCalendarioBase, $gradoAsignado['id_grado']]);
        }
    }

    db_exec('DELETE FROM calendarios WHERE id_calendario = ?', [$idCalendario]);
}

function restaurar_mapeo_bloques_al_calendario_base(string $idGrado, int $idCalendarioActual, int $idCalendarioBase): void
{
    $mapeo = db_all(
        'SELECT b_actual.id_bloque AS id_bloque_actual, b_base.id_bloque AS id_bloque_base
         FROM bloques_horarios b_actual
         JOIN bloques_horarios b_base
           ON b_base.id_calendario = ?
          AND b_base.tipo = b_actual.tipo
          AND b_base.hora_inicio = b_actual.hora_inicio
          AND b_base.hora_fin = b_actual.hora_fin
         WHERE b_actual.id_calendario = ?
         ORDER BY b_actual.hora_inicio, b_actual.id_bloque',
        [$idCalendarioBase, $idCalendarioActual]
    );

    foreach ($mapeo as $fila) {
        $idBloqueActual = (int) $fila['id_bloque_actual'];
        $idBloqueBase = (int) $fila['id_bloque_base'];

        db_exec(
            'UPDATE horario_semanal SET id_bloque = ? WHERE id_grado = ? AND id_bloque = ?',
            [$idBloqueBase, $idGrado, $idBloqueActual]
        );
        db_exec(
            'UPDATE novedades_cambios_horario SET id_bloque = ? WHERE id_grado = ? AND id_bloque = ?',
            [$idBloqueBase, $idGrado, $idBloqueActual]
        );
        db_exec(
            'UPDATE huecos_horario_temporales SET id_bloque = ? WHERE id_grado = ? AND id_bloque = ?',
            [$idBloqueBase, $idGrado, $idBloqueActual]
        );
    }
}

function fecha_proxima_dia(int $dia): string
{
    $hoy = new DateTimeImmutable('today');
    $diasHastaLaFecha = ($dia - (int) $hoy->format('N') + 7) % 7;
    return $hoy->modify('+' . $diasHastaLaFecha . ' days')->format('Y-m-d');
}
function reprogramar_clases_siguientes(string $grado, int $dia, int $bloque, ?int $ignorarId = null, bool $reprogramarSiguientes = false): void
{
    $grado = (string) $grado;
    $dia = (int) $dia;
    $bloque = (int) $bloque;
    $ignorarId = $ignorarId !== null ? (int) $ignorarId : null;

    $bloqueActual = db_one(
        'SELECT numero_bloque
         FROM bloques_horarios
         WHERE id_bloque = ? AND tipo = "CLASE"',
        [$bloque]
    );
    if (!$bloqueActual) {
        return;
    }
    $marcaCancelacionPendiente = 'Cancelación consecutiva pendiente de decisión';

    $clasesSiguientes = db_all(
        'SELECT h.id_horario, b.numero_bloque, h.estado
         FROM horario_semanal h
         JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
         WHERE h.id_grado = ? AND h.id_dia = ? AND b.tipo = "CLASE" AND b.numero_bloque > ?
         ORDER BY b.hora_inicio, b.numero_bloque',
        [$grado, $dia, (int) $bloqueActual['numero_bloque']]
    );

    foreach ($clasesSiguientes as $clase) {
        if ((string) $clase['estado'] !== 'CANCELADA') {
            break;
        }
        if ($ignorarId !== null && (int) $clase['id_horario'] === $ignorarId) {
            continue;
        }
        if (!$reprogramarSiguientes) {
            db_exec(
                "UPDATE horario_semanal
                 SET observacion = CASE
                     WHEN COALESCE(observacion, '') LIKE ? THEN observacion
                     WHEN COALESCE(observacion, '') = '' THEN ?
                     ELSE CONCAT(observacion, ' | ', ?)
                 END
                 WHERE id_horario = ?",
                ['%' . $marcaCancelacionPendiente . '%', $marcaCancelacionPendiente, $marcaCancelacionPendiente, (int) $clase['id_horario']]
            );
            continue;
        }
        db_exec(
            "UPDATE horario_semanal
             SET estado = ?, observacion = NULLIF(TRIM(BOTH ' |' FROM REPLACE(COALESCE(observacion, ''), ?, '')), '')
             WHERE id_horario = ?",
            ['PROGRAMADA', $marcaCancelacionPendiente, (int) $clase['id_horario']]
        );
        db_exec(
            "UPDATE novedades_cambios_horario SET estado = 'CANCELADA'
             WHERE id_horario = ? AND estado = 'ACTIVA' AND observacion LIKE 'Clase cancelada%'",
            [(int) $clase['id_horario']]
        );
        asegurar_tabla_horario_original_novedad();
        db_exec(
            "UPDATE horario_original_novedad
             SET estado = ?, observacion = NULLIF(TRIM(BOTH ' |' FROM REPLACE(COALESCE(observacion, ''), ?, '')), '')
             WHERE id_horario = ?",
            ['PROGRAMADA', $marcaCancelacionPendiente, (int) $clase['id_horario']]
        );
        registrar_cambio_horario((int) $clase['id_horario'], 'REPROGRAMACION', 'Clase reprogramada automáticamente al reactivar la secuencia del día.');
    }
}

function validate_horario(?int $ignoreId = null, bool $validarDisponibilidadPorFecha = false, ?int $ignoreAdditionId = null, bool $permitirBloqueDescanso = false): array
{
    $grado = post('id_grado', '0');
    $fecha = post('fecha');
    $fechaValida = $fecha ? DateTimeImmutable::createFromFormat('!Y-m-d', $fecha) : false;
    $dia = $fechaValida && $fechaValida->format('Y-m-d') === $fecha ? (int) $fechaValida->format('N') : 0;
    if ($fechaValida && $fechaValida < new DateTimeImmutable('today')) {
        throw new RuntimeException('La fecha debe ser igual o posterior a hoy.');
    }
    $bloque = (int) post('id_bloque', '0');
    $materia = (int) post('id_materia', '0');
    $profesor = post('id_profesor');
    $salon = post('id_salon');
    $estado = post('estado', 'PROGRAMADA');
    $observacion = post('observacion');

    if (!in_array($estado, ['PROGRAMADA', 'DESCANSO', 'CANCELADA'], true)) {
        throw new RuntimeException('El estado seleccionado no es válido.');
    }
    if (!$grado || !$dia || !$bloque) {
        throw new RuntimeException('Debe seleccionar grado, fecha y bloque.');
    }
    if ($estado === 'DESCANSO') {
        if (post('id_materia') || post('id_profesor') || post('id_salon')) {
            throw new RuntimeException('Para asignar materia, profesor o salón, seleccione el estado PROGRAMADA.');
        }
        if ($ignoreId === null) {
            throw new RuntimeException('Para colocar una clase, seleccione el estado PROGRAMADA.');
        }
        $claseExistente = db_one(
            'SELECT id_materia, id_profesor, id_salon FROM horario_semanal WHERE id_horario = ?',
            [$ignoreId]
        );
        if (!$claseExistente) {
            throw new RuntimeException('No se encontró la clase que desea dejar libre.');
        }
        $materia = (int) $claseExistente['id_materia'];
        $profesor = $claseExistente['id_profesor'];
        $salon = $claseExistente['id_salon'];
    }
    if (!$materia) {
        throw new RuntimeException('Para colocar una clase, seleccione el estado PROGRAMADA y una materia.');
    }
    $gradoRow = db_one('SELECT * FROM grados WHERE id_grado = ? AND activo = 1', [$grado]);
    if (!$gradoRow) {
        throw new RuntimeException('El grado seleccionado no existe o está desactivado.');
    }
    if (!exists_id('dias_semana', 'id_dia', $dia) || !exists_id('materias', 'id_materia', $materia)) {
        throw new RuntimeException('Hay una selección inválida. Verifique los datos.');
    }
    $bloqueRow = db_one('SELECT * FROM bloques_horarios WHERE id_bloque = ?', [$bloque]);
    if (!$bloqueRow) {
        throw new RuntimeException('El bloque seleccionado no existe.');
    }
    if ((int) $bloqueRow['id_calendario'] !== (int) $gradoRow['id_calendario']) {
        throw new RuntimeException('El bloque no pertenece al calendario de este grado.');
    }
    if ($bloqueRow['tipo'] !== 'CLASE' && !($permitirBloqueDescanso && $bloqueRow['tipo'] === 'DESCANSO')) {
        throw new RuntimeException('Solo se pueden asignar clases en bloques de tipo CLASE.');
    }
    $profesorId = $profesor ? (int) $profesor : null;
    $salonId = $salon ? (int) $salon : null;
    if ($profesorId === null && $ignoreId === null) {
        $profesoresPermanentes = db_all(
            'SELECT mp.id_profesor FROM materia_profesores mp
             JOIN profesores p ON p.id_profesor = mp.id_profesor AND p.activo = 1
             WHERE mp.id_materia = ?
             UNION SELECT sp.id_profesor FROM salon_profesores sp
             JOIN profesores ps ON ps.id_profesor = sp.id_profesor AND ps.activo = 1
             WHERE sp.id_salon = ?',
            [$materia, $salonId ?? 0]
        );
        if (count($profesoresPermanentes) === 1) {
            $profesorId = (int) $profesoresPermanentes[0]['id_profesor'];
        }
    }
    if ($profesorId && !exists_id('profesores', 'id_profesor', $profesorId)) {
        throw new RuntimeException('El profesor seleccionado no existe.');
    }
    if ($profesorId && $ignoreId === null
        && db_count('SELECT COUNT(*) AS total FROM horario_semanal WHERE id_profesor = ? AND id_materia = ?', [$profesorId, $materia]) === 0
        && db_count('SELECT COUNT(*) AS total FROM materia_profesores WHERE id_profesor = ? AND id_materia = ?', [$profesorId, $materia]) === 0
        && (!$salonId || db_count('SELECT COUNT(*) AS total FROM salon_profesores WHERE id_profesor = ? AND id_salon = ?', [$profesorId, $salonId]) === 0)) {
        throw new RuntimeException('La materia seleccionada no está asignada a ese profesor.');
    }
    if ($salonId && !exists_id('salones', 'id_salon', $salonId)) {
        throw new RuntimeException('El salón seleccionado no existe.');
    }
    if ($estado === 'PROGRAMADA') {
        $ocupacionesGrado = ocupaciones_horario(
            $dia,
            $bloqueRow['hora_inicio'],
            $bloqueRow['hora_fin'],
            $validarDisponibilidadPorFecha ? $fecha : null,
            $ignoreId,
            $ignoreAdditionId
        );
        foreach ($ocupacionesGrado as $ocupacion) {
            if ((string) ($ocupacion['id_grado'] ?? '') === $grado) {
                throw new RuntimeException(
                    'El grado ya tiene clase de ' . $ocupacion['materia'] . ' de ' .
                    $ocupacion['hora_inicio'] . ' a ' . $ocupacion['hora_fin'] . ' en ese día.'
                );
            }
        }
        validar_disponibilidad_horario(
            $dia,
            $bloqueRow['hora_inicio'],
            $bloqueRow['hora_fin'],
            $profesorId,
            $salonId,
            $ignoreId,
            $validarDisponibilidadPorFecha ? $fecha : null,
            $ignoreAdditionId
        );
    }

    return [$grado, $dia, $bloque, $materia, $profesorId, $salonId, $estado, $observacion];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action', '');
    $operation = post('operation', '');
    if (in_array($operation, ['cancel', 'delete'], true)) {
        $action = $operation;
    } elseif ($operation === 'cancel_addition') {
        $action = 'cancel_addition';
    }
    if ($action === 'create' && (int) post('id_horario', '0') > 0) {
        $action = 'update';
    }
    $backGrado = post('id_grado') ?: get('grado');
    try {
        if ($action === 'convert_class_slot_to_break') {
            $idGrado = post('id_grado', '');
            $idDia = (int) post('convertir_dia', '0');
            $idBloque = (int) post('convertir_bloque', '0');
            if (post('confirmar_conversion_descanso', '0') !== '1') {
                throw new RuntimeException('Confirma la conversión de esta casilla a descanso.');
            }
            $gradoConvertir = db_one('SELECT id_calendario FROM grados WHERE id_grado = ? AND activo = 1', [$idGrado]);
            $bloqueConvertir = db_one(
                'SELECT id_calendario, tipo, agregado_desde_horario FROM bloques_horarios WHERE id_bloque = ?',
                [$idBloque]
            );
            $respaldoDescanso = db_one(
                "SELECT id_bloque_agregado FROM bloques_descansos_reemplazados
                 WHERE id_bloque_agregado = ? AND accion = 'PARCIAL'",
                [$idBloque]
            );
            if (!$gradoConvertir || !$bloqueConvertir || !$respaldoDescanso
                || (int) $bloqueConvertir['id_calendario'] !== (int) $gradoConvertir['id_calendario']
                || $bloqueConvertir['tipo'] !== 'CLASE'
                || (int) $bloqueConvertir['agregado_desde_horario'] !== 1
                || !exists_id('dias_semana', 'id_dia', $idDia)) {
                throw new RuntimeException('Solo puedes convertir a descanso una casilla de un bloque que dividió un descanso.');
            }
            if (db_count(
                'SELECT COUNT(*) AS total FROM horario_semanal WHERE id_grado = ? AND id_dia = ? AND id_bloque = ?',
                [$idGrado, $idDia, $idBloque]
            ) > 0) {
                throw new RuntimeException('Solo puedes convertir a descanso una casilla vacía.');
            }
            if (db_count(
                "SELECT COUNT(*) AS total FROM novedades_cambios_horario
                 WHERE id_grado = ? AND id_dia = ? AND id_bloque = ? AND estado = 'ACTIVA'",
                [$idGrado, $idDia, $idBloque]
            ) > 0) {
                throw new RuntimeException('No se puede convertir una casilla con novedades activas.');
            }
            db_exec(
                'INSERT INTO descansos_por_dia (id_grado, id_dia, id_bloque) VALUES (?, ?, ?)',
                [$idGrado, $idDia, $idBloque]
            );
            flash_set('success', 'La casilla seleccionada se convirtió en descanso. Los demás días no cambiaron.');
            $backGrado = $idGrado;
        } elseif ($action === 'restore_split_break') {
            $idGrado = post('id_grado', '');
            $idBloque = (int) post('id_bloque', '0');
            if (post('confirmar_conversion_descanso', '0') !== '1') {
                throw new RuntimeException('Confirma la restauración del descanso.');
            }
            db()->beginTransaction();
            try {
                $restauracion = db_one(
                    'SELECT b.id_calendario, b.tipo, b.agregado_desde_horario,
                            r.hora_inicio, r.hora_fin, r.id_bloque_descanso_restante
                     FROM bloques_horarios b
                     JOIN bloques_descansos_reemplazados r ON r.id_bloque_agregado = b.id_bloque
                     WHERE b.id_bloque = ? AND r.accion = \'PARCIAL\' FOR UPDATE',
                    [$idBloque]
                );
                $gradoRestauracion = db_one('SELECT id_calendario FROM grados WHERE id_grado = ? AND activo = 1', [$idGrado]);
                if (!$restauracion || !$gradoRestauracion
                    || $restauracion['tipo'] !== 'CLASE'
                    || (int) $restauracion['agregado_desde_horario'] !== 1
                    || (int) $restauracion['id_calendario'] !== (int) $gradoRestauracion['id_calendario']) {
                    throw new RuntimeException('Este bloque no tiene un descanso parcial para restaurar.');
                }
                if (db_count('SELECT COUNT(*) AS total FROM grados WHERE id_calendario = ? AND activo = 1', [(int) $gradoRestauracion['id_calendario']]) !== 1) {
                    throw new RuntimeException('Solo puedes restaurar el descanso de un calendario exclusivo.');
                }
                if (db_count('SELECT COUNT(*) AS total FROM horario_semanal WHERE id_bloque = ?', [$idBloque]) > 0) {
                    throw new RuntimeException('Quita primero las clases asignadas a este bloque.');
                }
                $novedadesActivas = db_count(
                    "SELECT COUNT(*) AS total FROM novedades_cambios_horario n
                     LEFT JOIN horario_semanal h ON h.id_horario = n.id_horario
                     WHERE n.estado = 'ACTIVA' AND (
                         (n.id_grado = ? AND n.id_bloque = ?)
                         OR (h.id_grado = ? AND h.id_bloque = ?)
                     )",
                    [$idGrado, $idBloque, $idGrado, $idBloque]
                );
                if ($novedadesActivas > 0) {
                    throw new RuntimeException('No se puede restaurar el descanso mientras existan novedades activas en este bloque.');
                }
                $descansoRestante = db_one(
                    "SELECT id_bloque FROM bloques_horarios
                     WHERE id_bloque = ? AND id_calendario = ? AND tipo = 'DESCANSO' FOR UPDATE",
                    [(int) $restauracion['id_bloque_descanso_restante'], (int) $restauracion['id_calendario']]
                );
                if (!$descansoRestante) {
                    throw new RuntimeException('No se encontró el descanso que debe unirse con este bloque.');
                }
                db_exec(
                    'UPDATE bloques_horarios SET hora_inicio = ? WHERE id_bloque = ?',
                    [$restauracion['hora_inicio'], (int) $descansoRestante['id_bloque']]
                );
                db_exec('DELETE FROM huecos_horario_temporales WHERE id_grado = ? AND id_bloque = ?', [$idGrado, $idBloque]);
                db_exec('DELETE FROM bloques_horarios WHERE id_bloque = ?', [$idBloque]);
                reordenar_bloques_clase_calendario((int) $restauracion['id_calendario']);
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
            flash_set('success', 'Se restauró el descanso completo y se quitó el bloque vacío.');
            $backGrado = $idGrado;
        } elseif ($action === 'delete_calendar_block') {
            $idGrado = post('id_grado', '');
            $idBloque = (int) post('id_bloque', '0');
            db()->beginTransaction();
            try {
                $bloqueEliminar = db_one(
                    'SELECT id_calendario, tipo FROM bloques_horarios
                     WHERE id_bloque = ? AND agregado_desde_horario = 1 FOR UPDATE',
                    [$idBloque]
                );
                $gradoEliminar = db_one('SELECT id_calendario FROM grados WHERE id_grado = ? AND activo = 1', [$idGrado]);
                if (!$bloqueEliminar || !$gradoEliminar
                    || (int) $bloqueEliminar['id_calendario'] !== (int) $gradoEliminar['id_calendario']) {
                    throw new RuntimeException('Solo puedes quitar bloques agregados desde este horario.');
                }
                if (db_count('SELECT COUNT(*) AS total FROM grados WHERE id_calendario = ? AND activo = 1', [(int) $gradoEliminar['id_calendario']]) > 1) {
                    throw new RuntimeException('Solo puedes quitar un bloque que pertenezca al calendario exclusivo de este grado.');
                }
                $bloqueEliminar = db_one(
                    'SELECT id_bloque, id_calendario, tipo, hora_inicio, hora_fin FROM bloques_horarios
                     WHERE id_bloque = ? AND agregado_desde_horario = 1 FOR UPDATE',
                    [$idBloque]
                );
                $descansoReemplazado = db_one(
                    'SELECT numero_bloque, hora_inicio, hora_fin, descripcion, agregado_desde_horario,
                            hora_inicio_clase_original, accion, id_bloque_descanso_restante
                     FROM bloques_descansos_reemplazados WHERE id_bloque_agregado = ? FOR UPDATE',
                    [$idBloque]
                );
                $bloqueSiguiente = $bloqueEliminar['tipo'] === 'CLASE' ? db_one(
                    "SELECT id_bloque, hora_inicio, hora_fin FROM bloques_horarios
                     WHERE id_calendario = ? AND tipo = 'CLASE' AND hora_inicio = ? AND id_bloque <> ?
                     ORDER BY hora_fin LIMIT 1 FOR UPDATE",
                    [(int) $gradoEliminar['id_calendario'], $bloqueEliminar['hora_fin'], $idBloque]
                ) : null;
                $bloqueAnterior = $bloqueEliminar['tipo'] === 'CLASE' ? db_one(
                    "SELECT id_bloque, hora_inicio, hora_fin FROM bloques_horarios
                     WHERE id_calendario = ? AND tipo = 'CLASE' AND hora_fin = ? AND id_bloque <> ?
                     ORDER BY hora_inicio DESC LIMIT 1 FOR UPDATE",
                    [(int) $gradoEliminar['id_calendario'], $bloqueEliminar['hora_inicio'], $idBloque]
                ) : null;
                $clasesBloque = db_all(
                    'SELECT id_dia, id_materia, id_profesor, id_salon, estado, observacion
                     FROM horario_semanal WHERE id_grado = ? AND id_bloque = ? ORDER BY id_dia',
                    [$idGrado, $idBloque]
                );
                $clasesSiguiente = $bloqueSiguiente ? db_all(
                    'SELECT id_dia, id_materia, id_profesor, id_salon, estado, observacion
                     FROM horario_semanal WHERE id_grado = ? AND id_bloque = ? ORDER BY id_dia',
                    [$idGrado, (int) $bloqueSiguiente['id_bloque']]
                ) : [];
                $clasesAnteriores = $bloqueAnterior ? db_all(
                    'SELECT id_dia, id_materia, id_profesor, id_salon, estado, observacion
                     FROM horario_semanal WHERE id_grado = ? AND id_bloque = ? ORDER BY id_dia',
                    [$idGrado, (int) $bloqueAnterior['id_bloque']]
                ) : [];
                $puedeRestaurarTramo = $bloqueSiguiente !== null && count($clasesBloque) === count($clasesSiguiente);
                if ($puedeRestaurarTramo) {
                    $clasesSiguientePorDia = [];
                    foreach ($clasesSiguiente as $claseSiguiente) {
                        $clasesSiguientePorDia[(int) $claseSiguiente['id_dia']] = $claseSiguiente;
                    }
                    foreach ($clasesBloque as $claseBloque) {
                        $claseSiguiente = $clasesSiguientePorDia[(int) $claseBloque['id_dia']] ?? null;
                        if (!$claseSiguiente) {
                            $puedeRestaurarTramo = false;
                            break;
                        }
                        foreach (['id_materia', 'id_profesor', 'id_salon', 'estado', 'observacion'] as $campo) {
                            if ($claseBloque[$campo] !== $claseSiguiente[$campo]) {
                                $puedeRestaurarTramo = false;
                                break 2;
                            }
                        }
                    }
                }
                $puedeRestaurarTramoAnterior = $bloqueAnterior !== null
                    && $clasesBloque !== []
                    && count($clasesBloque) === count($clasesAnteriores);
                if ($puedeRestaurarTramoAnterior) {
                    $clasesAnterioresPorDia = [];
                    foreach ($clasesAnteriores as $claseAnterior) {
                        $clasesAnterioresPorDia[(int) $claseAnterior['id_dia']] = $claseAnterior;
                    }
                    foreach ($clasesBloque as $claseBloque) {
                        $claseAnterior = $clasesAnterioresPorDia[(int) $claseBloque['id_dia']] ?? null;
                        if (!$claseAnterior) {
                            $puedeRestaurarTramoAnterior = false;
                            break;
                        }
                        foreach (['id_materia', 'id_profesor', 'id_salon', 'estado', 'observacion'] as $campo) {
                            if ($claseBloque[$campo] !== $claseAnterior[$campo]) {
                                $puedeRestaurarTramoAnterior = false;
                                break 2;
                            }
                        }
                    }
                }
                $novedadesActivas = db_count(
                    "SELECT COUNT(*) AS total FROM novedades_cambios_horario n
                     LEFT JOIN horario_semanal h ON h.id_horario = n.id_horario
                     WHERE n.estado = 'ACTIVA' AND (
                         (n.id_grado = ? AND n.id_bloque IN (?, ?, ?))
                         OR (h.id_grado = ? AND h.id_bloque IN (?, ?, ?))
                     )",
                    [
                        $idGrado, $idBloque, (int) ($bloqueSiguiente['id_bloque'] ?? 0), (int) ($bloqueAnterior['id_bloque'] ?? 0),
                        $idGrado, $idBloque, (int) ($bloqueSiguiente['id_bloque'] ?? 0), (int) ($bloqueAnterior['id_bloque'] ?? 0),
                    ]
                );
                $horariosOriginales = $bloqueSiguiente ? db_count(
                    'SELECT COUNT(*) AS total FROM horario_original_novedad o
                     JOIN horario_semanal h ON h.id_horario = o.id_horario
                     WHERE h.id_grado = ? AND h.id_bloque IN (?, ?, ?)',
                    [$idGrado, $idBloque, (int) $bloqueSiguiente['id_bloque'], (int) ($bloqueAnterior['id_bloque'] ?? 0)]
                ) : 0;
                if ($clasesBloque && !$puedeRestaurarTramo && !$puedeRestaurarTramoAnterior) {
                    throw new RuntimeException('No se puede quitar este bloque porque contiene clases distintas del tramo siguiente.');
                }
                $descansoReemplazadoSinClases = $descansoReemplazado && !$clasesBloque;
                if ($bloqueSiguiente && !$puedeRestaurarTramo && !$puedeRestaurarTramoAnterior && $clasesSiguiente && !$descansoReemplazadoSinClases) {
                    throw new RuntimeException('No se puede quitar este bloque porque las clases de ambos tramos ya no coinciden.');
                }
                $esTramoDividido = $puedeRestaurarTramo || $puedeRestaurarTramoAnterior;
                if ($descansoReemplazado && !$esTramoDividido && !$descansoReemplazadoSinClases) {
                    throw new RuntimeException('No se puede quitar este bloque porque la clase continua ya fue modificada.');
                }
                if ($puedeRestaurarTramo) {
                    db_exec(
                        'UPDATE bloques_horarios SET hora_inicio = ? WHERE id_bloque = ?',
                        [$descansoReemplazado['hora_inicio_clase_original'] ?? $bloqueEliminar['hora_inicio'], (int) $bloqueSiguiente['id_bloque']]
                    );
                    db_exec('DELETE FROM horario_semanal WHERE id_grado = ? AND id_bloque = ?', [$idGrado, $idBloque]);
                } elseif ($puedeRestaurarTramoAnterior) {
                    db_exec(
                        'UPDATE bloques_horarios SET hora_fin = ? WHERE id_bloque = ?',
                        [$bloqueEliminar['hora_fin'], (int) $bloqueAnterior['id_bloque']]
                    );
                    db_exec('DELETE FROM horario_semanal WHERE id_grado = ? AND id_bloque = ?', [$idGrado, $idBloque]);
                } elseif ($descansoReemplazadoSinClases && $bloqueSiguiente) {
                    db_exec(
                        'UPDATE bloques_horarios SET hora_inicio = ? WHERE id_bloque = ?',
                        [$descansoReemplazado['hora_inicio_clase_original'], (int) $bloqueSiguiente['id_bloque']]
                    );
                }
                if ($descansoReemplazado && $descansoReemplazado['accion'] === 'PARCIAL') {
                    $restauracionDescanso = db_exec(
                        'UPDATE bloques_horarios SET hora_inicio = ? WHERE id_bloque = ? AND tipo = \'DESCANSO\'',
                        [$descansoReemplazado['hora_inicio'], (int) $descansoReemplazado['id_bloque_descanso_restante']]
                    );
                    if (!$restauracionDescanso) {
                        throw new RuntimeException('No se pudo restaurar el descanso original.');
                    }
                } elseif ($descansoReemplazado) {
                    db_exec(
                        'INSERT INTO bloques_horarios
                            (id_calendario, numero_bloque, tipo, hora_inicio, hora_fin, descripcion, agregado_desde_horario)
                         VALUES (?, ?, \'DESCANSO\', ?, ?, ?, ?)',
                        [
                            (int) $bloqueEliminar['id_calendario'], $descansoReemplazado['numero_bloque'],
                            $descansoReemplazado['hora_inicio'], $descansoReemplazado['hora_fin'],
                            $descansoReemplazado['descripcion'], $descansoReemplazado['agregado_desde_horario'],
                        ]
                    );
                }
                db_exec(
                    "DELETE FROM novedades_cambios_horario
                     WHERE tipo_cambio = 'BLOQUE' AND id_grado = ? AND id_bloque = ?",
                    [$idGrado, $idBloque]
                );
                db_exec('DELETE FROM huecos_horario_temporales WHERE id_grado = ? AND id_bloque = ?', [$idGrado, $idBloque]);
                db_exec('DELETE FROM bloques_horarios WHERE id_bloque = ? AND agregado_desde_horario = 1', [$idBloque]);
                $idCalendarioActual = (int) db_one('SELECT id_calendario FROM grados WHERE id_grado = ? AND activo = 1', [$idGrado])['id_calendario'];
                $idCalendarioBase = calendario_base_si_es_personalizado($idGrado, $idCalendarioActual);
                $hayBloquesAgregados = db_count(
                    'SELECT COUNT(*) AS total FROM bloques_horarios WHERE id_calendario = ? AND agregado_desde_horario = 1',
                    [$idCalendarioActual]
                );
                if ($idCalendarioBase !== null && $hayBloquesAgregados === 0) {
                    restaurar_mapeo_bloques_al_calendario_base($idGrado, (int) $bloqueEliminar['id_calendario'], $idCalendarioBase);
                    db_exec('UPDATE grados SET id_calendario = ? WHERE id_grado = ? AND activo = 1', [$idCalendarioBase, $idGrado]);
                }
                eliminar_calendario_si_esta_vacio((int) $bloqueEliminar['id_calendario']);
                reordenar_bloques_clase_calendario((int) $bloqueEliminar['id_calendario']);
                db()->commit();
                eliminar_calendario_personalizado_si_es_redundante($idGrado, (int) $bloqueEliminar['id_calendario']);
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
            flash_set('success', 'Se quitó el bloque agregado y se actualizaron los números del calendario.' . ($descansoReemplazado
                ? ' Se restauraron el descanso y la hora original de la clase.'
                : ($esTramoDividido ? ' El tramo continuo volvió a su duración anterior.' : '')));
            $backGrado = $idGrado;
        } elseif ($action === 'compact_calendar_block') {
            $idGrado = post('id_grado', '');
            $idBloque = (int) post('id_bloque', '0');
            $idBloqueEliminar = (int) post('id_bloque_eliminar', '0');
            $idDiaCompactar = (int) post('id_dia', '0');
            if (!$idGrado || !$idBloque || !$idBloqueEliminar || !$idDiaCompactar) {
                throw new RuntimeException('Debe indicar el bloque y el grado para compactar.');
            }
            $calendarioGrado = db_one(
                'SELECT id_calendario FROM grados WHERE id_grado = ? AND activo = 1',
                [$idGrado]
            );
            if (!$calendarioGrado) {
                throw new RuntimeException('No se encontró el grado para compactar.');
            }
            $bloquesCalendario = db_all(
                'SELECT id_bloque, tipo FROM bloques_horarios
                 WHERE id_calendario = ? ORDER BY hora_inicio, numero_bloque',
                [(int) $calendarioGrado['id_calendario']]
            );
            $bloquesCalendario = array_values(array_filter(
                $bloquesCalendario,
                static fn(array $bloque): bool => !in_array((int) $bloque['id_bloque'], [24, 25], true)
            ));
            $siguienteAlBloque = null;
            $numeroBloqueBase = null;
            foreach ($bloquesCalendario as $indice => $bloqueCalendario) {
                if ((int) $bloqueCalendario['id_bloque'] === $idBloque) {
                    $numeroBloqueBase = (int) $bloqueCalendario['numero_bloque'];
                    $siguienteAlBloque = $bloquesCalendario[$indice + 1] ?? null;
                    break;
                }
            }
            if (in_array($numeroBloqueBase, [1, 2, 3], true)) {
                throw new RuntimeException('No se puede quitar la clase desde los bloques 1, 2 o 3.');
            }
            if (!$siguienteAlBloque
                || (int) $siguienteAlBloque['id_bloque'] !== $idBloqueEliminar
                || $siguienteAlBloque['tipo'] !== 'CLASE') {
                throw new RuntimeException('Solo se pueden compactar bloques consecutivos.');
            }
            $horarioAEliminar = db_one(
                'SELECT siguiente.id_horario
                 FROM horario_semanal actual
                 JOIN horario_semanal siguiente
                   ON siguiente.id_grado = actual.id_grado
                  AND siguiente.id_dia = actual.id_dia
                  AND siguiente.id_bloque = ?
                  AND siguiente.id_materia = actual.id_materia
                  AND siguiente.id_profesor <=> actual.id_profesor
                  AND siguiente.id_salon <=> actual.id_salon
                  AND siguiente.estado = "PROGRAMADA"
                 WHERE actual.id_grado = ? AND actual.id_dia = ? AND actual.id_bloque = ? AND actual.estado = "PROGRAMADA"
                 LIMIT 1',
                [$idBloqueEliminar, $idGrado, $idDiaCompactar, $idBloque]
            );
            $horarioAEliminar = $horarioAEliminar ? (int) $horarioAEliminar['id_horario'] : null;
            if ($horarioAEliminar === null) {
                throw new RuntimeException('Los bloques no tienen la misma materia, profesor y salón en un mismo día.');
            }
            db()->beginTransaction();
            try {
                db_exec('DELETE FROM horario_semanal WHERE id_horario = ?', [$horarioAEliminar]);
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
            flash_set('success', 'Se eliminó el bloque seleccionado de esta secuencia.');
            $backGrado = $idGrado;
        } elseif ($action === 'create_calendar_block') {
            $idGrado = post('id_grado', '');
            $idBloqueDespues = (int) post('id_bloque_despues', '0');
            $tipoNuevoBloque = post('tipo', 'CLASE');
            $horaInicio = normalizar_hora_bloque(post('hora_inicio', ''));
            $horaFin = normalizar_hora_bloque(post('hora_fin', ''));
            $descripcion = validar_descripcion_bloque(post('descripcion', ''));
            if (!in_array($tipoNuevoBloque, ['CLASE', 'DESCANSO'], true)) {
                throw new RuntimeException('Seleccione si el nuevo bloque será clase o descanso.');
            }
            $gradoBloque = db_one('SELECT id_calendario FROM grados WHERE id_grado = ? AND activo = 1', [$idGrado]);
            if (!$gradoBloque) {
                throw new RuntimeException('No se encontró el grado para agregar el bloque.');
            }
            if ($horaInicio === null || $horaFin === null || $horaInicio >= $horaFin) {
                throw new RuntimeException('Indique un horario válido para el nuevo bloque.');
            }
            $bloqueDespues = db_one(
                'SELECT id_calendario, numero_bloque, tipo, hora_inicio, hora_fin FROM bloques_horarios WHERE id_bloque = ?',
                [$idBloqueDespues]
            );
            if (!$bloqueDespues || (int) $bloqueDespues['id_calendario'] !== (int) $gradoBloque['id_calendario']) {
                throw new RuntimeException('No se encontró el bloque después del cual agregar el nuevo horario.');
            }
            $descansoSiguienteAlBloque = db_one(
                "SELECT id_bloque, hora_inicio, hora_fin FROM bloques_horarios
                 WHERE id_calendario = ? AND tipo = 'DESCANSO' AND hora_inicio = ?",
                [(int) $gradoBloque['id_calendario'], $bloqueDespues['hora_fin']]
            );
            $permiteEditarDescanso = $bloqueDespues['tipo'] === 'DESCANSO' || $descansoSiguienteAlBloque !== null;
            if ($tipoNuevoBloque === 'DESCANSO' && !$permiteEditarDescanso) {
                throw new RuntimeException('Solo puedes convertir en descanso un espacio abierto desde un bloque de descanso.');
            }
            $idCalendarioActivo = (int) $gradoBloque['id_calendario'];
            $bloquesSolapados = db_all(
                'SELECT id_bloque, numero_bloque, tipo, hora_inicio, hora_fin, descripcion, agregado_desde_horario FROM bloques_horarios
                 WHERE id_calendario = ? AND id_bloque NOT IN (24, 25)
                   AND hora_inicio < ? AND hora_fin > ?
                 ORDER BY hora_inicio, hora_fin, id_bloque',
                [$idCalendarioActivo, $horaFin, $horaInicio]
            );
            $bloquePartir = null;
            $datosBloquePartir = null;
            $bloqueClaseFinalPartir = null;
            $datosBloqueClaseFinalPartir = null;
            $bloqueDescansoReemplazar = null;
            $bloqueDescansoEsParcial = false;
            $horaInicioClaseOriginal = null;
            $horaFinClaseOriginal = null;
            $inicioCola = $horaInicio;
            $puntosSegmentacion = [$horaInicio, $horaFin];
            foreach ($bloquesSolapados as $bloqueSolapado) {
                foreach (['hora_inicio', 'hora_fin'] as $campoHora) {
                    $punto = format_time($bloqueSolapado[$campoHora]);
                    if ($punto > $horaInicio && $punto < $horaFin) {
                        $puntosSegmentacion[] = $punto;
                    }
                }
            }
            $puntosSegmentacion = array_values(array_unique($puntosSegmentacion));
            sort($puntosSegmentacion);
            if ($tipoNuevoBloque === 'DESCANSO') {
                if (count($bloquesSolapados) !== 1
                    || $bloquesSolapados[0]['tipo'] !== 'DESCANSO'
                    || format_time($bloquesSolapados[0]['hora_inicio']) !== $horaInicio
                    || $horaFin > format_time($bloquesSolapados[0]['hora_fin'])
                    || !$permiteEditarDescanso) {
                    throw new RuntimeException('No se puede agregar ese bloque porque se cruza con otro bloque.');
                }
                $bloqueDescansoReemplazar = $bloquesSolapados[0];
                $bloqueDescansoEsParcial = $horaFin < format_time($bloqueDescansoReemplazar['hora_fin']);
                $inicioCola = $horaFin;
            } elseif ($bloquesSolapados) {
                $cursorHorario = $horaInicio;
                foreach ($bloquesSolapados as $indiceSolapado => $bloqueSolapado) {
                    $inicioSolapado = format_time($bloqueSolapado['hora_inicio']);
                    $finSolapado = format_time($bloqueSolapado['hora_fin']);
                    if ($inicioSolapado !== $cursorHorario) {
                        throw new RuntimeException('No se puede agregar ese bloque porque se cruza con otro bloque.');
                    }
                    if ($bloqueSolapado['tipo'] === 'DESCANSO') {
                        $descansoEsSiguienteAlBloque = (int) $bloqueSolapado['id_bloque'] === $idBloqueDespues
                            && $bloqueDespues['tipo'] === 'DESCANSO';
                        $descansoVaDespuesDelBloque = $bloqueDespues['tipo'] === 'CLASE'
                            && format_time($bloqueDespues['hora_fin']) === $inicioSolapado;
                        if ($indiceSolapado !== 0
                            || $inicioSolapado !== $horaInicio
                            || (!$descansoEsSiguienteAlBloque && !$descansoVaDespuesDelBloque)) {
                            throw new RuntimeException('No se puede agregar ese bloque porque se cruza con otro bloque.');
                        }
                        $bloqueDescansoReemplazar = $bloqueSolapado;
                        if ($horaFin < $finSolapado) {
                            $bloqueDescansoEsParcial = true;
                            $cursorHorario = $horaFin;
                            break;
                        }
                        $cursorHorario = $finSolapado;
                        continue;
                    }
                    if ($bloqueSolapado['tipo'] !== 'CLASE') {
                        throw new RuntimeException('No se puede agregar ese bloque porque se cruza con otro bloque.');
                    }
                    if ($finSolapado > $horaFin) {
                        if ($indiceSolapado === 0 && $inicioSolapado === $horaInicio) {
                            $bloquePartir = (int) $bloqueSolapado['id_bloque'];
                            $datosBloquePartir = $bloqueSolapado;
                        } else {
                            $bloqueClaseFinalPartir = (int) $bloqueSolapado['id_bloque'];
                            $datosBloqueClaseFinalPartir = $bloqueSolapado;
                        }
                        $horaFinClaseOriginal = $finSolapado;
                        $cursorHorario = $horaFin;
                        break;
                    }
                    $cursorHorario = $finSolapado;
                }
                if ($bloqueDescansoReemplazar && !$bloquePartir && !$bloqueClaseFinalPartir) {
                    $primeraClaseDespuesDescanso = $bloquesSolapados[1] ?? null;
                    if ($primeraClaseDespuesDescanso && $primeraClaseDespuesDescanso['tipo'] === 'CLASE') {
                        $horaInicioClaseOriginal = $primeraClaseDespuesDescanso['hora_inicio'];
                    }
                }
                $inicioCola = $cursorHorario;
            }
            if ($bloqueDescansoEsParcial && $bloqueDescansoReemplazar !== null) {
                $puntosSegmentacion[] = format_time($bloqueDescansoReemplazar['hora_fin']);
                $puntosSegmentacion = array_values(array_unique($puntosSegmentacion));
                sort($puntosSegmentacion);
            }
            $requiereConfirmacionReparticion = count($puntosSegmentacion) > 2
                || count($bloquesSolapados) > 1
                || $bloquePartir !== null
                || $bloqueClaseFinalPartir !== null
                || $bloqueDescansoEsParcial
                || $inicioCola < $horaFin;
            if ($tipoNuevoBloque === 'CLASE' && $requiereConfirmacionReparticion) {
                $primerBloqueClase = null;
                foreach ($bloquesSolapados as $bloqueSolapado) {
                    if ($bloqueSolapado['tipo'] === 'CLASE') {
                        $primerBloqueClase = $bloqueSolapado;
                        break;
                    }
                }
                if ($primerBloqueClase) {
                    $diasConClase = db_count(
                        "SELECT COUNT(DISTINCT h.id_dia) AS total FROM horario_semanal h
                         JOIN dias_semana d ON d.id_dia = h.id_dia
                         WHERE h.id_grado = ? AND h.id_bloque = ? AND h.estado = 'PROGRAMADA'
                           AND d.orden_dia BETWEEN 1 AND 5",
                        [$idGrado, (int) $primerBloqueClase['id_bloque']]
                    );
                    $diasLaborales = db_count('SELECT COUNT(*) AS total FROM dias_semana WHERE orden_dia BETWEEN 1 AND 5');
                    if ($diasLaborales > 0 && $diasConClase >= $diasLaborales) {
                        throw new RuntimeException('No se puede distribuir el horario porque la siguiente clase ocupa ese bloque todos los días.');
                    }
                }
            }
            if ($requiereConfirmacionReparticion && post('confirmar_reparticion', '0') !== '1') {
                throw new RuntimeException('Confirma la distribución del horario antes de continuar.');
            }
            $referenciasBloquesOcultos = db_count(
                'SELECT COUNT(*) AS total FROM horario_semanal WHERE id_grado = ? AND id_bloque IN (24, 25)',
                [$idGrado]
            ) + db_count(
                'SELECT COUNT(*) AS total FROM horario_original_novedad o
                 JOIN horario_semanal h ON h.id_horario = o.id_horario
                 WHERE h.id_grado = ? AND o.id_bloque IN (24, 25)',
                [$idGrado]
            ) + db_count(
                "SELECT COUNT(*) AS total FROM novedades_cambios_horario
                 WHERE id_grado = ? AND id_bloque IN (24, 25) AND estado = 'ACTIVA'",
                [$idGrado]
            ) + db_count(
                'SELECT COUNT(*) AS total FROM huecos_horario_temporales WHERE id_grado = ? AND id_bloque IN (24, 25)',
                [$idGrado]
            );
            if ($referenciasBloquesOcultos > 0) {
                throw new RuntimeException('No se puede individualizar el calendario mientras existan clases o novedades en bloques antiguos ocultos.');
            }
            $horaMinima = format_time($bloqueDespues['tipo'] === 'DESCANSO'
                ? $bloqueDespues['hora_inicio']
                : $bloqueDespues['hora_fin']);
            if ($horaInicio < $horaMinima) {
                throw new RuntimeException('El nuevo bloque debe iniciar a las ' . $horaMinima . ' o después del bloque seleccionado.');
            }
            $diaDisponible = db_one(
                "SELECT d.id_dia FROM dias_semana d
                 WHERE d.orden_dia BETWEEN 1 AND 5
                   AND NOT EXISTS (
                       SELECT 1 FROM horario_semanal h
                       JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                       WHERE h.id_grado = ? AND h.id_dia = d.id_dia AND h.estado = 'PROGRAMADA'
                         AND b.hora_inicio < ? AND b.hora_fin > ?
                   )
                 ORDER BY d.orden_dia LIMIT 1",
                [$idGrado, $horaFin, $horaInicio]
            );
            if (!$diaDisponible) {
                throw new RuntimeException('Esa hora ya está ocupada todos los días de lunes a viernes para este grado. Elige un intervalo libre al menos un día.');
            }
            if ($tipoNuevoBloque === 'DESCANSO' && db_count(
                "SELECT COUNT(*) AS total FROM horario_semanal h
                 JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                 WHERE h.id_grado = ? AND h.estado = 'PROGRAMADA'
                   AND b.hora_inicio < ? AND b.hora_fin > ?",
                [$idGrado, $horaFin, $horaInicio]
            ) > 0) {
                throw new RuntimeException('No se puede agregar ese bloque porque se cruza con otro bloque.');
            }
            $numeroTemporal = $tipoNuevoBloque === 'CLASE' ? (int) db_one(
                "SELECT COALESCE(MAX(numero_bloque), 0) + 1 AS siguiente
                 FROM bloques_horarios WHERE id_calendario = ? AND tipo = 'CLASE'",
                [$idCalendarioActivo]
            )['siguiente'] : null;
            $bloquesNuevos = [];
            if ($tipoNuevoBloque === 'DESCANSO') {
                $bloquesNuevos[] = [
                    'inicio' => $horaInicio,
                    'fin' => $horaFin,
                    'tipo' => 'DESCANSO',
                    'restaurar_descanso' => $bloqueDescansoReemplazar !== null,
                ];
            } else {
                if ($bloqueDescansoReemplazar !== null) {
                    $bloquesNuevos[] = [
                        'inicio' => format_time($bloqueDescansoReemplazar['hora_inicio']),
                        'fin' => $bloqueDescansoEsParcial ? $horaFin : format_time($bloqueDescansoReemplazar['hora_fin']),
                        'tipo' => 'CLASE',
                        'restaurar_descanso' => true,
                    ];
                }
                if ($bloquePartir !== null) {
                    $bloquesNuevos[] = [
                        'inicio' => $horaInicio,
                        'fin' => $horaFin,
                        'tipo' => 'CLASE',
                        'copiar_desde' => $bloquePartir,
                    ];
                }
                if ($bloqueClaseFinalPartir !== null) {
                    $bloquesNuevos[] = [
                        'inicio' => $horaFin,
                        'fin' => $horaFinClaseOriginal,
                        'tipo' => 'CLASE',
                        'copiar_desde' => $bloqueClaseFinalPartir,
                    ];
                }
                if ($inicioCola < $horaFin) {
                    $bloquesNuevos[] = ['inicio' => $inicioCola, 'fin' => $horaFin, 'tipo' => 'CLASE'];
                }
                if (!$bloquesNuevos) {
                    throw new RuntimeException('Ese horario ya está cubierto por los bloques existentes.');
                }
            }
            db()->beginTransaction();
            try {
                $gradosEnCalendario = (int) db_one(
                    'SELECT COUNT(*) AS total FROM grados WHERE id_calendario = ? AND activo = 1',
                    [$idCalendarioActivo]
                )['total'];
                $calendarioActualEsBase = calendario_base_si_es_personalizado($idGrado, $idCalendarioActivo) === null;
                if ($gradosEnCalendario > 1 || $calendarioActualEsBase) {
                    $calendarioOrigen = db_one('SELECT nombre, descripcion FROM calendarios WHERE id_calendario = ?', [$idCalendarioActivo]);
                    $nombreCalendario = trim((string) ($calendarioOrigen['nombre'] ?? '')) ?: 'Calendario';
                    $descripcionCalendario = trim((string) ($calendarioOrigen['descripcion'] ?? '')) ?: 'Calendario exclusivo';
                    $idCalendarioNuevo = crear_calendario($nombreCalendario . ' - ' . $idGrado, $descripcionCalendario);
                    $bloquesOrigen = db_all(
                        'SELECT id_bloque, numero_bloque, tipo, hora_inicio, hora_fin, descripcion, agregado_desde_horario
                         FROM bloques_horarios WHERE id_calendario = ? AND id_bloque NOT IN (24, 25)
                         ORDER BY hora_inicio, hora_fin, numero_bloque',
                        [$idCalendarioActivo]
                    );
                    $mapaBloques = [];
                    foreach ($bloquesOrigen as $bloqueOrigen) {
                        db_exec(
                            'INSERT INTO bloques_horarios
                                (id_calendario, numero_bloque, tipo, hora_inicio, hora_fin, descripcion, agregado_desde_horario)
                             VALUES (?, ?, ?, ?, ?, ?, ?)',
                            [
                                $idCalendarioNuevo, $bloqueOrigen['numero_bloque'], $bloqueOrigen['tipo'],
                                $bloqueOrigen['hora_inicio'], $bloqueOrigen['hora_fin'], $bloqueOrigen['descripcion'],
                                $bloqueOrigen['agregado_desde_horario'],
                            ]
                        );
                        $mapaBloques[(int) $bloqueOrigen['id_bloque']] = (int) db()->lastInsertId();
                    }
                    if (!isset($mapaBloques[$idBloqueDespues])
                        || ($bloquePartir && !isset($mapaBloques[$bloquePartir]))
                        || ($bloqueClaseFinalPartir && !isset($mapaBloques[$bloqueClaseFinalPartir]))
                        || ($bloqueDescansoReemplazar && !isset($mapaBloques[(int) $bloqueDescansoReemplazar['id_bloque']]))) {
                        throw new RuntimeException('No se pudieron copiar los bloques necesarios al calendario exclusivo.');
                    }
                    foreach ($mapaBloques as $bloqueOrigenId => $bloqueNuevoId) {
                        db_exec(
                            'UPDATE horario_semanal SET id_bloque = ? WHERE id_grado = ? AND id_bloque = ?',
                            [$bloqueNuevoId, $idGrado, $bloqueOrigenId]
                        );
                        db_exec(
                            'UPDATE horario_original_novedad o
                             JOIN horario_semanal h ON h.id_horario = o.id_horario
                             SET o.id_bloque = ?
                             WHERE h.id_grado = ? AND h.id_bloque = ? AND o.id_bloque = ?',
                            [$bloqueNuevoId, $idGrado, $bloqueNuevoId, $bloqueOrigenId]
                        );
                        db_exec(
                            "UPDATE novedades_cambios_horario SET id_bloque = ?
                             WHERE id_grado = ? AND id_bloque = ? AND estado = 'ACTIVA'",
                            [$bloqueNuevoId, $idGrado, $bloqueOrigenId]
                        );
                        db_exec(
                            'UPDATE huecos_horario_temporales SET id_bloque = ? WHERE id_grado = ? AND id_bloque = ?',
                            [$bloqueNuevoId, $idGrado, $bloqueOrigenId]
                        );
                    }
                    db_exec('UPDATE grados SET id_calendario = ? WHERE id_grado = ? AND activo = 1', [$idCalendarioNuevo, $idGrado]);
                    $idCalendarioActivo = $idCalendarioNuevo;
                    $idBloqueDespues = $mapaBloques[$idBloqueDespues];
                    $bloquePartir = $bloquePartir !== null ? $mapaBloques[$bloquePartir] : null;
                    $bloqueClaseFinalPartir = $bloqueClaseFinalPartir !== null ? $mapaBloques[$bloqueClaseFinalPartir] : null;
                    if ($bloqueDescansoReemplazar !== null) {
                        $bloqueDescansoReemplazar['id_bloque'] = $mapaBloques[(int) $bloqueDescansoReemplazar['id_bloque']];
                    }
                    foreach ($bloquesNuevos as &$bloqueNuevoPlan) {
                        if (isset($bloqueNuevoPlan['copiar_desde'])) {
                            $bloqueNuevoPlan['copiar_desde'] = $mapaBloques[(int) $bloqueNuevoPlan['copiar_desde']];
                        }
                    }
                    unset($bloqueNuevoPlan);
                    $bloqueDespues = db_one(
                        'SELECT id_calendario, numero_bloque, hora_fin FROM bloques_horarios WHERE id_bloque = ?',
                        [$idBloqueDespues]
                    );
                }
                if ($bloquePartir !== null) {
                    db_exec(
                        'UPDATE bloques_horarios SET hora_inicio = ? WHERE id_bloque = ? AND id_calendario = ?',
                        [$horaFin, $bloquePartir, $idCalendarioActivo]
                    );
                }
                if ($bloqueClaseFinalPartir !== null) {
                    db_exec(
                        'UPDATE bloques_horarios SET hora_fin = ? WHERE id_bloque = ? AND id_calendario = ?',
                        [$horaFin, $bloqueClaseFinalPartir, $idCalendarioActivo]
                    );
                }
                $idsCreados = [];
                foreach ($bloquesNuevos as $indiceNuevo => $bloqueNuevoPlan) {
                    $numeroNuevoBloque = $bloqueNuevoPlan['tipo'] === 'CLASE' ? $numeroTemporal++ : null;
                    $descripcionNuevo = $descripcion ?: ($bloqueNuevoPlan['tipo'] === 'DESCANSO'
                        ? 'Descanso'
                        : 'Bloque ' . $numeroNuevoBloque);
                    db_exec(
                        'INSERT INTO bloques_horarios
                            (id_calendario, numero_bloque, tipo, hora_inicio, hora_fin, descripcion, agregado_desde_horario)
                         VALUES (?, ?, ?, ?, ?, ?, 1)',
                        [
                            $idCalendarioActivo, $numeroNuevoBloque, $bloqueNuevoPlan['tipo'],
                            $bloqueNuevoPlan['inicio'], $bloqueNuevoPlan['fin'], $descripcionNuevo,
                        ]
                    );
                    $idBloqueCreado = (int) db()->lastInsertId();
                    $idsCreados[] = $idBloqueCreado;
                    if (!empty($bloqueNuevoPlan['restaurar_descanso']) && $bloqueDescansoReemplazar !== null) {
                        db_exec(
                            'INSERT INTO bloques_descansos_reemplazados
                                (id_bloque_agregado, numero_bloque, hora_inicio, hora_fin, descripcion,
                                 agregado_desde_horario, hora_inicio_clase_original, accion, id_bloque_descanso_restante)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                            [
                                $idBloqueCreado, $bloqueDescansoReemplazar['numero_bloque'],
                                $bloqueDescansoReemplazar['hora_inicio'], $bloqueDescansoReemplazar['hora_fin'],
                                $bloqueDescansoReemplazar['descripcion'], $bloqueDescansoReemplazar['agregado_desde_horario'],
                                $horaInicioClaseOriginal ?? $bloqueDescansoReemplazar['hora_fin'],
                                $bloqueDescansoEsParcial ? 'PARCIAL' : 'REEMPLAZADO',
                                $bloqueDescansoEsParcial ? (int) $bloqueDescansoReemplazar['id_bloque'] : null,
                            ]
                        );
                        if ($bloqueDescansoEsParcial) {
                            db_exec(
                                'UPDATE bloques_horarios SET hora_inicio = ? WHERE id_bloque = ? AND id_calendario = ? AND tipo = \'DESCANSO\'',
                                [$horaFin, (int) $bloqueDescansoReemplazar['id_bloque'], $idCalendarioActivo]
                            );
                        } else {
                            db_exec(
                                'DELETE FROM bloques_horarios WHERE id_bloque = ? AND id_calendario = ? AND tipo = \'DESCANSO\'',
                                [(int) $bloqueDescansoReemplazar['id_bloque'], $idCalendarioActivo]
                            );
                        }
                    }
                    if (isset($bloqueNuevoPlan['copiar_desde'])) {
                        db_exec(
                            'INSERT INTO horario_semanal
                                (id_grado, id_dia, id_bloque, id_materia, id_profesor, id_salon, estado, observacion)
                             SELECT id_grado, id_dia, ?, id_materia, id_profesor, id_salon, estado, observacion
                             FROM horario_semanal WHERE id_grado = ? AND id_bloque = ?',
                            [$idBloqueCreado, $idGrado, (int) $bloqueNuevoPlan['copiar_desde']]
                        );
                        asegurar_tabla_cambios_horario();
                        foreach (db_all('SELECT id_horario FROM horario_semanal WHERE id_grado = ? AND id_bloque = ?', [$idGrado, $idBloqueCreado]) as $claseDividida) {
                            registrar_cambio_horario(
                                (int) $claseDividida['id_horario'],
                                'CREACION',
                                'Clase conservada en el nuevo bloque al dividir un tramo continuo.'
                            );
                        }
                    }
                    if ($bloqueNuevoPlan['tipo'] === 'CLASE'
                        && !empty($bloqueNuevoPlan['restaurar_descanso'])
                        && $bloqueDescansoReemplazar !== null) {
                        db_exec(
                            'INSERT IGNORE INTO descansos_por_dia (id_grado, id_dia, id_bloque)
                             SELECT ?, d.id_dia, ?
                             FROM dias_semana d
                             WHERE d.orden_dia BETWEEN 1 AND 5
                               AND NOT EXISTS (
                                   SELECT 1 FROM horario_semanal h
                                   WHERE h.id_grado = ? AND h.id_dia = d.id_dia AND h.id_bloque = ?
                               )',
                            [$idGrado, $idBloqueCreado, $idGrado, $idBloqueCreado]
                        );
                    }
                }
                if ($tipoNuevoBloque === 'CLASE') {
                    reordenar_bloques_clase_calendario($idCalendarioActivo);
                    $primerBloqueCreado = db_one(
                        'SELECT numero_bloque FROM bloques_horarios WHERE id_bloque = ? LIMIT 1',
                        [$idsCreados[0]]
                    );
                    $numeroNuevo = (int) ($primerBloqueCreado['numero_bloque'] ?? 0);
                }
                $grupoBloquesCreados = bin2hex(random_bytes(16));
                foreach ($idsCreados as $idBloqueCreado) {
                    $detalleBloqueCreado = db_one(
                        "SELECT tipo, numero_bloque, TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio,
                                TIME_FORMAT(hora_fin, '%H:%i') AS hora_fin
                         FROM bloques_horarios WHERE id_bloque = ?",
                        [$idBloqueCreado]
                    );
                    if (!$detalleBloqueCreado) {
                        throw new RuntimeException('No se encontró el bloque creado para registrar la novedad.');
                    }
                    $tipoCambioNovedad = $detalleBloqueCreado['tipo'] === 'DESCANSO' ? 'BLOQUE' : 'ADICION';
                    $etiquetaBloque = $detalleBloqueCreado['tipo'] === 'DESCANSO'
                        ? 'descanso'
                        : 'clase nueva';
                    $observacionBloque = $detalleBloqueCreado['tipo'] === 'DESCANSO'
                        ? 'Se creó el ' . $etiquetaBloque . ' para el grado ' . $idGrado .
                            ' de ' . $detalleBloqueCreado['hora_inicio'] . ' a ' . $detalleBloqueCreado['hora_fin'] . '.'
                        : 'Se registró una clase nueva para el grado ' . $idGrado .
                            ' de ' . $detalleBloqueCreado['hora_inicio'] . ' a ' . $detalleBloqueCreado['hora_fin'] . '.';
                    $estadoNovedad = $detalleBloqueCreado['tipo'] === 'DESCANSO' ? 'HISTORICO' : 'ACTIVA';
                    db_exec(
                        "INSERT INTO novedades_cambios_horario
                            (tipo_cambio, grupo_novedad, fecha, observacion, estado, id_grado, id_bloque)
                         VALUES (?, ?, CURDATE(), ?, ?, ?, ?)",
                        [$tipoCambioNovedad, $grupoBloquesCreados, $observacionBloque, $estadoNovedad, $idGrado, $idBloqueCreado]
                    );
                }
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
            flash_set('success', $tipoNuevoBloque === 'DESCANSO'
                ? 'Se agregó el descanso solo al grado seleccionado.'
                : 'El horario quedó distribuido en ' . (count($puntosSegmentacion) - 1) . ' tramos consecutivos para el grado seleccionado.');
            $backGrado = $idGrado;
        } elseif ($action === 'create_break_addition') {
            [$g, $dia, $bloque, $materia, $profesorId, $salonId, $estado, $observacion] = validate_horario(null, true, null, true);
            $bloqueDescanso = db_one(
                "SELECT b.id_bloque, b.tipo, b.id_calendario,
                        TIME_FORMAT(b.hora_inicio, '%H:%i') AS hora_inicio,
                        TIME_FORMAT(b.hora_fin, '%H:%i') AS hora_fin, d.nombre AS dia
                 FROM bloques_horarios b
                 JOIN grados g ON g.id_calendario = b.id_calendario
                 JOIN dias_semana d ON d.id_dia = ?
                 WHERE b.id_bloque = ? AND b.tipo = 'DESCANSO' AND g.id_grado = ?",
                [$dia, $bloque, $g]
            );
            if (!$bloqueDescanso) {
                throw new RuntimeException('El intervalo seleccionado ya no corresponde a un descanso del grado.');
            }
            $fecha = post('fecha');
            $grupoExistente = db_count(
                "SELECT COUNT(*) AS total FROM novedades_cambios_horario
                 WHERE tipo_cambio = 'ADICION' AND id_grado = ? AND id_dia = ? AND id_bloque = ?
                   AND fecha = ? AND estado = 'ACTIVA' AND COALESCE(nuevo_estado, 'PROGRAMADA') = 'PROGRAMADA'",
                [$g, $dia, $bloque, $fecha]
            );
            if ($grupoExistente > 0) {
                throw new RuntimeException('Ya existe una clase extra activa para ese descanso y fecha.');
            }
            $detalleClase = db_one(
                'SELECT m.nombre AS materia, p.nombre_completo AS profesor, s.nombre AS salon
                 FROM materias m
                 LEFT JOIN profesores p ON p.id_profesor = ?
                 LEFT JOIN salones s ON s.id_salon = ?
                 WHERE m.id_materia = ?',
                [$profesorId, $salonId, $materia]
            );
            if (!$detalleClase) {
                throw new RuntimeException('No se encontraron los datos de la clase extra.');
            }
            $observacionNovedad = 'Clase extra durante el descanso: ' . $bloqueDescanso['dia'] . ' ' .
                $bloqueDescanso['hora_inicio'] . '-' . $bloqueDescanso['hora_fin'] .
                ', grado ' . $g . ', ' . $detalleClase['materia'];
            if ($detalleClase['profesor']) {
                $observacionNovedad .= ', profesor ' . $detalleClase['profesor'];
            }
            if ($detalleClase['salon']) {
                $observacionNovedad .= ', salón ' . $detalleClase['salon'];
            }
            if (trim((string) $observacion) !== '') {
                $observacionNovedad .= '. ' . trim((string) $observacion);
            } else {
                $observacionNovedad .= '.';
            }
            db_exec(
                'INSERT INTO novedades_cambios_horario
                    (tipo_cambio, grupo_novedad, id_horario, fecha, nuevo_profesor, nueva_materia, nuevo_salon, nuevo_estado,
                     observacion, estado, id_grado, id_dia, id_bloque, dia, materia, hora_inicio, hora_fin, profesor, salon, detalle)
                 VALUES (\'ADICION\', ?, NULL, ?, ?, ?, ?, ?, ?, \'ACTIVA\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    bin2hex(random_bytes(16)), $fecha, $profesorId, $materia, $salonId, $estado,
                    $observacionNovedad, $g, $dia, $bloque, $bloqueDescanso['dia'], $detalleClase['materia'],
                    $bloqueDescanso['hora_inicio'], $bloqueDescanso['hora_fin'], $detalleClase['profesor'],
                    $detalleClase['salon'], 'Clase extra guardada como novedad normal durante un descanso.',
                ]
            );
            flash_set('success', 'La clase extra quedó registrada como novedad normal. El horario semanal no fue modificado.');
            $backGrado = $g;
        } elseif ($action === 'create') {
            $guardarPermanente = post('guardar_permanente', '0') === '1';
            $reemplazoDescanso = post('reemplazo_descanso', '0') === '1';
            [$g, $dia, $bloque, $materia, $profesorId, $salonId, $estado, $observacion] = validate_horario(null, !$guardarPermanente);
            if ($reemplazoDescanso && ($dia !== (int) post('reemplazo_descanso_dia', '0')
                || $bloque !== (int) post('reemplazo_descanso_bloque', '0'))) {
                throw new RuntimeException('La clase debe reemplazar el descanso del mismo día y bloque seleccionado.');
            }
            if ($reemplazoDescanso && db_count(
                'SELECT COUNT(*) AS total FROM descansos_por_dia WHERE id_grado = ? AND id_dia = ? AND id_bloque = ?',
                [$g, $dia, $bloque]
            ) === 0) {
                throw new RuntimeException('El descanso de esta casilla ya fue reemplazado.');
            }
            if (db_count(
                'SELECT COUNT(*) AS total FROM horario_semanal WHERE id_grado = ? AND id_dia = ? AND id_bloque = ?',
                [$g, $dia, $bloque]
            ) > 0) {
                throw new RuntimeException('El bloque ya tiene una clase en el horario semanal.');
            }
            if (!$guardarPermanente && db_count(
                "SELECT COUNT(*) AS total FROM novedades_cambios_horario
                 WHERE tipo_cambio = 'ADICION' AND id_grado = ? AND id_dia = ? AND id_bloque = ?
                                     AND fecha = ? AND estado = 'ACTIVA'
                                     AND COALESCE(nuevo_estado, 'PROGRAMADA') = 'PROGRAMADA'",
                [$g, $dia, $bloque, post('fecha')]
            ) > 0) {
                throw new RuntimeException('Ya existe una novedad activa para ese bloque y fecha.');
            }
            asegurar_tabla_cambios_horario();
            db()->beginTransaction();
            try {
                if ($guardarPermanente) {
                    db_exec(
                        'INSERT INTO horario_semanal (id_grado, id_dia, id_bloque, id_materia, id_profesor, id_salon, estado, observacion)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [$g, $dia, $bloque, $materia, $profesorId, $salonId, $estado, $observacion]
                    );
                    $idCreado = (int) db()->lastInsertId();
                    db_exec(
                        'DELETE FROM descansos_por_dia WHERE id_grado = ? AND id_dia = ? AND id_bloque = ?',
                        [$g, $dia, $bloque]
                    );
                    $resumenClase = resumen_clase_horario($idCreado);
                    $observacionPermanente = 'Se agregó al horario semanal: ' . $resumenClase;
                    $observacionIngresada = trim((string) $observacion);
                    if ($observacionIngresada !== '') {
                        $observacionPermanente .= "\nObservación: " . $observacionIngresada;
                    }
                    registrar_cambio_horario($idCreado, 'CREACION', $observacionPermanente);
                    if ($estado === 'PROGRAMADA') {
                        reprogramar_clases_siguientes((string) $g, (int) $dia, (int) $bloque, $idCreado, post('reprogramar_siguientes', '0') === '1');
                    }
                    db_exec(
                        'INSERT INTO novedades_temporales (grupo_novedad, id_horario, expira_en, observacion)
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 3 MINUTE), ?)',
                        [bin2hex(random_bytes(16)), $idCreado, $observacionPermanente]
                    );
                } else {
                    $detalleClase = db_one(
                        "SELECT d.nombre AS dia, m.nombre AS materia,
                                TIME_FORMAT(b.hora_inicio, '%H:%i') AS hora_inicio,
                                TIME_FORMAT(b.hora_fin, '%H:%i') AS hora_fin,
                                p.nombre_completo AS profesor, s.nombre AS salon
                         FROM dias_semana d
                         JOIN materias m ON m.id_materia = ?
                         JOIN bloques_horarios b ON b.id_bloque = ?
                         LEFT JOIN profesores p ON p.id_profesor = ?
                         LEFT JOIN salones s ON s.id_salon = ?
                         WHERE d.id_dia = ?",
                        [$materia, $bloque, $profesorId, $salonId, $dia]
                    );
                    if (!$detalleClase) {
                        throw new RuntimeException('No se encontró el bloque seleccionado.');
                    }
                    $profesorObservacion = trim((string) ($detalleClase['profesor'] ?? ''));
                    $salonObservacion = trim((string) ($detalleClase['salon'] ?? ''));
                    $observacionNovedad = 'Se agregó la clase de ' . $detalleClase['materia'] .
                        ' para el ' . post('fecha') . ' (' . $detalleClase['dia'] . ', ' .
                        $detalleClase['hora_inicio'] . '-' . $detalleClase['hora_fin'] . ')';
                    $observacionNovedad .= $profesorObservacion !== ''
                        ? ', con el profesor ' . $profesorObservacion
                        : ', sin profesor asignado';
                    $observacionNovedad .= $salonObservacion !== ''
                        ? ' y en el salón ' . $salonObservacion . '.'
                        : ' y sin salón asignado.';
                    $observacionIngresada = trim((string) $observacion);
                    if ($observacionIngresada !== '') {
                        $observacionNovedad .= "\nObservación: " . $observacionIngresada;
                    }
                    db_exec(
                        'INSERT INTO novedades_cambios_horario
                            (tipo_cambio, grupo_novedad, id_horario, fecha, nuevo_profesor, nueva_materia, nuevo_salon, nuevo_estado,
                             observacion, estado, id_grado, id_dia, id_bloque, dia, materia, hora_inicio, hora_fin,
                             profesor, salon, detalle)
                         VALUES (\'ADICION\', ?, NULL, ?, ?, ?, ?, ?, ?, \'ACTIVA\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            bin2hex(random_bytes(16)), post('fecha'), $profesorId, $materia, $salonId,
                            $estado, $observacionNovedad, $g, $dia, $bloque, $detalleClase['dia'], $detalleClase['materia'],
                            $detalleClase['hora_inicio'], $detalleClase['hora_fin'], $detalleClase['profesor'],
                            $detalleClase['salon'], 'Clase añadida para una sola fecha desde el horario semanal.',
                        ]
                    );
                    $resumenClase = $g . ', ' . $detalleClase['materia'] . ', ' . $detalleClase['hora_inicio'] . '-' . $detalleClase['hora_fin'];
                }
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
            flash_set('success', $guardarPermanente
                ? 'Clase guardada permanentemente en el horario semanal y resaltada durante 3 minutos: ' . $resumenClase . '.'
                : 'Novedad registrada para ' . post('fecha') . ' sin modificar el horario semanal: ' . $resumenClase . '.');
            $backGrado = $g;
        } elseif ($action === 'cancel_addition') {
            $idNovedad = (int) post('id_novedad', '0');
            $adicion = db_one(
                "SELECT id_cambio, id_grado FROM novedades_cambios_horario
                 WHERE id_cambio = ? AND tipo_cambio = 'ADICION' AND id_horario IS NULL AND estado = 'ACTIVA'",
                [$idNovedad]
            );
            if (!$adicion || (string) $adicion['id_grado'] !== (string) post('id_grado', '')) {
                throw new RuntimeException('No se encontró la novedad activa que desea cancelar.');
            }
            db_exec(
                "UPDATE novedades_cambios_horario SET estado = 'CANCELADA'
                 WHERE id_cambio = ? AND tipo_cambio = 'ADICION' AND id_horario IS NULL AND estado = 'ACTIVA'",
                [$idNovedad]
            );
            flash_set('success', 'Se canceló la novedad y se restauró el descanso de esta casilla.');
            $backGrado = (string) $adicion['id_grado'];
        } elseif ($action === 'update_addition') {
            $idNovedad = (int) post('id_novedad', '0');
            $adicion = db_one(
                "SELECT id_cambio, id_grado FROM novedades_cambios_horario
                 WHERE id_cambio = ? AND tipo_cambio = 'ADICION' AND id_horario IS NULL AND estado = 'ACTIVA'",
                [$idNovedad]
            );
            if (!$adicion) {
                throw new RuntimeException('No se encontró la novedad que desea editar.');
            }
            [$g, $dia, $bloque, $materia, $profesorId, $salonId, $estado, $observacion] = validate_horario(null, true, $idNovedad);
            if ((string) $adicion['id_grado'] !== $g) {
                throw new RuntimeException('La novedad no pertenece al grado seleccionado.');
            }
            $detalleClase = db_one(
                "SELECT d.nombre AS dia, m.nombre AS materia,
                        TIME_FORMAT(b.hora_inicio, '%H:%i') AS hora_inicio,
                        TIME_FORMAT(b.hora_fin, '%H:%i') AS hora_fin,
                        p.nombre_completo AS profesor, s.nombre AS salon
                 FROM dias_semana d
                 JOIN materias m ON m.id_materia = ?
                 JOIN bloques_horarios b ON b.id_bloque = ?
                 LEFT JOIN profesores p ON p.id_profesor = ?
                 LEFT JOIN salones s ON s.id_salon = ?
                 WHERE d.id_dia = ?",
                [$materia, $bloque, $profesorId, $salonId, $dia]
            );
            if (!$detalleClase) {
                throw new RuntimeException('No se encontró el bloque seleccionado.');
            }
            $observacionNovedad = 'Se agregó la clase de ' . $detalleClase['materia'] .
                ' para el ' . post('fecha') . ' (' . $detalleClase['dia'] . ', ' .
                $detalleClase['hora_inicio'] . '-' . $detalleClase['hora_fin'] . ')';
            $observacionNovedad .= $detalleClase['profesor']
                ? ', con el profesor ' . $detalleClase['profesor']
                : ', sin profesor asignado';
            $observacionNovedad .= $detalleClase['salon']
                ? ' y en el salón ' . $detalleClase['salon'] . '.'
                : ' y sin salón asignado.';
            if (trim((string) $observacion) !== '') {
                $observacionNovedad .= "\nObservación: " . trim((string) $observacion);
            }
            if (post('guardar_permanente', '0') === '1') {
                db()->beginTransaction();
                try {
                    db_exec(
                        "UPDATE novedades_cambios_horario
                         SET estado = 'CANCELADA', observacion = CONCAT_WS(CHAR(10), NULLIF(observacion, ''), 'Clase aplicada permanentemente al horario semanal.')
                         WHERE id_cambio = ? AND tipo_cambio = 'ADICION' AND estado = 'ACTIVA'",
                        [$idNovedad]
                    );
                    db_exec(
                        'INSERT INTO horario_semanal (id_grado, id_dia, id_bloque, id_materia, id_profesor, id_salon, estado, observacion)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [$g, $dia, $bloque, $materia, $profesorId, $salonId, $estado, $observacion]
                    );
                    $idCreado = (int) db()->lastInsertId();
                    db_exec(
                        'DELETE FROM descansos_por_dia WHERE id_grado = ? AND id_dia = ? AND id_bloque = ?',
                        [$g, $dia, $bloque]
                    );
                    $resumenClase = resumen_clase_horario($idCreado);
                    registrar_cambio_horario($idCreado, 'CREACION', 'Clase guardada permanentemente desde una novedad: ' . $resumenClase);
                    if ($estado === 'PROGRAMADA') {
                        reprogramar_clases_siguientes($g, $dia, $bloque, $idCreado, post('reprogramar_siguientes', '0') === '1');
                    }
                    db_exec(
                        'INSERT INTO novedades_temporales (grupo_novedad, id_horario, expira_en, observacion)
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 3 MINUTE), ?)',
                        [bin2hex(random_bytes(16)), $idCreado, 'Clase guardada permanentemente desde una novedad: ' . $resumenClase]
                    );
                    db()->commit();
                } catch (Throwable $e) {
                    if (db()->inTransaction()) {
                        db()->rollBack();
                    }
                    throw $e;
                }
                flash_set('success', 'La clase se guardó permanentemente y se resaltará durante 3 minutos.');
            } else {
                db_exec(
                    "UPDATE novedades_cambios_horario
                     SET id_dia = ?, id_bloque = ?, fecha = ?, nuevo_profesor = ?, nueva_materia = ?, nuevo_salon = ?,
                         nuevo_estado = ?, observacion = ?, dia = ?, materia = ?, hora_inicio = ?, hora_fin = ?, profesor = ?, salon = ?
                     WHERE id_cambio = ? AND tipo_cambio = 'ADICION' AND id_horario IS NULL AND estado = 'ACTIVA'",
                    [
                        $dia, $bloque, post('fecha'), $profesorId, $materia, $salonId, $estado, $observacionNovedad,
                        $detalleClase['dia'], $detalleClase['materia'], $detalleClase['hora_inicio'],
                        $detalleClase['hora_fin'], $detalleClase['profesor'], $detalleClase['salon'], $idNovedad,
                    ]
                );
                flash_set('success', 'La novedad fue actualizada correctamente.');
            }
            $backGrado = $g;
        } elseif ($action === 'cancel') {
            $id = (int) post('id_horario', '0');
            $clase = db_one('SELECT h.id_grado, h.id_dia, h.id_bloque, h.estado, b.hora_inicio, b.hora_fin FROM horario_semanal h JOIN bloques_horarios b ON b.id_bloque = h.id_bloque WHERE h.id_horario = ?', [$id]);
            if (!$id || !$clase) {
                throw new RuntimeException('No se encontró la clase que desea cancelar.');
            }
            if ($clase['estado'] !== 'CANCELADA') {
                $fechaCancelacion = fecha_proxima_dia((int) $clase['id_dia']);
                asegurar_esquema_novedades();
                db()->beginTransaction();
                try {
                    registrar_novedad_cancelacion_horario(
                        $id,
                        bin2hex(random_bytes(16)),
                        'Clase cancelada desde el horario semanal.',
                        $fechaCancelacion
                    );
                    registrar_cambio_horario($id, 'CANCELACION', 'Clase cancelada desde el horario semanal.');
                    db()->commit();
                } catch (Throwable $e) {
                    if (db()->inTransaction()) {
                        db()->rollBack();
                    }
                    throw $e;
                }
            }
            flash_set('success', 'La clase quedó cancelada y marcada en el horario.');
            $backGrado = $clase['id_grado'];
        } elseif ($action === 'update') {
            $id = (int) post('id_horario', '0');
            $guardarPermanente = post('guardar_permanente', '0') === '1';
            if (!$id) {
                throw new RuntimeException('No se encontró la clase.');
            }
            [$g, $dia, $bloque, $materia, $profesorId, $salonId, $estado, $observacion] = validate_horario($id, true);
            $anterior = db_one(
                'SELECT h.id_grado, h.id_dia, h.id_bloque, h.estado, h.observacion,
                        h.id_profesor, h.id_materia, h.id_salon,
                        p.nombre_completo AS profesor, m.nombre AS materia, s.nombre AS salon,
                        TIME_FORMAT(b.hora_inicio, \'%H:%i\') AS hora_inicio,
                        TIME_FORMAT(b.hora_fin, \'%H:%i\') AS hora_fin
                 FROM horario_semanal h
                 LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
                 JOIN materias m ON m.id_materia = h.id_materia
                 JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                 LEFT JOIN salones s ON s.id_salon = h.id_salon
                 WHERE h.id_horario = ?',
                [$id]
            );
            if (!$anterior) {
                throw new RuntimeException('No se encontró la clase que desea editar.');
            }
            $resolucionCancelacion = post('resolucion_cancelacion', '');
            if ($guardarPermanente && $estado === 'CANCELADA') {
                $limitesJornada = db_one(
                        'SELECT TIME_FORMAT(MIN(b.hora_inicio), \'%H:%i\') AS primera_hora,
                            TIME_FORMAT(MAX(b.hora_inicio), \'%H:%i\') AS ultima_hora
                     FROM horario_semanal h
                     JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                     WHERE h.id_grado = ? AND h.id_dia = ? AND h.id_horario <> ?
                       AND h.estado = \'PROGRAMADA\'',
                    [$g, $dia, $id]
                );
                $esClaseIntermedia = $limitesJornada
                    && $limitesJornada['primera_hora'] !== null
                    && $limitesJornada['ultima_hora'] !== null
                    && $anterior['hora_inicio'] > $limitesJornada['primera_hora']
                    && $anterior['hora_inicio'] < $limitesJornada['ultima_hora'];
                if ($esClaseIntermedia) {
                    throw new RuntimeException('Una clase intermedia debe reemplazarse por otro profesor o convertirse en descanso antes de guardarla permanentemente.');
                }
            }
            if ($resolucionCancelacion === 'profesor'
                && (!$guardarPermanente || $estado !== 'PROGRAMADA' || !$profesorId
                    || (int) $profesorId === (int) ($anterior['id_profesor'] ?? 0))) {
                throw new RuntimeException('Seleccione un profesor diferente y guarde permanentemente la clase.');
            }
            if ($resolucionCancelacion === 'descanso' && (!$guardarPermanente || $estado !== 'DESCANSO')) {
                throw new RuntimeException('Para dejar el bloque como descanso, seleccione DESCANSO y guarde permanentemente.');
            }
            if ($resolucionCancelacion !== '' && !in_array($resolucionCancelacion, ['profesor', 'descanso'], true)) {
                throw new RuntimeException('La resolución de la cancelación no es válida.');
            }
            if ((string) $anterior['id_grado'] !== $g || (int) $anterior['id_dia'] !== $dia) {
                throw new RuntimeException('La edición de una clase no puede cambiar el grado ni el día semanal original.');
            }
            $cambiosBase = (
                (int) $anterior['id_bloque'] !== (int) $bloque
                || (int) $anterior['id_materia'] !== (int) $materia
                || ((int) ($anterior['id_profesor'] ?? 0)) !== ((int) ($profesorId ?? 0))
                || ((int) ($anterior['id_salon'] ?? 0)) !== ((int) ($salonId ?? 0))
                || (string) ($anterior['estado'] ?? '') !== (string) $estado
                || (string) ($anterior['observacion'] ?? '') !== (string) ($observacion ?? '')
            );
            if ($cambiosBase) {
                if ($guardarPermanente) {
                    eliminar_horario_original_novedad($id);
                    db_exec(
                        'UPDATE horario_semanal
                         SET id_dia = ?, id_bloque = ?, id_materia = ?, id_profesor = ?, id_salon = ?, estado = ?, observacion = ?
                         WHERE id_horario = ?',
                        [$dia, $bloque, $materia, $profesorId, $salonId, $estado, $observacion, $id]
                    );
                    sincronizar_observacion_horario_novedad($id, (string) $observacion);
                    if ($estado === 'PROGRAMADA') {
                        reprogramar_clases_siguientes((string) $g, (int) $dia, (int) $bloque, (int) $id, post('reprogramar_siguientes', '0') === '1');
                    }
                }
                registrar_cambio_horario($id, 'CAMBIO', 'Clase actualizada desde el horario semanal.');
            }
            $bloqueNuevo = db_one(
                'SELECT TIME_FORMAT(hora_inicio, \'%H:%i\') AS hora_inicio,
                        TIME_FORMAT(hora_fin, \'%H:%i\') AS hora_fin
                 FROM bloques_horarios WHERE id_bloque = ?',
                [$bloque]
            );
            if (!$bloqueNuevo) {
                throw new RuntimeException('No se encontró el bloque seleccionado.');
            }
            $detallesNovedad = [];
            $profesorCambio = ($anterior['id_profesor'] !== null ? (int) $anterior['id_profesor'] : null) !== $profesorId;
            $materiaCambio = (int) $anterior['id_materia'] !== $materia;
            $salonCambio = ($anterior['id_salon'] !== null ? (int) $anterior['id_salon'] : null) !== $salonId;
            $horaCambio = $anterior['hora_inicio'] !== $bloqueNuevo['hora_inicio'] || $anterior['hora_fin'] !== $bloqueNuevo['hora_fin'];
            if (($anterior['id_profesor'] !== null ? (int) $anterior['id_profesor'] : null) !== $profesorId) {
                $profesorNuevo = $profesorId ? db_one('SELECT nombre_completo FROM profesores WHERE id_profesor = ?', [$profesorId]) : null;
                $detallesNovedad[] = 'Profesor: ' . ($anterior['profesor'] ?: 'Sin profesor') . ', ' . ($profesorNuevo['nombre_completo'] ?? 'Sin profesor');
            }
            if ((int) $anterior['id_materia'] !== $materia) {
                $materiaNueva = db_one('SELECT nombre FROM materias WHERE id_materia = ?', [$materia]);
                $detallesNovedad[] = 'Materia: ' . $anterior['materia'] . ', ' . ($materiaNueva['nombre'] ?? 'Sin materia');
            }
            if (($anterior['id_salon'] !== null ? (int) $anterior['id_salon'] : null) !== $salonId) {
                $salonNuevo = $salonId ? db_one('SELECT nombre FROM salones WHERE id_salon = ?', [$salonId]) : null;
                $detallesNovedad[] = 'Salón: ' . ($anterior['salon'] ?: 'Sin salón') . ', ' . ($salonNuevo['nombre'] ?? 'Sin salón');
            }
            $fechaNovedad = post('fecha');
            if ($horaCambio) {
                $detallesNovedad[] = 'Hora: ' . $anterior['hora_inicio'] . '-' . $anterior['hora_fin'] . ', ' . $bloqueNuevo['hora_inicio'] . '-' . $bloqueNuevo['hora_fin'];
            }
            $observacionActual = trim((string) ($observacion ?? ''));
            $observacionOriginal = trim((string) ($anterior['observacion'] ?? ''));
            $observacionCambio = $observacionActual !== $observacionOriginal;
            $estadoCambio = (string) ($anterior['estado'] ?? '') !== (string) $estado;
            if ($estadoCambio) {
                $detallesNovedad[] = $estado === 'CANCELADA'
                    ? 'Clase cancelada desde el horario semanal.'
                    : 'Estado: ' . ($anterior['estado'] ?? 'PROGRAMADA') . ', ' . $estado;
            } elseif ($estado === 'DESCANSO') {
                $detallesNovedad[] = 'La casilla se mantiene en descanso.';
            }
            $observacionSinCancelacion = trim(implode("\n", array_filter(
                array_map('trim', preg_split('/\r?\n/', $observacionActual) ?: []),
                static fn(string $linea): bool => stripos($linea, 'clase cancelada') === false
            )));
            if ($observacionCambio && $observacionSinCancelacion !== '') {
                $detallesNovedad[] = 'Observación: ' . $observacionSinCancelacion;
            }
            $hayCambios = $profesorCambio || $materiaCambio || $salonCambio || $horaCambio || $observacionCambio
                || $estadoCambio
                || $estado === 'DESCANSO'
                || ($guardarPermanente && $resolucionCancelacion !== '');
            if ($resolucionCancelacion === 'descanso' && $estadoCambio) {
                $detallesNovedad[] = 'La casilla quedó como descanso.';
            }
            $observacionNovedad = $estado === 'CANCELADA'
                ? 'Clase cancelada desde el horario semanal.'
                : implode("\n", $detallesNovedad);
            asegurar_esquema_novedades();
            asegurar_tabla_cambios_horario();
            db()->beginTransaction();
            try {
                $novedadesExistentes = db_all(
                    "SELECT id_cambio, grupo_novedad FROM novedades_cambios_horario
                         WHERE id_horario = ? AND fecha = ? AND estado = 'ACTIVA'
                        ORDER BY id_cambio DESC",
                    [$id, $fechaNovedad]
                );
                $novedadPrincipal = $novedadesExistentes[0] ?? null;
                $grupoNovedad = (string) ($novedadPrincipal['grupo_novedad'] ?? bin2hex(random_bytes(16)));
                if ($estadoCambio && $estado === 'PROGRAMADA') {
                    asegurar_tabla_horario_original_novedad();
                    db_exec(
                        'UPDATE horario_original_novedad SET estado = ? WHERE id_horario = ?',
                        ['PROGRAMADA', $id]
                    );
                }
                if ($guardarPermanente && $hayCambios) {
                    db_exec(
                        "UPDATE novedades_cambios_horario
                         SET estado = 'CANCELADA', observacion = CONCAT_WS(CHAR(10), NULLIF(observacion, ''), 'Cambio aplicado permanentemente al horario semanal.')
                         WHERE id_horario = ? AND estado = 'ACTIVA'",
                        [$id]
                    );
                    eliminar_horario_original_novedad($id);
                    db_exec('DELETE FROM novedades_temporales WHERE id_horario = ?', [$id]);
                    db_exec(
                        'INSERT INTO novedades_temporales (grupo_novedad, id_horario, expira_en, observacion)
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 3 MINUTE), ?)',
                        [bin2hex(random_bytes(16)), $id, 'Cambios aplicados permanentemente desde el horario semanal: ' . implode('; ', $detallesNovedad)]
                    );
                    if ($estadoCambio && $estado === 'CANCELADA') {
                        registrar_novedad_cancelacion_horario(
                            $id,
                            $grupoNovedad,
                            'Clase cancelada desde el horario semanal.',
                            $fechaNovedad
                        );
                    }
                    registrar_cambio_horario($id, 'CAMBIO', 'Cambios permanentes guardados desde el horario semanal.');
                } elseif ($hayCambios) {
                    db_exec('DELETE FROM novedades_temporales WHERE id_horario = ?', [$id]);
                    $valoresNovedad = [
                        $profesorCambio ? $profesorId : null,
                        $materiaCambio ? $materia : null,
                        $salonCambio ? $salonId : null,
                        $estadoCambio || $estado === 'DESCANSO' ? $estado : null,
                        $horaCambio ? $bloqueNuevo['hora_inicio'] : null,
                        $horaCambio ? $bloqueNuevo['hora_fin'] : null,
                        $observacionNovedad,
                    ];
                    if ($novedadPrincipal) {
                        db_exec(
                            'UPDATE novedades_cambios_horario
                             SET grupo_novedad = ?, nuevo_profesor = ?, nueva_materia = ?, nuevo_salon = ?, nuevo_estado = ?,
                                 nueva_hora_inicio = ?, nueva_hora_fin = ?, observacion = ?, estado = \'ACTIVA\'
                             WHERE id_cambio = ?',
                            [$grupoNovedad, ...$valoresNovedad, $novedadPrincipal['id_cambio']]
                        );
                    } else {
                        db_exec(
                            'INSERT INTO novedades_cambios_horario
                                          (tipo_cambio, grupo_novedad, id_horario, fecha, nuevo_profesor, nueva_materia, nuevo_salon, nuevo_estado,
                                 nueva_hora_inicio, nueva_hora_fin, observacion, estado)
                                      VALUES (\'NOVEDAD\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'ACTIVA\')',
                            [$grupoNovedad, $id, $fechaNovedad, ...$valoresNovedad]
                        );
                    }
                    registrar_cambio_horario($id, 'CAMBIO', 'Novedad de horario registrada para ' . $fechaNovedad . '.');
                } elseif ($novedadesExistentes && (post('restaurar_original', '0') === '1' || !$hayCambios)) {
                    db_exec(
                        "UPDATE novedades_cambios_horario
                         SET estado = 'CANCELADA', observacion = CONCAT_WS(CHAR(10), NULLIF(observacion, ''), 'Cambio revertido al horario original.')
                         WHERE id_horario = ? AND fecha = ? AND estado = 'ACTIVA'",
                        [$id, $fechaNovedad]
                    );
                    registrar_cambio_horario($id, 'CAMBIO', 'Novedad revertida al horario semanal original para ' . $fechaNovedad . '.');
                }
                if ($hayCambios) {
                    foreach (array_slice($novedadesExistentes, 1) as $duplicada) {
                        db_exec("UPDATE novedades_cambios_horario SET estado = 'CANCELADA' WHERE id_cambio = ?", [$duplicada['id_cambio']]);
                    }
                }
                if ($guardarPermanente && ($anterior['estado'] ?? '') === 'CANCELADA') {
                    db_exec(
                        "DELETE FROM novedades_cambios_horario
                         WHERE id_horario = ? AND estado = 'HISTORICO'",
                        [$id]
                    );
                }
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
            $restauradoAlOriginal = $novedadesExistentes && !$hayCambios;
            flash_set('success', $guardarPermanente && $hayCambios
                ? ($estado === 'CANCELADA'
                    ? 'La clase quedó cancelada permanentemente. El espacio aparece resaltado durante 3 minutos.'
                    : 'Cambios guardados permanentemente en el horario semanal. Se resaltarán durante 3 minutos.')
                : ($hayCambios
                ? 'Novedad registrada para ' . $fechaNovedad . '. El horario semanal original no fue modificado.'
                : ($restauradoAlOriginal || post('restaurar_original', '0') === '1'
                    ? 'Se restauró el horario original para ' . $fechaNovedad . '. La novedad ya no es necesaria.'
                    : ($novedadesExistentes
                        ? 'No se detectaron cambios. La novedad existente se conservó y el horario semanal original permanece igual.'
                        : 'No se detectaron cambios. El horario semanal original permanece igual.'))));
            $backGrado = $g;
        } elseif ($action === 'delete') {
            throw new RuntimeException('No se permite eliminar una clase permanentemente. Edítela para reemplazarla o convierta la casilla en descanso.');
        }
    } catch (PDOException $e) {
        flash_set('error', unique_error($e, 'No se pudo guardar la información. Verifique los datos e inténtelo nuevamente.'));
    } catch (RuntimeException $e) {
        $mensaje = $e->getMessage();
        $esOcupado = str_starts_with($mensaje, 'Esa hora ya está ocupada')
            || stripos($mensaje, 'ya tiene clase') !== false
            || stripos($mensaje, 'ya está ocupado') !== false
            || str_starts_with($mensaje, 'No se puede distribuir el horario porque la siguiente clase ocupa ese bloque todos los días.');
        $esCruceBloque = str_starts_with($mensaje, 'No se puede agregar ese bloque porque se cruza con otro bloque.');
        flash_set($esOcupado || $esCruceBloque ? 'occupied' : 'error', $esOcupado && !str_starts_with($mensaje, 'Esa hora ya está ocupada')
            ? 'Esa hora ya está ocupada. ' . $mensaje
            : $mensaje);
    }
    horario_redirect($backGrado);
}

$bloques = [];
$grid = [];
$descansosPorDiaBloque = [];
$ocupacionesPorDia = [];
$huecosTemporalesPorDiaBloque = [];
$gradoInfo = null;
if ($grado) {
    $gradoInfo = db_one(
        'SELECT g.*, c.nombre AS calendario FROM grados g JOIN calendarios c ON c.id_calendario = g.id_calendario WHERE g.id_grado = ? AND g.activo = 1',
        [$grado]
    );
    if ($gradoInfo) {
        $bloques = db_all(
            'SELECT * FROM bloques_horarios WHERE id_calendario = ? ORDER BY hora_inicio, numero_bloque',
            [$gradoInfo['id_calendario']]
        );
        $bloques = array_values(array_filter($bloques, static function (array $bloque): bool {
            return !in_array((int) $bloque['id_bloque'], [24, 25], true);
        }));
        $clases = db_all(
            'SELECT h.*, b.hora_inicio, b.hora_fin, m.nombre AS materia, p.nombre_completo AS profesor, s.nombre AS salon, d.nombre AS dia
             FROM horario_semanal h
             JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
             JOIN materias m ON m.id_materia = h.id_materia
             JOIN dias_semana d ON d.id_dia = h.id_dia
             LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
             LEFT JOIN salones s ON s.id_salon = h.id_salon
             WHERE h.id_grado = ?',
            [$grado]
        );
        foreach ($clases as $clase) {
            $grid[(int) $clase['id_bloque']][(int) $clase['id_dia']] = $clase;
            if ($clase['estado'] === 'PROGRAMADA') {
                $ocupacionesPorDia[(int) $clase['id_dia']][] = [
                    'id' => (int) $clase['id_horario'],
                    'start' => format_time($clase['hora_inicio']),
                    'end' => format_time($clase['hora_fin']),
                ];
            }
        }
        $limitesJornadaPorDia = [];
        foreach ($ocupacionesPorDia as $idDia => $ocupaciones) {
            $horasInicio = array_column($ocupaciones, 'start');
            sort($horasInicio, SORT_STRING);
            if ($horasInicio) {
                $limitesJornadaPorDia[(int) $idDia] = [
                    'primera_hora' => $horasInicio[0],
                    'ultima_hora' => $horasInicio[count($horasInicio) - 1],
                ];
            }
        }
        foreach (db_all('SELECT id_dia, id_bloque FROM descansos_por_dia WHERE id_grado = ?', [$grado]) as $descansoPorDia) {
            $descansosPorDiaBloque[(int) $descansoPorDia['id_bloque']][(int) $descansoPorDia['id_dia']] = true;
        }
        $huecosTemporales = db_all(
            'SELECT id_dia, id_bloque, UNIX_TIMESTAMP(expira_en) AS expira_timestamp
             FROM huecos_horario_temporales WHERE id_grado = ? AND expira_en > NOW()',
            [$grado]
        );
        foreach ($huecosTemporales as $huecoTemporal) {
            $key = (int) $huecoTemporal['id_dia'] . ':' . (int) $huecoTemporal['id_bloque'];
            $huecosTemporalesPorDiaBloque[$key] = (int) $huecoTemporal['expira_timestamp'];
        }

        $novedadesActivas = db_all(
            'SELECT n.id_cambio AS id_novedad, n.tipo_cambio, n.id_horario, n.id_grado, n.fecha, n.nuevo_estado, n.nuevo_profesor, n.nueva_materia, n.nuevo_salon,
                    n.nueva_hora_inicio, n.nueva_hora_fin, n.observacion, n.estado,
                    COALESCE(h.id_dia, n.id_dia) AS id_dia,
                    COALESCE(h.id_bloque, n.id_bloque) AS id_bloque,
                    h.id_materia, h.id_profesor, h.id_salon,
                    b.hora_inicio AS hora_actual_inicio, b.hora_fin AS hora_actual_fin,
                    mn.nombre AS materia_nueva, pn.nombre_completo AS profesor_nuevo, sn.nombre AS salon_nuevo,
                    m.nombre AS materia_actual, p.nombre_completo AS profesor_actual, s.nombre AS salon_actual
             FROM novedades_cambios_horario n
             LEFT JOIN horario_semanal h ON h.id_horario = n.id_horario
             LEFT JOIN bloques_horarios b ON b.id_bloque = COALESCE(h.id_bloque, n.id_bloque)
             LEFT JOIN materias m ON m.id_materia = h.id_materia
             LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
             LEFT JOIN salones s ON s.id_salon = h.id_salon
             LEFT JOIN materias mn ON mn.id_materia = n.nueva_materia
             LEFT JOIN profesores pn ON pn.id_profesor = n.nuevo_profesor
             LEFT JOIN salones sn ON sn.id_salon = n.nuevo_salon
             WHERE (n.id_grado = ? OR h.id_grado = ?) AND n.estado IN ("ACTIVA", "CANCELADA")
             ORDER BY n.fecha DESC, n.id_cambio DESC',
            [$grado, $grado]
        );
        $novedadesTemporales = db_all(
                'SELECT t.id_temporal AS id_novedad, t.grupo_novedad, t.id_horario, t.expira_en,
                    UNIX_TIMESTAMP(t.expira_en) AS expira_timestamp,
                    t.observacion, h.id_dia, h.id_bloque, h.id_materia,
                    h.id_profesor AS nuevo_profesor, h.id_profesor, h.id_salon,
                    NULL AS nueva_materia, NULL AS nuevo_salon, NULL AS nueva_hora_inicio, NULL AS nueva_hora_fin,
                    b.hora_inicio AS hora_actual_inicio, b.hora_fin AS hora_actual_fin,
                    m.nombre AS materia_actual, p.nombre_completo AS profesor_actual,
                    s.nombre AS salon_actual, m.nombre AS materia_nueva,
                    p.nombre_completo AS profesor_nuevo, s.nombre AS salon_nuevo,
                    \'ACTIVA\' AS estado
             FROM novedades_temporales t
             JOIN horario_semanal h ON h.id_horario = t.id_horario
             JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
             JOIN materias m ON m.id_materia = h.id_materia
             LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
             LEFT JOIN salones s ON s.id_salon = h.id_salon
             WHERE h.id_grado = ? AND t.expira_en > NOW()
             ORDER BY t.creado_en DESC',
            [$grado]
        );
        foreach ($novedadesTemporales as &$novedadTemporal) {
            $novedadTemporal['temporal'] = true;
        }
        unset($novedadTemporal);
        array_push($novedadesActivas, ...$novedadesTemporales);
        $novedadesPorDiaBloque = [];
        foreach ($novedadesActivas as $novedad) {
            if (($novedad['estado'] ?? '') !== 'ACTIVA') {
                continue;
            }
            $materiaNueva = $novedad['nueva_materia'] !== null ? (int) $novedad['nueva_materia'] : null;
            $profesorNuevo = $novedad['nuevo_profesor'] !== null ? (int) $novedad['nuevo_profesor'] : null;
            $salonNuevo = $novedad['nuevo_salon'] !== null ? (int) $novedad['nuevo_salon'] : null;
            $horaNuevaInicio = $novedad['nueva_hora_inicio'] ?? null;
            $horaNuevaFin = $novedad['nueva_hora_fin'] ?? null;
            $detalleNovedad = (string) ($novedad['observacion'] ?? '');
            $cambioMateria = preg_match('/(?:^|\n)Materia:/u', $detalleNovedad) === 1
                || ($materiaNueva !== null && (int) $novedad['id_materia'] !== $materiaNueva);
            $cambioProfesor = !empty($novedad['temporal'])
                || preg_match('/(?:^|\n)Profesor:/u', $detalleNovedad) === 1
                || preg_match('/Profesor eliminado de (?:la materia|del salón)/u', $detalleNovedad) === 1
                || ($profesorNuevo !== null && (int) ($novedad['id_profesor'] ?? 0) !== $profesorNuevo);
            $cambioSalon = preg_match('/(?:^|\n)Salón:/u', $detalleNovedad) === 1
                || ($salonNuevo !== null && (int) ($novedad['id_salon'] ?? 0) !== $salonNuevo);
            $cambioHora = preg_match('/(?:^|\n)Hora:/u', $detalleNovedad) === 1
                || ($horaNuevaInicio !== null && $horaNuevaFin !== null && (
                    $novedad['hora_actual_inicio'] !== $horaNuevaInicio || $novedad['hora_actual_fin'] !== $horaNuevaFin
                ));
            $novedad['cambio_materia'] = $cambioMateria;
            $novedad['cambio_profesor'] = $cambioProfesor;
            $novedad['cambio_salon'] = $cambioSalon;
            $diaNovedad = (int) $novedad['id_dia'];
            $bloqueNovedad = (int) $novedad['id_bloque'];
            $key = $diaNovedad . ':' . $bloqueNovedad;
            if (!isset($novedadesPorDiaBloque[$key])
                || (!empty($novedad['temporal']) && empty($novedadesPorDiaBloque[$key]['temporal']))) {
                $novedadesPorDiaBloque[$key] = $novedad;
            }
        }
    }
}

$bloquesClase = array_values(array_filter($bloques, static fn($b) => $b['tipo'] === 'CLASE'));
$bloquesDescansoPartidos = array_fill_keys(array_map(
    'intval',
    array_column(db_all("SELECT id_bloque_agregado FROM bloques_descansos_reemplazados WHERE accion = 'PARCIAL'"), 'id_bloque_agregado')
), true);
$bloquesParaPlanificar = array_map(static function (array $bloque) use ($grid): array {
    $diasProgramados = count(array_filter(
        $grid[(int) $bloque['id_bloque']] ?? [],
        static fn(array $clase): bool => $clase['estado'] === 'PROGRAMADA'
    ));
    return [
        'inicio' => format_time($bloque['hora_inicio']),
        'fin' => format_time($bloque['hora_fin']),
        'tipo' => $bloque['tipo'],
        'diasProgramados' => $diasProgramados,
    ];
}, array_values(array_filter($bloques, static fn(array $bloque): bool => !in_array((int) $bloque['id_bloque'], [24, 25], true))));
$diasTabla = $filtroDia > 0
    ? array_values(array_filter($dias, static fn($dia) => (int) $dia['id_dia'] === $filtroDia))
    : $dias;
$bloquesTabla = array_values(array_filter($bloques, static function (array $bloque) use ($filtroHoraInicio, $filtroHoraFin, $soloLibres): bool {
    $horaInicio = format_time($bloque['hora_inicio']);
    $horaFin = format_time($bloque['hora_fin']);
    return (!$soloLibres || $bloque['tipo'] === 'CLASE')
        && (!$filtroHoraInicio || $horaFin > $filtroHoraInicio)
        && (!$filtroHoraFin || $horaInicio < $filtroHoraFin);
}));
$idsBloqueTabla = array_fill_keys(array_map(static fn(array $bloque): int => (int) $bloque['id_bloque'], $bloquesTabla), true);
$permiteDescansoPorBloque = [];
$mostrarMasPorBloque = [];
$bloquesOrdenados = array_values($bloques);
foreach ($bloquesOrdenados as $indice => $bloque) {
    $siguiente = $bloquesOrdenados[$indice + 1] ?? null;
    if (!$siguiente) {
        continue;
    }
    if ($bloque['tipo'] === 'DESCANSO' || $siguiente['tipo'] === 'DESCANSO') {
        $permiteDescansoPorBloque[(int) $bloque['id_bloque']] = true;
    }
    if ($bloque['tipo'] === 'DESCANSO' && $siguiente['tipo'] !== 'CLASE') {
        continue;
    }
    if ($bloque['tipo'] !== 'CLASE' && $bloque['tipo'] !== 'DESCANSO') {
        continue;
    }
    $diasLibres = 0;
    foreach ($dias as $dia) {
        $ordenDia = (int) ($dia['orden_dia'] ?? 0);
        if ($ordenDia < 1 || $ordenDia > 5) {
            continue;
        }
        $claseSiguiente = $grid[(int) $siguiente['id_bloque']][(int) $dia['id_dia']] ?? null;
        if ($siguiente['tipo'] === 'DESCANSO' || !$claseSiguiente || ($claseSiguiente['estado'] ?? '') !== 'PROGRAMADA') {
            $diasLibres++;
        }
    }
    if (($bloque['tipo'] === 'DESCANSO' && $diasLibres >= 1)
        || ($bloque['tipo'] === 'CLASE' && $diasLibres >= 3)) {
        $mostrarMasPorBloque[(int) $bloque['id_bloque']] = true;
    }
}
require dirname(__DIR__) . '/includes/header.php';

function horario_form(array $bloquesClase, array $ocupacionesPorDia, array $materias, array $profesores, array $salones, string $grado, ?array $row = null, bool $puedeRestaurarDescanso = false, bool $soloGuardarClase = false, ?int $idAdicion = null, bool $adicionReemplazaDescanso = false, bool $novedadDuranteDescanso = false): void
{
    $fijarHorario = isset($row['id_horario']);
    $claseEsIntermedia = false;
    if ($fijarHorario && isset($row['id_dia'])) {
        $clasesDelDia = $ocupacionesPorDia[(int) $row['id_dia']] ?? [];
        usort($clasesDelDia, static fn(array $primera, array $segunda): int =>
            strcmp($primera['start'], $segunda['start'])
                ?: strcmp($primera['end'], $segunda['end'])
                ?: ((int) $primera['id'] <=> (int) $segunda['id'])
        );
        $idsClasesDelDia = array_map('intval', array_column($clasesDelDia, 'id'));
        $posicionClase = array_search((int) $row['id_horario'], $idsClasesDelDia, true);
        $claseEsIntermedia = $posicionClase !== false
            && $posicionClase > 0
            && $posicionClase < count($idsClasesDelDia) - 1;
    }
    $action = $novedadDuranteDescanso
        ? 'create_break_addition'
        : ($idAdicion !== null ? 'update_addition' : (isset($row['id_horario']) ? 'update' : 'create'));
    $fecha = $row['fecha'] ?? (isset($row['id_dia']) ? fecha_proxima_dia((int) $row['id_dia']) : (new DateTimeImmutable('today'))->format('Y-m-d'));
    $est = $row['estado'] ?? 'PROGRAMADA';
    ?>
    <form method="post" data-class-editor data-availability-url="<?= e(APP_BASE) ?>/admin/horarios.php?api=salones"
        data-break-addition="<?= $novedadDuranteDescanso ? 'true' : 'false' ?>"
        data-middle-class="<?= $claseEsIntermedia ? 'true' : 'false' ?>"
        data-can-restore-break="<?= $puedeRestaurarDescanso ? 'true' : 'false' ?>"
        data-original-teacher="<?= e((string) ($row['id_profesor'] ?? '')) ?>"
        data-original-subject="<?= e((string) ($row['id_materia'] ?? '')) ?>"
        data-original-room="<?= e((string) ($row['id_salon'] ?? '')) ?>"
        data-original-teacher-name="<?= e((string) ($row['profesor'] ?? '')) ?>"
        data-original-subject-name="<?= e((string) ($row['materia'] ?? '')) ?>"
        data-original-room-name="<?= e((string) ($row['salon'] ?? '')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="restaurar_original" value="0">
        <input type="hidden" name="guardar_permanente" value="0">
        <input type="hidden" name="reprogramar_siguientes" value="0">
        <input type="hidden" name="action" value="<?= e($action) ?>">
        <?php if ($novedadDuranteDescanso): ?><input type="hidden" name="novedad_descanso" value="1"><?php endif; ?>
        <?php if ($puedeRestaurarDescanso && isset($row['id_bloque'])): ?>
            <input type="hidden" name="confirmar_conversion_descanso" value="0">
            <input type="hidden" name="convertir_dia" value="<?= (int) ($row['id_dia'] ?? 0) ?>">
            <input type="hidden" name="convertir_bloque" value="<?= (int) $row['id_bloque'] ?>">
        <?php endif; ?>
        <?php if ($soloGuardarClase && isset($row['id_dia'], $row['id_bloque'])): ?>
            <input type="hidden" name="reemplazo_descanso" value="1">
            <input type="hidden" name="reemplazo_descanso_dia" value="<?= (int) $row['id_dia'] ?>">
            <input type="hidden" name="reemplazo_descanso_bloque" value="<?= (int) $row['id_bloque'] ?>">
        <?php endif; ?>
        <?php if (isset($row['id_horario'])): ?>
            <input type="hidden" name="id_horario" value="<?= (int) $row['id_horario'] ?>">
        <?php endif; ?>
        <?php if ($idAdicion !== null): ?>
            <input type="hidden" name="id_novedad" value="<?= $idAdicion ?>">
        <?php endif; ?>
        <input type="hidden" name="id_grado" value="<?= e($grado) ?>">
        <div class="field"><label>Grado</label><input value="<?= e($grado) ?>" disabled></div>
        <div class="grid-2">
            <div class="field">
                <label>Fecha</label>
                <?php if ($fijarHorario): ?>
                    <input type="date" value="<?= e($fecha) ?>" disabled>
                    <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
                <?php else: ?>
                    <input type="date" name="fecha" min="<?= e((new DateTimeImmutable('today'))->format('Y-m-d')) ?>" required value="<?= e($fecha) ?>">
                <?php endif; ?>
            </div>
            <div class="field">
                <label>Rango de la clase</label>
                <select name="id_bloque" data-occupancies="<?= e(json_encode($ocupacionesPorDia)) ?>" required <?= $fijarHorario ? 'disabled' : '' ?>>
                    <?php if ($novedadDuranteDescanso): ?>
                        <option value="<?= (int) $row['id_bloque'] ?>" data-number="Descanso" data-start="<?= e(format_time($row['hora_inicio'])) ?>" data-end="<?= e(format_time($row['hora_fin'])) ?>" selected>Descanso (<?= e(format_time($row['hora_inicio'])) ?> - <?= e(format_time($row['hora_fin'])) ?>)</option>
                    <?php else: ?>
                        <option value="">Seleccione un rango</option>
                        <?php foreach ($bloquesClase as $b): ?>
                            <option value="<?= (int) $b['id_bloque'] ?>" data-number="<?= (int) $b['numero_bloque'] ?>" data-start="<?= e(format_time($b['hora_inicio'])) ?>" data-end="<?= e(format_time($b['hora_fin'])) ?>" <?= isset($row['id_bloque']) && (int) $row['id_bloque'] === (int) $b['id_bloque'] ? 'selected' : '' ?>>
                                <?= e(format_time($b['hora_inicio'])) ?> - <?= e(format_time($b['hora_fin'])) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if ($fijarHorario): ?><input type="hidden" name="id_bloque" value="<?= (int) $row['id_bloque'] ?>"><?php endif; ?>
            </div>
        </div>
        <small class="schedule-resource-status muted" data-class-resource-status aria-live="polite">Puede empezar por materia, profesor o salón.</small>
        <div class="field" data-class-resource>
            <label>Materia</label>
            <select name="id_materia" <?= $soloGuardarClase ? '' : 'required' ?>>
                <option value=""><?= $soloGuardarClase ? 'No aplica' : '' ?></option>
                <?php foreach ($materias as $materia): ?>
                    <option value="<?= (int) $materia['id_materia'] ?>" data-teachers="<?= e((string) ($materia['todos_profesores'] ?? '')) ?>" data-rooms="<?= e((string) ($materia['salones'] ?? '')) ?>" data-teacher-counts="<?= e((string) ($materia['conteo_profesores'] ?? '')) ?>" <?= (int) ($row['id_materia'] ?? 0) === (int) $materia['id_materia'] ? 'selected' : '' ?>><?= e($materia['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="muted" data-not-applicable hidden>No aplica</span>
        </div>
        <div class="grid-2">
            <div class="field" data-class-resource>
                <label>Profesor</label>
                <select name="id_profesor">
                    <option value=""><?= $soloGuardarClase ? 'No aplica' : '' ?></option>
                    <?php foreach ($profesores as $profesor): ?>
                        <option value="<?= (int) $profesor['id_profesor'] ?>" data-subjects="<?= e((string) ($profesor['materias_todas'] ?? '')) ?>" data-permanent-subjects="<?= e((string) ($profesor['materias_permanentes'] ?? '')) ?>" data-rooms="<?= e((string) ($profesor['salones_todos'] ?? '')) ?>" data-permanent-rooms="<?= e((string) ($profesor['salones_permanentes'] ?? '')) ?>" data-room-counts="<?= e((string) ($profesor['conteo_salones'] ?? '')) ?>" <?= (int) ($row['id_profesor'] ?? 0) === (int) $profesor['id_profesor'] ? 'selected' : '' ?>><?= e($profesor['nombre_completo']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="muted" data-not-applicable hidden>No aplica</span>
            </div>
            <div class="field" data-class-resource>
                <label>Salón</label>
                <select name="id_salon" data-searchable-room>
                    <option value=""><?= $soloGuardarClase ? 'No aplica' : '' ?></option>
                    <?= options_html($salones, 'id_salon', 'nombre', $row['id_salon'] ?? null, false) ?>
                </select>
                <span class="muted" data-not-applicable hidden>No aplica</span>
            </div>
        </div>
        <div class="field">
            <label>Estado</label>
            <select name="estado">
                <option value="PROGRAMADA" <?= $est === 'PROGRAMADA' ? 'selected' : '' ?>>PROGRAMADA</option>
                <option value="DESCANSO" <?= $est === 'DESCANSO' ? 'selected' : '' ?>>DESCANSO</option>
                <option value="CANCELADA" <?= $est === 'CANCELADA' ? 'selected' : '' ?>>CANCELADA</option>
            </select>
        </div>
        <?php if (isset($row['id_horario'])): ?>
            <input type="hidden" name="resolucion_cancelacion" value="">
            <div class="field" data-cancel-resolution-panel hidden tabindex="-1">
                <strong>La clase no se eliminará. Elige qué debe quedar en esta casilla.</strong>
                <div class="actions">
                    <button class="btn btn-secondary" type="button" data-cancel-resolution-choice="profesor">Asignar otro profesor</button>
                    <button class="btn btn-secondary" type="button" data-cancel-resolution-choice="descanso">Dejar descanso</button>
                </div>
            </div>
        <?php endif; ?>
        <div class="field">
            <div class="schedule-observation-head">
                <label>Observación</label>
                <?php if (isset($row['id_horario'])): ?>
                <label class="schedule-auto-observations"><input type="checkbox" name="auto_observaciones" checked><span>Auto observaciones</span></label>
                <?php endif; ?>
            </div>
            <textarea class="schedule-observation" name="observacion" rows="5"><?= e((string) ($row['observacion'] ?? '')) ?></textarea>
        </div>
        <div class="actions">
            <?php if ($novedadDuranteDescanso): ?>
                <button class="btn btn-primary" type="submit">Guardar novedad</button>
            <?php elseif ($soloGuardarClase): ?>
                <button class="btn btn-primary" type="submit">Guardar</button>
                <button class="btn btn-secondary" type="submit" data-save-permanently data-confirm="La clase reemplazará permanentemente el descanso solo en este día. ¿Deseas continuar?">Guardar permanente</button>
            <?php elseif ($idAdicion !== null): ?>
                <button class="btn btn-primary" type="submit">Guardar novedad</button>
            <?php else: ?>
                <button class="btn btn-primary" type="submit">Guardar</button>
            <?php endif; ?>
            <?php if ($puedeRestaurarDescanso && !isset($row['id_horario'])): ?>
                <button class="btn btn-secondary" type="button" data-convert-slot-to-break>Convertir esta casilla en descanso</button>
            <?php endif; ?>
            <?php if (isset($row['id_horario'])): ?>
                <button class="btn btn-secondary" type="submit" data-save-permanently data-confirm="Los cambios se aplicarán permanentemente al horario semanal. Se mostrarán en verde durante 3 minutos y luego quedarán guardados sin resaltado. ¿Desea continuar?">Guardar permanente</button>
            <?php elseif (!$soloGuardarClase && $idAdicion === null): ?>
                <button class="btn btn-secondary" type="submit" data-save-permanently data-confirm="La clase se agregará al horario semanal de forma permanente y se resaltará en verde durante 3 minutos. ¿Desea continuar?">Guardar permanente</button>
            <?php endif; ?>
            <?php if ($idAdicion !== null && $adicionReemplazaDescanso): ?>
                <button class="btn btn-secondary" type="submit" name="operation" value="cancel_addition" data-confirm="¿Cancelar esta novedad y volver a mostrar el descanso en esta casilla?">Convertir esta casilla en descanso</button>
            <?php endif; ?>
        </div>
    </form>
    <?php
}
?>
<section class="card">
    <div class="card-head">
        <h2>Filtros del horario semanal</h2>
        <div class="card-actions">
            <?php if ($gradosSinHorario): ?>
                <button class="btn btn-primary" type="button" data-open-modal="tpl-create-grade-schedule" data-title="Crear horario nuevo">Crear horario nuevo</button>
            <?php else: ?>
                <button class="btn btn-primary" type="button" data-no-empty-grades>Crear horario nuevo</button>
            <?php endif; ?>
            <button class="btn btn-ghost" type="button" data-toggle-schedule-filters="weekly-filters" aria-controls="weekly-filters" aria-expanded="<?= !$gradoInfo || $filtroDia || $filtroHoraInicio || $filtroHoraFin || $filtroMateria || $soloLibres ? 'true' : 'false' ?>">Filtros</button>
        </div>
    </div>
    <form class="toolbar" method="get">
        <div class="schedule-filter-panel" id="weekly-filters" <?= $gradoInfo && !$filtroDia && !$filtroHoraInicio && !$filtroHoraFin && !$filtroMateria && !$soloLibres ? 'hidden' : '' ?>>
            <div class="field">
                <label>Grado</label>
                <select name="grado">
                    <option value="">Seleccione un grado</option>
                    <?php foreach ($grados as $g): ?>
                        <option value="<?= e($g['id_grado']) ?>" <?= $grado === $g['id_grado'] ? 'selected' : '' ?>><?= e($g['id_grado']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Día</label>
                <select name="filtro_dia">
                    <option value="0">Todos los días</option>
                    <?php foreach ($dias as $dia): ?>
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
                    <?php foreach ($materias as $materia): ?>
                        <option value="<?= (int) $materia['id_materia'] ?>" <?= $filtroMateria === (int) $materia['id_materia'] ? 'selected' : '' ?>><?= e($materia['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="schedule-filter-actions">
                <label class="schedule-filter-check"><input type="checkbox" name="solo_libres" value="1" <?= $soloLibres ? 'checked' : '' ?>> Solo espacios libres</label>
                <button class="btn btn-secondary" type="submit">Aplicar filtros</button>
                <?php if ($gradoInfo): ?>
                    <button class="btn btn-primary" type="button" data-open-modal="tpl-create" data-title="Nueva clase">Nueva clase</button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</section>

<?php if ($gradosSinHorario): ?>
<template id="tpl-create-grade-schedule">
    <form method="get">
        <div class="field">
            <label>Grado sin horario</label>
            <select name="grado" required>
                <option value="">Seleccione un grado</option>
                <?php foreach ($gradosSinHorario as $gradoDisponible): ?>
                    <option value="<?= e($gradoDisponible['id_grado']) ?>"><?= e($gradoDisponible['id_grado']) ?> | Calendario <?= (int) $gradoDisponible['id_calendario'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="crear_horario" value="1">
        <div class="actions">
            <button class="btn btn-primary" type="submit">Crear horario</button>
        </div>
    </form>
</template>
<?php endif; ?>

<?php if ($gradoInfo): ?>
<section class="card">
    <div class="card-head">
        <h2>Horario de <?= e($grado) ?> <span class="muted">(<?= e(pretty_name($gradoInfo['calendario'])) ?><?= $crearHorarioNuevo ? ' · Nuevo' : '' ?>)</span></h2>
    </div>
    <div class="table-wrap schedule-wrap">
        <table class="schedule">
            <thead>
            <tr>
                <th aria-label="Agregar bloque"></th>
                <th>Hora</th>
                <?php foreach ($diasTabla as $dia): ?>
                    <th><?= e($dia['nombre']) ?></th>
                <?php endforeach; ?>
                <th aria-label="Quitar bloque"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($bloquesTabla as $bloque): ?>
                <tr>
                    <td class="schedule-block-add">
                        <?php if (!empty($mostrarMasPorBloque[(int) $bloque['id_bloque']])): ?>
                            <?php $esCambioDescanso = !empty($permiteDescansoPorBloque[(int) $bloque['id_bloque']]); ?>
                            <button class="btn btn-sm btn-ghost schedule-inline-action schedule-add-action" type="button" data-open-modal="tpl-add-calendar-block-<?= (int) $bloque['id_bloque'] ?>" data-title="<?= $esCambioDescanso ? 'Cambiar descanso' : 'Agregar bloque después' ?>" title="Agregar después de este bloque" aria-label="Agregar bloque después de <?= e(format_time($bloque['hora_fin'])) ?>">+</button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-ghost schedule-inline-action schedule-add-action" type="button" disabled title="No se puede agregar después de este bloque" aria-label="No se puede agregar después de <?= e(format_time($bloque['hora_fin'])) ?>">+</button>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= e(format_time($bloque['hora_inicio'])) ?> - <?= e(format_time($bloque['hora_fin'])) ?></strong><br>
                        <small><?= e($bloque['tipo'] === 'DESCANSO' ? 'Descanso' : 'Bloque ' . $bloque['numero_bloque']) ?></small>
                    </td>
                    <?php foreach ($diasTabla as $dia): ?>
                        <td>
                            <?php
                            $cell = $grid[(int) $bloque['id_bloque']][(int) $dia['id_dia']] ?? null;
                            $novedad = $novedadesPorDiaBloque[$dia['id_dia'] . ':' . $bloque['id_bloque']] ?? null;
                            $esAdicionActiva = $novedad
                                && ($novedad['tipo_cambio'] ?? '') === 'ADICION'
                                && $novedad['estado'] === 'ACTIVA';
                            $estadoVisible = $novedad && ($novedad['estado'] ?? '') === 'ACTIVA'
                                && ($novedad['nuevo_estado'] ?? null) !== null
                                ? $novedad['nuevo_estado']
                                : ($cell['estado'] ?? 'PROGRAMADA');
                            $esLibre = (!$cell && !$esAdicionActiva) || $estadoVisible === 'DESCANSO';
                            $esCanceladaEnExtremo = false;
                            if ($cell && ($cell['estado'] ?? '') === 'CANCELADA') {
                                $horaInicioCancelada = format_time($cell['hora_inicio']);
                                $limitesJornada = $limitesJornadaPorDia[(int) $dia['id_dia']] ?? null;
                                $esCanceladaEnExtremo = !$limitesJornada
                                    || $horaInicioCancelada <= $limitesJornada['primera_hora']
                                    || $horaInicioCancelada >= $limitesJornada['ultima_hora'];
                            }
                                $marcaTemporalCancelacion = $esCanceladaEnExtremo && !empty($novedad['temporal'])
                                    ? (int) $novedad['expira_timestamp'] * 1000
                                    : null;
                            ?>
                            <?php if ($esCanceladaEnExtremo): ?>
                                    <div class="cell-empty <?= $marcaTemporalCancelacion ? 'is-temporary' : '' ?>"
                                        <?= $marcaTemporalCancelacion ? 'data-expire-at="' . $marcaTemporalCancelacion . '"' : '' ?>
                                        data-open-modal="tpl-edit-<?= (int) $cell['id_horario'] ?>"
                                        data-title="Editar clase cancelada"
                                        aria-label="Editar la clase cancelada del <?= e($dia['nombre']) ?>">+</div>
                            <?php elseif ($bloque['tipo'] === 'DESCANSO'): ?>
                                <button class="cell-break cell-break-action" type="button"
                                    data-open-modal="tpl-break-addition-<?= (int) $bloque['id_bloque'] ?>-<?= (int) $dia['id_dia'] ?>"
                                    data-title="Agregar clase durante el descanso"
                                    aria-label="Agregar clase durante el descanso del <?= e($dia['nombre']) ?>">Descanso</button>
                            <?php elseif ($estadoVisible === 'DESCANSO'): ?>
                                <?php
                                $plantillaDescanso = $esAdicionActiva
                                    ? 'tpl-addition-' . (int) $novedad['id_novedad']
                                    : ($cell
                                        ? 'tpl-edit-' . (int) $cell['id_horario']
                                        : 'tpl-create-' . (int) $bloque['id_bloque'] . '-' . (int) $dia['id_dia']);
                                $descansoTemporal = !empty($novedad['temporal']);
                                $descansoNovedad = !$descansoTemporal
                                    && ($novedad['estado'] ?? '') === 'ACTIVA';
                                ?>
                                <button class="cell-break cell-break-action <?= $descansoTemporal ? 'is-temporary' : ($descansoNovedad ? 'is-modified' : '') ?>" type="button"
                                    <?= $descansoTemporal ? 'data-expire-at="' . (int) $novedad['expira_timestamp'] * 1000 . '"' : '' ?>
                                    data-open-modal="<?= e($plantillaDescanso) ?>"
                                    data-title="Editar descanso"
                                    aria-label="Editar descanso del <?= e($dia['nombre']) ?>">Descanso</button>
                            <?php elseif (!empty($descansosPorDiaBloque[(int) $bloque['id_bloque']][(int) $dia['id_dia']]) && !$esAdicionActiva): ?>
                                <button class="cell-break cell-break-action" type="button"
                                    data-open-modal="tpl-create-<?= (int) $bloque['id_bloque'] ?>-<?= (int) $dia['id_dia'] ?>"
                                    data-title="Reemplazar descanso"
                                    aria-label="Reemplazar descanso del <?= e($dia['nombre']) ?>">Descanso</button>
                            <?php else:
                                $materiaIdVisible = $esLibre ? 0 : ($novedad && $novedad['cambio_materia']
                                    ? (int) ($novedad['nueva_materia'] ?? 0)
                                    : (int) ($cell['id_materia'] ?? 0));
                                if (($soloLibres && !$esLibre) || ($filtroMateria && $cell && $materiaIdVisible !== $filtroMateria)):
                                    ?>
                                    <div class="cell-filtered" aria-hidden="true"></div>
                                <?php elseif ($cell || $novedad):
                                    $materiaVisible = $esLibre ? 'No aplica' : ($novedad && $novedad['cambio_materia'] ? ($novedad['materia_nueva'] ?: 'Sin materia') : ($cell['materia'] ?? 'Sin materia'));
                                    $profesorVisible = $esLibre ? 'No aplica' : ($novedad && $novedad['cambio_profesor'] ? ($novedad['profesor_nuevo'] ?: 'Sin profesor') : ($cell['profesor'] ?? 'Sin profesor'));
                                    $salonVisible = $esLibre ? 'No aplica' : ($novedad && $novedad['cambio_salon'] ? ($novedad['salon_nuevo'] ?: 'Sin salón') : ($cell['salon'] ?? 'Sin salón'));
                                    $horaVisible = $novedad && $novedad['nueva_hora_inicio'] && $novedad['nueva_hora_fin'] ? format_time($novedad['nueva_hora_inicio']) . ' - ' . format_time($novedad['nueva_hora_fin']) : null;
                                    $hayCambioRealNovedad = $novedad && (
                                        !empty($novedad['cambio_profesor'])
                                        || !empty($novedad['cambio_materia'])
                                        || !empty($novedad['cambio_salon'])
                                        || !empty($novedad['cambio_hora'])
                                    );
                                    $isNovedad = $novedad !== null && $hayCambioRealNovedad;
                                    $esTemporal = $isNovedad && !empty($novedad['temporal']);
                                    $esCancelada = $estadoVisible === 'CANCELADA'
                                        || ($novedad && ($novedad['estado'] ?? '') === 'ACTIVA'
                                            && preg_match('/clase cancelada/i', (string) ($novedad['observacion'] ?? '')) === 1);
                                    $observacionVisible = trim((string) ($cell['observacion'] ?? ''));
                                    if ($observacionVisible === '' && $esCancelada) {
                                        $observacionVisible = trim((string) ($novedad['observacion'] ?? ''));
                                    }
                                    $esCanceladaIntermedia = $esCancelada
                                        && strpos($observacionVisible, 'Cancelación intermedia por desactivación del profesor') !== false;
                                    $esCanceladaPendiente = $esCancelada
                                        && strpos($observacionVisible, 'Cancelación consecutiva pendiente de decisión') !== false;
                                    ?>
                                    <div class="cell-class <?= $esCancelada ? 'is-cancelled' : ($esTemporal ? 'is-temporary' : ($isNovedad ? 'is-modified' : '')) ?><?= $esCanceladaIntermedia ? ' is-midday-cancelled' : '' ?><?= $esCanceladaPendiente ? ' is-sequence-cancelled' : '' ?>" <?= $esTemporal ? 'data-expire-at="' . (int) $novedad['expira_timestamp'] * 1000 . '"' : '' ?> data-edit="<?= $esAdicionActiva ? 'tpl-addition-' . (int) $novedad['id_novedad'] : 'tpl-edit-' . (int) ($cell['id_horario'] ?? 0) ?>" data-title="<?= $esAdicionActiva ? 'Nueva clase' : 'Editar clase' ?>" role="button">
                                        <?php if ($esCancelada): ?>
                                            <strong><?= e($materiaVisible) ?></strong>
                                            <small><?= e($profesorVisible ?: 'Sin profesor') ?></small>
                                            <small><?= e($salonVisible ?: 'Sin salón') ?></small>
                                            <small>Clase cancelada</small>
                                        <?php else: ?>
                                            <strong><?= e($materiaVisible) ?></strong>
                                            <small><?= e($profesorVisible ?: 'Sin profesor') ?></small>
                                            <small><?= e($salonVisible ?: 'Sin salón') ?></small>
                                            <?php if ($horaVisible): ?><small><?= e($horaVisible) ?></small><?php endif; ?>
                                            <small><?= $esLibre ? 'DESCANSO' : ($esTemporal ? 'NOVEDAD TEMPORAL · 3 MIN' : ($isNovedad ? 'NOVEDAD' : e($estadoVisible))) ?></small>
                                        <?php endif; ?>
                                        <?php if ($esCancelada && $observacionVisible !== ''): ?><small class="schedule-novelty-note"><?= e($observacionVisible) ?></small><?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <?php $expiraHueco = $huecosTemporalesPorDiaBloque[$dia['id_dia'] . ':' . $bloque['id_bloque']] ?? null; ?>
                                    <div class="cell-empty <?= $expiraHueco ? 'is-temporary' : '' ?>" <?= $expiraHueco ? 'data-expire-at="' . $expiraHueco * 1000 . '"' : '' ?> data-open-modal="tpl-create-<?= (int) $bloque['id_bloque'] ?>-<?= (int) $dia['id_dia'] ?>" data-title="Nueva clase">+</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="schedule-block-remove">
                        <?php if ((int) ($bloque['agregado_desde_horario'] ?? 0) === 1): ?>
                            <?php $etiquetaBloqueAgregado = $bloque['tipo'] === 'DESCANSO' ? 'el descanso' : 'el bloque ' . (int) $bloque['numero_bloque']; ?>
                            <form method="post" data-confirm="¿Quitar <?= e($etiquetaBloqueAgregado) ?>? Si forma parte de una clase continua sin cambios, el tramo se restaurará automáticamente.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_calendar_block">
                                <input type="hidden" name="id_grado" value="<?= e($grado) ?>">
                                <input type="hidden" name="id_bloque" value="<?= (int) $bloque['id_bloque'] ?>">
                                <button class="btn btn-sm btn-danger schedule-inline-action schedule-remove-action" type="submit" title="Quitar <?= e($etiquetaBloqueAgregado) ?>" aria-label="Quitar <?= e($etiquetaBloqueAgregado) ?>">-</button>
                            </form>
                        <?php else: ?>
                            <button class="btn btn-sm btn-danger schedule-inline-action schedule-remove-action" type="button" disabled title="Solo se pueden quitar bloques agregados" aria-label="El bloque <?= (int) $bloque['numero_bloque'] ?> no se puede quitar">-</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php foreach ($bloquesTabla as $bloque): ?>
<?php if (!empty($mostrarMasPorBloque[(int) $bloque['id_bloque']]) || $bloque['tipo'] === 'DESCANSO'): ?>
<template id="tpl-add-calendar-block-<?= (int) $bloque['id_bloque'] ?>">
    <?php $permiteDescanso = $bloque['tipo'] !== 'DESCANSO' && !empty($permiteDescansoPorBloque[(int) $bloque['id_bloque']]); ?>
    <form method="post" data-calendar-block-form data-allow-rest="<?= $permiteDescanso ? 'true' : 'false' ?>" data-calendar-blocks="<?= e(json_encode($bloquesParaPlanificar, JSON_THROW_ON_ERROR)) ?>">
        <?php $horaInicioBloqueNuevo = $bloque['tipo'] === 'DESCANSO' ? $bloque['hora_inicio'] : $bloque['hora_fin']; ?>
        <?php $horaInicioMinimaBloqueNuevo = $bloque['tipo'] === 'DESCANSO' ? $bloque['hora_inicio'] : $bloque['hora_fin']; ?>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_calendar_block">
        <input type="hidden" name="confirmar_reparticion" value="0">
        <input type="hidden" name="id_grado" value="<?= e($grado) ?>">
        <input type="hidden" name="id_bloque_despues" value="<?= (int) $bloque['id_bloque'] ?>">
        <div class="field">
            <label>Tipo de bloque</label>
            <select name="tipo" required>
                <option value="CLASE">Clase</option>
                <?php if ($permiteDescanso): ?>
                    <option value="DESCANSO">Descanso</option>
                <?php endif; ?>
            </select>
        </div>
        <div class="grid-2">
                <div class="field"><label>Hora de inicio</label><input type="time" name="hora_inicio" min="<?= e(format_time($horaInicioMinimaBloqueNuevo)) ?>" value="<?= e(format_time($horaInicioBloqueNuevo)) ?>" required></div>
            <div class="field"><label>Hora de finalización</label><input type="time" name="hora_fin" required></div>
        </div>
        <div class="field"><label>Descripción</label><input name="descripcion" maxlength="<?= LIMITE_CARACTERES_DESCRIPCION_BLOQUE ?>" placeholder="Por ejemplo, Clase de la tarde"><small>Máximo <?= LIMITE_PALABRAS_DESCRIPCION_BLOQUE ?> palabras y <?= LIMITE_CARACTERES_DESCRIPCION_BLOQUE ?> caracteres.</small></div>
        <div class="actions"><button class="btn btn-primary" type="submit">Agregar después de este bloque</button></div>
    </form>
</template>
<?php endif; ?>
<?php endforeach; ?>

<?php foreach ($bloquesTabla as $bloque): ?>
    <?php if ($bloque['tipo'] === 'DESCANSO'): ?>
        <?php foreach ($diasTabla as $dia): ?>
            <template id="tpl-break-addition-<?= (int) $bloque['id_bloque'] ?>-<?= (int) $dia['id_dia'] ?>">
                <?php horario_form($bloquesClase, $ocupacionesPorDia, $materias, $profesores, $salones, $grado, [
                    'id_dia' => (int) $dia['id_dia'],
                    'id_bloque' => (int) $bloque['id_bloque'],
                    'id_materia' => null,
                    'id_profesor' => null,
                    'id_salon' => null,
                    'hora_inicio' => $bloque['hora_inicio'],
                    'hora_fin' => $bloque['hora_fin'],
                    'estado' => 'DESCANSO',
                    'observacion' => '',
                ], false, false, null, false, true); ?>
            </template>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endforeach; ?>

<template id="tpl-create">
    <?php horario_form($bloquesClase, $ocupacionesPorDia, $materias, $profesores, $salones, $grado); ?>
</template>
<?php foreach ($bloquesClase as $bloque): ?>
    <?php foreach ($dias as $dia): ?>
        <template id="tpl-create-<?= (int) $bloque['id_bloque'] ?>-<?= (int) $dia['id_dia'] ?>">
            <?php
            $esDescansoConvertido = !empty($descansosPorDiaBloque[(int) $bloque['id_bloque']][(int) $dia['id_dia']]);
            $puedeConvertirEnDescanso = isset($bloquesDescansoPartidos[(int) $bloque['id_bloque']])
                && !isset($grid[(int) $bloque['id_bloque']][(int) $dia['id_dia']])
                && !$esDescansoConvertido;
            horario_form($bloquesClase, $ocupacionesPorDia, $materias, $profesores, $salones, $grado, [
                'id_dia' => $dia['id_dia'],
                'id_bloque' => $bloque['id_bloque'],
                'id_materia' => null,
                'id_profesor' => null,
                'id_salon' => null,
                'estado' => $esDescansoConvertido ? 'DESCANSO' : 'PROGRAMADA',
                'observacion' => '',
            ], $puedeConvertirEnDescanso, $esDescansoConvertido);
            ?>
        </template>
    <?php endforeach; ?>
<?php endforeach; ?>

<?php foreach ($grid as $bloqueId => $byDay): ?>
    <?php foreach ($byDay as $clase): ?>
        <template id="tpl-edit-<?= (int) $clase['id_horario'] ?>">
            <?php
            $claseParaEditar = $clase;
            $novedadClase = $novedadesPorDiaBloque[(int) $clase['id_dia'] . ':' . (int) $clase['id_bloque']] ?? null;
            if ($novedadClase && ($novedadClase['estado'] ?? '') === 'ACTIVA'
                && ($novedadClase['nuevo_estado'] ?? null) !== null) {
                $claseParaEditar['estado'] = $novedadClase['nuevo_estado'];
            }
            if (($clase['estado'] ?? '') === 'CANCELADA' && trim((string) ($clase['observacion'] ?? '')) === '' && $novedadClase) {
                $claseParaEditar['observacion'] = (string) ($novedadClase['observacion'] ?? '');
            }
            horario_form($bloquesClase, $ocupacionesPorDia, $materias, $profesores, $salones, $grado, $claseParaEditar);
            ?>
        </template>
    <?php endforeach; ?>
<?php endforeach; ?>

<?php foreach ($novedadesActivas as $adicion): ?>
    <?php if (($adicion['tipo_cambio'] ?? '') === 'ADICION' && ($adicion['estado'] ?? '') === 'ACTIVA' && (string) ($adicion['id_grado'] ?? '') === $grado): ?>
        <?php
        $observacionAdicion = '';
        if (preg_match('/(?:^|\n)Observación:\s*(.*)$/su', (string) ($adicion['observacion'] ?? ''), $matches) === 1) {
            $observacionAdicion = trim($matches[1]);
        }
        $adicionParaEditar = [
            'id_dia' => (int) $adicion['id_dia'],
            'id_bloque' => (int) $adicion['id_bloque'],
            'id_materia' => (int) $adicion['nueva_materia'],
            'id_profesor' => (int) ($adicion['nuevo_profesor'] ?? 0),
            'id_salon' => (int) ($adicion['nuevo_salon'] ?? 0),
            'estado' => $adicion['nuevo_estado'] ?? 'PROGRAMADA',
            'fecha' => $adicion['fecha'],
            'observacion' => $observacionAdicion,
        ];
        $adicionReemplazaDescanso = !empty($descansosPorDiaBloque[(int) $adicion['id_bloque']][(int) $adicion['id_dia']]);
        ?>
        <template id="tpl-addition-<?= (int) $adicion['id_novedad'] ?>">
            <?php horario_form($bloquesClase, $ocupacionesPorDia, $materias, $profesores, $salones, $grado, $adicionParaEditar, false, true, (int) $adicion['id_novedad'], $adicionReemplazaDescanso); ?>
        </template>
    <?php endif; ?>
<?php endforeach; ?>

<?php else: ?>
<section class="empty-state-panel" aria-labelledby="horario-empty-title">
    <div class="empty-state-icon" aria-hidden="true">📅</div>
    <p class="empty-state-eyebrow">SEMANA ACTUAL</p>
    <h2 id="horario-empty-title">Selecciona un grado</h2>
    <p>El horario semanal aparecerá aquí con sus clases, docentes y salones.</p>
</section>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
