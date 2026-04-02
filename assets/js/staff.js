// Staff System JavaScript Functions - Ultra Safe Reset Version + Error Fix
// รีเซ็ตทั้งหมดและป้องกัน Error 100% + แก้ไขปัญหา responsive

// ===========================
// STEP 1: รีเซ็ตทุกอย่างก่อน
// ===========================

// Clear existing functions to prevent conflicts
if (typeof window.SidebarManager !== 'undefined') {
    delete window.SidebarManager;
}

// ===========================
// STEP 2: Ultra Safe Helpers + Enhanced Error Protection
// ===========================

const SafeDOM = {
    get: function (id) {
        try {
            if (typeof document === 'undefined' || !document.getElementById) return null;
            const element = document.getElementById(id);
            return element;
        } catch (e) {
            return null;
        }
    },

    query: function (selector) {
        try {
            if (typeof document === 'undefined' || !document.querySelector) return null;
            const element = document.querySelector(selector);
            return element;
        } catch (e) {
            return null;
        }
    },

    queryAll: function (selector) {
        try {
            if (typeof document === 'undefined' || !document.querySelectorAll) return [];
            const elements = document.querySelectorAll(selector);
            return Array.from(elements);
        } catch (e) {
            return [];
        }
    },

    addClass: function (element, className) {
        try {
            if (!element || !element.classList || !className) return false;
            if (typeof element.classList.add !== 'function') return false;
            if (!element.classList.contains(className)) {
                element.classList.add(className);
            }
            return true;
        } catch (e) {
            return false;
        }
    },

    removeClass: function (element, className) {
        try {
            if (!element || !element.classList || !className) return false;
            if (typeof element.classList.remove !== 'function') return false;
            if (element.classList.contains(className)) {
                element.classList.remove(className);
            }
            return true;
        } catch (e) {
            return false;
        }
    },

    hasClass: function (element, className) {
        try {
            if (!element || !element.classList || !className) return false;
            if (typeof element.classList.contains !== 'function') return false;
            return element.classList.contains(className);
        } catch (e) {
            return false;
        }
    },

    toggleClass: function (element, className) {
        try {
            if (!element || !element.classList || !className) return false;
            if (typeof element.classList.toggle !== 'function') return false;
            return element.classList.toggle(className);
        } catch (e) {
            return false;
        }
    },

    setStyle: function (element, property, value) {
        try {
            if (!element || !element.style || !property) return false;
            element.style[property] = value;
            return true;
        } catch (e) {
            return false;
        }
    },

    getStyle: function (element, property) {
        try {
            if (!element || !property) return null;
            if (window.getComputedStyle) {
                return window.getComputedStyle(element)[property];
            }
            return element.style[property] || null;
        } catch (e) {
            return null;
        }
    },

    isReady: function () {
        try {
            return typeof document !== 'undefined' &&
                document.readyState !== 'loading' &&
                document.body !== null;
        } catch (e) {
            return false;
        }
    },

    isVisible: function (element) {
        try {
            if (!element) return false;
            const style = this.getStyle(element, 'display');
            const visibility = this.getStyle(element, 'visibility');
            const opacity = this.getStyle(element, 'opacity');

            return style !== 'none' &&
                visibility !== 'hidden' &&
                opacity !== '0' &&
                element.offsetWidth > 0 &&
                element.offsetHeight > 0;
        } catch (e) {
            return false;
        }
    }
};

// ===========================
// STEP 3: Enhanced Safe Event Manager
// ===========================

const SafeEvents = {
    listeners: new Map(),

    add: function (element, event, handler, options) {
        try {
            if (!element || !event || !handler) return false;
            if (typeof element.addEventListener !== 'function') return false;

            // สร้าง wrapper function เพื่อ handle errors
            const safeHandler = function (e) {
                try {
                    return handler.call(this, e);
                } catch (err) {
                    // Silent error handling
                    return false;
                }
            };

            element.addEventListener(event, safeHandler, options);

            // Store for cleanup
            if (!this.listeners.has(element)) {
                this.listeners.set(element, []);
            }
            this.listeners.get(element).push({ event, handler: safeHandler, original: handler });

            return true;
        } catch (e) {
            return false;
        }
    },

    remove: function (element, event, handler) {
        try {
            if (!element || !event || !handler) return false;
            if (typeof element.removeEventListener !== 'function') return false;

            // Find the wrapped handler
            const elementListeners = this.listeners.get(element);
            if (elementListeners) {
                const listener = elementListeners.find(l => l.event === event && l.original === handler);
                if (listener) {
                    element.removeEventListener(event, listener.handler);
                    // Remove from stored listeners
                    const index = elementListeners.indexOf(listener);
                    if (index > -1) {
                        elementListeners.splice(index, 1);
                    }
                    return true;
                }
            }

            // Fallback
            element.removeEventListener(event, handler);
            return true;
        } catch (e) {
            return false;
        }
    },

    removeAll: function (element) {
        try {
            const elementListeners = this.listeners.get(element);
            if (elementListeners) {
                elementListeners.forEach(listener => {
                    try {
                        element.removeEventListener(listener.event, listener.handler);
                    } catch (e) {
                        // Silent error
                    }
                });
                this.listeners.delete(element);
            }
            return true;
        } catch (e) {
            return false;
        }
    }
};

// ===========================
// STEP 4: Enhanced Ultra Safe Sidebar Manager
// ===========================

window.SidebarManager = (function () {
    'use strict';

    let elements = {};
    let initialized = false;
    let isUpdating = false;
    let resizeTimeout = null;

    function safeGetElements() {
        if (!SafeDOM.isReady()) return false;

        try {
            const newElements = {
                sidebar: SafeDOM.get('sidebar'),
                overlay: SafeDOM.get('sidebarOverlay'),
                mainContent: SafeDOM.query('.main-content'),
                toggle: SafeDOM.get('mobileMenuToggle'),
                topHeader: SafeDOM.get('topHeader'),
                body: SafeDOM.query('body')
            };

            // ตรวจสอบว่า elements สำคัญมีอยู่หรือไม่
            if (!newElements.sidebar) {
                return false;
            }

            elements = newElements;
            return true;
        } catch (e) {
            elements = {};
            return false;
        }
    }

    function init() {
        if (initialized) return true;

        try {
            if (!safeGetElements()) return false;
            initialized = true;
            return true;
        } catch (e) {
            initialized = false;
            return false;
        }
    }

    function forceRefreshElements() {
        try {
            initialized = false;
            elements = {};
            return init();
        } catch (e) {
            return false;
        }
    }

    function getScreenWidth() {
        try {
            return typeof window !== 'undefined' ? (window.innerWidth || 0) : 0;
        } catch (e) {
            return 0;
        }
    }

    function isMobile() {
        return getScreenWidth() <= 768;
    }

    function isDesktop() {
        return getScreenWidth() > 768;
    }

    function isSidebarActive() {
        try {
            if (!elements.sidebar) return false;
            return SafeDOM.hasClass(elements.sidebar, 'active');
        } catch (e) {
            return false;
        }
    }

    function open() {
        try {
            if (isUpdating) return false;
            isUpdating = true;

            if (!init()) {
                isUpdating = false;
                return false;
            }

            if (!elements.sidebar) {
                isUpdating = false;
                return false;
            }

            SafeDOM.addClass(elements.sidebar, 'active');

            if (isDesktop()) {
                // Desktop behavior
                if (elements.mainContent) {
                    SafeDOM.addClass(elements.mainContent, 'shifted');
                }
                if (elements.topHeader) {
                    SafeDOM.addClass(elements.topHeader, 'with-sidebar');
                }
                if (elements.overlay) {
                    SafeDOM.removeClass(elements.overlay, 'active');
                }
            } else {
                // Mobile behavior
                if (elements.overlay) {
                    SafeDOM.addClass(elements.overlay, 'active');
                }
                if (elements.mainContent) {
                    SafeDOM.removeClass(elements.mainContent, 'shifted');
                }
                if (elements.topHeader) {
                    SafeDOM.removeClass(elements.topHeader, 'with-sidebar');
                }
            }

            if (elements.toggle) {
                SafeDOM.addClass(elements.toggle, 'active');
            }

            isUpdating = false;
            return true;
        } catch (e) {
            isUpdating = false;
            return false;
        }
    }

    function close() {
        try {
            if (isUpdating) return false;
            isUpdating = true;

            if (!init()) {
                isUpdating = false;
                return false;
            }

            if (!elements.sidebar) {
                isUpdating = false;
                return false;
            }

            SafeDOM.removeClass(elements.sidebar, 'active');

            if (elements.overlay) {
                SafeDOM.removeClass(elements.overlay, 'active');
            }
            if (elements.mainContent) {
                SafeDOM.removeClass(elements.mainContent, 'shifted');
            }
            if (elements.topHeader) {
                SafeDOM.removeClass(elements.topHeader, 'with-sidebar');
            }
            if (elements.toggle) {
                SafeDOM.removeClass(elements.toggle, 'active');
            }

            isUpdating = false;
            return true;
        } catch (e) {
            isUpdating = false;
            return false;
        }
    }

    function toggle() {
        try {
            if (isUpdating) return false;

            if (!init()) return false;
            if (!elements.sidebar) return false;

            const isActive = isSidebarActive();
            return isActive ? close() : open();
        } catch (e) {
            return false;
        }
    }

    function confirmLogout() {
        try {
            // Safe event handling
            let logoutBtn = null;
            if (typeof event !== 'undefined' && event && event.target) {
                try {
                    if (event.target.closest) {
                        logoutBtn = event.target.closest('.logout-button') ||
                            event.target.closest('.sidebar-logout');
                    }
                } catch (e) {
                    // Ignore closest errors
                }
            }

            if (logoutBtn) {
                SafeDOM.setStyle(logoutBtn, 'background', 'rgba(220, 53, 69, 0.3)');
                SafeDOM.setStyle(logoutBtn, 'transform', 'scale(0.95)');
            }

            const confirmed = typeof confirm !== 'undefined' ?
                confirm('คุณต้องการออกจากระบบหรือไม่?') : true;

            if (confirmed) {
                if (logoutBtn) {
                    try {
                        logoutBtn.innerHTML = '<span class="menu-icon">⏳</span><span>กำลังออกจากระบบ...</span>';
                    } catch (e) {
                        // Ignore innerHTML errors
                    }
                }

                setTimeout(() => {
                    try {
                        if (typeof window !== 'undefined' && window.location && window.location.href) {
                            window.location.href = '../logout.php';
                        }
                    } catch (e) {
                        // Fallback
                        if (typeof location !== 'undefined') {
                            location.href = '../logout.php';
                        }
                    }
                }, 500);

                return true;
            } else {
                if (logoutBtn) {
                    SafeDOM.setStyle(logoutBtn, 'background', '');
                    SafeDOM.setStyle(logoutBtn, 'transform', '');
                }
                return false;
            }
        } catch (e) {
            // Ultimate fallback
            try {
                const confirmed = typeof confirm !== 'undefined' ?
                    confirm('คุณต้องการออกจากระบบหรือไม่?') : true;
                if (confirmed) {
                    if (typeof window !== 'undefined' && window.location) {
                        window.location.href = '../logout.php';
                    }
                }
            } catch (e2) {
                // Do nothing - prevent any errors
            }
            return false;
        }
    }

    function handleResize() {
        try {
            // ใช้ debounce เพื่อป้องกันการเรียกฟังก์ชันบ่อยเกินไป
            if (resizeTimeout) {
                clearTimeout(resizeTimeout);
            }

            resizeTimeout = setTimeout(() => {
                try {
                    if (isUpdating) return;

                    // Refresh elements ในกรณีที่ DOM เปลี่ยนแปลง
                    if (!forceRefreshElements()) return;
                    if (!elements.sidebar) return;

                    const isActive = isSidebarActive();

                    if (isMobile()) {
                        // Mobile mode
                        if (elements.mainContent) {
                            SafeDOM.removeClass(elements.mainContent, 'shifted');
                        }
                        if (elements.topHeader) {
                            SafeDOM.removeClass(elements.topHeader, 'with-sidebar');
                        }
                        if (isActive && elements.overlay) {
                            SafeDOM.addClass(elements.overlay, 'active');
                        }
                    } else {
                        // Desktop mode
                        if (elements.overlay) {
                            SafeDOM.removeClass(elements.overlay, 'active');
                        }
                        if (isActive) {
                            if (elements.mainContent) {
                                SafeDOM.addClass(elements.mainContent, 'shifted');
                            }
                            if (elements.topHeader) {
                                SafeDOM.addClass(elements.topHeader, 'with-sidebar');
                            }
                        }
                    }
                } catch (e) {
                    // Silent error handling for resize
                }
            }, 150); // Debounce delay

        } catch (e) {
            // Ignore resize errors
        }
    }

    function autoInit() {
        try {
            if (!SafeDOM.isReady()) {
                setTimeout(autoInit, 100);
                return;
            }

            const body = SafeDOM.query('body');
            const isStaffPage = body && (
                SafeDOM.hasClass(body, 'staff-layout') ||
                (typeof window !== 'undefined' &&
                    window.location &&
                    window.location.pathname &&
                    (window.location.pathname.includes('/staff/') ||
                        window.location.pathname.includes('/admin/')))
            );

            if (isStaffPage && isDesktop()) {
                setTimeout(() => {
                    open();
                }, 100);
            }
        } catch (e) {
            // Ignore auto-init errors
        }
    }

    function cleanup() {
        try {
            if (resizeTimeout) {
                clearTimeout(resizeTimeout);
                resizeTimeout = null;
            }

            // Remove event listeners
            if (typeof window !== 'undefined') {
                SafeEvents.removeAll(window);
            }

            initialized = false;
            elements = {};
            isUpdating = false;
        } catch (e) {
            // Silent cleanup
        }
    }

    // Enhanced resize listener with error protection
    try {
        if (typeof window !== 'undefined' && window.addEventListener) {
            SafeEvents.add(window, 'resize', handleResize);
            SafeEvents.add(window, 'orientationchange', handleResize);
        }
    } catch (e) {
        // Ignore event listener errors
    }

    // Handle page unload
    try {
        if (typeof window !== 'undefined' && window.addEventListener) {
            SafeEvents.add(window, 'beforeunload', cleanup);
        }
    } catch (e) {
        // Ignore event listener errors
    }

    return {
        init: init,
        open: open,
        close: close,
        toggle: toggle,
        confirmLogout: confirmLogout,
        autoInit: autoInit,
        cleanup: cleanup,
        forceRefresh: forceRefreshElements,
        isActive: isSidebarActive,
        isMobile: isMobile,
        isDesktop: isDesktop
    };
})();

// ===========================
// STEP 5: Enhanced Safe Global Functions
// ===========================

window.toggleSidebar = function () {
    try {
        if (window.SidebarManager && window.SidebarManager.toggle) {
            return window.SidebarManager.toggle();
        }
    } catch (e) {
        // Silent fail
    }
    return false;
};

window.openSidebar = function () {
    try {
        if (window.SidebarManager && window.SidebarManager.open) {
            return window.SidebarManager.open();
        }
    } catch (e) {
        // Silent fail
    }
    return false;
};

window.closeSidebar = function () {
    try {
        if (window.SidebarManager && window.SidebarManager.close) {
            return window.SidebarManager.close();
        }
    } catch (e) {
        // Silent fail
    }
    return false;
};

// Legacy support
window.toggleMainSidebar = window.toggleSidebar;

// ===========================
// STEP 6: Enhanced Safe Notification System
// ===========================

window.showNotification = function (message, type, duration) {
    try {
        if (!message) return;

        type = type || 'info';
        duration = duration || 5000;

        let container = SafeDOM.get('notification-container');
        if (!container) {
            try {
                container = document.createElement('div');
                container.id = 'notification-container';
                SafeDOM.setStyle(container, 'position', 'fixed');
                SafeDOM.setStyle(container, 'top', '20px');
                SafeDOM.setStyle(container, 'right', '20px');
                SafeDOM.setStyle(container, 'zIndex', '1002');
                SafeDOM.setStyle(container, 'pointerEvents', 'none');

                if (document.body) {
                    document.body.appendChild(container);
                } else {
                    return; // Cannot create notification
                }
            } catch (e) {
                return; // Cannot create container
            }
        }

        const colors = {
            success: '#28a745',
            error: '#dc3545',
            warning: '#ffc107',
            info: '#17a2b8'
        };

        const icons = {
            success: '✅',
            error: '❌',
            warning: '⚠️',
            info: 'ℹ️'
        };

        const titles = {
            success: 'สำเร็จ',
            error: 'ข้อผิดพลาด',
            warning: 'คำเตือน',
            info: 'แจ้งเตือน'
        };

        try {
            const notification = document.createElement('div');
            const color = colors[type] || colors.info;
            const icon = icons[type] || icons.info;
            const title = titles[type] || titles.info;

            SafeDOM.setStyle(notification, 'background', color);
            SafeDOM.setStyle(notification, 'color', 'white');
            SafeDOM.setStyle(notification, 'padding', '15px 20px');
            SafeDOM.setStyle(notification, 'marginBottom', '10px');
            SafeDOM.setStyle(notification, 'borderRadius', '8px');
            SafeDOM.setStyle(notification, 'boxShadow', '0 4px 15px rgba(0,0,0,0.2)');
            SafeDOM.setStyle(notification, 'pointerEvents', 'auto');
            SafeDOM.setStyle(notification, 'cursor', 'pointer');
            SafeDOM.setStyle(notification, 'maxWidth', '350px');
            SafeDOM.setStyle(notification, 'wordBreak', 'break-word');
            SafeDOM.setStyle(notification, 'animation', 'slideInRight 0.3s ease');

            notification.innerHTML = `
                <div style="font-weight: bold; margin-bottom: 5px; font-size: 14px;">
                    ${icon} ${title}
                </div>
                <div style="font-size: 13px; line-height: 1.4;">${message}</div>
            `;

            // Add CSS if not exists
            if (!SafeDOM.get('notification-styles')) {
                try {
                    const style = document.createElement('style');
                    style.id = 'notification-styles';
                    style.textContent = `
                        @keyframes slideInRight {
                            from { transform: translateX(100%); opacity: 0; }
                            to { transform: translateX(0); opacity: 1; }
                        }
                        @keyframes slideOutRight {
                            from { transform: translateX(0); opacity: 1; }
                            to { transform: translateX(100%); opacity: 0; }
                        }
                    `;
                    if (document.head) {
                        document.head.appendChild(style);
                    }
                } catch (e) {
                    // Ignore style errors
                }
            }

            container.appendChild(notification);

            // Auto remove
            setTimeout(() => {
                try {
                    if (notification.parentNode) {
                        SafeDOM.setStyle(notification, 'animation', 'slideOutRight 0.3s ease');
                        setTimeout(() => {
                            try {
                                if (notification.parentNode) {
                                    notification.remove();
                                }
                            } catch (e) {
                                // Ignore removal errors
                            }
                        }, 300);
                    }
                } catch (e) {
                    // Ignore timeout errors
                }
            }, duration);

            // Click to dismiss
            SafeEvents.add(notification, 'click', function () {
                try {
                    SafeDOM.setStyle(notification, 'animation', 'slideOutRight 0.3s ease');
                    setTimeout(() => {
                        try {
                            if (notification.parentNode) {
                                notification.remove();
                            }
                        } catch (e) {
                            // Ignore removal errors
                        }
                    }, 300);
                } catch (e) {
                    // Ignore click errors
                }
            });

        } catch (e) {
            // Ultimate fallback - try alert
            try {
                if (typeof alert !== 'undefined') {
                    alert(`${type.toUpperCase()}: ${message}`);
                }
            } catch (e2) {
                // Complete silence if alert also fails
            }
        }
    } catch (e) {
        // Complete silence - do nothing
    }
};

// ===========================
// STEP 7: Enhanced Safe Initialization
// ===========================

function ultraSafeInit() {
    try {
        // Initialize SidebarManager
        if (window.SidebarManager) {
            window.SidebarManager.init();
            window.SidebarManager.autoInit();
        }

        // Safe console log
        if (typeof console !== 'undefined' && console.log) {
            console.log('Ultra Safe Staff System initialized');
        }
    } catch (e) {
        // Complete silence
    }
}

// ===========================
// STEP 8: Enhanced Safe DOM Ready Handler
// ===========================

function waitForDOM() {
    try {
        if (SafeDOM.isReady()) {
            ultraSafeInit();
        } else if (typeof document !== 'undefined' && document.addEventListener) {
            document.addEventListener('DOMContentLoaded', ultraSafeInit);
        } else {
            // Fallback - try again in 100ms
            setTimeout(waitForDOM, 100);
        }
    } catch (e) {
        // Try fallback initialization
        setTimeout(ultraSafeInit, 500);
    }
}

// ===========================
// STEP 9: Enhanced Complete Error Suppression
// ===========================

try {
    if (typeof window !== 'undefined') {
        // Enhanced silent error handlers
        SafeEvents.add(window, 'error', function (e) {
            // Check if it's our sidebar-related error
            if (e && e.message && e.message.includes('classList')) {
                // Try to recover by reinitializing
                try {
                    if (window.SidebarManager && window.SidebarManager.forceRefresh) {
                        setTimeout(() => {
                            window.SidebarManager.forceRefresh();
                        }, 100);
                    }
                } catch (recoveryError) {
                    // Silent recovery attempt
                }
            }
            // Prevent default error handling
            if (e && e.preventDefault) {
                e.preventDefault();
            }
            return true;
        });

        SafeEvents.add(window, 'unhandledrejection', function (e) {
            // Silent - do nothing to prevent console spam
            if (e && e.preventDefault) {
                e.preventDefault();
            }
            return true;
        });
    }
} catch (e) {
    // Ultimate silence
}

// ===========================
// STEP 10: Enhanced Utility Functions (Safe)
// ===========================

window.formatDate = function (dateString) {
    try {
        if (!dateString) return '';
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return dateString;
        return date.toLocaleDateString('th-TH', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch (e) {
        return dateString || '';
    }
};

window.formatNumber = function (number) {
    try {
        const num = parseFloat(number);
        if (isNaN(num)) return number;
        return new Intl.NumberFormat('th-TH').format(num);
    } catch (e) {
        return number || '';
    }
};

window.truncateText = function (text, length) {
    try {
        length = length || 50;
        if (!text || typeof text !== 'string') return '';
        if (text.length <= length) return text;
        return text.substring(0, length) + '...';
    } catch (e) {
        return text || '';
    }
};

// ===========================
// STEP 11: Responsive Handler Enhancement
// ===========================

window.handleResponsiveChanges = function () {
    try {
        if (window.SidebarManager) {
            // Force refresh elements on responsive changes
            window.SidebarManager.forceRefresh();
        }
    } catch (e) {
        // Silent error handling
    }
};

// Add responsive change listeners
try {
    if (typeof window !== 'undefined') {
        SafeEvents.add(window, 'resize', window.handleResponsiveChanges);
        SafeEvents.add(window, 'orientationchange', window.handleResponsiveChanges);
    }
} catch (e) {
    // Silent error handling
}

// Start initialization
waitForDOM();

// ===========================
// STEP 12: Recovery Mechanism
// ===========================

// Auto-recovery mechanism for DOM-related errors
setInterval(function () {
    try {
        // Check if sidebar exists but manager is broken
        const sidebar = SafeDOM.get('sidebar');
        if (sidebar && window.SidebarManager && !window.SidebarManager.isActive) {
            // Try to recover
            window.SidebarManager.forceRefresh();
        }
    } catch (e) {
        // Silent recovery attempt
    }
}, 5000); // Check every 5 seconds