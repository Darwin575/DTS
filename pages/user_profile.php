<?php
include '../layouts/header.php';
require_once __DIR__ . '/../server-logic/config/db.php';
require_once __DIR__ . '/../server-logic/config/session_init.php';
require_once __DIR__ . '/../server-logic/config/require_login.php';

require_once __DIR__ . '/../server-logic/user-operations/user_activity_data.php';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$userId = SessionManager::get('user')['id'] ?? 0;
$activities = getActivityFeed($conn, $userId);
$stmt = $conn->prepare("SELECT name, email, office_name, profile_picture_path, esig_path FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    $user = [
        'name' => '',
        'email' => '',
        'office_name' => '',
        'profile_picture_path' => '../uploads/profile/default_profile_pic.jpg',
        'esig_path' => ''
    ];
}

?>
<style>
    #signature-pad {
        width: 100% !important;
        max-width: 100vw;
        height: 220px;
        max-height: 60vh;
        touch-action: none;
    }

    @media (max-width: 600px) {
        #signature-pad {
            height: 40vw;
            min-height: 180px;
        }

        .modal-dialog {
            margin: 0;
            max-width: 100vw;
            width: 100vw;
        }
    }



    #signature-pad {
        width: 100% !important;
        max-width: 800px;
        height: 300px !important;
        max-height: 60vh;
        touch-action: none;
        display: block;
        margin: 0 auto;
    }

    @media (max-width: 900px) {
        #signature-pad {
            width: 98vw !important;
            max-width: 98vw !important;
            height: 220px !important;
        }

        .modal-dialog {
            max-width: 100vw !important;
            width: 100vw !important;
        }
    }

    /* Prevent email overflow on mobile */
    address strong {
        word-break: break-all;
        display: inline-block;
        max-width: 100%;
    }
</style>

<body>

    <div id="wrapper">

        <?php

        include '../layouts/sidebar.php';

        ?>

        <div id="page-wrapper" class="gray-bg">
            <div class="row border-bottom">
                <?php

                include '../layouts/user_navbar_top.php';

                ?>
            </div>
            <div class="row wrapper border-bottom  page-heading">

            </div>
            <div class="wrapper wrapper-content animated fadeInRight">
                <div class="col-lg-12">
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="contact-box">
                                <div class="row">
                                    <div class="col-4">
                                        <div class="text-center">
                                            <img alt="image" class="rounded-circle m-t-xs img-fluid" style="width:80px;height:80px;object-fit:cover"
                                                src="<?= $user['profile_picture_path'] ? htmlspecialchars($user['profile_picture_path']) : '../uploads/profile/default_profile_pic.jpg' ?>">
                                            <div class="m-t-xs font-bold"><?= htmlspecialchars($user['office_name']) ?></div>
                                        </div>
                                    </div>
                                    <div class="col-8">
                                        <h3><strong><?= htmlspecialchars($user['name']) ?></strong></h3>
                                        <address>
                                            <strong><?= htmlspecialchars($user['email']) ?></strong><br>
                                        </address>
                                    </div>
                                </div>
                            </div>

                            <div class="ibox ">
                                <div class="ibox-title">
                                    <h5>Update profile information</h5>
                                </div>
                                <div class="ibox-content">
                                    <form id="profileForm" method="POST" enctype="multipart/form-data">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <div class="form-group row">
                                            <label class="col-lg-2 col-form-label">Name</label>
                                            <div class="col-lg-10">
                                                <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" class="form-control">
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-lg-4 col-form-label">Change profile photo</label>
                                            <div class="custom-file col-lg-8">
                                                <input id="inputGroupFile01" name="profile_picture" type="file" class="custom-file-input" accept="image/*">
                                                <label class="custom-file-label" for="inputGroupFile01">Choose file</label>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-lg-2 col-form-label">Email</label>
                                            <div class="col-lg-10">
                                                <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" class="form-control">
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-lg-2 col-form-label">Password</label>
                                            <div class="col-lg-10">
                                                <input type="password" name="password" placeholder="Password" class="form-control">
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <button class="btn btn-primary btn-success btn-sm" type="submit">Save changes</button>
                                        </div>
                                    </form>
                                    <div class="form-group row">
                                        <button class="btn btn-warning btn-block btn-sm" id="initEsigBtn">Initialize E-Signature</button>
                                    </div>
                                    <?php if ($user['esig_path']): ?>
                                        <div class="form-group row">
                                            <label class="col-lg-4 col-form-label">Current E-Signature:</label>
                                            <div class="col-lg-8">
                                                <img src="<?= htmlspecialchars($user['esig_path']) ?>" alt="E-Signature" style="max-width: 100%; height: 80px;">
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>


                        <div class="col-lg-6">
                            <div class="ibox">
                                <div class="ibox-title">
                                    <h5>Activity log</h5>
                                </div>
                                <div class="ibox-content overflow-auto h-30 h-md-50 vh-100 vh-md-50">
                                    <?php foreach ($activities as $act):
                                        $dt       = new \DateTime($act['timestamp']);
                                        $friendly = formatFriendlyDate($dt);
                                        // map badge → bootstrap label class
                                        $classes  = [
                                            'Change'   => 'primary',
                                            'Receive'  => 'success',
                                            'Approved' => 'success',
                                            'Rejected' => 'danger',
                                            'Comment'  => 'warning',
                                            'Create'   => 'info',
                                            'Update'   => 'default',
                                        ];
                                        $cls = $classes[$act['badge']] ?? 'default';
                                    ?>
                                        <div class="stream-small">
                                            <span class="label label-<?= $cls ?>"><?= $act['badge'] ?></span>
                                            <span class="text-muted"><?= $friendly ?></span>
                                            / <?= $act['message'] ?>
                                        </div>
                                    <?php endforeach; ?>
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

    <!-- Signature Pad Modal -->
    <div class="modal fade" id="esigModal" tabindex="-1" role="dialog" aria-labelledby="esigModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document" style="max-width:900px;width:98vw;">
            <div class="modal-content">
                <form id="esigForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="esigModalLabel">Create E-Signature</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <!-- Only the pad is horizontally scrollable -->
                    <div id="signature-scroll-container" style="overflow-x:auto; width:100%; padding: 0 0 10px 0;">
                        <div style="width:100%; padding: 0 0 10px 0;">
                            <canvas id="signature-pad" style="border:1px solid #ccc; width:100%; max-width:800px; height:300px; touch-action: none; display:block; margin:0 auto;"></canvas>
                        </div>
                    </div>
                    <div class="modal-body text-center" style="padding-top:0;">
                        <button type="button" class="btn btn-secondary btn-sm mt-2" id="clearEsig">Clear</button>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Save E-Signature</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/../modals/login_modal.php';
    ?>
    <?php
    include __DIR__ . '/../modals/otp_modal.php';
    ?>

    <!-- Mainly scripts -->
    <?php

    include '../layouts/footer.php';

    ?>
    <script src="/DTS/server-logic/config/auto_logout.js"></script>

    <!-- iCheck -->
    <script>
        $(document).ready(function() {
            $(document).on('change', '#inputGroupFile01', function(e) {
                // Check if a file was selected.
                if (this.files && this.files[0]) {
                    const file = this.files[0];
                    // Define 15 MB limit in bytes (15 * 1000000 = 15,000,000 bytes)
                    const MAX_PROFILE_SIZE = 15 * 1000000;

                    // If the file size exceeds the limit, display an error and clear the input.
                    if (file.size > MAX_PROFILE_SIZE) {
                        toastr.error("The selected profile picture is too large. Maximum allowed size is 15 MB.");
                        $(this).val(''); // Clear the input
                        // Also reset the file label text if needed.
                        $(this).next('.custom-file-label').text("Choose file");
                    }
                }
            });

            $('#profileForm').on('submit', function(e) {
                e.preventDefault();
                var formData = new FormData(this);
                formData.append('csrf_token', '<?= $csrf_token ?>');
                $.ajax({
                    url: '../server-logic/user-operations/update_profile.php',
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            swal({
                                title: "Updated successfully!",
                                type: "success"
                            });
                            setTimeout(() => location.reload(), 1200);
                        } else {
                            swal({
                                title: "Error",
                                text: response.message,
                                type: "error"
                            });
                        }
                    }
                });
            });

            $('.demo2').click(function(event) {
                event.preventDefault(); // Prevent form submission
                swal({
                    title: "Updated successfully!",
                    text: "",
                    type: "success"
                });
            });
            $(document).on('change', '.custom-file-input', function(e) {
                var fileName = e.target.files[0]?.name || 'Choose photo';
                $(this).next('.custom-file-label').text(fileName);
            });
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.6/dist/signature_pad.umd.min.js"></script>
    <script>
        let signaturePad;

        function resizeSignaturePad() {
            const canvas = document.getElementById('signature-pad');
            let width = Math.min(window.innerWidth * 0.98, 800);
            let height = window.innerWidth < 900 ? 220 : 300;
            canvas.width = width;
            canvas.height = height;
            canvas.style.width = width + 'px';
            canvas.style.height = height + 'px';
            if (signaturePad) signaturePad.clear();
        }

        $('#initEsigBtn').on('click', function() {
            $('#esigModal').modal('show');
            setTimeout(() => {
                resizeSignaturePad();
                signaturePad = new SignaturePad(document.getElementById('signature-pad'), {
                    minWidth: 1,
                    maxWidth: 2
                });
            }, 300);
        });

        $('#esigModal').on('shown.bs.modal', function() {
            resizeSignaturePad();
            if (signaturePad) signaturePad.clear();
        });

        $('#clearEsig').on('click', function() {
            if (signaturePad) signaturePad.clear();
        });
        $('#esigForm').on('submit', function(e) {
            e.preventDefault();
            if (signaturePad.isEmpty()) {
                alert('Please provide a signature.');
                return;
            }
            $.post(
                '../server-logic/user-operations/init_esig_save.php', {
                    esig: signaturePad.toDataURL('image/png'),
                    csrf_token: '<?= $csrf_token ?>'
                },
                function(response) {
                    if (response.status === 'redirect') {
                        window.location.href = response.redirect;
                    } else if (response.status === 'modal') {
                        $("#esigModal").modal("hide");
                        $("#loginModal").appendTo('body').modal("show");
                    } else {
                        alert('Failed to start e-signature save.');
                    }
                },
                'json'
            );

        });

        // Add this function to fetch and update the e-signature image
        function refreshEsigImage() {
            $.get('../server-logic/user-operations/get_esig_path.php', function(response) {
                if (response.status === 'success' && response.esig_path) {
                    $('img[alt="E-Signature"]').attr('src', response.esig_path + '?t=' + Date.now());
                }
            }, 'json');
        }
    </script>

</body>

</html>
<?php if (!empty($_SESSION['esig_saved'])): ?>
    <?php unset($_SESSION['esig_saved']); ?>
    <script>
        $(document).ready(function() {
            swal({
                title: "E-signature saved successfully!",
                type: "success"
            }, function() {
                console.log("SweetAlert closed, calling refreshEsigImage()");
                refreshEsigImage();
            });
        });
    </script>

<?php endif; ?>