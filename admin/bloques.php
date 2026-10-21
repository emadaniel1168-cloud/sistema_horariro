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

function firma_bloque_por_dia(string $idGrado, int $idBloque): array
{
    $bloque = db_one(
        'SELECT tipo FROM bloques_horarios WHERE id_bloque = ?',
        [$idBloque]
    );
    if (!$bloque) {
        return [];
    }

    $filas = db_all(
        'SELECT d.orden_dia, h.id_horario, h.id_materia, h.id_profesor, h.id_salon, h.estado, h.observacion
         FROM dias_semana d
         LEFT JOIN horario_semanal h
           ON h.id_dia = d.id_dia AND h.id_grado = ? AND h.id_bloque = ?
         WHERE d.orden_dia BETWEEN 1 AND 5
         ORDER BY d.orden_dia',
        [$idGrado, $idBloque]
    );
    $descansosMarcados = array_fill_keys(array_map(
        'intval',
        array_column(db_all(
            'SELECT d.orden_dia FROM descansos_por_dia dp
             JOIN dias_semana d ON d.id_dia = dp.id_dia
             WHERE dp.id_grado = ? AND dp.id_bloque = ? AND d.orden_dia BETWEEN 1 AND 5',
            [$idGrado, $idBloque]
        ), 'orden_dia')
    ), true);

    $firmas = [];
    foreach ($filas as $fila) {
        $dia = (int) $fila['orden_dia'];
        $esDescanso = isset($descansosMarcados[$dia])
            || ($fila['id_horario'] !== null && $fila['estado'] === 'DESCANSO')
            || ($fila['id_horario'] === null && $bloque['tipo'] === 'DESCANSO');
        if ($esDescanso) {
            $firmas[$dia] = ['DESCANSO'];
        } elseif ($fila['id_horario'] === null) {
            $firmas[$dia] = ['VACIO'];
        } else {
            $firmas[$dia] = [
                'CLASE',
                $fila['id_materia'],
                $fila['id_profesor'],
                $fila['id_salon'],
                $fila['estado'],
                $fila['observacion'],
            ];
        }
    }

    if (count($firmas) !== 5) {
        return [];
    }

    return $firmas;
}

function compactar_bloque_eliminado(array $bloque): bool
{
    asegurar_tabla_descansos_por_dia();
    asegurar_tabla_horario_original_novedad();
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $idBloque = (int) $bloque['id_bloque'];
        $idCalendario = (int) $bloque['id_calendario'];
        $bloque = db_one(
            'SELECT id_bloque, id_calendario, tipo, hora_inicio, hora_fin
             FROM bloques_horarios WHERE id_bloque = ? FOR UPDATE',
            [$idBloque]
        );
        if (!$bloque || (int) $bloque['id_calendario'] !== $idCalendario) {
            $pdo->rollBack();
            return false;
        }

        $anterior = db_one(
            'SELECT id_bloque, tipo, hora_inicio, hora_fin FROM bloques_horarios
             WHERE id_calendario = ? AND hora_fin = ? AND id_bloque NOT IN (24, 25)
             ORDER BY hora_inicio DESC, id_bloque DESC LIMIT 1 FOR UPDATE',
            [$idCalendario, $bloque['hora_inicio']]
        );
        $siguiente = db_one(
            'SELECT id_bloque, tipo, hora_inicio, hora_fin FROM bloques_horarios
             WHERE id_calendario = ? AND hora_inicio = ? AND id_bloque NOT IN (24, 25)
             ORDER BY hora_fin, id_bloque LIMIT 1 FOR UPDATE',
            [$idCalendario, $bloque['hora_fin']]
        );
        $candidatos = array_values(array_filter([$anterior, $siguiente]));
        usort($candidatos, static function (array $a, array $b): int {
            $prioridadA = $a['tipo'] === 'CLASE' ? 0 : 1;
            $prioridadB = $b['tipo'] === 'CLASE' ? 0 : 1;
            return ($prioridadA <=> $prioridadB)
                ?: ($a['hora_inicio'] <=> $b['hora_inicio']);
        });

        $grados = db_all(
            'SELECT id_grado FROM grados WHERE id_calendario = ?
             UNION
             SELECT id_grado FROM horario_semanal WHERE id_bloque IN (?, ?)
             UNION
             SELECT id_grado FROM descansos_por_dia WHERE id_bloque IN (?, ?)
             ORDER BY id_grado',
            [$idCalendario, $idBloque, $idBloque, $idBloque, $idBloque]
        );
        if (!$grados) {
            $pdo->rollBack();
            return false;
        }
        $vecinoCompatible = null;
        foreach ($candidatos as $candidato) {
            $idVecino = (int) $candidato['id_bloque'];
            if (db_count(
                'SELECT COUNT(*) AS total FROM novedades_cambios_horario WHERE id_bloque IN (?, ?)',
                [$idBloque, $idVecino]
            ) > 0
                || db_count(
                    'SELECT COUNT(*) AS total FROM disponibilidad_profesores WHERE id_bloque IN (?, ?)',
                    [$idBloque, $idVecino]
                ) > 0
                || db_count(
                    'SELECT COUNT(*) AS total FROM horario_original_novedad o
                     JOIN horario_semanal h ON h.id_horario = o.id_horario
                     WHERE h.id_bloque IN (?, ?)',
                    [$idBloque, $idVecino]
                ) > 0) {
                continue;
            }

            $compatible = true;
            foreach ($grados as $grado) {
                $idGrado = (string) $grado['id_grado'];
                $firmaBloque = firma_bloque_por_dia($idGrado, $idBloque);
                if (count($firmaBloque) !== 5
                    || $firmaBloque !== firma_bloque_por_dia($idGrado, $idVecino)) {
                    $compatible = false;
                    break;
                }
            }
            if ($compatible) {
                $vecinoCompatible = $candidato;
                break;
            }
        }

        if (!$vecinoCompatible) {
            $pdo->rollBack();
            return false;
        }

        $idVecino = (int) $vecinoCompatible['id_bloque'];
        $vecinoEsAnterior = $vecinoCompatible['hora_fin'] === $bloque['hora_inicio'];
        if ($vecinoEsAnterior) {
            db_exec(
                'UPDATE bloques_horarios SET hora_fin = ? WHERE id_bloque = ?',
                [$bloque['hora_fin'], $idVecino]
            );
        } else {
            db_exec(
                'UPDATE bloques_horarios SET hora_inicio = ? WHERE id_bloque = ?',
                [$bloque['hora_inicio'], $idVecino]
            );
        }

        db_exec(
            'INSERT IGNORE INTO descansos_por_dia (id_grado, id_dia, id_bloque)
             SELECT id_grado, id_dia, ? FROM descansos_por_dia WHERE id_bloque = ?',
            [$idVecino, $idBloque]
        );
        db_exec('DELETE FROM horario_semanal WHERE id_bloque = ?', [$idBloque]);
        db_exec('DELETE FROM bloques_horarios WHERE id_bloque = ?', [$idBloque]);
        reordenar_bloques_clase_calendario($idCalendario);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
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
                    flash_set('success', 'Se unificaron los bloques porque tienen las mismas clases o descansos de lunes a viernes.');
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
                $compactado = false;
                try {
                    $compactado = compactar_bloque_eliminado($bloque);
                } catch (RuntimeException $e) {
                    $compactado = false;
                }
                if ($compactado) {
                    flash_set('success', 'Se unificaron los bloques porque tienen las mismas clases o descansos de lunes a viernes.');
                } else {
                    db_exec('DELETE FROM bloques_horarios WHERE id_bloque = ?', [$id]);
                    eliminar_calendario_si_esta_vacio((int) $bloque['id_calendario']);
                    $gradoCalendario = db_one(
                        'SELECT id_grado FROM grados WHERE id_calendario = ? AND activo = 1 LIMIT 1',
                        [(int) $bloque['id_calendario']]
                    );
                    if ($gradoCalendario) {
                        eliminar_calendario_personalizado_si_es_redundante(
                            (string) $gradoCalendario['id_grado'],
                            (int) $bloque['id_calendario']
                        );
                    }
                    flash_set('success', 'Registro eliminado correctamente.');
                }
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
