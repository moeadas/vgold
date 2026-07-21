<?php
// Simple PDO database wrapper
class DB {
    private static $pdo = null;

    public static function conn() {
        if (self::$pdo !== null) return self::$pdo;
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$pdo;
    }

    public static function query($sql, $params = []) {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch($sql, $params = []) {
        return self::query($sql, $params)->fetch();
    }

    public static function fetchAll($sql, $params = []) {
        return self::query($sql, $params)->fetchAll();
    }

    public static function insert($table, $data) {
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ":$c", $cols);
        $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
        self::query($sql, $data);
        return self::conn()->lastInsertId();
    }

    public static function update($table, $data, $where, $whereParams = []) {
        $set = array_map(fn($c) => "$c = :set_$c", array_keys($data));
        $sql = "UPDATE `$table` SET " . implode(',', $set) . " WHERE $where";
        $named = [];
        foreach ($data as $k => $v) $named[":set_$k"] = $v;
        // Convert positional ? params to named :w_N
        $whereNamed = [];
        $wi = 0;
        foreach ($whereParams as $wp) {
            $key = ":w_$wi";
            $sql = preg_replace('/\?/', $key, $sql, 1);
            $named[$key] = $wp;
            $wi++;
        }
        self::query($sql, $named);
        return self::query("SELECT ROW_COUNT()")->fetchColumn();
    }

    public static function delete($table, $where, $params = []) {
        $sql = "DELETE FROM `$table` WHERE $where";
        return self::query($sql, $params)->rowCount();
    }
}