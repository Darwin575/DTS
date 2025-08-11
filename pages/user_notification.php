<?php
include '../layouts/header.php';
include_once __DIR__ . '/../server-logic/config/db.php';
include_once __DIR__ . '/../server-logic/config/session_init.php';
$user_role = SessionManager::get('user')['role'];

if ($user_role !== 'user') {
    header('Location: /DTS/index.php');
    exit;
}
global $conn;
$user = SessionManager::get('user');
$userId = $user['id'] ?? 0;
if (!$userId) {
    exit;
}
?>


<body>
    <div id="wrapper">
        <?php include '../layouts/sidebar.php'; ?>
        <div id="page-wrapper" class="gray-bg">
            <div class="row border-bottom">
                <?php include '../layouts/user_navbar_top.php'; ?>
            </div>

            <div class="wrapper wrapper-content animated fadeIn">
                <div class="row" id="urgent-container">
                    <!-- Urgent documents will be loaded here via AJAX -->
                </div>

                <!-- Notification Section -->
                <div class="row">
                    <div class="col-lg-12">
                        <div class="ibox">
                            <div class="ibox-title bg-success text-white">
                                <h5>Notifications</h5>
                                <div class="ibox-tools">
                                    <a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                </div>
                            </div>
                            <div
                                id="notification-container"
                                class="ibox-content notification-container overflow-auto"
                                style="max-height: 300px;">
                                <!-- Notifications will be loaded here via AJAX -->
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
        function fetchDashboardUpdates() {
            $.ajax({
                url: '../server-logic/user-operations/user-notification.php',
                dataType: 'json',
                success: function(data) {
                    if (data.urgent_html !== undefined)
                        $('#urgent-container').html(data.urgent_html);
                    if (data.notification_html !== undefined)
                        $('#notification-container').html(data.notification_html);
                },
                error: function(xhr, status, error) {
                    console.error("Dashboard update error:", error);
                }
            });
        }

        // Poll every 20 seconds
        setInterval(fetchDashboardUpdates, 20000);

        // Also fetch immediately on page load
        $(document).ready(function() {
            fetchDashboardUpdates();
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>