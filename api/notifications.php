<?php
// api/notifications.php - AJAX Handler สำหรับ Notification System
define('SECURE_ACCESS', true);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/notifications.php';

// ตรวจสอบการล็อกอิน
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';
$userId = $_SESSION['user_id'] ?? '';
$userType = $_SESSION['user_role'] ?? 'student';

try {
    switch ($action) {
        case 'get_notifications':
            $limit = intval($_REQUEST['limit'] ?? 20);
            $offset = intval($_REQUEST['offset'] ?? 0);

            $notifications = getUserNotifications($userId, $userType, $limit, $offset);
            $stats = getNotificationStats($userId, $userType);

            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'stats' => $stats,
                'has_more' => count($notifications) >= $limit
            ]);
            break;

        case 'get_unread_count':
            $count = getUnreadNotificationCount($userId, $userType);
            echo json_encode([
                'success' => true,
                'unread_count' => $count
            ]);
            break;

        case 'mark_as_read':
            $notificationId = intval($_REQUEST['notification_id'] ?? 0);
            if ($notificationId <= 0) {
                throw new Exception('Invalid notification ID');
            }

            $result = markNotificationAsRead($notificationId, $userId);

            echo json_encode([
                'success' => $result,
                'message' => $result ? 'ทำเครื่องหมายว่าอ่านแล้ว' : 'เกิดข้อผิดพลาด'
            ]);
            break;

        case 'mark_all_as_read':
            $result = markAllNotificationsAsRead($userId, $userType);

            echo json_encode([
                'success' => $result,
                'message' => $result ? 'ทำเครื่องหมายทั้งหมดว่าอ่านแล้ว' : 'เกิดข้อผิดพลาด'
            ]);
            break;

        case 'delete_notification':
            $notificationId = intval($_REQUEST['notification_id'] ?? 0);
            if ($notificationId <= 0) {
                throw new Exception('Invalid notification ID');
            }

            $result = deleteNotification($notificationId, $userId);

            echo json_encode([
                'success' => $result,
                'message' => $result ? 'ลบการแจ้งเตือนแล้ว' : 'เกิดข้อผิดพลาด'
            ]);
            break;

        case 'get_stats':
            $stats = getNotificationStats($userId, $userType);
            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
            break;

        case 'test_notification':
            // สำหรับทดสอบระบบ (เฉพาะ admin)
            if (($_SESSION['permission'] ?? 0) != 3) {
                throw new Exception('Permission denied');
            }

            $result = createNotification(
                '🧪 ทดสอบระบบแจ้งเตือน',
                'นี่คือการแจ้งเตือนทดสอบระบบ เวลา: ' . date('Y-m-d H:i:s'),
                null,
                $userType === 'student' ? $userId : null,
                $userType === 'teacher' ? $userId : null,
                $userId
            );

            echo json_encode([
                'success' => $result !== false,
                'message' => $result !== false ? 'ส่งการแจ้งเตือนทดสอบแล้ว' : 'เกิดข้อผิดพลาด',
                'notification_id' => $result
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

<!-- Notification UI Components -->
<script>
    /**
     * Notification System JavaScript
     * ระบบจัดการการแจ้งเตือนแบบ Real-time
     */
    class NotificationSystem {
        constructor() {
            this.apiUrl = '../api/notifications.php';
            this.updateInterval = 30000; // 30 วินาที
            this.maxNotifications = 50;
            this.isUpdating = false;

            this.init();
        }

        init() {
            this.createNotificationUI();
            this.bindEvents();
            this.startAutoUpdate();
            this.loadNotifications();
        }

        createNotificationUI() {
            // สร้าง Notification Bell Icon
            const header = document.querySelector('.header-nav') || document.querySelector('header');
            if (header && !document.getElementById('notificationBell')) {
                const bellHTML = `
                <div class="notification-container">
                    <button id="notificationBell" class="notification-bell" title="การแจ้งเตือน">
                        <span class="bell-icon">🔔</span>
                        <span id="notificationBadge" class="notification-badge" style="display: none;">0</span>
                    </button>
                    <div id="notificationDropdown" class="notification-dropdown" style="display: none;">
                        <div class="notification-header">
                            <h4>การแจ้งเตือน</h4>
                            <div class="notification-actions">
                                <button id="markAllRead" class="btn-small">อ่านทั้งหมด</button>
                                <button id="refreshNotifications" class="btn-small">รีเฟรช</button>
                            </div>
                        </div>
                        <div id="notificationList" class="notification-list">
                            <div class="loading">กำลังโหลด...</div>
                        </div>
                        <div class="notification-footer">
                            <button id="loadMoreNotifications" class="btn-small" style="display: none;">โหลดเพิ่มเติม</button>
                        </div>
                    </div>
                </div>
            `;

                header.insertAdjacentHTML('beforeend', bellHTML);
            }

            // เพิ่ม CSS
            this.addNotificationCSS();
        }

        addNotificationCSS() {
            if (document.getElementById('notificationCSS')) return;

            const css = `
            <style id="notificationCSS">
                .notification-container {
                    position: relative;
                    display: inline-block;
                }

                .notification-bell {
                    background: none;
                    border: none;
                    cursor: pointer;
                    position: relative;
                    padding: 8px;
                    border-radius: 50%;
                    transition: all 0.3s ease;
                }

                .notification-bell:hover {
                    background: rgba(255, 255, 255, 0.1);
                }

                .bell-icon {
                    font-size: 20px;
                    display: block;
                }

                .notification-badge {
                    position: absolute;
                    top: 0;
                    right: 0;
                    background: #e53e3e;
                    color: white;
                    border-radius: 50%;
                    min-width: 18px;
                    height: 18px;
                    font-size: 11px;
                    font-weight: bold;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    animation: pulse 2s infinite;
                }

                @keyframes pulse {
                    0%, 100% { transform: scale(1); }
                    50% { transform: scale(1.1); }
                }

                .notification-dropdown {
                    position: absolute;
                    top: 100%;
                    right: 0;
                    width: 380px;
                    max-width: 90vw;
                    background: white;
                    border-radius: 10px;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
                    z-index: 9999;
                    border: 1px solid #e2e8f0;
                }

                .notification-header {
                    padding: 15px 20px;
                    border-bottom: 1px solid #e2e8f0;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .notification-header h4 {
                    margin: 0;
                    font-size: 16px;
                    color: #2d3748;
                }

                .notification-actions {
                    display: flex;
                    gap: 8px;
                }

                .btn-small {
                    padding: 4px 8px;
                    font-size: 12px;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                    background: #667eea;
                    color: white;
                    transition: all 0.2s ease;
                }

                .btn-small:hover {
                    background: #5a67d8;
                }

                .notification-list {
                    max-height: 400px;
                    overflow-y: auto;
                }

                .notification-item {
                    padding: 12px 20px;
                    border-bottom: 1px solid #f7fafc;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    position: relative;
                }

                .notification-item:hover {
                    background: #f7fafc;
                }

                .notification-item.unread {
                    background: #ebf8ff;
                    border-left: 3px solid #3182ce;
                }

                .notification-item.urgent {
                    border-left: 3px solid #e53e3e;
                    background: #fef5e7;
                }

                .notification-title {
                    font-weight: 600;
                    color: #2d3748;
                    margin-bottom: 4px;
                    font-size: 14px;
                }

                .notification-message {
                    color: #4a5568;
                    font-size: 13px;
                    line-height: 1.4;
                    margin-bottom: 6px;
                }

                .notification-time {
                    color: #a0aec0;
                    font-size: 11px;
                }

                .notification-actions-item {
                    position: absolute;
                    top: 8px;
                    right: 8px;
                    display: none;
                }

                .notification-item:hover .notification-actions-item {
                    display: flex;
                    gap: 4px;
                }

                .action-btn {
                    background: none;
                    border: none;
                    cursor: pointer;
                    padding: 2px;
                    border-radius: 3px;
                    font-size: 10px;
                }

                .action-btn:hover {
                    background: rgba(0, 0, 0, 0.1);
                }

                .notification-footer {
                    padding: 10px 20px;
                    text-align: center;
                    border-top: 1px solid #e2e8f0;
                }

                .loading, .empty-notifications {
                    text-align: center;
                    padding: 30px 20px;
                    color: #a0aec0;
                    font-style: italic;
                }

                .notification-toast {
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    background: white;
                    padding: 15px 20px;
                    border-radius: 8px;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
                    z-index: 10000;
                    max-width: 350px;
                    border-left: 4px solid #48bb78;
                    opacity: 0;
                    transform: translateY(100%);
                    transition: all 0.3s ease;
                }

                .notification-toast.show {
                    opacity: 1;
                    transform: translateY(0);
                }

                .notification-toast.error {
                    border-left-color: #e53e3e;
                }

                .notification-toast.warning {
                    border-left-color: #ed8936;
                }

                @media (max-width: 768px) {
                    .notification-dropdown {
                        width: 320px;
                        right: -10px;
                    }
                }
            </style>
        `;

            document.head.insertAdjacentHTML('beforeend', css);
        }

        bindEvents() {
            // Toggle dropdown
            document.addEventListener('click', (e) => {
                const bell = document.getElementById('notificationBell');
                const dropdown = document.getElementById('notificationDropdown');

                if (bell && e.target.closest('#notificationBell')) {
                    e.preventDefault();
                    const isVisible = dropdown.style.display !== 'none';
                    dropdown.style.display = isVisible ? 'none' : 'block';

                    if (!isVisible) {
                        this.loadNotifications();
                    }
                } else if (dropdown && !e.target.closest('.notification-dropdown')) {
                    dropdown.style.display = 'none';
                }
            });

            // Mark all as read
            const markAllBtn = document.getElementById('markAllRead');
            if (markAllBtn) {
                markAllBtn.addEventListener('click', () => this.markAllAsRead());
            }

            // Refresh notifications
            const refreshBtn = document.getElementById('refreshNotifications');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', () => this.loadNotifications(true));
            }

            // Load more notifications
            const loadMoreBtn = document.getElementById('loadMoreNotifications');
            if (loadMoreBtn) {
                loadMoreBtn.addEventListener('click', () => this.loadMoreNotifications());
            }
        }

        async loadNotifications(forceRefresh = false) {
            if (this.isUpdating && !forceRefresh) return;

            this.isUpdating = true;
            const listElement = document.getElementById('notificationList');

            try {
                if (forceRefresh) {
                    listElement.innerHTML = '<div class="loading">กำลังโหลด...</div>';
                }

                const response = await fetch(this.apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_notifications&limit=20'
                });

                const data = await response.json();

                if (data.success) {
                    this.renderNotifications(data.notifications);
                    this.updateBadge(data.stats.unread);

                    const loadMoreBtn = document.getElementById('loadMoreNotifications');
                    if (loadMoreBtn) {
                        loadMoreBtn.style.display = data.has_more ? 'block' : 'none';
                    }
                } else {
                    this.showToast('เกิดข้อผิดพลาดในการโหลดการแจ้งเตือน', 'error');
                }
            } catch (error) {
                console.error('Error loading notifications:', error);
                this.showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
            } finally {
                this.isUpdating = false;
            }
        }

        renderNotifications(notifications) {
            const listElement = document.getElementById('notificationList');

            if (!notifications || notifications.length === 0) {
                listElement.innerHTML = '<div class="empty-notifications">ไม่มีการแจ้งเตือน</div>';
                return;
            }

            const html = notifications.map(notification => `
            <div class="notification-item ${notification.Noti_status == '0' ? 'unread' : ''} ${notification.is_urgent ? 'urgent' : ''}" 
                 data-id="${notification.Noti_id}">
                <div class="notification-title">${this.escapeHtml(notification.Noti_title)}</div>
                <div class="notification-message">${this.escapeHtml(notification.Noti_message)}</div>
                <div class="notification-time">${notification.time_ago}</div>
                <div class="notification-actions-item">
                    ${notification.Noti_status == '0' ? '<button class="action-btn mark-read" data-id="' + notification.Noti_id + '">✓</button>' : ''}
                    <button class="action-btn delete-notification" data-id="${notification.Noti_id}">✕</button>
                </div>
            </div>
        `).join('');

            listElement.innerHTML = html;

            // Bind item events
            this.bindNotificationItemEvents();
        }

        bindNotificationItemEvents() {
            const listElement = document.getElementById('notificationList');

            // Click on notification item
            listElement.addEventListener('click', (e) => {
                const item = e.target.closest('.notification-item');
                if (!item) return;

                const notificationId = item.dataset.id;

                if (e.target.classList.contains('mark-read')) {
                    e.stopPropagation();
                    this.markAsRead(notificationId);
                } else if (e.target.classList.contains('delete-notification')) {
                    e.stopPropagation();
                    this.deleteNotification(notificationId);
                } else {
                    // Mark as read and potentially navigate
                    if (item.classList.contains('unread')) {
                        this.markAsRead(notificationId);
                    }

                    // TODO: Navigate to related page if needed
                }
            });
        }

        async markAsRead(notificationId) {
            try {
                const response = await fetch(this.apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=mark_as_read&notification_id=${notificationId}`
                });

                const data = await response.json();

                if (data.success) {
                    // Update UI
                    const item = document.querySelector(`[data-id="${notificationId}"]`);
                    if (item) {
                        item.classList.remove('unread');
                        const markReadBtn = item.querySelector('.mark-read');
                        if (markReadBtn) {
                            markReadBtn.remove();
                        }
                    }

                    // Update badge count
                    this.updateUnreadCount();
                } else {
                    this.showToast('เกิดข้อผิดพลาดในการทำเครื่องหมาย', 'error');
                }
            } catch (error) {
                console.error('Error marking as read:', error);
                this.showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
            }
        }

        async markAllAsRead() {
            try {
                const response = await fetch(this.apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=mark_all_as_read'
                });

                const data = await response.json();

                if (data.success) {
                    // Update UI
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                        const markReadBtn = item.querySelector('.mark-read');
                        if (markReadBtn) {
                            markReadBtn.remove();
                        }
                    });

                    this.updateBadge(0);
                    this.showToast('ทำเครื่องหมายทั้งหมดแล้ว', 'success');
                } else {
                    this.showToast('เกิดข้อผิดพลาด', 'error');
                }
            } catch (error) {
                console.error('Error marking all as read:', error);
                this.showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
            }
        }

        async deleteNotification(notificationId) {
            if (!confirm('ต้องการลบการแจ้งเตือนนี้?')) return;

            try {
                const response = await fetch(this.apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=delete_notification&notification_id=${notificationId}`
                });

                const data = await response.json();

                if (data.success) {
                    // Remove from UI
                    const item = document.querySelector(`[data-id="${notificationId}"]`);
                    if (item) {
                        item.remove();
                    }

                    this.updateUnreadCount();
                    this.showToast('ลบการแจ้งเตือนแล้ว', 'success');
                } else {
                    this.showToast('เกิดข้อผิดพลาดในการลบ', 'error');
                }
            } catch (error) {
                console.error('Error deleting notification:', error);
                this.showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
            }
        }

        async updateUnreadCount() {
            try {
                const response = await fetch(this.apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_unread_count'
                });

                const data = await response.json();

                if (data.success) {
                    this.updateBadge(data.unread_count);
                }
            } catch (error) {
                console.error('Error getting unread count:', error);
            }
        }

        updateBadge(count) {
            const badge = document.getElementById('notificationBadge');
            if (badge) {
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            }
        }

        startAutoUpdate() {
            setInterval(() => {
                this.updateUnreadCount();
            }, this.updateInterval);
        }

        showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `notification-toast ${type}`;
            toast.innerHTML = `
            <div style="font-weight: bold; margin-bottom: 5px;">
                ${type === 'success' ? '✅' : type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️'} 
                ${type === 'success' ? 'สำเร็จ' : type === 'error' ? 'ข้อผิดพลาด' : type === 'warning' ? 'คำเตือน' : 'แจ้งเตือน'}
            </div>
            <div>${this.escapeHtml(message)}</div>
        `;

            document.body.appendChild(toast);

            setTimeout(() => toast.classList.add('show'), 100);

            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);

            toast.addEventListener('click', () => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            });
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // Auto-initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof window.notificationSystem === 'undefined') {
            window.notificationSystem = new NotificationSystem();
        }
    });
</script>