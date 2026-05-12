<?php
/**
 * ============================================
 * RATE LIMITER - Control de Descargas
 * ============================================
 * Limita descargas por usuario para prevenir abuso
 * Compatible con PHP 5.6+
 * 
 * LINEAMIENTOS IMPLEMENTADOS:
 * - Prepared statements (SQL injection)
 * - Integración con Logger
 * - Manejo seguro de errores
 * ============================================
 */

class RateLimiter {
    
    private $pdo;
    
    // Límites por tipo (por hora)
    private $limites = array(
        'pdf'              => 100,  // 100 PDFs por hora
        'zip_especialidad' => 20,   // 20 ZIPs especialidad por hora
        'zip_area'         => 10    // 10 ZIPs área por hora
    );
    
    // Período en segundos (1 hora)
    private $periodo = 3600;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Verificar si el usuario puede descargar
     * 
     * @param int $usuario_id
     * @param string $tipo_descarga
     * @param string $ip_address
     * @return array
     */
    public function verificarLimite($usuario_id, $tipo_descarga, $ip_address) {
        // Validar tipo
        if (!isset($this->limites[$tipo_descarga])) {
            return array(
                'permitido' => false,
                'restantes' => 0,
                'mensaje' => 'Tipo de descarga no válido'
            );
        }
        
        $limite = $this->limites[$tipo_descarga];
        
        try {
            // Contar descargas en el período
            $sql = "
                SELECT COUNT(*) as total 
                FROM log_descargas 
                WHERE usuario_id = ? 
                AND tipo_descarga = ?
                AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array($usuario_id, $tipo_descarga, $this->periodo));
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $descargas_actuales = (int)$result['total'];
            $restantes = $limite - $descargas_actuales;
            
            if ($descargas_actuales >= $limite) {
                // Calcular tiempo de espera
                $sql = "
                    SELECT created_at 
                    FROM log_descargas 
                    WHERE usuario_id = ? 
                    AND tipo_descarga = ?
                    AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
                    ORDER BY created_at ASC 
                    LIMIT 1
                ";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array($usuario_id, $tipo_descarga, $this->periodo));
                $primera = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $tiempo_espera = '';
                if ($primera) {
                    $expira = strtotime($primera['created_at']) + $this->periodo;
                    $minutos = ceil(($expira - time()) / 60);
                    $tiempo_espera = " Intenta en {$minutos} minutos.";
                }
                
                // Log de rate limit alcanzado
                if (class_exists('Logger')) {
                    Logger::security("Rate limit descargas excedido", array(
                        'usuario_id' => $usuario_id,
                        'tipo' => $tipo_descarga,
                        'limite' => $limite,
                        'ip' => $ip_address
                    ));
                }
                
                return array(
                    'permitido' => false,
                    'restantes' => 0,
                    'mensaje' => "Límite alcanzado ({$limite}/hora).{$tiempo_espera}"
                );
            }
            
            return array(
                'permitido' => true,
                'restantes' => $restantes,
                'mensaje' => ''
            );
            
        } catch (PDOException $e) {
            if (class_exists('Logger')) {
                Logger::error("RateLimiter: Error verificando límite", array(
                    'error' => $e->getMessage(),
                    'usuario_id' => $usuario_id
                ));
            }
            // En caso de error de BD, permitir descarga (fail-open para UX)
            return array(
                'permitido' => true,
                'restantes' => 1,
                'mensaje' => ''
            );
        }
    }
    
    /**
     * Registrar una descarga
     * 
     * @param int $usuario_id
     * @param string $tipo_descarga
     * @param int|null $archivo_id
     * @param string $nombre_archivo
     * @param int $tamano_kb
     * @param string $ip_address
     * @return bool
     */
    public function registrarDescarga($usuario_id, $tipo_descarga, $archivo_id, $nombre_archivo, $tamano_kb, $ip_address) {
        try {
            $sql = "
                INSERT INTO log_descargas 
                (usuario_id, tipo_descarga, archivo_id, nombre_archivo, tamano_kb, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array(
                $usuario_id,
                $tipo_descarga,
                $archivo_id,
                $nombre_archivo,
                $tamano_kb,
                $ip_address
            ));
            
            // Log de descarga exitosa
            if (class_exists('Logger')) {
                Logger::info("Descarga realizada", array(
                    'usuario_id' => $usuario_id,
                    'tipo' => $tipo_descarga,
                    'archivo_id' => $archivo_id,
                    'archivo' => $nombre_archivo,
                    'tamano_kb' => $tamano_kb
                ));
            }
            
            return true;
            
        } catch (PDOException $e) {
            if (class_exists('Logger')) {
                Logger::error("RateLimiter: Error registrando descarga", array(
                    'error' => $e->getMessage(),
                    'usuario_id' => $usuario_id,
                    'archivo' => $nombre_archivo
                ));
            }
            return false;
        }
    }
    
    /**
     * Obtener estadísticas del usuario
     * 
     * @param int $usuario_id
     * @return array
     */
    public function obtenerEstadisticas($usuario_id) {
        $stats = array();
        
        foreach ($this->limites as $tipo => $limite) {
            try {
                $sql = "
                    SELECT COUNT(*) as total 
                    FROM log_descargas 
                    WHERE usuario_id = ? 
                    AND tipo_descarga = ?
                    AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
                ";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array($usuario_id, $tipo, $this->periodo));
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $stats[$tipo] = array(
                    'usadas' => (int)$result['total'],
                    'limite' => $limite,
                    'restantes' => $limite - (int)$result['total']
                );
            } catch (PDOException $e) {
                $stats[$tipo] = array(
                    'usadas' => 0, 
                    'limite' => $limite, 
                    'restantes' => $limite
                );
            }
        }
        
        return $stats;
    }
    
    /**
     * Limpiar registros antiguos (para cron job)
     * 
     * @param int $dias
     * @return int Registros eliminados
     */
    public function limpiarAntiguos($dias = 30) {
        try {
            $sql = "DELETE FROM log_descargas WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array($dias));
            $eliminados = $stmt->rowCount();
            
            if ($eliminados > 0 && class_exists('Logger')) {
                Logger::info("RateLimiter: Limpieza de registros", array(
                    'eliminados' => $eliminados,
                    'dias' => $dias
                ));
            }
            
            return $eliminados;
        } catch (PDOException $e) {
            return 0;
        }
    }
    
    /**
     * Configurar límite personalizado
     */
    public function setLimite($tipo, $limite) {
        if (isset($this->limites[$tipo])) {
            $this->limites[$tipo] = (int)$limite;
        }
    }
    
    /**
     * Configurar período personalizado
     */
    public function setPeriodo($segundos) {
        $this->periodo = (int)$segundos;
    }
}