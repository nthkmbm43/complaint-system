// Main JavaScript functions for the complaint system

// Global variables
let currentUser = null;
let currentRole = '';

// Initialize application
document.addEventListener('DOMContentLoaded', function () {
    console.log('Complaint System Initialized');
    initializeComponents();
});

// Initialize components
function initializeComponents() {
    // Initialize form validation
    initializeFormValidation();

    // Initialize notifications
    initializeNotifications();

    // Initialize responsive features
    initializeResponsive();

    // Initialize tooltips
    initializeTooltips();
}

// Form validation functions
function initializeFormValidation() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
        inputs.forEach(input => {
            input.addEventListener('blur', validateField);
            input.addEventListener('input', clearError);
        });
    });
}

function validateField(event) {
    const field = event.target;
    const value = field.value.trim();
    const fieldType = field.type;
    const fieldName = field.name;

    let isValid = true;
    let errorMessage = '';

    // Required field validation
    if (field.hasAttribute('required') && !value) {
        isValid = false;
        errorMessage = 'กรุณากรอกข้อมูลนี้';
    }

    // Email validation
    if (fieldType === 'email' && value) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(value)) {
            isValid = false;
            errorMessage = 'รูปแบบอีเมลไม่ถูกต้อง';
        }
    }

    // Password validation
    if (fieldType === 'password' && value) {
        if (value.length < 6) {
            isValid = false;
            errorMessage = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
        }
    }

    // Phone validation
    if (fieldName === 'phone' && value) {
        const phoneRegex = /^[0-9]{10}$/;
        if (!phoneRegex.test(value.replace(/[-\s]/g, ''))) {
            isValid = false;
            errorMessage = 'เบอร์โทรศัพท์ต้องเป็นตัวเลข 10 หลัก';
        }
    }

    // Student ID validation
    if (fieldName === 'student_id' && value) {
        if (value.length !== 13) {
            isValid = false;
            errorMessage = 'รหัสนักศึกษาต้องมี 13 หลักรวมขีด (-)';
        }
    }

    showFieldError(field, isValid, errorMessage);
    return isValid;
}

function clearError(event) {
    const field = event.target;
    const formGroup = field.closest('.form-group');
    if (formGroup) {
        formGroup.classList.remove('error');
    }
}

function showFieldError(field, isValid, errorMessage) {
    const formGroup = field.closest('.form-group');
    if (!formGroup) return;

    if (isValid) {
        formGroup.classList.remove('error');
    } else {
        formGroup.classList.add('error');
        const errorElement = formGroup.querySelector('.error-message');
        if (errorElement) {
            errorElement.textContent = errorMessage;
        }
    }
}

// Notification system
function initializeNotifications() {
    // Remove notifications after 5 seconds
    const notifications = document.querySelectorAll('.notification');
    notifications.forEach(notification => {
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    });
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
        <div style="font-weight: bold; margin-bottom: 5px;">
            ${getNotificationIcon(type)} ${getNotificationTitle(type)}
        </div>
        <div>${message}</div>
        <button onclick="this.parentElement.remove()" style="position: absolute; top: 10px; right: 10px; background: none; border: none; font-size: 18px; cursor: pointer;">&times;</button>
    `;

    // Style the notification
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: white;
        border-left: 5px solid ${getNotificationColor(type)};
        padding: 15px 40px 15px 20px;
        border-radius: 10px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        max-width: 300px;
        z-index: 9999;
        animation: slideInRight 0.5s ease-out;
    `;

    document.body.appendChild(notification);

    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
}

function getNotificationIcon(type) {
    const icons = {
        'success': '✅',
        'error': '❌',
        'warning': '⚠️',
        'info': 'ℹ️'
    };
    return icons[type] || 'ℹ️';
}

function getNotificationTitle(type) {
    const titles = {
        'success': 'สำเร็จ',
        'error': 'ข้อผิดพลาด',
        'warning': 'คำเตือน',
        'info': 'แจ้งเตือน'
    };
    return titles[type] || 'แจ้งเตือน';
}

function getNotificationColor(type) {
    const colors = {
        'success': '#28a745',
        'error': '#dc3545',
        'warning': '#ffc107',
        'info': '#17a2b8'
    };
    return colors[type] || '#17a2b8';
}

// Responsive features
function initializeResponsive() {
    // Mobile menu toggle
    const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', toggleMobileMenu);
    }

    // Close mobile menu when clicking outside
    document.addEventListener('click', function (e) {
        const sidebar = document.querySelector('.sidebar');
        const toggle = document.querySelector('.mobile-menu-toggle');

        if (sidebar && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
            sidebar.classList.remove('active');
        }
    });
}

function toggleMobileMenu() {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.classList.toggle('active');
    }
}

// Tooltip initialization
function initializeTooltips() {
    const tooltipElements = document.querySelectorAll('[title]');
    tooltipElements.forEach(element => {
        element.addEventListener('mouseenter', showTooltip);
        element.addEventListener('mouseleave', hideTooltip);
    });
}

function showTooltip(event) {
    const element = event.target;
    const tooltipText = element.getAttribute('title');

    if (!tooltipText) return;

    // Remove title to prevent default tooltip
    element.setAttribute('data-original-title', tooltipText);
    element.removeAttribute('title');

    // Create tooltip
    const tooltip = document.createElement('div');
    tooltip.className = 'custom-tooltip';
    tooltip.textContent = tooltipText;
    tooltip.style.cssText = `
        position: absolute;
        background: rgba(0,0,0,0.8);
        color: white;
        padding: 8px 12px;
        border-radius: 5px;
        font-size: 12px;
        z-index: 9999;
        pointer-events: none;
    `;

    document.body.appendChild(tooltip);

    // Position tooltip
    const rect = element.getBoundingClientRect();
    tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
    tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
}

function hideTooltip(event) {
    const element = event.target;
    const originalTitle = element.getAttribute('data-original-title');

    if (originalTitle) {
        element.setAttribute('title', originalTitle);
        element.removeAttribute('data-original-title');
    }

    const tooltip = document.querySelector('.custom-tooltip');
    if (tooltip) {
        tooltip.remove();
    }
}

// Utility functions
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('th-TH', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

function formatDateTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('th-TH', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function sanitizeInput(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Loading states
function showLoading(element) {
    if (element) {
        element.innerHTML = '<div class="loading-spinner"></div>';
        element.disabled = true;
    }
}

function hideLoading(element, originalContent) {
    if (element) {
        element.innerHTML = originalContent;
        element.disabled = false;
    }
}

// AJAX helper functions
function makeRequest(url, options = {}) {
    const defaultOptions = {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        },
    };

    return fetch(url, { ...defaultOptions, ...options })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .catch(error => {
            console.error('Request failed:', error);
            showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
            throw error;
        });
}

// Session management
function checkSession() {
    // Check if user session is still valid
    return makeRequest('/api/check-session.php')
        .then(response => {
            if (!response.valid) {
                window.location.href = '/login.php';
            }
        })
        .catch(() => {
            // Handle session check failure
        });
}

// Auto-save functionality
function setupAutoSave(formId, saveUrl) {
    const form = document.getElementById(formId);
    if (!form) return;

    const inputs = form.querySelectorAll('input, textarea, select');
    const debouncedSave = debounce(() => {
        const formData = new FormData(form);
        makeRequest(saveUrl, {
            method: 'POST',
            body: formData
        }).then(() => {
            showNotification('บันทึกอัตโนมัติเรียบร้อย', 'info');
        });
    }, 2000);

    inputs.forEach(input => {
        input.addEventListener('input', debouncedSave);
    });
}

// Export functions for global use
window.showNotification = showNotification;
window.formatDate = formatDate;
window.formatDateTime = formatDateTime;
window.makeRequest = makeRequest;