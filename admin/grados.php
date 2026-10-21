<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin_auth();

$sectionTitle = 'Grados';
$calendarios = db_all('SELECT id_calendario, nombre FROM calendarios WHERE id_calendario IN (1, 2) ORDER BY id_calendario');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action', '');
    try {
        if ($action === 'create' || $action === 'update') {
            $id = strtoupper(trim((string) post('id_grado', '')));
            $idCalendario = (int) post('id_calendario', '0');
            $activo = post('activo') === '0' ? 0 : 1;
            if ($id === '' || !$idCalendario) {
                throw new RuntimeException('Debe indicar el grado y seleccionar un calendario.');
            }
            if (!in_array($idCalendario, [1, 2], true) || !exists_id('calendarios', 'id_calendario', $idCalendario)) {
                throw new RuntimeException('Seleccione el calendario 1 o el calendario 2.');
            }
            if ($action === 'create') {
                if (db_count('SELECT COUNT(*) AS total FROM grados WHERE id_grado = ?', [$id]) > 0) {
                    throw new RuntimeException('El grado ' . $id . ' ya fue creado.');
                }
                db_exec('INSERT INTO grados (id_grado, id_calendario, activo) VALUES (?, ?, ?)', [$id, $idCalendario, $activo]);
                flash_set('success', 'Grado creado correctamente.');
            } else {
                $original = post('id_original');
                if (!$original) {
                    throw new RuntimeException('No se encontró el grado a editar.');
                }
                if (db_count('SELECT COUNT(*) AS total FROM grados WHERE id_grado = ? AND id_grado <> ?', [$id, $original]) > 0) {
                    throw new RuntimeException('El grado ' . $id . ' ya fue creado.');
                }
                db_exec('UPDATE grados SET id_grado = ?, id_calendario = ?, activo = ? WHERE id_grado = ?', [$id, $idCalendario, $activo, $original]);
                flash_set('success', 'Información actualizada correctamente.');
            }
        } elseif ($action === 'toggle') {
            $id = post('id_grado');
            $row = db_one('SELECT activo FROM grados WHERE id_grado = ?', [$id]);
            if (!$row) {
                throw new RuntimeException('El grado no existe.');
            }
            $next = ((int) $row['activo'] === 1) ? 0 : 1;
            db_exec('UPDATE grados SET activo = ? WHERE id_grado = ?', [$next, $id]);
            flash_set('success', 'Información actualizada correctamente.');
        } elseif ($action === 'delete') {
            $id = trim((string) post('id_grado', ''));
            if ($id === '') {
                throw new RuntimeException('No se encontró el grado a eliminar.');
            }

            asegurar_esquema_novedades();
            asegurar_tabla_descansos_por_dia();
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $grado = db_one('SELECT id_grado FROM grados WHERE id_grado = ? FOR UPDATE', [$id]);
                if (!$grado) {
                    throw new RuntimeException('El grado no existe.');
                }

                db_exec(
                    'DELETE n FROM novedades_cambios_horario n
                     LEFT JOIN horario_semanal h ON h.id_horario = n.id_horario
                     WHERE n.id_grado = ? OR h.id_grado = ?',
                    [$id, $id]
                );
                db_exec('DELETE FROM horario_semanal WHERE id_grado = ?', [$id]);
                if (db_exec('DELETE FROM grados WHERE id_grado = ?', [$id]) !== 1) {
                    throw new RuntimeException('No se pudo eliminar el grado.');
                }
                $pdo->commit();
                flash_set('success', 'El grado y sus horarios asociados fueron eliminados correctamente.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    } catch (PDOException $e) {
        flash_set('error', unique_error($e, 'No se pudo guardar la información. Verifique los datos e inténtelo nuevamente.'));
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(APP_BASE . '/admin/grados.php');
}

$q = get('q', '');
$sql = 'SELECT g.*, c.nombre AS calendario FROM grados g JOIN calendarios c ON c.id_calendario = g.id_calendario';
$params = [];
if ($q) {
    $sql .= ' WHERE g.id_grado LIKE ? OR c.nombre LIKE ?';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$sql .= ' ORDER BY CAST(SUBSTRING_INDEX(g.id_grado, \'-\', 1) AS UNSIGNED), CAST(SUBSTRING_INDEX(g.id_grado, \'-\', -1) AS UNSIGNED), g.id_grado';
$rows = db_all($sql, $params);
$gradosExistentes = array_column(db_all('SELECT id_grado FROM grados'), 'id_grado');

require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card">
    <div class="card-head">
        <h2>Grados</h2>
        <button class="btn btn-primary" data-open-modal="tpl-create" data-title="Nuevo grado">🟢 Nuevo</button>
    </div>
    <form class="toolbar" method="get">
        <input class="search" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar grado o calendario">
        <button class="btn btn-secondary" type="submit">Filtrar</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Grado</th><th>Calendario</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <?= e($row['id_grado']) ?>
                        <small class="muted"><?= ((int) $row['activo'] === 1) ? 'Activo' : 'Inactivo' ?></small>
                    </td>
                    <td>Calendario <?= (int) $row['id_calendario'] ?></td>
                    <td class="actions">
                        <form method="post" data-confirm="¿Está seguro de que desea cambiar el estado de este grado?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id_grado" value="<?= e($row['id_grado']) ?>">
                            <button class="btn btn-sm btn-secondary" type="submit">
                                <?= (int) $row['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                            </button>
                        </form>
                        <form method="post" data-confirm="¿Desea eliminar este grado y todos sus horarios y novedades asociadas? Esta acción no se puede deshacer.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_grado" value="<?= e($row['id_grado']) ?>">
                            <button class="btn btn-sm btn-danger" type="submit">🗑️ Eliminar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<template id="tpl-create">
    <form method="post" data-grade-validation data-existing-grades="<?= e(json_encode($gradosExistentes, JSON_UNESCAPED_UNICODE)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="field">
            <label>Grado</label>
            <input name="id_grado" required autocomplete="off" aria-describedby="grade-duplicate-message">
            <small class="grade-duplicate-message" id="grade-duplicate-message" data-grade-duplicate-message role="alert" hidden></small>
        </div>
        <div class="field">
            <label>Calendario</label>
            <select name="id_calendario" required>
                <option value="">Seleccione un calendario</option>
                <?php foreach ($calendarios as $calendario): ?>
                    <option value="<?= (int) $calendario['id_calendario'] ?>">Calendario <?= (int) $calendario['id_calendario'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Estado</label>
            <select name="activo"><option value="1">Activo</option><option value="0">Inactivo</option></select>
        </div>
        <button class="btn btn-primary" type="submit">Guardar</button>
    </form>
</template>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
