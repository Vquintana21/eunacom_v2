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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1b7db8 0%, #3ab4f2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .header {
            text-align: center;
            padding: 40px 20px;
            background: linear-gradient(135deg, #1EA4ED 0%, #3ab4f2 100%);
            color: white;
            border-radius: 15px;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .header .codigo {
            font-size: 1.2rem;
            opacity: 0.9;
        }
        
        /* Score Circle */
        .score-section {
            text-align: center;
            padding: 40px;
        }
        
        .score-circle {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            border: 10px solid;
            position: relative;
        }
        
        .score-percentage {
            font-size: 4rem;
            font-weight: bold;
        }
        
        .score-label {
            font-size: 1.2rem;
            font-weight: 600;
            margin-top: 10px;
        }
        
        .score-message {
            font-size: 1.1rem;
            color: #7f8c8d;
            margin-top: 20px;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .stat-box {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            border: 3px solid #e9ecef;
        }
        
        .stat-box.correctas {
            background: #d4edda;
            border-color: #28a745;
        }
        
        .stat-box.incorrectas {
            background: #f8d7da;
            border-color: #dc3545;
        }
        
        .stat-box.omitidas {
            background: #fff3cd;
            border-color: #ffc107;
        }
        
        .stat-number {
            font-size: 3rem;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .stat-number.correctas { color: #155724; }
        .stat-number.incorrectas { color: #721c24; }
        .stat-number.omitidas { color: #856404; }
        
        .stat-label {
            color: #6c757d;
            font-weight: 600;
        }
        
        /* Tablas de Resultados */
        h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 1.8rem;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #3498db;
            color: white;
        }
        
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        tbody tr:hover {
            background: #f8f9fa;
        }
        
        .progress-bar-container {
            width: 100%;
            height: 25px;
            background: #e9ecef;
            border-radius: 15px;
            overflow: hidden;
            position: relative;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #27ae60, #2ecc71);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
            transition: width 1s ease;
        }
        
        .progress-bar.bajo {
            background: linear-gradient(90deg, #e74c3c, #c0392b);
        }
        
        .progress-bar.medio {
            background: linear-gradient(90deg, #f39c12, #e67e22);
        }
        
        .progress-bar.alto {
            background: linear-gradient(90deg, #27ae60, #2ecc71);
        }
        
        /* Botones */
        .actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            justify-content: center;
        }
        
        .btn {
            padding: 15px 40px;
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #7f8c8d;
        }
		
		.btn-success {
            background: #2A7B9B;
            color: white;
        }
        
        .btn-success:hover {
            background: #57C785;
        }
        
        .meta-info {
            display: flex;
            justify-content: space-around;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .meta-item {
            text-align: center;
        }
        
        .meta-label {
            color: #7f8c8d;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        
        .meta-value {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
        }
    </style>
</head>
<body>
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
                            <td style="text-align: center; color: #dc3545; font-weight: 600;"><?= $area['incorrectas'] ?></td>
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
                            <td style="text-align: center; color: #dc3545; font-weight: 600;"><?= $tipo['incorrectas'] ?></td>
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