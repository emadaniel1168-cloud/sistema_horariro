<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

function crear_calendario(string $nombre, string $descripcion): int
{
    $pdo = db();
    $iniciarTransaccion = !$pdo->inTransaction();
    if ($iniciarTransaccion) {
        $pdo->beginTransaction();
    }

    try {
        $ultimo = db_one('SELECT id_calendario FROM calendarios ORDER BY id_calendario DESC LIMIT 1 FOR UPDATE');
        $idCalendario = (int) ($ultimo['id_calendario'] ?? 0) + 1;
        db_exec(
            'INSERT INTO calendarios (id_calendario, nombre, descripcion) VALUES (?, ?, ?)',
            [$idCalendario, $nombre, $descripcion]
        );
        if ($iniciarTransaccion) {
            $pdo->commit();
        }
        return $idCalendario;
    } catch (Throwable $e) {
        if ($iniciarTransaccion && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function calendario_personalizado_es_redundante(string $idGrado, int $idCalendarioActual): bool
{
    $calendarioActual = db_one('SELECT nombre FROM calendarios WHERE id_calendario = ?', [$idCalendarioActual]);
    $nombreActual = trim((string) ($calendarioActual['nombre'] ?? ''));
    if ($nombreActual === '') {
        return false;
    }

    $patron = '/\s+-\s*' . preg_quote($idGrado, '/') . '$/';
    if (preg_match($patron, $nombreActual) !== 1) {
        return false;
    }

    $nombreBase = preg_replace($patron, '', $nombreActual);
    if ($nombreBase === '') {
        return false;
    }

    $base = db_one(
        'SELECT id_calendario FROM calendarios WHERE nombre = ? AND id_calendario <> ? ORDER BY id_calendario LIMIT 1',
        [$nombreBase, $idCalendarioActual]
    );
    if (!$base) {
        return false;
    }

    $idCalendarioBase = (int) $base['id_calendario'];
    $bloquesActuales = db_all(
        'SELECT tipo, hora_inicio, hora_fin, descripcion FROM bloques_horarios WHERE id_calendario = ? ORDER BY hora_inicio, hora_fin, id_bloque',
        [$idCalendarioActual]
    );
    $bloquesBase = db_all(
        'SELECT tipo, hora_inicio, hora_fin, descripcion FROM bloques_horarios WHERE id_calendario = ? ORDER BY hora_inicio, hora_fin, id_bloque',
        [$idCalendarioBase]
    );

    if (count($bloquesActuales) !== count($bloquesBase)) {
        return false;
    }

    foreach ($bloquesActuales as $indice => $bloqueActual) {
        $bloqueBase = $bloquesBase[$indice] ?? null;
        if (!$bloqueBase) {
            return false;
        }
        foreach (['tipo', 'hora_inicio', 'hora_fin', 'descripcion'] as $campo) {
            if (($bloqueActual[$campo] ?? null) !== ($bloqueBase[$campo] ?? null)) {
                return false;
            }
        }
    }

    return true;
}

function eliminar_calendario_personalizado_si_es_redundante(string $idGrado, int $idCalendarioActual): void
{
    $calendarioActual = db_one('SELECT nombre FROM calendarios WHERE id_calendario = ?', [$idCalendarioActual]);
    if (!$calendarioActual) {
        return;
    }

    $nombreActual = trim((string) ($calendarioActual['nombre'] ?? ''));
    if ($nombreActual === '') {
        return;
    }

    $patron = '/\s+-\s*' . preg_quote($idGrado, '/') . '$/';
    if (preg_match($patron, $nombreActual) !== 1) {
        return;
    }

    $nombreBase = preg_replace($patron, '', $nombreActual);
    if ($nombreBase === '') {
        return;
    }

    $base = db_one(
        'SELECT id_calendario FROM calendarios WHERE nombre = ? AND id_calendario <> ? ORDER BY id_calendario LIMIT 1',
        [$nombreBase, $idCalendarioActual]
    );
    if (!$base || !calendario_personalizado_es_redundante($idGrado, $idCalendarioActual)) {
        return;
    }

    db_exec('UPDATE grados SET id_calendario = ? WHERE id_grado = ? AND activo = 1', [(int) $base['id_calendario'], $idGrado]);
    db_exec('DELETE FROM bloques_horarios WHERE id_calendario = ?', [$idCalendarioActual]);
    db_exec('DELETE FROM calendarios WHERE id_calendario = ?', [$idCalendarioActual]);
}

function asegurar_columna_bloque_agregado_horario(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $columnas = array_column(db_all('SHOW COLUMNS FROM bloques_horarios'), 'Field');
    if (!in_array('agregado_desde_horario', $columnas, true)) {
        db()->exec('ALTER TABLE bloques_horarios ADD COLUMN agregado_desde_horario TINYINT(1) NOT NULL DEFAULT 0');
    }
    $ready = true;
}

function reordenar_bloques_clase_calendario(int $idCalendario): void
{
    $bloques = db_all(
        "SELECT id_bloque FROM bloques_horarios
         WHERE id_calendario = ? AND tipo = 'CLASE'
         ORDER BY hora_inicio, hora_fin, id_bloque",
        [$idCalendario]
    );
    $maxNumero = (int) db_one(
        "SELECT COALESCE(MAX(numero_bloque), 0) AS maximo
         FROM bloques_horarios WHERE id_calendario = ? AND tipo = 'CLASE'",
        [$idCalendario]
    )['maximo'];
    $desplazamiento = $maxNumero + count($bloques) + 1;
    db_exec(
        "UPDATE bloques_horarios SET numero_bloque = numero_bloque + ?
         WHERE id_calendario = ? AND tipo = 'CLASE'",
        [$desplazamiento, $idCalendario]
    );
    foreach ($bloques as $indice => $bloque) {
        db_exec('UPDATE bloques_horarios SET numero_bloque = ? WHERE id_bloque = ?', [$indice + 1, (int) $bloque['id_bloque']]);
    }
}

function asegurar_esquema_novedades(): void
{
    static $ready = false;
    if ($ready) return;

    asegurar_tabla_cambios_horario();
    $columns = db_all('SHOW COLUMNS FROM novedades_cambios_horario');
    $columnByName = [];
    foreach ($columns as $column) {
        $columnByName[$column['Field']] = $column['Type'];
    }
    $nullableSnapshotColumns = [
        'id_horario' => 'INT NULL',
        'id_grado' => 'VARCHAR(32) NULL',
        'dia' => 'VARCHAR(50) NULL',
        'materia' => 'VARCHAR(150) NULL',
        'hora_inicio' => 'TIME NULL',
        'hora_fin' => 'TIME NULL',
    ];
    $nullabilityByName = [];
    foreach ($columns as $column) {
        $nullabilityByName[$column['Field']] = $column['Null'];
    }
    foreach ($nullableSnapshotColumns as $nombre => $definicion) {
        if (($nullabilityByName[$nombre] ?? 'YES') === 'NO') {
            db()->exec("ALTER TABLE novedades_cambios_horario MODIFY COLUMN $nombre $definicion");
        }
    }
    $columnasNovedad = [
        'grupo_novedad' => 'CHAR(32) NULL',
        'id_dia' => 'INT NULL',
        'id_bloque' => 'INT NULL',
        'fecha' => 'DATE NULL',
        'nuevo_profesor' => 'INT NULL',
        'nueva_materia' => 'INT NULL',
        'nuevo_salon' => 'INT NULL',
        'nuevo_estado' => 'VARCHAR(20) NULL',
        'nueva_hora_inicio' => 'TIME NULL',
        'nueva_hora_fin' => 'TIME NULL',
        'observacion' => 'TEXT NULL',
        'estado' => "VARCHAR(20) NOT NULL DEFAULT 'HISTORICO'",
    ];
    foreach ($columnasNovedad as $nombre => $definicion) {
        if (!isset($columnByName[$nombre])) {
            db()->exec("ALTER TABLE novedades_cambios_horario ADD COLUMN $nombre $definicion");
        }
    }
    $ready = true;
}

function asegurar_tabla_horario_original_novedad(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS horario_original_novedad (
            id_horario INT NOT NULL,
            id_bloque INT NOT NULL,
            id_materia INT NOT NULL,
            id_profesor INT NULL,
            id_salon INT NULL,
            estado VARCHAR(20) NOT NULL,
            observacion TEXT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_horario),
            CONSTRAINT fk_horario_original_novedad_horario FOREIGN KEY (id_horario) REFERENCES horario_semanal (id_horario) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
}

function asegurar_tabla_descansos_reemplazados(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS bloques_descansos_reemplazados (
            id_bloque_agregado INT NOT NULL,
            numero_bloque INT NULL,
            hora_inicio TIME NOT NULL,
            hora_fin TIME NOT NULL,
            descripcion VARCHAR(100) NULL,
            agregado_desde_horario TINYINT(1) NOT NULL DEFAULT 0,
            hora_inicio_clase_original TIME NOT NULL,
            accion VARCHAR(20) NOT NULL DEFAULT \'REEMPLAZADO\',
            id_bloque_descanso_restante INT NULL,
            PRIMARY KEY (id_bloque_agregado),
            CONSTRAINT fk_descanso_reemplazado_bloque FOREIGN KEY (id_bloque_agregado)
                REFERENCES bloques_horarios (id_bloque) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $columnas = array_column(db_all('SHOW COLUMNS FROM bloques_descansos_reemplazados'), 'Field');
    if (!in_array('accion', $columnas, true)) {
        db()->exec("ALTER TABLE bloques_descansos_reemplazados ADD COLUMN accion VARCHAR(20) NOT NULL DEFAULT 'REEMPLAZADO'");
    }
    if (!in_array('id_bloque_descanso_restante', $columnas, true)) {
        db()->exec('ALTER TABLE bloques_descansos_reemplazados ADD COLUMN id_bloque_descanso_restante INT NULL');
    }
    $ready = true;
}

function asegurar_tabla_descansos_por_dia(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS descansos_por_dia (
            id_grado VARCHAR(10) NOT NULL,
            id_dia TINYINT NOT NULL,
            id_bloque INT NOT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_grado, id_dia, id_bloque),
            CONSTRAINT fk_descanso_dia_grado FOREIGN KEY (id_grado) REFERENCES grados (id_grado) ON DELETE CASCADE,
            CONSTRAINT fk_descanso_dia_dia FOREIGN KEY (id_dia) REFERENCES dias_semana (id_dia) ON DELETE CASCADE,
            CONSTRAINT fk_descanso_dia_bloque FOREIGN KEY (id_bloque) REFERENCES bloques_horarios (id_bloque) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
}

function guardar_horario_original_novedad(int $idHorario): void
{
    asegurar_tabla_horario_original_novedad();
    db_exec(
        'INSERT IGNORE INTO horario_original_novedad
            (id_horario, id_bloque, id_materia, id_profesor, id_salon, estado, observacion)
         SELECT id_horario, id_bloque, id_materia, id_profesor, id_salon, estado, observacion
         FROM horario_semanal WHERE id_horario = ?',
        [$idHorario]
    );
}

function eliminar_horario_original_novedad(int $idHorario): void
{
    asegurar_tabla_horario_original_novedad();
    db_exec('DELETE FROM horario_original_novedad WHERE id_horario = ?', [$idHorario]);
}

function restaurar_horario_original_si_no_hay_novedades(int $idHorario): void
{
    asegurar_tabla_horario_original_novedad();
    if (db_count("SELECT COUNT(*) AS total FROM novedades_cambios_horario WHERE id_horario = ? AND estado = 'ACTIVA'", [$idHorario]) > 0) {
        return;
    }

    $original = db_one(
        'SELECT id_bloque, id_materia, id_profesor, id_salon, estado, observacion
         FROM horario_original_novedad WHERE id_horario = ?',
        [$idHorario]
    );
    if ($original) {
        db_exec(
            'UPDATE horario_semanal
             SET id_bloque = ?, id_materia = ?, id_profesor = ?, id_salon = ?, estado = ?, observacion = ?
             WHERE id_horario = ?',
            [
                $original['id_bloque'],
                $original['id_materia'],
                $original['id_profesor'],
                $original['id_salon'],
                $original['estado'],
                $original['observacion'],
                $idHorario,
            ]
        );
    }
    eliminar_horario_original_novedad($idHorario);
}

function sincronizar_observacion_horario_novedad(int $idHorario, string $observacion): void
{
    $texto = trim((string) $observacion);
    $novedades = db_all(
        "SELECT id_cambio FROM novedades_cambios_horario WHERE id_horario = ? AND estado = 'ACTIVA' ORDER BY id_cambio DESC",
        [$idHorario]
    );
    foreach ($novedades as $novedad) {
        db_exec(
            'UPDATE novedades_cambios_horario SET observacion = ? WHERE id_cambio = ?',
            [$texto, (int) $novedad['id_cambio']]
        );
    }
}

function sincronizar_novedades_con_horario(): void
{
    db_exec(
        "UPDATE novedades_cambios_horario n
         LEFT JOIN horario_semanal h ON h.id_horario = n.id_horario
         SET n.estado = 'CANCELADA'
         WHERE n.estado = 'ACTIVA'
           AND n.id_horario IS NOT NULL
           AND h.id_horario IS NULL"
    );
}

function registrar_novedad_cancelacion_horario(int $idHorario, string $grupo, string $observacion, string $fecha): void
{
    $observacion = 'Clase cancelada desde el horario semanal.';
    asegurar_esquema_novedades();
    $existente = db_one(
        "SELECT id_cambio, observacion FROM novedades_cambios_horario
         WHERE id_horario = ? AND fecha = ? AND estado <> 'HISTORICO'
         ORDER BY id_cambio DESC LIMIT 1",
        [$idHorario, $fecha]
    );

    if ($existente) {
        db_exec(
            'UPDATE novedades_cambios_horario
             SET grupo_novedad = ?, observacion = ?, estado = "ACTIVA"
             WHERE id_cambio = ?',
            [$grupo, $observacion, (int) $existente['id_cambio']]
        );
        return;
    }

    db_exec(
        'INSERT INTO novedades_cambios_horario
            (id_horario, tipo_cambio, grupo_novedad, fecha, observacion, estado)
         VALUES (?, "NOVEDAD", ?, ?, ?, "ACTIVA")',
        [$idHorario, $grupo, $fecha, $observacion]
    );
}

function asegurar_novedades_clases_canceladas(): void
{
    asegurar_esquema_novedades();
    asegurar_tabla_descansos_por_dia();
    $clasesCanceladas = db_all(
        "SELECT h.id_horario, h.id_grado, h.id_dia
         FROM horario_semanal h
         WHERE h.estado = 'CANCELADA'
           AND NOT EXISTS (
               SELECT 1 FROM descansos_por_dia dp
               WHERE dp.id_grado = h.id_grado AND dp.id_dia = h.id_dia AND dp.id_bloque = h.id_bloque
           )
           AND NOT EXISTS (
               SELECT 1 FROM novedades_cambios_horario n
               WHERE n.id_horario = h.id_horario AND n.estado = 'ACTIVA'
           )
         ORDER BY h.id_grado, h.id_dia, h.id_bloque"
    );
    $gruposPorGrado = [];
    $hoy = new DateTimeImmutable('today');

    foreach ($clasesCanceladas as $clase) {
        $grado = (string) $clase['id_grado'];
        $gruposPorGrado[$grado] ??= bin2hex(random_bytes(16));
        $diasHastaClase = ((int) $clase['id_dia'] - (int) $hoy->format('N') + 7) % 7;
        $fecha = $hoy->modify('+' . $diasHastaClase . ' days')->format('Y-m-d');
        registrar_novedad_cancelacion_horario(
            (int) $clase['id_horario'],
            $gruposPorGrado[$grado],
            'Clase cancelada desde el horario semanal.',
            $fecha
        );
    }
}

function agrupar_novedades_activas_por_grado_fecha(): void
{
    $novedades = db_all(
        "SELECT n.id_cambio, n.grupo_novedad, n.fecha, h.id_grado
         FROM novedades_cambios_horario n
         JOIN horario_semanal h ON h.id_horario = n.id_horario
         WHERE n.estado = 'ACTIVA' AND h.estado = 'CANCELADA'
         ORDER BY n.fecha, h.id_grado, n.id_cambio"
    );

    foreach ($novedades as $novedad) {
        $grupo = md5('novedades:' . (string) $novedad['id_grado'] . ':' . (string) $novedad['fecha']);
        if ((string) ($novedad['grupo_novedad'] ?? '') === $grupo) {
            continue;
        }
        db_exec(
            'UPDATE novedades_cambios_horario SET grupo_novedad = ? WHERE id_cambio = ?',
            [$grupo, (int) $novedad['id_cambio']]
        );
    }
}

function asegurar_tabla_materia_profesores(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS materia_profesores (
            id_materia INT NOT NULL,
            id_profesor INT NOT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_materia, id_profesor),
            KEY idx_materia_profesores_profesor (id_profesor),
            CONSTRAINT fk_materia_profesores_materia FOREIGN KEY (id_materia) REFERENCES materias (id_materia) ON DELETE CASCADE,
            CONSTRAINT fk_materia_profesores_profesor FOREIGN KEY (id_profesor) REFERENCES profesores (id_profesor) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
}

function obtener_clases_canceladas_profesor(int $idProfesor): array
{
    return db_all(
        'SELECT h.id_horario,
                h.id_grado,
                d.nombre AS dia,
                m.nombre AS materia,
                TIME_FORMAT(b.hora_inicio, "%H:%i") AS hora_inicio,
                TIME_FORMAT(b.hora_fin, "%H:%i") AS hora_fin,
                h.estado AS estado_actual,
                h.observacion,
                n.fecha AS fecha_cancelacion,
                n.observacion AS motivo_cancelacion,
                CASE
                    WHEN h.estado = "CANCELADA" THEN "cancelada"
                    WHEN EXISTS (
                        SELECT 1 FROM novedades_cambios_horario nc
                        WHERE nc.id_horario = h.id_horario AND nc.estado = "ACTIVA" AND nc.tipo_cambio = "NOVEDAD"
                    ) THEN "reemplazada"
                    ELSE "restablecida"
                END AS estado_novedad
         FROM horario_semanal h
         JOIN grados g ON g.id_grado = h.id_grado
         JOIN dias_semana d ON d.id_dia = h.id_dia
         JOIN materias m ON m.id_materia = h.id_materia
         JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
         LEFT JOIN novedades_cambios_horario n
             ON n.id_horario = h.id_horario
            AND n.estado = "ACTIVA"
            AND n.tipo_cambio IN ("NOVEDAD", "CANCELACION")
         WHERE h.id_profesor = ?
           AND h.estado = "CANCELADA"
         ORDER BY d.orden_dia, b.hora_inicio, h.id_grado',
        [$idProfesor]
    );
}

function activar_o_desactivar_clases_profesor(int $idProfesor, bool $activar): void
{
    $clases = db_all(
        'SELECT h.id_horario, h.id_grado, h.id_dia, h.id_bloque, h.id_materia, h.id_profesor, h.id_salon,
                h.estado, h.observacion
         FROM horario_semanal h
         WHERE h.id_profesor = ?
           AND h.estado <> "DESCANSO"',
        [$idProfesor]
    );
    foreach ($clases as $clase) {
        $idHorario = (int) $clase['id_horario'];
        if ($activar) {
            $original = db_one(
                'SELECT id_bloque, id_materia, id_profesor, id_salon, estado, observacion
                 FROM horario_original_novedad WHERE id_horario = ?',
                [$idHorario]
            );
            if ($original) {
                db_exec(
                    'UPDATE horario_semanal
                     SET id_bloque = ?, id_materia = ?, id_profesor = ?, id_salon = ?, estado = ?, observacion = ?
                     WHERE id_horario = ?',
                    [
                        $original['id_bloque'],
                        $original['id_materia'],
                        $original['id_profesor'],
                        $original['id_salon'],
                        $original['estado'],
                        $original['observacion'],
                        $idHorario,
                    ]
                );
                db_exec('DELETE FROM horario_original_novedad WHERE id_horario = ?', [$idHorario]);
            } else {
                db_exec(
                    'UPDATE horario_semanal SET estado = "PROGRAMADA", observacion = "" WHERE id_horario = ?',
                    [$idHorario]
                );
            }
            if (db_count(
                'SELECT COUNT(*) AS total FROM novedades_cambios_horario WHERE id_horario = ? AND fecha = CURDATE() AND tipo_cambio = "NOVEDAD" AND estado = "ACTIVA"',
                [$idHorario]
            ) === 0) {
                $gradoActual = db_one('SELECT id_grado FROM horario_semanal WHERE id_horario = ?', [$idHorario]);
                db_exec(
                    'INSERT INTO novedades_cambios_horario (tipo_cambio, id_horario, id_grado, fecha, observacion, estado)
                     VALUES (?, ?, ?, CURDATE(), ?, "ACTIVA")',
                    ['NOVEDAD', $idHorario, $gradoActual['id_grado'] ?? null, 'Profesor reactivado y programación restablecida.']
                );
            }
            continue;
        }

        guardar_horario_original_novedad($idHorario);
        db_exec(
            'UPDATE horario_semanal
             SET estado = "CANCELADA",
                 observacion = COALESCE(CONCAT(observacion, " | Cancelada por inactividad del profesor"), "Cancelada por inactividad del profesor")
             WHERE id_horario = ?',
            [$idHorario]
        );
        if (db_count(
            'SELECT COUNT(*) AS total FROM novedades_cambios_horario WHERE id_horario = ? AND fecha = CURDATE() AND tipo_cambio = "CANCELACION" AND estado = "ACTIVA"',
            [$idHorario]
        ) === 0) {
            $gradoActual = db_one('SELECT id_grado FROM horario_semanal WHERE id_horario = ?', [$idHorario]);
            db_exec(
                'INSERT INTO novedades_cambios_horario (tipo_cambio, id_horario, id_grado, fecha, observacion, estado)
                 VALUES (?, ?, ?, CURDATE(), ?, "ACTIVA")',
                ['CANCELACION', $idHorario, $gradoActual['id_grado'] ?? null, 'Clase cancelada por inactividad del profesor.']
            );
        }
    }
}

function asegurar_tabla_novedades_temporales(): void
{
    static $ready = false;
    if ($ready) {
        db_exec('UPDATE novedades_temporales SET expira_en = DATE_ADD(creado_en, INTERVAL 20 SECOND) WHERE expira_en > DATE_ADD(creado_en, INTERVAL 20 SECOND)');
        db_exec('DELETE FROM novedades_temporales WHERE expira_en <= NOW()');
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS novedades_temporales (
            id_temporal INT NOT NULL AUTO_INCREMENT,
            grupo_novedad CHAR(32) NOT NULL,
            id_horario INT NOT NULL,
            expira_en DATETIME NOT NULL,
            observacion TEXT NOT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_temporal),
            KEY idx_novedades_temporales_expira (expira_en),
            KEY idx_novedades_temporales_grupo (grupo_novedad),
            CONSTRAINT fk_novedad_temporal_horario FOREIGN KEY (id_horario) REFERENCES horario_semanal (id_horario) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
    db_exec('UPDATE novedades_temporales SET expira_en = DATE_ADD(creado_en, INTERVAL 20 SECOND) WHERE expira_en > DATE_ADD(creado_en, INTERVAL 20 SECOND)');
    db_exec('DELETE FROM novedades_temporales WHERE expira_en <= NOW()');
}

function limpiar_avisos_temporales_eliminacion(): void
{
    db_exec(
        "DELETE FROM novedades_cambios_horario
         WHERE tipo_cambio = 'ELIMINACION'
           AND estado <> 'ARCHIVADO'
           AND creado_en <= DATE_SUB(NOW(), INTERVAL 20 SECOND)"
    );
}

function asegurar_tabla_cambios_horario(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS novedades_cambios_horario (
            id_cambio BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_horario INT NULL,
            tipo_cambio VARCHAR(20) NOT NULL,
            grupo_novedad CHAR(32) NULL,
            fecha DATE NULL,
            nuevo_profesor INT NULL,
            nueva_materia INT NULL,
            nuevo_salon INT NULL,
            nueva_hora_inicio TIME NULL,
            nueva_hora_fin TIME NULL,
            observacion TEXT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT \'HISTORICO\',
            id_grado VARCHAR(32) NULL,
            dia VARCHAR(50) NULL,
            materia VARCHAR(150) NULL,
            hora_inicio TIME NULL,
            hora_fin TIME NULL,
            profesor VARCHAR(150) NULL,
            salon VARCHAR(150) NULL,
            detalle TEXT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_cambio),
            KEY idx_cambios_horario_fecha (creado_en),
            KEY idx_cambios_horario_grado (id_grado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
}

function registrar_cambio_horario(int $idHorario, string $tipo, string $detalle): void
{
    asegurar_tabla_cambios_horario();
    $clase = db_one(
        "SELECT h.id_horario, h.id_grado, d.nombre AS dia, m.nombre AS materia,
                b.hora_inicio, b.hora_fin, p.nombre_completo AS profesor, s.nombre AS salon
         FROM horario_semanal h
         JOIN dias_semana d ON d.id_dia = h.id_dia
         JOIN materias m ON m.id_materia = h.id_materia
         JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
         LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
         LEFT JOIN salones s ON s.id_salon = h.id_salon
         WHERE h.id_horario = ?",
        [$idHorario]
    );
    if (!$clase) {
        throw new RuntimeException('No se pudo guardar la novedad porque la clase no existe.');
    }

    db_exec(
        'INSERT INTO novedades_cambios_horario
            (id_horario, tipo_cambio, id_grado, dia, materia, hora_inicio, hora_fin, profesor, salon, detalle)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $clase['id_horario'], $tipo, $clase['id_grado'], $clase['dia'], $clase['materia'],
            $clase['hora_inicio'], $clase['hora_fin'], $clase['profesor'], $clase['salon'], $detalle,
        ]
    );
}

function registrar_novedad_por_cambio_profesor(string $tipoRecurso, int $idRecurso, ?int $nuevoProfesorId): void
{
    if ($nuevoProfesorId === null) {
        return;
    }
    asegurar_esquema_novedades();

    $campo = $tipoRecurso === 'materia' ? 'id_materia' : 'id_salon';
    $clases = db_all(
        "SELECT h.id_horario, h.id_profesor, h.id_grado, h.id_dia, h.id_materia, h.id_salon,
                m.nombre AS materia, d.nombre AS dia, b.hora_inicio, b.hora_fin
         FROM horario_semanal h
         JOIN materias m ON m.id_materia = h.id_materia
         JOIN dias_semana d ON d.id_dia = h.id_dia
         JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
         WHERE h.$campo = ? AND h.estado = 'PROGRAMADA'",
        [$idRecurso]
    );
    $fechaHoy = (new DateTimeImmutable('today'))->format('Y-m-d');

    $gruposPorGrado = [];
    foreach ($clases as $clase) {
        $profesorActual = $clase['id_profesor'] ? (int) $clase['id_profesor'] : null;
        if ($profesorActual === $nuevoProfesorId) {
            continue;
        }
        $yaExiste = db_count(
            "SELECT COUNT(*) AS total FROM novedades_cambios_horario
             WHERE id_horario = ? AND fecha = ? AND nuevo_profesor = ? AND estado = 'ACTIVA'",
            [$clase['id_horario'], $fechaHoy, $nuevoProfesorId]
        );
        if ($yaExiste > 0) {
            continue;
        }

        $gradoClave = (string) $clase['id_grado'];
        $gruposPorGrado[$gradoClave] ??= bin2hex(random_bytes(16));

        $detalle = $tipoRecurso === 'materia'
            ? 'Cambio de profesor asignado a la materia ' . $clase['materia'] . ' para el grado ' . $clase['id_grado'] . '.'
            : 'Cambio de profesor asignado al salón del grado ' . $clase['id_grado'] . ' para la materia ' . $clase['materia'] . '.';

        db_exec(
            'INSERT INTO novedades_cambios_horario (tipo_cambio, grupo_novedad, id_horario, fecha, nuevo_profesor, nueva_materia, nuevo_salon, observacion, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['NOVEDAD', $gruposPorGrado[$gradoClave], $clase['id_horario'], $fechaHoy, $nuevoProfesorId, null, null, $detalle, 'ACTIVA']
        );
    }
}

function registrar_novedad_por_desvincular_profesor(string $tipoRecurso, int $idRecurso, int $profesorId): void
{
    asegurar_esquema_novedades();
    $campo = $tipoRecurso === 'materia' ? 'id_materia' : 'id_salon';
    $clases = db_all(
        "SELECT h.id_horario, h.id_grado, h.id_profesor, m.nombre AS materia
         FROM horario_semanal h
         JOIN materias m ON m.id_materia = h.id_materia
         WHERE h.$campo = ? AND h.id_profesor = ? AND h.estado = 'PROGRAMADA'
         ORDER BY h.id_grado, h.id_dia, h.id_bloque",
        [$idRecurso, $profesorId]
    );

    if ($clases === []) {
        return;
    }

    $profesor = db_one('SELECT nombre_completo FROM profesores WHERE id_profesor = ?', [$profesorId]);
    $recurso = $tipoRecurso === 'materia'
        ? db_one('SELECT nombre FROM materias WHERE id_materia = ?', [$idRecurso])
        : db_one('SELECT nombre FROM salones WHERE id_salon = ?', [$idRecurso]);

    $nombreProfesor = $profesor['nombre_completo'] ?? 'Profesor sin nombre';
    $nombreRecurso = $recurso['nombre'] ?? 'registro';
    $gruposPorGrado = [];
    foreach ($clases as $clase) {
        $gradoClave = (string) $clase['id_grado'];
        $gruposPorGrado[$gradoClave] ??= bin2hex(random_bytes(16));
    }
    $titulo = $tipoRecurso === 'materia'
        ? 'Profesor eliminado de la materia ' . $nombreRecurso . ': ' . $nombreProfesor
        : 'Profesor eliminado del salón ' . $nombreRecurso . ': ' . $nombreProfesor;
    $fechaHoy = (new DateTimeImmutable('today'))->format('Y-m-d');

    foreach ($clases as $clase) {
        guardar_horario_original_novedad((int) $clase['id_horario']);
        $novedadExistente = db_one(
            'SELECT id_cambio FROM novedades_cambios_horario WHERE id_horario = ? AND fecha = ? AND estado <> "HISTORICO"',
            [$clase['id_horario'], $fechaHoy]
        );

        $gradoClave = (string) $clase['id_grado'];
        $detalle = $titulo . '. Grado ' . $gradoClave . ': el profesor ya no da la materia en este curso.';

        if ($novedadExistente) {
            db_exec(
                'UPDATE novedades_cambios_horario
                 SET grupo_novedad = ?, nuevo_profesor = NULL, nueva_materia = NULL, nuevo_salon = NULL,
                     nueva_hora_inicio = NULL, nueva_hora_fin = NULL, observacion = ?, estado = ?
                 WHERE id_cambio = ?',
                [$gruposPorGrado[$gradoClave], $detalle, 'ACTIVA', $novedadExistente['id_cambio']]
            );
        } else {
            db_exec(
                'INSERT INTO novedades_cambios_horario (tipo_cambio, grupo_novedad, id_horario, fecha, nuevo_profesor, nueva_materia, nuevo_salon, observacion, estado)
                 VALUES (?, ?, ?, ?, NULL, NULL, NULL, ?, ?)',
                ['NOVEDAD', $gruposPorGrado[$gradoClave], $clase['id_horario'], $fechaHoy, $detalle, 'ACTIVA']
            );
        }
    }
}

function ocupaciones_horario(int $dia, string $horaInicio, string $horaFin, ?string $fecha = null, ?int $ignorarHorario = null, ?int $ignorarAdicion = null): array
{
    if ($fecha !== null) {
                asegurar_esquema_novedades();
        $sql = "SELECT h.id_horario, h.id_grado, d.nombre AS dia, m.nombre AS materia,
                                             TIME_FORMAT(COALESCE(n.nueva_hora_inicio, b.hora_inicio), '%H:%i') AS hora_inicio,
                                             TIME_FORMAT(COALESCE(n.nueva_hora_fin, b.hora_fin), '%H:%i') AS hora_fin,
                                             COALESCE(n.nueva_materia, h.id_materia) AS id_materia,
                                             COALESCE(mn.nombre, m.nombre) AS materia,
                                             COALESCE(n.nuevo_profesor, h.id_profesor) AS id_profesor,
                                             COALESCE(pn.nombre_completo, p.nombre_completo) AS profesor,
                                             COALESCE(n.nuevo_salon, h.id_salon) AS id_salon,
                                             COALESCE(sn.nombre, s.nombre) AS salon
                FROM horario_semanal h
                JOIN dias_semana d ON d.id_dia = h.id_dia
                JOIN materias m ON m.id_materia = h.id_materia
                JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                                LEFT JOIN novedades_cambios_horario n ON n.id_horario = h.id_horario AND n.fecha = ? AND n.estado = 'ACTIVA'
                LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
                                LEFT JOIN profesores pn ON pn.id_profesor = n.nuevo_profesor
                LEFT JOIN salones s ON s.id_salon = h.id_salon
                                LEFT JOIN salones sn ON sn.id_salon = n.nuevo_salon
                                LEFT JOIN materias mn ON mn.id_materia = n.nueva_materia
                                WHERE h.id_dia = ? AND h.estado = 'PROGRAMADA'
                                    AND COALESCE(n.nueva_hora_inicio, b.hora_inicio) < ?
                                    AND COALESCE(n.nueva_hora_fin, b.hora_fin) > ?";
                $params = [$fecha, $dia, $horaFin, $horaInicio];
    } else {
        $sql = "SELECT h.id_horario, h.id_grado, d.nombre AS dia, m.nombre AS materia,
                       TIME_FORMAT(b.hora_inicio, '%H:%i') AS hora_inicio,
                       TIME_FORMAT(b.hora_fin, '%H:%i') AS hora_fin,
                       h.id_profesor, p.nombre_completo AS profesor,
                       h.id_salon, s.nombre AS salon
                FROM horario_semanal h
                JOIN dias_semana d ON d.id_dia = h.id_dia
                JOIN materias m ON m.id_materia = h.id_materia
                JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
                LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
                LEFT JOIN salones s ON s.id_salon = h.id_salon
                WHERE h.id_dia = ? AND h.estado = 'PROGRAMADA'
                  AND b.hora_inicio < ? AND b.hora_fin > ?";
        $params = [$dia, $horaFin, $horaInicio];
    }

    if ($ignorarHorario !== null) {
        $sql .= ' AND h.id_horario <> ?';
        $params[] = $ignorarHorario;
    }
    $sql .= ' ORDER BY b.hora_inicio, h.id_grado';
    $ocupaciones = db_all($sql, $params);
    if ($fecha !== null) {
        $sqlAdiciones =
            "SELECT n.id_grado, d.nombre AS dia,
                    TIME_FORMAT(b.hora_inicio, '%H:%i') AS hora_inicio,
                    TIME_FORMAT(b.hora_fin, '%H:%i') AS hora_fin,
                    n.nueva_materia AS id_materia, m.nombre AS materia,
                    n.nuevo_profesor AS id_profesor, p.nombre_completo AS profesor,
                    n.nuevo_salon AS id_salon, s.nombre AS salon
             FROM novedades_cambios_horario n
             JOIN dias_semana d ON d.id_dia = n.id_dia
             JOIN bloques_horarios b ON b.id_bloque = n.id_bloque
             JOIN materias m ON m.id_materia = n.nueva_materia
             LEFT JOIN profesores p ON p.id_profesor = n.nuevo_profesor
             LEFT JOIN salones s ON s.id_salon = n.nuevo_salon
                         WHERE n.tipo_cambio = 'ADICION' AND n.fecha = ? AND n.estado = 'ACTIVA'
                             AND COALESCE(n.nuevo_estado, 'PROGRAMADA') = 'PROGRAMADA'
               AND b.hora_inicio < ? AND b.hora_fin > ?";
        $paramsAdiciones = [$fecha, $horaFin, $horaInicio];
        if ($ignorarAdicion !== null) {
            $sqlAdiciones .= ' AND n.id_cambio <> ?';
            $paramsAdiciones[] = $ignorarAdicion;
        }
        $adiciones = db_all($sqlAdiciones, $paramsAdiciones);
        array_push($ocupaciones, ...$adiciones);
    }
    return $ocupaciones;
}

function validar_disponibilidad_horario(
    int $dia,
    string $horaInicio,
    string $horaFin,
    ?int $profesorId,
    ?int $salonId,
    ?int $ignorarHorario = null,
    ?string $fecha = null,
    ?int $ignorarAdicion = null
): void {
    $ocupaciones = ocupaciones_horario($dia, $horaInicio, $horaFin, $fecha, $ignorarHorario, $ignorarAdicion);
    foreach ($ocupaciones as $ocupacion) {
        if ($profesorId !== null && (int) ($ocupacion['id_profesor'] ?? 0) === $profesorId) {
            throw new RuntimeException(
                'El profesor ' . $ocupacion['profesor'] . ' ya tiene clase de ' . $ocupacion['materia'] .
                ' con el grado ' . $ocupacion['id_grado'] . ' en el salón ' . ($ocupacion['salon'] ?: 'sin salón') .
                ' de ' . $ocupacion['hora_inicio'] . ' a ' . $ocupacion['hora_fin'] . '.'
            );
        }
        if ($salonId !== null && (int) ($ocupacion['id_salon'] ?? 0) === $salonId) {
            throw new RuntimeException(
                'El salón ' . $ocupacion['salon'] . ' ya está ocupado por el grado ' . $ocupacion['id_grado'] .
                ' con ' . $ocupacion['materia'] . ' y el profesor ' . ($ocupacion['profesor'] ?: 'sin profesor') .
                ' de ' . $ocupacion['hora_inicio'] . ' a ' . $ocupacion['hora_fin'] . '.'
            );
        }
    }
}

function resumen_clase_horario(int $idHorario): string
{
    $clase = db_one(
        "SELECT h.id_grado, d.nombre AS dia, m.nombre AS materia,
                TIME_FORMAT(b.hora_inicio, '%H:%i') AS hora_inicio,
                TIME_FORMAT(b.hora_fin, '%H:%i') AS hora_fin,
                p.nombre_completo AS profesor, s.nombre AS salon
         FROM horario_semanal h
         JOIN dias_semana d ON d.id_dia = h.id_dia
         JOIN materias m ON m.id_materia = h.id_materia
         JOIN bloques_horarios b ON b.id_bloque = h.id_bloque
         LEFT JOIN profesores p ON p.id_profesor = h.id_profesor
         LEFT JOIN salones s ON s.id_salon = h.id_salon
         WHERE h.id_horario = ?",
        [$idHorario]
    );
    if (!$clase) {
        return '';
    }
    return $clase['dia'] . ' ' . $clase['hora_inicio'] . '-' . $clase['hora_fin'] .
        ': grado ' . $clase['id_grado'] . ', ' . $clase['materia'] . ', profesor ' .
        ($clase['profesor'] ?: 'sin asignar') . ', salón ' . ($clase['salon'] ?: 'sin salón') . '.';
}

const APP_NAME = 'Colegio Santander';
$appScriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
$appBase = preg_replace('~/[^/]+$~', '', $appScriptPath) ?? '';
if (str_ends_with($appBase, '/admin')) {
    $appBase = substr($appBase, 0, -strlen('/admin'));
}
define('APP_BASE', $appBase);
const LIMITE_PALABRAS_DESCRIPCION_BLOQUE = 12;
const LIMITE_CARACTERES_DESCRIPCION_BLOQUE = 100;

function validar_descripcion_bloque(?string $descripcion): string
{
    $descripcion = trim((string) $descripcion);
    $caracteres = preg_match_all('/./us', $descripcion);
    $palabras = preg_split('/\s+/u', $descripcion, -1, PREG_SPLIT_NO_EMPTY);
    if ($caracteres === false || $palabras === false) {
        throw new RuntimeException('La descripción contiene texto no válido.');
    }
    if ($caracteres > LIMITE_CARACTERES_DESCRIPCION_BLOQUE) {
        throw new RuntimeException('La descripción admite máximo ' . LIMITE_CARACTERES_DESCRIPCION_BLOQUE . ' caracteres.');
    }
    if (count($palabras) > LIMITE_PALABRAS_DESCRIPCION_BLOQUE) {
        throw new RuntimeException('La descripción admite máximo ' . LIMITE_PALABRAS_DESCRIPCION_BLOQUE . ' palabras.');
    }
    return $descripcion;
}

function normalizar_hora_bloque(?string $hora): ?string
{
    $hora = strtoupper(trim((string) $hora));
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $hora, $matches) === 1) {
        $horas = (int) $matches[1];
        $minutos = (int) $matches[2];
        if ($horas > 23 || $minutos > 59) {
            return null;
        }
        return sprintf('%02d:%02d', $horas, $minutos);
    }
    if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/', $hora, $matches) !== 1) {
        return null;
    }
    $horas = (int) $matches[1];
    $minutos = (int) $matches[2];
    if ($horas < 1 || $horas > 12 || $minutos > 59) {
        return null;
    }
    $horas %= 12;
    if ($matches[3] === 'PM') {
        $horas += 12;
    }
    return sprintf('%02d:%02d', $horas, $minutos);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function current_page(): string
{
    return basename($_SERVER['PHP_SELF'] ?? '');
}

function is_active(string $file): string
{
    return current_page() === $file ? 'active' : '';
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $token = $_POST['csrf'] ?? '';
    return is_string($token) && hash_equals($_SESSION['csrf'] ?? '', $token);
}

function post(string $key, ?string $default = null): ?string
{
    if (!isset($_POST[$key])) {
        return $default;
    }
    $value = trim((string) $_POST[$key]);
    return $value === '' ? $default : $value;
}

function get(string $key, ?string $default = null): ?string
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    $value = trim((string) $_GET[$key]);
    return $value === '' ? $default : $value;
}

function require_csrf(): void
{
    if (!csrf_verify()) {
        flash_set('error', 'La sesión expiró. Intente nuevamente.');
        redirect($_SERVER['REQUEST_URI'] ?? (APP_BASE . '/admin/index.php'));
    }
}

function asegurar_tabla_admin(): void
{
    $adminTableExists = db_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin'"
    ) !== null;
    $legacyTableExists = db_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'administrador'"
    ) !== null;

    if (!$adminTableExists && $legacyTableExists) {
        db_exec('RENAME TABLE administrador TO admin');
    }

    db_exec(
        'CREATE TABLE IF NOT EXISTS admin (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            correo VARCHAR(254) NOT NULL UNIQUE,
            nombre VARCHAR(100) NOT NULL,
            contrasena VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (db_one('SELECT id FROM admin LIMIT 1') === null) {
        db_exec(
            'INSERT INTO admin (correo, nombre, contrasena) VALUES (?, ?, ?)',
            ['administrador1234@gmail.com', 'Administrador', password_hash('123admin', PASSWORD_DEFAULT)]
        );
    }
}

function require_admin_auth(): void
{
    asegurar_tabla_admin();
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $admin = $adminId > 0
        ? db_one('SELECT id, correo, nombre FROM admin WHERE id = ?', [$adminId])
        : null;

    if ($admin === null) {
        unset($_SESSION['admin_id'], $_SESSION['admin_nombre'], $_SESSION['admin_correo']);
        redirect(APP_BASE . '/admin/login.php');
    }

    $_SESSION['admin_nombre'] = $admin['nombre'];
    $_SESSION['admin_correo'] = $admin['correo'];
}

function pretty_name(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return str_replace('_', ' ', $value);
}

function format_time(?string $time): string
{
    if ($time === null || $time === '') {
        return '—';
    }
    return substr($time, 0, 5);
}

function badge_activo(int|string|bool $activo): string
{
    $on = (int) $activo === 1;
    $class = $on ? 'badge badge-ok' : 'badge badge-off';
    $text = $on ? 'Activo' : 'Inactivo';
    return '<span class="' . $class . '">' . $text . '</span>';
}

function badge_estado(string $estado): string
{
    $map = [
        'ACTIVA' => 'badge-ok',
        'PROGRAMADA' => 'badge-ok',
        'DISPONIBLE' => 'badge-ok',
        'CANCELADA' => 'badge-warn',
        'LIBRE' => 'badge-muted',
        'NO_DISPONIBLE' => 'badge-off',
        'CLASE' => 'badge-ok',
        'DESCANSO' => 'badge-muted',
    ];
    $class = $map[$estado] ?? 'badge-muted';
    return '<span class="badge ' . $class . '">' . e(str_replace('_', ' ', $estado)) . '</span>';
}

function pagination(int $total, int $page, int $perPage, array $query = []): string
{
    $pages = max(1, (int) ceil($total / $perPage));
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        $params = array_merge($query, ['page' => $i]);
        $url = '?' . http_build_query($params);
        $cls = $i === $page ? 'page-btn active' : 'page-btn';
        $html .= '<a class="' . $cls . '" href="' . e($url) . '">' . $i . '</a>';
    }
    $html .= '</nav>';
    return $html;
}

function exists_id(string $table, string $column, mixed $id): bool
{
    $allowed = [
        'profesores' => 'id_profesor',
        'materias' => 'id_materia',
        'grados' => 'id_grado',
        'calendarios' => 'id_calendario',
        'salones' => 'id_salon',
        'lugares' => 'id_lugar',
        'dias_semana' => 'id_dia',
        'bloques_horarios' => 'id_bloque',
        'horario_semanal' => 'id_horario',
    ];
    if (!isset($allowed[$table]) || $allowed[$table] !== $column) {
        return false;
    }
    return db_one("SELECT $column AS id FROM `$table` WHERE `$column` = ? LIMIT 1", [$id]) !== null;
}

function detectar_tabla_desde_sql(string $mensaje): ?string
{
    if (preg_match('/(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?([A-Za-z0-9_]+)`?/i', $mensaje, $coincidencias) === 1) {
        return $coincidencias[1];
    }

    return null;
}

function unique_error(PDOException $e, string $fallback): string
{
    $mensaje = $e->getMessage();

    if (str_contains($mensaje, 'Duplicate') || (int) $e->getCode() === 23000) {
        $tabla = detectar_tabla_desde_sql($mensaje);
        if ($tabla !== null) {
            $base = 'Se detectó un registro duplicado al intentar guardar en la tabla \'' . $tabla . '\'.';
            if (strtolower($tabla) === 'novedades_cambios_horario') {
                return $base . ' Esto suele ocurrir cuando ya existe una novedad activa para ese mismo horario, día y grado, o cuando un profesor ya está asignado a esa hora con ese curso.';
            }
            return $base . ' Verifique que no exista ya ese dato en esa tabla.';
        }

        return 'Ya existe un registro con esos datos. Verifique que no esté duplicado.';
    }
    if (str_contains($mensaje, 'foreign key') || str_contains($mensaje, 'Cannot delete')) {
        return 'No se puede completar la operación porque el registro está relacionado con otra información.';
    }
    return $fallback;
}

function options_html(array $rows, string $idKey, string $labelKey, mixed $selected = null, bool $empty = true): string
{
    $html = $empty ? '<option value="">Seleccione una opción</option>' : '';
    foreach ($rows as $row) {
        $id = (string) $row[$idKey];
        $sel = ((string) $selected === $id) ? ' selected' : '';
        $html .= '<option value="' . e($id) . '"' . $sel . '>' . e(pretty_name((string) $row[$labelKey])) . '</option>';
    }
    return $html;
}

function page_title(string $title): string
{
    return $title . ' | ' . APP_NAME;
}
