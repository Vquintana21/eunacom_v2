<?php
/**
 * Generador de Simulacros EUNACOM - VERSIÓN LIMPIA (Sin logs)
 * Distribuye 180 preguntas según porcentajes oficiales
 */

class SimulacroGenerator {
    private $pdo;
    
    // Distribución oficial EUNACOM-ST
    private $distribucion = [
        // Medicina Interna: 37% (67 preguntas)
        1 => [
            'nombre' => 'Medicina Interna',
            'total' => 67,
            'especialidades' => [
                1  => 10, // Cardiología
                5  => 10, // Enfermedades Respiratorias
                6  => 10, // Gastroenterología
                3  => 5,  // Endocrinología
                2  => 5,  // Diabetes y Nutrición
                10 => 5,  // Neurología
                9  => 5,  // Nefrología
                8  => 5,  // Hémato-oncología
                4  => 4,  // Enfermedades Infecciosas
                11 => 4,  // Reumatología
                7  => 4   // Geriatría
            ]
        ],
        // Cirugía: 12% (20 preguntas)
        4 => [
            'nombre' => 'Cirugía',
            'total' => 20,
            'especialidades' => [
                1 => 10, // Cirugía General y Anestesia
                2 => 5,  // Traumatología
                3 => 5   // Urología
            ]
        ],
        // Pediatría: 16% (29 preguntas)
        2 => [
            'nombre' => 'Pediatría',
            'total' => 29,
            'especialidades' => [
                1 => 29 // Pediatría General
            ]
        ],
        // Obstetricia y Ginecología: 16% (29 preguntas)
        3 => [
            'nombre' => 'Obstetricia y Ginecología',
            'total' => 29,
            'especialidades' => [
                1 => 29 // Obstetricia y Ginecología
            ]
        ],
        // Psiquiatría: 8% (14 preguntas)
        5 => [
            'nombre' => 'Psiquiatría',
            'total' => 14,
            'especialidades' => [
                1 => 14 // Psiquiatría General
            ]
        ],
        // Salud Pública: 5% (9 preguntas)
        7 => [
            'nombre' => 'Salud Pública',
            'total' => 9,
            'especialidades' => [
                1 => 9 // Salud Pública
            ]
        ],
        // Especialidades: 6% (12 preguntas)
        6 => [
            'nombre' => 'Especialidades',
            'total' => 12,
            'especialidades' => [
                1 => 4, // Dermatología
                2 => 4, // Oftalmología
                3 => 4  // Otorrinolaringología
            ]
        ]
    ];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Genera un nuevo simulacro completo
     */
    public function generarSimulacro($usuario_id) {
        try {
            $this->pdo->beginTransaction();
            
            // 1. Crear registro de examen
            $codigo_examen = $this->generarCodigoUnico();
            
            $sql = "
                INSERT INTO examenes (usuario_id, codigo_examen, estado, sesion_actual)
                VALUES (?, ?, 'en_curso', 1)
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$usuario_id, $codigo_examen]);
            $examen_id = $this->pdo->lastInsertId();
            
            // 2. Seleccionar preguntas según distribución
            $preguntas_seleccionadas = $this->seleccionarPreguntas();
            
            // 3. Mezclar aleatoriamente
            shuffle($preguntas_seleccionadas);
            
            // 4. Dividir en 2 sesiones (90 + 90)
            $sesion1 = array_slice($preguntas_seleccionadas, 0, 90);
            $sesion2 = array_slice($preguntas_seleccionadas, 90, 90);
            
            // 5. Insertar preguntas del examen
            $this->insertarPreguntasExamen($examen_id, $sesion1, 1);
            $this->insertarPreguntasExamen($examen_id, $sesion2, 2);
            
            // 6. Crear registros de respuestas vacías
            $this->crearRespuestasVacias($examen_id, $preguntas_seleccionadas);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'examen_id' => $examen_id,
                'codigo_examen' => $codigo_examen
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }
    
    /**
     * Selecciona preguntas según distribución oficial
     */
    private function seleccionarPreguntas() {
        $preguntas_totales = [];
        
        foreach ($this->distribucion as $area_id => $area_config) {
            foreach ($area_config['especialidades'] as $codigo_esp => $cantidad) {
                
                // Obtener ID real de la especialidad
                $sql = "
                    SELECT id FROM especialidades 
                    WHERE area_id = ? AND codigo_especialidad = ?
                ";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$area_id, $codigo_esp]);
                $especialidad = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$especialidad) {
                    throw new Exception(
                        "No se encontró la especialidad con area_id={$area_id} y codigo_especialidad={$codigo_esp}"
                    );
                }
                
                $especialidad_id = $especialidad['id'];
                $cantidad_int = (int)$cantidad;
                
                // Seleccionar preguntas aleatorias
                $sql = "
                    SELECT p.id 
                    FROM preguntas p
                    INNER JOIN temas t ON p.tema_id = t.id
                    WHERE t.especialidad_id = ?
                    ORDER BY RAND()
                    LIMIT {$cantidad_int}
                ";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$especialidad_id]);
                $preguntas = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $encontradas = count($preguntas);
                
                // Validar que tengamos suficientes preguntas
                if ($encontradas < $cantidad) {
                    throw new Exception(
                        "No hay suficientes preguntas en especialidad ID {$especialidad_id}. " .
                        "Se necesitan {$cantidad}, solo hay {$encontradas}"
                    );
                }
                
                $preguntas_totales = array_merge($preguntas_totales, $preguntas);
            }
        }
        
        // Validar que tengamos exactamente 180 preguntas
        if (count($preguntas_totales) !== 180) {
            throw new Exception(
                "Error en distribución: se generaron " . count($preguntas_totales) . 
                " preguntas en lugar de 180"
            );
        }
        
        return $preguntas_totales;
    }
    
    /**
     * Inserta las preguntas en la tabla examen_preguntas
     */
    private function insertarPreguntasExamen($examen_id, $preguntas, $sesion) {
        $sql = "
            INSERT INTO examen_preguntas (examen_id, pregunta_id, sesion, orden)
            VALUES (?, ?, ?, ?)
        ";
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($preguntas as $orden => $pregunta_id) {
            $stmt->execute([$examen_id, $pregunta_id, $sesion, $orden + 1]);
        }
    }
    
    /**
     * Crea registros vacíos de respuestas
     */
    private function crearRespuestasVacias($examen_id, $preguntas) {
        $sql = "
            INSERT INTO respuestas_usuario (examen_id, pregunta_id)
            VALUES (?, ?)
        ";
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($preguntas as $pregunta_id) {
            $stmt->execute([$examen_id, $pregunta_id]);
        }
    }
    
    /**
     * Genera código único para el examen
     */
    private function generarCodigoUnico() {
        return 'SIM-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    }
    
    /**
     * Obtiene la distribución configurada
     */
    public function getDistribucion() {
        return $this->distribucion;
    }
}