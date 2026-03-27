<?php
require_once __DIR__ . '/config.php';

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];
            // Azure MySQL requires SSL
            if (strpos(DB_HOST, 'azure.com') !== false) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = '';
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            die('A system error occurred. Please try again later.');
        }
    }
    return $pdo;
}

function query($sql, $params = []) {
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function fetch_one($sql, $params = []) {
    return query($sql, $params)->fetch();
}

function fetch_all($sql, $params = []) {
    return query($sql, $params)->fetchAll();
}

function insert($table, $data) {
    $columns = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
    query($sql, array_values($data));
    return get_db()->lastInsertId();
}

function update($table, $data, $where, $whereParams = []) {
    $set = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
    $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
    query($sql, array_merge(array_values($data), $whereParams));
}

function delete_row($table, $where, $params = []) {
    query("DELETE FROM {$table} WHERE {$where}", $params);
}
