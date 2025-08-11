<?php
require_once __DIR__ . '/../server-logic/config/session_init.php';
require_once __DIR__ . '/../server-logic/config/require_login.php';

include '../layouts/header.php';
?>

<style>
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

    /* Mobile adjustments */
    @media (max-width: 768px) {
        .pagination .page-link {
            padding: 4px 10px;
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
            <div class="wrapper wrapper-content animated fadeInUp">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="ibox">
                            <div class="ibox-title">
                                <h5>Drafts</h5>
                            </div>
                            <div class="ibox-content">
                                <div class="m-b-sm m-t-sm">
                                    <div class="input-group">

                                        <input type="text" class="form-control form-control-sm" placeholder="Search documents...">

                                    </div>
                                </div>
                                <div class="project-list">
                                    <table class="table table-hover">
                                        <tbody>
                                            <!-- Drafts will be loaded here by JS -->
                                        </tbody>
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
    <script src="/DTS/server-logic/config/auto_logout.js"></script>
    <script>
        $(function() {
            let currentPage = 1;
            let lastSearch = '';

            function fetchDrafts(page = 1, search = '') {
                $.get('../server-logic/user-operations/fetch-drafts.php', {
                    page,
                    search
                }, function(res) {
                    renderDrafts(res.drafts, res.page, Math.ceil(res.total / res.perPage));
                }, 'json');
            }

            function renderDrafts(drafts, page, totalPages) {
                const $tbody = $('.project-list tbody').empty();
                if (!drafts.length) {
                    $tbody.append('<tr><td colspan="2" class="text-center">No drafts found.</td></tr>');
                } else {
                    drafts.forEach(draft => {
                        $tbody.append(`
                    <tr data-id="${draft.document_id}">
                        <td class="project-title">
                            <a href="#" class="edit-draft">${draft.subject}</a>
                            <br /><small>Updated ${draft.updated_at}</small>
                        </td>
                        <td class="project-actions">
                            <button class="btn btn-danger btn-sm my-2 delete-draft"><i class="fa fa-trash"></i> Delete</button>
                            <button class="btn btn-white btn-sm edit-draft"><i class="fa fa-pencil"></i> Edit</button>
                        </td>
                    </tr>
                `);
                    });
                }
                // Pagination
                let pagHtml = '';
                if (totalPages > 1) {
                    pagHtml += `<nav><ul class="pagination justify-content-center">`;
                    // First page arrow
                    if (page > 1) {
                        pagHtml += `<li class="page-item"><a class="page-link" href="#" data-page="1">&laquo;</a></li>`;
                    }
                    // Previous arrow
                    if (page > 1) {
                        pagHtml += `<li class="page-item"><a class="page-link" href="#" data-page="${page-1}">&lsaquo;</a></li>`;
                    }

                    // Calculate which page numbers to show (always show 3)
                    let startPage = page - 1;
                    if (startPage < 1) startPage = 1;
                    if (startPage > totalPages - 2) startPage = Math.max(1, totalPages - 2);

                    // Page numbers (always 3 or less)
                    for (let i = startPage; i < startPage + 3 && i <= totalPages; i++) {
                        pagHtml += `<li class="page-item${i===page?' active':''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
                    }

                    // Next arrow
                    if (page < totalPages) {
                        pagHtml += `<li class="page-item"><a class="page-link" href="#" data-page="${page+1}">&rsaquo;</a></li>`;
                    }
                    // Last page arrow
                    if (page < totalPages) {
                        pagHtml += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}">&raquo;</a></li>`;
                    }
                    pagHtml += `</ul></nav>`;
                }
                $('.project-list').next('nav').remove();
                $('.project-list').after(pagHtml);
            }

            // Initial load
            fetchDrafts();

            // Pagination click
            $(document).on('click', '.pagination .page-link', function(e) {
                e.preventDefault();
                const page = $(this).data('page');
                currentPage = page;
                fetchDrafts(page, lastSearch);
            });

            // Search
            $('.input-group .form-control').on('input', function() {
                lastSearch = $(this).val();
                fetchDrafts(1, lastSearch);
            });

            // Refresh button
            $('.input-group-prepend .btn').click(function() {
                fetchDrafts(currentPage, lastSearch);
            });

            // Edit draft
            $(document).on('click', '.edit-draft', function(e) {
                e.preventDefault();
                const docId = $(this).closest('tr').data('id');
                window.location.href = `document_management.php?draft_id=${docId}`;
            });

            // Delete draft
            $(document).on('click', '.delete-draft', function() {
                const $tr = $(this).closest('tr');
                const docId = $tr.data('id');
                if (confirm('Are you sure you want to delete this draft?')) {
                    $.post('../server-logic/user-operations/delete-draft.php', {
                        id: docId
                    }, function(res) {
                        if (res.success) {
                            // Animate row removal
                            $tr.css('background', '#f8d7da').fadeOut(500, function() {
                                $(this).remove();
                                toastr.success('Draft deleted successfully!');
                            });
                        } else {
                            toastr.error(res.message || 'Failed to delete draft.');
                        }
                    }, 'json');
                }
            });
        });
    </script>
</body>

</html>