<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin_auth();

$sectionTitle = 'Salones';
asegurar_esquema_novedades();

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
            if (!$id || !$nombre) {
                throw new RuntimeException('Debe indicar el nombre del salón.');
            }
            db_exec('UPDATE salones SET nombre = ?, activo = ? WHERE id_salon = ?', [$nombre, $activo, $id]);
            flash_set('success', 'Información actualizada correctamente.');
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
                        <div class="field">
                            <label>Nombre</label>
                            <select name="nombre" required>
                                <option value="">Seleccione un salón</option>
                                <?= options_html($rows, 'nombre', 'nombre', $row['nombre'], false) ?>
                            </select>
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
