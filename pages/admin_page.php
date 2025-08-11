<?php
require_once __DIR__ . '/../server-logic/config/db.php';
require_once __DIR__ . '/../server-logic/config/session_init.php';
include '../layouts/header.php';
require_once __DIR__ . '/../server-logic/reports/summary.php';
$user_role = SessionManager::get('user')['role'];
if ($user_role !== 'admin') {
    header('Location: /DTS/index.php');
    exit;
}
$user_id = SessionManager::get('user')['id'];

// Refresh summaries for current periods
$today = new DateTime();
// Fetch all user IDs with role 'user'

// In admin_page.php - Ensure this runs before DashboardManager is initialized
$result = $conn->query("SELECT user_id FROM tbl_users WHERE role = 'user'");
while ($row = $result->fetch_assoc()) {
    foreach (['daily', 'weekly', 'monthly', 'yearly'] as $period) {
        upsertDocumentSummary($conn, $row['user_id'], $period, clone $today);
    }
}

class DashboardManager
{
    private $conn;
    private $current_user_id;
    private $timePeriod;
    private $selectedOffice = null;

    public function __construct($conn, $current_user_id)
    {
        $this->conn = $conn;
        $this->current_user_id = $current_user_id;
        $this->setTimePeriod();
        $this->handleOfficeSelection();

        // Refresh current period summary
        upsertDocumentSummary(
            $this->conn,
            $this->current_user_id,
            $this->timePeriod,
            new DateTime()
        );
    }

    private function setTimePeriod()
    {
        $validPeriods = ['daily', 'weekly', 'monthly', 'yearly'];
        $this->timePeriod = isset($_GET['period']) && in_array($_GET['period'], $validPeriods)
            ? $_GET['period']
            : 'daily';
    }

    private function handleOfficeSelection()
    {
        if (isset($_GET['office']) && !empty($_GET['office'])) {
            $this->selectedOffice = $this->conn->real_escape_string($_GET['office']);
        }
    }

    public function getGlobalDocumentTotals()
    {
        $query = $this->conn->prepare("
        SELECT 
            SUM(status = 'active') AS total_on_route,
            SUM(status = 'archived') AS total_completed 
        FROM tbl_documents
    ");
        $query->execute();
        $result = $query->get_result();
        return $result->fetch_assoc() ?? ['total_on_route' => 0, 'total_completed' => 0];
    }

    public function getOfficeSummaries()
    {
        $query = $this->conn->prepare("
        SELECT 
            u.office_name,
            COALESCE(SUM(d.status = 'active'), 0) AS total_on_route,
            COALESCE(SUM(d.status = 'archived'), 0) AS total_completed,
            GROUP_CONCAT(DISTINCT u.name SEPARATOR ', ') as users
        FROM tbl_users u
        LEFT JOIN tbl_documents d 
            ON u.user_id = d.user_id
        WHERE u.role = 'user'
        GROUP BY u.office_name
        ORDER BY u.office_name
    ");

        $query->execute();
        $result = $query->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function getTotals($officeSummaries)
    {
        return [
            'offices'   => count($officeSummaries),
            'users'     => array_sum(array_map(function ($office) {
                return count(explode(', ', $office['users']));
            }, $officeSummaries)),
            'on_route'  => array_sum(array_column($officeSummaries, 'total_on_route')),
            'completed' => array_sum(array_column($officeSummaries, 'total_completed'))
        ];
    }

    public function getRecentActivities()
    {
        // Prepare base SQL with optional office filter and exclude admin users
        $sql = "
        SELECT 
            ds.summary_date,
            ds.summary_type,
            u.name            AS user_name,
            u.office_name,
            ds.on_route_documents,
            ds.completed_documents
        FROM 
            tbl_document_summary ds
        JOIN 
            tbl_users u 
          ON ds.user_id = u.user_id
        WHERE
            ds.summary_type = ?
            AND u.role != 'admin'
            " . ($this->selectedOffice ? " AND u.office_name = ?" : "") . "
        ORDER BY 
            ds.summary_date DESC,
            ds.created_at   DESC
        LIMIT 5
    ";

        $query = $this->conn->prepare($sql);

        // Bind parameters: always bind summary_type, and bind office_name if filtering by office
        if ($this->selectedOffice) {
            $query->bind_param("ss", $this->timePeriod, $this->selectedOffice);
        } else {
            $query->bind_param("s",  $this->timePeriod);
        }

        $query->execute();

        // Fetch and return as an associative array
        return $query->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function getCurrentSummaryDate()
    {
        $today = new DateTime();
        switch ($this->timePeriod) {
            case 'daily':
                return $today->format('Y-m-d');
            case 'weekly':
                $start = (clone $today)->modify('monday this week');
                return $start->format('Y-m-d') . '_to_' . $start->modify('sunday this week')->format('Y-m-d');
            case 'monthly':
                return $today->format('Y-m') . '_monthly';
            case 'yearly':
                return $today->format('Y') . '_yearly';
            default:
                return $today->format('Y-m-d');
        }
    }

    public function getDistributionData()
    {
        $query = $this->conn->prepare("
            SELECT 
                COALESCE(SUM(on_route_documents), 0) as on_route,
                COALESCE(SUM(completed_documents), 0) as completed
            FROM tbl_document_summary ds
            JOIN tbl_users u ON ds.user_id = u.user_id
            WHERE ds.summary_type = ?
            " . ($this->selectedOffice ? " AND u.office_name = ?" : "") . "
        ");

        if ($this->selectedOffice) {
            $query->bind_param("ss", $this->timePeriod, $this->selectedOffice);
        } else {
            $query->bind_param("s", $this->timePeriod);
        }

        $query->execute();
        return $query->get_result()->fetch_assoc();
    }

    public function getTimePeriod()
    {
        return $this->timePeriod;
    }

    public function getSelectedOffice()
    {
        return $this->selectedOffice;
    }

    public function isAdmin()
    {
        $adminCheck = $this->conn->prepare("SELECT role FROM tbl_users WHERE user_id = ?");
        $adminCheck->bind_param("i", $this->current_user_id);
        $adminCheck->execute();
        return $adminCheck->get_result()->fetch_assoc()['role'] === 'admin';
    }
}

// Initialize dashboard manager
$dashboard = new DashboardManager($conn, SessionManager::get('user')['id']);
$officeSummaries = $dashboard->getOfficeSummaries();

// Get totals
$globalTotals = $dashboard->getGlobalDocumentTotals();
$periodTotals = $dashboard->getTotals($officeSummaries);
$recentActivities = $dashboard->getRecentActivities();
$distributionData = $dashboard->getDistributionData();

// Calculate completion percentage using GLOBAL totals
$completion_percentage = ($globalTotals['total_on_route'] + $globalTotals['total_completed']) > 0
    ? round(($globalTotals['total_completed'] / ($globalTotals['total_on_route'] + $globalTotals['total_completed'])) * 100)
    : 0;
?>


<style>
    :root {
        --admin: #3498db;
    }

    body.admin-dashboard {
        background-color: #f8f9fa;
        font-family: 'Segoe UI', Roboto, sans-serif;
    }

    .admin-card {
        border-left: 4px solid var(--admin);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        transition: transform 0.2s;
    }

    .admin-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }

    .progress-thin {
        height: 6px;
    }

    .user-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
    }

    .office-row {
        cursor: pointer;
        transition: background-color 0.2s;
    }

    .office-row:hover {
        background-color: rgba(155, 89, 182, 0.05);
    }

    .activity-badge {
        font-size: 12px;
        padding: 3px 8px;
        border-radius: 10px;
    }

    .period-filter.active {
        background-color: var(--admin);
        color: white;
    }

    tr.selected-office td {
        background-color: rgba(209, 213, 212, 0) !important;
    }

    tr.selected-office td:first-child {
        border-left: 3px solid var(--admin);
    }
</style>


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

            <!-- Summary Stats -->
            <div class="row">
                <div class="col-md-6 col-lg-4">
                    <div class="admin-card p-3 mb-4 bg-white">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-muted mb-1">Offices</h6>
                                <h3 class="mb-0"><?= $periodTotals['offices'] ?></h3>
                            </div>
                            <div class="text-admin">
                                <i class="fa fa-building fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="admin-card p-3 mb-4 bg-white">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-muted mb-1">On Route</h6>
                                <h3 class="mb-0"><?= $globalTotals['total_on_route'] ?></h3>
                            </div>
                            <div class="text-warning">
                                <i class="fa fa-truck fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="admin-card p-3 mb-4 bg-white">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-muted mb-1">Completed</h6>
                                <h3 class="mb-0"><?= $globalTotals['total_completed'] ?></h3>
                                <small class="text-success">
                                    <?= $completion_percentage ?>% completion rate
                                </small>
                            </div>
                            <div class="text-success">
                                <i class="fa fa-check-circle fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="row">
                <!-- Office Summary -->
                <div class="col-lg-8">
                    <div class="admin-card p-4 mb-4 bg-white">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="mb-0">Office Document Summary</h4>
                            <?php if ($dashboard->getSelectedOffice()): ?>
                                <button id="clear-office-filter" class="btn btn-sm btn-outline-admin">
                                    Clear Filter
                                </button>
                            <?php endif; ?>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Office</th>
                                        <th class="text-center">On Route</th>
                                        <th class="text-center">Completed</th>
                                        <th>Progress</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($officeSummaries as $office):
                                        $onRoute   = (int)$office['total_on_route'];
                                        $completed = (int)$office['total_completed'];
                                        $officeTotal = $onRoute + $completed;
                                        $officeCompletion = $officeTotal > 0
                                            ? round(($office['total_completed'] / $officeTotal) * 100)
                                            : 0;
                                        $isSelected = $dashboard->getSelectedOffice() === $office['office_name'];
                                    ?>
                                        <tr class="office-row<?= $isSelected ? ' selected-office' : '' ?>" data-office="<?= htmlspecialchars($office['office_name']) ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($office['office_name']) ?></strong>
                                                <div class="mt-1">
                                                    <?php
                                                    $users = !empty($office['users']) ? explode(', ', $office['users']) : [];
                                                    foreach (array_slice($users, 0, 2) as $user):
                                                    ?>
                                                        <span class="badge badge-light mr-1">
                                                            <?= htmlspecialchars(trim($user)) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td class="text-center"><?= $office['total_on_route'] ?></td>
                                            <td class="text-center"><?= $office['total_completed'] ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress progress-thin flex-grow-1 mr-2">
                                                        <div class="progress-bar bg-success"
                                                            style="width: <?= $officeCompletion ?>%"></div>
                                                    </div>
                                                    <small><?= $officeCompletion ?>%</small>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity & Quick Stats -->
                <div class="col-lg-4">
                    <!-- Document Distribution & Recent Updates -->
                    <div class="admin-card p-4 mb-4 bg-white" id="distributionCard">
                        <h4 class="mb-3">Document Distribution & Recent Updates</h4>
                        <div class="btn-group btn-group-sm mb-3" id="periodFilterGroup">
                            <button class="btn btn-outline-admin period-filter" data-period="daily">Daily</button>
                            <button class="btn btn-outline-admin period-filter" data-period="weekly">Weekly</button>
                            <button class="btn btn-outline-admin period-filter" data-period="monthly">Monthly</button>
                            <button class="btn btn-outline-admin period-filter" data-period="yearly">Yearly</button>
                        </div>
                        <div class="chart-container" style="height: 200px;">
                            <canvas id="documentDistributionChart"></canvas>
                        </div>
                        <div class="activity-feed mt-4" id="recentActivityFeed">
                            <!-- Recent activities will be loaded here -->
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
        let chartInstance = null;
        let currentPeriod = 'daily';
        let currentOffice = '<?= addslashes($dashboard->getSelectedOffice() ?? '') ?>';

        function loadDashboardCard(period, office) {
            $.get('../server-logic/admin-operations/admin_dashboard_card_data.php', {
                period: period,
                office: office
            }, function(res) {
                let data = JSON.parse(res);
                // Update Chart
                let ctx = document.getElementById('documentDistributionChart').getContext('2d');
                if (chartInstance) chartInstance.destroy();
                chartInstance = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['On Route', 'Completed'],
                        datasets: [{
                            data: [data.distributionData.on_route, data.distributionData.completed],
                            backgroundColor: [
                                'rgba(52, 152, 219, 0.8)',
                                'rgba(46, 204, 113, 0.8)'
                            ],
                            borderColor: [
                                'rgba(52, 152, 219, 1)',
                                'rgba(46, 204, 113, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                onClick: null
                            }
                        }
                    }
                });

                // Update Recent Activities
                let feedHtml = '';
                if (data.recentActivities.length) {
                    data.recentActivities.forEach(function(activity) {
                        feedHtml += `
                        <div class="mb-3 pb-2 border-bottom">
                            <div class="d-flex justify-content-between mb-1">
                               
                                <small class="text-muted">${activity.summary_date ? (new Date(activity.summary_date)).toLocaleString() : ''}</small>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="badge activity-badge bg-light text-dark mr-2">${activity.summary_type.charAt(0).toUpperCase() + activity.summary_type.slice(1)}</span>
                                    <span class="text-primary">${activity.on_route_documents} on route</span>
                                    <span class="mx-1">•</span>
                                    <span class="text-success">${activity.completed_documents} completed</span>
                                </div>
                                <small class="text-muted">${activity.office_name}</small>
                            </div>
                        </div>`;
                    });
                } else {
                    feedHtml = `<div class="text-center py-3 text-muted">No recent document activities found</div>`;
                }
                $('#recentActivityFeed').html(feedHtml);

                // Update period button active state
                $('.period-filter').removeClass('active');
                $(`.period-filter[data-period="${period}"]`).addClass('active');
            });
        }

        $(document).ready(function() {
            // Initial load
            loadDashboardCard(currentPeriod, currentOffice);

            // Period filter click
            $('#periodFilterGroup').on('click', '.period-filter', function() {
                currentPeriod = $(this).data('period');
                loadDashboardCard(currentPeriod, currentOffice);
            });

            // Office row click (dynamic highlight)
            $('.office-row').click(function() {
                // Remove highlight from all rows
                $('.office-row').removeClass('selected-office');
                // Add highlight to the clicked row
                $(this).addClass('selected-office');

                // Update currentOffice and reload the right card
                currentOffice = $(this).data('office');
                loadDashboardCard(currentPeriod, currentOffice);
            });

            // Clear office filter
            $('#clear-office-filter').click(function() {
                currentOffice = '';
                loadDashboardCard(currentPeriod, currentOffice);
            });

            // Add the suggested code change
            $('.office-row').removeClass('selected');
            $(this).addClass('selected');
        });
    </script>
</body>

</html>