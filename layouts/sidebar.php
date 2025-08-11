<?php
include_once __DIR__    . '/../server-logic/config/session_init.php';

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

include_once __DIR__ . '/../server-logic/config/require_login.php';
$user_role = SessionManager::get('user')['role'];

if ($user_role !== 'user') {
    header('Location: /DTS/index.php');
    exit;
}

?>

<nav class="navbar-default navbar-static-side" role="navigation">
    <div class="sidebar-collapse">
        <ul class="nav metismenu" id="side-menu">
            <li class="nav-header">
                <div class="dropdown profile-element text-center">
                    <img alt="image" class="rounded-circle m-t-s img-fluid" style="width: 80px; height: 80px; object-fit:cover;" src="<?= htmlspecialchars($profilePic) ?>" />
                    <div class="mt-2 text-left">
                        <?php if (!empty($name)): ?>
                            <span class="block m-t-xs font-bold"><?= htmlspecialchars($name) ?></span>
                            <?php if (!empty($office)): ?>
                                <span class="text-muted small d-block"><?= htmlspecialchars($office) ?></span>
                            <?php endif; ?>
                        <?php elseif (!empty($office)): ?>
                            <span class="block m-t-xs font-bold"><?= htmlspecialchars($office) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="logo-element">
                    <!-- IN+ -->
                </div>
            <li class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <a href="dashboard.php">
                    <i class="fa-solid fa-gauge fa-lg me-2"></i>
                    <span class="nav-label">Dashboard</span>
                </a>
            </li>
            <li class="<?php echo ($current_page == 'document_management.php') ? 'active' : ''; ?>">
                <a href="document_management.php">
                    <i class="fa-solid fa-upload fa-lg me-2"></i>
                    <span class="nav-label">Upload Document</span>
                </a>
            </li>
            <li class="<?php echo ($current_page == 'user_profile.php') ? 'active' : ''; ?>">
                <a href="user_profile.php">
                    <i class="fa-solid fa-user fa-lg me-2"></i>
                    <span class="nav-label">Profile</span>
                </a>
            </li>
            <li class="<?php echo ($current_page == 'receive_document.php') ? 'active' : ''; ?>">
                <a href="receive_document.php">
                    <i class="fa-solid fa-inbox fa-lg me-2"></i>
                    <span class="nav-label">Receive Document</span>
                </a>
            </li>
            <li class="<?php echo ($current_page == 'document_user_list.php') ? 'active' : ''; ?>">
                <a href="document_user_list.php">
                    <i class="fa-solid fa-list fa-lg me-2"></i>
                    <span class="nav-label">Document List</span>
                </a>
            </li>
            <li class="<?php echo ($current_page == 'drafted_document.php') ? 'active' : ''; ?>">
                <a href="drafted_document.php">
                    <i class="fa-solid fa-file-pen fa-lg me-2"></i>
                    <span class="nav-label">Drafted Document</span>
                </a>
            </li>
        </ul>

    </div>
    <script src="https://cdn.lordicon.com/lordicon.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="../css/icon-animations.css">
    <script src="../js/sidebar-animations.js"></script>
</nav>