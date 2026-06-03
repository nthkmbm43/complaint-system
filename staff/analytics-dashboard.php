<?php
// analytics-dashboard.php - Real-time Analytics Dashboard
define('SECURE_ACCESS', true);

require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../config/database.php';

// ตรวจสอบการเข้าสู่ระบบและสิทธิ์
checkPagePermission('teacher', 1);

$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - <?php echo SITE_SHORT_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/date-fns@2.29.3/index.min.js"></script>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }

        .dashboard-header {
            background: var(--primary-gradient);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .dashboard-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-20px);
            }
        }

        .metric-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            border: none;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .metric-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }

        .metric-card.success::before {
            background: var(--success-gradient);
        }

        .metric-card.warning::before {
            background: var(--warning-gradient);
        }

        .metric-card.info::before {
            background: var(--info-gradient);
        }

        .metric-number {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .metric-label {
            color: #6c757d;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.875rem;
        }

        .change-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .change-indicator.positive {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }

        .change-indicator.negative {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .chart-container {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            position: relative;
        }

        .chart-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f8f9fa;
        }

        .chart-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
        }

        .time-filter {
            display: flex;
            gap: 0.5rem;
        }

        .time-filter button {
            padding: 0.5rem 1rem;
            border: 2px solid #e9ecef;
            background: white;
            border-radius: 25px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .time-filter button.active {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }

        .realtime-updates {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            max-height: 400px;
            overflow-y: auto;
        }

        .update-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 0.75rem;
            transition: background 0.3s ease;
        }

        .update-item:hover {
            background: #f8f9fa;
        }

        .update-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }

        .update-icon.new {
            background: var(--info-gradient);
        }

        .update-icon.response {
            background: var(--success-gradient);
        }

        .update-icon.evaluation {
            background: var(--warning-gradient);
        }

        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #28a745;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .live-dot {
            width: 8px;
            height: 8px;
            background: #28a745;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }

            100% {
                opacity: 1;
            }
        }

        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 768px) {
            .metric-number {
                font-size: 2rem;
            }

            .time-filter {
                flex-wrap: wrap;
            }

            .chart-header {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>

    <style>
        /* Global Hide scrollbar */
        ::-webkit-scrollbar { display: none; }
        html { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>

<body>
    <!-- Navigation -->
    <?php include '../includes/teacher_nav.php'; ?>

    <div class="container-fluid">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1><i class="fas fa-chart-line me-3"></i>Analytics Dashboard</h1>
                        <p class="mb-0">ติดตามประสิทธิภาพระบบแบบเรียลไทม์</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="live-indicator">
                            <div class="live-dot"></div>
                            <span>Live Updates</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container">
            <!-- Quick Stats -->
            <div class="row mb-4" id="quickStats">
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="metric-card">
                        <div class="metric-number" id="todayRequests">-</div>
                        <div class="metric-label">ข้อร้องเรียนวันนี้</div>
                        <div class="change-indicator" id="todayRequestsChange"></div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="metric-card success">
                        <div class="metric-number" id="todayResponses">-</div>
                        <div class="metric-label">การตอบกลับวันนี้</div>
                        <div class="change-indicator" id="todayResponsesChange"></div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="metric-card warning">
                        <div class="metric-number" id="avgRating">-</div>
                        <div class="metric-label">คะแนนเฉลี่ยเดือนนี้</div>
                        <div class="change-indicator" id="avgRatingChange"></div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="metric-card info">
                        <div class="metric-number" id="successRate">-</div>
                        <div class="metric-label">อัตราความสำเร็จ</div>
                        <div class="change-indicator" id="successRateChange"></div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row">
                <div class="col-lg-8 mb-4">
                    <div class="chart-container">
                        <div class="chart-header">
                            <h4 class="chart-title">แนวโน้มข้อร้องเรียน</h4>
                            <div class="time-filter">
                                <button onclick="changePeriod('7')" class="active" data-period="7">7 วัน</button>
                                <button onclick="changePeriod('30')" data-period="30">30 วัน</button>
                                <button onclick="changePeriod('90')" data-period="90">90 วัน</button>
                                <button onclick="changePeriod('365')" data-period="365">1 ปี</button>
                            </div>
                        </div>
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
                <div class="col-lg-4 mb-4">
                    <div class="chart-container">
                        <div class="chart-header">
                            <h4 class="chart-title">สัดส่วนสถานะ</h4>
                        </div>
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Second Row -->
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="chart-container">
                        <div class="chart-header">
                            <h4 class="chart-title">ประเภทข้อร้องเรียน</h4>
                        </div>
                        <canvas id="typeChart"></canvas>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="chart-container">
                        <div class="chart-header">
                            <h4 class="chart-title">ความพึงพอใจรายเดือน</h4>
                        </div>
                        <canvas id="satisfactionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Performance Metrics -->
            <div class="row">
                <div class="col-lg-8 mb-4">
                    <div class="chart-container">
                        <div class="chart-header">
                            <h4 class="chart-title">ประสิทธิภาพการตอบสนอง</h4>
                        </div>
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>
                <div class="col-lg-4 mb-4">
                    <div class="realtime-updates">
                        <h5 class="mb-3">
                            <i class="fas fa-clock me-2"></i>กิจกรรมล่าสุด
                            <span class="loading-spinner ms-2" id="updatesLoader" style="display: none;"></span>
                        </h5>
                        <div id="realtimeUpdates">
                            <!-- Updates will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global chart instances
        let trendChart, statusChart, typeChart, satisfactionChart, performanceChart;
        let currentPeriod = '7';

        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            initializeDashboard();

            // Auto-refresh every 30 seconds
            setInterval(refreshDashboard, 30000);
        });

        async function initializeDashboard() {
            try {
                await loadDashboardStats();
                await loadTrendChart(currentPeriod);
                await loadStatusChart();
                await loadTypeChart();
                await loadSatisfactionChart();
                await loadPerformanceChart();
                await loadRealtimeUpdates();
            } catch (error) {
                console.error('Dashboard initialization error:', error);
            }
        }

        async function loadDashboardStats() {
            try {
                const response = await fetch('reports-ajax.php?action=get_dashboard_stats');
                const data = await response.json();

                document.getElementById('todayRequests').textContent = data.today.new_requests;
                document.getElementById('todayResponses').textContent = data.today.responses;
                document.getElementById('avgRating').textContent = parseFloat(data.this_month.avg_rating).toFixed(1);
                document.getElementById('successRate').textContent = data.this_year.success_rate + '%';

                // Update change indicators (mock data for demo)
                updateChangeIndicator('todayRequestsChange', +15);
                updateChangeIndicator('todayResponsesChange', +8);
                updateChangeIndicator('avgRatingChange', +0.2);
                updateChangeIndicator('successRateChange', +5.1);

            } catch (error) {
                console.error('Error loading dashboard stats:', error);
            }
        }

        async function loadTrendChart(period) {
            try {
                const response = await fetch(`reports-ajax.php?action=get_trend_data&period=${period}`);
                const data = await response.json();

                const ctx = document.getElementById('trendChart').getContext('2d');

                if (trendChart) {
                    trendChart.destroy();
                }

                trendChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                                label: 'ข้อร้องเรียนใหม่',
                                data: data.requests,
                                borderColor: '#667eea',
                                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                tension: 0.4,
                                fill: true
                            },
                            {
                                label: 'เสร็จสิ้น',
                                data: data.completed,
                                borderColor: '#28a745',
                                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                                tension: 0.4,
                                fill: true
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0,0,0,0.05)'
                                }
                            },
                            x: {
                                grid: {
                                    color: 'rgba(0,0,0,0.05)'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'top'
                            }
                        }
                    }
                });
            } catch (error) {
                console.error('Error loading trend chart:', error);
            }
        }

        async function loadStatusChart() {
            try {
                const response = await fetch('reports-ajax.php?action=get_dashboard_stats');
                const data = await response.json();

                const ctx = document.getElementById('statusChart').getContext('2d');

                if (statusChart) {
                    statusChart.destroy();
                }

                statusChart = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['รอดำเนินการ', 'กำลังดำเนินการ', 'เสร็จสิ้น'],
                        datasets: [{
                            data: [
                                data.this_month.pending,
                                data.this_month.total_requests - data.this_month.pending - data.this_month.completed,
                                data.this_month.completed
                            ],
                            backgroundColor: [
                                '#ffc107',
                                '#17a2b8',
                                '#28a745'
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            } catch (error) {
                console.error('Error loading status chart:', error);
            }
        }

        async function loadTypeChart() {
            try {
                const startDate = new Date();
                startDate.setMonth(startDate.getMonth() - 1);
                const endDate = new Date();

                const response = await fetch(`reports-ajax.php?action=get_type_analytics&start_date=${startDate.toISOString().split('T')[0]}&end_date=${endDate.toISOString().split('T')[0]}`);
                const data = await response.json();

                const ctx = document.getElementById('typeChart').getContext('2d');

                if (typeChart) {
                    typeChart.destroy();
                }

                typeChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.map(item => item.Type_infor),
                        datasets: [{
                            label: 'จำนวนข้อร้องเรียน',
                            data: data.map(item => item.total),
                            backgroundColor: '#667eea',
                            borderRadius: 5
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0,0,0,0.05)'
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            } catch (error) {
                console.error('Error loading type chart:', error);
            }
        }

        async function loadSatisfactionChart() {
            try {
                const response = await fetch('reports-ajax.php?action=get_satisfaction_trends&months=12');
                const data = await response.json();

                const ctx = document.getElementById('satisfactionChart').getContext('2d');

                if (satisfactionChart) {
                    satisfactionChart.destroy();
                }

                satisfactionChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.map(item => {
                            const date = new Date(item.month + '-01');
                            return date.toLocaleDateString('th-TH', {
                                month: 'short',
                                year: '2-digit'
                            });
                        }),
                        datasets: [{
                            label: 'คะแนนเฉลี่ย',
                            data: data.map(item => item.avg_score),
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 5,
                                grid: {
                                    color: 'rgba(0,0,0,0.05)'
                                }
                            },
                            x: {
                                grid: {
                                    color: 'rgba(0,0,0,0.05)'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            } catch (error) {
                console.error('Error loading satisfaction chart:', error);
            }
        }

        async function loadPerformanceChart() {
            try {
                const startDate = new Date();
                startDate.setMonth(startDate.getMonth() - 1);
                const endDate = new Date();

                const response = await fetch(`reports-ajax.php?action=get_performance_metrics&start_date=${startDate.toISOString().split('T')[0]}&end_date=${endDate.toISOString().split('T')[0]}`);
                const data = await response.json();

                const ctx = document.getElementById('performanceChart').getContext('2d');

                if (performanceChart) {
                    performanceChart.destroy();
                }

                performanceChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.response_time_distribution.map(item => item.time_range),
                        datasets: [{
                            label: 'จำนวนข้อร้องเรียน',
                            data: data.response_time_distribution.map(item => item.count),
                            backgroundColor: [
                                '#28a745',
                                '#17a2b8',
                                '#ffc107',
                                '#dc3545'
                            ],
                            borderRadius: 5
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0,0,0,0.05)'
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            } catch (error) {
                console.error('Error loading performance chart:', error);
            }
        }

        async function loadRealtimeUpdates() {
            try {
                document.getElementById('updatesLoader').style.display = 'inline-block';

                const response = await fetch('reports-ajax.php?action=get_realtime_updates');
                const data = await response.json();

                let html = '';

                // Recent requests
                data.recent_requests.forEach(request => {
                    html += `
                        <div class="update-item">
                            <div class="update-icon new">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div>
                                <strong>ข้อร้องเรียนใหม่ #${request.Re_id}</strong><br>
                                <small class="text-muted">${request.Type_infor}</small>
                            </div>
                        </div>
                    `;
                });

                // Recent responses
                data.recent_responses.forEach(response => {
                    html += `
                        <div class="update-item">
                            <div class="update-icon response">
                                <i class="fas fa-reply"></i>
                            </div>
                            <div>
                                <strong>ตอบกลับ #${response.Re_id}</strong><br>
                                <small class="text-muted">โดย ${response.Aj_name}</small>
                            </div>
                        </div>
                    `;
                });

                // Recent evaluations
                data.recent_evaluations.forEach(evaluation => {
                    html += `
                        <div class="update-item">
                            <div class="update-icon evaluation">
                                <i class="fas fa-star"></i>
                            </div>
                            <div>
                                <strong>ประเมิน #${evaluation.Re_id}</strong><br>
                                <small class="text-muted">${evaluation.Eva_score}/5 ดาว</small>
                            </div>
                        </div>
                    `;
                });

                document.getElementById('realtimeUpdates').innerHTML = html;
                document.getElementById('updatesLoader').style.display = 'none';
            } catch (error) {
                console.error('Error loading realtime updates:', error);
                document.getElementById('updatesLoader').style.display = 'none';
            }
        }

        function changePeriod(period) {
            currentPeriod = period;

            // Update active button
            document.querySelectorAll('.time-filter button').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`[data-period="${period}"]`).classList.add('active');

            // Reload trend chart
            loadTrendChart(period);
        }

        function updateChangeIndicator(elementId, change) {
            const element = document.getElementById(elementId);
            const isPositive = change >= 0;

            element.className = `change-indicator ${isPositive ? 'positive' : 'negative'}`;
            element.innerHTML = `
                <i class="fas fa-arrow-${isPositive ? 'up' : 'down'}"></i>
                ${Math.abs(change)}%
            `;
        }

        async function refreshDashboard() {
            try {
                await loadDashboardStats();
                await loadRealtimeUpdates();
            } catch (error) {
                console.error('Error refreshing dashboard:', error);
            }
        }
    </script>
</body>

</html>