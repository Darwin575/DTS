<?php
// require_once __DIR__ . '/server-logic/config/session_init.php';
// require_once __DIR__ . '/server-logic/config/db.php';
require_once __DIR__ . '/../server-logic/config/csrf.php';

// Initialize secure session
// $user = SessionManager::get('user');
// if (isset($user['id'])) {
//     unset($user['id']);
// }
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateCsrfToken();
}

if (isset($_GET['token'])) {
    $_SESSION['doc_token'] = $_GET['token'];
}

$image_url = '/DTS/img/BJMP_logo.png';
error_log("SESSION ID: " . session_id());
error_log("CSRF TOKEN: " . ($_SESSION['csrf_token'] ?? 'NOT SET'));
?>
<style>
    /* Modal header background and white text */
    .modal-header {
        background-color: #2F4050;
        border-bottom: none;
    }

    /* White close button */
    .modal-header .close span {
        color: white;
        font-size: 1.5rem;
    }

    /* Center logo image and add bottom margin */
    .modal-body img {
        display: block;
        margin: 0 auto 20px;
    }

    /* Optional: Adjust padding inside the login form container */
    .ibox-content {
        padding: 20px;
    }
</style>

<!-- Login Modal -->
<div class="modal fade" id="loginModal" data-backdrop="static" data-keyboard="false" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
    <!-- Use modal-lg to get a larger modal on wide screens -->
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content" style="background-color: #2F4050;">
            <!-- Modal header with close button -->
            <div class="modal-header">
                <!-- Optional Title (left out in favor of a minimal header) -->
                <a href="<?php echo strtok($_SERVER['REQUEST_URI'], '?'); ?>" class="close" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </a>


            </div>
            <!-- Modal body using two-column layout -->
            <div class="modal-body">
                <div class="row">
                    <!-- Left Column: Logo Image -->
                    <div class="col-12 col-md-6 text-center text-md-left mb-3 mb-md-0">
                        <div class="d-flex justify-content-center justify-content-md-start">
                            <img alt="logo" class="rounded-circle" src="<?php echo $image_url; ?>">
                        </div>
                    </div>
                    <!-- Right Column: Login Form -->
                    <div class="col-12 col-md-6">
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
                                <!-- Optional: Forgot password info -->
                                <!-- <small>Forgot your password? <a href="forgot-password.php">Contact the administrator</a></small> -->
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Optional: Modal footer -->
            <div class="modal-footer border-0">
                <!-- You could add extra footer links or text here if desired -->
            </div>
        </div>
    </div>
</div>

<?php
// include 'layouts/footer.php'; 
?>

<script>
    $(document).ready(function() {
        // Initialize the modal if needed, or trigger it when required.
        // For example, to show immediately for testing:
        // $("#loginModal").appendTo('body').modal("show");

        $("#loginForm").on("submit", function(e) {
            e.preventDefault(); // Stop normal form submission

            var $btn = $("#loginButton");
            var $error = $("#loginError");

            // Disable the button and show a loading spinner while processing
            $btn.prop("disabled", true);
            $btn.html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Logging in...');
            $error.hide();

            // Submit form data via AJAX to your authentication script
            $.ajax({
                url: '/DTS/server-logic/config/auth.php', // Adjust path as needed
                method: "POST",
                data: $(this).serialize() + "&login=true",
                dataType: "json",
                success: function(response) {
                    if (response.status === "success") {
                        // Check if we need to show the OTP modal
                        if (response.show_modal) {
                            $("#otpModal").appendTo('body').modal("show");
                        } else {
                            window.location.href = response.redirect;
                        }
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
                        $error.text(response.message || "Login failed").show();
                    } catch {
                        $error.text("An error occurred. Please try again.").show();
                    }
                },
                complete: function() {
                    $btn.prop("disabled", false);
                    $btn.text("Login");
                }
            });

        });

        // Hide any error message when the user starts typing
        $("input").on("input", function() {
            $("#loginError").hide();
        });
    });
</script>