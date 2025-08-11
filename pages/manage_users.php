<?php
require_once __DIR__ . '/../server-logic/config/session_init.php';

include '../layouts/header.php';
include '../server-logic/config/db.php';

$user_role = SessionManager::get('user')['role'];
if ($user_role !== 'admin') {
    header('Location: /DTS/index.php');
    exit;
}
// Initialize $users as an empty array
$users = [];

// Fetch all users from the database
$sql = "SELECT * FROM tbl_users";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;  // Add each row to the $users array
    }
}
?>

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
            <div class="row wrapper border-bottom white-bg page-heading">
                <div class="col-lg-2">
                </div>
            </div>
            <div class="wrapper wrapper-content animated fadeInRight">
                <!-- Add this block to display the success message -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success">
                        <?= htmlspecialchars($_SESSION['success_message']); ?>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-12">
                        <div class="ibox ">
                            <div class="ibox-title">
                                <h5>User List/Edit</h5>
                                <div class="ibox-tools">
                                    <button type="button" class="btn btn-primary btn-sm addUserBtn" data-toggle="modal" data-target="#myModal5">
                                        Add Account
                                    </button>
                                </div>
                            </div>
                            <div class="ibox-content table-responsive">
                                <input type="text" class="form-control form-control-sm m-b-xs" id="filter" placeholder="Search in table">
                                <table class="footable table table-stripped" data-filter="#filter">
                                    <thead>
                                        <tr>
                                            <th>Department/Office</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($users)): ?>
                                            <?php foreach ($users as $user): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($user['office_name']) ?></td>
                                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                                    <td><?= htmlspecialchars($user['role']) ?></td>
                                                    <td class="text-right">
                                                        <div class="btn-group">
                                                            <button type="button" class="btn btn-warning btn-sm editUserBtn" data-id="<?= $user['user_id'] ?>">
                                                                <i class="fa fa-edit"></i> Edit
                                                            </button>

                                                            <button type="button"
                                                                class="btn btn-sm <?= $user['is_deactivated'] ? 'btn-success' : 'btn-danger' ?> deactivateBtn"
                                                                data-id="<?= $user['user_id'] ?>"
                                                                data-status="<?= $user['is_deactivated'] ?>">
                                                                <?php if ($user['is_deactivated']): ?>
                                                                    <i class="fa fa-power-off"></i> Reactivate
                                                                <?php else: ?>
                                                                    <i class="fa fa-ban"></i> Deactivate
                                                                <?php endif; ?>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" class="text-center">No users found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <div class="bottom"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal fade" id="deactivateModal" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Confirm Account Status Change</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <p id="deactivateMessage"></p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-white btn-sm" data-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-danger btn-sm" id="confirmDeactivate">Confirm</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal fade" id="myModal5" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Add Account</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <form id="form" action="#" class="wizard-big">
                                    <input type="hidden" id="user_id" name="user_id">
                                    <h1>Account</h1>
                                    <fieldset>
                                        <h2>Account Information</h2>
                                        <div class="form-group">
                                            <label>Office *</label>
                                            <input id="officeName" name="officeName" type="text" class="form-control required">
                                        </div>
                                        <div class="form-group">
                                            <label>Email *</label>
                                            <input id="email" name="email" type="text" class="form-control required email">
                                        </div>
                                        <div class="form-group">
                                            <label>Role *</label>
                                            <select id="role" name="role" class="form-control required">
                                                <option value="user">User</option>
                                                <option value="admin">Admin</option>
                                            </select>
                                        </div>
                                        <div class="form-group" id="pwd-container1">
                                            <label>Password *</label>
                                            <input type="password" class="form-control example1 required" id="password" name="password" placeholder="Password">

                                            <p></p>
                                            <div class="pwstrength_viewport_progress"></div>

                                        </div>
                                        <div class="form-group">
                                            <label>Confirm Password *</label>
                                            <input id="confirm" name="confirm" type="password" class="form-control required">
                                        </div>
                                    </fieldset>
                                    <h1>Finish</h1>
                                    <fieldset>
                                        <div class="text-center mt-3" style="margin-top: 20px;">
                                            <h2 id="finishMessage">Click 'Finish' to add or update the user...</h2>
                                        </div>
                                    </fieldset>
                                </form>
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
    <!-- Page-Level Scripts -->
    <script>
        // Upgrade button class name
        $.fn.dataTable.Buttons.defaults.dom.button.className = 'btn btn-white btn-sm';

        $(document).ready(function() {
            // Track edit state
            let isEditMode = false;
            let currentEditData = null;
            let wizardInitialized = false;
            let currentOperation = '';


            // Initialize Footable
            $('.footable').footable();

            // Initialize wizard
            initializeWizard();
            wizardInitialized = true;

            // Reset form completely
            function resetForm() {
                $('#form')[0].reset();
                currentOperation = 'addUser';
                $('#user_id').val('');
                $('.modal-title').text('Add Account');
                $('#finishMessage').text('Click "Finish" to add or update the user...').css('color', '');
                window.currentEditingUserId = ''; // Clear the persisted ID
                // Reset wizard to first step if initialized
                if (wizardInitialized) {
                    $("#form").steps("destroy");
                    initializeWizard();
                }
            }

            // Handle modal show event
            $('#myModal5').on('show.bs.modal', function(e) {
                // Only reset if not in edit mode
                if (!isEditMode) {
                    resetForm();
                }

                // Reset password strength
                if ($('.example1').data('pwstrength')) {
                    $('.example1').pwstrength('destroy');
                }
                $('.pwstrength_viewport_progress').empty();
            });

            // Handle modal shown event (after display)
            $('#myModal5').on('shown.bs.modal', function() {
                // Initialize password strength
                var options1 = {
                    ui: {
                        container: "#pwd-container1",
                        showVerdictsInsideProgressBar: true,
                        viewports: {
                            progress: ".pwstrength_viewport_progress"
                        }
                    },
                    common: {
                        debug: true
                    }
                };

                if ($('.example1').length > 0 && !$('.example1').data('pwstrength')) {
                    $('.example1').pwstrength(options1);
                }
            });

            // Edit button click handler
            $('.editUserBtn').click(function() {
                var user_id = $(this).data('id');
                // Store the user_id in a variable that persists
                window.currentEditingUserId = user_id;

                $('#user_id').val(user_id);
                isEditMode = true;
                currentOperation = 'editUser';


                $.ajax({
                    url: '../server-logic/admin-operations/manage-user.php',
                    type: 'POST',
                    data: {
                        action: 'getUser',
                        user_id: user_id
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            currentEditData = response.data;
                            // Re-set the user_id from our persisted variable
                            $('#user_id').val(window.currentEditingUserId);
                            // Destroy existing wizard
                            if ($("#form").data('steps')) {
                                $("#form").steps("destroy");
                            }

                            // Initialize new wizard
                            initializeWizard();

                            // Populate form fields
                            // $('#user_id').val(currentEditData.user_id);
                            $('#officeName').val(currentEditData.office_name);
                            $('#email').val(currentEditData.email);
                            $('#role').val(currentEditData.role).trigger('change');
                            $('.modal-title').text('Edit Account');

                            // Clear password fields
                            $('#password').val('');
                            $('#confirm').val('');

                            // Show the modal
                            $('#myModal5').modal('show');
                        } else {
                            console.error("Error:", response.message);
                            alert('Error: ' + response.message);
                            isEditMode = false;
                            currentEditData = null;
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX Error:", status, error);
                        alert('An error occurred while fetching user data.');
                        isEditMode = false;
                        currentEditData = null;
                    }
                });
            });

            // Add button click handler
            $('.addUserBtn').click(function() {
                isEditMode = false;
                currentEditData = null;
                currentOperation = 'addUser';

                $('#myModal5').modal('show');
            });

            // Password complexity validation
            $.validator.addMethod("passwordComplexity", function(value, element) {
                const isValid = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&,])[A-Za-z\d@$!%*?&,]{8,}$/.test(value);
                return this.optional(element) || isValid;
            }, "Password must include at least one uppercase letter, one lowercase letter, one number, and one special character.");

            // Initialize Wizard Form
            function initializeWizard() {
                $("#form").steps({
                    bodyTag: "fieldset",
                    onStepChanging: function(event, currentIndex, newIndex) {
                        if (currentIndex > newIndex) return true;
                        var form = $(this);
                        if (currentIndex < newIndex) {
                            $(".body:eq(" + newIndex + ") label.error", form).remove();
                            $(".body:eq(" + newIndex + ") .error", form).removeClass("error");
                        }
                        form.validate().settings.ignore = ":disabled,:hidden";
                        return form.valid();
                    },
                    onFinishing: function(event, currentIndex) {
                        var form = $(this);
                        form.validate().settings.ignore = ":disabled";
                        return form.valid();
                    },
                    onFinished: function(event, currentIndex) {
                        var userId = $('#user_id').val() || window.currentEditingUserId;
                        console.log("operation", currentOperation);
                        var formData = {
                            user_id: userId,
                            officeName: $('#officeName').val(),
                            email: $('#email').val(),
                            role: $('#role').val(),
                            password: $('#password').val(),
                            confirm: $('#confirm').val(),
                            action: 'saveUser',
                            operation: currentOperation // Use the tracked operation
                        };

                        console.log("Submitting data:", formData); // Debug log
                        console.log("Current user_id value:", $('#user_id').val());
                        $.ajax({
                            url: '../server-logic/admin-operations/manage-user.php',
                            type: 'POST',
                            data: formData,
                            dataType: 'json',
                            success: function(response) {
                                if (response.status === 'success') {
                                    $('#finishMessage').text(response.message).css('color', 'green');
                                    setTimeout(function() {
                                        $('#myModal5').modal('hide');
                                        location.reload();
                                    }, 1500);
                                } else {
                                    $('#finishMessage').text(response.message).css('color', 'red');
                                }
                            },
                            error: function() {
                                $('#finishMessage').text('An error occurred. Please try again.').css('color', 'red');
                            }
                        });
                    }
                }).validate({
                    errorPlacement: function(error, element) {
                        element.before(error);
                    },
                    rules: {
                        password: {
                            required: function() {
                                return currentOperation === 'addUser'; // Only required for new users
                            },
                            minlength: 8,
                            passwordComplexity: true
                        },
                        confirm: {
                            equalTo: "#password"
                        }
                    },
                    messages: {
                        password: {
                            required: "Password is required.",
                            minlength: "Password must be at least 8 characters long."
                        },
                        confirm: {
                            equalTo: "Passwords do not match."
                        }
                    }
                });
            }
            $(document).on('click', '.deactivateBtn', function() {
                const userId = $(this).data('id');
                const isDeactivated = $(this).data('status');

                $('#deactivateMessage').text(`Are you sure you want to ${isDeactivated ? 'reactivate' : 'deactivate'} this account?`);
                $('#confirmDeactivate').text(isDeactivated ? 'Reactivate' : 'Deactivate');
                $('#deactivateModal').data('user-id', userId).modal('show');
            });

            // Update the AJAX request data in the deactivation handler
            $('#confirmDeactivate').click(function() {
                const userId = $('#deactivateModal').data('user-id');
                $.ajax({
                    url: '../server-logic/admin-operations/manage-user.php',
                    type: 'POST',
                    data: {
                        action: 'toggleUserStatus', // Changed from operation to action
                        user_id: userId
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            location.reload();
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                    }
                });
            });


            // Initialize Select2
            // $(".select2_demo_3").select2({
            //     theme: 'bootstrap4',
            //     placeholder: "Select a state",
            // });
        });
    </script>
</body>

</html>