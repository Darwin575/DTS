<?php
require_once __DIR__ . '/../server-logic/config/session_init.php';
require_once __DIR__ . '/../server-logic/config/db.php';
require_once __DIR__ . '/../server-logic/document/document_helpers.php';
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

define('DTS_CRYPT_KEY', 'PutYourStrongSecretKeyHere01234'); // CHANGE THIS IN PRODUCTION!
define('DTS_CRYPT_METHOD', 'aes-256-cbc');

// Helper to encrypt
function encryptToken($plaintext)
{
  $key = DTS_CRYPT_KEY;
  $ivlen = openssl_cipher_iv_length(DTS_CRYPT_METHOD);
  $iv = openssl_random_pseudo_bytes($ivlen);
  $ciphertext = openssl_encrypt($plaintext, DTS_CRYPT_METHOD, $key, 0, $iv);
  return base64_encode($iv . $ciphertext); // Prefix IV so we can decode
}

// Helper to decrypt
function decryptToken($encrypted)
{
  $key = DTS_CRYPT_KEY;
  $encrypted = base64_decode($encrypted);
  $ivlen = openssl_cipher_iv_length(DTS_CRYPT_METHOD);
  $iv = substr($encrypted, 0, $ivlen);
  $ciphertext = substr($encrypted, $ivlen);
  return openssl_decrypt($ciphertext, DTS_CRYPT_METHOD, $key, 0, $iv);
}

// ===================================
// Auth & OTP Checks
// ===================================
if (empty($_SESSION['user']) || empty($_SESSION['otp_verified'])) {
  header("Location: /DTS/index.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
  exit();
}
$currentUserId = $_SESSION['user']['id'] ?? 0;
global $conn;

// ===================================
// Document Fetch Logic
// ===================================
$showInput = true;
$access_type = null;
$restrictedMsg = '';
$document = null;
$canAccess = false;
$document_id = null; // Initialize to null
$in_system = isset($_GET['receive_doc_id']) ? intval($_GET['receive_doc_id']) : '';
$qrToken = $_GET['token'] ?? '';
$embedCode = $_GET['embed_code'] ?? '';
if (!empty($in_system)) {
  $document_id = $in_system;
  if (!empty($document_id)) {

    $access_type = 'direct';
  }
} elseif (!empty($qrToken)) {
  $qrToken = trim($_GET['token'] ?? '');
  $stmt = $conn->prepare("SELECT document_id, qr_token FROM tbl_documents WHERE qr_token IS NOT NULL AND qr_token != ''");
  $stmt->execute();
  $result = $stmt->get_result();

  // Loop through all matching documents (if necessary)
  while ($row = $result->fetch_assoc()) {
    // Decrypt the stored token and trim it; assume decryptToken() returns a string
    $plainQrToken = trim(decryptToken($row['qr_token']));

    // Use hash_equals for a timing-attack–safe string comparison
    if (hash_equals($plainQrToken, $qrToken)) {
      $document_id = $row['document_id'];
      $access_type = 'qr';
      break;
    }
  }
  $stmt->close();
} elseif (!empty($embedCode)) {
  // Instead of encrypting input, fetch possible rows and decrypt each for comparison
  $stmt = $conn->prepare("SELECT document_id, embed_token FROM tbl_documents");
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    // Decrypt token from DB
    $embedCode = trim($_GET['embed_code'] ?? '');
    // And possibly ensure the decrypted token is trimmed, if appropriate:
    $plainEmbedToken = trim(decryptToken($row['embed_token']));
    if (hash_equals($plainEmbedToken, $embedCode)) {
      $document_id = $row['document_id'];
      $access_type = 'embed';
      break;
    }
  }
  $stmt->close(); // Close the statement
}

if (!empty($document_id)) {
  $document = get_document_by_id($conn, $document_id);

  $showInput = false;
  if ($document) {
    $active_route = get_user_document_route($conn, $document_id, $currentUserId);
    if ($active_route) {
      $canAccess = true;
    } else {
      $showInput = true;
    }
  }
}

$remarks = $document ? get_document_remarks($conn, $document['document_id']) : [];
$actions = $document ? get_document_actions($conn, $document['document_id']) : [];
$inSystem = isInSystem($conn, $document_id);
// Route position (for last recipient detection)
$is_last_recipient = false;
$route_id = null;
if ($canAccess && $document) {
  $active_route = get_user_document_route($conn, $document_id, $currentUserId);
  $route_id = $active_route['route_id'] ?? null;
  if ($route_id) {
    $pending_routes = get_pending_document_routes($conn, $document['document_id']);
    if ($pending_routes && count($pending_routes) == 1 && $pending_routes[0]['route_id'] == $route_id) {
      $is_last_recipient = true;
    }
  }
}
if (isset($_POST['clear_doc'])) {
  $showInput = true;
}

$you_rejected = $conn->prepare("SELECT status FROM tbl_document_routes
    WHERE document_id = ? AND to_user_id = ? ORDER BY route_id DESC LIMIT 2");
$you_rejected->bind_param('ii', $document_id, $currentUserId);
$you_rejected->execute();

$result = $you_rejected->get_result(); // Get the mysqli_result object

$is_second_highest_status_rejected = false; // Flag to indicate if the 2nd highest status is 'rejected'

if ($result->num_rows >= 2) {
  // If there are at least two rows, fetch them into an array
  $rows = $result->fetch_all(MYSQLI_ASSOC);

  // The result is ordered by route_id DESC, so $rows[1] is the second highest.
  $second_highest_route = $rows[1];

  // Now, explicitly check if the status of this second highest route is 'rejected'
  if (isset($second_highest_route['status']) && $second_highest_route['status'] === 'rejected') {
    $is_second_highest_status_rejected = true;
    echo "The second highest status is 'rejected'.";
  }
}

$you_rejected->close();

$canAct = hasRoutingOrEsig($conn, $document_id, $currentUserId);
include '../layouts/header.php';

// Determine if we should render the logging script
$shouldRenderLoggingScript = !empty($document) && !empty($document['document_id']) && !empty($access_type);

// 2) guard so we only log *once* in the session
$shouldLogAccess = $is_second_highest_status_rejected;
$logKey = 'log_access';

// Clear the session log flag here immediately to allow for repeated immediate access in testing/dev
// if ($shouldRenderLoggingScript) {
//   $_SESSION['log_access'] = false;
// }
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

<style>
  /* Remove outer wrapper spacing */
  #wrapper {
    margin: 0;
    padding: 0;
  }

  /* Hide the sidebar if not needed */
  #wrapper>.sidebar,
  #sidebar,
  .sidebar {
    display: none;
  }

  /* Force main container to be full width */
  #page-wrapper {
    margin: 0 !important;
    padding: 15px !important;
    width: 100% !important;
  }

  /* Remove bootstrap grid gutter spacing for rows and columns */
  .row,
  .col-lg-12 {
    margin-left: 0 !important;
    margin-right: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
  }

  /* Force the ibox and its children to take full width without extra spacing */
  .ibox,
  .ibox-title,
  .ibox-content {
    margin: 0 !important;
    padding: 0 !important;
    width: 100% !important;
  }

  /* Add internal padding for readability; adjust as desired */
  .ibox-title,
  .ibox-content {
    padding: 15px !important;
  }

  /* Remove any extra spacing in inner wrappers */
  .wrapper.wrapper-content {
    margin: 0 !important;
    padding: 0 !important;
  }

  /* Styles for expandable content */
  .expandable {
    overflow: hidden;
    position: relative;
    max-height: 100px;
    transition: max-height 0.3s ease-out;
  }

  .expandable.expanded {
    max-height: none;
  }

  .expandable:not(.expanded)::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 40px;
    background: linear-gradient(transparent, white);
    pointer-events: none;
  }

  .toggle-remark {
    padding: 0;
    color: #007bff;
    font-size: 0.9rem;
    margin-top: 5px;
  }

  .toggle-remark:hover {
    text-decoration: underline;
    color: #0056b3;
  }
</style>
</head>

<body>
  <div id="wrapper">
    <?php include '../layouts/sidebar.php'; ?>
    <div id="page-wrapper" class="gray-bg">
      <div class="row border-bottom">
        <?php include '../layouts/user_navbar_top.php'; ?>
      </div>
      <div class="row wrapper border-bottom page-heading">
        <div class="col-12">
          <h2 class="h5 mb-0">
            <?= $document && !$showInput ? 'Received Document' : 'Access Document' ?>
          </h2>
        </div>
      </div>
      <!-- Main content area -->
      <div class="wrapper wrapper-content animated fadeInRight">
        <div class="row">
          <div class="col-lg-12">
            <div class="ibox">
              <!-- Ibox title -->
              <div class="ibox-title">
                <?php if ($document && !$showInput && $canAccess): ?>
                  <form method="post" action="">
                    <button type="submit" name="clear_doc" class="btn btn-link text-danger p-0 my-2-">
                      Search another document
                    </button>
                  </form>
                <?php else: ?>
                  <h5>Enter Document Code</h5>
                <?php endif; ?>
              </div>
              <!-- Ibox content -->
              <div class="ibox-content">
                <?php if ($showInput): ?>
                  <form method="get" action="">
                    <div class="form-group row">
                      <div class="col-lg-8">
                        <input type="text"
                          name="embed_code"
                          class="form-control"
                          placeholder="Enter the Embedded Code"
                          pattern="[A-Za-z0-9]{8}"
                          title="8-character alphanumeric code"
                          required>
                      </div>
                      <div class="col-lg-2">
                        <button type="submit" class="btn btn-primary btn-block">
                          <i class="fa fa-search"></i> Find
                        </button>
                      </div>
                    </div>
                  </form>
                <?php elseif ($document && $canAccess): ?>
                  <h2><?= htmlspecialchars($document['subject'] ?? '') ?></h2>
                  <div class="document-meta mb-3">
                    <p class="mb-1"><strong>From:</strong> <?= htmlspecialchars($document['sender_office'] ?? 'Unknown') ?></p>
                    <p class="mb-1">
                      <strong>Urgency:</strong>
                      <span class="badge badge-<?= ($document['urgency'] ?? 'info') == 'high' ? 'danger' : (($document['urgency'] ?? 'info') == 'medium' ? 'warning' : 'info') ?>">
                        <?= ucfirst($document['urgency'] ?? 'info') ?>
                      </span>
                    </p>
                  </div>
                  <p><strong>Creator's Remark:</strong></p>
                  <div class="creator-remark mb-3">
                    <div class="remark-content expandable"><?= $document['remarks'] ?? '' ?></div>
                    <button class="btn btn-link toggle-remark d-none" onclick="toggleContent(this)">See more</button>
                  </div>
                  <?php include '../server-logic/document/_document_file_preview.php'; ?>
                  <?php if (!empty($actions)): ?>
                    <div class="mt-3">
                      <h6>Action Requests:</h6>
                      <ul class="list-group">
                        <?php foreach ($actions as $act): ?>
                          <li class="list-group-item">
                            <?= htmlspecialchars($act['action']) ?>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($remarks)): ?>
                    <div class="mt-3">
                      <h6 style="font-size:1.2rem">Remarks:</h6>
                      <ul class="list-group">
                        <?php foreach ($remarks as $rm): ?>
                          <li class="list-group-item" style="font-size:1.15rem">
                            <?php
                            $rname = trim($rm['name'] ?? '');
                            $roffice = trim($rm['office_name'] ?? '');
                            if ($rname && $roffice) {
                              echo "<strong>$rname ($roffice)</strong><br>";
                            } elseif ($rname) {
                              echo "<strong>$rname</strong><br>";
                            } elseif ($roffice) {
                              echo "<strong>$roffice</strong><br>";
                            }
                            ?>
                            <div class="remark-content expandable"><?= $rm['comments'] ?></div>
                            <button class="btn btn-link toggle-remark d-none" onclick="toggleContent(this)">See more</button>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  <?php endif; ?>
                  <?php if ($inSystem > 0): ?>
                    <div class="mt-4 mb-2 col-lg-12">
                      <button
                        type="button"
                        class="btn btn-info btn-block"
                        data-toggle="modal"
                        data-target="#routeSheetModal">
                        <i class="fa fa-map"></i> View Route Sheet
                      </button>
                    </div>

                  <?php endif; ?>
                  <?php if ($canAccess): ?>
                    <input type="hidden" id="global_route_id" value="<?= htmlspecialchars($route_id) ?>">
                    <input type="hidden" id="global_document_id" value="<?= htmlspecialchars($document['document_id']) ?>">
                    <!-- Recipient UI -->
                    <div class="mt-4 mb-2">
                      <label>Comment / Remark:</label>
                      <div id="summernote-comment"></div>
                    </div>
                    <?php if ($is_last_recipient): ?>
                      <div class="container mt-3">
                        <button
                          class="btn btn-success w-100 d-block my-4 action-btn"
                          data-action="approved"
                          <?= $canAct ? '' : 'disabled' ?>>
                          Approve
                        </button>

                        <button
                          class="btn btn-danger w-100 d-block mb-4 action-btn"
                          data-action="disapproved">

                          Disapprove
                        </button>

                        <button
                          class="btn btn-warning w-100 d-block action-btn mb-4"
                          data-action="noted"
                          <?= $canAct ? '' : 'disabled' ?>>
                          Noted
                        </button>
                      </div>
                    <?php else: ?>
                      <div class="container mt-3">
                        <button
                          class="btn btn-success w-100 d-block my-4 action-btn"
                          data-action="approved"
                          <?= $canAct ? '' : 'disabled' ?>>
                          Approve
                        </button>

                        <button
                          class="btn btn-danger w-100 d-block action-btn mb-4"
                          data-action="disapproved">
                          Disapprove
                        </button>
                      </div>
                    <?php endif; ?>

                  <?php endif; ?>
                <?php endif; ?>
              </div>
              <!-- End of ibox-content -->
            </div>
          </div>

        </div>

      </div>
      <script>
        document.addEventListener('DOMContentLoaded', function() {
          // Function to toggle content visibility
          window.toggleContent = function(button) {
            const container = button.previousElementSibling;
            const isExpanded = container.classList.contains('expanded');
            container.classList.toggle('expanded');
            button.textContent = isExpanded ? 'See more' : 'See less';
          };

          // Function to check content height and show/hide toggle button
          function checkContentHeight(container, button) {
            if (container.scrollHeight > 100) {
              button.classList.remove('d-none');
            }
          }

          // Find all expandable content containers and initialize them
          const containers = document.querySelectorAll('.expandable');
          containers.forEach(container => {
            const button = container.nextElementSibling;
            if (button && button.classList.contains('toggle-remark')) {
              checkContentHeight(container, button);
            }
          });
        });
      </script>

      <div class="footer">
        <div class="text-right">
          <a href="/DTS/asus.html">
            <small>
              Developed by <strong>Team BJMP Peeps </strong>
            </small>
          </a>
        </div>
      </div>

      <?php if ($inSystem > 0): ?>
        <!-- Route Sheet Modal -->
        <div class="modal fade" id="routeSheetModal" tabindex="-1" aria-labelledby="routeSheetModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-xl" style="max-width:90%;">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="routeSheetModalLabel">Routing Sheet</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                  <span aria-hidden="true">&times;</span>
                </button>
              </div>
              <div class="modal-body">
                <div class="d-flex justify-content-between mb-2 align-items-center">
                  <div>
                    <button type="button" class="btn btn-light" id="printRouteSheet"><i class="fa fa-print"></i> Print</button>
                    <button type="button" class="btn btn-success" id="downloadRouteSheet"><i class="fa fa-download"></i> Download</button>
                    <?php if ($inSystem == 1): ?>
                      <form id="uploadRouteSheetForm" class="d-inline-block" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <label class="btn btn-outline-primary mb-0" for="routeSheetUpload">
                          <i class="fa fa-upload"></i> Upload
                        </label>
                        <input type="file" name="routeSheetUpload" id="routeSheetUpload" accept=".pdf, .doc, .docx" class="form-control-file d-none" required>
                      </form>
                    <?php endif; ?>
                  </div>
                  <div>
                    <?php if ($inSystem == 2): ?>
                      <button type="button" class="btn btn-warning" id="appendEsigBtn"><i class="fa fa-pencil-square-o"></i> Append E-Sig</button>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- PDF.js Controls -->
                <div class="d-flex justify-content-between align-items-center mb-2" id="pdfControls" style="display: none !important;">
                  <div class="btn-group" role="group">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="prevPage">
                      <i class="fa fa-chevron-left"></i> Prev Page
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="nextPage">
                      Next Page <i class="fa fa-chevron-right"></i>
                    </button>
                  </div>
                  <div class="d-flex align-items-center">
                    <span class="mr-2">Page:</span>
                    <input type="number" id="pageNum" class="form-control form-control-sm" style="width: 60px;" min="1" value="1">
                    <span class="mx-2">/</span>
                    <span id="pageCount">0</span>
                  </div>
                  <div class="btn-group" role="group">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="zoomOut">
                      <i class="fa fa-minus"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="zoomIn">
                      <i class="fa fa-plus"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="zoomFit">
                      Fit
                    </button>
                  </div>
                </div>

                <!-- Navigation section for routing sheet (only when inSystem==1) -->
                <?php if ($inSystem == 1): ?>
                  <div class="d-flex justify-content-between align-items-center mb-2" id="routingNav">
                    <button type="button" class="btn btn-outline-secondary" id="prevRouteSheet">
                      <i class="fa fa-arrow-left"></i> Prev
                    </button>
                    <span id="currentRouteName" class="font-weight-bold">Loading...</span>
                    <button type="button" class="btn btn-outline-secondary" id="nextRouteSheet">
                      Next <i class="fa fa-arrow-right"></i>
                    </button>
                  </div>
                <?php endif; ?>

                <!-- PDF.js Canvas Container -->
                <div id="pdfContainer" style="max-height:65vh; overflow-y:auto; border:1px solid #dee2e6; text-align: center; background: #f5f5f5;">
                  <canvas id="pdfCanvas" style="border: 1px solid #ccc; margin: 10px auto; display: block;"></canvas>
                </div>

                <!-- Fallback for non-PDF files -->
                <div id="uploadPreviewArea" style="max-height:65vh; overflow-y:auto; border:1px solid #dee2e6; display: none;">
                  <iframe id="routeSheetFrame" frameborder="0" style="width:100%; height:80vh;"></iframe>
                </div>

                <!-- Loading indicator -->
                <div id="loadingIndicator" style="text-align: center; padding: 50px; display: none;">
                  <i class="fa fa-spinner fa-spin fa-2x"></i>
                  <p class="mt-2">Loading PDF...</p>
                </div>
              </div>
              <?php if ($inSystem == 1): ?>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-dismiss="modal" id="cancelUploadBtn">Cancel</button>
                  <button type="button" class="btn btn-primary" id="confirmUploadBtn">Confirm & Submit</button>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>


      <div class="modal fade" id="confirmAppendEsigModal" tabindex="-1" role="dialog" aria-labelledby="confirmAppendEsigModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="confirmAppendEsigModalLabel">Confirm E-Signature Append</h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
            <div class="modal-body">
              Are you sure you want to append your e-signature to your pending routing entry?
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary" id="confirmAppendEsigBtn">Yes, Append E-signature</button>
            </div>
          </div>

        </div>
      </div>
      <?php
      include __DIR__ . '/../modals/login_modal.php';
      ?>
      <?php
      include __DIR__ . '/../modals/otp_modal.php';
      ?>
      <?php include '../layouts/footer.php'; ?>
      <script src="/DTS/server-logic/config/auto_logout.js"></script>

    </div>
  </div>
  <script src="https://unpkg.com/mammoth/mammoth.browser.min.js"></script>

  <script>
    let pdfDoc = null;
    let pageNum = 1;
    let pageRendering = false;
    let pageNumPending = null;
    let scale = 1.2;
    const canvas = document.getElementById('pdfCanvas');
    const ctx = canvas.getContext('2d');

    // Configure PDF.js worker
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    /**
     * Get page info from document, resize canvas accordingly, and render page.
     * @param num Page number.
     */
    function renderPage(num) {
      pageRendering = true;
      // Using promise to fetch the page
      pdfDoc.getPage(num).then(function(page) {
        const viewport = page.getViewport({
          scale: scale
        });
        canvas.height = viewport.height;
        canvas.width = viewport.width;

        // Render PDF page into canvas context
        const renderContext = {
          canvasContext: ctx,
          viewport: viewport
        };
        const renderTask = page.render(renderContext);

        // Wait for rendering to finish
        renderTask.promise.then(function() {
          pageRendering = false;
          if (pageNumPending !== null) {
            // New page rendering is pending
            renderPage(pageNumPending);
            pageNumPending = null;
          }
        });
      });

      // Update page counters
      document.getElementById('pageNum').value = num;
    }

    /**
     * If another page rendering in progress, waits until the rendering is
     * finished. Otherwise, executes rendering immediately.
     */
    function queueRenderPage(num) {
      if (pageRendering) {
        pageNumPending = num;
      } else {
        renderPage(num);
      }
    }

    /**
     * Load and display PDF
     */
    function loadPDF(url) {
      // Show loading indicator
      document.getElementById('loadingIndicator').style.display = 'block';
      document.getElementById('pdfContainer').style.display = 'none';
      document.getElementById('pdfControls').style.display = 'none';
      document.getElementById('uploadPreviewArea').style.display = 'none';

      pdfjsLib.getDocument(url).promise.then(function(pdfDoc_) {
        pdfDoc = pdfDoc_;
        document.getElementById('pageCount').textContent = pdfDoc.numPages;

        // Hide loading, show PDF container and controls
        document.getElementById('loadingIndicator').style.display = 'none';
        document.getElementById('pdfContainer').style.display = 'block';
        document.getElementById('pdfControls').style.display = 'flex';

        // Initial/first page rendering
        renderPage(pageNum);
      }).catch(function(error) {
        console.error('Error loading PDF:', error);
        // Fallback to iframe for non-PDF or problematic files
        document.getElementById('loadingIndicator').style.display = 'none';
        document.getElementById('pdfContainer').style.display = 'none';
        document.getElementById('pdfControls').style.display = 'none';
        document.getElementById('uploadPreviewArea').style.display = 'block';
        document.getElementById('routeSheetFrame').src = url;
      });
    }

    // PDF.js Event Listeners
    document.getElementById('prevPage').addEventListener('click', function() {
      if (pageNum <= 1) {
        return;
      }
      pageNum--;
      queueRenderPage(pageNum);
    });

    document.getElementById('nextPage').addEventListener('click', function() {
      if (pageNum >= pdfDoc.numPages) {
        return;
      }
      pageNum++;
      queueRenderPage(pageNum);
    });

    document.getElementById('pageNum').addEventListener('change', function() {
      const num = parseInt(this.value);
      if (num > 0 && num <= pdfDoc.numPages) {
        pageNum = num;
        queueRenderPage(pageNum);
      } else {
        this.value = pageNum;
      }
    });

    document.getElementById('zoomIn').addEventListener('click', function() {
      scale += 0.2;
      queueRenderPage(pageNum);
    });

    document.getElementById('zoomOut').addEventListener('click', function() {
      if (scale > 0.4) {
        scale -= 0.2;
        queueRenderPage(pageNum);
      }
    });

    document.getElementById('zoomFit').addEventListener('click', function() {
      const container = document.getElementById('pdfContainer');
      const containerWidth = container.clientWidth - 40; // Account for padding

      if (pdfDoc) {
        pdfDoc.getPage(pageNum).then(function(page) {
          const viewport = page.getViewport({
            scale: 1
          });
          scale = containerWidth / viewport.width;
          queueRenderPage(pageNum);
        });
      }
    });
    // Function to toggle content expansion
    function toggleContent(button) {
      const container = button.previousElementSibling;
      const isExpanded = container.classList.contains('expanded');

      container.classList.toggle('expanded');
      button.textContent = isExpanded ? 'See more' : 'See less';
    }

    // Function to check content height and show/hide toggle button
    function checkContentHeight(container, button) {
      if (container.scrollHeight > 100) {
        button.classList.remove('d-none');
      }
    }

    // Initialize expandable contents after page load
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('.expandable').forEach(container => {
        const button = container.nextElementSibling;
        if (button && button.classList.contains('toggle-remark')) {
          checkContentHeight(container, button);
        }
      });
    });
  </script>

  <script>
    var routingPaths = [];
    var currentRouteIndex = 0;

    // Modified fetch function
    function fetchRouteSheetData(documentId) {
      return $.get('../server-logic/document/get_routing_sheet_url.php', {
        document_id: documentId
      }, null, 'json'); // Expect JSON response
    }

    $(function() {
      // Summernote setup (disable file/image upload)
      $('#summernote-comment').summernote({
        height: 150,
        toolbar: [
          ['style', ['bold', 'italic', 'underline', 'clear']],
          ['fontsize', ['fontsize']],
          ['color', ['color']],
          ['para', ['ul', 'ol', 'paragraph']],
          ['misc', ['undo', 'redo']],
          ['insert', ['link']],
          ['view', ['fullscreen']]
        ],
        callbacks: {
          onImageUpload: function(files) {
            // Don't allow image uploads
            alert("Image/file upload is disabled in comments.");
          },
          onFileUpload: function(files) {
            alert("File upload is disabled in comments.");
          },
          onInit: function() {
            // Set default empty value
            $(this).summernote('code', '');
          }
        }
      });

      $(function() {

        $('#routeSheetUpload').on('change', function() {
          const file = this.files && this.files[0];
          if (!file) return;

          // Limit file size to 10 MB (10 * 1,000,000 = 10,000,000 bytes)
          const MAX_FILE_SIZE = 10 * 1000000;
          if (file.size > MAX_FILE_SIZE) {
            toastr.error("The selected file is too large. Maximum allowed size is 10 MB.");
            return;
          }

          const name = file.name.toLowerCase();
          const ext = name.slice(name.lastIndexOf('.') + 1);
          const $frame = $('#routeSheetFrame');
          const $modal = $('#routeSheetModal');

          // Clear previous preview (in case user re-selects)
          $frame.replaceWith('<div id="routeSheetFrame" class="border" style="min-height:80vh;width:100%;"></div>');
          const $preview = $('#routeSheetFrame');

          if (ext === 'pdf') {
            // PDF → data-URL in iframe
            const reader = new FileReader();
            reader.onload = e => {
              $preview.replaceWith(
                $('<iframe>', {
                  id: 'routeSheetFrame',
                  src: e.target.result,
                  frameborder: 0,
                  class: 'border',
                  css: {
                    width: '100%',
                    'max-height': '70vh'
                  }
                })
              );
              $modal.modal('show');
            };
            reader.readAsDataURL(file);
          } else if (ext === 'docx' || ext === 'doc') {
            // Word → Mammoth → HTML
            const reader = new FileReader();
            reader.onload = e => {
              mammoth.convertToHtml({
                  arrayBuffer: e.target.result
                })
                .then(result => {
                  $preview.html(result.value);
                  $modal.modal('show');
                })
                .catch(err => {
                  console.error(err);
                  $preview.html('<div class="text-danger p-3">Could not convert this Word document.</div>');
                  $modal.modal('show');
                });
            };
            reader.readAsArrayBuffer(file);
          } else {
            $preview.html(`<div class="text-danger p-3">Unsupported file type: .${ext}</div>`);
            $modal.modal('show');
          }
        });



        // When the user clicks "Confirm & Submit", actually post the form:
        $('#confirmUploadBtn').on('click', function() {
          $('#uploadRouteSheetForm-action-form').submit();
        });

        // Optional: if they cancel, clear the file input
        $('#cancelUploadBtn').on('click', function() {
          $('#routeSheetUpload').val('');
        });
      });



      $('#cancelUploadBtn').on('click', function() {
        $('#uploadedFileModal').modal('hide');
        $('#routeSheetUpload').val('');
        $('#uploadPreviewArea').empty();
        $('#summernote-comment').summernote('reset');
      });

      // Change the confirmUploadBtn handler to ONLY handle file uploads
      $('#confirmUploadBtn').on('click', function(e) {
        var file = $('#routeSheetUpload')[0].files[0];
        const route_id = $('#global_route_id').val();
        const document_id = $('#global_document_id').val();

        // Prepare FormData (NO action parameter here)
        var formData = new FormData();
        if (file) formData.append('routeSheetUpload', file);
        formData.append('route_id', route_id);
        formData.append('document_id', document_id);

        $.ajax({
          url: '../server-logic/document/route_action_handler.php',
          type: 'POST',
          data: formData,
          processData: false,
          contentType: false,
          success: function(resp) {
            $('#routeSheetModal').modal('hide');
            alert('File uploaded successfully.');
            setTimeout(function() {
              window.location.reload();
            }, 700);

          },
          error: function(xhr) {
            alert('Upload failed: ' + xhr.responseText);
          }
        });
      });

      // Add this new handler for action buttons (approve/disapprove/noted)
      $('.action-btn').on('click', function(e) {
        e.preventDefault();
        const route_id = $('#global_route_id').val();
        const document_id = $('#global_document_id').val();
        const actionVal = $(this).data('action');

        if (!route_id || !document_id) {
          alert("Missing document information!");
          return;
        }

        const comment = $('#summernote-comment').summernote('code');

        // For empty comments, send empty string instead of <p><br></p>
        if (comment === '<p><br></p>') {
          comment = '';
        }

        const formData = new FormData();
        formData.append('comment', comment);
        formData.append('route_id', route_id);
        formData.append('document_id', document_id);
        formData.append('action', actionVal);


        $.ajax({
          url: '../server-logic/document/route_action_handler.php',
          type: 'POST',
          data: formData,
          processData: false,
          contentType: false,
          success: function(resp) {
            alert('Action processed successfully.');
            window.location.href = '/DTS/pages/receive_document.php';
          },
          error: function(xhr) {
            alert('Action failed: ' + xhr.responseText);
          }
        });
      });

      // Show route sheet in modal and set up navigation.
      $('#routeSheetModal').on('show.bs.modal', function() {
        var docId = <?= json_encode((int)($document['document_id'] ?? 0)) ?>;
        var $modal = $(this);

        // Reset state
        pdfDoc = null;
        pageNum = 1;
        scale = 1.2;

        // Clear previous routing data
        routingPaths = [];
        currentRouteIndex = 0;

        // Hide all containers initially
        $('#pdfContainer').hide();
        $('#pdfControls').hide();
        $('#uploadPreviewArea').hide();
        $('#loadingIndicator').hide();

        // Fetch the routing paths
        fetchRouteSheetData(docId)
          .done(function(response) {
            if (response.routing_paths && response.routing_paths.length > 0) {
              routingPaths = response.routing_paths;
              currentRouteIndex = 0;
              updateRouteSheetWithPDFJS();
            } else {
              // Fallback URL
              loadPDF('../pdf/hala.php?document_id=' + docId);
            }
          })
          .fail(function() {
            loadPDF('../pdf/hala.php?document_id=' + docId);
          });
      });

      // Step 5: Update the updateRouteSheet function
      function updateRouteSheetWithPDFJS() {
        if (routingPaths.length === 0) return;

        var currentRoute = routingPaths[currentRouteIndex];
        $('#currentRouteName').text(currentRoute.creator);

        // Reset page number for new document
        pageNum = 1;

        // Check if it's a PDF file
        if (currentRoute.routing_sheet_path.toLowerCase().endsWith('.pdf')) {
          loadPDF(currentRoute.routing_sheet_path);
        } else if (currentRoute.routing_sheet_path.toLowerCase().endsWith('.docx')) {
          // Handle DOCX files
          $('#pdfContainer').hide();
          $('#pdfControls').hide();
          $('#loadingIndicator').show();

          fetch(currentRoute.routing_sheet_path)
            .then(response => response.arrayBuffer())
            .then(arrayBuffer => mammoth.convertToHtml({
              arrayBuffer
            }))
            .then(result => {
              $('#loadingIndicator').hide();
              $('#uploadPreviewArea').show();
              $('#uploadPreviewArea').html('<div style="padding:20px; max-height:65vh; overflow:auto;">' + result.value + '</div>');
            })
            .catch(() => {
              $('#loadingIndicator').hide();
              $('#uploadPreviewArea').show();
              $('#routeSheetFrame')[0].src = currentRoute.routing_sheet_path;
            });
        } else {
          // For other file types, use iframe
          $('#pdfContainer').hide();
          $('#pdfControls').hide();
          $('#uploadPreviewArea').show();
          $('#routeSheetFrame')[0].src = currentRoute.routing_sheet_path;
        }
      }

      // Step 6: Update navigation buttons to use the new function
      $('#prevRouteSheet').off('click').on('click', function() {
        if (routingPaths.length === 0) return;
        if (currentRouteIndex > 0) {
          currentRouteIndex--;
          updateRouteSheetWithPDFJS();
        }
      });

      $('#nextRouteSheet').off('click').on('click', function() {
        if (routingPaths.length === 0) return;
        if (currentRouteIndex < routingPaths.length - 1) {
          currentRouteIndex++;
          updateRouteSheetWithPDFJS();
        }
      });

      // Step 7: Update print functionality for PDF.js
      $('#printRouteSheet').off('click').on('click', function() {
        if (pdfDoc) {
          // For PDF.js rendered content, we need to print the current PDF
          var printWindow = window.open('', '_blank');
          var currentRoute = routingPaths[currentRouteIndex];
          printWindow.location.href = currentRoute.routing_sheet_path;
        } else {
          // Fallback to iframe printing
          var frame = document.getElementById('routeSheetFrame');
          if (frame && frame.src) {
            frame.contentWindow.focus();
            frame.contentWindow.print();
          }
        }
      });

      // Step 8: Update download functionality
      $('#downloadRouteSheet').off('click').on('click', function() {
        var currentRoute = routingPaths[currentRouteIndex];
        if (currentRoute && currentRoute.routing_sheet_path) {
          window.open(currentRoute.routing_sheet_path, "_blank");
        }
      });

      // Attach click events to the Prev and Next buttons.
      $('#prevRouteSheet').on('click', function() {
        if (routingPaths.length === 0) return;
        // Go to previous route if it exists:
        if (currentRouteIndex > 0) {
          currentRouteIndex--;
          updateRouteSheet();
        }
      });
      $('#nextRouteSheet').on('click', function() {
        if (routingPaths.length === 0) return;
        // Go to next route if it exists:
        if (currentRouteIndex < routingPaths.length - 1) {
          currentRouteIndex++;
          updateRouteSheet();
        }
      });

      // (Existing code for handling docx preview logic remains below...)
      $('#routeSheetModal').on('show.bs.modal', function() {
        var docId = <?= json_encode((int)($document['document_id'] ?? 0)) ?>;
        var $modal = $(this);

        // Ensure the container exists
        if ($modal.find('#docxPreview').length === 0) {
          $modal.find('.modal-body').append('<div id="docxPreview" style="display:none; padding:20px; max-height:500px; overflow:auto; border:1px solid #ccc;"></div>');
        }

        var frame = $modal.find('#routeSheetFrame')[0];
        var previewDiv = $modal.find('#docxPreview')[0];

        frame.src = '';
        previewDiv.innerHTML = '';

        // For .docx handling in case the route sheet URL returns a docx file.
        fetchRouteSheetData(docId).done(function(response) {
          if (response.routing_paths && response.routing_paths.length > 0) {
            // Already handled above via updateRouteSheet() and navigation.
            // Additionally, if the current route file is a docx, adapt as needed:
            var currentRoute = routingPaths[currentRouteIndex];
            if (currentRoute.routing_sheet_path.endsWith('.docx')) {
              fetch(currentRoute.routing_sheet_path)
                .then(response => response.arrayBuffer())
                .then(arrayBuffer => mammoth.convertToHtml({
                  arrayBuffer
                }))
                .then(result => {
                  previewDiv.innerHTML = result.value;
                  frame.style.display = 'none';
                  previewDiv.style.display = 'block';
                })
                .catch(() => {
                  frame.src = '../pdf/hala.php?document_id=' + docId;
                  frame.style.display = 'block';
                  previewDiv.style.display = 'none';
                });
            } else {
              frame.style.display = 'block';
              previewDiv.style.display = 'none';
            }
          } else {
            frame.src = '../pdf/hala.php?document_id=' + docId;
          }
        }).fail(function() {
          frame.src = '../pdf/hala.php?document_id=' + docId;
        });
      });

      $('#printRouteSheet').on('click', function() {
        var frame = document.getElementById('routeSheetFrame');
        frame.contentWindow.focus();
        frame.contentWindow.print();
      });
      $('#downloadRouteSheet').on('click', function() {
        var frame = document.getElementById('routeSheetFrame');
        window.open(frame.src, "_blank");
      });





      $('#confirmAppendEsigModal').on('hidden.bs.modal', function() {
        // Remove any lingering modal-backdrop elements.
        $('.modal-backdrop').remove();
        // Also remove the modal-open class from body in case it persists.
        $('body').removeClass('modal-open');
      });


      $('#appendEsigBtn').on('click', function() {
        // Step 1: Check for e-signature presence using check_sig_path.php
        $.ajax({
          url: '../server-logic/user-operations/check_sig_path.php',
          method: 'GET',
          dataType: 'json',
          success: function(response) {
            if (response && response.sig_path_exists) {
              // Step 2: Find the most recent pending recipient route for this doc with current user
              $.ajax({
                url: '../server-logic/document/get_user_pending_route.php',
                method: 'GET',
                headers: {
                  'X-CSRF-Token': '<?= $csrf_token ?>'
                },
                data: {
                  document_id: $('#global_document_id').val()
                },
                dataType: 'json',
                success: function(routeRes) {
                  if (routeRes && routeRes.route_id) {
                    $('#routeSheetModal').modal('hide');
                    $('#routeSheetModal').on('hidden.bs.modal', function() {
                      $('#confirmAppendEsigModal').modal('show');
                      // remove the event listener to prevent multiple bindings
                      $('#routeSheetModal').off('hidden.bs.modal');
                    });
                    // Clear any previous click bindings from the confirm button to avoid duplicates
                    $('#confirmAppendEsigBtn').off('click').on('click', function() {
                      // On confirmation, redirect the user back to the index page with a parameter.
                      // After login and OTP verification, the app should append the e-signature.

                      $("#loginModal").appendTo('body').modal("show");
                      $("#confirmAppendEsigModal").modal("hide");
                    });
                  } else {
                    toastr.error('No pending routing entry for this document and current user.');
                  }
                },
                error: function() {
                  toastr.error('Error fetching pending route.');
                }
              });
            } else {
              // When no e-signature path exists: show a toastr message only.
              toastr.error('No e-signature found in your profile. Please upload your e-signature.');
            }
          },
          error: function() {
            toastr.error('Failed to verify e-signature.');
          }
        });
      });



      $(document).ready(function() {
        // Create a URLSearchParams instance to parse URL parameters.
        var urlParams = new URLSearchParams(window.location.search);
        var appendEsig = urlParams.get('append_esig');
        var documentId = urlParams.get('receive_doc_id');

        // Check if the URL has the append trigger and a valid document id.
        if (appendEsig === '1' && documentId) {
          // Optionally clear parameters to prevent double-processing on page reload.
          window.history.replaceState({}, document.title, window.location.pathname);

          // Step 1: Fetch the pending route entry for this document and the current user.
          $.ajax({
            url: '../server-logic/document/get_user_pending_route.php',
            method: 'GET',
            headers: {
              'X-CSRF-Token': '<?= $csrf_token ?>'
            },
            data: {
              document_id: documentId
            },
            dataType: 'json',
            success: function(routeRes) {
              if (routeRes && routeRes.route_id) {
                // Step 2: Append the signature by calling append_sig.php using the route ID.
                $.post('../server-logic/routes/append_sig.php', {
                  route_id: routeRes.route_id
                }, function(data) {
                  if (data.success) {
                    // setTimeout(function() {
                    //   window.location.reload();
                    // }, 700);
                    toastr.success('E-signature appended to your routing entry.');
                    // Optionally open the modal that displays the route sheet with the updated e-signature.
                    $('#routeSheetModal').modal('show');
                    $('#appendEsigBtn').hide();
                    $('#routeSheetModal').one('hidden.bs.modal', function() {
                      window.location.href = "receive_document.php?receive_doc_id=<?= $document_id ?>";
                    });

                  } else {
                    toastr.error('Failed to append e-signature: ' + data.message);
                  }
                }, 'json');
              } else {
                toastr.error('No pending routing entry for this document and current user.');
              }
            },
            error: function() {
              toastr.error('Error fetching pending route.');
            }
          });
        }
      });


    });
  </script>
  <?php if ($shouldRenderLoggingScript): ?>
    <script>
      // Ensure jQuery is loaded
      if (typeof window.jQuery === 'undefined') {
        var script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js';
        document.head.appendChild(script);
      }
    </script>
    <script>
      // Waiting for document ready and jQuery to be available
      function logDocAccessWhenReady() {
        if (!window.jQuery) {
          setTimeout(logDocAccessWhenReady, 50);
          return;
        }

        jQuery(function($) {
          var docId = <?= (int)$document['document_id'] ?>;
          var accessType = '<?= addslashes($access_type) ?>';
          var logUrl = '/DTS/server-logic/reports/log_document_access.php';

          // Use a unique key for this document access log in sessionStorage
          var logKey = 'doc_access_logged_' + docId + '_' + accessType;

          // If the flag is already set in sessionStorage, skip logging
          if (sessionStorage.getItem(logKey)) {
            console.log('[DOC_LOG] Access already logged in this session');
            return;
          }

          console.log('[DOC_LOG] Logging access:', {
            docId,
            accessType,
            url: logUrl
          });

          $.post(logUrl, {
              document_id: docId,
              access_type: accessType
            })
            .done(function(response) {
              console.log('[DOC_LOG] Log response:', response);
              if (typeof response === 'object' && response.status && response.status !== 'success') {
                console.error('[DOC_LOG] Logging Failed:', response.message || 'Unknown error');
              }
              // Set flag so that we won’t log it again during this session
              sessionStorage.setItem(logKey, 'true');
            })
            .fail(function(xhr) {
              var r = xhr.responseText;
              try {
                r = JSON.parse(r);
              } catch (e) {}
              console.error('[DOC_LOG] AJAX failed:', (r && r.message ? r.message : r));
            });
        });
      }

      <?php if ($shouldLogAccess): ?>
        // Execute logging if it's not recorded in this session
        logDocAccessWhenReady();
        <?php
        // Optionally, you might still set a server-side session flag here,
        // but note that every page reload will run its own PHP logic.
        $_SESSION[$logKey] = true;
        ?>
      <?php else: ?>
        console.log('[DOC_LOG] Access already logged in this session');
      <?php endif; ?>
    </script>
  <?php endif; ?>

  <style>
    button[disabled] {
      cursor: not-allowed;
      opacity: 0.65;
    }

    @media (max-width: 576px) {

      /* Target your specific modal by ID (or use .modal-dialog globally if you prefer) */
      #routeSheetModal .modal-dialog {
        margin: 0;
        /* remove the left/right gutters */
        max-width: 100% !important;
        width: 100% !important;
        max-height: 50%;
        /* force full-width */
      }

      #routeSheetModal .modal-content {
        border-radius: 0;
        /* optional: square-off the corners for true fullscreen look */
      }
    }
  </style>

</body>

</html>