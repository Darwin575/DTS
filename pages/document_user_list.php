<?php
require_once __DIR__ . '/../server-logic/config/db.php';
require_once __DIR__ . '/../server-logic/config/session_init.php';
require_once __DIR__ . '/../server-logic/config/require_login.php';

include '../layouts/header.php';
?>
<style>
    .sticky-tabs-container {
        position: -webkit-sticky;
        position: sticky;
        top: 60px;
        background: transparent;
        /* Changed from white */
        z-index: 1000;
        border-bottom: 1px solid #e7eaec;
        /* Match table border color */
    }

    .nav-tabs {
        /* overflow-x: auto; */
        flex-wrap: nowrap;
        border-bottom: none;
        margin-bottom: 0;
        background: transparent;
    }

    .nav-tabs .nav-item {
        margin-bottom: -1px;
        background: transparent;
    }

    .nav-tabs .nav-link {
        padding: 0.5rem 1rem;
        border: 1px solid #e7eaec;
        border-radius: 4px 4px 0 0;
        border-bottom: none;
        background: #f8f8f8;
        /* Subtle off-white for tabs */
        margin-right: 5px;
    }

    .nav-tabs .nav-link.active {
        background: #fff;
        border-color: #e7eaec;
        border-bottom-color: #fff !important;
        color: #1ab394;
    }

    .nav-tabs .nav-link:not(.active):hover {
        background: #f0f0f0;
    }

    /* Remove the white line pseudo-element */
    .nav-tabs .nav-link::after {
        content: none;
    }

    /* Table responsive fixes */
    .ibox-content {
        padding: 15px;
    }

    .table-responsive {
        margin-bottom: 0;
        border: none;
    }

    .custom-table {
        width: 100%;
        margin: 0;
        table-layout: fixed;
    }

    .custom-table th,
    .custom-table td {
        padding: 8px;
        word-wrap: break-word;
        vertical-align: middle;
    }

    /* Column widths for desktop */
    .custom-table th:nth-child(1),
    .custom-table td:nth-child(1) {
        width: 25%;
    }

    /* Document Title */
    .custom-table th:nth-child(2),
    .custom-table td:nth-child(2) {
        width: 15%;
    }

    /* Type */
    .custom-table th:nth-child(3),
    .custom-table td:nth-child(3) {
        width: 15%;
    }

    /* Uploaded By */
    .custom-table th:nth-child(4),
    .custom-table td:nth-child(4) {
        width: 15%;
    }

    /* Date Uploaded */
    .custom-table th:nth-child(5),
    .custom-table td:nth-child(5) {
        width: 15%;
    }

    /* Status */
    .custom-table th:nth-child(6),
    .custom-table td:nth-child(6) {
        width: 15%;
    }

    /* Action */

    /* Action column styles */
    .custom-table td:last-child {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Responsive styles */
    @media (max-width: 1024px) {

        .col-uploaded-by,
        .col-date-uploaded {
            display: none;
        }

        /* Adjust widths when columns are hidden */
        .custom-table th:nth-child(1) {
            width: 35%;
        }

        /* Document Title */
        .custom-table th:nth-child(2) {
            width: 25%;
        }

        /* Type */
        .custom-table th:nth-child(5) {
            width: 25%;
        }

        /* Status */
        .custom-table th:nth-child(6) {
            width: 15%;
        }

        /* Action */
    }

    /* Pagination fixes */
    .pagination-container {
        position: relative;
        margin-top: 15px;
        padding: 10px 0;
        background-color: #fff;
        border-top: 1px solid #e7eaec;
    }

    .pagination-wrapper {
        padding-right: 15px;
    }

    /* Update table container */
    .table-responsive {
        margin-bottom: 0;
        border: none;
    }

    /* Mobile adjustments */
    @media (max-width: 1024px) {

        /* When columns are hidden, adjust the visible columns */
        .footable th[data-breakpoints="none"],
        .footable td:not([data-hide]) {
            width: 25% !important;
            /* Equal width for the 4 visible columns */
        }

        .footable th[data-breakpoints="none"]:first-child,
        .footable td:first-child {
            width: 30% !important;
            /* Slightly wider for Document Title */
        }

        .footable th[data-breakpoints="none"]:last-child,
        .footable td:last-child {
            width: 15% !important;
            /* Slightly narrower for Action */
        }

        /* Reset min-widths in responsive view */
        .footable th:nth-child(1),
        .footable th:nth-child(2),
        .footable th:nth-child(5),
        .footable th:nth-child(6) {
            min-width: 0;
        }
    }

    @media (max-width: 768px) {
        .sticky-tabs-container {
            top: 50px;
        }

        .nav-tabs .nav-link {
            padding: 0.4rem 0.8rem;
            border-radius: 3px 3px 0 0;
        }

        .pagination-container {
            margin-top: 10px;
            padding: 5px 0;
        }
    }
</style>

<body>
    <div id="wrapper">
        <?php include '../layouts/sidebar.php'; ?>
        <div id="page-wrapper" class="gray-bg">
            <div class="row border-bottom">
                <?php include '../layouts/user_navbar_top.php'; ?>
            </div>
            <div class="row wrapper border-bottom page-heading"></div>
            <div class="wrapper wrapper-content animated fadeInRight document-list">

                <!-- Document Filter Form -->
                <form id="filterForm" method="get" class="ibox-content m-b-sm border-bottom">
                    <div class="row">
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label class="col-form-label" for="document_name">Document Title</label>
                                <input type="text" id="document_name" name="document_name" value="<?= htmlspecialchars($_GET['document_name'] ?? '') ?>" placeholder="Document Name" class="form-control">
                            </div>
                        </div>
                        <div class="col-sm-2">
                            <div class="form-group">
                                <label class="col-form-label" for="type">Type</label>
                                <input type="text" id="type" name="type" value="<?= htmlspecialchars($_GET['type'] ?? '') ?>" placeholder="Document Type" class="form-control">
                            </div>
                        </div>
                        <div class="col-sm-2">
                            <div class="form-group">
                                <label class="col-form-label" for="status">Status</label>
                                <select id="status" name="status" class="form-control">
                                    <option value="">All</option>
                                    <option value="Active">Active</option>
                                    <option value="Rejected">Rejected</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label class="col-form-label" for="uploaded_by">Uploaded By</label>
                                <input type="text" id="uploaded_by" name="uploaded_by" value="<?= htmlspecialchars($_GET['uploaded_by'] ?? '') ?>" placeholder="Uploader's Name" class="form-control">
                            </div>
                        </div>
                        <div class="col-sm-12 mt-2">
                            <a href="../server-logic/user-operations/document_user_list_data.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </div>
                </form>
                <!-- Sticky Tabs Container -->
                <div class="sticky-tabs-container">
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" data-tab="1" href="#my-docs">My Docs</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-tab="2" href="#routed-docs">Routed</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-tab="3" href="#archived-docs">Archived</a>
                        </li>
                    </ul>
                </div>

                <!-- Document Table -->
                <div class="row">
                    <div class="col-lg-12">
                        <div class="ibox">
                            <div class="ibox-content" style="border-top: none;">
                                <div class="table-responsive">
                                    <table class="custom-table table table-stripped">
                                        <thead>
                                            <tr>
                                                <th>Document Title</th>
                                                <th>Type</th>
                                                <th class="col-uploaded-by">Uploaded By</th>
                                                <th class="col-date-uploaded">Date Uploaded</th>
                                                <th>Status</th>
                                                <th class="text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="docTableBody"></tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="6">
                                                    <div class="pagination-container">
                                                        <div class="pagination-wrapper">
                                                            <ul class="pagination float-right"></ul>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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

    <?php include '../layouts/footer.php'; ?>
    <!-- FontAwesome 4 for chevron icons -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">
    <script src="/DTS/server-logic/config/auto_logout.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize variables
            let currentTab = 1;
            let currentPage = 1;
            const itemsPerPage = 15;
            let allDocuments = [];

            // Dynamically update status dropdown based on tab
            function updateStatusDropdown(tab) {
                const $status = $('#status');
                let val = $status.val();
                let options = '';
                if (tab == 3) {
                    options += '<option value="">All</option>';
                    options += '<option value="approved">Approved</option>';
                    options += '<option value="noted">Noted</option>';
                } else {
                    options += '<option value="">All</option>';
                    options += '<option value="active">Active</option>';
                    options += '<option value="rejected">Rejected</option>';
                }
                $status.html(options);
                // Restore previous value if still present
                if ($status.find('option[value="' + val + '"]').length) {
                    $status.val(val);
                } else {
                    $status.val('');
                }
            }

            function renderPagination(page, totalPages) {
                let html = '';
                // Double left arrow
                html += `<li class="page-item${page === 1 ? ' disabled' : ''}"><a class="page-link" data-page="1">&laquo;</a></li>`;
                // Single left arrow
                html += `<li class="page-item${page === 1 ? ' disabled' : ''}"><a class="page-link" data-page="${page - 1}">&lsaquo;</a></li>`;
                // Page numbers (always 3)
                let start = Math.max(1, Math.min(page - 1, Math.max(1, totalPages - 2)));
                let end = Math.min(totalPages, start + 2);
                for (let i = start; i <= end; i++) {
                    html += `<li class="page-item${i === page ? ' active' : ''}"><a class="page-link" data-page="${i}">${i}</a></li>`;
                }
                // Single right arrow
                html += `<li class="page-item${page === totalPages ? ' disabled' : ''}"><a class="page-link" data-page="${page + 1}">&rsaquo;</a></li>`;
                // Double right arrow
                html += `<li class="page-item${page === totalPages ? ' disabled' : ''}"><a class="page-link" data-page="${totalPages}">&raquo;</a></li>`;
                $('.pagination').html(html);
            }

            // Tab click handler
            $('.nav-tabs a').on('click', function(e) {
                e.preventDefault();
                $('.nav-tabs a').removeClass('active');
                $(this).addClass('active');
                currentTab = $(this).data('tab');
                updateStatusDropdown(currentTab);
                currentPage = 1; // Reset to first page when changing tabs
                loadDocuments();
            });

            // On page load, set correct status dropdown
            updateStatusDropdown(currentTab);

            // Function to format cells with proper classes
            function formatTableRow(doc, idx) {
                // Responsive toggle arrow for mobile/tablet using FontAwesome 4
                let toggleArrow = `<i class='fa fa-chevron-down toggle-details d-inline-block d-lg-none' data-row='${idx}' style='cursor:pointer;'></i>`;
                // Type column: show actions with separator
                let typeVal = (doc.actions && doc.actions !== '') ? doc.actions : (doc.type || '');
                // Action column: show View button
                let viewBtn = doc.document_id ? `<a href="document_view.php?doc_id=${encodeURIComponent(doc.document_id)}" class="btn btn-sm btn-outline-primary view-btn" title="View document">View</a>` : '';
                // Status badge (use display_status)
                let statusText = (doc.display_status || '').toLowerCase();
                let statusClass = 'badge-primary';
                if (statusText === 'approved') statusClass = 'badge-info';
                else if (statusText === 'noted') statusClass = 'badge-success';
                else if (statusText === 'rejected') statusClass = 'badge-danger';
                else if (statusText === 'archived') statusClass = 'badge-secondary';
                else if (statusText === 'active') statusClass = 'badge-primary';
                // Capitalize first letter for display
                let statusDisplay = statusText ? statusText.charAt(0).toUpperCase() + statusText.slice(1) : '';
                let statusBadge = `<span class="badge ${statusClass}">${statusDisplay}</span>`;
                // Debug output to console
                console.log('Document ID:', doc.document_id, 'Status:', statusText, 'Display:', statusDisplay);
                // Main row (add toggle-row class and data-row)
                let mainRow = `
                    <tr class="toggle-row" data-row="${idx}">
                        <td>${toggleArrow} ${doc.subject || doc.title || ''}</td>
                        <td>${typeVal}</td>
                        <td class="col-uploaded-by">${doc.uploaded_by || ''}</td>
                        <td class="col-date-uploaded">${doc.created_at || doc.updated_at || ''}</td>
                        <td>${statusBadge}</td>
                        <td class="text-right">${viewBtn}</td>
                    </tr>
                `;
                // Details row (hidden by default)
                let detailsRow = `
                    <tr class="row-details" id="row-details-${idx}" style="display:none;">
                        <td colspan="6">
                            <div><strong>Uploaded By:</strong> ${doc.uploaded_by || ''}</div>
                            <div><strong>Date Uploaded:</strong> ${doc.created_at || doc.updated_at || ''}</div>
                        </td>
                    </tr>
                `;
                return mainRow + detailsRow;
            }

            function renderTablePage(page) {
                let startIdx = (page - 1) * itemsPerPage;
                let endIdx = startIdx + itemsPerPage;
                let pageDocs = allDocuments.slice(startIdx, endIdx);
                let html = '';
                if (pageDocs.length === 0) {
                    html = '<tr><td colspan="6">No documents found</td></tr>';
                } else {
                    pageDocs.forEach((doc, idx) => {
                        html += formatTableRow(doc, startIdx + idx);
                    });
                }
                $('#docTableBody').html(html);
                renderPagination(page, Math.ceil(allDocuments.length / itemsPerPage));
            }

            function loadDocuments() {
                const $form = $('#filterForm');
                $.ajax({
                    url: '../server-logic/user-operations/document_user_list_data.php',
                    data: $form.serialize() + '&tab=' + currentTab,
                    method: 'GET',
                    success: function(data) {
                        if (typeof data === 'string') {
                            try {
                                data = JSON.parse(data);
                            } catch (e) {
                                if (data.includes('td>')) {
                                    $('#docTableBody').html(data);
                                    renderPagination(1, 1);
                                    return;
                                }
                                $('#docTableBody').html('<tr><td colspan="6">Error loading data</td></tr>');
                                return;
                            }
                        }
                        if (data.error) {
                            $('#docTableBody').html(`<tr><td colspan="6">Error: ${data.error}</td></tr>`);
                            return;
                        }
                        if (Array.isArray(data)) {
                            allDocuments = data;
                        } else {
                            allDocuments = [];
                        }
                        currentPage = 1;
                        renderTablePage(currentPage);
                    },
                    error: function() {
                        $('#docTableBody').html('<tr><td colspan="6">Failed to load documents</td></tr>');
                    }
                });
            }

            // Pagination click handler
            $(document).on('click', '.pagination .page-link', function(e) {
                e.preventDefault();
                const newPage = parseInt($(this).data('page'));
                const totalPages = Math.ceil(allDocuments.length / itemsPerPage);
                if (newPage >= 1 && newPage <= totalPages && newPage !== currentPage) {
                    currentPage = newPage;
                    renderTablePage(currentPage);
                }
            });

            // Toggle details for mobile/tablet
            $(document).on('click', 'tr.toggle-row td:not(.text-right)', function(e) {
                // Prevent toggle if clicking the view button
                if ($(e.target).closest('.view-btn').length) return;
                const $row = $(this).closest('tr.toggle-row');
                const idx = $row.data('row');
                const $icon = $row.find('.toggle-details');
                const $details = $(`#row-details-${idx}`);
                if ($details.is(':visible')) {
                    $details.slideUp(150);
                    $icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
                } else {
                    $details.slideDown(150);
                    $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
                }
            });

            // Filter handlers
            $('#filterForm input, #filterForm select').on('input change', loadDocuments);
            $('#filterForm .btn-secondary').on('click', function(e) {
                e.preventDefault();
                $('#filterForm')[0].reset();
                currentPage = 1;
                loadDocuments();
            });

            // Initial load
            loadDocuments();
        }); // End of document.ready
    </script>
</body>

</html>