<?php
require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/classes/Logger.php';

// SEGURIDAD: Requiere autenticación
requireAuth();

$documento_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$documento_id) {
    die("Documento no especificado");
}

$pdo = getDB();

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
Logger::acceso('documento', $documento_id, 'ver');
// Construir URL del PDF
$pdf_url = buildMaterialUrl($documento['ruta_relativa']);

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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= e($documento['nombre_documento']) ?> - EUNACOM</title>
    
    <!-- PDF.js Library -->
    <script src="pdfjs/build/pdf.js"></script>
    <link rel="stylesheet" href="<?= buildUrl('css/style.css') ?>">
</head>
<body class="page-pdfviewer">
    <!-- Header -->
    <div id="pdf-header">
        <div class="header-content">
            <div class="doc-title">
                📄 <?= e($documento['nombre_documento']) ?>
            </div>
            <div class="header-buttons">
                <a href="<?= buildUrl("materiales.php?area={$documento['area_id']}&especialidad={$documento['especialidad_id']}") ?>" class="btn">
                    ← Volver
                </a>
                <a href="<?= buildUrl('download.php?tipo=pdf&id=' . $documento['id']) ?>" class="btn btn-success">
                    ⬇ Descargar
                </a>
            </div>
        </div>
    </div>
    
    <!-- Toolbar -->
    <div id="pdf-toolbar">
        <div class="toolbar-group">
            <button id="prev-page" class="toolbar-btn" title="Página anterior">◀</button>
            <div id="page-info">
                <input type="number" id="page-input" min="1" value="1"> / <span id="page-count">0</span>
            </div>
            <button id="next-page" class="toolbar-btn" title="Página siguiente">▶</button>
        </div>
        
        <div class="toolbar-group">
            <button id="zoom-out" class="toolbar-btn" title="Alejar">-</button>
            <span id="zoom-level" style="min-width: 60px; text-align: center;">100%</span>
            <button id="zoom-in" class="toolbar-btn" title="Acercar">+</button>
            <button id="zoom-fit" class="toolbar-btn" title="Ajustar a pantalla">⊡</button>
        </div>
    </div>
    
    <!-- Loading -->
    <div id="loading">
        <h3>Cargando documento...</h3>
        <div class="spinner"></div>
    </div>
    
    <!-- Canvas Container -->
    <div id="canvas-container">
        <canvas id="pdf-canvas"></canvas>
    </div>
    
    <!-- Navigation Footer -->
    <div id="nav-footer" style="display: none;">
        <?php if ($prev_doc): ?>
            <a href="<?= buildUrl("pdf-viewer.php?id={$prev_doc['id']}") ?>" class="nav-btn">
                <div class="nav-label">← Documento anterior</div>
                <div class="nav-title"><?= e(mb_strimwidth($prev_doc['nombre_documento'], 0, 50, '...')) ?></div>
            </a>
        <?php else: ?>
            <div class="nav-btn disabled">
                <div class="nav-label">← Documento anterior</div>
                <div class="nav-title">No disponible</div>
            </div>
        <?php endif; ?>
        
        <?php if ($next_doc): ?>
            <a href="<?= buildUrl("pdf-viewer.php?id={$next_doc['id']}") ?>" class="nav-btn">
                <div class="nav-label">Siguiente documento →</div>
                <div class="nav-title"><?= e(mb_strimwidth($prev_doc['nombre_documento'], 0, 50, '...')) ?></div>
            </a>
        <?php else: ?>
            <div class="nav-btn disabled">
                <div class="nav-label">Siguiente documento →</div>
                <div class="nav-title">No disponible</div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Configurar PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'pdfjs/build/pdf.worker.js';
        
        const pdfUrl = <?= json_encode($pdf_url) ?>;
        
        let pdfDoc = null;
        let pageNum = 1;
        let pageRendering = false;
        let pageNumPending = null;
        let scale = 1.5;
        
        const canvas = document.getElementById('pdf-canvas');
        const ctx = canvas.getContext('2d');
        const loading = document.getElementById('loading');
        const canvasContainer = document.getElementById('canvas-container');
        
        // Renderizar página
        function renderPage(num) {
            pageRendering = true;
            
            pdfDoc.getPage(num).then(function(page) {
                const viewport = page.getViewport({scale: scale});
                canvas.height = viewport.height;
                canvas.width = viewport.width;
                
                const renderContext = {
                    canvasContext: ctx,
                    viewport: viewport
                };
                
                const renderTask = page.render(renderContext);
                
                renderTask.promise.then(function() {
                    pageRendering = false;
                    if (pageNumPending !== null) {
                        renderPage(pageNumPending);
                        pageNumPending = null;
                    }
                });
            });
            
            // Actualizar controles
            document.getElementById('page-input').value = num;
            updateButtons();
        }
        
        function queueRenderPage(num) {
            if (pageRendering) {
                pageNumPending = num;
            } else {
                renderPage(num);
            }
        }
        
        function onPrevPage() {
            if (pageNum <= 1) return;
            pageNum--;
            queueRenderPage(pageNum);
        }
        
        function onNextPage() {
            if (pageNum >= pdfDoc.numPages) return;
            pageNum++;
            queueRenderPage(pageNum);
        }
        
        function updateButtons() {
            document.getElementById('prev-page').disabled = pageNum <= 1;
            document.getElementById('next-page').disabled = pageNum >= pdfDoc.numPages;
        }
        
        function zoomIn() {
            scale += 0.25;
            queueRenderPage(pageNum);
            updateZoomLevel();
        }
        
        function zoomOut() {
            if (scale <= 0.5) return;
            scale -= 0.25;
            queueRenderPage(pageNum);
            updateZoomLevel();
        }
        
        function zoomFit() {
            const containerWidth = canvasContainer.clientWidth - 40;
            pdfDoc.getPage(pageNum).then(function(page) {
                const viewport = page.getViewport({scale: 1});
                scale = containerWidth / viewport.width;
                queueRenderPage(pageNum);
                updateZoomLevel();
            });
        }
        
        function updateZoomLevel() {
            document.getElementById('zoom-level').textContent = Math.round(scale * 100) + '%';
        }
        
        function goToPage() {
            const input = document.getElementById('page-input');
            const newPage = parseInt(input.value);
            
            if (newPage >= 1 && newPage <= pdfDoc.numPages && newPage !== pageNum) {
                pageNum = newPage;
                queueRenderPage(pageNum);
            } else {
                input.value = pageNum;
            }
        }
        
        // Event Listeners
        document.getElementById('prev-page').addEventListener('click', onPrevPage);
        document.getElementById('next-page').addEventListener('click', onNextPage);
        document.getElementById('zoom-in').addEventListener('click', zoomIn);
        document.getElementById('zoom-out').addEventListener('click', zoomOut);
        document.getElementById('zoom-fit').addEventListener('click', zoomFit);
        document.getElementById('page-input').addEventListener('change', goToPage);
        document.getElementById('page-input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') goToPage();
        });
        
        // Navegación con teclado
        document.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
                e.preventDefault();
                onPrevPage();
            }
            if (e.key === 'ArrowRight' || e.key === 'PageDown') {
                e.preventDefault();
                onNextPage();
            }
            if (e.key === '+' || e.key === '=') {
                e.preventDefault();
                zoomIn();
            }
            if (e.key === '-' || e.key === '_') {
                e.preventDefault();
                zoomOut();
            }
        });
        
        // Cargar PDF
        pdfjsLib.getDocument(pdfUrl).promise.then(function(pdfDoc_) {
            pdfDoc = pdfDoc_;
            document.getElementById('page-count').textContent = pdfDoc.numPages;
            document.getElementById('page-input').max = pdfDoc.numPages;
            
            // Ocultar loading
            loading.style.display = 'none';
            document.getElementById('nav-footer').style.display = 'flex';
            
            // Renderizar primera página
            renderPage(pageNum);
            updateZoomLevel();
            
            // Ajustar a pantalla en móviles
            if (window.innerWidth <= 768) {
                zoomFit();
            }
        }).catch(function(error) {
            loading.innerHTML = `
                <h3>❌ Error al cargar el PDF</h3>
                <p style="margin-top: 15px;">${error.message}</p>
                <p style="margin-top: 15px;">
                    <a href="<?= $pdf_url ?>" download style="color: white; text-decoration: underline;">
                        Descargar documento
                    </a>
                </p>
            `;
            console.error('Error cargando PDF:', error);
        });
    </script>
</body>
</html>