<?php
/**
 * Generic Database Helper Class for MariaDB/MySQL
 * Provides dynamic insert, update, save, delete, fetchAll, fetchOne, fetchColumn methods.
 */

require_once __DIR__ . '/../config.php';

class DBHelper {
    /**
     * Dynamic Insert Record
     */
    public static function insert($table, array $data) {
        $db = getDB();
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');
        
        $sql = "INSERT INTO {$table} (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_values($data));
        return $db->lastInsertId();
    }

    /**
     * Dynamic Update Record
     */
    public static function update($table, array $data, $where_col = 'id', $where_val = null) {
        $db = getDB();
        $fields = array_keys($data);
        $set_parts = array_map(fn($f) => "{$f} = ?", $fields);
        
        $sql = "UPDATE {$table} SET " . implode(', ', $set_parts) . " WHERE {$where_col} = ?";
        $params = array_values($data);
        $params[] = $where_val;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Smart Save Record (Runs update if ID > 0, else runs insert)
     */
    public static function save($table, array $data, $id_col = 'id') {
        $id = intval($data[$id_col] ?? 0);
        unset($data[$id_col]);
        if ($id > 0) {
            self::update($table, $data, $id_col, $id);
            return $id;
        } else {
            return self::insert($table, $data);
        }
    }

    /**
     * Dynamic Delete Record
     */
    public static function delete($table, $where_col = 'id', $where_val = null) {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM {$table} WHERE {$where_col} = ?");
        $stmt->execute([$where_val]);
        return $stmt->rowCount();
    }

    /**
     * Fetch All Rows
     */
    public static function fetchAll($sql, $params = []) {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Fetch Single Row
     */
    public static function fetchOne($sql, $params = []) {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Fetch Single Column Value
     */
    public static function fetchColumn($sql, $params = []) {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
