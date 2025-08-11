<?php
require_once __DIR__ . '/../server-logic/config/db.php';
require_once __DIR__ . '/../server-logic/config/session_init.php';
require_once __DIR__ . '/../server-logic/config/require_login.php';


$user_id = SessionManager::get('user')['id'] ?? 0;
include '../layouts/header.php';

// Initialize variables to prevent undefined warnings
$file_path = '';
$file_type = '';
$file_size = '';


// Predefined actions
$predefined = [
    "APPROVAL / ENDORSEMENT",
    "APPROPRIATE ACTION",
    "COMMENT / RECOMMENDATION",
    "STUDY / INVESTIGATION",
    "REWRITE / REDRAFT",
    "REPLY DIRECT TO WRITER",
    "INFORMATION",
    "SEE ME / CALL ME",
    "DISPATCH",
    "FILE / REFERENCE",
    "PREPARE SPEECH MESSAGE",
    "SEE REMARKS"
];

$is_edit = false;
$draft = null;
$file_info = [
    'file_name' => '',
    'file_path' => '',
    'file_type' => '',
    'file_size' => ''
];
$actions = [];
$other_actions = [];

// Offices for routing
$offices = [];
$res = $conn->query("SELECT DISTINCT office_name FROM tbl_users WHERE role='user' AND office_name IS NOT NULL AND office_name != '' AND user_id != $user_id ORDER BY office_name");
while ($row = $res->fetch_assoc()) {
    $offices[] = $row['office_name'];
}

// Fetch draft if editing
$recipients = [];

$current_user_role = SessionManager::get('user')['role'];


$web_file_path = '';

if (isset($_GET['rejected_doc_id'])) {
    $_SESSION['rejected_doc_id'] = intval($_GET['rejected_doc_id']);
}
if (isset($_GET['rejected_doc_id']) && $current_user_role === 'user') {
    $reject_stmt = $conn->prepare("SELECT status, in_at FROM tbl_document_routes
        WHERE document_id = ? AND from_user_id = ? ORDER BY route_id DESC LIMIT 2");
    $reject_stmt->bind_param('ii', $_SESSION['rejected_doc_id'], $user_id);
    $reject_stmt->execute();

    $result = $reject_stmt->get_result(); // Get the mysqli_result object
    $rows = $result->fetch_all(MYSQLI_ASSOC); // Fetch all rows upfront

    // Flag to determine if redirection is needed. Assume no, unless conditions force it.
    $should_redirect = false;

    // Condition 1: If the document is a 'draft', immediately set for redirection.
    if ($document['status'] === 'draft') {
        $should_redirect = true;
    } else {
        // Condition 2: Check document routes only if not a 'draft'.
        if ($result->num_rows == 1) {
            $first_highest_route = $rows[0];
            // If there's one route and it's NOT 'rejected', then redirect.
            if (!isset($first_highest_route['status']) || $first_highest_route['status'] !== 'rejected') {
                $should_redirect = true;
                // echo "row 1 break";
            }
        } else if ($result->num_rows == 2) {
            $first_highest_route = $rows[0]; // Most recent route
            $second_highest_route = $rows[1]; // Previous route

            // If there are two routes, check if the second highest was NOT 'rejected'
            // OR if it was 'rejected' but the current route's 'in_at' is NOT NULL/empty.
            if (
                !isset($second_highest_route['status']) || $second_highest_route['status'] !== 'rejected' ||
                (isset($first_highest_route['in_at']) && ($first_highest_route['in_at'] !== NULL && $first_highest_route['in_at'] !== ''))
            ) {
                $should_redirect = true;
                // echo "row 2 break";
            }
        } else {
            // Condition 3: If num_rows is 0 (or anything other than 1 or 2), redirect.
            $should_redirect = true;
            // echo "else break";
        }
    }

    // Perform the redirection if the flag is true
    if ($should_redirect) {
        header("Location: document_management.php");
        exit();
    }
}

// $reject_stmt->close();

if (isset($_GET['draft_id']) || isset($_GET['rejected_doc_id'])) {
    if (isset($_GET['draft_id'])) {
        $id = intval($_GET['draft_id']);

        // Check ownership and draft status
        $stmt = $conn->prepare("SELECT * FROM tbl_documents WHERE document_id = ? AND user_id = ? AND status = 'draft'");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $draft = $result->fetch_assoc();
        $is_edit = !empty($draft);
    }

    if (isset($_GET['rejected_doc_id'])) {
        $id = intval($_GET['rejected_doc_id']);

        // Check if the user is the one who originally sent the document
        $stmt = $conn->prepare("
            SELECT d.* 
            FROM tbl_documents d
            INNER JOIN tbl_document_routes r ON d.document_id = r.document_id
            WHERE d.document_id = ? AND r.from_user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $draft = $result->fetch_assoc();
        $is_edit = !empty($draft);
    }

    if ($is_edit) {
        // Fetch document actions
        $stmt = $conn->prepare("SELECT action FROM tbl_document_actions WHERE document_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            if (in_array($row['action'], $predefined)) {
                $actions[] = $row['action'];
            } else {
                $other_actions[] = $row['action'];
            }
        }

        // File info
        $file_info = [
            'file_name' => $draft['file_name'] ?? '',
            'file_path' => $draft['file_path'] ?? '',
            'file_type' => $draft['file_type'] ?? '',
            'file_size' => $draft['file_size'] ?? ''
        ];

        if (!empty($file_info['file_path'])) {
            $file_basename = basename($file_info['file_path']);
            $web_file_path = '/DTS/documents/' . $file_basename;
        }

        // Fetch recipients (offices in route)
        $stmt = $conn->prepare("
            SELECT DISTINCT u.office_name
            FROM tbl_document_routes r
            INNER JOIN tbl_users u ON r.to_user_id = u.user_id
            WHERE r.document_id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $recipients[] = $row['office_name'];
        }
    }
}


?>
<script>
    <?php if ($draft && $file_info['file_path']): ?>
        const existingFilePath = $('#file_path').val();
        if (existingFilePath && !selectedFile) {
            // Determine file extension. In our example, we assume PDF can be previewed inline.
            let ext = existingFilePath.split('.').pop().toLowerCase();
            // Adjust file URL - add cache buster to always get latest version
            let webUrl = '/DTS/documents/' + existingFilePath.split('/').pop();
            if (ext === "pdf") {
                // Add cache buster
                webUrl += '?v=' + Date.now();
                $('#filePreviewContainer').html(
                    `<iframe src="${webUrl}" style="width:100%;min-height:400px;border:1px solid #ccd;"></iframe>`
                );
            } else if (ext === "docx") {
                $('#filePreviewContainer').html(
                    `<div id="docx-preview" style="border: 1px solid #ddd; border-radius: 8px; padding: 15px; min-height:400px; overflow-y: auto;">Loading preview...</div>`
                );
                // Fetch and process DOCX file using mammoth if needed:
                fetch(webUrl)
                    .then(response => response.blob())
                    .then(blob => {
                        const reader = new FileReader();
                        reader.onload = function(event) {
                            mammoth.convertToHtml({
                                    arrayBuffer: event.target.result
                                })
                                .then(result => {
                                    document.getElementById('docx-preview').innerHTML = result.value;
                                })
                                .catch(err => {
                                    document.getElementById('docx-preview').innerHTML = '<em>Error loading preview.</em>';
                                    console.error(err);
                                });
                        };
                        reader.readAsArrayBuffer(blob);
                    });
            }
            // Optionally add other file type handling...
        }
    <?php endif; ?>
</script>
<style>
    /* ALL form styles are tightly scoped. Theme/layout CSS remains untouched! */
    #modernUploadForm .safe-card {
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.07), 0 4px 16px rgba(0, 0, 0, 0.07);
        border-radius: 10px;
        border: none;
        margin-bottom: 30px;
        background: #fff;
        padding: 2rem 1rem 1.5rem 1rem;
        max-width: 950px;
        margin-left: auto;
        margin-right: auto;
    }

    @media (max-width: 700px) {
        #modernUploadForm .safe-card {
            padding: 1rem 3vw;
            max-width: 99vw;
        }
    }

    #modernUploadForm .safe-label {
        font-weight: 600;
        letter-spacing: 0.5px;
        font-size: 0.98rem;
        margin-bottom: 5px;
        color: #283145;
    }

    #modernUploadForm .safe-btn {
        display: inline-block;
        padding: 0.48em 1.6em;
        font-size: 1.02rem;
        font-weight: 500;
        line-height: 1.21;
        border: none;
        border-radius: 3px;
        transition: background 0.1s;
        margin-left: 0.25em;
        margin-right: 0.25em;
        margin-bottom: 4px;
        cursor: pointer;
    }

    #modernUploadForm .safe-btn-primary {
        background: #007bff;
        color: #fff;
    }

    #modernUploadForm .safe-btn-warning {
        background: #ffc107;
        color: #333;
    }

    #modernUploadForm .safe-btn-primary:hover,
    #modernUploadForm .safe-btn-warning:hover {
        filter: brightness(0.92);
    }

    #modernUploadForm .dropzone {
        border: 2px dashed #007bff;
        border-radius: 8px;
        background: #f8fbff;
        min-height: 110px;
        padding: 18px;
        margin-bottom: 0.6em;
    }

    #modernUploadForm .safe-row {
        display: flex;
        flex-wrap: wrap;
        margin-left: -10px;
        margin-right: -10px;
    }

    #modernUploadForm .safe-col-6 {
        flex: 1 1 300px;
        min-width: 240px;
        max-width: 50%;
        padding: 10px;
    }

    @media(max-width:600px) {
        #modernUploadForm .safe-col-6 {
            flex: 1 1 100% !important;
            max-width: 99% !important;
        }

        #modernUploadForm .safe-btn,
        #modernUploadForm button,
        #modernUploadForm input[type="button"],
        #modernUploadForm input[type="submit"] {
            font-size: 0.99rem !important;
            padding: 0.4em 1.1em !important;
        }
    }

    #modernUploadForm .list-group-safe {
        border-radius: 4px;
        min-height: 120px;
        background: #f8f8fa;
        padding: 0.3em 0;
        margin: 0;
        list-style: none;
        border: 1.5px solid #dedede;
    }

    #modernUploadForm .list-group-safe li {
        background: #fff;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        margin: 0.25em 0 0.25em 0;
        padding: 0.55em 1em;
        cursor: grab;
        font-size: 1.01rem;
        transition: background .18s;
        user-select: none;
    }

    #modernUploadForm .list-group-safe li:hover,
    #modernUploadForm .list-group-safe li:active {
        background: #e8f2ff;
    }

    #modernUploadForm #user-feedback {
        margin-top: 1.3rem;
    }

    /* Improved Dropzone Styles */
    #modernUploadForm .dropzone {
        border: 2.5px dashed #0066cc;
        border-radius: 10px;
        background: #f0f6fb;
        min-height: 130px;
        padding: 22px;
        margin-bottom: 0.7em;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        cursor: pointer;
        transition: border-color .2s;
        position: relative;
        overflow: hidden;
    }

    #modernUploadForm .dropzone:hover {
        border-color: #285eb8;
        background: #eaf3fa;
    }

    #filePreviewContainer {
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 15px;
        background: #fcfcfc;
        margin-bottom: 1.5em;
    }

    .preview-filename {
        font-weight: 500;
        color: #333;
        padding: 5px 0;
        border-bottom: 1px solid #eee;
        margin-bottom: 10px;
    }

    #docx-text-preview {
        max-height: 380px;
        overflow-y: auto;
        padding: 10px;
        border: 1px solid #eee;
        background: white;
    }

    .dropzone__icon {
        font-size: 2.1em;
        color: #007bff;
        margin-bottom: 7px;
    }

    .dropzone__text {
        font-size: 1.05em;
        color: #557aaf;
        margin-bottom: 2px;
        text-align: center;
    }

    .dropzone__hint {
        font-size: 0.95em;
        color: #999;
        text-align: center;
    }



    .safe-card .note-editor {
        border-radius: 7px;
    }

    .safe-card .note-toolbar {
        background: #f8fbff;
        border-radius: 7px 7px 0 0;
    }

    @media (max-width: 700px) {
        #modernUploadForm .dropzone {
            padding: 12px;
            min-height: 80px;
        }
    }

    .form-section-title {
        padding: 8px 0 6px;
        font-weight: 600;
        color: #334060;
        letter-spacing: .04em;
        margin-bottom: .4em;
        border-bottom: 1px solid #eee;
    }

    .action-checkbox.form-check {
        margin-bottom: .6em;
        min-height: 2.2em;
    }

    .action-checkbox .form-check-input {
        margin-top: 0.25em;
    }

    .safe-col-6,
    .col-md-6,
    .col-md-4,
    .col-lg-10 {
        padding-bottom: 1rem;
    }

    .list-group-safe {
        min-height: 120px;
        font-size: 1.025em;
        background: #fff;
        border: 1.5px solid #dedede;
        border-radius: 4px;
        padding: 0.3em 0;
        margin-bottom: 0;
        list-style: none;
    }

    .list-group-safe li {
        background: #f6fafd;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        margin: 0.24em 0;
        padding: 0.48em 1em;
        cursor: grab;
        user-select: none;
        transition: background .18s;
    }

    .list-group-safe li:hover,
    .list-group-safe li:active {
        background: #e8f2ff;
    }


    .route-section-label {
        font-weight: 500;
        color: #365bad;
        margin-bottom: .2em;
    }

    .bootstrap-input .form-control,
    .bootstrap-input .input-group,
    .bootstrap-input input {
        margin-bottom: 0.5rem;
    }

    #remarks {
        display: none;
    }

    /* ensure the editor floats on top in fullscreen mode */
    .note-editor.fullscreen {
        position: fixed !important;
        z-index: 9999 !important;
        top: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 100% !important;
    }

    .preview-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .fullscreen-btn {
        padding: 0;
        border: none;
        background: transparent;
        color: #007bff;
        /* Adjust to your preferred color */
        font-size: 18px;
        /* Adjust icon size as needed */
        cursor: pointer;
    }
</style>

<div id="wrapper">
    <?php include '../layouts/sidebar.php'; ?>
    <div id="page-wrapper" class="gray-bg">
        <div class="row border-bottom">
            <?php include '../layouts/user_navbar_top.php'; ?>
        </div>
        <div class="container-fluid px-1 ">
            <div class="row justify-content-center">
                <div id="modernUploadForm" class="col-12 mt-2 mb-4">
                    <div class="safe-card">
                        <h3 class="card-title mb-4"><?= $is_edit ? 'Edit & Route Document' : 'Upload and Route Document' ?></h3>
                        <form id="docRouteForm" autocomplete="off">
                            <input type="hidden" name="draft_id" value="<?= htmlspecialchars($draft['document_id'] ?? ($_GET['draft_id'] ?? '')) ?>">
                            <!-- Subject -->
                            <div class="form-group bootstrap-input">
                                <label for="subject" class="safe-label">Subject <span class="text-danger">*</span></label>
                                <input type="text" name="subject" id="subject" class="form-control" required value="<?= htmlspecialchars($draft['subject'] ?? '') ?>" placeholder="Enter document subject">
                            </div>





                            <div class="form-group">
                                <label class="safe-label">Document File <span class="text-danger">*</span></label>
                                <div class="btn-group btn-group-toggle d-flex mb-1" data-toggle="buttons">
                                    <label class="btn btn-outline-info active flex-fill text-center">
                                        <input type="radio" name="fileOption" id="withFile" value="with" autocomplete="off" checked> with
                                    </label>
                                    <label class="btn btn-outline-warning flex-fill text-center">
                                        <input type="radio" name="fileOption" id="withoutFile" value="without" autocomplete="off"> without
                                    </label>
                                </div>





                                <!-- <div id="prev-uploaded-file" style="display: <?= !empty($file_info['file_name']) ? 'block' : 'none'; ?>">

                                </div> -->
                                <!-- Stylized Dropzone Area -->
                                <div id="fileDropArea" class="safe-dropzone dropzone">
                                    <div class="dropzone__icon"><i class="fa fa-cloud-upload"></i></div>
                                    <div id="dropText" class="dropzone__text">Drag &amp; drop a file here, or click to select</div>
                                    <div class="dropzone__hint">(PDF, DOC, DOCX. Max: 50MB)</div>
                                    <input type="file" name="file" id="fileInput" accept=".pdf,.doc,.docx" style="display:none;">
                                </div>
                                <div id="filePreviewContainer" class="mt-3 preview-pane"></div>
                                <?php if ($draft && !empty($web_file_path)): ?>
                                    <input type="hidden" name="uploaded_file" id="uploaded_file" value="<?= htmlspecialchars($file_info['file_name'] ?? '') ?>">
                                    <input type="hidden" name="uploaded_file_size" id="uploaded_file_size" value="<?= htmlspecialchars($file_info['file_size'] ?? '') ?>">
                                    <input type="hidden" name="file_path" id="file_path" value="<?= htmlspecialchars($web_file_path) ?>">
                                    <input type="hidden" name="file_type" id="file_type" value="<?= htmlspecialchars($file_info['file_type'] ?? '') ?>">
                                    <input type="hidden" name="file_size" id="file_size" value="<?= htmlspecialchars($file_info['file_size'] ?? '') ?>">
                                <?php endif; ?>
                            </div>



                            <div class="form-group">
                                <div class="form-section-title">Action Requests</div>
                                <div class="row">
                                    <?php foreach ($predefined as $i => $action) : ?>
                                        <div class="col-md-4">
                                            <div class="form-check action-checkbox">
                                                <input class="form-check-input" type="checkbox" name="actions[]" value="<?= htmlspecialchars($action) ?>"
                                                    id="action-<?= $i ?>" <?= (in_array($action, $actions) ? 'checked' : '') ?>>
                                                <label class="form-check-label" for="action-<?= $i ?>"><?= htmlspecialchars($action) ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="col-12 col-md-8 mt-2">
                                        <input type="text" name="other_action" class="form-control form-control-sm" placeholder="Other action (optional)" value="<?= htmlspecialchars($other_actions[0] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group mb-3">
                                <label for="urgency" class="safe-label">Urgency Level <span class="text-danger">*</span></label>
                                <select id="urgency" name="urgency" class="form-control" required>
                                    <option value="low" <?= (isset($draft['urgency']) && $draft['urgency'] == 'low') ? 'selected' : '' ?>>Low</option>
                                    <option value="medium" <?= (isset($draft['urgency']) && $draft['urgency'] == 'medium') ? 'selected' : '' ?>>Medium</option>
                                    <option value="high" <?= (isset($draft['urgency']) && $draft['urgency'] == 'high') ? 'selected' : '' ?>>High</option>
                                </select>
                            </div>
                            <!-- Remarks (Summernote) -->
                            <div class="form-group mb-3">
                                <label for="remarks" class="safe-label">Comments/Remarks</label>
                                <textarea id="remarks" name="remarks" class="summernote"><?= htmlspecialchars_decode($draft['remarks'] ?? '') ?></textarea>
                            </div>
                            <!-- Route to Offices (Bootstrap grid, DnD, and clickable!) -->
                            <div class="form-group">
                                <div class="form-section-title">Route to Offices <span class="text-danger">*</span>
                                    <small style="color:#888;">Drag <b>or CLICK</b> to move. Order matters.</small>
                                </div>
                                <div class="row">
                                    <!-- Available Offices -->
                                    <div class="col-md-6 mb-2">
                                        <div class="route-section-label">Available Offices</div>
                                        <input type="text" class="form-control form-control-sm mb-2" id="searchAvailable" placeholder="Search available...">
                                        <ul id="availableOffices" class="list-group-safe">
                                            <?php foreach ($offices as $office): ?>
                                                <li class="list-group-item" data-value="<?= htmlspecialchars($office) ?>">
                                                    <?= htmlspecialchars($office) ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <!-- Recipients -->
                                    <div class="col-md-6 mb-2">
                                        <div class="route-section-label">Recipients</div>
                                        <input type="text" class="form-control form-control-sm mb-2" id="searchRecipients" placeholder="Search recipients...">
                                        <ul id="recipientOffices" class="list-group-safe"></ul>
                                    </div>
                                </div>
                            </div>
                            <!-- Buttons -->
                            <div class="form-group mt-2 d-flex flex-wrap justify-content-end align-items-center">
                                <button type="button" class="safe-btn safe-btn-warning mr-2" id="btnSaveDraft">
                                    <i class="fa fa-save"></i> Save as Draft
                                </button>
                                <button type="submit" class="safe-btn safe-btn-primary" id="btnFinalizeRoute">
                                    <i class="fa fa-paper-plane"></i> Review & Route
                                </button>
                            </div>
                            <div id="user-feedback"></div>
                        </form>
                    </div>
                </div><!--col-->
            </div><!--row-->
        </div><!--container-fluid-->
        <div class="footer">
            <div class="text-right">
                <a href="/DTS/asus.html">
                    <small>
                        Developed by <strong>Team BJMP Peeps </strong>
                    </small>
                </a>
            </div>
        </div>

    </div>
</div>
<?php include __DIR__ . '/../modals/esig_confirm_modal.php'; ?>
<?php
include __DIR__ . '/../modals/login_modal.php';
?>
<?php
include __DIR__ . '/../modals/otp_modal.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.4.0/mammoth.browser.min.js"></script>
<!-- JS dependencies -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<?php include __DIR__ . '/../modals/review_document_modal.php'; ?>



<!-- <script src="/DTS/server-logic/config/auto_logout.js"></script> -->
<?php include '../layouts/footer.php'; ?>

<script>
    var preselectedRecipients = <?= json_encode($recipients ?? []) ?>;
</script>

<script type="module" src="../assets/js/document_management.js"></script>