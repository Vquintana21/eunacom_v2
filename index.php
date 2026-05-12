<?php
/**
 * ============================================
 * DASHBOARD PRINCIPAL - VERSIÓN MEJORADA
 * ============================================
 * Estadísticas combinadas: Sistema + Usuario
 */

require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/classes/Cache.php';

// Requiere autenticación
requireAuth();

// Obtener datos del usuario actual
$usuario = getCurrentUser();

// Obtener conexión a BD
$pdo = getDB();

// ==================================================
// ESTADÍSTICAS DEL SISTEMA (Generales)
// ==================================================
$stats_sistema = Cache::remember('stats_sistema', function() use ($pdo) {
    try {
        // Una sola consulta para todas las estadísticas
        $sql = "
            SELECT 
                (SELECT COUNT(*) FROM areas WHERE activo = 1) as total_areas,
                (SELECT COUNT(*) FROM temas WHERE activo = 1) as total_temas,
                (SELECT COUNT(*) FROM documentos_estudio WHERE activo = 1) as total_documentos,
                (SELECT COUNT(*) FROM preguntas WHERE activa = 1) as total_preguntas
        ";
        $stmt = $pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("[Dashboard] Error al obtener estadísticas del sistema: " . $e->getMessage());
        return array(
            'total_areas' => 0,
            'total_temas' => 0,
            'total_documentos' => 0,
            'total_preguntas' => 0
        );
    }
}, 300); // 5 minutos de caché

$total_areas = $stats_sistema['total_areas'];
$total_temas = $stats_sistema['total_temas'];
$total_documentos = $stats_sistema['total_documentos'];
$total_preguntas = $stats_sistema['total_preguntas'];

// ==================================================
// ESTADÍSTICAS DEL USUARIO (Personalizadas)
// ==================================================
// ==================================================
// ESTADÍSTICAS DEL USUARIO (Optimizadas)
// ==================================================
try {
    // Consulta única para estadísticas de simulacros y respuestas
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT e.id) as total_simulacros,
            SUM(CASE WHEN e.estado = 'finalizado' THEN 1 ELSE 0 END) as simulacros_completados,
            MAX(e.puntaje_porcentaje) as mejor_puntaje,
            COUNT(ru.id) as total_respondidas,
            SUM(CASE WHEN ru.es_correcta = 1 THEN 1 ELSE 0 END) as total_correctas,
            SUM(CASE WHEN ru.alternativa_seleccionada IS NULL THEN 1 ELSE 0 END) as total_omitidas
        FROM examenes e
        LEFT JOIN respuestas_usuario ru ON ru.examen_id = e.id
        WHERE e.usuario_id = ?
    ");
    $stmt->execute(array($usuario['id']));
    $stats_combinadas = $stmt->fetch();
    
    // Estadísticas de progreso por área (separada porque puede no existir)
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(total_preguntas_respondidas), 0) as preguntas_respondidas_areas,
            COALESCE(SUM(preguntas_correctas), 0) as correctas_areas,
            COALESCE(SUM(temas_completados), 0) as temas_completados,
            COALESCE(SUM(tiempo_total_estudio_min), 0) as tiempo_total_min
        FROM progreso_estudiante
        WHERE usuario_id = ?
    ");
    $stmt->execute(array($usuario['id']));
    $stats_progreso = $stmt->fetch();
    
    // Últimos simulacros (limitado a 10 para mejor rendimiento)
    $stmt = $pdo->prepare("
        SELECT 
            codigo_examen,
            estado,
            fecha_inicio,
            puntaje_porcentaje,
            respuestas_correctas,
            total_preguntas
        FROM examenes
        WHERE usuario_id = ?
        ORDER BY fecha_inicio DESC
        LIMIT 10
    ");
    $stmt->execute(array($usuario['id']));
    $ultimos_simulacros = $stmt->fetchAll();
    
    // Mapear resultados
    $stats_simulacros = array(
        'total_simulacros' => $stats_combinadas['total_simulacros'],
        'simulacros_completados' => $stats_combinadas['simulacros_completados'],
        'mejor_puntaje' => $stats_combinadas['mejor_puntaje']
    );
    
    $stats_respuestas = array(
        'total_respondidas' => $stats_combinadas['total_respondidas'],
        'total_correctas' => $stats_combinadas['total_correctas'],
        'total_omitidas' => $stats_combinadas['total_omitidas']
    );
    
} catch (PDOException $e) {
    error_log("[Dashboard] Error al obtener estadísticas del usuario: " . $e->getMessage());
    $stats_simulacros = array('total_simulacros' => 0, 'simulacros_completados' => 0, 'mejor_puntaje' => 0);
    $stats_respuestas = array('total_respondidas' => 0, 'total_correctas' => 0, 'total_omitidas' => 0);
    $stats_progreso = array('preguntas_respondidas_areas' => 0, 'correctas_areas' => 0, 'temas_completados' => 0, 'tiempo_total_min' => 0);
    $ultimos_simulacros = array();
}

// ==================================================
// CALCULAR MÉTRICAS FINALES
// ==================================================

// Para las tarjetas principales, priorizamos datos de SIMULACROS
$preguntas_respondidas_display = ($stats_respuestas['total_respondidas'] !== false && $stats_respuestas['total_respondidas'] > 0) 
    ? $stats_respuestas['total_respondidas'] 
    : 0;

$preguntas_correctas_display = ($stats_respuestas['total_correctas'] !== false && $stats_respuestas['total_correctas'] > 0) 
    ? $stats_respuestas['total_correctas'] 
    : 0;

// Calcular porcentaje de aciertos
$porcentaje_aciertos = 0;
if ($preguntas_respondidas_display > 0) {
    $porcentaje_aciertos = round(($preguntas_correctas_display / $preguntas_respondidas_display) * 100, 1);
}

// Simulacros completados
$simulacros_completados = ($stats_simulacros['simulacros_completados'] !== false) 
    ? $stats_simulacros['simulacros_completados'] 
    : 0;

// Mejor puntaje
$mejor_puntaje = ($stats_simulacros['mejor_puntaje'] !== false && $stats_simulacros['mejor_puntaje'] !== null) 
    ? round($stats_simulacros['mejor_puntaje'], 1) 
    : 0;

// Formatear tiempo total de estudio
$tiempo_estudio_horas = 0;
$tiempo_estudio_min = 0;
if ($stats_progreso['tiempo_total_min'] !== false && $stats_progreso['tiempo_total_min'] > 0) {
    $tiempo_estudio_horas = floor($stats_progreso['tiempo_total_min'] / 60);
    $tiempo_estudio_min = $stats_progreso['tiempo_total_min'] % 60;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?= buildUrl('css/style.css') ?>">
</head>
<body class="page-index">

    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
			<img src="<?= buildUrl('img/logo.png') ?>" alt="Logo <?= SITE_NAME ?>" class="logo-image">
			<div class="header-text">
				<h1>🏥 <?php echo SITE_NAME; ?></h1>
				<p>Sistema de Preparación EUNACOM</p>
			</div>
		</div>
            <div class="header-right">
                <div class="user-info">
                    <div class="user-name"><?php echo e($usuario['nombre']); ?></div>
                    <div class="user-email"><?php echo e($usuario['email']); ?></div>
                </div>
                <a href="<?php echo buildUrl('perfil.php'); ?>" class="btn-perfil">👤 Mi Perfil</a>
                <a href="<?php echo buildUrl('logout.php'); ?>" class="btn-logout">Cerrar Sesión</a>
            </div>
        </div>
        
        <!-- Welcome -->
        <div class="welcome">
            <h2>¡Bienvenido de vuelta, <?php echo e(explode(' ', $usuario['nombre'])[0]); ?>!</h2>
            <p>Estás a un paso más cerca de aprobar tu examen EUNACOM</p>
        </div>
		
		<!-- Modules -->
        <h2 class="modules-title">📚 Módulos de Estudio</h2>
        
        <div class="modules-grid">
            <!-- Módulo 1: Materiales -->
            <a href="<?php echo buildUrl('materiales.php'); ?>" class="module-card">
                <div class="module-header">
                    <div class="module-icon">📖</div>
                    <div class="module-title">Materiales de Estudio</div>
                </div>
                <div class="module-body">
                    <div class="module-description">
                        Accede a PDFs y documentos organizados por área, especialidad y tema. Material completo para tu preparación.
                    </div>
                    <div class="module-btn">Explorar Materiales →</div>
                </div>
            </a>
            
            <!-- Módulo 2: Entrenamiento -->
            <a href="<?php echo buildUrl('entrenamiento.php'); ?>" class="module-card">
                <div class="module-header">
                    <div class="module-icon">💪</div>
                    <div class="module-title">Entrenamiento por Temas</div>
                </div>
                <div class="module-body">
                    <div class="module-description">
                        Practica con preguntas tipo test organizadas por tema. Revisa tus respuestas y aprende de tus errores.
                    </div>
                    <div class="module-btn">Comenzar Entrenamiento →</div>
                </div>
            </a>
            
            <!-- Módulo 3: Simulacro -->
            <a href="<?php echo buildUrl('simulacro_inicio.php'); ?>" class="module-card">
                <div class="module-header">
                    <div class="module-icon">🎯</div>
                    <div class="module-title">Simulacro EUNACOM</div>
                </div>
                <div class="module-body">
                    <div class="module-description">
                        Realiza una simulación completa del examen oficial con 180 preguntas en 2 sesiones de 90 minutos.
                    </div>
                    <div class="module-btn">Iniciar Simulacro →</div>
                </div>
            </a>
        </div>
        
        <!-- Estadísticas del Sistema -->
        <div class="stats-section">
            <h3 class="stats-title">📊 Contenido Disponible en la Plataforma</h3>
            <div class="stats-grid">
                <div class="stat-card sistema">
                    <div class="stat-icon">📚</div>
                    <div class="stat-value"><?php echo $total_areas; ?></div>
                    <div class="stat-label">Áreas Médicas</div>
                </div>
                
                <div class="stat-card sistema">
                    <div class="stat-icon">📝</div>
                    <div class="stat-value"><?php echo number_format($total_temas); ?></div>
                    <div class="stat-label">Temas Disponibles</div>
                </div>
                
                <div class="stat-card sistema">
                    <div class="stat-icon">📄</div>
                    <div class="stat-value"><?php echo number_format($total_documentos); ?></div>
                    <div class="stat-label">Documentos de Estudio</div>
                </div>
                
                <div class="stat-card sistema">
                    <div class="stat-icon">❓</div>
                    <div class="stat-value"><?php echo number_format($total_preguntas); ?></div>
                    <div class="stat-label">Preguntas en el Banco</div>
                </div>
            </div>
        </div>
        
        <!-- Estadísticas del Usuario -->
        <div class="stats-section">
            <h3 class="stats-title">🎯 Tu Progreso Personal</h3>
            <div class="stats-grid">
                <div class="stat-card usuario">
                    <div class="stat-icon">💪</div>
                    <div class="stat-value"><?php echo number_format($preguntas_respondidas_display); ?></div>
                    <div class="stat-label">Preguntas Respondidas</div>
                    <div class="stat-sublabel">En simulacros</div>
                </div>
                
                <div class="stat-card usuario">
                    <div class="stat-icon">✅</div>
                    <div class="stat-value"><?php echo number_format($preguntas_correctas_display); ?></div>
                    <div class="stat-label">Respuestas Correctas</div>
                    <div class="stat-sublabel">¡Sigue así!</div>
                </div>
                
                <div class="stat-card usuario">
                    <div class="stat-icon">📊</div>
                    <div class="stat-value"><?php echo $porcentaje_aciertos; ?>%</div>
                    <div class="stat-label">Porcentaje de Aciertos</div>
                    <div class="stat-sublabel">
                        <?php 
                        if ($porcentaje_aciertos >= 70) {
                            echo '¡Excelente! 🎉';
                        } elseif ($porcentaje_aciertos >= 50) {
                            echo 'Buen progreso 👍';
                        } elseif ($porcentaje_aciertos > 0) {
                            echo 'Sigue practicando 💪';
                        } else {
                            echo 'Comienza tu práctica';
                        }
                        ?>
                    </div>
                </div>
                
                <div class="stat-card usuario">
                    <div class="stat-icon">🎯</div>
                    <div class="stat-value"><?php echo $simulacros_completados; ?></div>
                    <div class="stat-label">Simulacros Completados</div>
                    <div class="stat-sublabel">
                        <?php if ($mejor_puntaje > 0): ?>
                            Mejor: <?php echo $mejor_puntaje; ?>%
                        <?php else: ?>
                            Realiza tu primer simulacro
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        
        
        <!-- Recent Activity -->
        <?php if (count($ultimos_simulacros) > 0): ?>
        <div class="recent-activity">
            <h3>📈 Últimos Simulacros</h3>
            <ul class="activity-list">
                <?php foreach ($ultimos_simulacros as $simulacro): ?>
                <li class="activity-item">
                    <div class="activity-title" style="display: flex; justify-content: space-between; align-items: center;">
					<div>
						Simulacro #<?php echo e($simulacro['codigo_examen']); ?>
						<?php if ($simulacro['estado'] === 'finalizado'): ?>
							- <?php echo number_format($simulacro['puntaje_porcentaje'], 1); ?>%
						<?php else: ?>
							- <?php echo ($simulacro['estado'] === 'en_curso') ? 'En Curso' : 'Cancelado'; ?>
						<?php endif; ?>
					</div>

					<?php if ($simulacro['estado'] === 'finalizado'): ?>
						 <a href="simulacro_resultados.php?examen=<?php echo $simulacro['codigo_examen']; ?>"
           class="btn-primary-custom">
           <i class="fas fa-eye"></i> Revisar
        </a>
					<?php endif; ?>
				</div>

                    <div class="activity-meta">
                        <?php echo date('d/m/Y H:i', strtotime($simulacro['fecha_inicio'])); ?>
                        <?php if ($simulacro['estado'] === 'finalizado'): ?>
                            - <?php echo $simulacro['respuestas_correctas']; ?>/<?php echo $simulacro['total_preguntas']; ?> correctas
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php else: ?>
        <div class="recent-activity">
            <div class="empty-state">
                <div class="empty-state-icon">📝</div>
                <p>Aún no has realizado ningún simulacro</p>
                <p style="font-size: 0.9rem; margin-top: 10px;">¡Comienza tu preparación ahora!</p>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Footer Info -->
        <?php if ($tiempo_estudio_horas > 0 || $tiempo_estudio_min > 0): ?>
        <div style="background: white; border-radius: 15px; padding: 20px; text-align: center; margin-top: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <p style="color: #7f8c8d;">
                ⏱️ Tiempo total de estudio: 
                <strong style="color: #2c3e50;">
                    <?php 
                    if ($tiempo_estudio_horas > 0) {
                        echo $tiempo_estudio_horas . 'h ';
                    }
                    echo $tiempo_estudio_min . 'min';
                    ?>
                </strong>
            </p>
        </div>
        <?php endif; ?>
        
    </div>
</body>
</html>
