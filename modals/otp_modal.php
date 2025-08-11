<?php
// otp_modal.php
// Set timezone and initialize session/environment
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../server-logic/config/session_init.php';

// Only run header checks when accessing the file directly (not when included)
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    if (!hash_equals(SessionManager::get('auth_csrf'), $_GET['auth_token'] ?? '')) {
        header('Location: /DTS/index.php?error=invalid_auth');
        exit;
    }
}

// Generate new CSRF token for OTP form
SessionManager::set('otp_csrf', bin2hex(random_bytes(32)));

$user = SessionManager::get('user');
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    if (empty($user) || empty($user['email'])) {
        error_log("NO VALID USER SESSION - REDIRECTING");
        error_log("Session dump: " . print_r([
            'session_id' => session_id(),
            'all_data' => SessionManager::get(null)
        ], true));
        header('Location: /DTS/index.php');
        exit;
    }
}
$email = $user['email'];

// Fetch OTP expiration time from DB (assumes $conn is available)
$stmt = $conn->prepare("SELECT otp_expiration FROM tbl_users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

$otp_expiration = isset($user['otp_expiration']) ? strtotime($user['otp_expiration']) : 0;
$current_time = time();
$time_remaining = max(0, $otp_expiration - $current_time);

// Image path and URL
$image_path = $_SERVER['DOCUMENT_ROOT'] . '/DTS/img/BJMP_logo.png';
$image_url = '/DTS/img/BJMP_logo.png';

error_log("Generating CSRF Token: " . SessionManager::get('otp_csrf'));
?>
<!-- Custom Styles for OTP Modal -->
<style>
    .modal-header {
        background-color: #2F4050;
        color: white;
    }

    .modal-header .close span,
    .modal-header .close {
        color: white;
        font-size: 1.5rem;
    }

    .modal-body {
        padding: 20px;
    }

    /* OTP Input styles */
    .otp-container {
        display: flex;
        justify-content: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .otp-input {
        width: 50px;
        height: 50px;
        text-align: center;
        font-size: 24px;
        border: 2px solid #ccc;
        border-radius: 5px;
        outline: none;
    }

    .otp-input:focus {
        border-color: #007bff;
        box-shadow: 0 0 5px rgba(0, 123, 255, 0.5);
    }

    @media (max-width: 768px) {
        .otp-input {
            width: 40px;
            height: 40px;
            font-size: 18px;
        }
    }

    @media (max-width: 576px) {
        .otp-input {
            width: 35px;
            height: 35px;
            font-size: 16px;
        }

        .otp-container {
            gap: 6px;
        }
    }
</style>

<!-- OTP Modal -->
<div class="modal fade" id="otpModal" data-backdrop="static" data-keyboard="false" tabindex="-1" aria-labelledby="otpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <!-- Modal Header with Title and Close -->
            <div class="modal-header">
                <!-- <h5 class="modal-title" id="otpModalLabel">OTP Verification</h5> -->
                <!-- Close button reloads current page without query parameters -->
                <a href="<?php echo strtok($_SERVER['REQUEST_URI'], '?'); ?>" class="close" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </a>
            </div>
            <!-- Modal Body with a two-column layout on large screens -->
            <div class="modal-body" style="background-color: #2F4050;">
                <div class="row">
                    <!-- Left Column: Logo and Instructions -->
                    <div class="col-12 col-md-6 text-center text-md-left">
                        <?php if (file_exists($image_path)): ?>
                            <img alt="logo" class="rounded-circle mb-4" src="<?php echo $image_url; ?>" style="width: 150px; height: 150px;">
                        <?php else: ?>
                            <p>Image not found. Please check the file path.</p>
                        <?php endif; ?>

                    </div>
                    <!-- Right Column: OTP Form -->
                    <div class="col-12 col-md-6">
                        <p>Please enter the 6-digit OTP sent to your email.</p>
                        <div class="ibox-content bg-white p-4 rounded shadow">
                            <form id="otpForm" class="m-t" role="form" method="POST" action="">
                                <input type="hidden" id="csrf_token" name="csrf_token" value="<?= SessionManager::get('otp_csrf') ?>">
                                <div class="form-group text-center">
                                    <div class="otp-container mb-4">
                                        <?php for ($i = 1; $i <= 6; $i++): ?>
                                            <input type="text" name="digit<?php echo $i; ?>" maxlength="1" class="otp-input form-control text-center" required>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <div id="loginError" class="mb-3" style="display: none;"></div>
                                <!-- Timer -->
                                <p id="countdown-timer" class="text-center">OTP expires in: <span id="timer"></span></p>
                                <button type="submit" class="btn btn-primary block full-width m-b">Verify OTP</button>
                                <p class="m-t text-center">
                                    <small>Didn't receive the OTP? <a href="#" id="resendOtp">Resend OTP</a></small>
                                </p>
                            </form>
                        </div>
                    </div>
                </div><!-- End row -->
            </div>
            <!-- Optional Modal Footer -->
            <div class="modal-footer  border-0" style="background-color: #2F4050;">
                <!-- Optional footer content can go here -->
            </div>
        </div>
    </div>
</div>

<?php
// If needed, include your footer here
// include '../layouts/footer.php';
?>

<script>
    $(document).ready(function() {
        const timeRemaining = <?php echo $time_remaining; ?>;
        let countdownTimer;

        function startCountdown(seconds) {
            const timerElement = $('#timer');
            countdownTimer = setInterval(() => {
                const minutes = Math.floor(seconds / 60);
                const remainingSeconds = seconds % 60;
                timerElement.text(`${minutes}:${remainingSeconds < 10 ? '0' : ''}${remainingSeconds}`);
                if (seconds <= 60) {
                    timerElement.css('color', 'red');
                } else {
                    timerElement.css('color', 'inherit');
                }
                if (seconds <= 0) {
                    clearInterval(countdownTimer);
                    timerElement.text('OTP has expired.');
                    timerElement.css('color', 'red');
                }
                seconds--;
            }, 1000);
        }

        if (timeRemaining > 0) {
            startCountdown(timeRemaining);
        } else {
            $('#timer').text('OTP has expired.');
            $('#timer').css('color', 'red');
        }

        // Auto-tab between OTP input fields
        $('.otp-input').on('input', function() {
            if ($(this).val().length === 1) {
                $(this).next('.otp-input').focus();
            }
        });
        $('.otp-input').on('keydown', function(e) {
            if (e.key === 'Backspace' && $(this).val().length === 0) {
                $(this).prev('.otp-input').focus();
            }
        });

        // Handle OTP verification
        $('#otpForm').on('submit', function(e) {
            e.preventDefault();
            const formData = {
                csrf_token: $('#csrf_token').val()
            };
            $('.otp-input').each(function(index) {
                formData['digit' + (index + 1)] = $(this).val();
            });
            $.ajax({
                url: '../server-logic/config/verify-otp.php',
                method: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        window.location.href = response.redirect;
                    } else {
                        if (response.require_new_otp) {
                            $('#resendOtp').click();
                        }
                        $('#loginError').text(response.message).show();
                    }
                },
                error: function(xhr) {
                    console.log("OTP AJAX Error: ", xhr);
                    const errorMsg = xhr.responseJSON?.message || "Verification failed. Please try again.";
                    $('#loginError').text(errorMsg).show();
                }
            });
        });

        // Handle OTP resend
        $('#resendOtp').on('click', function(e) {
            e.preventDefault();
            const $btn = $(this);
            $btn.prop('disabled', true);
            $btn.html('<span class="spinner-border spinner-border-sm" role="status"></span> Sending...');
            $.ajax({
                url: '/DTS/server-logic/config/send-otp.php',
                method: 'POST',
                data: {
                    action: 'resend_otp'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        const attemptsLeft = response.attempts_remaining;
                        $('#attempt-counter').text(`${attemptsLeft} ${attemptsLeft === 1 ? 'attempt' : 'attempts'} left`);
                        const $alert = $(`
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                ${response.message}
                            </div>
                        `);
                        $('#otpForm').prepend($alert);
                        setTimeout(() => $alert.alert('close'), 5000);
                        if (attemptsLeft <= 0) {
                            $btn.prop('disabled', true).text('No attempts left');
                        }
                    } else {
                        const $error = $(`
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <strong>Error:</strong> ${response.message}
                            </div>
                        `);
                        $('#otpForm').prepend($error);
                        if (response.message.includes('locked')) {
                            startLockoutTimer(3600);
                            $btn.prop('disabled', true).text('Account Locked');
                        }
                    }
                },
                error: function(xhr) {
                    let message = "Network error. Please try again.";
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        message = xhr.responseJSON.message;
                    }
                    $(`
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <strong>Error:</strong> ${message}
                        </div>
                    `).insertAfter('#otpForm');
                },
                complete: function() {
                    if (!$btn.is(':disabled')) {
                        $btn.prop('disabled', false).html('Resend OTP');
                    }
                }
            });
        });

        function startLockoutTimer(seconds) {
            const timer = $('#lockout-timer');
            timer.show();
            const interval = setInterval(() => {
                const mins = Math.floor(seconds / 60);
                const secs = seconds % 60;
                timer.text(`Lockout expires in ${mins}:${secs < 10 ? '0' : ''}${secs}`);
                if (seconds-- <= 0) {
                    clearInterval(interval);
                    timer.hide();
                    location.reload();
                }
            }, 1000);
        }
    });
</script>