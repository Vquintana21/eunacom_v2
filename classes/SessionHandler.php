<?php

class DatabaseSessionHandler implements SessionHandlerInterface
{
    private $pdo;
    private $table = 'php_sessions';
    
    /**
     * Constructor - recibe conexión PDO
     */
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Abrir sesión
     */
    public function open($savePath, $sessionName) {
        return true;
    }
    
    /**
     * Cerrar sesión
     */
    public function close() {
        return true;
    }
    
    /**
     * Leer datos de sesión
     */
    public function read($sessionId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT session_data 
                FROM {$this->table} 
                WHERE session_id = ? AND expires_at > NOW()
            ");
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $row ? $row['session_data'] : '';
        } catch (PDOException $e) {
            error_log("[SessionHandler] Error al leer sesión: " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Escribir datos de sesión
     */
    public function write($sessionId, $sessionData) {
        try {
            $expires = date('Y-m-d H:i:s', time() + (int)ini_get('session.gc_maxlifetime'));
            
            // Usar REPLACE para insertar o actualizar
            $stmt = $this->pdo->prepare("
                REPLACE INTO {$this->table} (session_id, session_data, expires_at, updated_at)
                VALUES (?, ?, ?, NOW())
            ");
            
            return $stmt->execute([$sessionId, $sessionData, $expires]);
        } catch (PDOException $e) {
            error_log("[SessionHandler] Error al escribir sesión: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Destruir sesión
     */
    public function destroy($sessionId) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE session_id = ?");
            return $stmt->execute([$sessionId]);
        } catch (PDOException $e) {
            error_log("[SessionHandler] Error al destruir sesión: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Garbage Collection - limpiar sesiones expiradas
     */
    public function gc($maxlifetime) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE expires_at < NOW()");
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("[SessionHandler] Error en GC: " . $e->getMessage());
            return false;
        }
    }
}