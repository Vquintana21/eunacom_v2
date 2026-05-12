<?php

require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';

requireAuth();
$usuario = getCurrentUser();
$usuario_id = $usuario['id'];

$pdo = getDB();

// Obtener código del examen
$codigo_examen = isset($_GET['examen']) ? $_GET['examen'] : null;

if (!$codigo_examen) {
    header("Location: simulacro_inicio.php");
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

if (!$examen || $examen['estado'] !== 'finalizado') {
    header("Location: simulacro_inicio.php");
    exit;
}

// Calcular duración total
$inicio = new DateTime($examen['fecha_inicio']);
$fin = new DateTime($examen['fecha_finalizacion']);
$duracion = $fin->diff($inicio);
$duracion_minutos = ($duracion->h * 60) + $duracion->i;

// Obtener resultados por área
$sql = "
    SELECT 
        a.nombre as area_nombre,
        COUNT(*) as total_preguntas,
        SUM(CASE WHEN ru.es_correcta = 1 THEN 1 ELSE 0 END) as correctas,
        SUM(CASE WHEN ru.alternativa_seleccionada IS NOT NULL AND ru.es_correcta = 0 THEN 1 ELSE 0 END) as incorrectas,
        SUM(CASE WHEN ru.alternativa_seleccionada IS NULL THEN 1 ELSE 0 END) as omitidas
    FROM respuestas_usuario ru
    INNER JOIN preguntas p ON ru.pregunta_id = p.id
    INNER JOIN temas t ON p.tema_id = t.id
    INNER JOIN especialidades e ON t.especialidad_id = e.id
    INNER JOIN areas a ON e.area_id = a.id
    WHERE ru.examen_id = ?
    GROUP BY a.id, a.nombre
    ORDER BY a.id
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$examen['id']]);
$resultados_areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener resultados por tipo de situación
$sql = "
    SELECT 
        ts.nombre as tipo_nombre,
        COUNT(*) as total_preguntas,
        SUM(CASE WHEN ru.es_correcta = 1 THEN 1 ELSE 0 END) as correctas,
        SUM(CASE WHEN ru.alternativa_seleccionada IS NOT NULL AND ru.es_correcta = 0 THEN 1 ELSE 0 END) as incorrectas
    FROM respuestas_usuario ru
    INNER JOIN preguntas p ON ru.pregunta_id = p.id
    INNER JOIN temas t ON p.tema_id = t.id
    INNER JOIN tipos_situacion ts ON t.tipo_situacion_id = ts.id
    WHERE ru.examen_id = ?
    GROUP BY ts.id, ts.nombre
    ORDER BY ts.id
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$examen['id']]);
$resultados_tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Determinar nivel de desempeño
$porcentaje = $examen['puntaje_porcentaje'];
if ($porcentaje >= 70) {
    $nivel = 'Excelente';
    $nivel_color = '#27ae60';
    $nivel_icon = '🏆';
    $mensaje = '¡Felicitaciones! Tu desempeño es sobresaliente.';
} elseif ($porcentaje >= 60) {
    $nivel = 'Muy Bueno';
    $nivel_color = '#3498db';
    $nivel_icon = '⭐';
    $mensaje = 'Buen trabajo. Estás muy cerca del nivel excelente.';
} elseif ($porcentaje >= 50) {
    $nivel = 'Bueno';
    $nivel_color = '#f39c12';
    $nivel_icon = '👍';
    $mensaje = 'Buen intento. Con más práctica mejorarás aún más.';
} else {
    $nivel = 'Necesita Mejorar';
    $nivel_color = '#e74c3c';
    $nivel_icon = '📚';
    $mensaje = 'Sigue practicando. Identifica tus áreas débiles y refuérzalas.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultados - Simulacro EUNACOM</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-simulacro-resultados">
    <div class="container">
        
        <!-- Header -->
        <div class="header">
            <h1><?= $nivel_icon ?> Simulacro Completado</h1>
            <div class="codigo">Código: <?= e($examen['codigo_examen']) ?></div>
        </div>
        
        <!-- Score Principal -->
        <div class="card">
            <div class="score-section">
                <div class="score-circle" style="border-color: <?= $nivel_color ?>; color: <?= $nivel_color ?>">
                    <div class="score-percentage"><?= round($porcentaje) ?>%</div>
                    <div class="score-label"><?= $nivel ?></div>
                </div>
                <div class="score-message"><?= $mensaje ?></div>
            </div>
            
            <!-- Estadísticas Generales -->
            <div class="stats-grid">
                <div class="stat-box correctas">
                    <div class="stat-number correctas"><?= $examen['respuestas_correctas'] ?></div>
                    <div class="stat-label">Correctas</div>
                </div>
                
                <div class="stat-box incorrectas">
                    <div class="stat-number incorrectas"><?= $examen['respuestas_incorrectas'] ?></div>
                    <div class="stat-label">Incorrectas</div>
                </div>
                
                <div class="stat-box omitidas">
                    <div class="stat-number omitidas"><?= $examen['preguntas_omitidas'] ?></div>
                    <div class="stat-label">Omitidas</div>
                </div>
                <!--
                <div class="stat-box">
                    <div class="stat-number" style="color: #2c3e50;"><? // =  $examen['total_preguntas'] ?></div>                    <div class="stat-label">Total</div>
                </div>-->
            </div> 
            
            <!-- Meta Información -->
            <div class="meta-info">
                <div class="meta-item">
                    <div class="meta-label">Fecha</div>
                    <div class="meta-value"><?= date('d/m/Y', strtotime($examen['fecha_inicio'])) ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Duración Total</div>
                    <div class="meta-value"><?= floor($duracion_minutos / 60) ?>h <?= $duracion_minutos % 60 ?>min</div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Respondidas</div>
                    <div class="meta-value"><?= $examen['preguntas_respondidas'] ?>/180</div>
                </div>
            </div>
        </div>
        
        <!-- Resultados por Área -->
        <div class="card">
            <h2>📊 Resultados por Área</h2>
            <table>
                <thead>
                    <tr>
                        <th>Área</th>
                        <th style="text-align: center;">Total</th>
                        <th style="text-align: center;">Correctas</th>
                        <th style="text-align: center;">Incorrectas</th>
                        <th style="text-align: center;">Omitidas</th>
                        <th>Rendimiento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultados_areas as $area): ?>
                        <?php 
                        $porcentaje_area = ($area['correctas'] / $area['total_preguntas']) * 100;
                        $clase_progreso = $porcentaje_area >= 70 ? 'alto' : ($porcentaje_area >= 50 ? 'medio' : 'bajo');
                        ?>
                        <tr>
                            <td><strong><?= e($area['area_nombre']) ?></strong></td>
                            <td style="text-align: center;"><?= $area['total_preguntas'] ?></td>
                            <td style="text-align: center; color: #28a745; font-weight: 600;"><?= $area['correctas'] ?></td>
                            <td style="text-align: center; color: var(--color-accent); font-weight: 600;"><?= $area['incorrectas'] ?></td>
                            <td style="text-align: center; color: #ffc107; font-weight: 600;"><?= $area['omitidas'] ?></td>
                            <td>
                                <div class="progress-bar-container">
                                    <div class="progress-bar <?= $clase_progreso ?>" style="width: <?= $porcentaje_area ?>%">
                                        <?= round($porcentaje_area) ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Resultados por Tipo de Situación -->
        <div class="card">
            <h2>📋 Resultados por Tipo de Situación</h2>
            <table>
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th style="text-align: center;">Total</th>
                        <th style="text-align: center;">Correctas</th>
                        <th style="text-align: center;">Incorrectas</th>
                        <th>Rendimiento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultados_tipos as $tipo): ?>
                        <?php 
                        $porcentaje_tipo = ($tipo['correctas'] / $tipo['total_preguntas']) * 100;
                        $clase_progreso = $porcentaje_tipo >= 70 ? 'alto' : ($porcentaje_tipo >= 50 ? 'medio' : 'bajo');
                        ?>
                        <tr>
                            <td><strong><?= e($tipo['tipo_nombre']) ?></strong></td>
                            <td style="text-align: center;"><?= $tipo['total_preguntas'] ?></td>
                            <td style="text-align: center; color: #28a745; font-weight: 600;"><?= $tipo['correctas'] ?></td>
                            <td style="text-align: center; color: var(--color-accent); font-weight: 600;"><?= $tipo['incorrectas'] ?></td>
                            <td>
                                <div class="progress-bar-container">
                                    <div class="progress-bar <?= $clase_progreso ?>" style="width: <?= $porcentaje_tipo ?>%">
                                        <?= round($porcentaje_tipo) ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Acciones -->
        <div class="card">
            <div class="actions">
                <a href="<?= buildUrl('simulacro_revision.php?examen=' . urlencode($codigo_examen)) ?>" class="btn btn-primary" style="background: #9b59b6;">
                    📝 Revisar Respuestas
                </a>
                <a href="<?= buildUrl('simulacro_inicio.php') ?>" class="btn btn-primary">
                    🔄 Nuevo Simulacro
                </a>
                <a href="<?= buildUrl('entrenamiento.php') ?>" class="btn btn-secondary">
                    📚 Ir a Entrenamiento
                </a>
				<a href="<?= buildUrl('index.php') ?>" class="btn btn-success">
                    🏠 Ir a Inicio
                </a>
            </div>
        </div>
        
    </div>
    
    <script>
        // Animar barras de progreso
        document.addEventListener('DOMContentLoaded', () => {
            const progressBars = document.querySelectorAll('.progress-bar');
            
            progressBars.forEach(bar => {
                const targetWidth = bar.style.width;
                bar.style.width = '0%';
                
                setTimeout(() => {
                    bar.style.width = targetWidth;
                }, 100);
            });
        });
    </script>
</body>
</html>