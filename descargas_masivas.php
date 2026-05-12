<?php
/**
 * ============================================
 * DESCARGAS MASIVAS CON RATE LIMITING
 * ============================================
 * 
 * LINEAMIENTOS DE SEGURIDAD IMPLEMENTADOS:
 * - Autenticación requerida (requireAuth)
 * - Prepared statements (SQL injection)
 * - Escape de salida con e() (XSS)
 * - Integración con Logger
 * ============================================
 */

require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/classes/Logger.php';

// ============================================
// SEGURIDAD: Requiere autenticación
// ============================================
requireAuth();

$usuario = getCurrentUser();
$usuario_id = $usuario['id'];

$pdo = getDB();

// Log de acceso a la página
Logger::acceso('pagina', 0, 'descargas_masivas');

// ============================================
// OBTENER DATOS
// ============================================
// Obtener todas las áreas con sus ZIPs (incluyendo el ID del ZIP)
$sql = "
    SELECT 
        a.id,
        a.nombre,
        z.id as zip_id,
        z.nombre_zip,
        z.ruta_zip,
        z.tamano_kb,
        z.total_archivos,
        z.fecha_generacion
    FROM areas a
    LEFT JOIN zips_materiales z ON a.id = z.area_id AND z.nivel = 'area' AND z.activo = 1
    ORDER BY a.id
";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$areas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Descargas Masivas - <?php echo e(SITE_NAME); ?></title>
    <link rel="stylesheet" href="<?= buildUrl('css/style.css') ?>">
</head>
<body class="page-descargas">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1>🏥 <?php echo e(SITE_NAME); ?></h1>
                <p>Descargas Masivas de Materiales</p>
            </div>
            <div class="header-right">
                <div class="user-info">
                    <div class="user-name">👤 <?php echo e($usuario['nombre']); ?></div>
                    <div class="user-email"><?php echo e($usuario['email']); ?></div>
                </div>
                <a href="<?php echo buildUrl('index.php'); ?>" class="btn-home">🏠 Inicio</a>
                <a href="<?php echo buildUrl('logout.php'); ?>" class="btn-logout">🚪 Salir</a>
            </div>
        </div>
        
        <!-- Intro -->
        <div class="card">
            <h2>📦 Descarga Materiales por Paquetes</h2>
            <p class="subtitle">
                Descarga todos los materiales de estudio organizados por área o especialidad.
                Los archivos ZIP contienen todos los PDFs correspondientes listos para estudiar offline.
            </p>
            <div class="info-box">
                <p>ℹ️ <strong>Límites de descarga:</strong> 10 áreas completas/hora, 20 especialidades/hora, 100 PDFs/hora.</p>
            </div>
        </div>
        
        <!-- Listado de Áreas -->
        <div class="card">
            <?php if (empty($areas)): ?>
                <p style="text-align: center; color: #7f8c8d; padding: 40px;">
                    📭 No hay materiales disponibles para descarga
                </p>
            <?php else: ?>
                <?php foreach ($areas as $area): ?>
                    <div class="area-item" id="area-<?php echo (int)$area['id']; ?>">
                        <!-- Header del Área -->
                        <div class="area-header" onclick="toggleArea(<?php echo (int)$area['id']; ?>)">
                            <div>
                                <div class="area-title">
                                    <span class="area-icon">📚</span>
                                    <span><?php echo e($area['nombre']); ?></span>
                                </div>
                                <?php if (!empty($area['nombre_zip'])): ?>
                                    <div class="file-info">
                                        📦 <?php echo formatBytes($area['tamano_kb']); ?> • 
                                        <?php echo (int)$area['total_archivos']; ?> archivos • 
                                        Actualizado: <?php echo date('d/m/Y', strtotime($area['fecha_generacion'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="area-actions">
                                <?php if (!empty($area['zip_id'])): ?>
                                    <a href="<?php echo buildUrl('download.php?tipo=zip_area&id=' . (int)$area['zip_id']); ?>" 
                                       class="btn-download"
                                       onclick="event.stopPropagation()">
                                        ⬇️ Descargar Área Completa
                                    </a>
                                <?php else: ?>
                                    <span class="no-zip-message">ZIP no disponible</span>
                                <?php endif; ?>
                                <button type="button" class="btn-toggle" onclick="event.stopPropagation(); toggleArea(<?php echo (int)$area['id']; ?>)">
                                    <span id="toggle-icon-<?php echo (int)$area['id']; ?>">▼ Ver Especialidades</span>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Contenido expandible (Especialidades) -->
                        <div class="area-content" id="content-<?php echo (int)$area['id']; ?>">
                            <?php
                            // Obtener ZIPs de especialidades del área con prepared statement
                            $sql_esp = "
                                SELECT 
                                    z.id,
                                    z.nombre_zip,
                                    z.ruta_zip,
                                    z.tamano_kb,
                                    z.total_archivos
                                FROM zips_materiales z
                                WHERE z.area_id = ?
                                AND z.nivel = 'especialidad' 
                                AND z.activo = 1
                                ORDER BY z.nombre_zip
                            ";
                            $stmt_esp = $pdo->prepare($sql_esp);
                            $stmt_esp->execute(array($area['id']));
                            $especialidades = $stmt_esp->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (empty($especialidades)):
                            ?>
                                <div class="especialidad-item">
                                    <p style="color: #7f8c8d; font-style: italic;">
                                        No hay materiales disponibles para descarga individual
                                    </p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($especialidades as $esp): ?>
                                    <?php
                                    // Formatear nombre legible
                                    $nombre_display = str_replace(array('_', '.zip'), array(' ', ''), $esp['nombre_zip']);
                                    $tamano_mb = number_format($esp['tamano_kb'] / 1024, 2);
                                    ?>
                                    <div class="especialidad-item">
                                        <div>
                                            <div class="item-title">
                                                🏥 <?php echo e($nombre_display); ?>
                                            </div>
                                            <div class="file-info">
                                                <?php echo $tamano_mb; ?> MB • 
                                                <?php echo (int)$esp['total_archivos']; ?> archivos
                                            </div>
                                        </div>
                                        
                                        <a href="<?php echo buildUrl('download.php?tipo=zip_esp&id=' . (int)$esp['id']); ?>" 
                                           class="btn-download">
                                            ⬇️ Descargar
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function toggleArea(areaId) {
            var content = document.getElementById('content-' + areaId);
            var header = document.querySelector('#area-' + areaId + ' .area-header');
            var icon = document.getElementById('toggle-icon-' + areaId);
            
            if (content.classList.contains('show')) {
                content.classList.remove('show');
                header.classList.remove('active');
                icon.textContent = '▼ Ver Especialidades';
            } else {
                content.classList.add('show');
                header.classList.add('active');
                icon.textContent = '▲ Ocultar Especialidades';
            }
        }
    </script>
</body>
</html>