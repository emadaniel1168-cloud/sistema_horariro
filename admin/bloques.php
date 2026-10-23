<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';
require_admin_auth();

$sectionTitle = 'Bloques horarios';
$calendarios = db_all('SELECT id_calendario, nombre FROM calendarios ORDER BY nombre');

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

function compactar_bloque_eliminado(array $bloque): bool
{
    if ((string) $bloque['tipo'] !== 'CLASE') {
        return false;
    }

    $idBloque = (int) $bloque['id_bloque'];
    $idCalendario = (int) $bloque['id_calendario'];
    $hayClasesReales = db_count('SELECT COUNT(*) AS total FROM horario_semanal WHERE id_bloque = ?', [$idBloque]) > 0
        || db_count('SELECT COUNT(*) AS total FROM novedades_cambios_horario WHERE id_bloque = ?', [$idBloque]) > 0;
    if ($hayClasesReales) {
        return false;
    }
    $bloqueSiguiente = db_one(
        "SELECT * FROM bloques_horarios
         WHERE id_calendario = ? AND tipo = 'CLASE' AND hora_inicio = ? AND id_bloque <> ?
         ORDER BY hora_inicio, numero_bloque, id_bloque
         LIMIT 1 FOR UPDATE",
        [$idCalendario, $bloque['hora_fin'], $idBloque]
    );
    if (!$bloqueSiguiente) {
        return false;
    }

    $grados = db_all(
        'SELECT DISTINCT id_grado FROM horario_semanal WHERE id_bloque = ? ORDER BY id_grado',
        [$idBloque]
    );
    foreach ($grados as $grado) {
        $idGrado = (int) $grado['id_grado'];
        $clasesActuales = db_all(
            'SELECT id_horario, id_dia, id_materia, id_profesor, id_salon, estado, observacion
             FROM horario_semanal WHERE id_grado = ? AND id_bloque = ? ORDER BY id_dia',
            [$idGrado, $idBloque]
        );
        $clasesSiguientes = db_all(
            'SELECT id_horario, id_dia, id_materia, id_profesor, id_salon, estado, observacion
             FROM horario_semanal WHERE id_grado = ? AND id_bloque = ? ORDER BY id_dia',
            [$idGrado, (int) $bloqueSiguiente['id_bloque']]
        );

        $siguientesPorDia = [];
        foreach ($clasesSiguientes as $claseSiguiente) {
            $siguientesPorDia[(int) $claseSiguiente['id_dia']] = $claseSiguiente;
        }

        foreach ($clasesActuales as $claseActual) {
            $claseSiguiente = $siguientesPorDia[(int) $claseActual['id_dia']] ?? null;
            if ($claseSiguiente) {
                $campos = ['id_materia', 'id_profesor', 'id_salon', 'estado', 'observacion'];
                foreach ($campos as $campo) {
                    if ($claseActual[$campo] !== $claseSiguiente[$campo]) {
                        throw new RuntimeException('No se puede compactar este bloque porque hay clases incompatibles en el siguiente tramo.');
                    }
                }
            }
        }
    }

    db()->beginTransaction();
    try {
        db_exec(
            'UPDATE bloques_horarios SET hora_inicio = ? WHERE id_bloque = ?',
            [$bloque['hora_inicio'], (int) $bloqueSiguiente['id_bloque']]
        );
        foreach ($grados as $grado) {
            $idGrado = (int) $grado['id_grado'];
            db_exec(
                'UPDATE horario_semanal SET id_bloque = ? WHERE id_grado = ? AND id_bloque = ?',
                [(int) $bloqueSiguiente['id_bloque'], $idGrado, $idBloque]
            );
        }
        db_exec('DELETE FROM bloques_horarios WHERE id_bloque = ?', [$idBloque]);
        db()->commit();
        return true;
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

function save_bloque(string $action): void
{
    $id = (int) post('id_bloque', '0');
    $idCalendario = (int) post('id_calendario', '0');
    $tipo = post('tipo');
    $numero = post('numero_bloque');
    $inicio = post('hora_inicio');
    $fin = post('hora_fin');
    $descripcion = validar_descripcion_bloque(post('descripcion'));

    if (!$idCalendario || !$tipo || !$inicio || !$fin) {
        throw new RuntimeException('Complete calendario, tipo y horas.');
    }
    if (!in_array($tipo, ['CLASE', 'DESCANSO'], true)) {
        throw new RuntimeException('El tipo de bloque no es válido.');
    }
    if (!exists_id('calendarios', 'id_calendario', $idCalendario)) {
        throw new RuntimeException('El calendario seleccionado no existe.');
    }
    if ($inicio >= $fin) {
        throw new RuntimeException('La hora de inicio debe ser anterior a la hora de finalización.');
    }
    $numeroVal = ($tipo === 'DESCANSO' && ($numero === null || $numero === '')) ? null : (int) $numero;
    if ($tipo === 'CLASE' && !$numeroVal) {
        throw new RuntimeException('Las clases deben tener un número de bloque.');
    }

    if ($action === 'create') {
        db_exec(
            'INSERT INTO bloques_horarios (id_calendario, numero_bloque, tipo, hora_inicio, hora_fin, descripcion) VALUES (?, ?, ?, ?, ?, ?)',
            [$idCalendario, $numeroVal, $tipo, $inicio, $fin, $descripcion]
        );
        flash_set('success', 'Bloque horario creado correctamente.');
        return;
    }

    if (!$id) {
        throw new RuntimeException('No se encontró el bloque.');
    }
    $anterior = db_one(
        'SELECT id_calendario, tipo, hora_fin FROM bloques_horarios WHERE id_bloque = ?',
        [$id]
    );
    if (!$anterior) {
        throw new RuntimeException('No se encontró el bloque que desea editar.');
    }

    $minutos = static fn(string $hora): int => ((int) substr($hora, 0, 2) * 60) + (int) substr($hora, 3, 2);
    $bloquesPosteriores = [];
    $desplazamiento = 0;
    if ($tipo === 'CLASE'
        && $anterior['tipo'] === 'CLASE'
        && (int) $anterior['id_calendario'] === $idCalendario
        && $fin > format_time($anterior['hora_fin'])) {
        $desplazamiento = $minutos($fin) - $minutos(format_time($anterior['hora_fin']));
        $bloquesPosteriores = db_all(
            'SELECT id_bloque, numero_bloque, tipo, hora_inicio, hora_fin
             FROM bloques_horarios
             WHERE id_calendario = ? AND id_bloque <> ? AND hora_inicio >= ?
             ORDER BY hora_inicio, hora_fin, id_bloque',
            [$idCalendario, $id, $anterior['hora_fin']]
        );
        if ($bloquesPosteriores && post('ajustar_bloques_siguientes', '0') !== '1') {
            throw new RuntimeException('Confirme el desplazamiento de los bloques siguientes antes de guardar.');
        }
        foreach ($bloquesPosteriores as $bloquePosterior) {
            if ($minutos(format_time($bloquePosterior['hora_fin'])) + $desplazamiento >= 24 * 60) {
                throw new RuntimeException('No se puede desplazar el horario porque un bloque posterior terminaría después de las 11:59 p. m.');
            }
        }
    }

    db()->beginTransaction();
    try {
        db_exec(
            'UPDATE bloques_horarios SET id_calendario = ?, numero_bloque = ?, tipo = ?, hora_inicio = ?, hora_fin = ?, descripcion = ? WHERE id_bloque = ?',
            [$idCalendario, $numeroVal, $tipo, $inicio, $fin, $descripcion, $id]
        );
        foreach ($bloquesPosteriores as $bloquePosterior) {
            $horaInicioPosterior = sprintf('%02d:%02d', intdiv($minutos(format_time($bloquePosterior['hora_inicio'])) + $desplazamiento, 60), ($minutos(format_time($bloquePosterior['hora_inicio'])) + $desplazamiento) % 60);
            $horaFinPosterior = sprintf('%02d:%02d', intdiv($minutos(format_time($bloquePosterior['hora_fin'])) + $desplazamiento, 60), ($minutos(format_time($bloquePosterior['hora_fin'])) + $desplazamiento) % 60);
            db_exec(
                'UPDATE bloques_horarios SET hora_inicio = ?, hora_fin = ? WHERE id_bloque = ? AND id_calendario = ?',
                [$horaInicioPosterior, $horaFinPosterior, $bloquePosterior['id_bloque'], $idCalendario]
            );
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
    flash_set('success', $bloquesPosteriores
        ? 'Bloque actualizado y ' . count($bloquesPosteriores) . ' bloques siguientes desplazados ' . $desplazamiento . ' minutos.'
        : 'Información actualizada correctamente.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action', '');
    try {
        if ($action === 'create' || $action === 'update') {
            save_bloque($action);
        } elseif ($action === 'delete') {
            $id = (int) post('id_bloque', '0');
            $bloque = db_one('SELECT * FROM bloques_horarios WHERE id_bloque = ?', [$id]);
            if (!$bloque) {
                throw new RuntimeException('No se encontró el bloque que desea eliminar.');
            }

            $used = db_count('SELECT COUNT(*) AS total FROM horario_semanal WHERE id_bloque = ?', [$id])
                + db_count('SELECT COUNT(*) AS total FROM disponibilidad_profesores WHERE id_bloque = ?', [$id])
                + db_count('SELECT COUNT(*) AS total FROM novedades_cambios_horario WHERE id_bloque = ?', [$id]);
            if ($used > 0) {
                $compactado = false;
                try {
                    $compactado = compactar_bloque_eliminado($bloque);
                } catch (RuntimeException $e) {
                    $compactado = false;
                }
                if ($compactado) {
                    $idCalendario = (int) $bloque['id_calendario'];
                    eliminar_calendario_si_esta_vacio($idCalendario);
                    flash_set('success', 'Se condensó la clase en el bloque siguiente y se eliminó el bloque actual.');
                } else {
                    $hayClasesEnBloque = db_count('SELECT COUNT(*) AS total FROM horario_semanal WHERE id_bloque = ?', [$id]) > 0
                        || db_count('SELECT COUNT(*) AS total FROM novedades_cambios_horario WHERE id_bloque = ?', [$id]) > 0;
                    if ($hayClasesEnBloque) {
                        throw new RuntimeException('No se puede borrar este bloque porque contiene clases o novedades que no se pueden compactar.');
                    }
                    db_exec('DELETE FROM horario_semanal WHERE id_bloque = ?', [$id]);
                    db_exec('DELETE FROM disponibilidad_profesores WHERE id_bloque = ?', [$id]);
                    db_exec('DELETE FROM novedades_cambios_horario WHERE id_bloque = ?', [$id]);
                    db_exec('DELETE FROM bloques_horarios WHERE id_bloque = ?', [$id]);
                    eliminar_calendario_si_esta_vacio((int) $bloque['id_calendario']);
                    eliminar_calendario_personalizado_si_es_redundante((string) db_one('SELECT id_grado FROM grados WHERE id_calendario = ? AND activo = 1 LIMIT 1', [(int) $bloque['id_calendario']])['id_grado'], (int) $bloque['id_calendario']);
                    flash_set('success', 'Se liberaron las referencias y el bloque quedó eliminado para permitir crear otro nuevo.');
                }
            } else {
                db_exec('DELETE FROM bloques_horarios WHERE id_bloque = ?', [$id]);
                eliminar_calendario_si_esta_vacio((int) $bloque['id_calendario']);
                eliminar_calendario_personalizado_si_es_redundante((string) db_one('SELECT id_grado FROM grados WHERE id_calendario = ? AND activo = 1 LIMIT 1', [(int) $bloque['id_calendario']])['id_grado'], (int) $bloque['id_calendario']);
                flash_set('success', 'Registro eliminado correctamente.');
            }
        }
    } catch (PDOException $e) {
        flash_set('error', unique_error($e, 'No se pudo guardar la información. Verifique los datos e inténtelo nuevamente.'));
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(APP_BASE . '/admin/bloques.php');
}

$rows = db_all(
    'SELECT b.*, c.nombre AS calendario,
            (SELECT CASE WHEN COUNT(*) = 1 THEN MAX(g.id_grado) ELSE CAST(b.id_calendario AS CHAR) END
             FROM grados g WHERE g.id_calendario = b.id_calendario AND g.activo = 1) AS calendario_visible
     FROM bloques_horarios b
     JOIN calendarios c ON c.id_calendario = b.id_calendario
     WHERE b.id_calendario IN (1, 2)
        OR NOT EXISTS (
            SELECT 1 FROM grados g
            WHERE g.id_calendario = b.id_calendario AND g.activo = 1
        )
        OR NOT EXISTS (
            SELECT 1 FROM bloques_horarios compartido
            WHERE compartido.id_calendario IN (1, 2)
              AND compartido.tipo = b.tipo
              AND compartido.hora_inicio = b.hora_inicio
              AND compartido.hora_fin = b.hora_fin
        )
     ORDER BY c.nombre, b.hora_inicio, b.numero_bloque'
);
$bloquesPorCalendario = [];
foreach (db_all(
    'SELECT id_bloque, id_calendario, numero_bloque, tipo,
            TIME_FORMAT(hora_inicio, \'%H:%i\') AS hora_inicio,
            TIME_FORMAT(hora_fin, \'%H:%i\') AS hora_fin
     FROM bloques_horarios
     ORDER BY id_calendario, hora_inicio, hora_fin, id_bloque'
) as $bloqueHorario) {
    $bloquesPorCalendario[(int) $bloqueHorario['id_calendario']][] = $bloqueHorario;
}

require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card">
    <div class="card-head">
        <h2>Bloques horarios</h2>
        <button class="btn btn-primary" data-open-modal="tpl-create" data-title="Nuevo bloque">🟢 Nuevo</button>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Calendario</th>
                <th>Número</th>
                <th>Tipo</th>
                <th>Horario</th>
                <th>Descripción</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e((string) $row['calendario_visible']) ?></td>
                    <td><?= $row['numero_bloque'] !== null ? (int) $row['numero_bloque'] : '—' ?></td>
                    <td><?= badge_estado($row['tipo']) ?></td>
                    <td><?= e(format_time($row['hora_inicio'])) ?> - <?= e(format_time($row['hora_fin'])) ?></td>
                    <td><?= e($row['descripcion'] ?: '—') ?></td>
                    <td class="actions">
                        <button class="btn btn-sm btn-secondary" data-edit="tpl-edit-<?= (int) $row['id_bloque'] ?>" data-title="Editar bloque">✏️ Editar</button>
                        <form method="post" data-confirm="¿Está seguro de que desea eliminar este registro?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_bloque" value="<?= (int) $row['id_bloque'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">🗑️ Eliminar</button>
                        </form>
                    </td>
                </tr>
                <template id="tpl-edit-<?= (int) $row['id_bloque'] ?>">
                    <?php
                    $bloquesCalendario = $bloquesPorCalendario[(int) $row['id_calendario']] ?? [];
                    $bloquesSiguientes = array_values(array_filter(
                        $bloquesCalendario,
                        static fn(array $bloqueHorario): bool => (int) $bloqueHorario['id_bloque'] !== (int) $row['id_bloque']
                            && $bloqueHorario['hora_inicio'] >= format_time($row['hora_fin'])
                    ));
                    ?>
                    <form method="post" data-shift-calendar-block data-block-number="<?= (int) ($row['numero_bloque'] ?? 0) ?>" data-original-end="<?= e(format_time($row['hora_fin'])) ?>" data-following-blocks="<?= e(json_encode($bloquesSiguientes, JSON_THROW_ON_ERROR)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id_bloque" value="<?= (int) $row['id_bloque'] ?>">
                        <input type="hidden" name="ajustar_bloques_siguientes" value="0">
                        <div class="field">
                            <label>Calendario</label>
                            <select name="id_calendario" required><?= options_html($calendarios, 'id_calendario', 'nombre', $row['id_calendario'], false) ?></select>
                        </div>
                        <div class="grid-2">
                            <div class="field"><label>Número de bloque</label><input type="number" min="1" name="numero_bloque" value="<?= e((string) $row['numero_bloque']) ?>"></div>
                            <div class="field">
                                <label>Tipo</label>
                                <select name="tipo">
                                    <option value="CLASE" <?= $row['tipo'] === 'CLASE' ? 'selected' : '' ?>>CLASE</option>
                                    <option value="DESCANSO" <?= $row['tipo'] === 'DESCANSO' ? 'selected' : '' ?>>DESCANSO</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid-2">
                            <div class="field"><label>Hora de inicio</label><input type="time" name="hora_inicio" required value="<?= e(format_time($row['hora_inicio'])) ?>"></div>
                            <div class="field"><label>Hora de finalización</label><input type="time" name="hora_fin" required value="<?= e(format_time($row['hora_fin'])) ?>"></div>
                        </div>
                        <div class="field"><label>Descripción</label><input name="descripcion" maxlength="<?= LIMITE_CARACTERES_DESCRIPCION_BLOQUE ?>" value="<?= e((string) $row['descripcion']) ?>"><small>Máximo <?= LIMITE_PALABRAS_DESCRIPCION_BLOQUE ?> palabras y <?= LIMITE_CARACTERES_DESCRIPCION_BLOQUE ?> caracteres.</small></div>
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
        <div class="field">
            <label>Calendario</label>
            <select name="id_calendario" required><?= options_html($calendarios, 'id_calendario', 'nombre') ?></select>
        </div>
        <div class="grid-2">
            <div class="field"><label>Número de bloque</label><input type="number" min="1" name="numero_bloque"></div>
            <div class="field">
                <label>Tipo</label>
                <select name="tipo">
                    <option value="CLASE">CLASE</option>
                    <option value="DESCANSO">DESCANSO</option>
                </select>
            </div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Hora de inicio</label><input type="time" name="hora_inicio" required></div>
            <div class="field"><label>Hora de finalización</label><input type="time" name="hora_fin" required></div>
        </div>
        <div class="field"><label>Descripción</label><input name="descripcion" maxlength="<?= LIMITE_CARACTERES_DESCRIPCION_BLOQUE ?>"><small>Máximo <?= LIMITE_PALABRAS_DESCRIPCION_BLOQUE ?> palabras y <?= LIMITE_CARACTERES_DESCRIPCION_BLOQUE ?> caracteres.</small></div>
        <button class="btn btn-primary" type="submit">Guardar</button>
    </form>
</template>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
