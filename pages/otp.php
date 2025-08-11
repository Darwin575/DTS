<?php
// Set timezone
date_default_timezone_set('Asia/Manila');

// Initialize session and environment
require_once __DIR__ . '/../server-logic/config/session_init.php';

// Verify incoming auth token
if (!hash_equals(SessionManager::get('auth_csrf'), $_GET['auth_token'] ?? '')) {
    header('Location: /DTS/index.php?error=invalid_auth');
    exit;
}
// Generate new CSRF token for OTP form
SessionManager::set('otp_csrf', bin2hex(random_bytes(32)));

$user = SessionManager::get('user');
if (empty($user) || empty($user['email'])) {
    error_log("NO VALID USER SESSION - REDIRECTING");
    error_log("Session dump: " . print_r([
        'session_id' => session_id(),
        'all_data' => SessionManager::get(null)
    ], true));
    header('Location: /DTS/index.php');
    exit;
}
$email = $user['email'];

// Include the header file and DB
include '../layouts/header.php';
include '../server-logic/config/db.php';

// Fetch OTP expiration time from DB
$stmt = $conn->prepare("SELECT otp_expiration FROM tbl_users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

$otp_expiration = isset($user['otp_expiration']) ? strtotime($user['otp_expiration']) : 0;
$current_time = time();
$time_remaining = max(0, $otp_expiration - $current_time);

// Image path
$image_path = $_SERVER['DOCUMENT_ROOT'] . '/DTS/img/BJMP_logo.png';
$image_url = '/DTS/img/BJMP_logo.png';

// In your form generation script (before showing OTP form)
error_log("Generating CSRF Token: " . SessionManager::get('otp_csrf'));
?>

<body style="background-color: #2F4050;">
    <div class="page-wrapper" style="min-height: 100vh;">
        <div class="container">
            <div class="text-center mt-5">
                <?php if (file_exists($image_path)): ?>
                    <img alt="logo" class="rounded-circle mb-4" src="<?php echo $image_url; ?>" style="width: 150px; height: 150px;">
                <?php else: ?>
                    <p>Image not found. Please check the file path.</p>
                <?php endif; ?>
                <p>Please enter the 6-digit OTP sent to your email.</p>
            </div>
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="ibox-content bg-white p-4 rounded shadow">
                        <form id="otpForm" class="m-t" role="form" method="POST" action="">
                            <input type="hidden" id="csrf_token" name="csrf_token" value="<?= SessionManager::get('otp_csrf') ?>">
                            <div class="form-group text-center">
                                <div class="otp-container d-flex flex-wrap justify-content-center gap-3 mb-4">
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
            </div>
        </div>
    </div>
    <!-- Custom Styles -->
    <style>
        .page-wrapper {
            min-height: 100vh;
            background-color: #2F4050;
        }

        .otp-container {
            display: flex;
            justify-content: center;
            gap: 10px;
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
                flex-wrap: wrap;
                gap: 6px;
            }
        }
    </style>
    <?php include '../layouts/footer.php'; ?>
    <script src="/DTS/server-logic/config/auto_logout.js"></script>
    <script>
        $(document).ready(function() {
            const timeRemaining = <?php echo $time_remaining; ?>;
            let countdownTimer;

            function startCountdown(seconds) {
                const timerElement = $('#timer');
                countdownTimer = setInterval(() => {
                    const minutes = Math.floor(seconds / 60);
                    const remainingSeconds = seconds % 60;

                    // Update the countdown timer display
                    timerElement.text(`${minutes}:${remainingSeconds < 10 ? '0' : ''}${remainingSeconds}`);

                    // Change the font color to red if less than 1 minute remains
                    if (seconds <= 60) {
                        timerElement.css('color', 'red');
                    } else {
                        timerElement.css('color', 'inherit'); // Default color
                    }

                    // If the timer expires
                    if (seconds <= 0) {
                        clearInterval(countdownTimer);
                        timerElement.text('OTP has expired.');
                        timerElement.css('color', 'red');
                    }
                    seconds--;
                }, 1000);
            }

            // Start countdown with the time remaining from PHP
            if (timeRemaining > 0) {
                startCountdown(timeRemaining);
            } else {
                $('#timer').text('OTP has expired.');
                $('#timer').css('color', 'red');
            }

            // OTP input focus handling (for navigation between fields)
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

            // Handle form submission (verify OTP)
            // Track attempts
            let attemptsLeft = 4; // Will be updated from server response

            // Handle OTP verification
            $('#otpForm').on('submit', function(e) {
                e.preventDefault();

                // Prepare data object with CSRF token
                const formData = {
                    csrf_token: $('#csrf_token').val() // Get CSRF token from hidden input
                };

                // Add each OTP digit individually (digit1, digit2, etc.)
                $('.otp-input').each(function(index) {
                    formData['digit' + (index + 1)] = $(this).val();
                });

                // Debug: log what we're sending
                // console.log("Sending OTP data:", formData);

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
                                $('#resendOtp').click(); // Auto-resend on failure
                            }
                            // Show error message more elegantly
                            $('#loginError').text(response.message).show();
                        }
                    },
                    error: function(xhr) {
                        // Better error handling
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

                // Add loading indicator
                $btn.html('<span class="spinner-border spinner-border-sm" role="status"></span> Sending...');

                $.ajax({
                    url: '/DTS/server-logic/config/send-otp.php', // Updated path (absolute recommended)
                    method: 'POST',
                    data: {
                        action: 'resend_otp'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            // Update attempts counter
                            const attemptsLeft = response.attempts_remaining;
                            $('#attempt-counter').text(`${attemptsLeft} ${attemptsLeft === 1 ? 'attempt' : 'attempts'} left`);

                            // Show success message with timer
                            const $alert = $(`
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        ${response.message}
                        
                    </div>
                `);
                            $('#otpForm').prepend($alert);

                            // Auto-dismiss after 5 seconds
                            setTimeout(() => $alert.alert('close'), 5000);

                            // Update UI if last attempt
                            if (attemptsLeft <= 0) {
                                $btn.prop('disabled', true).text('No attempts left');
                            }
                        } else {
                            // Enhanced error handling
                            const $error = $(`
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <strong>Error:</strong> ${response.message}
                        // <button type="button"  data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                `);
                            $('#otpForm').prepend($error);

                            if (response.message.includes('locked')) {
                                startLockoutTimer(3600); // 1 hour timer
                                $btn.prop('disabled', true).text('Account Locked');
                            }
                        }
                    },
                    error: function(xhr) {
                        // Handle HTTP errors
                        let message = "Network error. Please try again.";
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }

                        $(`
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Error:</strong> ${message}
                    // <button type="button"  data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `).insertAfter('#otpForm');
                    },
                    complete: function() {
                        // Reset button state (unless locked)
                        if (!$btn.is(':disabled')) {
                            $btn.prop('disabled', false).html('Resend OTP');
                        }
                    }
                });
            });

            // Lockout timer display
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
</body>

</html>