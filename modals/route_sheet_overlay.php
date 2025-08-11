<?php

// /modals/route_sheet_overlay.php
require_once __DIR__ . '/../server-logic/document/document_helpers.php';
$document_id = isset($_GET['rejected_doc_id']) ? intval($_GET['rejected_doc_id']) : '';

$inSystem = isInSystem($conn, $document_id);
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<!-- Toastr JS/CSS for notifications -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<!-- No jQuery or PDF.js script tags here; parent loads them -->

<div class="route-sheet-hala-overlay d-none align-items-center justify-content-center"
  style="
       position: fixed;
       inset: 0;
       z-index: 2001;
       background: rgba(250,251,254,0.98);
       
       overflow-y: auto;     /* overlay scrolls vertically */
       overflow-x: hidden;   /* no horizontal scroll */
     ">
  <div class="shadow border rounded-lg bg-white d-flex flex-column"
    style="
         width: 100%;
         max-width: 98vw;
         max-height: calc(100vh - 20px); /* full height minus overlay padding */
         min-width: 0;
       ">

    <!-- STICKY HEADER -->
    <div class="d-flex justify-content-between align-items-center
                px-3 py-2 border-bottom bg-white"
      style="
           position: sticky;
           top: 0;              /* stick to top of card */
           z-index: 10;
         ">
      <button id="route-hala-back-btn"
        class="btn btn-outline-secondary btn-sm flex-shrink-0"
        style="min-width:85px;">
        <i class="bi bi-arrow-left"></i> Back
      </button>
      <span class="fw-bold text-truncate mx-2"
        style="font-size:1.1rem; max-width:calc(100% - 180px);">
        Route Sheet Preview
      </span>
      <div style="width:85px;"></div>
    </div>

    <!-- CONTENT: grows but does NOT scroll itself -->
    <div class="d-flex justify-content-center p-3"
      style="
           background: #f9fafc;
           flex: 1 1 auto;
           min-width: 0;
         ">
      <!-- PDF.js Main Overlay Viewer -->
      <div id="mainPdfViewerContainer" class="w-100" style="max-width:900px;">
        <div class="pdf-controls mb-2">
          <button id="mainPrevPage" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-chevron-left"></i> Previous
          </button>
          <span id="mainPageInfo" class="mx-3">Page <span id="mainPageNum">1</span> of <span id="mainPageCount">1</span></span>
          <button id="mainNextPage" class="btn btn-sm btn-outline-secondary">
            Next <i class="bi bi-chevron-right"></i>
          </button>
          <div class="ms-3">
            <button id="mainZoomOut" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-zoom-out"></i>
            </button>
            <span id="mainZoomLevel" class="mx-2">100%</span>
            <button id="mainZoomIn" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-zoom-in"></i>
            </button>
          </div>
        </div>
        <div class="pdf-canvas-container" style="max-height: 60vh; overflow: auto; border: 1px solid #ddd;">
          <canvas id="mainPdfCanvas" style="display: block; margin: 0 auto;"></canvas>
        </div>
      </div>
    </div>

    <!-- STICKY FOOTER -->
    <div id="defaultOverlayFooter" class="px-3 pt-2 pb-3 bg-light border-top d-flex flex-column align-items-center"
      style="
           position: sticky;
           bottom: 0;           /* stick to bottom of card */
           z-index: 10;
           min-width: 0;
         ">
      <div class="mb-3 w-100 d-flex justify-content-center gap-2">
        <button id="hala-route-print-btn" class="btn btn-outline-primary btn-sm me-1">
          <i class="bi bi-printer"></i> Print
        </button>
        <a id="hala-route-download-btn" class="btn btn-outline-success btn-sm" download>
          <i class="bi bi-download"></i> Download
        </a>
      </div>
      <div class="d-flex flex-column flex-md-row gap-3 w-100 justify-content-center align-items-center">
        <div class="d-flex flex-column align-items-center mb-3 w-100 w-md-auto">
          <label class="form-label mb-1 small">Upload Signed Route Sheet</label>
          <input id="signedRouteSheetFile" type="file" accept=".pdf,.doc,.docx"
            class="form-control form-control-sm" style="max-width:300px; width:100%;" />
          <button id="uploadSignedRouteSheetBtn" class="btn btn-outline-success btn-sm mt-2 w-100">
            <i class="bi bi-upload"></i> Upload & Preview
          </button>
        </div>

        <span class="fw-bold text-muted mt-3 mt-md-0">OR</span>
        <?php if ($inSystem != 2): ?>

          <div class="d-flex flex-column align-items-center w-100 w-md-auto">
            <label class="form-label mb-1 small">Append your e-Signature & Route</label>
            <button id="appendESigAndRouteBtn" class="btn btn-outline-warning btn-sm w-100">
              <i class="bi bi-person-bounding-box"></i> Append e-Sig & Route
            </button>
            <div class="small text-muted mt-1 text-center" style="max-width:260px;">
              You'll need to login then OTP.<br />
              After verifying, you'll return to sign and route instantly.
            </div>
          </div>
        <?php else: ?>
          <div class="d-flex flex-column align-items-center w-100 w-md-auto">
            <label class="form-label mb-1 small">Route with your Appended e-sig</label>
            <button id="esigAppendedProceedBtn" class="btn btn-outline-warning btn-sm w-100">
              <i class="bi bi-person-bounding-box"></i> Route
            </button>

          </div>
        <?php endif; ?>


        <span class="fw-bold text-muted mt-3 mt-md-0">OR</span>

        <div class="d-flex flex-column align-items-center w-100 w-md-auto">
          <label class="form-label mb-1 small">Route with Printed Sheet</label>
          <button id="routeWithPrintedBtn" class="btn btn-outline-secondary btn-sm w-100" disabled>
            <i class="bi bi-send"></i> Route via Hardcopy
          </button>
          <div class="small text-muted mt-1 text-center" style="max-width:260px;">
            Print and attach the route sheet to your document,<br />
            then submit it through manual/physical routing.
          </div>
        </div>

      </div>
      <?php if ($inSystem == 1): ?>
        <div class="mt-3 w-100">
          <button id="routeWithExistingRouteSheetBtn" class="btn btn-outline-info btn-sm w-75 d-block mx-auto">
            <i class="bi bi-arrow-right-circle"></i> Route with Existing Route Sheet
          </button>
        </div>
      <?php endif; ?>

    </div>

  </div>
</div>

<!-- Confirm Modal -->
<div class="modal fade" id="confirmHardcopyModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-primary">Confirm Physical Routing</h5>
        <button type="button" class="close" id="hCloseModal">
          <span>&times;</span>
        </button>
      </div>
      <div class="modal-body">
        Are you sure you want to finalize and route this document using a printed route sheet?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="cancelHardcopyRouteBtn">Cancel</button>
        <button id="confirmHardcopyRouteBtn" class="btn btn-primary">Yes, Proceed</button>
      </div>
    </div>
  </div>
</div>

<!-- Upload Review Modal -->
<div class="modal fade" id="uploadedRouteSheetReviewModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Review Uploaded Route Sheet</h5>
        <button type="button" class="close" id="rCloseModal" aria-label="Close">
          <span>&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div id="uploadedRouteSheetViewer" class="text-center">
          <!-- PDF Viewer Container -->
          <div id="pdfViewerContainer" class="d-none">
            <div class="pdf-controls mb-2">
              <button id="prevPage" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-chevron-left"></i> Previous
              </button>
              <span id="pageInfo" class="mx-3">Page <span id="pageNum">1</span> of <span id="pageCount">1</span></span>
              <button id="nextPage" class="btn btn-sm btn-outline-secondary">
                Next <i class="bi bi-chevron-right"></i>
              </button>
              <div class="ms-3">
                <button id="zoomOut" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-zoom-out"></i>
                </button>
                <span id="zoomLevel" class="mx-2">100%</span>
                <button id="zoomIn" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-zoom-in"></i>
                </button>
              </div>
            </div>
            <div class="pdf-canvas-container" style="max-height: 60vh; overflow: auto; border: 1px solid #ddd;">
              <canvas id="pdfCanvas" style="display: block; margin: 0 auto;"></canvas>
            </div>
          </div>

          <!-- Image Viewer Container -->
          <div id="imageViewerContainer" class="d-none">
            <img id="imageViewer" class="img-fluid rounded" alt="Route sheet" style="max-height:60vh;" />
          </div>

          <!-- DOCX Viewer Container -->
          <div id="docxViewerContainer" class="d-none" style="max-height: 60vh; overflow: auto; border: 1px solid #ddd; padding: 15px;">
          </div>

          <!-- Iframe Viewer Container (fallback) -->
          <div id="iframeViewerContainer" class="d-none">
            <iframe id="iframeViewer" style="width:100%;height:60vh;" frameborder="0"></iframe>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="uploadedRouteSheetCancelBtn">
          Cancel
        </button>
        <button type="button" class="btn btn-primary position-relative" id="uploadedRouteSheetRouteBtn">
          <!-- Spinner Container -->
          <div class="spinner-container" style="display: none;">
            <span class="spinner-border spinner-border-sm text-light"></span>
          </div>
          <!-- Visible text -->
          <span class="button-text">Route Document</span>
        </button>
      </div>
    </div>
  </div>
</div>

<?php
// include __DIR__ . '/../modals/login_modal.php';
?>

<script>
  $(document).ready(function() {
    // Debug PDF.js loading
    console.log('PDF.js loaded:', typeof pdfjsLib !== 'undefined');
    console.log('PDF.js version:', pdfjsLib?.version || 'Not loaded');

    // Test PDF.js worker
    if (typeof pdfjsLib !== 'undefined') {
      console.log('PDF.js worker configured:', pdfjsLib.GlobalWorkerOptions.workerSrc);
    }
  });
  let previewData = {};
  let hasPrintedOrDownloaded;
  hasPrintedOrDownloaded = false;
  // PDF.js setup
  // import * as pdfjsLib from "/DTS/js/plugins/build/pdf.mjs";
  // pdfjsLib.GlobalWorkerOptions.workerSrc = "/DTS/js/plugins/build/pdf.worker.mjs";
  // PDF.js setup with better error handling
  let isPdfjsReady = false;

  // Wait for PDF.js to be fully loaded from parent
  $(document).ready(function() {
    if (typeof pdfjsLib !== 'undefined') {
      // Use the same workerSrc as parent (should be set in parent, but set here for safety)
      if (!pdfjsLib.GlobalWorkerOptions.workerSrc) {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
      }
      isPdfjsReady = true;
      console.log('PDF.js initialized from parent');
    } else {
      console.error('PDF.js not loaded from parent');
    }
  });

  let currentPdf = null;
  let currentPageNum = 1;
  let currentScale = 1.0;

  // PDF viewer functions with better error handling
  function renderPdfPage(pdf, pageNum, scale = 1.0) {
    console.log('Rendering page:', pageNum, 'at scale:', scale);

    return pdf.getPage(pageNum).then(function(page) {
      const canvas = document.getElementById('pdfCanvas');
      const ctx = canvas.getContext('2d');

      if (!canvas || !ctx) {
        throw new Error('Canvas not found or context unavailable');
      }

      const viewport = page.getViewport({
        scale: scale
      });
      canvas.height = viewport.height;
      canvas.width = viewport.width;

      const renderContext = {
        canvasContext: ctx,
        viewport: viewport
      };

      return page.render(renderContext).promise.then(function() {
        console.log('Page rendered successfully');
        document.getElementById('pageNum').textContent = pageNum;
        document.getElementById('zoomLevel').textContent = Math.round(scale * 100) + '%';

        // Update navigation buttons
        document.getElementById('prevPage').disabled = (pageNum <= 1);
        document.getElementById('nextPage').disabled = (pageNum >= pdf.numPages);
      });
    }).catch(function(error) {
      console.error('Error rendering PDF page:', error);
      throw error;
    });
  }

  function loadPdfViewer(file) {
    console.log('Loading PDF viewer for file:', file.name, 'Size:', file.size);

    if (!isPdfjsReady) {
      console.error('PDF.js not ready');
      toastr.error('PDF viewer not ready. Please try again.');
      return Promise.reject('PDF.js not ready');
    }

    const fileUrl = URL.createObjectURL(file);
    console.log('Created blob URL:', fileUrl);

    return pdfjsLib.getDocument({
      url: fileUrl,
      verbosity: 1 // Add some verbosity for debugging
    }).promise.then(function(pdf) {
      console.log('PDF loaded successfully. Pages:', pdf.numPages);

      currentPdf = pdf;
      currentPageNum = 1;
      currentScale = 1.0;

      document.getElementById('pageCount').textContent = pdf.numPages;

      // Show PDF viewer first, then render
      hideAllViewers();
      document.getElementById('pdfViewerContainer').classList.remove('d-none');

      // Render the first page
      return renderPdfPage(pdf, currentPageNum, currentScale);

    }).catch(function(error) {
      console.error('Detailed PDF loading error:', error);
      URL.revokeObjectURL(fileUrl); // Clean up on error

      // More specific error messages
      if (error.name === 'InvalidPDFException') {
        toastr.error('Invalid PDF file. Please select a valid PDF.');
      } else if (error.name === 'MissingPDFException') {
        toastr.error('PDF file appears to be corrupted.');
      } else if (error.name === 'UnexpectedResponseException') {
        toastr.error('Unable to load PDF file.');
      } else {
        toastr.error('Error loading PDF: ' + (error.message || 'Unknown error'));
      }

      throw error;
    });
  }

  // Main overlay PDF.js state
  let mainPdf = null;
  let mainPageNum = 1;
  let mainScale = 1.0;

  function renderMainPdfPage(pdf, pageNum, scale = 1.0) {
    return pdf.getPage(pageNum).then(function(page) {
      const canvas = document.getElementById('mainPdfCanvas');
      const ctx = canvas.getContext('2d');
      const viewport = page.getViewport({
        scale: scale
      });
      canvas.height = viewport.height;
      canvas.width = viewport.width;
      const renderContext = {
        canvasContext: ctx,
        viewport: viewport
      };
      return page.render(renderContext).promise.then(function() {
        document.getElementById('mainPageNum').textContent = pageNum;
        document.getElementById('mainZoomLevel').textContent = Math.round(scale * 100) + '%';
        document.getElementById('mainPrevPage').disabled = (pageNum <= 1);
        document.getElementById('mainNextPage').disabled = (pageNum >= pdf.numPages);
      });
    });
  }

  function loadMainPdfViewer(file) {
    if (!isPdfjsReady) {
      toastr.error('PDF viewer not ready. Please try again.');
      return Promise.reject('PDF.js not ready');
    }
    const fileUrl = URL.createObjectURL(file);
    return pdfjsLib.getDocument({
      url: fileUrl,
      verbosity: 1
    }).promise.then(function(pdf) {
      mainPdf = pdf;
      mainPageNum = 1;
      mainScale = 1.0;
      document.getElementById('mainPageCount').textContent = pdf.numPages;
      // Render the first page
      return renderMainPdfPage(pdf, mainPageNum, mainScale);
    }).catch(function(error) {
      toastr.error('Error loading PDF: ' + (error.message || 'Unknown error'));
      throw error;
    });
  }

  // PDF control event handlers
  document.getElementById('prevPage').addEventListener('click', function() {
    if (currentPdf && currentPageNum > 1) {
      currentPageNum--;
      renderPdfPage(currentPdf, currentPageNum, currentScale);
      this.disabled = (currentPageNum <= 1);
      document.getElementById('nextPage').disabled = false;
    }
  });

  document.getElementById('nextPage').addEventListener('click', function() {
    if (currentPdf && currentPageNum < currentPdf.numPages) {
      currentPageNum++;
      renderPdfPage(currentPdf, currentPageNum, currentScale);
      this.disabled = (currentPageNum >= currentPdf.numPages);
      document.getElementById('prevPage').disabled = false;
    }
  });

  document.getElementById('zoomIn').addEventListener('click', function() {
    if (currentPdf && currentScale < 3.0) {
      currentScale += 0.25;
      renderPdfPage(currentPdf, currentPageNum, currentScale);
    }
  });

  document.getElementById('zoomOut').addEventListener('click', function() {
    if (currentPdf && currentScale > 0.5) {
      currentScale -= 0.25;
      renderPdfPage(currentPdf, currentPageNum, currentScale);
    }
  });

  $('#mainPrevPage').on('click', function() {
    if (mainPdf && mainPageNum > 1) {
      mainPageNum--;
      renderMainPdfPage(mainPdf, mainPageNum, mainScale);
    }
  });
  $('#mainNextPage').on('click', function() {
    if (mainPdf && mainPageNum < mainPdf.numPages) {
      mainPageNum++;
      renderMainPdfPage(mainPdf, mainPageNum, mainScale);
    }
  });
  $('#mainZoomIn').on('click', function() {
    if (mainPdf && mainScale < 3.0) {
      mainScale += 0.25;
      renderMainPdfPage(mainPdf, mainPageNum, mainScale);
    }
  });
  $('#mainZoomOut').on('click', function() {
    if (mainPdf && mainScale > 0.5) {
      mainScale -= 0.25;
      renderMainPdfPage(mainPdf, mainPageNum, mainScale);
    }
  });

  function enableHardcopyRouteBtn() {
    $('#routeWithPrintedBtn').removeAttr('disabled');
  }

  // Print/download actions (on print or download click)
  $('#hala-route-print-btn').on('click', function() {
    const iframe = document.getElementById('halaRouteSheetIframe');
    if (iframe) {
      iframe.contentWindow.focus();
      iframe.contentWindow.print();
      hasPrintedOrDownloaded = true;
      enableHardcopyRouteBtn();
    }
  });

  $('#hala-route-download-btn').on('click', function() {
    const iframe = document.getElementById('halaRouteSheetIframe');
    if (iframe) {
      const src = iframe.getAttribute('src');
      if (src) {
        this.href = src.split('?')[0] + '?' + src.split('?')[1] + '&download=1';
        hasPrintedOrDownloaded = true;
        enableHardcopyRouteBtn();
      }
    }
  });
  $('#hCloseModal').on('click', function() {
    $('#confirmHardcopyModal').hide();
    $('.modal-backdrop').remove();
  });
  $('#cancelHardcopyRouteBtn').on('click', function() {
    $('#confirmHardcopyModal').hide();
    $('.modal-backdrop').remove();
  });

  // When route-with-printed is enabled and clicked, show confirm modal and finalize
  $('#routeWithPrintedBtn').off('click').on('click', function() {
    if (!hasPrintedOrDownloaded) {
      alert('Please print or download the route sheet first.');
      return;
    }
    // Use the global document ID for routing
    const documentId = window.currentRouteSheetDocumentId;
    if (!documentId) {
      alert('Cannot determine document ID for routing.');
      return;
    }
    // Store documentId on the confirm modal for later retrieval
    $('#confirmHardcopyModal').data('document_id', documentId);
    const modal = new bootstrap.Modal(document.getElementById('confirmHardcopyModal'));
    modal.show();
  });

  $('#confirmHardcopyRouteBtn').on('click', function() {
    // Retrieve the documentId stored in the modal
    const documentId = $('#confirmHardcopyModal').data('document_id');
    if (!documentId) {
      alert('Document ID missing. Please retry.');
      return;
    }
    fetch('../server-logic/user-operations/finalize_uploaded_route_sheet.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: `document_id=${encodeURIComponent(documentId)}`,
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alert('Document successfully routed via hardcopy.');
          window.location.href = '/DTS/pages/document_management.php';
        } else {
          alert('Failed to finalize. Please try again.');
        }
      })
      .catch(err => {
        console.error('Fetch error:', err);
        alert('An error occurred. Please try again.');
      });
  });
  // Overlay open handler for new route sheet hala.php
  function openRouteSheetOverlay(documentId) {
    // Hide all viewers, show only mainPdfViewerContainer
    hideAllViewers();
    $('#mainPdfViewerContainer').removeClass('d-none');
    $('.route-sheet-hala-overlay').removeClass('d-none').addClass('d-flex');
    // Store the current document ID globally for use in handlers
    window.currentRouteSheetDocumentId = documentId;
    // Fetch the PDF from hala.php as a blob
    const pdfUrl = '../pdf/hala.php?document_id=' + encodeURIComponent(documentId) + '&as_pdf=1';
    fetch(pdfUrl)
      .then(res => res.blob())
      .then(blob => {
        const file = new File([blob], 'route_sheet.pdf', {
          type: 'application/pdf'
        });
        return loadMainPdfViewer(file);
      })
      .catch(err => {
        toastr.error('Failed to load route sheet PDF.');
      });
  }

  // Hide all viewers utility (add mainPdfViewerContainer)
  function hideAllViewers() {
    document.getElementById('mainPdfViewerContainer').classList.add('d-none');
    document.getElementById('pdfViewerContainer').classList.add('d-none');
    document.getElementById('imageViewerContainer').classList.add('d-none');
    document.getElementById('docxViewerContainer').classList.add('d-none');
    document.getElementById('iframeViewerContainer').classList.add('d-none');
  }

  // On overlay close, clean up main PDF
  $('#route-hala-back-btn').on('click', function() {
    $('.route-sheet-hala-overlay').addClass('d-none').removeClass('d-flex');
    if (mainPdf) {
      mainPdf.destroy();
      mainPdf = null;
    }
    mainPageNum = 1;
    mainScale = 1.0;
    hideAllViewers();
  });
  // Confirm modal open handler for route-with-printed
  $('#routeWithPrintedBtn').on('click', function() {
    if (!hasPrintedOrDownloaded) {
      alert('Please print or download the route sheet first.');
      return;
    }

    const modal = new bootstrap.Modal(document.getElementById('confirmHardcopyModal'));
    modal.show();
  });

  // Initialize toastr notifications
  toastr.options = {
    "closeButton": true,
    "progressBar": true,
    "positionClass": "toast-top-right",
    "timeOut": "3000"
  };

  // On modal close
  $('#rCloseModal').on('click', function() {
    $('#uploadedRouteSheetReviewModal').modal('hide');
  });

  // Handle Cancel/Route in modal
  $('#uploadedRouteSheetCancelBtn').on('click', function() {
    const fileUrl = $('#uploadedRouteSheetReviewModal').data('file_url');
    const documentId = $('#uploadedRouteSheetReviewModal').data('document_id');
    $('#uploadedRouteSheetAnimation').show();
    // Call backend to remove the uploaded file
    fetch('../server-logic/user-operations/delete_uploaded_route_sheet.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: `file_url=${encodeURIComponent(fileUrl)}&document_id=${encodeURIComponent(documentId)}`
    }).then(resp => resp.json()).then(result => {
      setTimeout(function() {
        $('#uploadedRouteSheetReviewModal').modal('hide');

        toastr.info('Upload cancelled.');
      }, 800);
    });
  });

  $('#uploadedRouteSheetRouteBtn').on('click', function(e) {
    e.preventDefault();
    const $btn = $(this);
    const $spinnerContainer = $btn.find('.spinner-container');
    const $label = $btn.find('.button-text');
    // Retrieve the documentId from uploadPreview, not data('document-id')
    const previewData = $('#uploadedRouteSheetReviewModal').data('uploadPreview');
    const documentId = previewData ? previewData.document_id : null;

    if (!documentId) {
      toastr.error('Document ID is missing.');
      return;
    }
    // Show spinner and disable button
    $btn.prop('disabled', true);
    $label.hide();
    $spinnerContainer.show();

    fetch('../server-logic/user-operations/finalize_uploaded_route_sheet.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: `document_id=${encodeURIComponent(documentId)}` // Fixed template literal
      })
      .then(resp => {
        if (!resp.ok) throw new Error(`Server ${resp.status}`); // Fixed template literal
        return resp.json();
      })
      .then(result => {
        setTimeout(() => {
          toastr.success('Route sheet accepted and routed!');
          window.location.href = '/DTS/pages/document_management.php';

          // Reset button even if leaving page (good practice)
          $spinnerContainer.hide();
          $label.show();
          $btn.prop('disabled', false);
        }, 800);
      })
      .catch(err => {
        console.error('Routing failed:', err); // Better error logging
        toastr.error(`Failed to route document: ${err.message}`);

        // Immediate reset on error
        $spinnerContainer.hide();
        $label.show();
        $btn.prop('disabled', false);
      });
  });





  // Close modal with X button
  $('#uploadedRouteSheetReviewCancelBtn').on('click', function() {
    $('#uploadedRouteSheetCancelBtn').click();
  });
  // Append e-Sig & Route Handler
  $('#appendESigAndRouteBtn').off('click').on('click', function() {
    // Use the global variable set by openRouteSheetOverlay
    const documentId = window.currentRouteSheetDocumentId;
    if (!documentId) {
      toastr.error('Cannot determine document for e-sign.');
      return;
    }

    // 1. Check if current user has e-sig (AJAX)
    $.post('../server-logic/user-operations/check_sig_path.php', {}, function(resp) {
      if (resp.status !== 'success' || !resp.sig_path_exists) {
        toastr.error(resp.message || 'You do not have an e-signature on file. Please create one before proceeding.');
        return;
      }

      // 2. Set session pending doc and go to login if needed
      $.post('../server-logic/user-operations/set_route_pending_doc.php', {
        document_id: documentId
      }, function(setResp) {
        if (setResp.status === 'success') {
          $("#loginModal").appendTo('body').modal("show");

          $("#reviewModal").modal("hide");

          // Load the login modal fragment from your dedicated partial view


        } else {
          toastr.error('Failed to initiate e-sig routing, please try again.');
        }
      }, 'json');

    }, 'json');
  });

  // E-Signature Confirmation Modal Functions
  function showEsigConfirmModal(documentId) {
    $('#esigRouteSheetIframe').attr('src', '../pdf/hala.php?document_id=' + encodeURIComponent(documentId) + '&e_sig=1');
    $('#esigActionSpinner').hide();
    $('#esigConfirmModalBg').addClass('active');
  }

  function hideEsigConfirmModal() {
    $('#esigConfirmModalBg').removeClass('active');
    $('#esigRouteSheetIframe').attr('src', 'about:blank');
  }

  // E-Signature Modal Event Handlers
  $('#esigConfirmCloseBtn, #esigConfirmCancelBtn').off('click').on('click', function() {
    $('#esigActionSpinner').show();
    // Get document ID from iframe src
    const src = $('#esigRouteSheetIframe').attr('src');
    const urlParams = new URLSearchParams(src.split('?')[1]);
    const docId = urlParams.get('document_id');

    // Remove e-sig: AJAX to backend
    $.post('../server-logic/user-operations/remove_document_esig.php', {
      document_id: docId
    }, function(resp) {
      setTimeout(function() {
        $('#esigActionSpinner').hide();
        hideEsigConfirmModal();
        toastr.info('Your signature was removed from the route sheet.');
      }, 700);
    }, 'json');
  });

  $('#esigConfirmProceedBtn').off('click').on('click', function() {
    $('#esigActionSpinner').show();
    // Get document ID from iframe src
    const src = $('#esigRouteSheetIframe').attr('src');
    const urlParams = new URLSearchParams(src.split('?')[1]);
    const docId = urlParams.get('document_id');

    // Route doc: set doc status to active + update route table
    $.post('../server-logic/user-operations/accept_document_esig.php', {
      document_id: docId
    }, function(resp) {
      setTimeout(function() {
        $('#esigActionSpinner').hide();
        $('.esig-modal-close').hide();

        showEsigConfirmModal(docId);
        toastr.success('Document has been routed with your e-signature!');

        // Replace existing buttons with a close button
        // Replace the modal footer buttons
        $('.esig-modal-footer').html(
          '<button type="button" class="btn btn-primary closeBtn">' +
          '<i class="bi bi-x-circle"></i> Close' +
          '</button>'
        );

        // Bind click event to the new button
        $('.closeBtn').on('click', function() {
          // Ensure you target the modal's ID correctly;
          // Here I'm assuming your modal's id is "esigConfirmModal"
          hideEsigConfirmModal();
        });
      }, 700);
    }, 'json');

  });

  // Trigger on arrival from OTP redirect
  $(function() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('e_sig_ready') === '1' && params.get('document_id')) {
      // Show the modal with e-sig appended
      const docId = params.get('document_id');
      showEsigConfirmModal(docId);

      // Clean up URL to remove e_sig_ready param
      window.history.replaceState({}, document.title, window.location.pathname);
    }
  });

  // Upload & Preview handler for signed route sheet
  $('#uploadSignedRouteSheetBtn').off('click').on('click', function() {
    const fileInput = document.getElementById('signedRouteSheetFile');
    const file = fileInput.files && fileInput.files[0];
    if (!file) {
      toastr.error('Please select a file to upload.');
      return;
    }
    // Show the modal
    $('#uploadedRouteSheetReviewModal').modal('show');
    // Reset all viewers
    hideAllViewers();
    // Preview logic
    const ext = file.name.split('.').pop().toLowerCase();
    if (ext === 'pdf') {
      // PDF preview
      loadPdfViewer(file);
    } else if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(ext)) {
      // Image preview
      const reader = new FileReader();
      reader.onload = function(e) {
        document.getElementById('imageViewer').src = e.target.result;
        document.getElementById('imageViewerContainer').classList.remove('d-none');
      };
      reader.readAsDataURL(file);
    } else if (['doc', 'docx'].includes(ext)) {
      // DOCX preview (basic: just show filename)
      document.getElementById('docxViewerContainer').innerHTML = '<div class="text-center text-muted">DOC/DOCX preview not supported. File: ' + file.name + '</div>';
      document.getElementById('docxViewerContainer').classList.remove('d-none');
    } else {
      toastr.error('Unsupported file type.');
      $('#uploadedRouteSheetReviewModal').modal('hide');
    }
    // Store file and documentId for later use (e.g., routing)
    $('#uploadedRouteSheetReviewModal').data('uploadPreview', {
      file: file,
      document_id: window.currentRouteSheetDocumentId || null
    });
  });

  // Print/download actions for main overlay (PDF.js)
  $('#hala-route-print-btn').off('click').on('click', function() {
    if (!mainPdf) {
      toastr.error('No PDF loaded to print.');
      return;
    }
    // Render current page to a new window for printing
    const canvas = document.getElementById('mainPdfCanvas');
    const dataUrl = canvas.toDataURL('image/png');
    const printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Print Route Sheet</title></head><body style="margin:0;padding:0;text-align:center;background:#fff;">');
    printWindow.document.write('<img src="' + dataUrl + '" style="max-width:100%;height:auto;"/>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    printWindow.onload = function() {
      printWindow.print();
      printWindow.close();
    };
    hasPrintedOrDownloaded = true;
    enableHardcopyRouteBtn();
  });

  $('#hala-route-download-btn').off('click').on('click', function(e) {
    if (!mainPdf) {
      toastr.error('No PDF loaded to download.');
      e.preventDefault();
      return;
    }
    // Download the original PDF blob (from hala.php)
    const documentId = window.currentRouteSheetDocumentId;
    if (!documentId) {
      toastr.error('No document ID found for download.');
      e.preventDefault();
      return;
    }
    const pdfUrl = '../pdf/hala.php?document_id=' + encodeURIComponent(documentId) + '&as_pdf=1&download=1';
    this.href = pdfUrl;
    hasPrintedOrDownloaded = true;
    enableHardcopyRouteBtn();
  });
</script>
<style>
  .pdf-controls {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 10px;
    padding: 10px;
    background-color: #f8f9fa;
    border-radius: 5px;
    margin-bottom: 10px;
  }

  .pdf-canvas-container {
    background-color: #525659;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 400px;
  }

  #pdfCanvas {
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
    background-color: white;
  }

  @media (max-width: 768px) {
    .pdf-controls {
      flex-direction: column;
      gap: 5px;
    }

    .pdf-controls>div {
      display: flex;
      align-items: center;
    }
  }

  /* Enhanced viewer containers */
  #docxViewerContainer {
    text-align: left;
    line-height: 1.6;
  }

  #imageViewerContainer {
    background-color: #f8f9fa;
    padding: 20px;
    border-radius: 5px;
  }

  /* Mobile-specific tweak */
  @media (max-width: 576px) {
    #route-hala-back-btn {
      padding-left: 12px !important;
      padding-right: 12px !important;
    }

    .route-sheet-hala-overlay .border-bottom>span {
      font-size: 1rem !important;
      /* Slightly smaller on mobile */
    }
  }

  #uploadedRouteSheetRouteBtn {
    min-height: 38px;
    /* Match Bootstrap's button height */
    overflow: visible;
    /* Ensure spinner isn't clipped */
  }

  #uploadedRouteSheetRouteBtn .spinner-container {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  #uploadedRouteSheetRouteBtn .spinner-border {
    width: 1.2rem;
    height: 1.2rem;
    border-width: 0.15em;
  }
</style>