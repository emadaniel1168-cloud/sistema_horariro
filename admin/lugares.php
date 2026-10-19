<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin_auth();

$sectionTitle = 'Lugares';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action', '');
    try {
        if ($action === 'create') {
            $nombre = post('nombre');
            if (!$nombre) {
                throw new RuntimeException('El nombre del lugar es obligatorio.');
            }
            db_exec('INSERT INTO lugares (nombre, activo) VALUES (?, 1)', [$nombre]);
            flash_set('success', 'Lugar creado correctamente.');
        } elseif ($action === 'update') {
            $id = (int) post('id_lugar', '0');
            $nombre = post('nombre');
            if (!$id || !$nombre) {
                throw new RuntimeException('Debe indicar el nombre del lugar.');
            }
            db_exec('UPDATE lugares SET nombre = ? WHERE id_lugar = ?', [$nombre, $id]);
            flash_set('success', 'Información actualizada correctamente.');
        } elseif ($action === 'delete') {
            $id = (int) post('id_lugar', '0');
            db_exec('DELETE FROM lugares WHERE id_lugar = ?', [$id]);
            flash_set('success', 'Registro eliminado correctamente.');
        }
    } catch (PDOException $e) {
        flash_set('error', unique_error($e, 'No se pudo guardar la información. Verifique los datos e inténtelo nuevamente.'));
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(APP_BASE . '/admin/lugares.php');
}

$q = get('q', '');
$sql = 'SELECT * FROM lugares';
$params = [];
if ($q) {
    $sql .= ' WHERE nombre LIKE ?';
    $params[] = '%' . $q . '%';
}
$sql .= ' ORDER BY nombre';
$rows = db_all($sql, $params);

require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card">
    <div class="card-head">
        <h2>Lugares</h2>
        <button class="btn btn-primary" data-open-modal="tpl-create" data-title="Nuevo lugar">🟢 Nuevo</button>
    </div>
    <form class="toolbar" method="get">
        <input class="search" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar lugar">
        <button class="btn btn-secondary" type="submit">Filtrar</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nombre</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e(pretty_name($row['nombre'])) ?></td>
                    <td class="actions">
                        <button class="btn btn-sm btn-secondary" data-edit="tpl-edit-<?= (int) $row['id_lugar'] ?>" data-title="Editar lugar">✏️ Editar</button>
                        <form method="post" data-confirm="¿Está seguro de que desea eliminar este registro?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_lugar" value="<?= (int) $row['id_lugar'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">🗑️ Eliminar</button>
                        </form>
                    </td>
                </tr>
                <template id="tpl-edit-<?= (int) $row['id_lugar'] ?>">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id_lugar" value="<?= (int) $row['id_lugar'] ?>">
                        <div class="field"><label>Nombre</label><input name="nombre" required value="<?= e($row['nombre']) ?>"></div>
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
        <div class="field"><label>Nombre</label><input name="nombre" required></div>
        <button class="btn btn-primary" type="submit">Guardar</button>
    </form>
</template>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
