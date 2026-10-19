<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin_auth();

$sectionTitle = 'Salones';
asegurar_esquema_novedades();
asegurar_tabla_salon_profesores();
asegurar_tabla_novedades_temporales();
asegurar_tabla_horario_original_novedad();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action', '');
    try {
        if ($action === 'create') {
            $nombre = post('nombre');
            if (!$nombre) {
                throw new RuntimeException('El nombre del salón es obligatorio.');
            }
            db_exec('INSERT INTO salones (nombre, activo) VALUES (?, 1)', [$nombre]);
            flash_set('success', 'Salón creado correctamente.');
        } elseif ($action === 'update') {
            $id = (int) post('id_salon', '0');
            $nombre = post('nombre');
            $activo = post('activo') === '1' ? 1 : 0;
            $profesorId = (int) post('id_profesor', '0');
            $profesorAEliminarRaw = post('profesor_a_quitar', '');
            $guardarPermanente = post('guardar_permanente', '') === '1';
            if (!$id || !$nombre) {
                throw new RuntimeException('Debe indicar el nombre del salón.');
            }
            if ($guardarPermanente && $profesorId === 0) {
                throw new RuntimeException('Seleccione un profesor para guardar el vínculo permanente.');
            }
            if ($profesorAEliminarRaw !== '' && $profesorId > 0) {
                throw new RuntimeException('No se puede agregar y quitar un profesor en la misma operación.');
            }
            if ($profesorId > 0) {
                asegurar_tabla_cambios_horario();
            }

            $clasesAgregadas = 0;
            db()->beginTransaction();
            try {
                if ($profesorId > 0 && $profesorAEliminarRaw === '') {
                    if (!$profesorId || !exists_id('profesores', 'id_profesor', $profesorId)) {
                        throw new RuntimeException('El profesor seleccionado no existe.');
                    }
                    if ($guardarPermanente) {
                        db_exec(
                            'INSERT IGNORE INTO salon_profesores (id_salon, id_profesor) VALUES (?, ?)',
                            [$id, $profesorId]
                        );
                    }
                    $clasesParaAsignar = $guardarPermanente
                        ? db_all(
                            'SELECT id_horario, id_profesor FROM horario_semanal
                             WHERE id_salon = ? AND estado = \'PROGRAMADA\'
                               AND (id_profesor IS NULL OR id_profesor = ?)
                             FOR UPDATE',
                            [$id, $profesorId]
                        )
                        : db_all(
                            'SELECT id_horario, id_profesor FROM horario_semanal
                             WHERE id_salon = ? AND id_profesor IS NULL AND estado = \'PROGRAMADA\'
                             FOR UPDATE',
                            [$id]
                        );
                    $grupoTemporal = $guardarPermanente ? bin2hex(random_bytes(16)) : null;
                    $nombreProfesor = $guardarPermanente
                        ? (db_one('SELECT nombre_completo FROM profesores WHERE id_profesor = ?', [$profesorId])['nombre_completo'] ?? 'Profesor')
                        : '';
                    foreach ($clasesParaAsignar as $clase) {
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
                            registrar_cambio_horario((int) $clase['id_horario'], 'CAMBIO', 'Profesor agregado al salón desde el formulario de salones.');
                        }
                        if ($guardarPermanente) {
                            db_exec(
                                "UPDATE novedades_cambios_horario SET estado = 'CANCELADA'
                                 WHERE id_horario = ? AND estado = 'ACTIVA' AND nuevo_profesor IS NULL
                                   AND observacion LIKE 'Profesor eliminado del salón %'",
                                [$clase['id_horario']]
                            );
                            db_exec('DELETE FROM novedades_temporales WHERE id_horario = ?', [$clase['id_horario']]);
                            db_exec(
                                'INSERT INTO novedades_temporales (grupo_novedad, id_horario, expira_en, observacion)
                                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 3 MINUTE), ?)',
                                [$grupoTemporal, $clase['id_horario'], 'Profesor asignado: ' . $nombreProfesor . '. Vínculo permanente con el salón ' . $nombre . '.']
                            );
                        } elseif (!db_one(
                            'SELECT id_cambio FROM novedades_cambios_horario WHERE id_horario = ? AND fecha = CURDATE() AND estado = "ACTIVA"',
                            [$clase['id_horario']]
                        )) {
                            db_exec(
                                'INSERT INTO novedades_cambios_horario (tipo_cambio, id_horario, fecha, nuevo_profesor, nueva_materia, nuevo_salon, observacion, estado)
                                 VALUES ("NOVEDAD", ?, CURDATE(), ?, NULL, NULL, ?, "ACTIVA")',
                                [$clase['id_horario'], $profesorId, 'Profesor agregado al salón desde el formulario de salones.']
                            );
                        }
                    }
                    $clasesAgregadas = count($clasesParaAsignar);
                }
                if ($profesorAEliminarRaw !== '') {
                    $profesorParaQuitar = (int) $profesorAEliminarRaw;
                    if (!$profesorParaQuitar || !exists_id('profesores', 'id_profesor', $profesorParaQuitar)) {
                        throw new RuntimeException('Debe seleccionar un profesor válido para quitar de este salón.');
                    }
                    $clasesProfesor = db_count('SELECT COUNT(*) AS total FROM horario_semanal WHERE id_salon = ? AND id_profesor = ?', [$id, $profesorParaQuitar]);
                    $vinculoPermanente = db_count('SELECT COUNT(*) AS total FROM salon_profesores WHERE id_salon = ? AND id_profesor = ?', [$id, $profesorParaQuitar]);
                    if ($clasesProfesor === 0 && $vinculoPermanente === 0) {
                        throw new RuntimeException('El profesor seleccionado no está asignado a este salón.');
                    }
                    if ($clasesProfesor > 0) {
                        registrar_novedad_por_desvincular_profesor('salon', $id, $profesorParaQuitar);
                    }
                    db_exec('UPDATE horario_semanal SET id_profesor = NULL WHERE id_salon = ? AND id_profesor = ?', [$id, $profesorParaQuitar]);
                    db_exec('DELETE FROM salon_profesores WHERE id_salon = ? AND id_profesor = ?', [$id, $profesorParaQuitar]);
                    db_exec(
                        'DELETE t FROM novedades_temporales t JOIN horario_semanal h ON h.id_horario = t.id_horario WHERE h.id_salon = ? AND h.id_profesor IS NULL',
                        [$id]
                    );
                }
                db_exec('UPDATE salones SET nombre = ?, activo = ? WHERE id_salon = ?', [$nombre, $activo, $id]);
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
            flash_set(
                'success',
                $guardarPermanente
                    ? 'Vínculo permanente guardado. Profesor agregado a ' . $clasesAgregadas . ' clase(s) sin profesor.'
                        . ($clasesAgregadas > 0 ? ' La novedad temporal quedará visible durante 3 minutos.' : ' No había clases sin profesor; no se generaron novedades temporales.')
                    : ($clasesAgregadas > 0
                        ? 'Profesor agregado a ' . $clasesAgregadas . ' clase(s) sin profesor. Información actualizada correctamente.'
                        : 'Información actualizada correctamente.')
            );
        } elseif ($action === 'toggle') {
            $id = (int) post('id_salon', '0');
            $row = db_one('SELECT activo FROM salones WHERE id_salon = ?', [$id]);
            if (!$row) {
                throw new RuntimeException('El salón no existe.');
            }
            $next = ((int) $row['activo'] === 1) ? 0 : 1;
            db_exec('UPDATE salones SET activo = ? WHERE id_salon = ?', [$next, $id]);
            flash_set('success', 'Información actualizada correctamente.');
        } elseif ($action === 'delete') {
            $id = (int) post('id_salon', '0');
            $used = db_count('SELECT COUNT(*) AS total FROM horario_semanal WHERE id_salon = ?', [$id])
                + db_count('SELECT COUNT(*) AS total FROM novedades_cambios_horario WHERE nuevo_salon = ? AND fecha IS NOT NULL', [$id]);
            if ($used > 0) {
                db_exec('UPDATE salones SET activo = 0 WHERE id_salon = ?', [$id]);
                flash_set('error', 'No se puede eliminar porque está relacionado con horarios. El salón fue desactivado.');
            } else {
                db_exec('DELETE FROM salones WHERE id_salon = ?', [$id]);
                flash_set('success', 'Registro eliminado correctamente.');
            }
        }
    } catch (PDOException $e) {
        flash_set('error', unique_error($e, 'No se pudo guardar la información. Verifique los datos e inténtelo nuevamente.'));
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(APP_BASE . '/admin/salones.php');
}

$q = get('q', '');
$sql = 'SELECT s.id_salon, s.nombre, s.activo FROM salones s';
$params = [];
if ($q) {
    $sql .= ' WHERE s.nombre LIKE ?';
    $params[] = '%' . $q . '%';
}
$sql .= ' GROUP BY s.id_salon, s.nombre, s.activo
          ORDER BY CASE WHEN s.nombre REGEXP \'[0-9]+$\' THEN 0 ELSE 1 END,
                   CAST(SUBSTRING_INDEX(s.nombre, \' \', -1) AS UNSIGNED), s.nombre';
$rows = db_all($sql, $params);
$profesores = db_all('SELECT id_profesor, nombre_completo FROM profesores WHERE activo = 1 ORDER BY nombre_completo');

require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card">
    <div class="card-head">
        <h2>Salones</h2>
        <button class="btn btn-primary" data-open-modal="tpl-create" data-title="Nuevo salón">🟢 Nuevo</button>
    </div>
    <form class="toolbar" method="get">
        <input class="search" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar salón">
        <button class="btn btn-secondary" type="submit">Filtrar</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nombre</th><th>Estado</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['nombre']) ?></td>
                    <td><?= badge_activo($row['activo']) ?></td>
                    <td class="actions">
                        <button class="btn btn-sm btn-secondary" data-edit="tpl-edit-<?= (int) $row['id_salon'] ?>" data-title="Ver salón">Ver</button>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id_salon" value="<?= (int) $row['id_salon'] ?>">
                            <button class="btn btn-sm btn-ghost" type="submit"><?= ((int) $row['activo'] === 1) ? 'Desactivar' : 'Activar' ?></button>
                        </form>
                        <form method="post" data-confirm="¿Está seguro de que desea eliminar este registro?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_salon" value="<?= (int) $row['id_salon'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">🗑️ Eliminar</button>
                        </form>
                    </td>
                </tr>
                <template id="tpl-edit-<?= (int) $row['id_salon'] ?>">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id_salon" value="<?= (int) $row['id_salon'] ?>">
                        <?php
                            $profesoresSalon = db_all(
                                'SELECT id_profesor FROM horario_semanal WHERE id_salon = ? AND id_profesor IS NOT NULL
                                 UNION SELECT id_profesor FROM salon_profesores WHERE id_salon = ?',
                                [$row['id_salon'], $row['id_salon']]
                            );
                            $profesoresSalonIds = array_fill_keys(array_map('intval', array_column($profesoresSalon, 'id_profesor')), true);
                        ?>
                        <div class="field">
                            <label>Nombre</label>
                            <select name="nombre" required>
                                <option value="">Seleccione un salón</option>
                                <?= options_html($rows, 'nombre', 'nombre', $row['nombre'], false) ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Profesor</label>
                            <select name="id_profesor" data-professor-mode-select>
                                <option value="0">Seleccione un profesor</option>
                                <?php foreach ($profesores as $profesor): ?>
                                    <?php $asignado = isset($profesoresSalonIds[(int) $profesor['id_profesor']]); ?>
                                    <option value="<?= (int) $profesor['id_profesor'] ?>" data-room-assigned="<?= $asignado ? '1' : '0' ?>" <?= $asignado ? 'hidden disabled' : '' ?>><?= e($profesor['nombre_completo']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="profesor_a_quitar" value="">
                        </div>
                        <div class="actions professor-actions">
                            <button type="button" class="btn btn-danger btn-clear-profesor" data-clear-select="id_profesor" data-resource-label="salón" hidden disabled>Eliminar profesor</button>
                            <button type="button" class="btn btn-ghost" data-toggle-professor-list data-assigned-label="Profesores del salón" data-available-label="Todos los profesores">Profesores del salón</button>
                        </div>
                        <div class="field">
                            <label>Estado</label>
                            <select name="activo">
                                <option value="1" <?= ((int) $row['activo'] === 1) ? 'selected' : '' ?>>Activo</option>
                                <option value="0" <?= ((int) $row['activo'] === 0) ? 'selected' : '' ?>>Inactivo</option>
                            </select>
                        </div>
                        <div class="actions">
                            <button class="btn btn-primary" type="submit">Guardar</button>
                            <button class="btn btn-secondary" type="submit" data-save-permanently data-confirm="Guardar permanentemente vinculará este profesor al salón y lo asignará a sus clases actuales que no tengan profesor. El horario cambiará y se mostrará un aviso verde durante 3 minutos; después se quitará el resaltado sin deshacer los cambios. ¿Desea continuar?">Guardar permanente</button>
                        </div>
                    </form>
                </template>
            <?php endforeach; ?>
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
