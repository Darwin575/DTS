<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../server-logic/config/session_init.php';
include_once __DIR__ . '/../server-logic/config/require_login.php';

$current_page = basename($_SERVER['PHP_SELF']); // Get current file name

// Get user info for sidebar
$user = SessionManager::get('user', []);
$profilePic = $user['profile_picture_path'] ?? '../uploads/profile/default_profile_pic.jpg';
if (empty($profilePic) || !file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($profilePic, '/'))) {
    $profilePic = '../uploads/profile/default_profile_pic.jpg';
}
$name = $user['name'] ?? '';
$office = $user['office_name'] ?? '';
$displayName = $name ? $name . ($office ? " ($office)" : "") : $office;


?>

<nav class="navbar-default navbar-static-side" role="navigation">
    <div class="sidebar-collapse">
        <ul class="nav metismenu" id="side-menu">
            <li class="nav-header">
                <div class="dropdown profile-element text-center">
                    <img
                        alt="image"
                        class="rounded-circle m-t-s img-fluid"
                        style="width: 80px; height: 80px; object-fit: cover;"
                        src="<?= htmlspecialchars($profilePic) ?>" />

                    <?php if (!empty($name)): ?>
                        <!-- Name is present: use text-left -->
                        <div class="mt-2 w-100 text-left">
                            <span class="d-block font-bold"><?= htmlspecialchars($name) ?></span>
                            <?php if (!empty($office)): ?>
                                <span class="d-block text-muted"><?= htmlspecialchars($office) ?></span>
                            <?php endif; ?>
                        </div>

                    <?php elseif (!empty($office)): ?>
                        <!-- Name is empty but office is present: center it -->
                        <div class="mt-2 w-100 text-center">
                            <span class="d-block font-bold"><?= htmlspecialchars($office) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="logo-element">
                    <!-- IN+ -->
                </div>
            </li>
            <li class="<?php echo ($current_page == 'admin_page.php') ? 'active' : ''; ?>">
                <a href="admin_page.php">
                    <i class="fa-solid fa-gauge fa-lg me-2"></i>
                    <span class="nav-label">Dashboards</span>
                </a>
            </li>
            <li class="<?php echo ($current_page == 'manage_users.php') ? 'active' : ''; ?>">
                <a href="/DTS/pages/manage_users.php">
                    <i class="fa-solid fa-users-cog fa-lg me-2"></i>
                    <span class="nav-label">Manage Users</span>
                </a>
            </li>
            <li class="<?php echo ($current_page == 'document_list.php') ? 'active' : ''; ?>">
                <a href="/DTS/pages/document_list.php">
                    <i class="fa-solid fa-file-alt fa-lg me-2"></i>
                    <span class="nav-label">Manage Documents</span>
                </a>
            </li>
            <li class="<?php echo ($current_page == 'user_activity.php') ? 'active' : ''; ?>">
                <a href="user_activity.php">
                    <i class="fa-solid fa-user-clock fa-lg me-2"></i>
                    <span class="nav-label">User Activity</span>
                </a>
            </li>
        </ul>
    </div>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="../css/icon-animations.css">
    <script src="../js/sidebar-animations.js"></script>
</nav>