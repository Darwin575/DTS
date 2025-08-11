<?php
require_once __DIR__ . '/server-logic/config/session_init.php';
require_once __DIR__ . '/server-logic/config/db.php';
include 'layouts/header.php';
// Initialize secure session
require_once __DIR__ . '/server-logic/config/csrf.php';
// At top of file:
$user = SessionManager::get('user');

// Check if the CSRF token is not set and assign one if missing.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateCsrfToken();
}

// Store any token passed in the URL.
if (isset($_GET['token'])) {
    $_SESSION['doc_token'] = $_GET['token'];
}

// Configuration settings for images and logging.
$image_path = $_SERVER['DOCUMENT_ROOT'] . '/DTS/img/BJMP_logo.png';
$image_url = '/DTS/img/BJMP_logo.png';
error_log("SESSION ID: " . session_id());
error_log("CSRF TOKEN: " . ($_SESSION['csrf_token'] ?? 'NOT SET'));
?>

<!-- Include the auto-logout script so that this page (and any protected page) listens for session changes -->
<script src="/DTS/server-logic/config/auto_logout.js"></script>

<body style="background-color: #2F4050;">
    <div class="page-wrapper" style="min-height: 100vh;">
        <div class="loginColumns animated fadeInDown">
            <div class="row">
                <!-- Left Column (Logo/Image) -->
                <div class="col-md-6 text-center text-md-left">
                    <div class="d-flex justify-content-center justify-content-md-start">
                        <img alt="logo" style="margin: 10px;" class="rounded-circle" src="<?php echo $image_url; ?>">
                    </div>
                </div>

                <!-- Right Column (Login Form) -->
                <div class="col-md-6">
                    <div class="ibox-content bg-white p-4 rounded shadow">
                        <form id="loginForm" method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <div class="form-group">
                                <input type="email" class="form-control" placeholder="Email" name="email" required
                                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email'], ENT_QUOTES) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <input type="password" class="form-control" placeholder="Password" name="password" required>
                            </div>
                            <button type="submit" id="loginButton" class="btn btn-primary block full-width m-b">
                                Login
                            </button>
                        </form>

                        <!-- Error message container -->
                        <div id="loginError" class="alert alert-danger text-center mt-3" style="display: none;"></div>

                        <p class="m-t text-center">
                            <!-- <small>Forgot your password? <a href="forgot-password.php">Contact the administrator</a></small> -->
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'layouts/footer.php'; ?>

    <script>
        $(document).ready(function() {
            // Ensure the CSRF token is sent with every request.
            var csrfToken = $('input[name="csrf_token"]').val();

            $('#loginForm').on('submit', function(e) {
                e.preventDefault(); // Prevent default form submission

                var $btn = $('#loginButton');
                var $error = $('#loginError');

                // Set the button to a loading state.
                $btn.prop('disabled', true);
                $btn.html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Logging in...');
                $error.hide();

                // Send the CSRF token along with the form data using AJAX.
                $.ajax({
                    url: '/DTS/server-logic/config/auth.php',
                    method: 'POST',
                    data: $(this).serialize() + '&login=true',
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            // *** AUTO-LOGOUT FEATURE CODE ***
                            // Build a session object based on the login details.
                            var sessionData = {
                                email: $('input[name="email"]').val()
                                // Optionally, you can add additional details like user ID or a token from the response.
                            };

                            // Update the session in localStorage.
                            localStorage.setItem("currentUser", JSON.stringify(sessionData));

                            // Notify other tabs using BroadcastChannel, if supported.
                            if (window.BroadcastChannel) {
                                var sessionChannel = new BroadcastChannel("session_channel");
                                sessionChannel.postMessage({
                                    type: "NEW_LOGIN",
                                    user: sessionData
                                });
                            }
                            // *** END AUTO-LOGOUT FEATURE CODE ***

                            console.log("Attempting redirect to:", response.redirect);
                            window.location.href = response.redirect;
                        } else {
                            $error.text(response.message).show();
                            if (response.attempts_remaining) {
                                $error.append('<div class="mt-2 small">Attempts remaining: ' + response.attempts_remaining + '</div>');
                            }
                        }
                    },
                    error: function(xhr, status, error) {
                        if (xhr.status === 400) {
                            console.error("400 Bad Request:", xhr.responseText);
                        }
                        console.error("AJAX error:", status, error);
                        try {
                            const response = JSON.parse(xhr.responseText);
                            $error.text(response.message || 'Login failed').show();
                        } catch {
                            $error.text('An error occurred. Please try again.').show();
                        }
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                        $btn.text('Login');
                    }
                });
            });

            // Clear the error message when the user types into any input field.
            $('input').on('input', function() {
                $('#loginError').hide();
            });
        });
    </script>
</body>

</html>