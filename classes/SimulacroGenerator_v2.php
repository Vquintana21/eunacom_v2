<?php
/**
 * Generador de Simulacros EUNACOM v2.0
 *
 * Mejoras implementadas:
 * - Eliminación de ORDER BY RAND() (selección eficiente en PHP)
 * - Batch INSERTs para mejor rendimiento
 * - Precarga de especialidades en una sola query
 * - Exclusión de preguntas ya respondidas por el usuario
 * - Configuración externalizable
 * - Mejor manejo de errores con excepciones tipadas
 * - Preparación de statements reutilizables
 *
 * @author EUNACOM Team
 * @version 2.0
 */

class SimulacroGeneratorException extends Exception {}
class InsuficientePreguntasException extends SimulacroGeneratorException {}
class EspecialidadNoEncontradaException extends SimulacroGeneratorException {}
class DistribucionInvalidaException extends SimulacroGeneratorException {}

class SimulacroGenerator_v2 {

    private PDO $pdo;
    private array $distribucion;
    private array $especialidadesCache = [];
    private int $totalPreguntasRequeridas = 180;
    private int $preguntasPorSesion = 90;

    // Ruta al archivo de configuración oficial
    private const CONFIG_PATH = __DIR__ . '/../config/distribucion_eunacom.json';

    /**
     * Constructor - Carga automáticamente la configuración desde JSON oficial
     *
     * @param PDO $pdo Conexión a base de datos
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->distribucion = $this->cargarConfiguracionOficial();
        $this->validarDistribucion();
    }

    /**
     * Carga la configuración oficial desde el archivo JSON
     */
    private function cargarConfiguracionOficial(): array {
        $rutaArchivo = self::CONFIG_PATH;

        if (!file_exists($rutaArchivo)) {
            throw new SimulacroGeneratorException(
                "Archivo de configuración oficial no encontrado: {$rutaArchivo}"
            );
        }

        $contenido = file_get_contents($rutaArchivo);
        $distribucion = json_decode($contenido, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new SimulacroGeneratorException(
                "Error parseando configuración JSON: " . json_last_error_msg()
            );
        }

        // Convertir claves string a int (JSON no soporta claves numéricas)
        return $this->normalizarClaves($distribucion);
    }

    /**
     * Normaliza las claves del array de string a int
     * (JSON convierte claves numéricas a strings)
     */
    private function normalizarClaves(array $distribucion): array {
        $normalizado = [];

        foreach ($distribucion as $areaId => $areaConfig) {
            $especialidadesNormalizadas = [];

            foreach ($areaConfig['especialidades'] as $codEsp => $cantidad) {
                $especialidadesNormalizadas[(int)$codEsp] = (int)$cantidad;
            }

            $normalizado[(int)$areaId] = [
                'nombre' => $areaConfig['nombre'],
                'total' => (int)$areaConfig['total'],
                'especialidades' => $especialidadesNormalizadas
            ];
        }

        return $normalizado;
    }

    /**
     * Valida que la distribución sume exactamente 180 preguntas
     */
    private function validarDistribucion(): void {
        $total = 0;

        foreach ($this->distribucion as $areaId => $areaConfig) {
            $sumaEspecialidades = array_sum($areaConfig['especialidades']);

            if ($sumaEspecialidades !== $areaConfig['total']) {
                throw new DistribucionInvalidaException(
                    "Área '{$areaConfig['nombre']}': suma de especialidades ({$sumaEspecialidades}) " .
                    "no coincide con total declarado ({$areaConfig['total']})"
                );
            }

            $total += $areaConfig['total'];
        }

        if ($total !== $this->totalPreguntasRequeridas) {
            throw new DistribucionInvalidaException(
                "La distribución suma {$total} preguntas, se requieren {$this->totalPreguntasRequeridas}"
            );
        }
    }

    /**
     * Precarga todas las especialidades en memoria (1 sola query)
     */
    private function precargarEspecialidades(): void {
        if (!empty($this->especialidadesCache)) {
            return; // Ya está cargado
        }

        $sql = "SELECT id, area_id, codigo_especialidad FROM especialidades";
        $stmt = $this->pdo->query($sql);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->especialidadesCache[$row['area_id']][$row['codigo_especialidad']] = (int)$row['id'];
        }
    }

    /**
     * Obtiene el ID de especialidad desde el caché
     */
    private function getEspecialidadId(int $areaId, int $codigoEsp): int {
        if (!isset($this->especialidadesCache[$areaId][$codigoEsp])) {
            throw new EspecialidadNoEncontradaException(
                "Especialidad no encontrada: area_id={$areaId}, codigo={$codigoEsp}"
            );
        }

        return $this->especialidadesCache[$areaId][$codigoEsp];
    }

    /**
     * Genera un nuevo simulacro completo
     *
     * @param int $usuarioId ID del usuario
     * @param bool $evitarRepetidas Si es true, intenta excluir preguntas ya respondidas
     * @return array Resultado con examen_id y codigo_examen
     */
    public function generarSimulacro(int $usuarioId, bool $evitarRepetidas = true): array {
        try {
            $this->pdo->beginTransaction();

            // Precargar datos necesarios
            $this->precargarEspecialidades();

            // 1. Crear registro de examen
            $codigoExamen = $this->generarCodigoUnico();
            $examenId = $this->crearExamen($usuarioId, $codigoExamen);

            // 2. Obtener IDs de preguntas ya respondidas (si aplica)
            $preguntasExcluidas = $evitarRepetidas
                ? $this->obtenerPreguntasRespondidas($usuarioId)
                : [];

            // 3. Seleccionar preguntas según distribución
            $resultadoSeleccion = $this->seleccionarPreguntas($preguntasExcluidas);
            $preguntasSeleccionadas = $resultadoSeleccion['preguntas'];

            // 4. Mezclar aleatoriamente
            shuffle($preguntasSeleccionadas);

            // 5. Dividir en sesiones
            $sesiones = array_chunk($preguntasSeleccionadas, $this->preguntasPorSesion);

            // 6. Insertar preguntas del examen (batch insert)
            $this->insertarPreguntasExamenBatch($examenId, $sesiones);

            // 7. Crear registros de respuestas vacías (batch insert)
            $this->crearRespuestasVaciasBatch($examenId, $preguntasSeleccionadas);

            $this->pdo->commit();

            return [
                'success' => true,
                'examen_id' => $examenId,
                'codigo_examen' => $codigoExamen,
                'total_preguntas' => count($preguntasSeleccionadas),
                'sesiones' => count($sesiones),
                'preguntas_excluidas' => count($preguntasExcluidas),
                'preguntas_nuevas' => $resultadoSeleccion['nuevas'],
                'preguntas_repetidas' => $resultadoSeleccion['repetidas'],
                'porcentaje_nuevas' => $resultadoSeleccion['porcentaje_nuevas']
            ];

        } catch (SimulacroGeneratorException $e) {
            $this->pdo->rollBack();
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => get_class($e)
            ];

        } catch (PDOException $e) {
            $this->pdo->rollBack();
            return [
                'success' => false,
                'error' => 'Error de base de datos: ' . $e->getMessage(),
                'error_type' => 'PDOException'
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    /**
     * Crea el registro del examen
     */
    private function crearExamen(int $usuarioId, string $codigoExamen): int {
        $sql = "INSERT INTO examenes (usuario_id, codigo_examen, estado, sesion_actual)
                VALUES (:usuario_id, :codigo, 'en_curso', 1)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':usuario_id' => $usuarioId,
            ':codigo' => $codigoExamen
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Obtiene IDs de preguntas ya respondidas por el usuario
     */
    private function obtenerPreguntasRespondidas(int $usuarioId): array {
        $sql = "
            SELECT DISTINCT ru.pregunta_id
            FROM respuestas_usuario ru
            INNER JOIN examenes e ON ru.examen_id = e.id
            WHERE e.usuario_id = :usuario_id
            AND ru.respuesta_seleccionada IS NOT NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':usuario_id' => $usuarioId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Selecciona preguntas según distribución oficial
     * Usa selección eficiente en PHP en lugar de ORDER BY RAND()
     *
     * Estrategia de selección:
     * 1. Primero intenta usar solo preguntas NO respondidas por el usuario
     * 2. Si no hay suficientes, completa con preguntas ya respondidas (priorizando las nuevas)
     * 3. Solo falla si no hay suficientes preguntas EN TOTAL en la BD
     *
     * @param array $excluidas IDs de preguntas ya respondidas por el usuario
     * @return array ['preguntas' => [...], 'nuevas' => int, 'repetidas' => int, 'porcentaje_nuevas' => float]
     */
    private function seleccionarPreguntas(array $excluidas = []): array {
        $preguntasTotales = [];
        $excluidasSet = array_flip($excluidas); // Para búsqueda O(1)

        $contadorNuevas = 0;
        $contadorRepetidas = 0;

        foreach ($this->distribucion as $areaId => $areaConfig) {
            foreach ($areaConfig['especialidades'] as $codigoEsp => $cantidad) {

                $especialidadId = $this->getEspecialidadId($areaId, $codigoEsp);

                // Obtener TODAS las preguntas de esta especialidad
                $idsTodasPreguntas = $this->obtenerIdsPreguntasEspecialidad($especialidadId, []);
                $totalEnBD = count($idsTodasPreguntas);

                // Validar que existan suficientes preguntas EN LA BD
                if ($totalEnBD < $cantidad) {
                    throw new InsuficientePreguntasException(
                        "Especialidad ID {$especialidadId} ({$areaConfig['nombre']}): " .
                        "se necesitan {$cantidad} preguntas, solo existen {$totalEnBD} en la base de datos. " .
                        "Debes agregar más preguntas a esta especialidad."
                    );
                }

                // Separar en nuevas (no respondidas) y ya respondidas
                $idsNuevas = [];
                $idsRespondidas = [];

                foreach ($idsTodasPreguntas as $id) {
                    if (!isset($excluidasSet[$id])) {
                        $idsNuevas[] = $id;
                    } else {
                        $idsRespondidas[] = $id;
                    }
                }

                $preguntasSeleccionadas = [];
                $nuevasDisponibles = count($idsNuevas);

                if ($nuevasDisponibles >= $cantidad) {
                    // Caso ideal: hay suficientes preguntas nuevas
                    $preguntasSeleccionadas = $this->seleccionarAleatorio($idsNuevas, $cantidad);
                    $contadorNuevas += $cantidad;

                } else {
                    // Caso mixto: usar todas las nuevas + completar con repetidas
                    $preguntasSeleccionadas = $idsNuevas; // Todas las nuevas disponibles
                    $contadorNuevas += $nuevasDisponibles;

                    $faltantes = $cantidad - $nuevasDisponibles;
                    $complemento = $this->seleccionarAleatorio($idsRespondidas, $faltantes);
                    $preguntasSeleccionadas = array_merge($preguntasSeleccionadas, $complemento);
                    $contadorRepetidas += $faltantes;
                }

                $preguntasTotales = array_merge($preguntasTotales, $preguntasSeleccionadas);
            }
        }

        // Validación final
        $totalSeleccionadas = count($preguntasTotales);
        if ($totalSeleccionadas !== $this->totalPreguntasRequeridas) {
            throw new DistribucionInvalidaException(
                "Error en selección: se generaron {$totalSeleccionadas} preguntas, " .
                "se requieren {$this->totalPreguntasRequeridas}"
            );
        }

        $porcentajeNuevas = $this->totalPreguntasRequeridas > 0
            ? round(($contadorNuevas / $this->totalPreguntasRequeridas) * 100, 1)
            : 0;

        return [
            'preguntas' => $preguntasTotales,
            'nuevas' => $contadorNuevas,
            'repetidas' => $contadorRepetidas,
            'porcentaje_nuevas' => $porcentajeNuevas
        ];
    }

    /**
     * Selecciona N elementos aleatorios de un array
     */
    private function seleccionarAleatorio(array $items, int $cantidad): array {
        if ($cantidad <= 0 || empty($items)) {
            return [];
        }

        if ($cantidad >= count($items)) {
            return $items; // Devolver todos si piden más de los disponibles
        }

        $indicesAleatorios = array_rand($items, $cantidad);

        // array_rand devuelve el índice directamente si cantidad es 1
        if (!is_array($indicesAleatorios)) {
            $indicesAleatorios = [$indicesAleatorios];
        }

        $seleccionados = [];
        foreach ($indicesAleatorios as $indice) {
            $seleccionados[] = $items[$indice];
        }

        return $seleccionados;
    }

    /**
     * Obtiene todos los IDs de preguntas de una especialidad
     * Query muy ligera (solo IDs)
     */
    private function obtenerIdsPreguntasEspecialidad(int $especialidadId, array $excluidasSet): array {
        $sql = "
            SELECT p.id
            FROM preguntas p
            INNER JOIN temas t ON p.tema_id = t.id
            WHERE t.especialidad_id = :especialidad_id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':especialidad_id' => $especialidadId]);

        $ids = [];
        while ($id = $stmt->fetchColumn()) {
            // Filtrar excluidas en PHP (más eficiente que subquery)
            if (!isset($excluidasSet[$id])) {
                $ids[] = (int)$id;
            }
        }

        return $ids;
    }

    /**
     * Inserta preguntas del examen usando batch INSERT
     */
    private function insertarPreguntasExamenBatch(int $examenId, array $sesiones): void {
        if (empty($sesiones)) {
            return;
        }

        $valores = [];
        $parametros = [];
        $contador = 0;

        foreach ($sesiones as $numSesion => $preguntas) {
            $sesion = $numSesion + 1;

            foreach ($preguntas as $orden => $preguntaId) {
                $valores[] = "(:examen_{$contador}, :pregunta_{$contador}, :sesion_{$contador}, :orden_{$contador})";
                $parametros[":examen_{$contador}"] = $examenId;
                $parametros[":pregunta_{$contador}"] = $preguntaId;
                $parametros[":sesion_{$contador}"] = $sesion;
                $parametros[":orden_{$contador}"] = $orden + 1;
                $contador++;
            }
        }

        $sql = "INSERT INTO examen_preguntas (examen_id, pregunta_id, sesion, orden) VALUES "
             . implode(', ', $valores);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parametros);
    }

    /**
     * Crea registros vacíos de respuestas usando batch INSERT
     */
    private function crearRespuestasVaciasBatch(int $examenId, array $preguntas): void {
        if (empty($preguntas)) {
            return;
        }

        // Dividir en chunks para evitar límites de MySQL (máx ~65535 placeholders)
        $chunks = array_chunk($preguntas, 5000);

        foreach ($chunks as $chunk) {
            $valores = [];
            $parametros = [':examen_id' => $examenId];

            foreach ($chunk as $i => $preguntaId) {
                $valores[] = "(:examen_id, :pregunta_{$i})";
                $parametros[":pregunta_{$i}"] = $preguntaId;
            }

            $sql = "INSERT INTO respuestas_usuario (examen_id, pregunta_id) VALUES "
                 . implode(', ', $valores);

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($parametros);
        }
    }

    /**
     * Genera código único para el examen
     */
    private function generarCodigoUnico(): string {
        return sprintf(
            'SIM-%s-%s',
            date('Ymd'),
            strtoupper(bin2hex(random_bytes(4)))
        );
    }

    /**
     * Obtiene estadísticas de preguntas disponibles por especialidad
     * Útil para diagnóstico y reportes
     */
    public function obtenerEstadisticasPreguntas(?int $usuarioId = null): array {
        $this->precargarEspecialidades();

        $preguntasRespondidas = $usuarioId
            ? array_flip($this->obtenerPreguntasRespondidas($usuarioId))
            : [];

        $estadisticas = [];

        foreach ($this->distribucion as $areaId => $areaConfig) {
            $estadisticas[$areaConfig['nombre']] = [
                'requeridas' => $areaConfig['total'],
                'especialidades' => []
            ];

            $totalDisponibles = 0;
            $totalSinResponder = 0;

            foreach ($areaConfig['especialidades'] as $codigoEsp => $cantidad) {
                $especialidadId = $this->getEspecialidadId($areaId, $codigoEsp);

                $idsTotal = $this->obtenerIdsPreguntasEspecialidad($especialidadId, []);
                $idsSinResponder = $this->obtenerIdsPreguntasEspecialidad($especialidadId, $preguntasRespondidas);

                $disponibles = count($idsTotal);
                $sinResponder = count($idsSinResponder);

                $estadisticas[$areaConfig['nombre']]['especialidades'][$codigoEsp] = [
                    'requeridas' => $cantidad,
                    'disponibles' => $disponibles,
                    'sin_responder' => $sinResponder,
                    'suficientes' => $disponibles >= $cantidad,
                    'deficit' => max(0, $cantidad - $disponibles)
                ];

                $totalDisponibles += $disponibles;
                $totalSinResponder += $sinResponder;
            }

            $estadisticas[$areaConfig['nombre']]['total_disponibles'] = $totalDisponibles;
            $estadisticas[$areaConfig['nombre']]['total_sin_responder'] = $totalSinResponder;
        }

        return $estadisticas;
    }

    /**
     * Verifica si es posible generar un simulacro completo
     */
    public function puedeGenerarSimulacro(?int $usuarioId = null): array {
        try {
            $this->precargarEspecialidades();
            $excluidas = $usuarioId ? $this->obtenerPreguntasRespondidas($usuarioId) : [];

            $problemas = [];

            foreach ($this->distribucion as $areaId => $areaConfig) {
                foreach ($areaConfig['especialidades'] as $codigoEsp => $cantidad) {
                    $especialidadId = $this->getEspecialidadId($areaId, $codigoEsp);
                    $disponibles = count($this->obtenerIdsPreguntasEspecialidad(
                        $especialidadId,
                        array_flip($excluidas)
                    ));

                    if ($disponibles < $cantidad) {
                        $problemas[] = [
                            'area' => $areaConfig['nombre'],
                            'especialidad_id' => $especialidadId,
                            'requeridas' => $cantidad,
                            'disponibles' => $disponibles,
                            'deficit' => $cantidad - $disponibles
                        ];
                    }
                }
            }

            return [
                'puede_generar' => empty($problemas),
                'problemas' => $problemas,
                'preguntas_ya_respondidas' => count($excluidas)
            ];

        } catch (Exception $e) {
            return [
                'puede_generar' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene la distribución configurada
     */
    public function getDistribucion(): array {
        return $this->distribucion;
    }

    /**
     * Limpia el caché de especialidades (útil para testing)
     */
    public function limpiarCache(): void {
        $this->especialidadesCache = [];
    }

    /**
     * Obtiene la ruta del archivo de configuración
     */
    public static function getRutaConfiguracion(): string {
        return self::CONFIG_PATH;
    }
}
