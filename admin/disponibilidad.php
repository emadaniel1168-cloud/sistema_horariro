<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';

$sectionTitle = 'Disponibilidad de profesores';
$profesores = db_all('SELECT id_profesor, nombre_completo FROM profesores ORDER BY nombre_completo');
$dias = db_all('SELECT * FROM dias_semana ORDER BY orden_dia');
$bloques = db_all(
    'SELECT b.id_bloque, b.numero_bloque, b.hora_inicio, b.hora_fin, b.tipo, c.nombre AS calendario
     FROM bloques_horarios b JOIN calendarios c ON c.id_calendario = b.id_calendario
     ORDER BY c.nombre, b.hora_inicio'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action', '');
    try {
        if ($action === 'create' || $action === 'update') {
            $idProfesor = (int) post('id_profesor', '0');
            $idDia = (int) post('id_dia', '0');
            $idBloque = (int) post('id_bloque', '0');
            $estado = post('estado', 'DISPONIBLE');
            $observacion = post('observacion');
            if (!$idProfesor || !$idDia || !$idBloque) {
                throw new RuntimeException('Debe seleccionar profesor, día y bloque.');
            }
            if (!in_array($estado, ['DISPONIBLE', 'NO_DISPONIBLE'], true)) {
                throw new RuntimeException('El estado no es válido.');
            }
            if (!exists_id('profesores', 'id_profesor', $idProfesor)
                || !exists_id('dias_semana', 'id_dia', $idDia)
                || !exists_id('bloques_horarios', 'id_bloque', $idBloque)) {
                throw new RuntimeException('Hay una selección inválida.');
            }
            if ($action === 'create') {
                db_exec(
                    'INSERT INTO disponibilidad_profesores (id_profesor, id_dia, id_bloque, estado, observacion) VALUES (?, ?, ?, ?, ?)',
                    [$idProfesor, $idDia, $idBloque, $estado, $observacion]
                );
                flash_set('success', 'Disponibilidad creada correctamente.');
            } else {
                $id = (int) post('id_disponibilidad', '0');
                db_exec(
                    'UPDATE disponibilidad_profesores SET id_profesor = ?, id_dia = ?, id_bloque = ?, estado = ?, observacion = ? WHERE id_disponibilidad = ?',
                    [$idProfesor, $idDia, $idBloque, $estado, $observacion, $id]
                );
                flash_set('success', 'Información actualizada correctamente.');
            }
        } elseif ($action === 'delete') {
            $id = (int) post('id_disponibilidad', '0');
            db_exec('DELETE FROM disponibilidad_profesores WHERE id_disponibilidad = ?', [$id]);
            flash_set('success', 'Registro eliminado correctamente.');
        }
    } catch (PDOException $e) {
        flash_set('error', unique_error($e, 'No se pudo guardar la información. Verifique los datos e inténtelo nuevamente.'));
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    $qs = http_build_query(array_filter([
        'profesor' => get('profesor'),
        'dia' => get('dia'),
        'estado' => get('estado'),
    ]));
    redirect(APP_BASE . '/admin/disponibilidad.php' . ($qs ? '?' . $qs : ''));
}

$filtroProfesor = get('profesor');
$filtroDia = get('dia');
$filtroEstado = get('estado');
$page = max(1, (int) (get('page') ?? 1));
$perPage = 25;
$where = [];
$params = [];
if ($filtroProfesor) {
    $where[] = 'd.id_profesor = ?';
    $params[] = (int) $filtroProfesor;
}
if ($filtroDia) {
    $where[] = 'd.id_dia = ?';
    $params[] = (int) $filtroDia;
}
if ($filtroEstado) {
    $where[] = 'd.estado = ?';
    $params[] = $filtroEstado;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = db_count('SELECT COUNT(*) AS total FROM disponibilidad_profesores d' . $whereSql, $params);
$offset = ($page - 1) * $perPage;
$rows = db_all(
    "SELECT d.*, p.nombre_completo, ds.nombre AS dia, b.numero_bloque, b.hora_inicio, b.hora_fin, c.nombre AS calendario
     FROM disponibilidad_profesores d
     JOIN profesores p ON p.id_profesor = d.id_profesor
     JOIN dias_semana ds ON ds.id_dia = d.id_dia
     JOIN bloques_horarios b ON b.id_bloque = d.id_bloque
    JOIN calendarios c ON c.id_calendario = b.id_calendario
     $whereSql
     ORDER BY p.nombre_completo, ds.orden_dia, b.hora_inicio
     LIMIT $perPage OFFSET $offset",
    $params
);

require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card">
    <div class="card-head">
        <h2>Disponibilidad</h2>
        <button class="btn btn-primary" data-open-modal="tpl-create" data-title="Nueva disponibilidad">🟢 Nuevo</button>
    </div>
    <form class="toolbar" method="get">
        <select name="profesor">
            <option value="">Todos los profesores</option>
            <?= options_html($profesores, 'id_profesor', 'nombre_completo', $filtroProfesor, false) ?>
        </select>
        <select name="dia">
            <option value="">Todos los días</option>
            <?= options_html($dias, 'id_dia', 'nombre', $filtroDia, false) ?>
        </select>
        <select name="estado">
            <option value="">Todos los estados</option>
            <option value="DISPONIBLE" <?= $filtroEstado === 'DISPONIBLE' ? 'selected' : '' ?>>Disponible</option>
            <option value="NO_DISPONIBLE" <?= $filtroEstado === 'NO_DISPONIBLE' ? 'selected' : '' ?>>No disponible</option>
        </select>
        <button class="btn btn-secondary" type="submit">Filtrar</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Profesor</th>
                <th>Día</th>
                <th>Bloque</th>
                <th>Horario</th>
                <th>Estado</th>
                <th>Observación</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['nombre_completo']) ?></td>
                    <td><?= e($row['dia']) ?></td>
                    <td><?= $row['numero_bloque'] !== null ? (int) $row['numero_bloque'] : 'Descanso' ?> (<?= e(pretty_name($row['calendario'])) ?>)</td>
                    <td><?= e(format_time($row['hora_inicio'])) ?> - <?= e(format_time($row['hora_fin'])) ?></td>
                    <td><?= badge_estado($row['estado']) ?></td>
                    <td><?= e($row['observacion'] ?: '—') ?></td>
                    <td class="actions">
                        <button class="btn btn-sm btn-secondary" data-edit="tpl-edit-<?= (int) $row['id_disponibilidad'] ?>" data-title="Editar disponibilidad">✏️ Editar</button>
                        <form method="post" data-confirm="¿Está seguro de que desea eliminar este registro?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_disponibilidad" value="<?= (int) $row['id_disponibilidad'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">🗑️ Eliminar</button>
                        </form>
                    </td>
                </tr>
                <template id="tpl-edit-<?= (int) $row['id_disponibilidad'] ?>">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id_disponibilidad" value="<?= (int) $row['id_disponibilidad'] ?>">
                        <div class="field"><label>Profesor</label><select name="id_profesor" required><?= options_html($profesores, 'id_profesor', 'nombre_completo', $row['id_profesor']) ?></select></div>
                        <div class="field"><label>Día</label><select name="id_dia" required><?= options_html($dias, 'id_dia', 'nombre', $row['id_dia']) ?></select></div>
                        <div class="field">
                            <label>Bloque</label>
                            <select name="id_bloque" required>
                                <option value="">Seleccione una opción</option>
                                <?php foreach ($bloques as $b): ?>
                                    <option value="<?= (int) $b['id_bloque'] ?>" <?= (int) $row['id_bloque'] === (int) $b['id_bloque'] ? 'selected' : '' ?>>
                                        <?= e(pretty_name($b['calendario'])) ?> — <?= e(format_time($b['hora_inicio'])) ?> - <?= e(format_time($b['hora_fin'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Estado</label>
                            <select name="estado">
                                <option value="DISPONIBLE" <?= $row['estado'] === 'DISPONIBLE' ? 'selected' : '' ?>>Disponible</option>
                                <option value="NO_DISPONIBLE" <?= $row['estado'] === 'NO_DISPONIBLE' ? 'selected' : '' ?>>No disponible</option>
                            </select>
                        </div>
                        <div class="field"><label>Observación</label><input name="observacion" value="<?= e((string) $row['observacion']) ?>"></div>
                        <button class="btn btn-primary" type="submit">Guardar</button>
                    </form>
                </template>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7">No hay registros con esos filtros.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= pagination($total, $page, $perPage, array_filter(['profesor' => $filtroProfesor, 'dia' => $filtroDia, 'estado' => $filtroEstado])) ?>
</section>
<template id="tpl-create">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="field"><label>Profesor</label><select name="id_profesor" required><?= options_html($profesores, 'id_profesor', 'nombre_completo') ?></select></div>
        <div class="field"><label>Día</label><select name="id_dia" required><?= options_html($dias, 'id_dia', 'nombre') ?></select></div>
        <div class="field">
            <label>Bloque</label>
            <select name="id_bloque" required>
                <option value="">Seleccione una opción</option>
                <?php foreach ($bloques as $b): ?>
                    <option value="<?= (int) $b['id_bloque'] ?>">
                        <?= e(pretty_name($b['calendario'])) ?> — <?= e(format_time($b['hora_inicio'])) ?> - <?= e(format_time($b['hora_fin'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Estado</label>
            <select name="estado">
                <option value="DISPONIBLE">Disponible</option>
                <option value="NO_DISPONIBLE">No disponible</option>
            </select>
        </div>
        <div class="field"><label>Observación</label><input name="observacion"></div>
        <button class="btn btn-primary" type="submit">Guardar</button>
    </form>
</template>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
