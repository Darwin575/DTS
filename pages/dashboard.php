<?php
require_once __DIR__ . '/../server-logic/config/db.php';
require_once __DIR__ . '/../server-logic/config/session_init.php';
require_once __DIR__ . '/../server-logic/config/require_login.php';

include __DIR__ . '/../server-logic/reports/summary.php';
include '../layouts/header.php';

$user_id = SessionManager::get('user')['id'];
$today = new DateTime();

// Fetch TOTAL DOCUMENTS (all time, not period-based)
$totalQuery = $conn->prepare("
    SELECT 
        SUM(status = 'active') AS total_on_route,
        SUM(status = 'archived') AS total_completed 
    FROM tbl_documents 
    WHERE user_id = ?
");
$totalQuery->bind_param("i", $user_id);
$totalQuery->execute();
$totalResult = $totalQuery->get_result();
$totalRow = $totalResult->fetch_assoc();

$total_on_route = $totalRow['total_on_route'] ?? 0;
$total_completed = $totalRow['total_completed'] ?? 0;
$completion_percentage = ($total_on_route + $total_completed) > 0
    ? round(($total_completed / ($total_on_route + $total_completed)) * 100)
    : 0;

// Upsert summaries for each type
foreach (['daily', 'weekly', 'monthly', 'yearly'] as $type) {
    upsertDocumentSummary($conn, $user_id, $type, clone $today);
}

// Calculate current summary dates for each type
$summaryDates = [];
foreach (['daily', 'weekly', 'monthly', 'yearly'] as $type) {
    $asOf = clone $today;
    switch ($type) {
        case 'daily':
            $summaryDate = $asOf->format('Y-m-d');
            break;
        case 'weekly':
            $startOfWeek = $asOf->modify('monday this week');
            $endOfWeek = (clone $startOfWeek)->modify('sunday this week')->setTime(23, 59, 59);
            $summaryDate = $startOfWeek->format('Y-m-d') . '_to_' . $endOfWeek->format('Y-m-d');
            break;
        case 'monthly':
            $startOfMonth = $asOf->modify('first day of this month');
            $summaryDate = $startOfMonth->format('Y-m') . '_monthly';
            break;
        case 'yearly':
            $startOfYear = $asOf->setDate((int)$asOf->format('Y'), 1, 1);
            $summaryDate = $startOfYear->format('Y') . '_yearly';
            break;
    }
    $summaryDates[$type] = $summaryDate;
}

// Fetch current summaries
$query = $conn->prepare("
    SELECT summary_type, on_route_documents as on_route, completed_documents as completed 
    FROM tbl_document_summary 
    WHERE user_id = ? 
        AND (
            (summary_type = 'daily' AND summary_date = ?)
            OR (summary_type = 'weekly' AND summary_date = ?)
            OR (summary_type = 'monthly' AND summary_date = ?)
            OR (summary_type = 'yearly' AND summary_date = ?)
        )
");
$query->bind_param(
    "issss",
    $user_id,
    $summaryDates['daily'],
    $summaryDates['weekly'],
    $summaryDates['monthly'],
    $summaryDates['yearly']
);
$query->execute();
$result = $query->get_result();

// Initialize summary data with zeros
$summary_data = [
    'daily' => ['on_route' => 0, 'completed' => 0],
    'weekly' => ['on_route' => 0, 'completed' => 0],
    'monthly' => ['on_route' => 0, 'completed' => 0],
    'yearly' => ['on_route' => 0, 'completed' => 0]
];

while ($row = $result->fetch_assoc()) {
    $type = $row['summary_type'];
    if (isset($summary_data[$type])) {
        $summary_data[$type]['on_route'] = $row['on_route'];
        $summary_data[$type]['completed'] = $row['completed'];
    }
}



?>


<style>
    :root {

        --admin: #3498db;

    }

    .metric-card,
    .progress-container,
    .chart-container-wrapper {
        background: #fff;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .metric-card {
        transition: .3s;
    }

    .chart-container-wrapper :hover {
        transform: translateY(-5px);
    }

    .metric-card:hover {

        transform: translateY(-5px);
    }

    .progress-bar {
        height: 10px;
        background: #e0e0e0;
        border-radius: 5px;
        overflow: hidden;
    }

    .progress-bar-fill {
        height: 100%;
        background: #1ab394;
        transition: width .6s;
    }

    .stat-label,
    .metric-label {
        color: #666;
        font-size: .9em;
    }

    .badge {
        font-size: 0.8em;
        padding: 5px 10px;
        padding-top: 7px;
        border-radius: 5px;
    }

    .side-style {
        border-left: 4px solid var(--admin);
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
            <div class="container-fluid">
                <div class="side-style progress-container">
                    <div class=" d-flex justify-content-between mb-3">
                        <h4>Document Progress</h4>
                        <span class="badge bg-success"><?= $completion_percentage ?>% Complete</span>
                    </div>
                    <div class="progress-bar mb-3">
                        <div class="progress-bar-fill" style="width: <?= $completion_percentage ?>%"></div>
                    </div>
                    <div class="row text-center">
                        <div class="col-md-4">
                            <div class="stat-value"><?= $total_completed ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-value"><?= $total_on_route ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-value"><?= $total_completed + $total_on_route ?></div>
                            <div class="stat-label">Total Documents</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <?php
                    $colors = ['daily' => '', 'weekly' => '#9b59b6', 'monthly' => '', 'yearly' => '#f39c12'];
                    foreach ($summary_data as $type => $data): ?>
                        <div class="col-md-6 col-lg-3">
                            <div class="metric-card side-style">
                                <div class="text-center mb-2"> <!-- Changed to text-center -->
                                    <h5 class="mb-0 text-capitalize"><?= $type ?></h5>
                                    <!-- Removed the duplicate badge element -->
                                </div>
                                <div class="d-flex justify-content-between">
                                    <div class="text-center">
                                        <div class="metric-value"><?= $data['on_route'] ?></div>
                                        <div class="metric-label">On Route</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="metric-value"><?= $data['completed'] ?></div>
                                        <div class="metric-label">Completed</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="side-style chart-container-wrapper">
                    <div class="d-flex justify-content-between mb-4">
                        <h4 class="mb-0">Document Flow</h4>
                        <div class="btn-group btn-group-sm">
                            <?php foreach (array_keys($summary_data) as $type): ?>
                                <button class="btn btn-outline-primary" onclick="updateChart('<?= $type ?>')">
                                    <?= ucfirst($type) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div style="height: 300px;"><canvas id="documentFlowChart"></canvas></div>
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

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const summaryData = <?= json_encode($summary_data); ?>;
        let chart;

        function initChart() {
            const ctx = document.getElementById('documentFlowChart').getContext('2d');
            chart = new Chart(ctx, {
                type: 'bar',
                data: getChartData('daily'),
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false // hides the legend
                        }
                    }
                }
            });
        }



        function getChartData(type) {
            return {
                labels: ['On Route', 'Completed'],
                datasets: [{
                    data: [summaryData[type].on_route, summaryData[type].completed],
                    backgroundColor: ['#3498db', '#1abc9c']
                }]
            };
        }

        function updateChart(type) {
            chart.data = getChartData(type);
            chart.update();
        }

        window.onload = initChart;
    </script>