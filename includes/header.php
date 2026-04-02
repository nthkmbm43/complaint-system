<?php
if (!defined('SECURE_ACCESS')) {
    exit('Direct access not allowed');
}

$user = getCurrentUser();
$currentRole = $_SESSION['user_role'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// ไม่ใช้ notifications table เนื่องจากไม่มีในฐานข้อมูลใหม่
$unreadCount = 0;

// Page titles
$pageTitles = [
    'index' => 'หน้าหลักเจ้าหน้าที่',
    'manage-complaints' => 'จัดการข้อร้องเรียน',
    'priority-management' => 'จัดการความสำคัญ',
    'complaint-replies' => 'ตอบกลับข้อร้องเรียน',
    'reports' => 'รายงานและสถิติ',
    'settings' => 'ตั้งค่าระบบ',
    'system-settings' => 'ตั้งค่าระบบ',
    'users' => 'จัดการผู้ใช้',
    'system-maintenance' => 'บำรุงรักษาระบบ',
    'system-logs' => 'ระบบ Logs'
];

$pageTitle = $pageTitles[$currentPage] ?? 'ระบบจัดการข้อร้องเรียน';
?>

<!-- Mobile Menu Toggle - แสดงเสมอ -->
<button class="mobile-menu-toggle logged-in" id="mobileMenuToggle" onclick="toggleSidebar()">
    <div class="hamburger">
        <span></span>
        <span></span>
        <span></span>
    </div>
</button>

<!-- Top Header (แสดงเฉพาะเมื่อเข้าสู่ระบบแล้ว) -->
<?php if (isLoggedIn() && in_array($currentRole, ['teacher', 'admin'])): ?>
    <div class="top-header show" id="topHeader">
        <div class="top-header-title"><?php echo $pageTitle; ?></div>
        <div class="top-header-actions">
            <div class="header-notification" onclick="toggleSidebar()" title="เมนู">
                <span style="font-size: 18px;">☰</span>
            </div>
        </div>
    </div>
<?php endif; ?>

<style>
    /* Mobile Menu Toggle - แสดงเสมอ */
    .mobile-menu-toggle {
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1001;
        background: rgba(255, 255, 255, 0.9);
        border: none;
        border-radius: 8px;
        padding: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
    }

    .mobile-menu-toggle:hover {
        background: white;
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
    }

    .mobile-menu-toggle .hamburger {
        width: 20px;
        height: 20px;
        position: relative;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .mobile-menu-toggle .hamburger span {
        width: 100%;
        height: 2px;
        background: #333;
        border-radius: 1px;
        transition: all 0.3s ease;
    }

    .mobile-menu-toggle.active .hamburger span:nth-child(1) {
        transform: rotate(45deg) translate(6px, 6px);
    }

    .mobile-menu-toggle.active .hamburger span:nth-child(2) {
        opacity: 0;
    }

    .mobile-menu-toggle.active .hamburger span:nth-child(3) {
        transform: rotate(-45deg) translate(6px, -6px);
    }

    /* Top Header - แสดงเฉพาะเมื่อเข้าสู่ระบบแล้ว */
    .top-header {
        position: fixed;
        top: 0;
        right: 0;
        left: 0;
        height: 70px;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-bottom: 1px solid #e1e5e9;
        z-index: 998;
        display: none;
        align-items: center;
        justify-content: space-between;
        padding: 0 80px 0 80px;
        transition: all 0.3s ease;
    }

    .top-header.show {
        display: flex;
    }

    .top-header.with-sidebar {
        left: 280px;
    }

    .top-header-title {
        font-size: 20px;
        font-weight: bold;
        color: #333;
    }

    .top-header-actions {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .header-notification {
        position: relative;
        padding: 8px;
        background: #f8f9fa;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .header-notification:hover {
        background: #e9ecef;
    }

    .notification-badge {
        position: absolute;
        top: -5px;
        right: -5px;
        background: #dc3545;
        color: white;
        border-radius: 50%;
        width: 18px;
        height: 18px;
        font-size: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
    }

    /* Content Adjustment */
    .main-content {
        transition: all 0.3s ease;
        padding-top: 90px;
        /* เผื่อพื้นที่สำหรับ top header */
    }

    .main-content.shifted {
        margin-left: 280px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .top-header {
            left: 0 !important;
            padding: 0 20px;
        }

        .top-header.with-sidebar {
            left: 0 !important;
        }

        .main-content {
            padding-top: 80px;
        }

        .main-content.shifted {
            margin-left: 0;
        }
    }

    @media (min-width: 769px) {
        .mobile-menu-toggle.guest-mode {
            display: none;
        }

        .mobile-menu-toggle.logged-in {
            display: block;
        }
    }
</style>

<script>
    // Update mobile menu toggle state when sidebar opens/closes
    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('mobileMenuToggle');

        // Listen for sidebar state changes
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                const sidebar = document.getElementById('sidebar');
                if (sidebar && toggle) {
                    if (sidebar.classList.contains('active')) {
                        toggle.classList.add('active');
                    } else {
                        toggle.classList.remove('active');
                    }
                }
            });
        });

        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            observer.observe(sidebar, {
                attributes: true,
                attributeFilter: ['class']
            });
        }
    });

    // Update top header title when needed
    function updateTopHeaderTitle(title) {
        const titleElement = document.querySelector('.top-header-title');
        if (titleElement) {
            titleElement.textContent = title;
        }
    }
</script>