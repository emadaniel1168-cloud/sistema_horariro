<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin_auth();

$sectionTitle = 'Materias';
asegurar_esquema_novedades();
asegurar_tabla_materia_profesores();
asegurar_tabla_novedades_temporales();
asegurar_tabla_horario_original_novedad();

if (get('api') === 'clases_profesor_materia') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $materiaId = (int) get('id_materia', '0');
        $profesorId = (int) get('id_profesor', '0');
        if (!$materiaId || !$profesorId || !exists_id('materias', 'id_materia', $materiaId) || !exists_id('profesores', 'id_profesor', $profesorId)) {
            throw new RuntimeException('Seleccione una materia y un profesor válidos.');
        }
        $clases = db_all(
                'SELECT h.id_horario, h.id_grado, h.id_dia, h.id_bloque, h.id_materia, h.id_salon,
                    m.nombre AS materia,
                    d.nombre AS dia, TIME_FORMAT(b.hora_inicio, \'%H:%i\') AS hora_inicio,
                    TIME_FORMAT(b.hora_fin, \'%H:%i\') AS hora_fin, COALESCE(s.nombre, \'Sin salón\') AS salon
             FROM horario_semanal h
             JOIN dias_semana d ON d.id_dia = h.id_dia
             JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                 JOIN materias m ON m.id_materia = h.id_materia
             LEFT JOIN salones s ON s.id_salon = h.id_salon
             WHERE h.id_materia = ? AND h.id_profesor = ? AND h.estado = \'PROGRAMADA\'
             ORDER BY h.id_grado, d.orden_dia, b.hora_inicio',
            [$materiaId, $profesorId]
        );
        echo json_encode(['clases' => $clases], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (get('api') === 'clases_materia') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $materiaId = (int) get('id_materia', '0');
        if (!$materiaId || !exists_id('materias', 'id_materia', $materiaId)) {
            throw new RuntimeException('Seleccione una materia válida.');
        }
        $clases = db_all(
            'SELECT h.id_horario, h.id_grado, h.id_dia, h.id_materia, h.id_profesor, h.id_salon, h.estado,
                    m.nombre AS materia,
                    d.nombre AS dia, TIME_FORMAT(b.hora_inicio, \'%H:%i\') AS hora_inicio,
                    TIME_FORMAT(b.hora_fin, \'%H:%i\') AS hora_fin,
                    p.nombre_completo AS profesor,
                    COALESCE(s.nombre, \'Sin salón\') AS salon
             FROM horario_semanal h
             JOIN dias_semana d ON d.id_dia = h.id_dia
             JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
             JOIN materias m ON m.id_materia = h.id_materia
             LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
             LEFT JOIN salones s ON s.id_salon = h.id_salon
             WHERE h.id_materia = ? AND h.estado = \'PROGRAMADA\'
             ORDER BY h.id_grado, d.orden_dia, b.hora_inicio',
            [$materiaId]
        );
        echo json_encode(['clases' => $clases], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action', '');

    try {
        if ($action === 'replace_professor') {
            $id = (int) post('id_materia', '0');
            $nombre = post('nombre');
            $activa = post('activa') === '1' ? 1 : 0;
            $profesorOriginalId = (int) post('profesor_a_quitar', '0');
            $eliminarPermanente = post('eliminar_permanente', '') === '1';
            $reemplazos = $_POST['reemplazos'] ?? [];
            if (!$id || !$nombre || !$profesorOriginalId || !is_array($reemplazos)) {
                throw new RuntimeException('No se recibieron todos los datos para reemplazar al profesor.');
            }

            asegurar_tabla_cambios_horario();
            db()->beginTransaction();
            try {
                $clases = db_all(
                            'SELECT h.id_horario, h.id_grado, h.id_dia, h.id_bloque, h.id_materia, h.id_salon,
                                m.nombre AS materia_actual, s.nombre AS salon_actual, p.nombre_completo AS profesor_actual,
                                b.hora_inicio, b.hora_fin
                     FROM horario_semanal h
                     JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                         JOIN materias m ON m.id_materia = h.id_materia
                         LEFT JOIN salones s ON s.id_salon = h.id_salon
                             LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
                     WHERE h.id_materia = ? AND h.id_profesor = ? AND h.estado = \'PROGRAMADA\'
                     ORDER BY h.id_grado, h.id_dia, h.id_bloque FOR UPDATE',
                    [$id, $profesorOriginalId]
                );
                if ($clases === []) {
                    throw new RuntimeException('El profesor ya no tiene clases programadas que necesiten reemplazo.');
                }

                $claseIds = array_fill_keys(array_map('intval', array_column($clases, 'id_horario')), true);
                $gruposPorGrado = [];
                foreach ($clases as $clase) {
                    $gradoClave = (string) $clase['id_grado'];
                    $gruposPorGrado[$gradoClave] ??= bin2hex(random_bytes(16));
                }
                $clasesReemplazadas = 0;
                foreach ($clases as $clase) {
                    $horarioId = (int) $clase['id_horario'];
                    $grupoNovedad = $gruposPorGrado[(string) $clase['id_grado']];
                    if (!array_key_exists($horarioId, $reemplazos) && !array_key_exists((string) $horarioId, $reemplazos)) {
                        throw new RuntimeException('Seleccione una acción para cada clase.');
                    }
                    $reemplazo = $reemplazos[$horarioId] ?? $reemplazos[(string) $horarioId] ?? null;
                    if (!is_array($reemplazo)) {
                        throw new RuntimeException('Complete la acción para cada clase.');
                    }
                    $accion = strtolower((string) ($reemplazo['accion'] ?? 'reemplazar'));
                    if ($accion === 'cancelar') {
                        if ($eliminarPermanente) {
                            eliminar_horario_original_novedad($horarioId);
                        } else {
                            guardar_horario_original_novedad($horarioId);
                        }
                        registrar_cambio_horario($horarioId, 'CANCELACION', 'Clase cancelada desde el horario semanal.');
                        db_exec(
                            'UPDATE horario_semanal SET estado = "CANCELADA", id_profesor = NULL, id_salon = NULL WHERE id_horario = ? AND id_profesor = ? AND id_materia = ?',
                            [$horarioId, $profesorOriginalId, $id]
                        );
                        registrar_novedad_cancelacion_horario(
                            $horarioId,
                            $grupoNovedad,
                            'Clase cancelada desde el horario semanal.',
                            (new DateTimeImmutable('today'))->format('Y-m-d')
                        );
                        $clasesReemplazadas++;
                        continue;
                    }
                    $profesorNuevoId = (int) ($reemplazo['profesor'] ?? 0);
                    $materiaNuevaId = (int) ($reemplazo['materia'] ?? 0);
                    $salonNuevoRaw = trim((string) ($reemplazo['salon'] ?? ''));
                    $salonNuevoId = $salonNuevoRaw !== '' ? (int) $salonNuevoRaw : null;
                    if (!$profesorNuevoId || $profesorNuevoId === $profesorOriginalId
                        || db_count('SELECT COUNT(*) AS total FROM profesores WHERE id_profesor = ? AND activo = 1', [$profesorNuevoId]) === 0) {
                        throw new RuntimeException('Seleccione un profesor activo distinto al profesor que se está retirando.');
                    }
                    if (!$materiaNuevaId || db_count('SELECT COUNT(*) AS total FROM materias WHERE id_materia = ? AND activa = 1', [$materiaNuevaId]) === 0) {
                        throw new RuntimeException('Seleccione una materia activa para cada clase.');
                    }
                    if ($salonNuevoId !== null && db_count('SELECT COUNT(*) AS total FROM salones WHERE id_salon = ? AND activo = 1', [$salonNuevoId]) === 0) {
                        throw new RuntimeException('Seleccione un salón activo para cada clase.');
                    }
                    validar_disponibilidad_horario(
                        (int) $clase['id_dia'],
                        $clase['hora_inicio'],
                        $clase['hora_fin'],
                        $profesorNuevoId,
                        $salonNuevoId,
                        $horarioId
                    );
                    registrar_cambio_horario($horarioId, 'CAMBIO', 'Profesor, materia o salón reemplazado desde el formulario de materias.');
                    db_exec(
                        'INSERT IGNORE INTO materia_profesores (id_materia, id_profesor) VALUES (?, ?)',
                        [$materiaNuevaId, $profesorNuevoId]
                    );
                    if ($eliminarPermanente) {
                        eliminar_horario_original_novedad($horarioId);
                    } else {
                        guardar_horario_original_novedad($horarioId);
                    }
                    db_exec(
                        'UPDATE horario_semanal SET id_profesor = ?, id_materia = ?, id_salon = ? WHERE id_horario = ? AND id_profesor = ? AND id_materia = ?',
                        [$profesorNuevoId, $materiaNuevaId, $salonNuevoId, $horarioId, $profesorOriginalId, $id]
                    );
                    db_exec(
                        "UPDATE novedades_cambios_horario SET estado = 'CANCELADA'
                         WHERE id_horario = ? AND estado = 'ACTIVA' AND nuevo_profesor IS NULL
                           AND observacion LIKE 'Profesor eliminado de la materia %'",
                        [$horarioId]
                    );
                    db_exec('DELETE FROM novedades_temporales WHERE id_horario = ?', [$horarioId]);
                    $yaExisteNovedad = db_one(
                            'SELECT id_cambio FROM novedades_cambios_horario WHERE id_horario = ? AND fecha = CURDATE() AND estado = "ACTIVA"',
                        [$horarioId]
                    );
                    $profesorNuevo = db_one('SELECT nombre_completo FROM profesores WHERE id_profesor = ?', [$profesorNuevoId]);
                    $detalleCambioProfesor = 'Profesor: ' . ($clase['profesor_actual'] ?: 'Sin profesor')
                        . ' → ' . ($profesorNuevo['nombre_completo'] ?? 'Profesor nuevo')
                        . ($eliminarPermanente ? ' · Cambio guardado permanentemente.' : '');
                    if (!$yaExisteNovedad) {
                        db_exec(
                            'INSERT INTO novedades_cambios_horario (tipo_cambio, grupo_novedad, id_horario, fecha, nuevo_profesor, nueva_materia, nuevo_salon, observacion, estado)
                             VALUES ("NOVEDAD", ?, ?, CURDATE(), ?, ?, ?, ?, "ACTIVA")',
                            [$grupoNovedad, $horarioId, $profesorNuevoId, $materiaNuevaId, $salonNuevoId,
                                $detalleCambioProfesor]
                        );
                    } else {
                        db_exec(
                            'UPDATE novedades_cambios_horario
                             SET grupo_novedad = ?, nuevo_profesor = ?, nueva_materia = ?, nuevo_salon = ?, observacion = ?
                             WHERE id_cambio = ?',
                            [$grupoNovedad, $profesorNuevoId, $materiaNuevaId, $salonNuevoId, $detalleCambioProfesor, $yaExisteNovedad['id_cambio']]
                        );
                    }
                    $clasesReemplazadas++;
                }

                foreach (array_keys($reemplazos) as $horarioId) {
                    if (!isset($claseIds[(int) $horarioId])) {
                        throw new RuntimeException('La lista de reemplazos incluye una clase que no pertenece a esta operación.');
                    }
                }
                db_exec('UPDATE materias SET nombre = ?, activa = ? WHERE id_materia = ?', [$nombre, $activa, $id]);
                db_exec('DELETE FROM materia_profesores WHERE id_materia = ? AND id_profesor = ?', [$id, $profesorOriginalId]);
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
            flash_set('success', 'Profesor reemplazado en ' . $clasesReemplazadas . ' clase(s). El horario quedó actualizado correctamente.');
        } elseif ($action === 'create') {
            $nombre = post('nombre');
            if (!$nombre) {
                throw new RuntimeException('El nombre de la materia es obligatorio.');
            }
            db_exec('INSERT INTO materias (nombre, activa) VALUES (?, 1)', [$nombre]);
            flash_set('success', 'Materia creada correctamente.');
        } elseif ($action === 'update') {
            $id = (int) post('id_materia', '0');
            $nombre = post('nombre');
            $activa = post('activa') === '1' ? 1 : 0;
            $profesorId = (int) post('id_profesor', '0');
            $profesorAEliminarRaw = post('profesor_a_quitar', '');
            $eliminarPermanente = post('eliminar_permanente', '') === '1';
            $guardarPermanente = $profesorId > 0 && $profesorAEliminarRaw === '';
            if (!$id || !$nombre) {
                throw new RuntimeException('Debe indicar el nombre de la materia.');
            }
            if ($profesorAEliminarRaw !== '' && $profesorId > 0) {
                throw new RuntimeException('No se puede agregar y quitar un profesor en la misma operación.');
            }
            if ($guardarPermanente && $profesorId === 0) {
                throw new RuntimeException('Seleccione un profesor para guardar el vínculo permanente.');
            }

            $clasesAgregadas = 0;
            if ($profesorId > 0 && $profesorAEliminarRaw === '') {
                asegurar_tabla_cambios_horario();
            }
            db()->beginTransaction();
            try {
                if ($profesorId > 0 && $profesorAEliminarRaw === '') {
                    if (!exists_id('profesores', 'id_profesor', $profesorId)) {
                        throw new RuntimeException('El profesor seleccionado no existe.');
                    }
                    if ($guardarPermanente) {
                        db_exec(
                            'INSERT IGNORE INTO materia_profesores (id_materia, id_profesor) VALUES (?, ?)',
                            [$id, $profesorId]
                        );
                    }
                    $yaAsignado = db_count(
                        'SELECT COUNT(*) AS total FROM horario_semanal WHERE id_materia = ? AND id_profesor = ?',
                        [$id, $profesorId]
                    ) > 0;
                    if (!$yaAsignado || $guardarPermanente) {
                        $clasesSinProfesor = $guardarPermanente
                            ? db_all(
                                'SELECT id_horario, id_profesor FROM horario_semanal
                                 WHERE id_materia = ? AND estado = \'PROGRAMADA\'
                                   AND (id_profesor IS NULL OR id_profesor = ?)
                                 FOR UPDATE',
                                [$id, $profesorId]
                            )
                            : db_all(
                                'SELECT id_horario, id_profesor FROM horario_semanal
                                 WHERE id_materia = ? AND id_profesor IS NULL AND estado = \'PROGRAMADA\'
                                 FOR UPDATE',
                                [$id]
                            );
                        if (!$clasesSinProfesor && !$yaAsignado && !$guardarPermanente) {
                            throw new RuntimeException('No hay clases programadas de esta materia sin profesor.');
                        }
                        $grupoTemporal = $guardarPermanente ? bin2hex(random_bytes(16)) : null;
                        $nombreProfesor = $guardarPermanente
                            ? (db_one('SELECT nombre_completo FROM profesores WHERE id_profesor = ?', [$profesorId])['nombre_completo'] ?? 'Profesor')
                            : '';
                        foreach ($clasesSinProfesor as $clase) {
                            if ($clase['id_profesor'] === null) {
                                if ($guardarPermanente) {
                                    eliminar_horario_original_novedad((int) $clase['id_horario']);
                                } else {
                                    guardar_horario_original_novedad((int) $clase['id_horario']);
                                }
                                db_exec(
                                    'UPDATE horario_semanal SET id_profesor = ? WHERE id_horario = ? AND id_profesor IS NULL',
                                    [$profesorId, $clase['id_horario']]
                                );
                                registrar_cambio_horario((int) $clase['id_horario'], 'CAMBIO', 'Profesor agregado a la materia desde el formulario de materias.');
                            }
                            if ($guardarPermanente) {
                                db_exec(
                                    "UPDATE novedades_cambios_horario SET estado = 'CANCELADA'
                                     WHERE id_horario = ? AND estado = 'ACTIVA' AND nuevo_profesor IS NULL
                                       AND observacion LIKE 'Profesor eliminado de la materia %'",
                                    [$clase['id_horario']]
                                );
                                db_exec('DELETE FROM novedades_temporales WHERE id_horario = ?', [$clase['id_horario']]);
                                db_exec(
                                    'INSERT INTO novedades_temporales (grupo_novedad, id_horario, expira_en, observacion)
                                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 20 SECOND), ?)',
                                    [
                                        $grupoTemporal,
                                        $clase['id_horario'],
                                        'Profesor asignado: ' . $nombreProfesor . '. Vínculo permanente con la materia ' . $nombre . '.',
                                    ]
                                );
                            } elseif (!db_one(
                                'SELECT id_cambio FROM novedades_cambios_horario WHERE id_horario = ? AND fecha = CURDATE() AND estado = "ACTIVA"',
                                [$clase['id_horario']]
                            )) {
                                db_exec(
                                    'INSERT INTO novedades_cambios_horario (tipo_cambio, id_horario, fecha, nuevo_profesor, nueva_materia, nuevo_salon, observacion, estado)
                                     VALUES ("NOVEDAD", ?, CURDATE(), ?, NULL, NULL, ?, "ACTIVA")',
                                    [$clase['id_horario'], $profesorId, 'Profesor agregado a la materia desde el formulario de materias.']
                                );
                            }
                        }
                        $clasesAgregadas = count($clasesSinProfesor);
                    }
                }
                if ($profesorAEliminarRaw !== '') {
                    $profesorParaQuitar = (int) $profesorAEliminarRaw;
                    if (!$profesorParaQuitar || !exists_id('profesores', 'id_profesor', $profesorParaQuitar)) {
                        throw new RuntimeException('Debe seleccionar un profesor válido para quitar de esta materia.');
                    }
                    $clasesDelProfesor = db_count(
                        'SELECT COUNT(*) AS total FROM horario_semanal WHERE id_materia = ? AND id_profesor = ?',
                        [$id, $profesorParaQuitar]
                    );
                    $vinculoPermanente = db_count(
                        'SELECT COUNT(*) AS total FROM materia_profesores WHERE id_materia = ? AND id_profesor = ?',
                        [$id, $profesorParaQuitar]
                    );
                    if ($clasesDelProfesor === 0 && $vinculoPermanente === 0) {
                        throw new RuntimeException('El profesor seleccionado no está asignado a esta materia.');
                    }
                    if ($clasesDelProfesor === 0 && !$eliminarPermanente) {
                        throw new RuntimeException('No hay clases actuales para quitar. Use Eliminar permanente para borrar el vínculo futuro.');
                    }
                    if ($clasesDelProfesor > 0) {
                        registrar_novedad_por_desvincular_profesor('materia', $id, $profesorParaQuitar);
                    }
                    db_exec(
                        'DELETE t FROM novedades_temporales t
                         JOIN horario_semanal h ON h.id_horario = t.id_horario
                         WHERE h.id_materia = ? AND h.id_profesor = ?',
                        [$id, $profesorParaQuitar]
                    );
                    db_exec('UPDATE horario_semanal SET id_profesor = NULL WHERE id_materia = ? AND id_profesor = ?', [$id, $profesorParaQuitar]);
                    db_exec('DELETE FROM materia_profesores WHERE id_materia = ? AND id_profesor = ?', [$id, $profesorParaQuitar]);
                }
                db_exec('UPDATE materias SET nombre = ?, activa = ? WHERE id_materia = ?', [$nombre, $activa, $id]);
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
            if ($profesorAEliminarRaw !== '') {
                flash_set(
                    'success',
                    $eliminarPermanente
                        ? 'Profesor quitado de las clases actuales y vínculo permanente eliminado.'
                        : 'Profesor quitado de las clases actuales. Se conserva el vínculo para futuras clases.'
                );
            } else {
                flash_set(
                    'success',
                    $guardarPermanente
                        ? 'Vínculo permanente guardado. Profesor agregado a ' . $clasesAgregadas . ' clase(s) sin profesor.'
                            . ($clasesAgregadas > 0 ? ' Las novedades temporales serán visibles durante 20 segundos; el horario conservará los cambios.' : ' No había clases sin profesor; no se generaron novedades temporales.')
                        : ($clasesAgregadas > 0
                            ? 'Profesor agregado a ' . $clasesAgregadas . ' clase(s) sin asignación. Información actualizada correctamente.'
                            : 'Información actualizada correctamente.')
                );
            }
        } elseif ($action === 'toggle') {
            $id = (int) post('id_materia', '0');
            $row = db_one('SELECT activa FROM materias WHERE id_materia = ?', [$id]);
            if (!$row) {
                throw new RuntimeException('La materia no existe.');
            }
            $next = ((int) $row['activa'] === 1) ? 0 : 1;
            db_exec('UPDATE materias SET activa = ? WHERE id_materia = ?', [$next, $id]);
            flash_set('success', 'Información actualizada correctamente.');
        } elseif ($action === 'delete_subject_with_replacements') {
            $id = (int) post('id_materia', '0');
            $reemplazos = $_POST['reemplazos'] ?? [];
            if (!$id || !is_array($reemplazos)) {
                throw new RuntimeException('Debe indicar cómo reemplazar las clases asociadas a la materia.');
            }
            $clases = db_all(
                'SELECT h.id_horario, h.id_grado, h.id_dia, h.id_profesor, h.id_salon, h.estado,
                        d.nombre AS dia, TIME_FORMAT(b.hora_inicio, \'%H:%i\') AS hora_inicio,
                        TIME_FORMAT(b.hora_fin, \'%H:%i\') AS hora_fin
                 FROM horario_semanal h
                 JOIN dias_semana d ON d.id_dia = h.id_dia
                 JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                 WHERE h.id_materia = ? AND h.estado = \'PROGRAMADA\'
                 ORDER BY h.id_grado, d.orden_dia, b.hora_inicio',
                [$id]
            );

            $gruposPorGrado = [];
            foreach ($clases as $clase) {
                $gradoClave = (string) $clase['id_grado'];
                $gruposPorGrado[$gradoClave] ??= bin2hex(random_bytes(16));
            }

            foreach ($clases as $clase) {
                $horarioId = (int) $clase['id_horario'];
                $grupoNovedad = $gruposPorGrado[(string) $clase['id_grado']];
                $reemplazo = $reemplazos[$horarioId] ?? $reemplazos[(string) $horarioId] ?? null;
                if (!is_array($reemplazo)) {
                    throw new RuntimeException('Falta definir la acción para una o más clases.');
                }
                $accion = strtolower((string) ($reemplazo['accion'] ?? 'reemplazar'));
                if ($accion === 'cancelar') {
                    eliminar_horario_original_novedad($horarioId);
                    db_exec('UPDATE horario_semanal SET estado = "CANCELADA", id_salon = NULL WHERE id_horario = ?', [$horarioId]);
                    registrar_novedad_cancelacion_horario(
                        $horarioId,
                        $grupoNovedad,
                        'Clase cancelada desde el horario semanal.',
                        (new DateTimeImmutable('today'))->format('Y-m-d')
                    );
                    continue;
                }
                $materiaNuevaId = (int) ($reemplazo['materia'] ?? 0);
                if (!$materiaNuevaId || !exists_id('materias', 'id_materia', $materiaNuevaId) || (int) $materiaNuevaId === $id) {
                    throw new RuntimeException('Seleccione una materia válida para cada clase.');
                }
                db_exec('UPDATE horario_semanal SET id_materia = ? WHERE id_horario = ?', [$materiaNuevaId, $horarioId]);
            }

            db_exec('DELETE FROM materia_profesores WHERE id_materia = ?', [$id]);
            db_exec('DELETE FROM materias WHERE id_materia = ?', [$id]);
            flash_set('success', 'Materia eliminada correctamente y las clases asociadas fueron reemplazadas o canceladas.');
        } elseif ($action === 'delete') {
            $id = (int) post('id_materia', '0');
            $used = db_count('SELECT COUNT(*) AS total FROM horario_semanal WHERE id_materia = ?', [$id]);
            if ($used > 0) {
                db_exec('UPDATE materias SET activa = 0 WHERE id_materia = ?', [$id]);
                flash_set('error', 'No se puede eliminar porque está usada en horarios. Debe reasignar o cancelar todas las clases y luego volver a intentar.');
            } else {
                db_exec('DELETE FROM materias WHERE id_materia = ?', [$id]);
                flash_set('success', 'Registro eliminado correctamente.');
            }
        }
    } catch (PDOException $e) {
        flash_set('error', unique_error($e, 'No se pudo guardar la información. Verifique los datos e inténtelo nuevamente.'));
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(APP_BASE . '/admin/materias.php');
}

$q = get('q', '');
$sql = 'SELECT m.id_materia, m.nombre, m.activa FROM materias m';
$params = [];
if ($q) {
    $sql .= ' WHERE m.nombre LIKE ?';
    $params[] = '%' . $q . '%';
}
$sql .= ' GROUP BY m.id_materia, m.nombre, m.activa ORDER BY m.nombre';
$rows = db_all($sql, $params);
$profesores = db_all('SELECT id_profesor, nombre_completo FROM profesores WHERE activo = 1 ORDER BY nombre_completo');
$materiasParaReemplazo = db_all('SELECT id_materia, nombre FROM materias WHERE activa = 1 ORDER BY nombre');
$salonesParaReemplazo = db_all("SELECT id_salon, nombre FROM salones WHERE activo = 1 ORDER BY CASE WHEN nombre REGEXP '[0-9]+$' THEN 0 ELSE 1 END, CAST(SUBSTRING_INDEX(nombre, ' ', -1) AS UNSIGNED), nombre");
$materiasPorProfesor = [];
foreach (db_all(
    'SELECT id_profesor, id_materia, COUNT(*) AS total FROM horario_semanal
     WHERE estado = \'PROGRAMADA\' GROUP BY id_profesor, id_materia'
) as $relacionProfesorMateria) {
    $materiasPorProfesor[(int) $relacionProfesorMateria['id_profesor']][(int) $relacionProfesorMateria['id_materia']] = (int) $relacionProfesorMateria['total'];
}
foreach (db_all('SELECT id_profesor, id_materia FROM materia_profesores') as $relacionProfesorMateria) {
    $materiasPorProfesor[(int) $relacionProfesorMateria['id_profesor']][(int) $relacionProfesorMateria['id_materia']] ??= 0;
}
$salonesPorProfesor = [];
$conteoSalonesProfesor = [];
foreach (db_all(
    'SELECT id_profesor, id_salon, COUNT(*) AS total FROM horario_semanal
     WHERE estado = \'PROGRAMADA\' AND id_salon IS NOT NULL
     GROUP BY id_profesor, id_salon'
) as $relacionProfesorSalon) {
    $idProfesor = (int) $relacionProfesorSalon['id_profesor'];
    $idSalon = (int) $relacionProfesorSalon['id_salon'];
    $salonesPorProfesor[$idProfesor][] = $idSalon;
    $conteoSalonesProfesor[$idProfesor][$idSalon] = max(
        $conteoSalonesProfesor[$idProfesor][$idSalon] ?? 0,
        (int) $relacionProfesorSalon['total']
    );
}
$salonesPorMateria = [];
foreach (db_all(
    'SELECT DISTINCT id_materia, id_salon FROM horario_semanal WHERE estado = \'PROGRAMADA\' AND id_salon IS NOT NULL'
) as $relacionMateriaSalon) {
    $salonesPorMateria[(int) $relacionMateriaSalon['id_materia']][] = (int) $relacionMateriaSalon['id_salon'];
}

require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card">
    <div class="card-head">
        <h2>Listado de materias</h2>
        <button class="btn btn-primary" data-open-modal="tpl-create" data-title="Nueva materia">🟢 Nuevo</button>
    </div>
    <form class="toolbar" method="get">
        <input class="search" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar materia">
        <button class="btn btn-secondary" type="submit">Filtrar</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nombre</th><th>Estado</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['nombre']) ?></td>
                    <td><?= badge_activo($row['activa']) ?></td>
                    <td class="actions">
                        <button class="btn btn-sm btn-secondary" data-edit="tpl-edit-<?= (int) $row['id_materia'] ?>" data-title="Ver materia">Ver</button>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id_materia" value="<?= (int) $row['id_materia'] ?>">
                            <button class="btn btn-sm btn-ghost" type="submit"><?= ((int) $row['activa'] === 1) ? 'Desactivar' : 'Activar' ?></button>
                        </form>
                        <form method="post" data-delete-material data-replacement-url="<?= e(APP_BASE) ?>/admin/materias.php?api=clases_materia" data-materia-name="<?= e($row['nombre']) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_materia" value="<?= (int) $row['id_materia'] ?>">
                            <select hidden data-replacement-subjects>
                                <?php foreach ($materiasParaReemplazo as $materiaReemplazo): ?>
                                    <?php if ((int) $materiaReemplazo['id_materia'] === (int) $row['id_materia']) continue; ?>
                                    <option value="<?= (int) $materiaReemplazo['id_materia'] ?>"><?= e($materiaReemplazo['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-danger" type="submit">🗑️ Eliminar</button>
                        </form>
                    </td>
                </tr>
                <template id="tpl-edit-<?= (int) $row['id_materia'] ?>">
                    <form method="post" data-professor-replacement-url="<?= e(APP_BASE) ?>/admin/materias.php?api=clases_profesor_materia" data-teacher-availability-url="<?= e(APP_BASE) ?>/admin/horarios.php?api=salones">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id_materia" value="<?= (int) $row['id_materia'] ?>">
                        <input type="hidden" name="eliminar_permanente" value="0">
                        <input type="hidden" name="reemplazos" value="">
                        <div hidden aria-hidden="true">
                            <select data-replacement-subjects>
                                <?php foreach ($materiasParaReemplazo as $materiaReemplazo): ?>
                                    <option value="<?= (int) $materiaReemplazo['id_materia'] ?>" data-rooms="<?= e(implode(',', $salonesPorMateria[(int) $materiaReemplazo['id_materia']] ?? [])) ?>"><?= e($materiaReemplazo['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select data-replacement-professors>
                                <?php foreach ($profesores as $profesorReemplazo): ?>
                                    <?php
                                        $materiasProfesor = $materiasPorProfesor[(int) $profesorReemplazo['id_profesor']] ?? [];
                                        $materiasProfesorIds = array_keys($materiasProfesor);
                                        $conteoMateriasProfesor = [];
                                        foreach ($materiasProfesor as $idMateriaProfesor => $totalClasesProfesor) {
                                            $conteoMateriasProfesor[] = $idMateriaProfesor . ':' . $totalClasesProfesor;
                                        }
                                    ?>
                                    <?php
                                        $conteoSalonesProfesorActual = [];
                                        foreach (($conteoSalonesProfesor[(int) $profesorReemplazo['id_profesor']] ?? []) as $idSalon => $totalSalon) {
                                            $conteoSalonesProfesorActual[] = $idSalon . ':' . $totalSalon;
                                        }
                                    ?>
                                    <option value="<?= (int) $profesorReemplazo['id_profesor'] ?>" data-subjects="<?= e(implode(',', $materiasProfesorIds)) ?>" data-teacher-counts="<?= e(implode(',', $conteoMateriasProfesor)) ?>" data-room-counts="<?= e(implode(',', $conteoSalonesProfesorActual)) ?>" data-rooms="<?= e(implode(',', array_unique($salonesPorProfesor[(int) $profesorReemplazo['id_profesor']] ?? []))) ?>"><?= e($profesorReemplazo['nombre_completo']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select data-replacement-rooms>
                                <?php foreach ($salonesParaReemplazo as $salonReemplazo): ?>
                                    <option value="<?= (int) $salonReemplazo['id_salon'] ?>"><?= e($salonReemplazo['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php
                            $profesoresMateria = db_all(
                                'SELECT id_profesor FROM horario_semanal WHERE id_materia = ? AND id_profesor IS NOT NULL
                                 UNION SELECT id_profesor FROM materia_profesores WHERE id_materia = ?',
                                [$row['id_materia'], $row['id_materia']]
                            );
                            $profesoresMateriaIds = array_fill_keys(array_map('intval', array_column($profesoresMateria, 'id_profesor')), true);
                            $profesoresConClases = db_all(
                                'SELECT DISTINCT id_profesor FROM horario_semanal
                                 WHERE id_materia = ? AND id_profesor IS NOT NULL AND estado = \'PROGRAMADA\'',
                                [$row['id_materia']]
                            );
                            $profesoresConClasesIds = array_fill_keys(array_map('intval', array_column($profesoresConClases, 'id_profesor')), true);
                            $profesoresPermanentes = db_all('SELECT id_profesor FROM materia_profesores WHERE id_materia = ?', [$row['id_materia']]);
                            $profesoresPermanentesIds = array_fill_keys(array_map('intval', array_column($profesoresPermanentes, 'id_profesor')), true);
                        ?>
                        <div class="field">
                            <label>Nombre</label>
                            <input type="text" name="nombre" value="<?= e($row['nombre']) ?>" readonly required>
                        </div>
                        <div class="field professor-field">
                            <label>Profesor</label>
                            <div class="professor-controls">
                                <select name="id_profesor" data-professor-mode-select>
                                    <option value="0">Seleccione un profesor</option>
                                    <?php foreach ($profesores as $profesor): ?>
                                        <?php $asignado = isset($profesoresMateriaIds[(int) $profesor['id_profesor']]); ?>
                                        <option value="<?= (int) $profesor['id_profesor'] ?>" data-subject-assigned="<?= $asignado ? '1' : '0' ?>" data-subject-current="<?= isset($profesoresConClasesIds[(int) $profesor['id_profesor']]) ? '1' : '0' ?>" data-subject-permanent="<?= isset($profesoresPermanentesIds[(int) $profesor['id_profesor']]) ? '1' : '0' ?>" <?= $asignado ? 'hidden disabled' : '' ?>><?= e($profesor['nombre_completo']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="actions professor-actions">
                                    <button type="button" class="btn btn-danger btn-clear-profesor" data-clear-select="id_profesor" data-remove-current data-replacement-workflow data-resource-label="materia" hidden disabled>Eliminar profesor</button>
                                    <button type="button" class="btn btn-ghost" data-toggle-professor-list>Profesores de materia</button>
                                </div>
                            </div>
                            <input type="hidden" name="profesor_a_quitar" value="">
                        </div>
                        <div class="field">
                            <label>Estado</label>
                            <select name="activa">
                                <option value="1" <?= ((int) $row['activa'] === 1) ? 'selected' : '' ?>>Activo</option>
                                <option value="0" <?= ((int) $row['activa'] === 0) ? 'selected' : '' ?>>Inactivo</option>
                            </select>
                        </div>
                        <div class="actions">
                            <button class="btn btn-primary" type="submit">Guardar</button>
                        </div>
                    </form>
                </template>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="3">No se encontraron materias.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<template id="tpl-create">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="field"><label>Nombre</label><input name="nombre" required></div>
        <button class="btn btn-primary" type="submit">Guardar</button>
    </form>
</template>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
