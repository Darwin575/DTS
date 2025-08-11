<?php
require_once __DIR__ . '/../server-logic/config/session_init.php';

include '../layouts/header.php';
$user_role = SessionManager::get('user')['role'];
if ($user_role !== 'admin') {
    header('Location: /DTS/index.php');
    exit;
}
?>
<style>
    /* Table responsive styling */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        /* Smooth scrolling on iOS */
        margin-bottom: 20px;
        /* Space between table and pagination */
        border: 1px solid #e7eaec;
        /* Optional: adds border around table */
        border-radius: 4px;
        /* Optional: rounds table corners */
    }

    /* Pagination container styling */
    nav[aria-label="Page navigation"] {
        width: 100%;
        position: relative;
        z-index: 10;
        /* Ensures pagination stays above other elements */
    }

    /* Pagination styling */
    .pagination {
        margin: 15px 0;
        display: flex;
        justify-content: center;
    }

    .pagination .page-item {
        margin: 0 2px;
    }

    .pagination .page-link {
        padding: 6px 12px;
        color: #337ab7;
        background-color: #fff;
        border: 1px solid #ddd;
        border-radius: 3px;
        transition: all 0.2s ease;
    }

    .pagination .page-item.active .page-link {
        color: #fff;
        background-color: #337ab7;
        border-color: #337ab7;
    }

    .pagination .page-link:hover:not(.active) {
        background-color: #f5f5f5;
    }

    /* Arrow styling */
    .page-link span[aria-hidden="true"] {
        font-size: 1.2em;
        line-height: 1;
    }

    /* Mobile responsiveness */
    @media (max-width: 576px) {
        .page-link {
            padding: 0.4rem 0.6rem;
            font-size: 1rem;
        }

        .page-item {
            margin: 0 2px;
        }
    }

    /* Optional: Table styling enhancements */
    .table {
        margin-bottom: 0;
        /* Remove default table margin */
    }

    .table th {
        white-space: nowrap;
        /* Prevent header text wrapping */
        position: sticky;
        top: 0;
        background-color: #f8f9fa;
        z-index: 5;
    }
</style>

<body>

    <div id="wrapper">

        <?php

        include '../layouts/admin_sidebar.php';

        ?>

        <div id="page-wrapper" class="gray-bg">
            <div class="row border-bottom">
                <?php

                include '../layouts/admin_navbar_top.php';

                ?>
            </div>
            <div class="row wrapper border-bottom page-heading">

            </div>
            <div class="wrapper wrapper-content animated fadeInUp">
                <div class="row">
                    <div class="col-lg-12">

                        <div class="ibox">
                            <div class="ibox-title">
                                <h5>All Documents</h5>
                                <div class="ibox-tools">
                                </div>
                            </div>
                            <div class="ibox-content">
                                <div class="m-b-sm m-t-sm">

                                    <div class="input-group mb-3">
                                        <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search status, subject, actions, uploader" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                                        <div class="input-group-append">
                                            <button id="resetBtn" class="btn btn-secondary btn-sm" type="button">Reset</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="project-list">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th class="align-middle" style="width: 100px;">Status</th>
                                                    <th class="align-middle">Subject</th>
                                                    <th class="align-middle" style="width: 150px;">Actions</th>
                                                    <th class="align-middle" style="width: 120px;">Uploader</th>
                                                    <th class="align-middle" style="width: 100px;">Completion</th>
                                                    <th class="align-middle" style="width: 80px;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="docTableBody">
                                                <?php include '../server-logic/admin-operations/document_list_data.php'; ?>
                                            </tbody>
                                        </table>
                                        <!-- Pagination remains unchanged -->

                                    </div>

                                    <nav aria-label="Page navigation">
                                        <ul class="pagination justify-content-center" id="docTablePagination">
                                            <!-- Arrows will be inserted here by JavaScript -->
                                        </ul>
                                    </nav>
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

    <!-- Mainly scripts -->
    <?php

    include '../layouts/footer.php';

    ?>
    <script src="/DTS/server-logic/config/auto_logout.js"></script>

    <script>
        $(function() {
            function loadTable(page = 1) {
                $.get('../server-logic/admin-operations/document_list_data.php', {
                    search: $('#searchInput').val(),
                    page: page,
                    ajax: 1
                }, function(data) {
                    let parts = data.split('||PAGINATION||');
                    $('#docTableBody').html(parts[0]);

                    if (parts[1]) {
                        let pagedata = JSON.parse(parts[1]);
                        let html = '';

                        // Previous arrow (only show if not on first page)
                        // First page arrow
                        if (pagedata.page > 1) {
                            html += `<li class="page-item"><a class="page-link" href="#" data-page="1">&laquo;</a></li>`;
                        }

                        // Previous arrow
                        if (pagedata.page > 1) {
                            html += `<li class="page-item"><a class="page-link" href="#" data-page="${pagedata.page - 1}">&lsaquo;</a></li>`;
                        }

                        // Calculate which page numbers to show (always show 3)
                        let startPage = pagedata.page - 1;
                        if (startPage < 1) startPage = 1;
                        if (startPage > pagedata.totalPages - 2) startPage = Math.max(1, pagedata.totalPages - 2);

                        // Page numbers (always 3 or less)
                        for (let i = startPage; i < startPage + 3 && i <= pagedata.totalPages; i++) {
                            html += `<li class="page-item${i === pagedata.page ? ' active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
                        }

                        // Next arrow
                        if (pagedata.page < pagedata.totalPages) {
                            html += `<li class="page-item"><a class="page-link" href="#" data-page="${pagedata.page + 1}">&rsaquo;</a></li>`;
                        }

                        // Last page arrow
                        if (pagedata.page < pagedata.totalPages) {
                            html += `<li class="page-item"><a class="page-link" href="#" data-page="${pagedata.totalPages}">&raquo;</a></li>`;
                        }

                        $('#docTablePagination').html(html);
                    }
                });
            }

            // Keep all your existing event handlers
            $('#searchInput').on('input', function() {
                loadTable(1);
            });

            $('#resetBtn').on('click', function() {
                $('#searchInput').val('');
                loadTable(1);
            });

            $('#docTablePagination').on('click', '.page-link', function(e) {
                e.preventDefault();
                let page = $(this).data('page');
                loadTable(page);
            });

            // Initial load
            loadTable(1);
        });
    </script>
</body>

</html>