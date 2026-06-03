<?php

/**
 * Alert and Message System
 * ระบบแสดงข้อความแจ้งเตือนแบบสวยงาม
 */

if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

/**
 * แสดงข้อความแจ้งเตือนแบบสวยงาม
 */
function showAlert($message = null, $type = null, $title = null, $autoHide = true)
{
    // ถ้าไม่ได้ส่งพารามิเตอร์มา ให้ใช้จาก URL
    if ($message === null && isset($_GET['message'])) {
        $message = $_GET['message'];
    }

    if ($type === null && isset($_GET['reason'])) {
        $type = $_GET['reason'];
    }

    if (empty($message)) {
        return;
    }

    // กำหนดประเภทและสีของ alert
    $alertConfig = getAlertConfig($type);

    if ($title === null) {
        $title = $alertConfig['title'];
    }

    $alertId = 'alert_' . uniqid();
?>

    <!-- Enhanced Alert System -->
    <div id="<?php echo $alertId; ?>" class="enhanced-alert enhanced-alert-<?php echo $alertConfig['type']; ?> <?php echo $autoHide ? 'auto-hide' : ''; ?>" role="alert">
        <div class="alert-content">
            <div class="alert-icon">
                <?php echo $alertConfig['icon']; ?>
            </div>
            <div class="alert-text">
                <?php if ($title): ?>
                    <div class="alert-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <div class="alert-message"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <button type="button" class="alert-close" onclick="closeAlert('<?php echo $alertId; ?>')" aria-label="ปิด">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    </div>

    <style>
        /* Enhanced Alert Styles */
        .enhanced-alert {
            position: fixed;
            top: 20px;
            right: 20px;
            width: 400px;
            max-width: calc(100vw - 40px);
            padding: 0;
            margin: 0;
            border: none;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
            backdrop-filter: blur(10px);
            z-index: 9999;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            overflow: hidden;
        }

        .enhanced-alert.show {
            opacity: 1;
            transform: translateX(0);
        }

        .enhanced-alert.hide {
            opacity: 0;
            transform: translateX(100%);
        }

        .alert-content {
            display: flex;
            align-items: flex-start;
            padding: 20px;
            position: relative;
        }

        .alert-icon {
            font-size: 24px;
            margin-right: 15px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .alert-text {
            flex: 1;
            min-width: 0;
        }

        .alert-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 5px;
            line-height: 1.3;
        }

        .alert-message {
            font-size: 14px;
            line-height: 1.5;
            opacity: 0.9;
        }

        .alert-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            opacity: 0.6;
            transition: opacity 0.3s ease;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .alert-close:hover {
            opacity: 1;
            background: rgba(255, 255, 255, 0.2);
        }

        /* Alert Types */
        .enhanced-alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }

        .enhanced-alert-error,
        .enhanced-alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }

        .enhanced-alert-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
        }

        .enhanced-alert-info {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
        }

        .enhanced-alert-not_logged_in {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
        }

        .enhanced-alert-insufficient_role,
        .enhanced-alert-insufficient_permission {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }

        /* Progress Bar for Auto-hide */
        .enhanced-alert.auto-hide::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 0 0 12px 12px;
            animation: progress 5s linear forwards;
        }

        @keyframes progress {
            from {
                width: 100%;
            }

            to {
                width: 0%;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .enhanced-alert {
                top: 10px;
                right: 10px;
                left: 10px;
                width: auto;
                max-width: none;
            }
        }

        /* Multiple Alerts Stacking */
        .enhanced-alert:nth-of-type(2) {
            top: 120px;
        }

        .enhanced-alert:nth-of-type(3) {
            top: 220px;
        }

        .enhanced-alert:nth-of-type(4) {
            top: 320px;
        }
    </style>

    <script>
        // Auto-show alert
        document.addEventListener('DOMContentLoaded', function() {
            const alert = document.getElementById('<?php echo $alertId; ?>');
            if (alert) {
                setTimeout(() => {
                    alert.classList.add('show');
                }, 100);

                <?php if ($autoHide): ?>
                    // Auto-hide after 5 seconds
                    setTimeout(() => {
                        closeAlert('<?php echo $alertId; ?>');
                    }, 5000);
                <?php endif; ?>
            }
        });
    </script>

<?php
}

/**
 * กำหนดค่า config สำหรับแต่ละประเภท alert
 */
function getAlertConfig($type)
{
    $configs = [
        'success' => [
            'type' => 'success',
            'icon' => '✅',
            'title' => 'สำเร็จ'
        ],
        'error' => [
            'type' => 'error',
            'icon' => '❌',
            'title' => 'เกิดข้อผิดพลาด'
        ],
        'danger' => [
            'type' => 'danger',
            'icon' => '⚠️',
            'title' => 'คำเตือน'
        ],
        'warning' => [
            'type' => 'warning',
            'icon' => '⚠️',
            'title' => 'คำเตือน'
        ],
        'info' => [
            'type' => 'info',
            'icon' => 'ℹ️',
            'title' => 'ข้อมูล'
        ],
        'not_logged_in' => [
            'type' => 'not_logged_in',
            'icon' => '🔐',
            'title' => 'ต้องเข้าสู่ระบบ'
        ],
        'insufficient_role' => [
            'type' => 'insufficient_role',
            'icon' => '👤',
            'title' => 'ไม่มีสิทธิ์เข้าถึง'
        ],
        'insufficient_permission' => [
            'type' => 'insufficient_permission',
            'icon' => '🔒',
            'title' => 'สิทธิ์ไม่เพียงพอ'
        ]
    ];

    return $configs[$type] ?? [
        'type' => 'info',
        'icon' => 'ℹ️',
        'title' => 'แจ้งเตือน'
    ];
}

/**
 * แสดง Toast Notification แบบเล็ก
 */
function showToast($message, $type = 'info', $duration = 3000)
{
    $toastId = 'toast_' . uniqid();
    $config = getAlertConfig($type);
?>

    <div id="<?php echo $toastId; ?>" class="toast-notification toast-<?php echo $config['type']; ?>">
        <div class="toast-icon"><?php echo $config['icon']; ?></div>
        <div class="toast-message"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>

    <style>
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            z-index: 9999;
            display: flex;
            align-items: center;
            max-width: 300px;
            opacity: 0;
            transform: translateY(100%);
            transition: all 0.3s ease;
        }

        .toast-notification.show {
            opacity: 1;
            transform: translateY(0);
        }

        .toast-icon {
            margin-right: 10px;
            font-size: 18px;
        }

        .toast-message {
            font-size: 14px;
            line-height: 1.4;
        }

        .toast-success {
            background: #d4edda;
            color: #155724;
        }

        .toast-error {
            background: #f8d7da;
            color: #721c24;
        }

        .toast-warning {
            background: #fff3cd;
            color: #856404;
        }

        .toast-info {
            background: #d1ecf1;
            color: #0c5460;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toast = document.getElementById('<?php echo $toastId; ?>');
            if (toast) {
                setTimeout(() => {
                    toast.classList.add('show');
                }, 100);

                setTimeout(() => {
                    toast.classList.remove('show');
                    setTimeout(() => {
                        toast.remove();
                    }, 300);
                }, <?php echo $duration; ?>);
            }
        });
    </script>

<?php
}

/**
 * แสดง Modal Dialog สำหรับข้อความสำคัญ
 */
function showModal($message, $title = 'แจ้งเตือน', $type = 'info', $buttons = ['ตกลง'])
{
    $modalId = 'modal_' . uniqid();
    $config = getAlertConfig($type);
?>

    <div id="<?php echo $modalId; ?>" class="enhanced-modal">
        <div class="modal-backdrop"></div>
        <div class="modal-content modal-<?php echo $config['type']; ?>">
            <div class="modal-header">
                <div class="modal-icon"><?php echo $config['icon']; ?></div>
                <h3 class="modal-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3>
            </div>
            <div class="modal-body">
                <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="modal-footer">
                <?php foreach ($buttons as $index => $button): ?>
                    <button type="button" class="modal-btn <?php echo $index === 0 ? 'btn-primary' : 'btn-secondary'; ?>"
                        onclick="closeModal('<?php echo $modalId; ?>')">
                        <?php echo htmlspecialchars($button, ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <style>
        .enhanced-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .enhanced-modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-backdrop {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            position: relative;
            width: 90%;
            max-width: 500px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            transform: scale(0.8);
            transition: transform 0.3s ease;
        }

        .enhanced-modal.show .modal-content {
            transform: scale(1);
        }

        .modal-header {
            padding: 25px 25px 15px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
        }

        .modal-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }

        .modal-title {
            margin: 0;
            font-size: 20px;
            font-weight: bold;
        }

        .modal-body {
            padding: 20px 25px;
            text-align: center;
        }

        .modal-body p {
            margin: 0;
            line-height: 1.6;
            color: #666;
        }

        .modal-footer {
            padding: 15px 25px 25px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }

        .modal-btn {
            padding: 10px 25px;
            margin: 0 5px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .modal-btn.btn-primary {
            background: #007bff;
            color: white;
        }

        .modal-btn.btn-primary:hover {
            background: #0056b3;
            transform: translateY(-1px);
        }

        .modal-btn.btn-secondary {
            background: #6c757d;
            color: white;
        }

        .modal-btn.btn-secondary:hover {
            background: #545b62;
            transform: translateY(-1px);
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('<?php echo $modalId; ?>');
            if (modal) {
                setTimeout(() => {
                    modal.classList.add('show');
                }, 100);
            }
        });
    </script>

<?php
}

/**
 * JavaScript Functions for Client-side Alert Management
 */
?>
<script>
    // Global Alert Management Functions
    function closeAlert(alertId) {
        const alert = document.getElementById(alertId);
        if (alert) {
            alert.classList.add('hide');
            setTimeout(() => {
                alert.remove();
            }, 400);
        }
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('show');
            setTimeout(() => {
                modal.remove();
            }, 300);
        }
    }

    function showClientAlert(message, type = 'info', title = null, autoHide = true) {
        const alertConfig = {
            'success': {
                icon: '✅',
                title: 'สำเร็จ'
            },
            'error': {
                icon: '❌',
                title: 'เกิดข้อผิดพลาด'
            },
            'warning': {
                icon: '⚠️',
                title: 'คำเตือน'
            },
            'info': {
                icon: 'ℹ️',
                title: 'ข้อมูล'
            }
        };

        const config = alertConfig[type] || alertConfig['info'];
        const alertId = 'alert_' + Date.now();

        const alertHTML = `
        <div id="${alertId}" class="enhanced-alert enhanced-alert-${type} ${autoHide ? 'auto-hide' : ''}" role="alert">
            <div class="alert-content">
                <div class="alert-icon">${config.icon}</div>
                <div class="alert-text">
                    ${title ? `<div class="alert-title">${title}</div>` : ''}
                    <div class="alert-message">${message}</div>
                </div>
                <button type="button" class="alert-close" onclick="closeAlert('${alertId}')" aria-label="ปิด">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        </div>
    `;

        document.body.insertAdjacentHTML('beforeend', alertHTML);

        const alertElement = document.getElementById(alertId);
        setTimeout(() => {
            alertElement.classList.add('show');
        }, 100);

        if (autoHide) {
            setTimeout(() => {
                closeAlert(alertId);
            }, 5000);
        }
    }

    function showClientToast(message, type = 'info', duration = 3000) {
        const toastConfig = {
            'success': '✅',
            'error': '❌',
            'warning': '⚠️',
            'info': 'ℹ️'
        };

        const icon = toastConfig[type] || toastConfig['info'];
        const toastId = 'toast_' + Date.now();

        const toastHTML = `
        <div id="${toastId}" class="toast-notification toast-${type}">
            <div class="toast-icon">${icon}</div>
            <div class="toast-message">${message}</div>
        </div>
    `;

        document.body.insertAdjacentHTML('beforeend', toastHTML);

        const toastElement = document.getElementById(toastId);
        setTimeout(() => {
            toastElement.classList.add('show');
        }, 100);

        setTimeout(() => {
            toastElement.classList.remove('show');
            setTimeout(() => {
                toastElement.remove();
            }, 300);
        }, duration);
    }

    // Auto-cleanup old alerts every 30 seconds
    setInterval(() => {
        const oldAlerts = document.querySelectorAll('.enhanced-alert:not(.show)');
        oldAlerts.forEach(alert => {
            if (alert.offsetParent === null) { // hidden
                alert.remove();
            }
        });
    }, 30000);
</script>

<?php
/**
 * Helper function สำหรับใช้ในหน้าต่างๆ
 */
function displayMessage()
{
    if (isset($_GET['message']) || isset($_GET['success']) || isset($_GET['error'])) {
        if (isset($_GET['success'])) {
            showAlert($_GET['success'], 'success');
        } elseif (isset($_GET['error'])) {
            showAlert($_GET['error'], 'error');
        } else {
            showAlert();
        }
    }
}

/**
 * Redirect พร้อมข้อความ
 */
function redirectWithMessage($url, $message, $type = 'info')
{
    $separator = strpos($url, '?') !== false ? '&' : '?';
    header("Location: {$url}{$separator}message=" . urlencode($message) . "&reason=" . $type);
    exit;
}

/**
 * Redirect พร้อมข้อความสำเร็จ
 */
function redirectWithSuccess($url, $message)
{
    $separator = strpos($url, '?') !== false ? '&' : '?';
    header("Location: {$url}{$separator}success=" . urlencode($message));
    exit;
}

/**
 * Redirect พร้อมข้อความ error
 */
function redirectWithError($url, $message)
{
    $separator = strpos($url, '?') !== false ? '&' : '?';
    header("Location: {$url}{$separator}error=" . urlencode($message));
    exit;
}
?>