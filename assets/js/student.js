// Student-specific JavaScript functions

// Student login functions
function handleStudentLogin() {
    const form = document.getElementById('student-login-form');
    if (!form) return;

    const formData = new FormData(form);
    const loginBtn = form.querySelector('button[type="submit"]');

    // Show loading
    showLoading(loginBtn);

    // Validate form
    if (!validateStudentLoginForm(form)) {
        hideLoading(loginBtn, '🔑 เข้าสู่ระบบ');
        return;
    }

    // Submit login request
    makeRequest('login.php', {
        method: 'POST',
        body: formData
    })
        .then(response => {
            if (response.success) {
                showNotification('เข้าสู่ระบบสำเร็จ', 'success');
                window.location.href = response.redirect || 'index.php';
            } else {
                showNotification(response.message || 'เข้าสู่ระบบไม่สำเร็จ', 'error');
            }
        })
        .catch(error => {
            showNotification('เกิดข้อผิดพลาดในการเข้าสู่ระบบ', 'error');
        })
        .finally(() => {
            hideLoading(loginBtn, '🔑 เข้าสู่ระบบ');
        });
}

function validateStudentLoginForm(form) {
    const studentId = form.querySelector('input[name="student_id"]');
    const password = form.querySelector('input[name="password"]');

    let isValid = true;

    // Validate student ID
    if (!studentId.value.trim()) {
        showFieldError(studentId, false, 'กรุณากรอกรหัสนักศึกษา');
        isValid = false;
    } else if (studentId.value.length !== 13) {
        showFieldError(studentId, false, 'รหัสนักศึกษาต้องมี 13 หลักรวมขีด (-)');
        isValid = false;
    }

    // Validate password
    if (!password.value.trim()) {
        showFieldError(password, false, 'กรุณากรอกรหัสผ่าน');
        isValid = false;
    }

    return isValid;
}

// Student registration functions
function handleStudentRegistration() {
    const form = document.getElementById('student-register-form');
    if (!form) return;

    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');

    // Show loading
    showLoading(submitBtn);

    // Validate form
    if (!validateStudentRegisterForm(form)) {
        hideLoading(submitBtn, '✨ ลงทะเบียน');
        return;
    }

    // Submit registration request
    makeRequest('register.php', {
        method: 'POST',
        body: formData
    })
        .then(response => {
            if (response.success) {
                showNotification('ลงทะเบียนสำเร็จ กรุณาเข้าสู่ระบบ', 'success');
                setTimeout(() => {
                    window.location.href = 'login.php';
                }, 2000);
            } else {
                showNotification(response.message || 'ลงทะเบียนไม่สำเร็จ', 'error');
            }
        })
        .catch(error => {
            showNotification('เกิดข้อผิดพลาดในการลงทะเบียน', 'error');
        })
        .finally(() => {
            hideLoading(submitBtn, '✨ ลงทะเบียน');
        });
}

function validateStudentRegisterForm(form) {
    const fields = [
        { name: 'student_id', required: true, length: 13 },
        { name: 'first_name', required: true },
        { name: 'last_name', required: true },
        { name: 'faculty', required: true },
        { name: 'major', required: true },
        { name: 'email', required: true, type: 'email' },
        { name: 'phone', required: true, type: 'phone' },
        { name: 'password', required: true, minLength: 6 },
        { name: 'confirm_password', required: true }
    ];

    let isValid = true;

    fields.forEach(field => {
        const input = form.querySelector(`input[name="${field.name}"], select[name="${field.name}"]`);
        if (!input) return;

        const value = input.value.trim();

        // Required validation
        if (field.required && !value) {
            showFieldError(input, false, 'กรุณากรอกข้อมูลนี้');
            isValid = false;
            return;
        }

        // Length validation
        if (field.length && value.length !== field.length) {
            showFieldError(input, false, `ต้องมี ${field.length} หลัก`);
            isValid = false;
            return;
        }

        // Min length validation
        if (field.minLength && value.length < field.minLength) {
            showFieldError(input, false, `ต้องมีอย่างน้อย ${field.minLength} ตัวอักษร`);
            isValid = false;
            return;
        }

        // Email validation
        if (field.type === 'email' && value) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(value)) {
                showFieldError(input, false, 'รูปแบบอีเมลไม่ถูกต้อง');
                isValid = false;
                return;
            }
        }

        // Phone validation
        if (field.type === 'phone' && value) {
            const phoneRegex = /^[0-9]{10}$/;
            if (!phoneRegex.test(value.replace(/[-\s]/g, ''))) {
                showFieldError(input, false, 'เบอร์โทรศัพท์ต้องเป็นตัวเลข 10 หลัก');
                isValid = false;
                return;
            }
        }

        // Clear error if valid
        showFieldError(input, true, '');
    });

    // Check password confirmation
    const password = form.querySelector('input[name="password"]');
    const confirmPassword = form.querySelector('input[name="confirm_password"]');

    if (password && confirmPassword && password.value !== confirmPassword.value) {
        showFieldError(confirmPassword, false, 'รหัสผ่านไม่ตรงกัน');
        isValid = false;
    }

    // Check agreement
    const agreement = form.querySelector('input[name="agreement"]');
    if (agreement && !agreement.checked) {
        showNotification('กรุณายอมรับเงื่อนไขการใช้งาน', 'warning');
        isValid = false;
    }

    return isValid;
}

// Complaint submission
function submitComplaint() {
    const form = document.getElementById('complaint-form');
    if (!form) return;

    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');

    // Show loading
    showLoading(submitBtn);

    // Validate form
    if (!validateComplaintForm(form)) {
        hideLoading(submitBtn, '📤 ส่งข้อร้องเรียน');
        return;
    }

    // Submit complaint
    makeRequest('complaint.php', {
        method: 'POST',
        body: formData
    })
        .then(response => {
            if (response.success) {
                showNotification(`ส่งข้อร้องเรียนสำเร็จ รหัสอ้างอิง: ${response.complaint_id}`, 'success');
                form.reset();
                // Redirect to tracking page
                setTimeout(() => {
                    window.location.href = 'tracking.php';
                }, 2000);
            } else {
                showNotification(response.message || 'ส่งข้อร้องเรียนไม่สำเร็จ', 'error');
            }
        })
        .catch(error => {
            showNotification('เกิดข้อผิดพลาดในการส่งข้อร้องเรียน', 'error');
        })
        .finally(() => {
            hideLoading(submitBtn, '📤 ส่งข้อร้องเรียน');
        });
}

function validateComplaintForm(form) {
    const requiredFields = ['category', 'title', 'description'];
    let isValid = true;

    requiredFields.forEach(fieldName => {
        const field = form.querySelector(`[name="${fieldName}"]`);
        if (!field || !field.value.trim()) {
            showFieldError(field, false, 'กรุณากรอกข้อมูลนี้');
            isValid = false;
        }
    });

    return isValid;
}

// Password visibility toggle
function togglePasswordVisibility(inputId, button) {
    const input = document.getElementById(inputId);
    if (!input) return;

    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';

    // Update button icon
    if (button) {
        button.textContent = isPassword ? '🙈' : '👁️';
        button.setAttribute('title', isPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
    }
}

// File upload handling
function handleFileUpload(input) {
    const files = input.files;
    const maxSize = 10 * 1024 * 1024; // 10MB
    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

    let isValid = true;

    Array.from(files).forEach(file => {
        // Check file size
        if (file.size > maxSize) {
            showNotification(`ไฟล์ ${file.name} มีขนาดเกิน 10MB`, 'warning');
            isValid = false;
        }

        // Check file type
        if (!allowedTypes.includes(file.type)) {
            showNotification(`ไฟล์ ${file.name} ไม่รองรับ`, 'warning');
            isValid = false;
        }
    });

    if (!isValid) {
        input.value = '';
    } else {
        showFilePreview(files);
    }
}

function showFilePreview(files) {
    const previewContainer = document.getElementById('file-preview');
    if (!previewContainer) return;

    previewContainer.innerHTML = '';

    Array.from(files).forEach((file, index) => {
        const fileItem = document.createElement('div');
        fileItem.className = 'file-preview-item';
        fileItem.innerHTML = `
            <span class="file-icon">${getFileIcon(file.type)}</span>
            <span class="file-name">${file.name}</span>
            <span class="file-size">(${formatFileSize(file.size)})</span>
            <button type="button" onclick="removeFile(${index})" class="remove-file-btn">×</button>
        `;
        previewContainer.appendChild(fileItem);
    });
}

function getFileIcon(fileType) {
    if (fileType.startsWith('image/')) return '🖼️';
    if (fileType === 'application/pdf') return '📄';
    if (fileType.includes('word')) return '📝';
    return '📎';
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function removeFile(index) {
    const fileInput = document.querySelector('input[type="file"]');
    if (!fileInput) return;

    const dt = new DataTransfer();
    const files = Array.from(fileInput.files);

    files.forEach((file, i) => {
        if (i !== index) {
            dt.items.add(file);
        }
    });

    fileInput.files = dt.files;
    showFilePreview(fileInput.files);
}

// Search and filter functions
function initializeSearch() {
    const searchInput = document.getElementById('search-input');
    if (!searchInput) return;

    const debouncedSearch = debounce((query) => {
        performSearch(query);
    }, 300);

    searchInput.addEventListener('input', (e) => {
        debouncedSearch(e.target.value.trim());
    });
}

function performSearch(query) {
    const searchUrl = new URL(window.location.href);
    searchUrl.searchParams.set('search', query);
    searchUrl.searchParams.set('page', '1');

    window.location.href = searchUrl.toString();
}

function filterComplints(filterType, filterValue) {
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set(filterType, filterValue);
    currentUrl.searchParams.set('page', '1');

    window.location.href = currentUrl.toString();
}

// Auto-save draft functionality
function initializeAutoSave() {
    const forms = document.querySelectorAll('form[data-autosave]');
    forms.forEach(form => {
        const formId = form.id;
        if (!formId) return;

        const debouncedSave = debounce(() => {
            saveDraft(formId);
        }, 2000);

        const inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            input.addEventListener('input', debouncedSave);
        });

        // Load saved draft
        loadDraft(formId);
    });
}

function saveDraft(formId) {
    const form = document.getElementById(formId);
    if (!form) return;

    const formData = new FormData(form);
    const draftData = {};

    for (let [key, value] of formData.entries()) {
        draftData[key] = value;
    }

    localStorage.setItem(`draft_${formId}`, JSON.stringify(draftData));
    showNotification('บันทึกร่างอัตโนมัติ', 'info');
}

function loadDraft(formId) {
    const draftData = localStorage.getItem(`draft_${formId}`);
    if (!draftData) return;

    try {
        const data = JSON.parse(draftData);
        const form = document.getElementById(formId);

        Object.keys(data).forEach(key => {
            const field = form.querySelector(`[name="${key}"]`);
            if (field) {
                field.value = data[key];
            }
        });

        showNotification('โหลดร่างที่บันทึกไว้', 'info');
    } catch (error) {
        console.error('Error loading draft:', error);
    }
}

function clearDraft(formId) {
    localStorage.removeItem(`draft_${formId}`);
    showNotification('ล้างร่างเรียบร้อย', 'info');
}

// Initialize student-specific features
document.addEventListener('DOMContentLoaded', function () {
    // Initialize search functionality
    initializeSearch();

    // Initialize auto-save
    initializeAutoSave();

    // Initialize file upload handlers
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(input => {
        input.addEventListener('change', () => handleFileUpload(input));
    });

    // Initialize student login form
    const loginForm = document.getElementById('student-login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', (e) => {
            e.preventDefault();
            handleStudentLogin();
        });
    }

    // Initialize student registration form
    const registerForm = document.getElementById('student-register-form');
    if (registerForm) {
        registerForm.addEventListener('submit', (e) => {
            e.preventDefault();
            handleStudentRegistration();
        });
    }

    // Initialize complaint form
    const complaintForm = document.getElementById('complaint-form');
    if (complaintForm) {
        complaintForm.addEventListener('submit', (e) => {
            e.preventDefault();
            submitComplaint();
        });
    }
});

// Export functions for global use
window.togglePasswordVisibility = togglePasswordVisibility;
window.handleFileUpload = handleFileUpload;
window.filterComplints = filterComplints;
window.saveDraft = saveDraft;
window.loadDraft = loadDraft;
window.clearDraft = clearDraft;