<?php
// staff/reports/overview.php - รายงานภาพรวม
if (!defined('SECURE_ACCESS')) exit('Direct access not allowed');

$data = $reportData;
?>

<!-- สรุปภาพรวม -->
<div class="report-section">
    <h2 class="section-title">📈 สรุปภาพรวม</h2>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($data['summary']['total_complaints']); ?></div>
            <div class="stat-label">ข้อร้องเรียนทั้งหมด</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($data['summary']['pending']); ?></div>
            <div class="stat-label">รอดำเนินการ</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($data['summary']['processing']); ?></div>
            <div class="stat-label">กำลังดำเนินการ</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($data['summary']['completed']); ?></div>
            <div class="stat-label">เสร็จสิ้น</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($data['summary']['evaluated']); ?></div>
            <div class="stat-label">ประเมินแล้ว</div>
        </div>
        <?php if ($data['summary']['spam'] > 0): ?>
            <div class="stat-card" style="border-left-color: #e53e3e;">
                <div class="stat-number"><?php echo number_format($data['summary']['spam']); ?></div>
                <div class="stat-label">สแปม</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- คำนวณเปอร์เซ็นต์ -->
    <?php
    $total = $data['summary']['total_complaints'];
    $successRate = $total > 0 ? round((($data['summary']['completed'] + $data['summary']['evaluated']) / $total) * 100, 1) : 0;
    ?>

    <div class="stats-grid">
        <div class="stat-card" style="border-left-color: #48bb78;">
            <div class="stat-number"><?php echo $successRate; ?>%</div>
            <div class="stat-label">อัตราความสำเร็จ</div>
        </div>
        <div class="stat-card" style="border-left-color: #ed8936;">
            <div class="stat-number"><?php echo $total > 0 ? round(($data['summary']['pending'] / $total) * 100, 1) : 0; ?>%</div>
            <div class="stat-label">ค้างดำเนินการ</div>
        </div>
    </div>
</div>

<!-- แนวโน้มรายวัน -->
<?php if (!empty($data['daily_trend'])): ?>
    <div class="report-section">
        <h2 class="section-title">📅 แนวโน้มรายวัน</h2>
        <div class="chart-container">
            <canvas id="dailyTrendChart"></canvas>
        </div>
    </div>
<?php endif; ?>

<!-- สถิติตามประเภท -->
<?php if (!empty($data['by_category'])): ?>
    <div class="report-section">
        <h2 class="section-title">📊 จำแนกตามประเภทข้อร้องเรียน</h2>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ประเภท</th>
                        <th>จำนวน</th>
                        <th>เปอร์เซ็นต์</th>
                        <th>คะแนนเฉลี่ย</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['by_category'] as $category): ?>
                        <tr>
                            <td>
                                <?php echo $category['Type_icon']; ?>
                                <?php echo htmlspecialchars($category['Type_infor']); ?>
                            </td>
                            <td><?php echo number_format($category['count']); ?></td>
                            <td><?php echo $total > 0 ? round(($category['count'] / $total) * 100, 1) : 0; ?>%</td>
                            <td>
                                <?php if ($category['avg_rating']): ?>
                                    <span style="color: <?php echo $category['avg_rating'] >= 4 ? '#48bb78' : ($category['avg_rating'] >= 3 ? '#ed8936' : '#e53e3e'); ?>">
                                        ⭐ <?php echo number_format($category['avg_rating'], 1); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #a0aec0;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- กราฟวงกลม -->
        <div class="chart-container">
            <canvas id="categoryChart"></canvas>
        </div>
    </div>
<?php endif; ?>

<!-- สถิติตามระดับความสำคัญ -->
<?php if (!empty($data['by_priority'])): ?>
    <div class="report-section">
        <h2 class="section-title">🚨 จำแนกตามระดับความสำคัญ</h2>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ระดับ</th>
                        <th>คำอธิบาย</th>
                        <th>จำนวน</th>
                        <th>เปอร์เซ็นต์</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $priorityLabels = [
                        '1' => ['label' => 'ปกติ', 'color' => '#48bb78', 'icon' => '🟢'],
                        '2' => ['label' => 'สำคัญ', 'color' => '#3182ce', 'icon' => '🔵'],
                        '3' => ['label' => 'เร่งด่วน', 'color' => '#ed8936', 'icon' => '🟡'],
                        '4' => ['label' => 'เร่งด่วนมาก', 'color' => '#e53e3e', 'icon' => '🔴'],
                        '5' => ['label' => 'วิกฤต/ฉุกเฉิน', 'color' => '#9f7aea', 'icon' => '🟣']
                    ];
                    ?>
                    <?php foreach ($data['by_priority'] as $priority): ?>
                        <tr>
                            <td style="color: <?php echo $priorityLabels[$priority['Re_level']]['color']; ?>">
                                <?php echo $priorityLabels[$priority['Re_level']]['icon']; ?>
                                <?php echo $priority['Re_level']; ?>
                            </td>
                            <td><?php echo $priorityLabels[$priority['Re_level']]['label']; ?></td>
                            <td><?php echo number_format($priority['count']); ?></td>
                            <td><?php echo $total > 0 ? round(($priority['count'] / $total) * 100, 1) : 0; ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- กราฟแท่ง -->
        <div class="chart-container">
            <canvas id="priorityChart"></canvas>
        </div>
    </div>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Daily Trend Chart
        <?php if (!empty($data['daily_trend'])): ?>
            const dailyCtx = document.getElementById('dailyTrendChart');
            if (dailyCtx) {
                new Chart(dailyCtx, {
                    type: 'line',
                    data: {
                        labels: [
                            <?php foreach ($data['daily_trend'] as $day): ?> '<?php echo date('j M', strtotime($day['date'])); ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            label: 'จำนวนข้อร้องเรียน',
                            data: [
                                <?php foreach ($data['daily_trend'] as $day): ?>
                                    <?php echo $day['count']; ?>,
                                <?php endforeach; ?>
                            ],
                            borderColor: 'rgb(102, 126, 234)',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            title: {
                                display: true,
                                text: 'แนวโน้มข้อร้องเรียนรายวัน'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'จำนวน (เรื่อง)'
                                }
                            }
                        }
                    }
                });
            }
        <?php endif; ?>

        // Category Chart
        <?php if (!empty($data['by_category'])): ?>
            const categoryCtx = document.getElementById('categoryChart');
            if (categoryCtx) {
                new Chart(categoryCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [
                            <?php foreach ($data['by_category'] as $category): ?> '<?php echo addslashes($category['Type_infor']); ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            data: [
                                <?php foreach ($data['by_category'] as $category): ?>
                                    <?php echo $category['count']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: [
                                'rgba(255, 99, 132, 0.8)',
                                'rgba(54, 162, 235, 0.8)',
                                'rgba(255, 205, 86, 0.8)',
                                'rgba(75, 192, 192, 0.8)',
                                'rgba(153, 102, 255, 0.8)',
                                'rgba(255, 159, 64, 0.8)',
                                'rgba(199, 199, 199, 0.8)',
                                'rgba(83, 102, 255, 0.8)'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            title: {
                                display: true,
                                text: 'การแจกแจงตามประเภทข้อร้องเรียน'
                            },
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
        <?php endif; ?>

        // Priority Chart
        <?php if (!empty($data['by_priority'])): ?>
            const priorityCtx = document.getElementById('priorityChart');
            if (priorityCtx) {
                new Chart(priorityCtx, {
                    type: 'bar',
                    data: {
                        labels: [
                            <?php foreach ($data['by_priority'] as $priority): ?> 'ระดับ <?php echo $priority['Re_level']; ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            label: 'จำนวนข้อร้องเรียน',
                            data: [
                                <?php foreach ($data['by_priority'] as $priority): ?>
                                    <?php echo $priority['count']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: [
                                <?php foreach ($data['by_priority'] as $priority): ?> '<?php
                                                                                        $colors = [
                                                                                            '1' => 'rgba(72, 187, 120, 0.8)',
                                                                                            '2' => 'rgba(49, 130, 206, 0.8)',
                                                                                            '3' => 'rgba(237, 137, 54, 0.8)',
                                                                                            '4' => 'rgba(229, 62, 62, 0.8)',
                                                                                            '5' => 'rgba(159, 122, 234, 0.8)'
                                                                                        ];
                                                                                        echo $colors[$priority['Re_level']] ?? 'rgba(160, 174, 192, 0.8)';
                                                                                        ?>',
                                <?php endforeach; ?>
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            title: {
                                display: true,
                                text: 'การแจกแจงตามระดับความสำคัญ'
                            },
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'จำนวน (เรื่อง)'
                                }
                            }
                        }
                    }
                });
            }
        <?php endif; ?>
    });
</script>