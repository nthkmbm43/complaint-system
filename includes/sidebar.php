<?php
if (!defined('SECURE_ACCESS')) {
    exit('Direct access not allowed');
}

$user = getCurrentUser();
$currentRole = $_SESSION['user_role'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Define menu items based on role
$menuItems = [];

// ตรวจสอบ permission level สำหรับ teacher
// 1=อาจารย์, 2=ผู้ดำเนินการ, 3=ผู้ดูแลระบบ
$isAdmin = false;
$isSuperAdmin = false;
if ($currentRole === 'teacher' && isset($_SESSION['permission'])) {
    $isSuperAdmin = ($_SESSION['permission'] == 3); // ผู้ดูแลระบบ (จัดการข้อมูลพื้นฐาน)
    $isAdmin = ($_SESSION['permission'] >= 2);       // ผู้ดำเนินการ + ผู้ดูแลระบบ
}

if ($currentRole === 'student') {
    // Student menu (คงเดิม ไม่แตะต้อง)
    $menuItems = [
        [
            'id' => 'index',
            'icon' => '🏠',
            'text' => 'หน้าหลัก',
            'url' => 'index.php',
            'active' => $currentPage === 'index',
            'required_role' => 'student'
        ],
        [
            'id' => 'complaint',
            'icon' => '📝',
            'text' => 'ส่งข้อร้องเรียน',
            'url' => 'complaint.php',
            'active' => $currentPage === 'complaint' || $currentPage === 'edit_complaint',
            'required_role' => 'student'
        ],
        [
            'id' => 'tracking',
            'icon' => '📋',
            'text' => 'ติดตามสถานะ',
            'url' => 'tracking.php',
            'active' => $currentPage === 'tracking',
            'required_role' => 'student'
        ],
        [
            'id' => 'evaluation',
            'icon' => '⭐',
            'text' => 'ประเมินความพึงพอใจ',
            'url' => 'evaluation.php',
            'active' => $currentPage === 'evaluation',
            'required_role' => 'student'
        ],
        [
            'id' => 'profile',
            'icon' => '👤',
            'text' => 'ข้อมูลส่วนตัว',
            'url' => 'profile.php',
            'active' => $currentPage === 'profile',
            'required_role' => 'student'
        ],
        [
            'id' => 'separator',
            'type' => 'separator'
        ],
        [
            'id' => 'logout',
            'icon' => '🚪',
            'text' => 'ออกจากระบบ',
            'type' => 'logout',
            'active' => false
        ]
    ];
} elseif ($currentRole === 'teacher' && !$isAdmin) {
    $menuItems = [
        [
            'id' => 'index',
            'icon' => '🏠',
            'text' => 'หน้าหลัก',
            'url' => 'index.php',
            'active' => $currentPage === 'index',
            'required_role' => 'teacher'
        ],
        [
            'id' => 'my-assignments',
            'icon' => '📥',
            'text' => 'บันทึกผลการดำเนินงาน',
            'url' => 'my-assignments.php',
            'active' => $currentPage === 'my-assignments' || $currentPage === 'complaint-detail',
            'required_role' => 'teacher'
        ],
        [
            'id' => 'users',
            'icon' => '👨‍🎓',
            'text' => 'จัดการข้อมูลนักศึกษา',
            'url' => 'users.php',
            'active' => $currentPage === 'users',
            'required_role' => 'teacher'
        ],
        [
            'id' => 'reports',
            'icon' => '📊',
            'text' => 'รายงานทั่วไป',
            'url' => 'reports.php',
            'active' => $currentPage === 'reports',
            'required_role' => 'teacher'
        ],
        [
            'id' => 'separator',
            'type' => 'separator'
        ],
        [
            'id' => 'logout',
            'icon' => '🚪',
            'text' => 'ออกจากระบบ',
            'type' => 'logout',
            'active' => false
        ]
    ];
} elseif ($currentRole === 'teacher' && $isSuperAdmin) {
    // ========== สิทธิ์ 3: ผู้ดูแลระบบ ==========
    // แสดงเฉพาะเมนูข้อมูลพื้นฐาน (แต่เข้าถึงได้ทุกไฟล์)
    $menuItems = [
        [
            'id' => 'dashboard',
            'icon' => '📊',
            'text' => 'แดชบอร์ด',
            'url' => 'dashboard.php',
            'active' => $currentPage === 'dashboard',
            'required_role' => 'teacher',
            'required_permission' => 3
        ],
        [
            'id' => 'complaint-types',
            'icon' => '📁',
            'text' => 'ประเภทข้อร้องเรียน',
            'url' => 'complaint-types.php',
            'active' => $currentPage === 'complaint-types',
            'required_role' => 'teacher',
            'required_permission' => 3
        ],
        [
            'id' => 'organization-management',
            'icon' => '🏛️',
            'text' => 'ข้อมูลหน่วยงาน/องค์กร',
            'url' => 'organization-management.php',
            'active' => $currentPage === 'organization-management',
            'required_role' => 'teacher',
            'required_permission' => 3
        ],
        [
            'id' => 'users-student',
            'icon' => '👨‍🎓',
            'text' => 'จัดการข้อมูลนักศึกษา',
            'url' => 'users.php',
            'active' => $currentPage === 'users',
            'required_role' => 'teacher',
            'required_permission' => 3
        ],
        [
            'id' => 'users-teacher',
            'icon' => '👨‍🏫',
            'text' => 'จัดการข้อมูลอาจารย์',
            'url' => 'user.php',
            'active' => $currentPage === 'user',
            'required_role' => 'teacher',
            'required_permission' => 3
        ],
        [
            'id' => 'reports',
            'icon' => '📊',
            'text' => 'รายงานทั่วไป',
            'url' => 'reports.php',
            'active' => $currentPage === 'reports',
            'required_role' => 'teacher',
            'required_permission' => 3
        ],
        ['id' => 'separator', 'type' => 'separator'],
        [
            'id' => 'logout',
            'icon' => '🚪',
            'text' => 'ออกจากระบบ',
            'type' => 'logout',
            'active' => false
        ]
    ];
} elseif ($currentRole === 'teacher' && $isAdmin) {
    // ========== สิทธิ์ 2: ผู้ดำเนินการ ==========
    // จัดการข้อร้องเรียน มอบหมายงาน ข้อมูลนักศึกษา รายงาน
    // ไม่แสดง: ประเภทข้อร้องเรียน, หน่วยงาน, ข้อมูลอาจารย์
    $menuItems = [
        [
            'id' => 'dashboard',
            'icon' => '🏠',
            'text' => 'หน้าหลัก',
            'url' => 'index.php',
            'active' => $currentPage === 'index' || $currentPage === 'dashboard',
            'required_role' => 'teacher'
        ],
        [
            'id' => 'manage-complaints',
            'icon' => '📋',
            'text' => 'จัดการข้อร้องเรียน',
            'url' => 'manage-complaints.php',
            'active' => $currentPage === 'manage-complaints' || $currentPage === 'complaint-detail',
            'required_role' => 'teacher',
            'required_permission' => 2
        ],
        [
            'id' => 'priority-management',
            'icon' => '🎯',
            'text' => 'จัดการความสำคัญ',
            'url' => 'priority-management.php',
            'active' => $currentPage === 'priority-management',
            'required_role' => 'teacher'
            // ลบ required_permission 2 ออก เพื่อให้ Level 1 เข้าได้
        ],
        [
            'id' => 'assign-complaint',
            'icon' => '📤',
            'text' => 'บันทึกจัดการข้อร้องเรียน',
            'url' => 'assign-complaint.php',
            'active' => $currentPage === 'assign-complaint',
            'required_role' => 'teacher',
            'required_permission' => 2
        ],
        [
            'id' => 'users',
            'icon' => '👨‍🎓',
            'text' => 'จัดการข้อมูลนักศึกษา',
            'url' => 'users.php',
            'active' => $currentPage === 'users',
            'required_role' => 'teacher',
            'required_permission' => 2
        ],
        [
            'id' => 'reports',
            'icon' => '📊',
            'text' => 'รายงานทั่วไป',
            'url' => 'reports.php',
            'active' => $currentPage === 'reports',
            'required_role' => 'teacher',
            'required_permission' => 2
        ],
        ['id' => 'separator', 'type' => 'separator'],
        [
            'id' => 'logout',
            'icon' => '🚪',
            'text' => 'ออกจากระบบ',
            'type' => 'logout',
            'active' => false
        ]
    ];

}

// ไม่ใช้ notifications count เนื่องจากไม่มีในฐานข้อมูลใหม่
$unreadCount = 0;
?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">🎓</div>
        <div class="sidebar-title">มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน</div>
        <div class="sidebar-subtitle">วิทยาเขตขอนแก่น</div>
    </div>

    <?php if (isLoggedIn() && $user): ?>
        <div class="sidebar-user show">
            <div class="sidebar-user-avatar">
                <?php
                if ($currentRole === 'teacher') {
                    echo $isAdmin ? '👨‍💼' : '👨‍🏫';
                } else {
                    echo '👨‍🎓';
                }
                ?>
            </div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name">
                    <?php
                    if ($currentRole === 'teacher') {
                        echo htmlspecialchars($user['Aj_name'] ?? 'ไม่ระบุชื่อ');
                    } elseif ($currentRole === 'student') {
                        echo htmlspecialchars($user['Stu_name'] ?? 'ไม่ระบุชื่อ');
                    } else {
                        echo 'ผู้ใช้';
                    }
                    ?>
                </div>
                <div class="sidebar-user-role">
                    <?php
                    if ($currentRole === 'teacher') {
                        // แสดงชื่อตามระดับสิทธิ์ 1=อาจารย์, 2=ผู้ดำเนินการ, 3=ผู้ดูแลระบบ
                        $perm = $_SESSION['permission'] ?? 1;
                        if ($perm == 3) echo 'ผู้ดูแลระบบ';
                        elseif ($perm == 2) echo 'ผู้ดำเนินการ';
                        else echo 'เจ้าหน้าที่';
                    } elseif ($currentRole === 'student') {
                        echo 'นักศึกษา';
                    } else {
                        echo 'ผู้ใช้';
                    }
                    ?>
                </div>
                <?php if ($currentRole === 'student'): ?>
                    <div class="sidebar-user-id"><?php echo htmlspecialchars($user['Stu_id']); ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="sidebar-menu">
        <?php foreach ($menuItems as $item): ?>
            <?php if (isset($item['type']) && $item['type'] === 'separator'): ?>
                <div class="sidebar-menu-separator"></div>
            <?php elseif (isset($item['type']) && $item['type'] === 'logout'): ?>
                <button class="sidebar-menu-item logout-button" onclick="return confirmLogout()">
                    <span class="menu-icon"><?php echo $item['icon']; ?></span>
                    <span class="menu-text"><?php echo $item['text']; ?></span>
                </button>
            <?php else: ?>
                <a href="javascript:void(0)"
                    class="sidebar-menu-item <?php echo $item['active'] ? 'active' : ''; ?>"
                    onclick="navigateWithAccessControl('<?php echo $item['url']; ?>', '<?php echo $item['required_role'] ?? ''; ?>', <?php echo $item['required_permission'] ?? 0; ?>)">
                    <span class="menu-icon"><?php echo $item['icon']; ?></span>
                    <span class="menu-text"><?php echo $item['text']; ?></span>
                    <?php if ($item['active']): ?>
                        <span class="menu-indicator"></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<div class="logout-overlay" id="logoutModal">
    <div class="logout-box">
        <div class="logout-icon">
            🚪
        </div>
        <div class="logout-header">
            <h3>ยืนยันการออกจากระบบ</h3>
            <p>คุณต้องการจบการทำงานในเซสชั่นนี้ใช่หรือไม่?</p>
        </div>
        <div class="logout-actions">
            <button class="btn-cancel" onclick="closeLogoutModal()">
                ยกเลิก
            </button>
            <button class="btn-confirm" onclick="performLogout()">
                <span class="btn-text">ใช่, ออกจากระบบ</span>
                <span class="btn-icon">➜</span>
            </button>
        </div>
    </div>
</div>
<style>
    /* CSS Styles เดิม */
    .sidebar {
        position: fixed;
        left: -300px;
        top: 70px;
        width: 300px;
        height: calc(100vh - 70px);
        background: linear-gradient(180deg, #1e3c72 0%, #2a5298 100%);
        z-index: 990;
        transition: all 0.3s ease;
        overflow-y: auto;
        box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
    }

    .sidebar.show {
        left: 0;
    }

    .sidebar-header {
        padding: 25px 20px;
        text-align: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .sidebar-logo {
        width: 60px;
        height: 60px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        margin: 0 auto 15px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    .sidebar-title {
        color: white;
        font-size: 14px;
        font-weight: bold;
        margin-bottom: 5px;
        line-height: 1.3;
    }

    .sidebar-subtitle {
        color: rgba(255, 255, 255, 0.8);
        font-size: 12px;
        line-height: 1.2;
    }

    .sidebar-user {
        padding: 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        display: flex;
        align-items: center;
        gap: 15px;
        display: none;
    }

    .sidebar-user.show {
        display: flex;
    }

    .sidebar-user-avatar {
        width: 50px;
        height: 50px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        flex-shrink: 0;
    }

    .sidebar-user-info {
        flex: 1;
        min-width: 0;
    }

    .sidebar-user-name {
        color: white;
        font-size: 14px;
        font-weight: bold;
        margin-bottom: 3px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sidebar-user-role {
        color: rgba(255, 255, 255, 0.7);
        font-size: 12px;
        margin-bottom: 2px;
    }

    .sidebar-user-id {
        color: rgba(255, 255, 255, 0.6);
        font-size: 11px;
    }

    .sidebar-menu {
        padding: 10px 0;
    }

    .sidebar-menu-item {
        display: block;
        width: calc(100% - 20px);
        margin: 2px 10px;
        padding: 12px 15px;
        color: rgba(255, 255, 255, 0.8);
        text-decoration: none;
        border-radius: 10px;
        transition: all 0.3s ease;
        border: none;
        background: none;
        text-align: left;
        font-size: 14px;
        cursor: pointer;
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
    }

    .sidebar-menu-item::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        width: 0;
        background: rgba(255, 255, 255, 0.1);
        transition: all 0.3s ease;
        z-index: 0;
    }

    .sidebar-menu-item:hover::before,
    .sidebar-menu-item.active::before {
        width: 100%;
    }

    .sidebar-menu-item:hover,
    .sidebar-menu-item.active {
        color: white;
        background: rgba(255, 255, 255, 0.1);
        transform: translateX(5px);
    }

    .sidebar-menu-item.active {
        background: rgba(255, 255, 255, 0.15);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }

    .sidebar-menu-item .menu-icon {
        display: inline-block;
        width: 25px;
        margin-right: 12px;
        text-align: center;
        font-size: 1.1rem;
        position: relative;
        z-index: 1;
    }

    .sidebar-menu-item .menu-text {
        flex: 1;
        font-size: 14px;
        font-weight: 500;
        position: relative;
        z-index: 1;
    }

    .menu-indicator {
        position: absolute;
        right: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 0;
        height: 0;
        border-left: 8px solid white;
        border-top: 5px solid transparent;
        border-bottom: 5px solid transparent;
        z-index: 1;
    }

    /* Menu Separator */
    .sidebar-menu-separator {
        height: 1px;
        background: rgba(255, 255, 255, 0.2);
        margin: 15px 25px;
        position: relative;
    }

    .sidebar-menu-separator::before {
        content: '';
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
        width: 30px;
        height: 1px;
        background: rgba(255, 255, 255, 0.4);
    }

    /* Logout Button Styles */
    .sidebar-menu-item.logout-button {
        margin-top: 5px;
        background: rgba(220, 53, 69, 0.1);
        border: 1px solid rgba(220, 53, 69, 0.3);
        border-radius: 8px;
        margin-left: 15px;
        margin-right: 15px;
        width: calc(100% - 30px);
        color: rgba(255, 255, 255, 0.9);
        font-weight: 500;
    }

    .sidebar-menu-item.logout-button:hover {
        background: rgba(220, 53, 69, 0.2);
        border-color: rgba(220, 53, 69, 0.5);
        color: white;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
    }

    .sidebar-menu-item.logout-button:hover::before {
        background: rgba(220, 53, 69, 0.1);
    }

    .sidebar-menu-item.logout-button .menu-icon {
        color: #ff6b6b;
    }

    /* Sidebar Overlay - Enhanced */
    .sidebar-overlay {
        position: fixed;
        top: 70px;
        left: 0;
        width: 100%;
        height: calc(100vh - 70px);
        background: rgba(0, 0, 0, 0.5);
        z-index: 989;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .sidebar-overlay.show {
        opacity: 1;
        visibility: visible;
    }

    /* Main Content */
    .main-content {
        margin-left: 0;
        transition: margin-left 0.3s ease;
        min-height: calc(100vh - 70px);
    }

    .main-content.shifted {
        margin-left: 300px;
    }

    /* Visual indicator for clickable area */
    .sidebar-overlay:hover {
        background: rgba(0, 0, 0, 0.6);
    }

    /* ===== SweetAlert-style Access Denied ===== */
    .ad-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(6px);
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .ad-overlay.show {
        opacity: 1;
        visibility: visible;
    }

    .ad-box {
        background: white;
        border-radius: 24px;
        padding: 36px 32px;
        width: 90%;
        max-width: 380px;
        text-align: center;
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.25);
        transform: scale(0.85) translateY(20px);
        transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        font-family: 'Kanit', sans-serif;
    }

    .ad-overlay.show .ad-box {
        transform: scale(1) translateY(0);
    }

    .ad-icon-wrap {
        width: 72px;
        height: 72px;
        background: #fee2e2;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        margin: 0 auto 16px;
        animation: adPulse 2s ease-in-out infinite;
    }

    @keyframes adPulse {

        0%,
        100% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.3);
        }

        50% {
            box-shadow: 0 0 0 12px rgba(239, 68, 68, 0);
        }
    }

    .ad-title {
        font-size: 1.2rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 6px;
    }

    .ad-subtitle {
        font-size: 0.88rem;
        color: #64748b;
        margin-bottom: 14px;
        line-height: 1.5;
    }

    .ad-reason {
        background: #fef2f2;
        border-left: 3px solid #ef4444;
        border-radius: 8px;
        padding: 10px 14px;
        font-size: 0.82rem;
        color: #991b1b;
        margin-bottom: 22px;
        text-align: left;
        line-height: 1.6;
    }

    .ad-btn {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: white;
        border: none;
        border-radius: 12px;
        padding: 11px 36px;
        font-size: 0.95rem;
        font-family: 'Kanit', sans-serif;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 5px 15px rgba(220, 38, 38, 0.35);
        transition: all 0.2s;
    }

    .ad-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(220, 38, 38, 0.4);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .sidebar {
            width: 100%;
            left: -100%;
        }

        .sidebar.show {
            left: 0;
        }

        .main-content.shifted {
            margin-left: 0;
        }

        .sidebar-menu-item.logout-button {
            margin: 5px 10px 0 10px;
            width: calc(100% - 20px);
        }

        .access-denied-message {
            width: 85%;
            padding: 25px 30px;
        }
    }

    @media (min-width: 1024px) {
        .sidebar.desktop-open {
            left: 0;
        }

        .main-content.desktop-shifted {
            margin-left: 300px;
        }
    }

    /* Animation Effects */
    .sidebar-menu-item {
        opacity: 0;
        animation: slideInMenu 0.3s ease forwards;
    }

    .sidebar-menu-item:nth-child(1) {
        animation-delay: 0.1s;
    }

    .sidebar-menu-item:nth-child(2) {
        animation-delay: 0.15s;
    }

    .sidebar-menu-item:nth-child(3) {
        animation-delay: 0.2s;
    }

    .sidebar-menu-item:nth-child(4) {
        animation-delay: 0.25s;
    }

    .sidebar-menu-item:nth-child(5) {
        animation-delay: 0.3s;
    }

    .sidebar-menu-item:nth-child(6) {
        animation-delay: 0.35s;
    }

    .sidebar-menu-item:nth-child(7) {
        animation-delay: 0.4s;
    }

    .sidebar-menu-item:nth-child(8) {
        animation-delay: 0.45s;
    }

    .sidebar-menu-item:nth-child(9) {
        animation-delay: 0.5s;
    }

    .sidebar-menu-item:nth-child(10) {
        animation-delay: 0.55s;
    }

    .sidebar-menu-item:nth-child(11) {
        animation-delay: 0.6s;
    }

    @keyframes slideInMenu {
        from {
            opacity: 0;
            transform: translateX(-20px);
        }

        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    /* ================= NEW ADDITION: Logout Modal Styles ================= */
    .logout-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100vh;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(8px);
        /* Blur effect */
        z-index: 9999;
        /* Higher than sidebar (990) and overlay (989) */
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .logout-overlay.show {
        opacity: 1;
        visibility: visible;
    }

    .logout-box {
        background: white;
        width: 90%;
        max-width: 400px;
        padding: 30px;
        border-radius: 24px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        text-align: center;
        transform: scale(0.9) translateY(20px);
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .logout-overlay.show .logout-box {
        transform: scale(1) translateY(0);
    }

    .logout-icon {
        width: 72px;
        height: 72px;
        background: #fee2e2;
        color: #ef4444;
        font-size: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        animation: floatIcon 3s ease-in-out infinite;
    }

    @keyframes floatIcon {

        0%,
        100% {
            transform: translateY(0);
        }

        50% {
            transform: translateY(-5px);
        }
    }

    .logout-header h3 {
        margin: 0 0 10px;
        color: #1e293b;
        font-size: 1.25rem;
        font-weight: 700;
    }

    .logout-header p {
        margin: 0 0 25px;
        color: #64748b;
        font-size: 0.95rem;
        line-height: 1.5;
    }

    .logout-actions {
        display: grid;
        grid-template-columns: 1fr 1.5fr;
        gap: 12px;
    }

    .logout-actions button {
        padding: 12px 20px;
        border-radius: 12px;
        border: none;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .btn-cancel {
        background: #f1f5f9;
        color: #64748b;
    }

    .btn-cancel:hover {
        background: #e2e8f0;
        color: #475569;
    }

    .btn-confirm {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: white;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
    }

    .btn-confirm:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(220, 38, 38, 0.4);
    }

    .btn-confirm:active {
        transform: translateY(0);
    }

    @media (max-width: 480px) {
        .logout-actions {
            grid-template-columns: 1fr;
        }

        .logout-actions button {
            width: 100%;
        }

        .logout-actions .btn-cancel {
            order: 2;
        }
    }
</style>

<!-- Access Denied Popup - SweetAlert Style -->
<div class="ad-overlay" id="adOverlay">
    <div class="ad-box">
        <div class="ad-icon-wrap">⛔</div>
        <h3 class="ad-title">ไม่มีสิทธิ์เข้าถึงหน้านี้</h3>
        <p class="ad-subtitle">ขออภัย คุณไม่สามารถเข้าถึงหน้านี้ได้</p>
        <div class="ad-reason" id="adReason"></div>
        <button class="ad-btn" id="adBtn">รับทราบ</button>
    </div>
</div>

<script>
    // Enhanced Sidebar Management with Access Control
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const mainContent = document.querySelector('.main-content');
        const toggle = document.querySelector('.mobile-menu-toggle');

        const isOpen = sidebar.classList.contains('show');

        if (isOpen) {
            closeSidebar();
        } else {
            openSidebar();
        }
    }

    function openSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const mainContent = document.querySelector('.main-content');
        const toggle = document.querySelector('.mobile-menu-toggle');

        sidebar.classList.add('show');
        if (toggle) toggle.classList.add('active');

        // Responsive behavior
        if (window.innerWidth >= 1024) {
            // Desktop: เลื่อนเนื้อหา ไม่แสดง overlay
            if (mainContent) mainContent.classList.add('shifted');
            sidebar.classList.add('desktop-open');
            if (mainContent) mainContent.classList.add('desktop-shifted');
        } else {
            // Mobile: แสดง overlay
            if (overlay) overlay.classList.add('show');
        }
    }

    function closeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const mainContent = document.querySelector('.main-content');
        const toggle = document.querySelector('.mobile-menu-toggle');

        sidebar.classList.remove('show');
        if (overlay) overlay.classList.remove('show');
        if (toggle) toggle.classList.remove('active');
        if (mainContent) mainContent.classList.remove('shifted');
        sidebar.classList.remove('desktop-open');
        if (mainContent) mainContent.classList.remove('desktop-shifted');
    }

    // Access Control Navigation Function
    function navigateWithAccessControl(url, requiredRole, requiredPermission) {
        // Get current user info from PHP session
        const currentRole = '<?php echo $currentRole; ?>';
        const currentPermission = <?php echo isset($_SESSION['permission']) ? $_SESSION['permission'] : 0; ?>;
        const isLoggedIn = <?php echo isLoggedIn() ? 'true' : 'false'; ?>;

        console.log('Navigation attempt:', {
            url: url,
            requiredRole: requiredRole,
            requiredPermission: requiredPermission,
            currentRole: currentRole,
            currentPermission: currentPermission,
            isLoggedIn: isLoggedIn
        });

        // Check if user is logged in
        if (!isLoggedIn) {
            // Redirect to student login page as preference
            window.location.href = '../students/login.php?redirect=' + encodeURIComponent(window.location.pathname);
            return;
        }

        // Check role access
        if (requiredRole && requiredRole !== currentRole) {
            showAccessDenied('หน้านี้สำหรับ' + getRoleDisplayName(requiredRole) + 'เท่านั้น สิทธิ์ของคุณไม่ตรงตามที่กำหนด', null);
            return;
        }

        // Check permission level for teachers
        if (currentRole === 'teacher' && requiredPermission > 0 && currentPermission < requiredPermission) {
            let permissionName = getPermissionDisplayName(requiredPermission);
            showAccessDenied('หน้านี้สำหรับ' + getPermissionDisplayName(requiredPermission) + 'เท่านั้น สิทธิ์ของคุณคือ ' + getPermissionDisplayName(currentPermission), null);
            return;
        }

        // If all checks pass, navigate to the page
        window.location.href = url;
    }

    // Helper functions for display names
    function getRoleDisplayName(role) {
        const roleNames = {
            'student': 'นักศึกษา',
            'teacher': 'เจ้าหน้าที่',
            'admin': 'ผู้ดูแลระบบ'
        };
        return roleNames[role] || role;
    }

    // ปรับชื่อสิทธิ์ให้ตรงกับระบบใหม่ 3 ระดับ
    function getPermissionDisplayName(permission) {
        const permissionNames = {
            1: 'อาจารย์/เจ้าหน้าที่',
            2: 'ผู้ดำเนินการ',
            3: 'ผู้ดูแลระบบ'
        };
        return permissionNames[permission] || 'ระดับ ' + permission;
    }

    function showAccessDenied(reason, redirectUrl) {
        const overlay = document.getElementById('adOverlay');
        const reasonEl = document.getElementById('adReason');
        const btn = document.getElementById('adBtn');

        if (reasonEl) reasonEl.innerHTML = '📌 เหตุผล: ' + reason;
        btn.onclick = redirectUrl ?
            function() {
                window.location.href = redirectUrl;
            } :
            closeAccessDenied;

        if (overlay) overlay.classList.add('show');
    }

    function closeAccessDenied() {
        const overlay = document.getElementById('adOverlay');
        if (overlay) overlay.classList.remove('show');
    }

    // Click outside to close sidebar
    document.addEventListener('click', function(e) {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.querySelector('.mobile-menu-toggle');

        if (!sidebar.contains(e.target) &&
            !toggle.contains(e.target) &&
            sidebar.classList.contains('show')) {
            closeSidebar();
        }
    });

    // Responsive window resize handler
    window.addEventListener('resize', function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const mainContent = document.querySelector('.main-content');

        if (window.innerWidth >= 1024) {
            // Desktop: ซ่อน overlay
            if (overlay) overlay.classList.remove('show');
            if (sidebar && sidebar.classList.contains('show')) {
                if (mainContent) mainContent.classList.add('shifted');
                sidebar.classList.add('desktop-open');
                if (mainContent) mainContent.classList.add('desktop-shifted');
            }
        } else {
            // Mobile: ลบ desktop classes
            if (mainContent) mainContent.classList.remove('shifted');
            if (sidebar) sidebar.classList.remove('desktop-open');
            if (mainContent) mainContent.classList.remove('desktop-shifted');
            if (sidebar && sidebar.classList.contains('show')) {
                if (overlay) overlay.classList.add('show');
            }
        }
    });

    // Auto-open sidebar on page load (desktop only)
    document.addEventListener('DOMContentLoaded', function() {
        if (window.innerWidth >= 1024) {
            setTimeout(() => {
                openSidebar();
            }, 500);
        }
    });

    // ================= NEW ADDITION: Enhanced Logout Logic =================

    // Function to open the logout modal
    function confirmLogout() {
        const modal = document.getElementById('logoutModal');
        modal.classList.add('show');
        // Prevent default link action
        return false;
    }

    // Function to close the logout modal
    function closeLogoutModal() {
        const modal = document.getElementById('logoutModal');
        modal.classList.remove('show');
    }

    // Function to perform the actual logout
    function performLogout() {
        // Optional: Change button state to indicate processing
        const btn = document.querySelector('.btn-confirm');
        if (btn) {
            btn.innerHTML = 'กำลังออกจากระบบ...';
            btn.style.opacity = '0.8';
        }

        // Slight delay for visual feedback before redirecting
        setTimeout(() => {
            window.location.href = '../logout.php';
        }, 300);
    }

    // Close modal when clicking outside the box
    document.getElementById('logoutModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeLogoutModal();
        }
    });

    // Close modal with ESC key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('logoutModal').classList.contains('show')) {
            closeLogoutModal();
        }
    });

    // Global functions for compatibility
    window.toggleSidebar = toggleSidebar;
    window.openSidebar = openSidebar;
    window.closeSidebar = closeSidebar;
    window.confirmLogout = confirmLogout;
    window.navigateWithAccessControl = navigateWithAccessControl;
    window.closeLogoutModal = closeLogoutModal; // Add new function to global scope
    window.showAccessDenied = showAccessDenied;
    window.closeAccessDenied = closeAccessDenied;
</script>