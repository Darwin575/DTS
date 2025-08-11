<?php
if ($document && isset($document['file_path']) && !empty($document['file_path'])) {
    $relativePath = str_replace(['\\', '../'], ['/', ''], $document['file_path']);
    $relativePath = preg_replace('/^.*documents\//', '', $relativePath);
    $filename = basename($relativePath);
    $filePath = $_SERVER['DOCUMENT_ROOT'] . '/DTS/documents/' . $filename;
    $fileUrl = "/DTS/documents/" . rawurlencode($filename);
    $fileType = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!file_exists($filePath) || $fileType === 'php') {
        $fileUrl = null;
        $fileType = null;
    }
} else {
    $filename = null;
    $filePath = null;
    $fileUrl = null;
    $fileType = null;
}
?>

<div class="document-preview mb-4 border p-3">
    <?php if ($fileUrl && $fileType === 'pdf'): ?>
        <div class="preview-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <div class="preview-filename">
                <i class="fa fa-file-pdf-o text-danger"></i> <?= htmlspecialchars($filename) ?>
            </div>
            <button type="button" class="btn btn-link fullscreen-btn" title="Fullscreen"
                onclick="window.open('/DTS/js/plugins/web/viewer.html?file=<?= urlencode($fileUrl) ?>','_blank')">
                <i class="fa fa-expand"></i>
            </button>
        </div>
        <div id="pdfjs-canvas-preview" style="width:100%; min-height:300px; max-height:80vh; overflow:auto; border:1px solid #ccc; padding: 10px;">
            <!-- Canvases will be dynamically added here -->
        </div>

        <script type="module">
            import * as pdfjsLib from "/DTS/js/plugins/build/pdf.mjs";
            pdfjsLib.GlobalWorkerOptions.workerSrc = "/DTS/js/plugins/build/pdf.worker.mjs";
            // Add loading indicator
            document.getElementById('pdfjs-canvas-preview').innerHTML =
                '<div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> Loading PDF pages...</div>';

            // STEP 1: Replace the existing renderPDFWithJS function with this updated version

            function renderPDFWithJS(source, containerId) {
                if (!pdfjsLib) {
                    document.getElementById(containerId).innerHTML =
                        '<span class="text-warning">PDF.js not loaded.</span>';
                    return;
                }

                try {
                    const loadingTask = pdfjsLib.getDocument(source);
                    loadingTask.promise.then(
                        function(pdf) {
                            console.log("PDF.js loaded PDF object:", pdf);
                            console.log("Total pages:", pdf.numPages);

                            // Clear the container and prepare for multiple pages
                            const container = document.getElementById(containerId);
                            container.innerHTML = '';

                            // Get container width for scaling
                            const containerWidth = container.clientWidth;

                            // Function to render a single page
                            function renderPage(pageNum) {
                                return pdf.getPage(pageNum).then(function(page) {
                                    console.log(`PDF.js loaded page ${pageNum}:`, page);

                                    // Calculate scale based on container width
                                    const unscaledViewport = page.getViewport({
                                        scale: 1
                                    });
                                    const scale = containerWidth / unscaledViewport.width;
                                    const viewport = page.getViewport({
                                        scale
                                    });

                                    // Create canvas for this page
                                    const canvas = document.createElement('canvas');
                                    canvas.id = `${containerId.replace('pdfjs-', '')}-page-${pageNum}`;
                                    canvas.style.display = 'block';
                                    canvas.style.marginBottom = '10px';
                                    canvas.style.border = '1px solid #ddd';

                                    // Create page number label
                                    const pageLabel = document.createElement('div');
                                    pageLabel.textContent = `Page ${pageNum} of ${pdf.numPages}`;
                                    pageLabel.style.textAlign = 'center';
                                    pageLabel.style.padding = '5px';
                                    pageLabel.style.backgroundColor = '#f8f9fa';
                                    pageLabel.style.fontSize = '12px';
                                    pageLabel.style.color = '#666';
                                    pageLabel.style.marginBottom = '5px';

                                    // Append to container
                                    container.appendChild(pageLabel);
                                    container.appendChild(canvas);

                                    const context = canvas.getContext("2d");

                                    if (viewport.width === 0 || viewport.height === 0) {
                                        const errorMsg = document.createElement('div');
                                        errorMsg.className = 'text-danger';
                                        errorMsg.textContent = `Page ${pageNum} has zero width/height.`;
                                        container.appendChild(errorMsg);
                                        return;
                                    }

                                    canvas.width = viewport.width;
                                    canvas.height = viewport.height;

                                    const renderContext = {
                                        canvasContext: context,
                                        viewport: viewport,
                                    };

                                    return page.render(renderContext).promise.then(function() {
                                        console.log(`Page ${pageNum} rendered successfully`);
                                    }).catch(function(renderErr) {
                                        console.error(`PDF.js render error for page ${pageNum}:`, renderErr);
                                        const errorMsg = document.createElement('div');
                                        errorMsg.className = 'text-danger';
                                        errorMsg.textContent = `Failed to render page ${pageNum}.`;
                                        container.appendChild(errorMsg);
                                    });
                                }).catch(function(pageErr) {
                                    console.error(`PDF.js getPage error for page ${pageNum}:`, pageErr);
                                    const errorMsg = document.createElement('div');
                                    errorMsg.className = 'text-danger';
                                    errorMsg.textContent = `Failed to load page ${pageNum}.`;
                                    container.appendChild(errorMsg);
                                });
                            }

                            // Render all pages sequentially
                            let renderPromise = Promise.resolve();
                            for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                                renderPromise = renderPromise.then(() => renderPage(pageNum));
                            }

                            renderPromise.catch(function(err) {
                                console.error("Error rendering pages:", err);
                            });
                        },
                        function(reason) {
                            console.error("PDF.js loading error:", reason);
                            document.getElementById(containerId).innerHTML =
                                '<span class="text-danger">Failed to load PDF preview.</span>';
                        }
                    );
                } catch (err) {
                    console.error("PDF.js exception:", err);
                    document.getElementById(containerId).innerHTML =
                        '<span class="text-danger">PDF.js error: ' + err.message + '</span>';
                }
            }

            // Initialize PDF preview
            renderPDFWithJS('<?= $fileUrl ?>', 'pdfjs-canvas-preview');
        </script>

    <?php elseif ($fileUrl && $fileType === 'docx'): ?>
        <div class="preview-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <div class="preview-filename">
                <i class="fa fa-file-word-o text-primary"></i> <?= htmlspecialchars($filename) ?>
            </div>
            <div>
                <button type="button" class="btn btn-link" title="Download"
                    onclick="window.open('<?= $fileUrl ?>','_blank')">
                    <i class="fa fa-download"></i>
                </button>
            </div>
        </div>
        <div id="docx-preview-container" style="width:100%; min-height:300px; max-height:80vh; overflow:auto; border:1px solid #ccc; padding: 15px; background: white;">
            <div class="text-center text-muted">
                <i class="fa fa-spinner fa-spin"></i> Loading document...
            </div>
        </div>

        <script type="module">
            import * as mammoth from 'https://cdn.skypack.dev/mammoth';

            async function renderDocxWithMammoth(fileUrl, containerId) {
                try {
                    const response = await fetch(fileUrl);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }

                    const arrayBuffer = await response.arrayBuffer();
                    const result = await mammoth.convertToHtml({
                        arrayBuffer: arrayBuffer
                    });

                    const container = document.getElementById(containerId);
                    if (result.value) {
                        container.innerHTML = result.value;

                        // Style the content
                        container.style.fontFamily = 'Arial, sans-serif';
                        container.style.lineHeight = '1.6';
                        container.style.fontSize = '14px';

                        // Add some basic styling to common elements
                        const style = document.createElement('style');
                        style.textContent = `
                            #${containerId} h1, #${containerId} h2, #${containerId} h3, #${containerId} h4, #${containerId} h5, #${containerId} h6 {
                                color: #333;
                                margin-top: 20px;
                                margin-bottom: 10px;
                            }
                            #${containerId} p {
                                margin-bottom: 10px;
                            }
                            #${containerId} table {
                                border-collapse: collapse;
                                width: 100%;
                                margin: 10px 0;
                            }
                            #${containerId} table, #${containerId} th, #${containerId} td {
                                border: 1px solid #ddd;
                            }
                            #${containerId} th, #${containerId} td {
                                padding: 8px;
                                text-align: left;
                            }
                            #${containerId} th {
                                background-color: #f2f2f2;
                            }
                        `;
                        document.head.appendChild(style);

                        // Show warnings if any
                        if (result.messages && result.messages.length > 0) {
                            const warnings = result.messages.filter(m => m.type === 'warning');
                            if (warnings.length > 0) {
                                console.warn('Mammoth conversion warnings:', warnings);
                            }
                        }
                    } else {
                        container.innerHTML = '<div class="text-danger">Failed to convert document content.</div>';
                    }
                } catch (error) {
                    console.error('Mammoth conversion error:', error);
                    document.getElementById(containerId).innerHTML =
                        `<div class="text-danger">Error loading document: ${error.message}</div>`;
                }
            }

            // Initialize DOCX preview
            renderDocxWithMammoth('<?= $fileUrl ?>', 'docx-preview-container');
        </script>

    <?php elseif ($fileUrl && $fileType === 'doc'): ?>
        <div class="alert alert-info">
            <i class="fa fa-file-word-o"></i>
            <a href="<?= $fileUrl ?>" target="_blank" download="<?= htmlspecialchars($filename) ?>">
                Download and view DOC file (preview not available)
            </a>
            <small class="d-block mt-1"><?= formatFileSize($document['file_size'] ?? 0) ?></small>
        </div>
    <?php elseif ($fileUrl): ?>
        <div class="alert alert-info">
            <i class="fa fa-download"></i>
            <a href="<?= $fileUrl ?>" download="<?= htmlspecialchars($filename) ?>">
                Download <?= strtoupper($fileType) ?> File
            </a>
            <small class="d-block mt-1"><?= formatFileSize($document['file_size'] ?? 0) ?></small>
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            File not available or access denied.
        </div>
    <?php endif; ?>
</div>

<?php
function formatFileSize($bytes)
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}
?>