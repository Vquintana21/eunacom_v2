<?php

require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';

// Requiere autenticación
requireAuth();

// Obtener usuario actual
$usuario = getCurrentUser();
$usuario_id = $usuario['id'];

$pdo = getDB();


// ============================================
// DETERMINAR NIVEL DE NAVEGACIÓN
// ============================================
$nivel = 'areas'; // Por defecto: mostrar áreas
$area_id = isset($_GET['area']) ? (int)$_GET['area'] : null;
$especialidad_id = isset($_GET['especialidad']) ? (int)$_GET['especialidad'] : null;
$tipo_id = isset($_GET['tipo']) ? (int)$_GET['tipo'] : null;
$tema_id = isset($_GET['tema']) ? (int)$_GET['tema'] : null;

if ($tema_id) {
    $nivel = 'preguntas';
} elseif ($tipo_id) {
    $nivel = 'temas';
} elseif ($especialidad_id) {
    $nivel = 'tipos';
} elseif ($area_id) {
    $nivel = 'especialidades';
}

// ============================================
// OBTENER DATOS SEGÚN NIVEL
// ============================================
$datos = [];
$breadcrumb = [];

switch ($nivel) {
    case 'areas':
        // Nivel 1: Listar ÁREAS
        $sql = "
            SELECT 
                a.id,
                a.nombre,
                COUNT(DISTINCT e.id) as total_especialidades,
                COUNT(DISTINCT t.id) as total_temas
            FROM areas a
            LEFT JOIN especialidades e ON a.id = e.area_id
            LEFT JOIN temas t ON e.id = t.especialidad_id
            GROUP BY a.id, a.nombre
            ORDER BY a.id
        ";
        $datos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $breadcrumb = [['nombre' => 'Áreas', 'url' => null]];
        break;
        
    case 'especialidades':
        // Nivel 2: Listar ESPECIALIDADES de un área
        $sql_area = "SELECT nombre FROM areas WHERE id = ?";
        $stmt = $pdo->prepare($sql_area);
        $stmt->execute([$area_id]);
        $area = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $sql = "
            SELECT 
                e.id,
                e.nombre,
                e.codigo_especialidad,
                COUNT(DISTINCT t.id) as total_temas
            FROM especialidades e
            LEFT JOIN temas t ON e.id = t.especialidad_id
            WHERE e.area_id = ?
            GROUP BY e.id, e.nombre, e.codigo_especialidad
			HAVING total_temas > 0 
            ORDER BY e.codigo_especialidad
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$area_id]);
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $breadcrumb = [
            ['nombre' => 'Áreas', 'url' => 'entrenamiento.php'],
            ['nombre' => $area['nombre'], 'url' => null]
        ];
        break;
        
    case 'tipos':
        // Nivel 3: Listar TIPOS DE SITUACIÓN
        $sql_area = "SELECT nombre FROM areas WHERE id = ?";
        $stmt = $pdo->prepare($sql_area);
        $stmt->execute([$area_id]);
        $area = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $sql_esp = "SELECT nombre FROM especialidades WHERE id = ?";
        $stmt = $pdo->prepare($sql_esp);
        $stmt->execute([$especialidad_id]);
        $especialidad = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $sql = "
            SELECT 
                ts.id,
                ts.nombre,
                COUNT(t.id) as total_temas
            FROM tipos_situacion ts
            LEFT JOIN temas t ON ts.id = t.tipo_situacion_id 
                AND t.especialidad_id = ?
            GROUP BY ts.id, ts.nombre
            HAVING total_temas > 0
            ORDER BY ts.id
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$especialidad_id]);
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $breadcrumb = [
            ['nombre' => 'Áreas', 'url' => 'entrenamiento.php'],
            ['nombre' => $area['nombre'], 'url' => "entrenamiento.php?area={$area_id}"],
            ['nombre' => $especialidad['nombre'], 'url' => null]
        ];
        break;
        
    case 'temas':
        // Nivel 4: Listar TEMAS
        $sql_area = "SELECT nombre FROM areas WHERE id = ?";
        $stmt = $pdo->prepare($sql_area);
        $stmt->execute([$area_id]);
        $area = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $sql_esp = "SELECT nombre FROM especialidades WHERE id = ?";
        $stmt = $pdo->prepare($sql_esp);
        $stmt->execute([$especialidad_id]);
        $especialidad = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $sql_tipo = "SELECT nombre FROM tipos_situacion WHERE id = ?";
        $stmt = $pdo->prepare($sql_tipo);
        $stmt->execute([$tipo_id]);
        $tipo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $sql = "
            SELECT 
                t.id,
                t.codigo_completo,
                t.nombre,
                t.total_preguntas
            FROM temas t
            WHERE t.especialidad_id = ?
            AND t.tipo_situacion_id = ?
            ORDER BY t.codigo_completo
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$especialidad_id, $tipo_id]);
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $breadcrumb = [
            ['nombre' => 'Áreas', 'url' => 'entrenamiento.php'],
            ['nombre' => $area['nombre'], 'url' => "entrenamiento.php?area={$area_id}"],
            ['nombre' => $especialidad['nombre'], 'url' => "entrenamiento.php?area={$area_id}&especialidad={$especialidad_id}"],
            ['nombre' => $tipo['nombre'], 'url' => null]
        ];
        break;
        
    case 'preguntas':
        // Nivel 5: Mostrar PREGUNTAS
        $sql_tema = "
            SELECT t.*, a.nombre as area_nombre, e.nombre as especialidad_nombre, ts.nombre as tipo_nombre
            FROM temas t
            INNER JOIN especialidades e ON t.especialidad_id = e.id
            INNER JOIN areas a ON e.area_id = a.id
            INNER JOIN tipos_situacion ts ON t.tipo_situacion_id = ts.id
            WHERE t.id = ?
        ";
        $stmt = $pdo->prepare($sql_tema);
        $stmt->execute([$tema_id]);
        $tema = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $sql_preguntas = "
            SELECT 
                p.id,
                p.numero_pregunta,
                p.texto_pregunta,
                e.respuesta_correcta,
                e.explicacion_completa
            FROM preguntas p
            LEFT JOIN explicaciones e ON p.id = e.pregunta_id
            WHERE p.tema_id = ?
            ORDER BY p.numero_pregunta
        ";
        $stmt = $pdo->prepare($sql_preguntas);
        $stmt->execute([$tema_id]);
        $preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);		

        
        foreach ($preguntas as &$pregunta) {
            $sql_alt = "
                SELECT opcion, texto_alternativa, es_correcta
                FROM alternativas
                WHERE pregunta_id = ?
                ORDER BY orden
            ";
            $stmt = $pdo->prepare($sql_alt);
            $stmt->execute([$pregunta['id']]);
            $pregunta['alternativas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($pregunta);
        $mostrar_resultados = false;
        $respuestas_usuario = [];
        $puntaje = 0;
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $mostrar_resultados = true;
            
            foreach ($preguntas as $pregunta) {
                $respuesta = isset($_POST['pregunta_' . $pregunta['id']]) ? $_POST['pregunta_' . $pregunta['id']] : null;
                $respuestas_usuario[$pregunta['id']] = $respuesta;
                
                if ($respuesta === $pregunta['respuesta_correcta']) {
                    $puntaje++;
                }
            }
        }
        
        $breadcrumb = [
            ['nombre' => 'Áreas', 'url' => 'entrenamiento.php'],
            ['nombre' => $tema['area_nombre'], 'url' => "entrenamiento.php?area={$tema['area_id']}"],
            ['nombre' => $tema['especialidad_nombre'], 'url' => "entrenamiento.php?area={$tema['area_id']}&especialidad={$tema['especialidad_id']}"],
            ['nombre' => $tema['tipo_nombre'], 'url' => "entrenamiento.php?area={$tema['area_id']}&especialidad={$tema['especialidad_id']}&tipo={$tema['tipo_situacion_id']}"],
            ['nombre' => $tema['nombre'], 'url' => null]
        ];
        break;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrenamiento EUNACOM</title>
    <link rel="stylesheet" href="<?= buildUrl('css/style.css') ?>">
</head>
<body class="page-entrenamiento">
    <div class="container">
	
	 <div class="header">
            <div class="header-left">
                <h1>🏥 <?= SITE_NAME ?></h1>
                <p>Sistema de Preparación EUNACOM</p>
            </div>
            <div class="header-right">
                <div class="user-info">
                    <div class="user-name">👤 <?= e($usuario['nombre']) ?></div>
                    <div class="user-email"><?= e($usuario['email']) ?></div>
                </div>
				<a href="<?= buildUrl('index.php') ?>" class="btn-home-green">
    🏠 Inicio
</a>
                <a href="<?= buildUrl('logout.php') ?>" class="btn-logout">
                    🚪 Cerrar Sesión
                </a>
            </div>
        </div>
        
        <!-- BREADCRUMB -->
        <div class="breadcrumb">
            <?php foreach ($breadcrumb as $index => $item): ?>
                <?php if ($item['url']): ?>
                    <a href="<?= $item['url'] ?>"><?= e($item['nombre']) ?></a>
                <?php else: ?>
                    <span class="breadcrumb-current"><?= e($item['nombre']) ?></span>
                <?php endif; ?>
                
                <?php if ($index < count($breadcrumb) - 1): ?>
                    <span class="breadcrumb-separator">›</span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        
        <?php if ($nivel === 'preguntas'): ?>
            <!-- VISTA DE PREGUNTAS -->
            
            <?php if ($mostrar_resultados): ?>
    <div class="resultados">
        <?php 
        // Calcular estadísticas
        $total_preguntas = count($preguntas);
        $correctas = $puntaje;
        $respondidas = 0;
        $omitidas = 0;
        $incorrectas = 0;
        
        // Contar respondidas y omitidas
        foreach ($preguntas as $pregunta) {
            if (isset($respuestas_usuario[$pregunta['id']]) && $respuestas_usuario[$pregunta['id']] !== null) {
                $respondidas++;
            } else {
                $omitidas++;
            }
        }
        
        // Incorrectas = respondidas - correctas
        $incorrectas = $respondidas - $correctas;
        
        // Porcentaje sobre el total (no solo sobre respondidas)
        $porcentaje = round(($correctas / $total_preguntas) * 100);
        $mensaje = $porcentaje >= 70 ? '¡Excelente!' : ($porcentaje >= 50 ? 'Buen trabajo' : 'Sigue practicando');
        ?>
        
        <h2><?= $mensaje ?></h2>
        <div class="score-circle"><?= $porcentaje ?>%</div>
        
        <div class="stats">
            <div class="stat-box stat-correctas">
                <div style="font-size: 1.8rem; font-weight: bold;"><?= $correctas ?></div>
                <div>Correctas</div>
            </div>
            <div class="stat-box stat-incorrectas">
                <div style="font-size: 1.8rem; font-weight: bold;"><?= $incorrectas ?></div>
                <div>Incorrectas</div>
            </div>
            <div class="stat-box stat-omitidas">
                <div style="font-size: 1.8rem; font-weight: bold;"><?= $omitidas ?></div>
                <div>Omitidas</div>
            </div>
            <div class="stat-box stat-total">
                <div style="font-size: 1.8rem; font-weight: bold;"><?= $total_preguntas ?></div>
                <div>Total</div>
            </div>
        </div>
    </div>
<?php endif; ?>
            
            <div class="card">
                <h1><?= e($tema['nombre']) ?></h1>
                <p class="subtitle">
                    <?= e($tema['codigo_completo']) ?> • 
                    <?= count($preguntas) ?> preguntas
                </p>
                
                <div style="margin-bottom: 20px;">
                    <a href="entrenamiento.php?area=<?= $tema['area_id'] ?>&especialidad=<?= $tema['especialidad_id'] ?>&tipo=<?= $tema['tipo_situacion_id'] ?>" class="btn" style="background: #95a5a6;">
                        ← Volver a temas
                    </a>
                    <?php if ($mostrar_resultados): ?>
                        <button class="btn" onclick="location.reload()">🔄 Reintentar</button>
                    <?php endif; ?>
                </div>
            </div>
            
            <form method="POST">
                <?php foreach ($preguntas as $pregunta): ?>
                    <div class="card pregunta-card">
                        <div style="margin-bottom: 15px;">
                            <span class="pregunta-numero"><?= $pregunta['numero_pregunta'] ?></span>
                            <span class="pregunta-texto"><?= nl2br(e($pregunta['texto_pregunta'])) ?></span>
                        </div>
                        
                        <?php foreach ($pregunta['alternativas'] as $alt): ?>
                            <?php
                            $es_seleccionada = $mostrar_resultados && isset($respuestas_usuario[$pregunta['id']]) && $respuestas_usuario[$pregunta['id']] === $alt['opcion'];
                            $es_correcta = $mostrar_resultados && $alt['es_correcta'];
                            $es_incorrecta = $mostrar_resultados && $es_seleccionada && !$alt['es_correcta'];
                            
                            $clase = '';
                            $clase_letra = '';
                            if ($es_correcta) {
                                $clase = 'correcta';
                                $clase_letra = 'correcta';
                            } elseif ($es_incorrecta) {
                                $clase = 'incorrecta';
                                $clase_letra = 'incorrecta';
                            }
                            ?>
                            
                            <label class="alternativa <?= $clase ?>">
                                <input 
                                    type="radio" 
                                    name="pregunta_<?= $pregunta['id'] ?>" 
                                    value="<?= $alt['opcion'] ?>"
                                    <?= $es_seleccionada ? 'checked' : '' ?>
                                    <?= $mostrar_resultados ? 'disabled' : '' ?>
                                >
                                <span class="opcion-letra <?= $clase_letra ?>"><?= $alt['opcion'] ?></span>
                                <span><?= e($alt['texto_alternativa']) ?></span>
                                
                                <?php if ($es_correcta): ?>
                                    <span style="margin-left: auto; color: #28a745; font-weight: bold;">✓</span>
                                <?php elseif ($es_incorrecta): ?>
                                    <span style="margin-left: auto; color: #dc3545; font-weight: bold;">✗</span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                        
                        <?php if ($mostrar_resultados && $pregunta['explicacion_completa']): ?>
                            <div class="explicacion show">
                                <strong>💡 Explicación:</strong><br>
                                <?= nl2br(e($pregunta['explicacion_completa'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <?php if (!$mostrar_resultados): ?>
                    <div class="card" style="text-align: center;">
                        <button type="submit" class="btn">📝 Enviar Respuestas</button>
                    </div>
                <?php endif; ?>
            </form>
            
        <?php else: ?>
            <!-- VISTAS DE NAVEGACIÓN -->
            <div class="card">
                <?php if ($nivel === 'areas'): ?>
                    <h1>📚 Áreas de Conocimiento</h1>
                    <p class="subtitle">Selecciona un área para comenzar</p>
                    
                <?php elseif ($nivel === 'especialidades'): ?>
                    <h1>🏥 Especialidades</h1>
                    <p class="subtitle">Selecciona una especialidad</p>
                    
                <?php elseif ($nivel === 'tipos'): ?>
                    <h1>📋 Tipos de Situación</h1>
                    <p class="subtitle">Selecciona el tipo de pregunta</p>
                    
                <?php elseif ($nivel === 'temas'): ?>
                    <h1>📖 Temas Disponibles</h1>
                    <p class="subtitle">Selecciona un tema para practicar</p>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <div class="grid">
                    <?php foreach ($datos as $item): ?>
                        <?php
                        // Construir URL según el nivel
                        $url = 'entrenamiento.php?';
                        
                        if ($nivel === 'areas') {
                            $url .= "area={$item['id']}";
                            $icono = '🏥';
                            $meta = "{$item['total_especialidades']} especialidades • {$item['total_temas']} temas";
                        } elseif ($nivel === 'especialidades') {
                            $url .= "area={$area_id}&especialidad={$item['id']}";
                            $icono = '🔬';
                            $meta = "{$item['total_temas']} temas disponibles";
                        } elseif ($nivel === 'tipos') {
                            $url .= "area={$area_id}&especialidad={$especialidad_id}&tipo={$item['id']}";
                            $icono = '📝';
                            $meta = "{$item['total_temas']} temas";
                        } elseif ($nivel === 'temas') {
                            $url .= "area={$area_id}&especialidad={$especialidad_id}&tipo={$tipo_id}&tema={$item['id']}";
                            $icono = '📄';
                            $meta = "{$item['total_preguntas']} preguntas";
                        }
                        ?>
                        
                        <a href="<?= $url ?>" class="item-card">
                            <div class="icon"><?= $icono ?></div>
                            <div class="item-title"><?= e($item['nombre']) ?></div>
                            <?php if ($nivel === 'temas'): ?>
                                <span class="item-badge"><?= e($item['codigo_completo']) ?></span>
                            <?php endif; ?>
                            <div class="item-meta"><?= $meta ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            
        <?php endif; ?>
        
    </div>

<!-- Botones flotantes de navegación -->
<div class="scroll-buttons">
    <button id="scrollToTop" class="scroll-btn scroll-btn-top" title="Ir al inicio">
        ↑
    </button>
    <button id="scrollToBottom" class="scroll-btn scroll-btn-bottom" title="Ir al final">
        ↓
    </button>
</div>

<script>
// Botones de navegación
var btnTop = document.getElementById('scrollToTop');
var btnBottom = document.getElementById('scrollToBottom');

// Mostrar/ocultar botones según scroll
window.onscroll = function() {
    if (document.body.scrollTop > 300 || document.documentElement.scrollTop > 300) {
        btnTop.style.display = 'flex';
    } else {
        btnTop.style.display = 'none';
    }
    
    var windowHeight = window.innerHeight;
    var documentHeight = document.documentElement.scrollHeight;
    var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    
    if (scrollTop + windowHeight < documentHeight - 300) {
        btnBottom.style.display = 'flex';
    } else {
        btnBottom.style.display = 'none';
    }
};

// Scroll suave al inicio
btnTop.onclick = function() {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
};

// Scroll suave al final
btnBottom.onclick = function() {
    window.scrollTo({
        top: document.documentElement.scrollHeight,
        behavior: 'smooth'
    });
};
</script>

</body>
</html>