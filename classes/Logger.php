<?php
/**
 * ============================================
 * SISTEMA DE LOGGING MEJORADO
 * ============================================
 * Registra eventos importantes para auditoría
 * Compatible con PHP 5.6+
 * ============================================
 */

class Logger {
    
    // Niveles de log
    const DEBUG = 'DEBUG';
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';
    const CRITICAL = 'CRITICAL';
    const SECURITY = 'SECURITY';
    
    private static $logDir = null;
    private static $logToDb = true;
    private static $logToFile = true;
    
    /**
     * Obtener directorio de logs
     */
    private static function getLogDir() {
        if (self::$logDir === null) {
            self::$logDir = __DIR__ . '/../logs';
            
            if (!is_dir(self::$logDir)) {
                mkdir(self::$logDir, 0755, true);
            }
        }
        return self::$logDir;
    }
    
    /**
     * Escribir log
     * 
     * @param string $level Nivel del log
     * @param string $mensaje Mensaje a registrar
     * @param array $contexto Datos adicionales
     * @param int|null $usuario_id ID del usuario (opcional)
     */
    public static function log($level, $mensaje, $contexto = array(), $usuario_id = null) {
        $timestamp = date('Y-m-d H:i:s');
        $ip = self::getClientIP();
        
        // Intentar obtener usuario de sesión si no se proporciona
        if ($usuario_id === null && isset($_SESSION['usuario_id'])) {
            $usuario_id = $_SESSION['usuario_id'];
        }
        
        // Log a archivo
        if (self::$logToFile) {
            self::writeToFile($timestamp, $level, $mensaje, $contexto, $usuario_id, $ip);
        }
        
        // Log a base de datos (solo para niveles importantes)
        if (self::$logToDb && in_array($level, array(self::WARNING, self::ERROR, self::CRITICAL, self::SECURITY))) {
            self::writeToDatabase($timestamp, $level, $mensaje, $contexto, $usuario_id, $ip);
        }
    }
    
    /**
     * Escribir a archivo
     */
    private static function writeToFile($timestamp, $level, $mensaje, $contexto, $usuario_id, $ip) {
        $logFile = self::getLogDir() . '/' . date('Y-m-d') . '.log';
        
        $contextoStr = !empty($contexto) ? ' | Contexto: ' . json_encode($contexto) : '';
        $usuarioStr = $usuario_id ? " | Usuario: {$usuario_id}" : '';
        
        $linea = "[{$timestamp}] [{$level}] [{$ip}]{$usuarioStr} {$mensaje}{$contextoStr}" . PHP_EOL;
        
        file_put_contents($logFile, $linea, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Escribir a base de datos
     */
    private static function writeToDatabase($timestamp, $level, $mensaje, $contexto, $usuario_id, $ip) {
        try {
            $pdo = Database::getInstance()->getConnection();
            
            $sql = "
                INSERT INTO log_sistema (nivel, mensaje, contexto, usuario_id, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array(
                $level,
                substr($mensaje, 0, 500), // Limitar longitud
                !empty($contexto) ? json_encode($contexto) : null,
                $usuario_id,
                $ip,
                isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
                $timestamp
            ));
            
        } catch (Exception $e) {
            // Si falla BD, al menos queda en archivo
            error_log("[Logger] Error al escribir en BD: " . $e->getMessage());
        }
    }
    
    /**
     * Obtener IP del cliente
     */
    private static function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'CLI';
        }
    }
    
    // ============================================
    // MÉTODOS DE CONVENIENCIA
    // ============================================
    
    public static function debug($mensaje, $contexto = array()) {
        if (defined('ENTORNO') && ENTORNO === 'desarrollo') {
            self::log(self::DEBUG, $mensaje, $contexto);
        }
    }
    
    public static function info($mensaje, $contexto = array()) {
        self::log(self::INFO, $mensaje, $contexto);
    }
    
    public static function warning($mensaje, $contexto = array()) {
        self::log(self::WARNING, $mensaje, $contexto);
    }
    
    public static function error($mensaje, $contexto = array()) {
        self::log(self::ERROR, $mensaje, $contexto);
    }
    
    public static function critical($mensaje, $contexto = array()) {
        self::log(self::CRITICAL, $mensaje, $contexto);
    }
    
    /**
     * Log de seguridad (intentos de acceso, CSRF fallidos, etc.)
     */
    public static function security($mensaje, $contexto = array()) {
        self::log(self::SECURITY, $mensaje, $contexto);
    }
    
    /**
     * Log de acceso a recursos (documentos, materiales)
     */
    public static function acceso($recurso, $recurso_id, $accion = 'ver') {
        self::log(self::INFO, "Acceso a {$recurso}", array(
            'recurso' => $recurso,
            'recurso_id' => $recurso_id,
            'accion' => $accion
        ));
    }
    
    /**
     * Log de examen/simulacro
     */
    public static function examen($examen_id, $accion, $detalles = array()) {
        self::log(self::INFO, "Examen: {$accion}", array_merge(array(
            'examen_id' => $examen_id,
            'accion' => $accion
        ), $detalles));
    }
}