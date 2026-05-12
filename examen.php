<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Examen - Sistema de Evaluación Médica</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/examen.css">
</head>
<body class="page-examen">
    <?php
    // Verificar que se haya pasado el parámetro tema
    if (!isset($_GET['tema'])) {
        header('Location: index.php');
        exit;
    }
    
    $codigo_tema = $_GET['tema'];
    
    // Cargar el índice para encontrar el tema
    $index_file = '_json_output/index.json';
    if (!file_exists($index_file)) {
        die('<div class="alert alert-danger m-5">Error: No se encontró el índice de temas.</div>');
    }
    
    $index_data = json_decode(file_get_contents($index_file), true);
    
    // Buscar el tema en el índice
    $tema_encontrado = null;
    $ruta_json = null;
    
    foreach ($index_data['categorias'] as $categoria) {
        foreach ($categoria['subcategorias'] as $subcategoria) {
            foreach ($subcategoria['temas'] as $tema) {
                if ($tema['codigo'] === $codigo_tema) {
                    $tema_encontrado = $tema;
                    $ruta_json = '_json_output/' . $tema['ruta_json'];
                    break 3;
                }
            }
        }
    }
    
    if (!$tema_encontrado || !file_exists($ruta_json)) {
        die('<div class="alert alert-danger m-5">Error: No se encontró el tema solicitado.</div>');
    }
    
    // Cargar el JSON del tema
    $tema_data = json_decode(file_get_contents($ruta_json), true);
    
    if (!$tema_data) {
        die('<div class="alert alert-danger m-5">Error: No se pudo cargar el contenido del tema.</div>');
    }
    
    $mostrar_resultados = false;
    $respuestas_usuario = array();
    $puntaje = 0;
    $total_preguntas = count($tema_data['preguntas']);
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $mostrar_resultados = true;
        
        foreach ($tema_data['preguntas'] as $index => $pregunta) {
            $respuesta_usuario = isset($_POST['question_' . $index]) ? $_POST['question_' . $index] : null;
            $respuestas_usuario[$index] = $respuesta_usuario;
            
            if ($respuesta_usuario === $pregunta['respuesta_correcta']) {
                $puntaje++;
            }
        }
        
        $porcentaje = round(($puntaje / $total_preguntas) * 100);
    }
    ?>
    
    <div class="main-container">
        <!-- Header -->
        <div class="header-card">
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <h1><i class="fas fa-stethoscope"></i> Examen Médico</h1>
                    <div class="topic-badge">
                        <i class="fas fa-book-medical"></i> 
                        <?php echo e($tema_data['codigo']); ?>
                    </div>
                </div>
                <a href="index.php" class="btn back-btn">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
            </div>
            
            <div class="breadcrumb-custom">
                <i class="fas fa-folder"></i> 
                <strong><?php echo e($tema_data['categoria']); ?></strong>
                <i class="fas fa-chevron-right mx-2"></i>
                <i class="fas fa-folder-open"></i>
                <?php echo e($tema_data['subcategoria']); ?>
                <i class="fas fa-chevron-right mx-2"></i>
                <span class="badge bg-secondary"><?php echo e($tema_data['tipo']); ?></span>
            </div>
        </div>
        
        <?php if ($mostrar_resultados): ?>
            <!-- Resultados -->
            <div class="results-card">
                <?php
                $clase_score = 'score-poor';
                $mensaje = 'Sigue practicando';
                $icono = 'fa-sad-tear';
                
                if ($porcentaje >= 90) {
                    $clase_score = 'score-excellent';
                    $mensaje = '¡Excelente trabajo!';
                    $icono = 'fa-trophy';
                } elseif ($porcentaje >= 70) {
                    $clase_score = 'score-good';
                    $mensaje = '¡Buen trabajo!';
                    $icono = 'fa-smile';
                } elseif ($porcentaje >= 50) {
                    $clase_score = 'score-average';
                    $mensaje = 'Puedes mejorar';
                    $icono = 'fa-meh';
                }
                ?>
                
                <div class="score-circle <?php echo $clase_score; ?>">
                    <?php echo $porcentaje; ?>%
                </div>
                
                <h2><i class="fas <?php echo $icono; ?>"></i> <?php echo $mensaje; ?></h2>
                
                <div class="stats-grid">
                    <div class="stat-box stat-correct">
                        <i class="fas fa-check-circle"></i>
                        <div><strong><?php echo $puntaje; ?></strong></div>
                        <small>Correctas</small>
                    </div>
                    <div class="stat-box stat-incorrect">
                        <i class="fas fa-times-circle"></i>
                        <div><strong><?php echo ($total_preguntas - $puntaje); ?></strong></div>
                        <small>Incorrectas</small>
                    </div>
                    <div class="stat-box stat-unanswered">
                        <i class="fas fa-list-ol"></i>
                        <div><strong><?php echo $total_preguntas; ?></strong></div>
                        <small>Total</small>
                    </div>
                </div>
                
                <div class="mt-4">
                    <button class="btn btn-primary reset-btn" onclick="location.reload()">
                        <i class="fas fa-redo"></i> Intentar de nuevo
                    </button>
                    <a href="index.php" class="btn back-btn">
                        <i class="fas fa-list"></i> Elegir otro tema
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Barra de progreso -->
            <div class="progress-section">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span><i class="fas fa-tasks"></i> Progreso del examen</span>
                    <span id="progress-text">0 de <?php echo $total_preguntas; ?> respondidas</span>
                </div>
                <div class="progress">
                    <div id="progress-bar" class="progress-bar bg-success" role="progressbar" style="width: 0%"></div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Formulario de preguntas -->
        <form method="post" id="exam-form">
            <?php foreach ($tema_data['preguntas'] as $index => $pregunta): ?>
                <div class="question-card">
                    <div class="d-flex align-items-start mb-3">
                        <span class="question-number"><?php echo $pregunta['numero']; ?></span>
                        <div class="question-text flex-grow-1">
                            <?php echo e($pregunta['texto']); ?>
                        </div>
                    </div>
                    
                    <div class="alternatives">
                        <?php foreach ($pregunta['alternativas'] as $alternativa): ?>
                            <?php
                            $opcion = $alternativa['opcion'];
                            $texto = $alternativa['texto'];
                            
                            $es_seleccionada = $mostrar_resultados && isset($respuestas_usuario[$index]) && $respuestas_usuario[$index] === $opcion;
                            $respuesta_correcta = $pregunta['respuesta_correcta'];
                            $es_correcta = $mostrar_resultados && $opcion === $respuesta_correcta;
                            $es_incorrecta = $mostrar_resultados && $es_seleccionada && $opcion !== $respuesta_correcta;
                            
                            $clase_alternativa = '';
                            $clase_letra = '';
                            
                            if ($es_correcta) {
                                $clase_alternativa = 'correct';
                                $clase_letra = 'correct-letter';
                            } elseif ($es_incorrecta) {
                                $clase_alternativa = 'incorrect';
                                $clase_letra = 'incorrect-letter';
                            } elseif ($es_seleccionada) {
                                $clase_alternativa = 'selected';
                            }
                            ?>
                            
                            <label class="alternative-option <?php echo $clase_alternativa; ?>">
                                <input type="radio" 
                                       name="question_<?php echo $index; ?>" 
                                       value="<?php echo $opcion; ?>"
                                       <?php echo $es_seleccionada ? 'checked' : ''; ?>
                                       <?php echo $mostrar_resultados ? 'disabled' : ''; ?>
                                       onchange="updateProgress()">
                                <span class="option-letter <?php echo $clase_letra; ?>"><?php echo $opcion; ?></span>
                                <span class="flex-grow-1"><?php echo e($texto); ?></span>
                                
                                <?php if ($es_correcta): ?>
                                    <i class="fas fa-check-circle text-success ms-2"></i>
                                <?php elseif ($es_incorrecta): ?>
                                    <i class="fas fa-times-circle text-danger ms-2"></i>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($mostrar_resultados && isset($pregunta['explicacion'])): ?>
                        <div class="explanation-box show">
                            <strong><i class="fas fa-lightbulb explanation-icon"></i> Explicación:</strong>
                            <p class="mb-0 mt-2"><?php echo e($pregunta['explicacion']); ?></p>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="fas fa-check"></i> Respuesta correcta: 
                                    <strong><?php echo $respuesta_correcta; ?></strong>
                                </small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
            <?php if (!$mostrar_resultados): ?>
                <div class="text-center mb-5">
                    <button type="submit" class="btn btn-primary submit-btn">
                        <i class="fas fa-paper-plane"></i> Enviar Respuestas
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>
    
    <script>
        function updateProgress() {
            const total = <?php echo $total_preguntas; ?>;
            const answered = document.querySelectorAll('input[type="radio"]:checked').length;
            const percentage = (answered / total) * 100;
            
            document.getElementById('progress-bar').style.width = percentage + '%';
            document.getElementById('progress-text').textContent = answered + ' de ' + total + ' respondidas';
        }
        
        // Scroll suave al hacer clic en submit
        document.getElementById('exam-form')?.addEventListener('submit', function(e) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        
        // Confirmar antes de salir si hay respuestas sin enviar
        <?php if (!$mostrar_resultados): ?>
        window.addEventListener('beforeunload', function(e) {
            const answered = document.querySelectorAll('input[type="radio"]:checked').length;
            if (answered > 0) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        <?php endif; ?>
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>