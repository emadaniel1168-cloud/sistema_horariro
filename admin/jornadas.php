<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';

$sectionTitle = 'Calendarios';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action', '');
    try {
        if ($action === 'create') {
            $nombre = post('nombre');
            $descripcion = post('descripcion');
            if (!$nombre || !$descripcion) {
                throw new RuntimeException('El nombre y la descripción son obligatorios.');
            }
            crear_calendario($nombre, $descripcion);
            flash_set('success', 'Calendario creado correctamente.');
        } elseif ($action === 'update') {
            $id = (int) post('id_calendario', '0');
            $nombre = post('nombre');
            $descripcion = post('descripcion');
            if (!$id || !$nombre || !$descripcion) {
                throw new RuntimeException('Complete todos los campos.');
            }
            db_exec('UPDATE calendarios SET nombre = ?, descripcion = ? WHERE id_calendario = ?', [$nombre, $descripcion, $id]);
            flash_set('success', 'Información actualizada correctamente.');
        } elseif ($action === 'delete') {
            $id = (int) post('id_calendario', '0');
            $used = db_count('SELECT COUNT(*) AS total FROM grados WHERE id_calendario = ?', [$id])
                + db_count('SELECT COUNT(*) AS total FROM bloques_horarios WHERE id_calendario = ?', [$id]);
            if ($used > 0) {
                flash_set('error', 'No se puede eliminar el calendario porque tiene grados o bloques asociados.');
            } else {
                db_exec('DELETE FROM calendarios WHERE id_calendario = ?', [$id]);
                flash_set('success', 'Registro eliminado correctamente.');
            }
        }
    } catch (PDOException $e) {
        flash_set('error', unique_error($e, 'No se pudo guardar la información. Verifique los datos e inténtelo nuevamente.'));
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(APP_BASE . '/admin/jornadas.php');
}

$rows = db_all('SELECT * FROM calendarios ORDER BY id_calendario');
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card">
    <div class="card-head">
        <h2>Calendarios</h2>
        <button class="btn btn-primary" data-open-modal="tpl-create" data-title="Nuevo calendario">🟢 Nuevo</button>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nombre</th><th>Descripción</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e(pretty_name($row['nombre'])) ?></td>
                    <td><?= e($row['descripcion']) ?></td>
                    <td class="actions">
                        <button class="btn btn-sm btn-secondary" data-edit="tpl-edit-<?= (int) $row['id_calendario'] ?>" data-title="Editar calendario">✏️ Editar</button>
                        <form method="post" data-confirm="¿Está seguro de que desea eliminar este registro?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_calendario" value="<?= (int) $row['id_calendario'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">🗑️ Eliminar</button>
                        </form>
                    </td>
                </tr>
                <template id="tpl-edit-<?= (int) $row['id_calendario'] ?>">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id_calendario" value="<?= (int) $row['id_calendario'] ?>">
                        <div class="field"><label>Nombre</label><input name="nombre" required value="<?= e($row['nombre']) ?>"></div>
                        <div class="field"><label>Descripción</label><input name="descripcion" required value="<?= e($row['descripcion']) ?>"></div>
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
        <div class="field"><label>Nombre</label><input name="nombre" required placeholder="Ejemplo: 6_a_9"></div>
        <div class="field"><label>Descripción</label><input name="descripcion" required></div>
        <button class="btn btn-primary" type="submit">Guardar</button>
    </form>
</template>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
