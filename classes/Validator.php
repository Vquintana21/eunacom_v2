<?php
/**
 * ============================================
 * VALIDADOR CENTRALIZADO
 * ============================================
 * Funciones de validación reutilizables
 * Compatible con PHP 5.6+
 * ============================================
 */

class Validator {
    
    private $errores = array();
    private $datos = array();
    
    /**
     * Constructor
     * @param array $datos Datos a validar
     */
    public function __construct($datos = array()) {
        $this->datos = $datos;
    }
    
    /**
     * Validar campo requerido
     */
    public function requerido($campo, $mensaje = null) {
        $valor = isset($this->datos[$campo]) ? trim($this->datos[$campo]) : '';
        
        if (empty($valor)) {
            $this->errores[$campo] = $mensaje ?: "El campo {$campo} es requerido";
            return false;
        }
        return true;
    }
    
    /**
     * Validar email
     */
    public function email($campo, $mensaje = null) {
        $valor = isset($this->datos[$campo]) ? trim($this->datos[$campo]) : '';
        
        if (!empty($valor) && !filter_var($valor, FILTER_VALIDATE_EMAIL)) {
            $this->errores[$campo] = $mensaje ?: "El email no es válido";
            return false;
        }
        return true;
    }
    
    /**
     * Validar longitud mínima
     */
    public function minLength($campo, $min, $mensaje = null) {
        $valor = isset($this->datos[$campo]) ? $this->datos[$campo] : '';
        
        if (strlen($valor) < $min) {
            $this->errores[$campo] = $mensaje ?: "El campo {$campo} debe tener al menos {$min} caracteres";
            return false;
        }
        return true;
    }
    
    /**
     * Validar longitud máxima
     */
    public function maxLength($campo, $max, $mensaje = null) {
        $valor = isset($this->datos[$campo]) ? $this->datos[$campo] : '';
        
        if (strlen($valor) > $max) {
            $this->errores[$campo] = $mensaje ?: "El campo {$campo} no puede tener más de {$max} caracteres";
            return false;
        }
        return true;
    }
    
    /**
     * Validar que sea numérico
     */
    public function numerico($campo, $mensaje = null) {
        $valor = isset($this->datos[$campo]) ? $this->datos[$campo] : '';
        
        if (!empty($valor) && !is_numeric($valor)) {
            $this->errores[$campo] = $mensaje ?: "El campo {$campo} debe ser numérico";
            return false;
        }
        return true;
    }
    
    /**
     * Validar entero positivo
     */
    public function enteroPositivo($campo, $mensaje = null) {
        $valor = isset($this->datos[$campo]) ? $this->datos[$campo] : '';
        
        if (!empty($valor) && (!is_numeric($valor) || (int)$valor <= 0)) {
            $this->errores[$campo] = $mensaje ?: "El campo {$campo} debe ser un número entero positivo";
            return false;
        }
        return true;
    }
    
    /**
     * Validar que dos campos coincidan
     */
    public function coincide($campo1, $campo2, $mensaje = null) {
        $valor1 = isset($this->datos[$campo1]) ? $this->datos[$campo1] : '';
        $valor2 = isset($this->datos[$campo2]) ? $this->datos[$campo2] : '';
        
        if ($valor1 !== $valor2) {
            $this->errores[$campo2] = $mensaje ?: "Los campos no coinciden";
            return false;
        }
        return true;
    }
    
    /**
     * Validar que esté en una lista de valores permitidos
     */
    public function enLista($campo, $lista, $mensaje = null) {
        $valor = isset($this->datos[$campo]) ? $this->datos[$campo] : '';
        
        if (!empty($valor) && !in_array($valor, $lista)) {
            $this->errores[$campo] = $mensaje ?: "El valor seleccionado no es válido";
            return false;
        }
        return true;
    }
    
    /**
     * Validar rango numérico
     */
    public function rango($campo, $min, $max, $mensaje = null) {
        $valor = isset($this->datos[$campo]) ? $this->datos[$campo] : '';
        
        if (!empty($valor) && is_numeric($valor)) {
            if ($valor < $min || $valor > $max) {
                $this->errores[$campo] = $mensaje ?: "El valor debe estar entre {$min} y {$max}";
                return false;
            }
        }
        return true;
    }
    
    /**
     * ¿Pasó todas las validaciones?
     */
    public function esValido() {
        return empty($this->errores);
    }
    
    /**
     * Obtener errores
     */
    public function getErrores() {
        return $this->errores;
    }
    
    /**
     * Obtener primer error
     */
    public function getPrimerError() {
        return !empty($this->errores) ? reset($this->errores) : null;
    }
    
    /**
     * Obtener valor limpio
     */
    public function getValor($campo, $default = null) {
        return isset($this->datos[$campo]) ? trim($this->datos[$campo]) : $default;
    }
    
    /**
     * Obtener valor como entero
     */
    public function getInt($campo, $default = 0) {
        return isset($this->datos[$campo]) ? (int)$this->datos[$campo] : $default;
    }
    
    // ============================================
    // MÉTODOS ESTÁTICOS DE CONVENIENCIA
    // ============================================
    
    /**
     * Sanitizar string para salida HTML
     */
    public static function sanitizar($string) {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validar ID (entero positivo)
     */
    public static function esIdValido($id) {
        return is_numeric($id) && (int)$id > 0;
    }
    
    /**
     * Limpiar string de caracteres peligrosos
     */
    public static function limpiar($string) {
        $string = trim($string);
        $string = stripslashes($string);
        return $string;
    }
}