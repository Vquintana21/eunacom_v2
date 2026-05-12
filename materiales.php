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
$documento_id = isset($_GET['documento']) ? (int)$_GET['documento'] : null;

if ($documento_id) {
    $nivel = 'visor';
} elseif ($especialidad_id) {
    $nivel = 'documentos';
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
                COUNT(DISTINCT d.id) as total_documentos
            FROM areas a
            LEFT JOIN especialidades e ON a.id = e.area_id
            LEFT JOIN documentos_estudio d ON a.id = d.area_id AND d.activo = 1
            WHERE a.activo = 1
            GROUP BY a.id, a.nombre
            HAVING total_documentos > 0
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
                COUNT(d.id) as total_documentos,
                ROUND(SUM(d.tamano_kb) / 1024, 2) as total_mb
            FROM especialidades e
            LEFT JOIN documentos_estudio d ON e.id = d.especialidad_id AND d.activo = 1
            WHERE e.area_id = ?
            GROUP BY e.id, e.nombre, e.codigo_especialidad
            HAVING total_documentos > 0
            ORDER BY e.codigo_especialidad
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$area_id]);
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $breadcrumb = [
            ['nombre' => 'Áreas', 'url' => buildUrl('materiales.php')],
            ['nombre' => $area['nombre'], 'url' => null]
        ];
        break;
        
    case 'documentos':
        // Nivel 3: Listar DOCUMENTOS de una especialidad
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
                d.id,
                d.nombre_documento,
                d.nombre_archivo,
                d.ruta_relativa,
                d.tamano_kb,
                d.orden
            FROM documentos_estudio d
            WHERE d.area_id = ? 
            AND d.especialidad_id = ?
            AND d.activo = 1
            ORDER BY d.orden, d.nombre_documento
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$area_id, $especialidad_id]);
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $breadcrumb = [
            ['nombre' => 'Áreas', 'url' => buildUrl('materiales.php')],
            ['nombre' => $area['nombre'], 'url' => buildUrl("materiales.php?area={$area_id}")],
            ['nombre' => $especialidad['nombre'], 'url' => null]
        ];
        break;
        
    case 'visor':
        // Nivel 4: Mostrar VISOR DE PDF
        $sql = "
            SELECT 
                d.*,
                a.nombre as area_nombre,
                e.nombre as especialidad_nombre
            FROM documentos_estudio d
            INNER JOIN areas a ON d.area_id = a.id
            INNER JOIN especialidades e ON d.especialidad_id = e.id
            WHERE d.id = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$documento_id]);
        $documento = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$documento) {
            die("Documento no encontrado");
        }
        
        // Construir URL correcta del documento
        $documento['ruta_web_correcta'] = buildMaterialUrl($documento['ruta_relativa']);
        
        // Obtener documento anterior y siguiente
        $sql_prev = "
            SELECT id, nombre_documento 
            FROM documentos_estudio 
            WHERE especialidad_id = ? 
            AND orden < ? 
            AND activo = 1
            ORDER BY orden DESC 
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql_prev);
        $stmt->execute([$documento['especialidad_id'], $documento['orden']]);
        $prev_doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $sql_next = "
            SELECT id, nombre_documento 
            FROM documentos_estudio 
            WHERE especialidad_id = ? 
            AND orden > ? 
            AND activo = 1
            ORDER BY orden ASC 
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql_next);
        $stmt->execute([$documento['especialidad_id'], $documento['orden']]);
        $next_doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $breadcrumb = [
            ['nombre' => 'Áreas', 'url' => buildUrl('materiales.php')],
            ['nombre' => $documento['area_nombre'], 'url' => buildUrl("materiales.php?area={$documento['area_id']}")],
            ['nombre' => $documento['especialidad_nombre'], 'url' => buildUrl("materiales.php?area={$documento['area_id']}&especialidad={$documento['especialidad_id']}")],
            ['nombre' => $documento['nombre_documento'], 'url' => null]
        ];
        break;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Materiales de Estudio - EUNACOM</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
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
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 20px;
        }
        
        .breadcrumb {
            background: #f8f9fa;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .breadcrumb a {
            color: #3498db;
            text-decoration: none;
            font-weight: 500;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        .breadcrumb-separator {
            margin: 0 10px;
            color: #7f8c8d;
        }
        
        .breadcrumb-current {
            color: #2c3e50;
            font-weight: 600;
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #7f8c8d;
            margin-bottom: 20px;
        }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .item-card {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: inherit;
            display: block;
            position: relative;
        }
        
        .item-card:hover {
            background: #e3f2fd;
            border-color: #3498db;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
        
        .item-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .item-badge {
            display: inline-block;
            background: #3498db;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-right: 8px;
            margin-bottom: 8px;
        }
        
        .item-badge.badge-success {
            background: #27ae60;
        }
        
        .item-badge.badge-warning {
            background: #f39c12;
        }
        
        .item-meta {
            color: #7f8c8d;
            font-size: 0.9rem;
            margin-top: 10px;
        }
        
        .icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        /* Estilos para documentos */
        .doc-card {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .doc-card:hover {
            background: #fff;
            border-color: #3498db;
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.2);
            transform: translateX(5px);
        }
        
        .doc-card.hidden {
            display: none;
        }
        
        .doc-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            flex-shrink: 0;
        }
        
        .doc-info {
            flex: 1;
        }
        
        .doc-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .doc-size {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .doc-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 0.95rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            white-space: nowrap;
        }
        
        .btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
        
        .btn-secondary {
            background: #95a5a6;
        }
        
        .btn-secondary:hover {
            background: #7f8c8d;
        }
        
        .btn-success {
            background: #27ae60;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        .btn-small {
            padding: 8px 15px;
            font-size: 0.85rem;
        }
        
        /* Visor PDF */
        .pdf-viewer-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .pdf-header {
            background: #2c3e50;
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .pdf-title {
            font-size: 1.3rem;
            font-weight: 600;
            flex: 1;
        }
        
        .pdf-controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .pdf-viewer {
            width: 100%;
            height: 80vh;
            border: none;
        }
        
        .nav-docs {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            gap: 15px;
        }
        
        .nav-doc-btn {
            flex: 1;
            padding: 15px;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            text-decoration: none;
            color: #2c3e50;
        }
        
        .nav-doc-btn:hover:not(.disabled) {
            background: #e3f2fd;
            border-color: #3498db;
        }
        
        .nav-doc-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .nav-doc-label {
            font-size: 0.85rem;
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        
        .nav-doc-title {
            font-weight: 600;
            font-size: 0.95rem;
        }
        
        /* Buscador de documentos */
        .search-container {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .search-box {
            position: relative;
            display: flex;
            align-items: center;
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            transition: all 0.3s;
        }
        
        .search-box:focus-within {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .search-box i {
            color: #7f8c8d;
            margin-right: 10px;
            font-size: 1.2rem;
        }
        
        .search-box input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 1rem;
            color: #2c3e50;
        }
        
        .search-box input::placeholder {
            color: #95a5a6;
        }
        
        #clear-search {
            background: none;
            border: none;
            color: #7f8c8d;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 5px;
            transition: all 0.3s;
        }
        
        #clear-search:hover {
            color: #e74c3c;
        }
        
        #search-results {
            background: #fff3cd;
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 10px;
            color: #856404;
            font-size: 0.95rem;
        }
        
        .no-results {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
            background: #f8f9fa;
            border-radius: 10px;
            margin-top: 20px;
        }
        
        .no-results i {
            font-size: 3rem;
            display: block;
            margin-bottom: 15px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
            
            .doc-card {
                flex-direction: column;
                text-align: center;
            }
            
            .doc-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .btn {
                width: 100%;
            }
            
            .pdf-controls {
                width: 100%;
            }
            
            .nav-docs {
                flex-direction: column;
            }
        }
        
        /* Indicador de carga */
        .loading {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }
        
        .loading::after {
            content: '⏳';
            font-size: 3rem;
            display: block;
            margin-top: 20px;
        }

        /* Alert de configuración */
        .config-alert {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .config-alert strong {
            color: #856404;
        }
		
		/* Header */
        .header {
            background: white;
            border-radius: 15px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left h1 {
            color: #2c3e50;
            font-size: 1.8rem;
            margin-bottom: 5px;
        }
        
        .header-left p {
            color: #7f8c8d;
        }
        
        .header-right {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .user-info {
            text-align: right;
        }
        
        .user-name {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .user-email {
            font-size: 0.85rem;
            color: #7f8c8d;
        }
        
        .btn-logout {
            padding: 10px 20px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .btn-logout:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }
		.btn-home-green {
            padding: 10px 20px;
            background: #27ae60;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            font-weight: 600;
        }

        .btn-home-green:hover {
            background: #229954;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(39, 174, 96, 0.3);
        }

        /* Botón Azul */
        .btn-home-blue {
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            font-weight: 600;
        }

        .btn-home-blue:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.3);
        }
		
    </style>
</head>
<body>
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
        <!-- ALERT DE CONFIGURACIÓN (Solo visible en desarrollo) -->
        <?php /* if (ENTORNO === 'desarrollo'): ?>
            <div class="config-alert">
                <span style="font-size: 1.5rem;">⚙️</span>
                <div>
                    <strong>Modo: <?= ENTORNO ?></strong><br>
                    <small>Base: <?= BASE_URL ?> | Materiales: <?= MATERIALES_URL ?></small>
                </div>
            </div>
        <?php endif; */?>
        
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
        
        <?php if ($nivel === 'visor'): ?>
    <!-- Redirigir al visor PDF.js -->
    <script>
        window.location.href = '<?= buildUrl("pdf-viewer.php?id={$documento_id}") ?>';
    </script>
    
    <!-- Fallback por si JavaScript está deshabilitado -->
    <div class="card">
        <p style="text-align: center; padding: 40px;">
            Redirigiendo al visor de documentos...<br><br>
            <a href="<?= buildUrl("pdf-viewer.php?id={$documento_id}") ?>" class="btn">
                Si no te redirige automáticamente, haz clic aquí
            </a>
        </p>
    </div>

            
        <?php elseif ($nivel === 'documentos'): ?>
            <!-- LISTA DE DOCUMENTOS -->
            
            <div class="card">
				<div class="header">
						<div class="header-left">
							<h1>📚 Materiales de Estudio</h1>
							  <p class="subtitle">
                    <?= e($especialidad['nombre']) ?> • 
                    <span id="total-documentos"><?= count($datos) ?></span> documentos disponibles
                </p>
						</div>
						<div class="header-right">
							<a href="<?= buildUrl('descargas_masivas.php') ?>" class="btn-home-green">
							<i class="bi bi-cloud-download"></i> Descargar Todo
							</a>
						</div>	
				 </div>
				 
				 <!-- BUSCADOR DE DOCUMENTOS -->
				 <div class="search-container">
					<div class="search-box">
						<i class="bi bi-search"></i>
						<input 
							type="text" 
							id="buscador-docs" 
							placeholder="🔍 Buscar documento por nombre..."
							autocomplete="off"
						>
						<button id="clear-search" style="display: none;">✕</button>
					</div>
					<div id="search-results" style="display: none;"></div>
				 </div>
				 
                <a href="<?= buildUrl("materiales.php?area={$area_id}") ?>" class="btn btn-secondary" style="margin-bottom: 20px;">
                    ← Volver a especialidades
                </a>
            </div>
            
            <?php if (empty($datos)): ?>
                <div class="card">
                    <p style="text-align: center; color: #7f8c8d; padding: 40px;">
                        📭 No hay documentos disponibles en esta especialidad
                    </p>
                </div>
            <?php else: ?>
                <div class="card">
                    <div id="documentos-container">
                        <?php foreach ($datos as $doc): ?>
                            <?php $doc_url_correcta = buildMaterialUrl($doc['ruta_relativa']); ?>
                            <div class="doc-card" data-nombre="<?= strtolower(e($doc['nombre_documento'])) ?>" onclick="window.location.href='<?= buildUrl("materiales.php?area={$area_id}&especialidad={$especialidad_id}&documento={$doc['id']}") ?>'">
                                <div class="doc-icon">📄</div>
                                <div class="doc-info">
                                    <div class="doc-title"><?= e($doc['nombre_documento']) ?></div>
                                    <div class="doc-size">
                                        📦 <?= formatBytes($doc['tamano_kb']) ?> • 
                                        PDF
                                    </div>
                                </div>
                                <div class="doc-actions">
                                    <a href="<?= buildUrl("materiales.php?area={$area_id}&especialidad={$especialidad_id}&documento={$doc['id']}") ?>" 
                                       class="btn btn-small" 
                                       onclick="event.stopPropagation()">
                                        👁 Ver
                                    </a>
                                    <a href="<?= e($doc_url_correcta) ?>" 
                                       download="<?= e($doc['nombre_archivo']) ?>" 
                                       class="btn btn-success btn-small" 
                                       onclick="event.stopPropagation()">
                                        ⬇ Descargar
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Mensaje cuando no hay resultados -->
                    <div id="no-results" class="no-results" style="display: none;">
                        <i class="bi bi-search"></i>
                        <div style="font-size: 1.2rem; font-weight: 600; margin-bottom: 10px;">
                            No se encontraron documentos
                        </div>
                        <div style="color: #95a5a6;">
                            Intenta con otros términos de búsqueda
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <script>
            // BUSCADOR EN TIEMPO REAL
            (function() {
                const buscador = document.getElementById('buscador-docs');
                const clearBtn = document.getElementById('clear-search');
                const searchResults = document.getElementById('search-results');
                const totalDocs = document.getElementById('total-documentos');
                const noResults = document.getElementById('no-results');
                const documentos = document.querySelectorAll('.doc-card');
                const totalOriginal = documentos.length;
                
                buscador.addEventListener('input', function() {
                    const termino = this.value.toLowerCase().trim();
                    
                    // Mostrar/ocultar botón limpiar
                    clearBtn.style.display = termino ? 'block' : 'none';
                    
                    if (termino === '') {
                        // Mostrar todos
                        documentos.forEach(doc => doc.classList.remove('hidden'));
                        searchResults.style.display = 'none';
                        noResults.style.display = 'none';
                        totalDocs.textContent = totalOriginal;
                        return;
                    }
                    
                    // Filtrar documentos
                    let contador = 0;
                    documentos.forEach(doc => {
                        const nombre = doc.getAttribute('data-nombre');
                        if (nombre.includes(termino)) {
                            doc.classList.remove('hidden');
                            contador++;
                        } else {
                            doc.classList.add('hidden');
                        }
                    });
                    
                    // Actualizar contador
                    totalDocs.textContent = contador;
                    
                    // Mostrar mensaje de resultados
                    if (contador > 0) {
                        searchResults.style.display = 'block';
                        searchResults.innerHTML = `<i class="bi bi-filter-circle"></i> Se encontraron <strong>${contador}</strong> documento(s) que coinciden con "${termino}"`;
                        noResults.style.display = 'none';
                    } else {
                        searchResults.style.display = 'none';
                        noResults.style.display = 'block';
                    }
                });
                
                // Limpiar búsqueda
                clearBtn.addEventListener('click', function() {
                    buscador.value = '';
                    buscador.dispatchEvent(new Event('input'));
                    buscador.focus();
                });
                
                // Atajo de teclado: Ctrl+F o Cmd+F
                document.addEventListener('keydown', function(e) {
                    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                        e.preventDefault();
                        buscador.focus();
                        buscador.select();
                    }
                    
                    // ESC para limpiar
                    if (e.key === 'Escape' && buscador.value) {
                        clearBtn.click();
                    }
                });
            })();
            </script>
            
        <?php else: ?>
            <!-- VISTAS DE NAVEGACIÓN (ÁREAS Y ESPECIALIDADES) -->
            
            <div class="card">
                <?php if ($nivel === 'areas'): ?>
                    <div class="header">
						<div class="header-left">
							<h1>📚 Materiales de Estudio</h1>
							<p class="subtitle">Selecciona un área para ver los materiales disponibles</p>
						</div>
						<div class="header-right">
							<a href="<?= buildUrl('descargas_masivas.php') ?>" class="btn-home-green">
							<i class="bi bi-cloud-download"></i> Descargar Todo
							</a>
						</div>
					</div>
                    
                <?php elseif ($nivel === 'especialidades'): ?>
				<div class="header">
						<div class="header-left">
                    <h1>🏥 Especialidades</h1>
                    <p class="subtitle">Selecciona una especialidad para ver los documentos</p>
					</div>
						<div class="header-right">
							<a href="<?= buildUrl('descargas_masivas.php') ?>" class="btn-home-green">
							<i class="bi bi-cloud-download"></i> Descargar Todo
							</a>
						</div>
					</div>
                <?php endif; ?>
            </div>
            
            <?php if (empty($datos)): ?>
                <div class="card">
                    <p style="text-align: center; color: #7f8c8d; padding: 40px;">
                        📭 No hay materiales disponibles
                    </p>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="grid">
                        <?php foreach ($datos as $item): ?>
                            <?php
                            // Construir URL según el nivel
                            if ($nivel === 'areas') {
                                $url = buildUrl("materiales.php?area={$item['id']}");
                                $icono = '🏥';
                                $meta = "{$item['total_documentos']} documentos";
                            } elseif ($nivel === 'especialidades') {
                                $url = buildUrl("materiales.php?area={$area_id}&especialidad={$item['id']}");
                                $icono = '📁';
                                $meta = "{$item['total_documentos']} documentos • " . number_format($item['total_mb'], 2) . " MB";
                            }
                            ?>
                            
                            <a href="<?= $url ?>" class="item-card">
                                <div class="icon"><?= $icono ?></div>
                                <div class="item-title"><?= e($item['nombre']) ?></div>
                                <?php if ($item['total_documentos'] > 0): ?>
                                    <span class="item-badge badge-success">✓ Disponible</span>
                                <?php endif; ?>
                                <div class="item-meta"><?= $meta ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
        <?php endif; ?>
        
    </div>
</body>
</html>