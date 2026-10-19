<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin_auth();

$sectionTitle = 'Días de la semana';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action', '');
    try {
        if ($action === 'create') {
            $id = (int) post('id_dia', '0');
            $nombre = post('nombre');
            $orden = (int) post('orden_dia', '0');
            if (!$id || !$nombre || !$orden) {
                throw new RuntimeException('Complete el identificador, el nombre y el orden del día.');
            }
            db_exec('INSERT INTO dias_semana (id_dia, nombre, orden_dia) VALUES (?, ?, ?)', [$id, $nombre, $orden]);
            flash_set('success', 'Día creado correctamente.');
        } elseif ($action === 'update') {
            $id = (int) post('id_dia', '0');
            $nombre = post('nombre');
            $orden = (int) post('orden_dia', '0');
            if (!$id || !$nombre || !$orden) {
                throw new RuntimeException('Complete todos los campos.');
            }
            db_exec('UPDATE dias_semana SET nombre = ?, orden_dia = ? WHERE id_dia = ?', [$nombre, $orden, $id]);
            flash_set('success', 'Información actualizada correctamente.');
        } elseif ($action === 'delete') {
            $id = (int) post('id_dia', '0');
            $used = db_count('SELECT COUNT(*) AS total FROM horario_semanal WHERE id_dia = ?', [$id])
                + db_count('SELECT COUNT(*) AS total FROM disponibilidad_profesores WHERE id_dia = ?', [$id]);
            if ($used > 0) {
                flash_set('error', 'No se puede eliminar el día porque está usado en horarios o disponibilidad.');
            } else {
                db_exec('DELETE FROM dias_semana WHERE id_dia = ?', [$id]);
                flash_set('success', 'Registro eliminado correctamente.');
            }
        }
    } catch (PDOException $e) {
        flash_set('error', unique_error($e, 'No se pudo guardar la información. Verifique los datos e inténtelo nuevamente.'));
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(APP_BASE . '/admin/dias.php');
}

$rows = db_all('SELECT * FROM dias_semana ORDER BY orden_dia');
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card">
    <div class="card-head">
        <h2>Días de la semana</h2>
        <button class="btn btn-primary" data-open-modal="tpl-create" data-title="Nuevo día">🟢 Nuevo</button>
    </div>
    <p class="muted">Mantenga el orden de lunes a viernes. Solo elimine un día si no está asociado a horarios.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Orden</th><th>Día</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= (int) $row['orden_dia'] ?></td>
                    <td><?= e($row['nombre']) ?></td>
                    <td class="actions">
                        <button class="btn btn-sm btn-secondary" data-edit="tpl-edit-<?= (int) $row['id_dia'] ?>" data-title="Editar día">✏️ Editar</button>
                        <form method="post" data-confirm="¿Está seguro de que desea eliminar este registro?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_dia" value="<?= (int) $row['id_dia'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">🗑️ Eliminar</button>
                        </form>
                    </td>
                </tr>
                <template id="tpl-edit-<?= (int) $row['id_dia'] ?>">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id_dia" value="<?= (int) $row['id_dia'] ?>">
                        <div class="field"><label>Nombre</label><input name="nombre" required value="<?= e($row['nombre']) ?>"></div>
                        <div class="field"><label>Orden</label><input type="number" min="1" name="orden_dia" required value="<?= (int) $row['orden_dia'] ?>"></div>
                        <button class="btn btn-primary" type="submit">Guardar</button>
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
        <div class="field"><label>Identificador</label><input type="number" min="1" name="id_dia" required></div>
        <div class="field"><label>Nombre</label><input name="nombre" required></div>
        <div class="field"><label>Orden</label><input type="number" min="1" name="orden_dia" required></div>
        <button class="btn btn-primary" type="submit">Guardar</button>
    </form>
</template>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
