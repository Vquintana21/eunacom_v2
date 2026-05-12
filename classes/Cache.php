<?php
/**
 * ============================================
 * SISTEMA DE CACHÉ SIMPLE
 * ============================================
 * Caché en archivo para datos que cambian poco
 * Compatible con PHP 5.6+
 * ============================================
 */

class Cache {
    
    private static $cacheDir = null;
    private static $memoryCache = array();
    
    /**
     * Obtener directorio de caché
     */
    private static function getCacheDir() {
        if (self::$cacheDir === null) {
            self::$cacheDir = __DIR__ . '/cache';
            
            // Crear directorio si no existe
            if (!is_dir(self::$cacheDir)) {
                mkdir(self::$cacheDir, 0755, true);
            }
        }
        return self::$cacheDir;
    }
    
    /**
     * Generar nombre de archivo de caché
     */
    private static function getCacheFile($key) {
        return self::getCacheDir() . '/' . md5($key) . '.cache';
    }
    
    /**
     * Obtener valor del caché
     * 
     * @param string $key Clave del caché
     * @param int $ttl Tiempo de vida en segundos (default: 300 = 5 min)
     * @return mixed|null Valor cacheado o null si no existe/expiró
     */
    public static function get($key, $ttl = 300) {
        // Primero buscar en memoria (más rápido)
        if (isset(self::$memoryCache[$key])) {
            $cached = self::$memoryCache[$key];
            if (time() < $cached['expires']) {
                return $cached['data'];
            }
            unset(self::$memoryCache[$key]);
        }
        
        // Buscar en archivo
        $file = self::getCacheFile($key);
        
        if (!file_exists($file)) {
            return null;
        }
        
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }
        
        $cached = unserialize($content);
        
        if ($cached === false || !isset($cached['expires']) || !isset($cached['data'])) {
            return null;
        }
        
        // Verificar expiración
        if (time() > $cached['expires']) {
            @unlink($file);
            return null;
        }
        
        // Guardar en memoria para accesos subsecuentes
        self::$memoryCache[$key] = $cached;
        
        return $cached['data'];
    }
    
    /**
     * Guardar valor en caché
     * 
     * @param string $key Clave del caché
     * @param mixed $data Datos a cachear
     * @param int $ttl Tiempo de vida en segundos
     * @return bool Éxito
     */
    public static function set($key, $data, $ttl = 300) {
        $cached = array(
            'data' => $data,
            'expires' => time() + $ttl,
            'created' => time()
        );
        
        // Guardar en memoria
        self::$memoryCache[$key] = $cached;
        
        // Guardar en archivo
        $file = self::getCacheFile($key);
        $result = file_put_contents($file, serialize($cached), LOCK_EX);
        
        return $result !== false;
    }
    
    /**
     * Eliminar valor del caché
     * 
     * @param string $key Clave del caché
     * @return bool Éxito
     */
    public static function delete($key) {
        unset(self::$memoryCache[$key]);
        
        $file = self::getCacheFile($key);
        if (file_exists($file)) {
            return @unlink($file);
        }
        return true;
    }
    
    /**
     * Limpiar todo el caché
     * 
     * @return bool Éxito
     */
    public static function clear() {
        self::$memoryCache = array();
        
        $dir = self::getCacheDir();
        $files = glob($dir . '/*.cache');
        
        foreach ($files as $file) {
            @unlink($file);
        }
        
        return true;
    }
    
    /**
     * Obtener o calcular (helper útil)
     * Si existe en caché, retorna. Si no, ejecuta callback y guarda.
     * 
     * @param string $key Clave del caché
     * @param callable $callback Función que retorna los datos
     * @param int $ttl Tiempo de vida
     * @return mixed Datos
     */
    public static function remember($key, $callback, $ttl = 300) {
        $cached = self::get($key, $ttl);
        
        if ($cached !== null) {
            return $cached;
        }
        
        $data = call_user_func($callback);
        self::set($key, $data, $ttl);
        
        return $data;
    }
}