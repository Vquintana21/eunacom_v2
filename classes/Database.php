<?php

class Database {
    
    // Instancia única de la clase
    private static $instance = null;
    
    // Conexión PDO
    private $connection;
    
    /**
     * Constructor privado (Patrón Singleton)
     * Solo se puede instanciar desde dentro de la clase
     */
    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                array(
                    // Modo de error: lanzar excepciones
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    
                    // Modo de fetch por defecto: array asociativo
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    
                    // No emular prepared statements (más seguro)
                    PDO::ATTR_EMULATE_PREPARES => false,
                    
                    // CRÍTICO: Conexión persistente (reutiliza conexiones)
                    PDO::ATTR_PERSISTENT => true,
                    
                    // Timeout de conexión (30 segundos)
                    PDO::ATTR_TIMEOUT => 30
                )
            );
           // Configurar SQL Mode para compatibilidad (opcional)
            if (defined('MYSQL_STRICT_MODE') && !MYSQL_STRICT_MODE) {
                $this->connection->exec("SET SESSION sql_mode = ''");
            }
            
        } catch (PDOException $e) {
            // Log del error
            error_log("[Database] Error de conexión: " . $e->getMessage());
            
            // Mensaje genérico al usuario (sin exponer detalles)
            die("Error al conectar con la base de datos. Por favor, contacte al administrador.");
        }
    }
    
    /**
     * Obtener la instancia única de Database
     * 
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Obtener la conexión PDO
     * 
     * @return PDO
     */
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * Ejecutar una consulta SELECT
     * 
     * @param string $sql Consulta SQL
     * @param array $params Parámetros para prepared statement
     * @return array Resultados
     */
    public function query($sql, $params = array()) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("[Database] Error en query: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Ejecutar una consulta SELECT y obtener una sola fila
     * 
     * @param string $sql Consulta SQL
     * @param array $params Parámetros para prepared statement
     * @return array|false Fila o false si no hay resultados
     */
    public function fetchOne($sql, $params = array()) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("[Database] Error en fetchOne: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Ejecutar un INSERT, UPDATE o DELETE
     * 
     * @param string $sql Consulta SQL
     * @param array $params Parámetros para prepared statement
     * @return bool Éxito de la operación
     */
    public function execute($sql, $params = array()) {
        try {
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("[Database] Error en execute: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Obtener el ID del último registro insertado
     * 
     * @return string
     */
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
    
    /**
     * Iniciar una transacción
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    /**
     * Confirmar una transacción
     */
    public function commit() {
        return $this->connection->commit();
    }
    
    /**
     * Revertir una transacción
     */
    public function rollBack() {
        return $this->connection->rollBack();
    }
    
    /**
     * Verificar si hay una transacción activa
     * 
     * @return bool
     */
    public function inTransaction() {
        return $this->connection->inTransaction();
    }
    
    /**
     * Prevenir la clonación de la instancia
     */
    private function __clone() {}
    
    /**
     * Prevenir la deserialización de la instancia
     */
    public function __wakeup() {
        throw new Exception("No se puede deserializar un singleton");
    }
}