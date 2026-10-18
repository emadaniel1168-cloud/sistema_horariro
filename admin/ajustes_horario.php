<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/includes/functions.php';

$sectionTitle = 'Ajustes generales del horario';
$preview = null;
$calendarioSeleccionado = 'todos';
$fechaCancelacion = date('Y-m-d');
$horaEntrada = '';
$horasEntrada = ['06:15', '07:10', '08:05', '09:00', '09:30', '09:55', '10:25', '11:20', '12:15', '13:10'];

function ajuste_horario_minutos(string $hora): int
{
    return ((int) substr($hora, 0, 2) * 60) + (int) substr($hora, 3, 2);
}

function ajuste_horario_formato(int $minutos): string
{
    return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
}

function registrar_novedad_reprogramacion_entrada(
    int $idHorario,
    string $grupoNovedad,
    string $fecha,
    string $horaInicio,
    string $horaFin,
    string $horaEntrada
): void {
    $detalle = 'AJUSTE_GENERAL_ENTRADA=' . $horaEntrada;
    $observacion = 'Clase reprogramada por la hora de entrada de ajustes generales.';
    $existente = db_one(
        "SELECT id_cambio FROM novedades_cambios_horario
         WHERE id_horario = ? AND fecha = ? AND estado <> 'HISTORICO'
         ORDER BY id_cambio DESC LIMIT 1",
        [$idHorario, $fecha]
    );

    if ($existente) {
        db_exec(
            "UPDATE novedades_cambios_horario
             SET tipo_cambio = 'NOVEDAD', grupo_novedad = ?, nueva_hora_inicio = ?, nueva_hora_fin = ?,
                 observacion = ?, estado = 'ACTIVA', detalle = ?
             WHERE id_cambio = ?",
            [$grupoNovedad, $horaInicio, $horaFin, $observacion, $detalle, (int) $existente['id_cambio']]
        );
        return;
    }

    db_exec(
        "INSERT INTO novedades_cambios_horario
            (tipo_cambio, grupo_novedad, id_horario, fecha, nueva_hora_inicio, nueva_hora_fin, observacion, estado, detalle)
         VALUES ('NOVEDAD', ?, ?, ?, ?, ?, ?, 'ACTIVA', ?)",
        [$grupoNovedad, $idHorario, $fecha, $horaInicio, $horaFin, $observacion, $detalle]
    );
}

function generar_plan_ajuste_horario(string $calendario, string $fecha, string $horaEntrada): array
{
    $horasEntradaPermitidas = ['06:15', '07:10', '08:05', '09:00', '09:30', '09:55', '10:25', '11:20', '12:15', '13:10'];
    if (!in_array($horaEntrada, $horasEntradaPermitidas, true)) {
        throw new RuntimeException('Seleccione una hora de entrada válida.');
    }
    if (!in_array($calendario, ['todos', '1', '2'], true)) {
        throw new RuntimeException('Seleccione un calendario válido.');
    }
    $fechaValidada = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if (!$fechaValidada || $fechaValidada->format('Y-m-d') !== $fecha) {
        throw new RuntimeException('Seleccione una fecha válida.');
    }
    if ($fecha < date('Y-m-d')) {
        throw new RuntimeException('La fecha no puede ser anterior al día de hoy.');
    }

    $condiciones = [
        "b.tipo = 'CLASE'",
        'b.hora_inicio < ?',
        "COALESCE(h.estado, 'PROGRAMADA') <> 'CANCELADA'",
        'h.id_dia = ?',
        'g.activo = 1',
    ];
    $parametros = [$horaEntrada, (int) $fechaValidada->format('N')];
    if ($calendario !== 'todos') {
        $condiciones[] = 'g.id_calendario = ?';
        $parametros[] = (int) $calendario;
    }
    $clases = db_all(
        "SELECT h.id_horario, h.id_grado, h.id_dia, h.id_bloque,
                d.nombre AS dia, b.numero_bloque,
                TIME_FORMAT(b.hora_inicio, '%H:%i') AS hora_inicio,
                TIME_FORMAT(b.hora_fin, '%H:%i') AS hora_fin,
                m.nombre AS materia
         FROM horario_semanal h
         JOIN grados g ON g.id_grado = h.id_grado
         JOIN dias_semana d ON d.id_dia = h.id_dia
         JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
         JOIN materias m ON m.id_materia = h.id_materia
         WHERE " . implode(' AND ', $condiciones) . "
         ORDER BY g.id_calendario, h.id_grado, d.orden_dia, b.hora_inicio, h.id_horario",
        $parametros
    );
    $horaEntradaMinutos = ajuste_horario_minutos($horaEntrada);
    return array_map(static function (array $clase) use ($horaEntrada, $horaEntradaMinutos): array {
        $inicioClase = ajuste_horario_minutos($clase['hora_inicio']);
        $finClase = ajuste_horario_minutos($clase['hora_fin']);
        $reprogramar = $inicioClase < $horaEntradaMinutos && $finClase > $horaEntradaMinutos;
        $nuevoFin = $horaEntradaMinutos + ($finClase - $inicioClase);
        if ($reprogramar && $nuevoFin >= 24 * 60) {
            throw new RuntimeException('La clase que coincide con la hora de entrada excedería el final del día.');
        }

        return [
            'id_horario' => (int) $clase['id_horario'],
            'id_grado' => (string) $clase['id_grado'],
            'id_dia' => (int) $clase['id_dia'],
            'id_bloque' => (int) $clase['id_bloque'],
            'dia' => $clase['dia'],
            'numero_bloque' => (int) $clase['numero_bloque'],
            'hora_inicio' => $clase['hora_inicio'],
            'hora_fin' => $clase['hora_fin'],
            'materia' => $clase['materia'],
            'accion' => $reprogramar ? 'REPROGRAMAR' : 'CANCELAR',
            'hora_inicio_nueva' => $reprogramar ? $horaEntrada : null,
            'hora_fin_nueva' => $reprogramar ? ajuste_horario_formato($nuevoFin) : null,
        ];
    }, $clases);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action', 'preview');
    $calendarioSeleccionado = post('calendario', 'todos') ?? 'todos';
    $fechaCancelacion = post('fecha_cancelacion', '') ?? '';
    $horaEntrada = post('hora_entrada', '') ?? '';
    $grupoNovedad = null;
    $planActual = [];
    try {
        if ($action === 'preview') {
            $preview = generar_plan_ajuste_horario($calendarioSeleccionado, $fechaCancelacion, $horaEntrada);
            $_SESSION['ajuste_horario_preview'] = [
                'calendario' => $calendarioSeleccionado,
                'fecha_cancelacion' => $fechaCancelacion,
                'hora_entrada' => $horaEntrada,
                'plan' => $preview,
                'token' => bin2hex(random_bytes(16)),
            ];
        } elseif ($action === 'apply') {
            $guardado = $_SESSION['ajuste_horario_preview'] ?? null;
            if (!$guardado || !hash_equals((string) ($guardado['token'] ?? ''), (string) post('preview_token', ''))) {
                throw new RuntimeException('La vista previa expiró. Revise el ajuste antes de aplicarlo.');
            }
            if (
                $calendarioSeleccionado !== $guardado['calendario']
                || $fechaCancelacion !== $guardado['fecha_cancelacion']
                || $horaEntrada !== $guardado['hora_entrada']
            ) {
                throw new RuntimeException('El ajuste cambió desde la vista previa. Genere una nueva vista previa.');
            }

            db()->beginTransaction();
            try {
                $planActual = generar_plan_ajuste_horario($calendarioSeleccionado, $fechaCancelacion, $horaEntrada);
                if ($planActual !== $guardado['plan']) {
                    throw new RuntimeException('Las clases cambiaron desde la vista previa. Genere una nueva vista previa.');
                }
                asegurar_esquema_novedades();
                $grupoNovedad = bin2hex(random_bytes(16));
                foreach ($planActual as $clase) {
                    if ($clase['accion'] === 'REPROGRAMAR') {
                        registrar_novedad_reprogramacion_entrada(
                            $clase['id_horario'],
                            $grupoNovedad,
                            $fechaCancelacion,
                            $clase['hora_inicio_nueva'],
                            $clase['hora_fin_nueva'],
                            $horaEntrada
                        );
                    } else {
                        registrar_novedad_cancelacion_horario(
                            $clase['id_horario'],
                            $grupoNovedad,
                            'Clase cancelada desde el horario semanal.',
                            $fechaCancelacion
                        );
                        db_exec(
                            'UPDATE novedades_cambios_horario SET nueva_hora_inicio = NULL, nueva_hora_fin = NULL, detalle = ? WHERE id_horario = ? AND fecha = ? AND grupo_novedad = ?',
                            ['AJUSTE_GENERAL_ENTRADA=' . $horaEntrada, $clase['id_horario'], $fechaCancelacion, $grupoNovedad]
                        );
                    }
                }
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
            unset($_SESSION['ajuste_horario_preview']);
            flash_set('success', 'Se aplicaron los cambios de hora de entrada para la fecha seleccionada.');
            redirect(APP_BASE . '/admin/ajustes_horario.php');
        }
    } catch (PDOException $e) {
        if ($action === 'apply' && $grupoNovedad !== null && $planActual !== []) {
            try {
                $cancelacionesGuardadas = db_count(
                    "SELECT COUNT(*) AS total FROM novedades_cambios_horario
                     WHERE grupo_novedad = ? AND fecha = ? AND estado = 'ACTIVA' AND detalle = ?",
                    [$grupoNovedad, $fechaCancelacion, 'AJUSTE_GENERAL_ENTRADA=' . $horaEntrada]
                );
                if ($cancelacionesGuardadas === count($planActual)) {
                    unset($_SESSION['ajuste_horario_preview']);
                    flash_set('success', 'Las cancelaciones quedaron registradas correctamente para todos los horarios seleccionados.');
                    redirect(APP_BASE . '/admin/ajustes_horario.php');
                }
            } catch (Throwable $verificacionError) {
                error_log('No se pudo verificar el resultado de ajustes_horario.php: ' . $verificacionError->getMessage());
            }
        }
        error_log('Error PDO en ajustes_horario.php (' . $action . '): ' . $e->getMessage());
        $mensajeError = match ($action) {
            'preview' => 'No se pudo generar la vista previa del horario.',
            default => 'No se guardaron las cancelaciones del ajuste general.',
        };
        flash_set('error', unique_error($e, $mensajeError));
        $preview = null;
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        $preview = null;
    }
}

require dirname(__DIR__) . '/includes/header.php';
?>
<section class="card">
    <div class="card-head">
        <h2>Ajustes generales del horario</h2>
    </div>
    <form method="post" class="toolbar">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="preview">
        <div class="field">
            <label for="calendar-scope">Calendario</label>
            <select id="calendar-scope" name="calendario" required>
                <option value="todos" <?= $calendarioSeleccionado === 'todos' ? 'selected' : '' ?>>Todos los calendarios</option>
                <option value="1" <?= $calendarioSeleccionado === '1' ? 'selected' : '' ?>>Calendario 1</option>
                <option value="2" <?= $calendarioSeleccionado === '2' ? 'selected' : '' ?>>Calendario 2</option>
            </select>
        </div>
        <div class="field">
            <label for="cancel-date">Fecha</label>
            <input id="cancel-date" type="date" name="fecha_cancelacion" value="<?= e($fechaCancelacion) ?>" min="<?= e(date('Y-m-d')) ?>" required>
        </div>
        <div class="field">
            <label for="entry-time">Hora de entrada</label>
            <select id="entry-time" name="hora_entrada" required>
                <option value="">Seleccione una hora</option>
                <?php foreach ($horasEntrada as $hora): ?>
                    <option value="<?= e($hora) ?>" <?= $horaEntrada === $hora ? 'selected' : '' ?>><?= e(ltrim($hora, '0')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">Ver vista previa</button>
    </form>
    <p class="muted">Se cancelan las clases anteriores a la entrada; la clase que esté en curso a esa hora comienza allí y conserva su duración. Las clases siguientes mantienen su horario.</p>
</section>

<?php if ($preview !== null): ?>
    <section class="card">
        <div class="card-head">
            <h2>Vista previa · <?= count($preview) ?> clase(s) afectadas</h2>
        </div>
        <p class="muted">
            Todos los grados ·
            <?= $calendarioSeleccionado === 'todos' ? 'Todos los calendarios' : 'Calendario ' . e($calendarioSeleccionado) ?> ·
            <?= e($fechaCancelacion) ?> · entrada <?= e(ltrim($horaEntrada, '0')) ?> ·
            <?= count(array_filter($preview, static fn(array $clase): bool => $clase['accion'] === 'CANCELAR')) ?> canceladas ·
            <?= count(array_filter($preview, static fn(array $clase): bool => $clase['accion'] === 'REPROGRAMAR')) ?> reprogramadas
        </p>
        <?php if ($preview): ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Grado</th><th>Día</th><th>Bloque</th><th>Materia</th><th>Horario actual</th><th>Acción</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview as $clase): ?>
                        <tr>
                            <td><?= e($clase['id_grado']) ?></td>
                            <td><?= e($clase['dia']) ?></td>
                            <td><?= (int) $clase['numero_bloque'] ?></td>
                            <td><?= e($clase['materia']) ?></td>
                            <td><?= e($clase['hora_inicio']) ?>–<?= e($clase['hora_fin']) ?></td>
                            <td><?= $clase['accion'] === 'REPROGRAMAR'
                                ? 'Reprogramar · ' . e($clase['hora_inicio_nueva']) . '–' . e($clase['hora_fin_nueva'])
                                : 'Cancelar' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="muted">No hay clases que modificar para esa hora.</p>
        <?php endif; ?>
        <form method="post" class="actions" data-confirm="Se cancelarán las clases anteriores y se reprogramará la clase que coincida con la hora de entrada. ¿Desea continuar?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="calendario" value="<?= e($calendarioSeleccionado) ?>">
            <input type="hidden" name="fecha_cancelacion" value="<?= e($fechaCancelacion) ?>">
            <input type="hidden" name="hora_entrada" value="<?= e($horaEntrada) ?>">
            <input type="hidden" name="preview_token" value="<?= e((string) ($_SESSION['ajuste_horario_preview']['token'] ?? '')) ?>">
            <button class="btn btn-primary" type="submit" <?= !$preview ? 'disabled' : '' ?>>Aplicar ajuste de entrada</button>
        </form>
    </section>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>