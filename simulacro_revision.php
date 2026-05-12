<?php
/**
 * ============================================
 * REVISIÓN DE SIMULACRO - Ver respuestas y justificaciones
 * ============================================
 */

require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';

// Requiere autenticación
requireAuth();

// Obtener usuario actual
$usuario = getCurrentUser();
$usuario_id = $usuario['id'];

// Obtener conexión a BD
$pdo = getDB();

// Obtener código del examen
$codigo_examen = isset($_GET['examen']) ? $_GET['examen'] : null;

if (!$codigo_examen) {
    header("Location: " . buildUrl('simulacro_inicio.php'));
    exit;
}

// Obtener datos del examen
$sql = "
    SELECT * FROM examenes 
    WHERE codigo_examen = ? AND usuario_id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$codigo_examen, $usuario_id]);
$examen = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$examen) {
    die("Examen no encontrado");
}

// Solo permitir revisión si el examen está finalizado
if ($examen['estado'] !== 'finalizado') {
    header("Location: " . buildUrl('simulacro_inicio.php'));
    exit;
}

// Obtener filtro de sesión (default: todas)
$filtro_sesion = isset($_GET['sesion']) ? (int)$_GET['sesion'] : 0;

// Obtener filtro de estado (default: todas)
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : 'todas';

// Construir query base
$sql_where = "WHERE ru.examen_id = ?";
$params = [$examen['id']];

// Filtro por sesión
if ($filtro_sesion == 1 || $filtro_sesion == 2) {
    $sql_where .= " AND ep.sesion = ?";
    $params[] = $filtro_sesion;
}

// Obtener TODAS las preguntas con sus respuestas
$sql = "
    SELECT 
        p.id as pregunta_id,
        p.numero_pregunta,
        p.texto_pregunta,
        ru.alternativa_seleccionada,
        ru.es_correcta,
        ru.marcada_revision,
        e.respuesta_correcta,
        e.explicacion_completa,
        ep.sesion,
        ep.orden,
        t.nombre as tema_nombre,
        esp.nombre as especialidad_nombre,
        a.nombre as area_nombre
    FROM respuestas_usuario ru
    INNER JOIN preguntas p ON ru.pregunta_id = p.id
    INNER JOIN examen_preguntas ep ON ep.examen_id = ru.examen_id 
        AND ep.pregunta_id = p.id
    LEFT JOIN explicaciones e ON p.id = e.pregunta_id
    INNER JOIN temas t ON p.tema_id = t.id
    INNER JOIN especialidades esp ON t.especialidad_id = esp.id
    INNER JOIN areas a ON esp.area_id = a.id
    $sql_where
    ORDER BY ep.sesion, ep.orden
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$todas_preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener alternativas para cada pregunta
foreach ($todas_preguntas as &$pregunta) {
    $sql_alt = "
        SELECT opcion, texto_alternativa, es_correcta
        FROM alternativas
        WHERE pregunta_id = ?
        ORDER BY orden
    ";
    $stmt = $pdo->prepare($sql_alt);
    $stmt->execute([$pregunta['pregunta_id']]);
    $pregunta['alternativas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

unset($pregunta);

// Aplicar filtro de estado (en PHP después de cargar todo)
if ($filtro_estado !== 'todas') {
    $todas_preguntas = array_filter($todas_preguntas, function($p) use ($filtro_estado) {
        switch ($filtro_estado) {
            case 'correctas':
                return $p['es_correcta'] == 1;
            case 'incorrectas':
                return $p['alternativa_seleccionada'] !== null && $p['es_correcta'] == 0;
            case 'omitidas':
                return $p['alternativa_seleccionada'] === null;
            case 'marcadas':
                return $p['marcada_revision'] == 1;
            default:
                return true;
        }
    });
}

// Calcular estadísticas
$stats_sesion1 = [
    'total' => 0,
    'correctas' => 0,
    'incorrectas' => 0,
    'omitidas' => 0
];
$stats_sesion2 = [
    'total' => 0,
    'correctas' => 0,
    'incorrectas' => 0,
    'omitidas' => 0
];

$sql = "
    SELECT 
        ep.sesion,
        COUNT(*) as total,
        SUM(CASE WHEN ru.es_correcta = 1 THEN 1 ELSE 0 END) as correctas,
        SUM(CASE WHEN ru.alternativa_seleccionada IS NOT NULL AND ru.es_correcta = 0 THEN 1 ELSE 0 END) as incorrectas,
        SUM(CASE WHEN ru.alternativa_seleccionada IS NULL THEN 1 ELSE 0 END) as omitidas
    FROM respuestas_usuario ru
    INNER JOIN examen_preguntas ep ON ru.examen_id = ep.examen_id AND ru.pregunta_id = ep.pregunta_id
    WHERE ru.examen_id = ?
    GROUP BY ep.sesion
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$examen['id']]);
$stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($stats as $s) {
    if ($s['sesion'] == 1) {
        $stats_sesion1 = $s;
    } else {
        $stats_sesion2 = $s;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revisión - Simulacro EUNACOM</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-simulacro-revision">
    <div class="container">
        
        <!-- Header -->
        <div class="header">
            <h1>📝 Revisión de Simulacro</h1>
            <p class="subtitle">Código: <?= e($examen['codigo_examen']) ?></p>
            
            <div class="acciones">
                <a href="<?= buildUrl('simulacro_resultados.php?examen=' . urlencode($codigo_examen)) ?>" class="btn btn-secondary">
                    ← Volver a Resultados
                </a>
                <a href="<?= buildUrl('simulacro_inicio.php') ?>" class="btn btn-primary">
                    🔄 Nuevo Simulacro
                </a>
				<a href="<?= buildUrl('index.php') ?>" class="btn btn-success">
                    🏠 Ir a Inicio
                </a>
            </div>
        </div>
        
        <!-- Estadísticas por Sesión -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-number"><?= $stats_sesion1['total'] ?></div>
                <div class="stat-label">Sesión 1 - Total</div>
            </div>
            <div class="stat-card correctas">
                <div class="stat-number"><?= $stats_sesion1['correctas'] ?></div>
                <div class="stat-label">Sesión 1 - Correctas</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats_sesion2['total'] ?></div>
                <div class="stat-label">Sesión 2 - Total</div>
            </div>
            <div class="stat-card correctas">
                <div class="stat-number"><?= $stats_sesion2['correctas'] ?></div>
                <div class="stat-label">Sesión 2 - Correctas</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filtros">
            <div class="filtro-group">
                <label>Sesión:</label>
                <a href="?examen=<?= urlencode($codigo_examen) ?>&sesion=0&estado=<?= $filtro_estado ?>" 
                   class="btn-filtro <?= $filtro_sesion == 0 ? 'active' : '' ?>">
                    Todas
                </a>
                <a href="?examen=<?= urlencode($codigo_examen) ?>&sesion=1&estado=<?= $filtro_estado ?>" 
                   class="btn-filtro <?= $filtro_sesion == 1 ? 'active' : '' ?>">
                    Sesión 1
                </a>
                <a href="?examen=<?= urlencode($codigo_examen) ?>&sesion=2&estado=<?= $filtro_estado ?>" 
                   class="btn-filtro <?= $filtro_sesion == 2 ? 'active' : '' ?>">
                    Sesión 2
                </a>
            </div>
            
            <div class="filtro-group">
                <label>Estado:</label>
                <a href="?examen=<?= urlencode($codigo_examen) ?>&sesion=<?= $filtro_sesion ?>&estado=todas" 
                   class="btn-filtro <?= $filtro_estado == 'todas' ? 'active' : '' ?>">
                    Todas
                </a>
                <a href="?examen=<?= urlencode($codigo_examen) ?>&sesion=<?= $filtro_sesion ?>&estado=correctas" 
                   class="btn-filtro <?= $filtro_estado == 'correctas' ? 'active' : '' ?>">
                    Correctas
                </a>
                <a href="?examen=<?= urlencode($codigo_examen) ?>&sesion=<?= $filtro_sesion ?>&estado=incorrectas" 
                   class="btn-filtro <?= $filtro_estado == 'incorrectas' ? 'active' : '' ?>">
                    Incorrectas
                </a>
                <a href="?examen=<?= urlencode($codigo_examen) ?>&sesion=<?= $filtro_sesion ?>&estado=omitidas" 
                   class="btn-filtro <?= $filtro_estado == 'omitidas' ? 'active' : '' ?>">
                    Omitidas
                </a>
                <a href="?examen=<?= urlencode($codigo_examen) ?>&sesion=<?= $filtro_sesion ?>&estado=marcadas" 
                   class="btn-filtro <?= $filtro_estado == 'marcadas' ? 'active' : '' ?>">
                    Marcadas
                </a>
            </div>
        </div>
        
        <!-- Preguntas -->
        <?php if (empty($todas_preguntas)): ?>
            <div class="no-resultados">
                <div class="no-resultados-icon">🔍</div>
                <h3>No hay preguntas con estos filtros</h3>
                <p>Intenta cambiar los filtros de búsqueda</p>
            </div>
        <?php else: ?>
            <?php foreach ($todas_preguntas as $pregunta): ?>
                <div class="pregunta-card">
                    <div class="pregunta-header">
                        <div>
                            <span class="pregunta-numero"><?= $pregunta['orden'] ?></span>
                        </div>
                        <div class="pregunta-meta">
                            <strong>Sesión <?= $pregunta['sesion'] ?></strong><br>
                            <?= e($pregunta['area_nombre']) ?> › 
                            <?= e($pregunta['especialidad_nombre']) ?><br>
                            <small><?= e($pregunta['tema_nombre']) ?></small>
                        </div>
                    </div>
                    
                    <div class="pregunta-texto">
                        <?= nl2br(e($pregunta['texto_pregunta'])) ?>
                    </div>
                    
                    <div class="alternativas">
                        <?php foreach ($pregunta['alternativas'] as $alt): ?>
                            <?php
                            $es_correcta = $alt['es_correcta'] == 1;
                            $es_seleccionada = $alt['opcion'] === $pregunta['alternativa_seleccionada'];
                            
                            $clase_alternativa = '';
                            $clase_letra = '';
                            $indicador = '';
                            
                            if ($es_correcta) {
                                $clase_alternativa = 'correcta';
                                $clase_letra = 'correcta';
                                
                                if ($es_seleccionada) {
                                    $indicador = '<span class="indicador-respuesta tu-respuesta-correcta">✓ Tu respuesta (CORRECTA)</span>';
                                } else {
                                    $indicador = '<span class="indicador-respuesta correcta">✓ Respuesta correcta</span>';
                                }
                            } elseif ($es_seleccionada) {
                                $clase_alternativa = 'seleccionada-incorrecta';
                                $clase_letra = 'incorrecta';
                                $indicador = '<span class="indicador-respuesta tu-respuesta-incorrecta">✗ Tu respuesta (INCORRECTA)</span>';
                            }
                            ?>
                            
                            <div class="alternativa <?= $clase_alternativa ?>">
                                <span class="opcion-letra <?= $clase_letra ?>"><?= $alt['opcion'] ?></span>
                                <span class="alternativa-texto"><?= e($alt['texto_alternativa']) ?></span>
                                <?= $indicador ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Explicación -->
                    <?php if (!empty($pregunta['explicacion_completa'])): ?>
                        <div class="explicacion">
                            <h4>💡 Justificación</h4>
                            <div class="explicacion-texto">
                                <?= nl2br(e($pregunta['explicacion_completa'])) ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="sin-explicacion">
                            ℹ️ No hay explicación disponible para esta pregunta
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
    </div>
</body>
</html>