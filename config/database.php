<?php

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'colegio_santander';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        exit('No fue posible conectar con la base de datos. Verifique que MySQL esté activo y que exista la base colegio_santander.');
    }

    return $pdo;
}

function db_all(string $sql, array $params = []): array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        throw new PDOException($e->getMessage() . ' | SQL: ' . preg_replace('/\s+/', ' ', trim($sql)), (int) $e->getCode(), $e);
    }
}

function db_one(string $sql, array $params = []): ?array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    } catch (PDOException $e) {
        throw new PDOException($e->getMessage() . ' | SQL: ' . preg_replace('/\s+/', ' ', trim($sql)), (int) $e->getCode(), $e);
    }
}

function db_exec(string $sql, array $params = []): int
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        throw new PDOException($e->getMessage() . ' | SQL: ' . preg_replace('/\s+/', ' ', trim($sql)), (int) $e->getCode(), $e);
    }
}

function db_count(string $sql, array $params = []): int
{
    try {
        $row = db_one($sql, $params);
        return (int) ($row['total'] ?? 0);
    } catch (PDOException $e) {
        throw new PDOException($e->getMessage() . ' | SQL: ' . preg_replace('/\s+/', ' ', trim($sql)), (int) $e->getCode(), $e);
    }
}
