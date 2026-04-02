<?php
// staff/users.php - หน้าจัดการข้อมูลนักศึกษา (ใช้ AJAX + CSS สวยงาม)
define('SECURE_ACCESS', true);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();
requireStaffAccess();
// ตรวจสอบการล็อกอินและสิทธิ์
if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

if (!hasRole('teacher')) {
    $accessDeniedMessage = "หน้านี้สำหรับอาจารย์และผู้ดูแลระบบเท่านั้น เนื่องจากคุณไม่มีสิทธิ์เข้าถึงข้อมูลนักศึกษา";
    $accessDeniedRedirect = "../index.php";
}

$db = getDB();
$user = getCurrentUser();

// ตรวจสอบสิทธิ์
$userPermission = $_SESSION['permission'] ?? 0;
$isAdmin = ($userPermission >= 2); // ผู้ดำเนินการและผู้ดูแลระบบสามารถจัดการนักศึกษาได้
$canEditUsers = ($userPermission >= 1);
$canViewOnly = false;

// ดึงข้อมูลพื้นฐานสำหรับแสดงผล (สำหรับ fallback)
$currentPage = intval($_GET['page'] ?? 1);
$itemsPerPage = 20;

// ดึงสถิติพื้นฐาน
$stats = [
    'total' => 0,
    'active' => 0,
    'suspended' => 0,
    'today' => 0
];

try {
    $stats = [
        'total' => $db->count('student'),
        'active' => $db->count('student', 'Stu_status = 1'),
        'suspended' => $db->count('student', 'Stu_status = 0'),
        'today' => $db->count('student', 'DATE(created_at) = CURDATE()')
    ];
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
}

// ดึงข้อมูลคณะสำหรับ dropdown
$faculties = [];
try {
    $faculties = $db->fetchAll("SELECT * FROM organization_unit WHERE Unit_parent_id IS NULL OR Unit_parent_id = 0 ORDER BY Unit_name");
} catch (Exception $e) {
    error_log("Faculties error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข้อมูลนักศึกษา - ระบบร้องเรียน</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Enhanced CSS with improved modal design and responsive layout */
        body {
            font-family: 'Sarabun', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            margin: 0;
            padding: 0;
        }

        .users-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            color: white;
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);
        }

        .users-title {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .users-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 300;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 2rem 1.5rem;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid #f1f5f9;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
        }

        .stat-card.total::before {
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .stat-card.active::before {
            background: linear-gradient(90deg, #56ab2f, #a8e6cf);
        }

        .stat-card.suspended::before {
            background: linear-gradient(90deg, #ff6b6b, #ffa8a8);
        }

        .stat-card.today::before {
            background: linear-gradient(90deg, #4ecdc4, #44a08d);
        }

        .stat-icon {
            font-size: 3rem;
            padding: 1.2rem;
            border-radius: 16px;
            flex-shrink: 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .stat-card.total .stat-icon {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .stat-card.active .stat-icon {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
        }

        .stat-card.suspended .stat-icon {
            background: linear-gradient(135deg, #ff6b6b, #ffa8a8);
            color: white;
        }

        .stat-card.today .stat-icon {
            background: linear-gradient(135deg, #4ecdc4, #44a08d);
            color: white;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2d3748;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 1rem;
            color: #718096;
            font-weight: 500;
        }

        .users-controls {
            background: white;
            padding: 2.5rem;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
            border: 1px solid #f1f5f9;
        }

        .filters-section {
            display: grid;
            grid-template-columns: 2fr 1fr 1.5fr auto auto auto;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1.5rem;
            min-height: 60px;
        }

        .filter-group {
            min-width: 0;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
            font-family: 'Sarabun', sans-serif;
            box-sizing: border-box;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }

        .filter-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            justify-content: flex-end;
            white-space: nowrap;
        }

        .actions-section {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
            display: flex;
            justify-content: flex-end;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 1rem 2rem;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            font-family: 'Sarabun', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.3);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #c82333, #bd2130);
        }

        .btn-success {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
            box-shadow: 0 6px 20px rgba(86, 171, 47, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
            box-shadow: 0 6px 20px rgba(240, 147, 251, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3);
        }

        .btn-confirm-delete {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }

        .btn-confirm-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, #4ecdc4, #44a08d);
            color: white;
            box-shadow: 0 6px 20px rgba(78, 205, 196, 0.3);
        }

        .btn-sm {
            padding: 0.75rem 1rem;
            font-size: 0.9rem;
            border-radius: 8px;
        }

        .refresh-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 1rem;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.3s ease;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
        }

        .refresh-btn:hover {
            transform: rotate(90deg) scale(1.1);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }

        .users-table-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            border: 1px solid #f1f5f9;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        .users-table thead {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .users-table th {
            padding: 1.5rem 1.25rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.75px;
        }

        .users-table tbody tr {
            transition: all 0.3s ease;
            border-bottom: 1px solid #f1f5f9;
        }

        .users-table tbody tr:hover {
            background: linear-gradient(90deg, #f8faff, #ffffff);
            transform: translateX(2px);
        }

        .users-table td {
            padding: 1.5rem 1.25rem;
            vertical-align: middle;
        }

        .student-id {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: #4a5568;
            background: #f8faff;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            display: inline-block;
            font-size: 0.9rem;
        }

        .student-name strong {
            font-size: 1.1rem;
            color: #2d3748;
            font-weight: 600;
        }

        .unit-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .major,
        .faculty {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
        }

        .major {
            color: #4a5568;
            font-weight: 600;
        }

        .faculty {
            color: #718096;
            font-weight: 500;
        }

        .unit-icon {
            font-size: 1.1rem;
            flex-shrink: 0;
            min-width: 1.2rem;
            text-align: center;
        }

        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .phone,
        .email {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            color: #4a5568;
        }

        .status-badge {
            padding: 0.6rem 1.2rem;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 600;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
            width: fit-content;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .status-badge.active {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
        }

        .status-badge.suspended {
            background: linear-gradient(135deg, #ff6b6b, #ffa8a8);
            color: white;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .action-buttons .btn {
            min-width: 3rem;
            height: 3rem;
            padding: 0;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .loading {
            text-align: center;
            padding: 4rem;
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            margin: 2rem 0;
        }

        .loading-spinner {
            border: 4px solid #f1f5f9;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1.5rem;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .toast {
            position: fixed;
            top: 2rem;
            right: 2rem;
            padding: 1.25rem 2rem;
            border-radius: 16px;
            color: white;
            font-weight: 600;
            z-index: 1000;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            min-width: 350px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
        }

        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }

        .toast.success {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
        }

        .toast.error {
            background: linear-gradient(135deg, #ff6b6b, #ffa8a8);
        }

        .toast.warning {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }

        /* Enhanced Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1500;
            left: 0;
            top: 0;
            width: 100%;
            height: 100vh;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            animation: fadeIn 0.3s ease;
            overflow-y: auto;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            box-sizing: border-box;
        }

        .modal-content {
            background-color: white;
            border-radius: 20px;
            width: 100%;
            max-width: 700px;
            max-height: calc(100vh - 4rem);
            overflow: hidden;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.4s ease;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2rem 2.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 20px 20px 0 0;
            flex-shrink: 0;
        }

        .modal-title {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .close {
            font-size: 1.8rem;
            font-weight: bold;
            cursor: pointer;
            color: rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
            width: 2.5rem;
            height: 2.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            flex-shrink: 0;
        }

        .close:hover {
            color: white;
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg) scale(1.1);
        }

        .modal-content form {
            padding: 2.5rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            overflow-y: auto;
            flex: 1;
        }

        .form-group {
            margin-bottom: 0;
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 600;
            color: #2d3748;
            font-size: 1rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 1.2rem 1.5rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
            font-family: 'Sarabun', sans-serif;
            box-sizing: border-box;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
            flex: 1;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }

        /* Form Validation Styles - Inline Alerts */
        .form-group.has-error input,
        .form-group.has-error select {
            border-color: #e53e3e;
            background-color: #fff5f5;
        }

        .form-group.has-error input:focus,
        .form-group.has-error select:focus {
            border-color: #e53e3e;
            box-shadow: 0 0 0 4px rgba(229, 62, 62, 0.15);
        }

        .form-group.has-success input,
        .form-group.has-success select {
            border-color: #38a169;
            background-color: #f0fff4;
        }

        .form-group.has-success input:focus,
        .form-group.has-success select:focus {
            border-color: #38a169;
            box-shadow: 0 0 0 4px rgba(56, 161, 105, 0.15);
        }

        .inline-alert {
            display: none;
            margin-top: 0.5rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .inline-alert.error {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #fff5f5, #fed7d7);
            border: 1px solid #fc8181;
            color: #c53030;
        }

        .inline-alert.success {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #f0fff4, #c6f6d5);
            border: 1px solid #68d391;
            color: #276749;
        }

        .inline-alert.warning {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #fffaf0, #feebc8);
            border: 1px solid #f6ad55;
            color: #c05621;
        }

        .inline-alert .alert-icon {
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .inline-alert .alert-message {
            flex: 1;
            line-height: 1.4;
        }

        .validation-spinner {
            display: none;
            width: 16px;
            height: 16px;
            border: 2px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        @keyframes spin {
            to {
                transform: translateY(-50%) rotate(360deg);
            }
        }

        .form-group.validating .validation-spinner {
            display: block;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper input {
            padding-right: 2.5rem;
        }

        .input-status-icon {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.2rem;
            display: none;
        }

        .form-group.has-error .input-status-icon.error-icon {
            display: block;
            color: #e53e3e;
        }

        .form-group.has-success .input-status-icon.success-icon {
            display: block;
            color: #38a169;
        }

        /* Enhanced Select with Icons */
        .custom-select {
            position: relative;
            display: block;
        }

        .custom-select-button {
            width: 100%;
            padding: 1.2rem 3.5rem 1.2rem 1.5rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            background: white;
            text-align: left;
            cursor: pointer;
            font-size: 1rem;
            font-family: 'Sarabun', sans-serif;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-sizing: border-box;
        }

        .custom-select-button:hover,
        .custom-select-button:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }

        .custom-select-arrow {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }

        .custom-select.open .custom-select-arrow {
            transform: translateY(-50%) rotate(180deg);
        }

        .custom-select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #e2e8f0;
            border-top: none;
            border-radius: 0 0 12px 12px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
        }

        .custom-select.open .custom-select-dropdown {
            display: block;
        }

        .custom-select-option {
            padding: 1rem 1.5rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: background-color 0.2s ease;
            font-size: 0.95rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .custom-select-option:last-child {
            border-bottom: none;
        }

        .custom-select-option:hover {
            background: #f8faff;
        }

        .custom-select-option.selected {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .option-icon {
            font-size: 1.2rem;
            flex-shrink: 0;
            min-width: 1.5rem;
            text-align: center;
        }

        .option-text {
            flex: 1;
            font-weight: 500;
        }

        .faculty-option {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.25rem;
        }

        .faculty-option .option-text {
            font-weight: 600;
            color: #2d3748;
        }

        .faculty-option .faculty-name {
            font-size: 0.8rem;
            color: #718096;
            margin-left: 2rem;
        }

        .modal-actions {
            display: flex;
            gap: 1.5rem;
            justify-content: flex-end;
            margin-top: auto;
            padding-top: 2rem;
            border-top: 2px solid #f1f5f9;
            grid-column: 1 / -1;
            flex-shrink: 0;
        }

        /* Suspend Modal Styles */
        .suspend-modal .modal-content {
            max-width: 600px;
            min-height: 500px;
        }

        .suspend-modal .modal-header {
            background: linear-gradient(135deg, #ff6b6b, #ffa8a8);
        }

        .suspend-form {
            padding: 2.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            overflow-y: auto;
            flex: 1;
        }

        .student-info {
            background: #fff5f5;
            padding: 2rem 1.5rem;
            border-radius: 16px;
            border: 2px solid #fed7d7;
            text-align: center;
            flex-shrink: 0;
        }

        .student-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            margin: 0 auto 1rem;
        }

        .student-name-display {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.5rem;
        }

        .student-id-display {
            font-family: 'Courier New', monospace;
            color: #718096;
            font-size: 0.9rem;
        }

        .suspend-form .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .suspend-form .form-group textarea {
            flex: 1;
            min-height: 150px;
            resize: vertical;
        }

        .suspend-form .modal-actions {
            margin-top: auto;
            padding-top: 2rem;
            border-top: 2px solid #f1f5f9;
            flex-shrink: 0;
        }

        /* Release Modal Styles */
        .release-modal .modal-content {
            max-width: 600px;
            min-height: 500px;
        }

        .release-modal .modal-header {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
        }

        .release-form {
            padding: 2.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            overflow-y: auto;
            flex: 1;
        }

        .release-student-info {
            background: #f0fff4;
            padding: 2rem 1.5rem;
            border-radius: 16px;
            border: 2px solid #c6f6d5;
            text-align: center;
            flex-shrink: 0;
        }

        .release-student-info .student-avatar {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
        }

        .suspension-info {
            background: #fff5f5;
            padding: 1.5rem;
            border-radius: 12px;
            border: 2px solid #fed7d7;
            margin-bottom: 1rem;
        }

        .suspension-title {
            font-size: 1rem;
            font-weight: 600;
            color: #c53030;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .suspension-details {
            display: grid;
            gap: 0.75rem;
        }

        .suspension-detail {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }

        .detail-label-small {
            font-size: 0.9rem;
            color: #718096;
            font-weight: 500;
            min-width: 120px;
            flex-shrink: 0;
        }

        .detail-value-small {
            font-size: 0.9rem;
            color: #2d3748;
            flex: 1;
        }

        .suspend-reason-text {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            font-style: italic;
            color: #4a5568;
            line-height: 1.5;
        }

        .release-form .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .release-form .form-group textarea {
            flex: 1;
            min-height: 120px;
            resize: vertical;
        }

        .release-form .modal-actions {
            margin-top: auto;
            padding-top: 2rem;
            border-top: 2px solid #f1f5f9;
            flex-shrink: 0;
        }

        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            padding: 2rem;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            margin-top: 2rem;
            border: 1px solid #f1f5f9;
        }

        .pagination {
            display: flex;
            gap: 0.5rem;
        }

        .page-link {
            padding: 0.8rem 1.2rem;
            background: white;
            border: 2px solid #e2e8f0;
            color: #4a5568;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .page-link:hover {
            background: #f8faff;
            border-color: #667eea;
            color: #667eea;
            transform: translateY(-2px);
        }

        .page-link.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-color: #667eea;
            color: white;
        }

        /* Student Detail Modal Styles */
        .student-detail-modal .modal-content {
            max-width: 900px;
            max-height: calc(100vh - 2rem);
        }

        .student-detail-header {
            background: linear-gradient(135deg, #4ecdc4, #44a08d);
            color: white;
            padding: 2rem 2.5rem;
            border-radius: 20px 20px 0 0;
        }

        .student-detail-body {
            padding: 2.5rem;
            overflow-y: auto;
            flex: 1;
        }

        .detail-section {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .detail-section:last-child {
            border-bottom: none;
        }

        .section-title {
            color: #2d3748;
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .detail-item {
            background: #f8faff;
            padding: 1.5rem;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
        }

        .detail-label {
            font-size: 0.9rem;
            color: #718096;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .detail-value {
            font-size: 1.1rem;
            color: #2d3748;
            font-weight: 600;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: #f8faff;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2d3748;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #718096;
            margin-top: 0.5rem;
        }

        .recent-requests {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .request-item {
            background: #f8faff;
            padding: 1.5rem;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
        }

        .request-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .request-type {
            font-weight: 600;
            color: #4a5568;
        }

        .request-date {
            color: #718096;
            font-size: 0.9rem;
        }

        .request-title {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.5rem;
        }

        .request-content {
            color: #4a5568;
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .request-footer {
            display: flex;
            gap: 1rem;
        }

        .request-status,
        .request-level {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .request-status.status-0 {
            background: #fed7d7;
            color: #c53030;
        }

        .request-status.status-1 {
            background: #fef5e7;
            color: #d69e2e;
        }

        .request-status.status-2 {
            background: #e6fffa;
            color: #319795;
        }

        .request-status.status-3 {
            background: #f0fff4;
            color: #38a169;
        }

        .suspend-history {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .suspend-item {
            background: #fff5f5;
            padding: 1.5rem;
            border-radius: 12px;
            border: 2px solid #fed7d7;
        }

        .suspend-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .still-suspended {
            color: #c53030;
            font-weight: 600;
        }

        .release-date {
            color: #38a169;
            font-weight: 600;
        }

        .eval-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .eval-item {
            text-align: center;
            padding: 1.5rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 12px;
        }

        .eval-score,
        .eval-count {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .eval-label {
            font-size: 1rem;
            opacity: 0.9;
        }

        /* Custom Confirm Dialog */
        .custom-confirm {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .confirm-content {
            background: white;
            padding: 0;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.4s ease;
        }

        .confirm-header {
            background: linear-gradient(135deg, #ff6b6b, #ffa8a8);
            color: white;
            padding: 1.5rem 2rem;
            border-radius: 20px 20px 0 0;
            text-align: center;
        }

        .confirm-body {
            padding: 2rem;
            text-align: center;
        }

        .confirm-input {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            margin: 1rem 0;
            font-size: 1rem;
            text-align: center;
            font-family: 'Courier New', monospace;
            box-sizing: border-box;
        }

        .confirm-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .filters-section {
                grid-template-columns: 1fr 1fr 1fr;
                gap: 1rem;
            }

            .filter-actions {
                grid-column: 1 / -1;
                justify-content: center;
                margin-top: 1rem;
            }

            .stats-row {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filters-section {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .filter-actions {
                grid-column: 1;
                justify-content: stretch;
            }

            .filter-actions .btn,
            .filter-actions .refresh-btn {
                flex: 1;
                text-align: center;
            }

            .users-table-container {
                overflow-x: auto;
            }

            .users-table {
                min-width: 600px;
            }

            .pagination-container {
                flex-direction: column;
                gap: 1rem;
            }

            .modal {
                padding: 1rem;
                align-items: flex-start;
                padding-top: 2rem;
            }

            .modal-content {
                max-height: calc(100vh - 2rem);
                width: 100%;
                margin: 0;
            }

            .modal-content form {
                grid-template-columns: 1fr;
                gap: 1.5rem;
                padding: 2rem;
            }

            .suspend-form {
                padding: 2rem;
            }

            .release-form {
                padding: 2rem;
            }

            .custom-select-dropdown {
                max-height: 200px;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .stats-row {
                grid-template-columns: 1fr 1fr;
            }

            .eval-stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .modal {
                padding: 0.5rem;
                padding-top: 1rem;
            }

            .modal-content form {
                padding: 1.5rem;
            }

            .suspend-form {
                padding: 1.5rem;
            }

            .release-form {
                padding: 1.5rem;
            }
        }

        .no-data {
            text-align: center;
            padding: 4rem 2rem;
            color: #718096;
        }

        .no-data div {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        .no-data p {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
        }

        .faculty-select .custom-select-dropdown {
            max-height: 300px;
            overflow-y: auto;
        }

        .faculty-select {
            position: relative;
        }

        .faculty-select select {
            transform: translateY(0);
            position: relative;
            z-index: 10;
        }

        .faculty-select select option {
            padding: 0.5rem;
        }
    </style>
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>
    <?php if (isset($accessDeniedMessage)): ?>
        <script>
            window.addEventListener('DOMContentLoaded', function() {
                showAccessDenied(
                    "<?php echo $accessDeniedMessage; ?>",
                    "<?php echo $accessDeniedRedirect; ?>"
                );
            });
        </script>
    <?php endif; ?>

    <div class="main-content">
        <?php include '../includes/header.php'; ?>

        <div class="page-content">
            <!-- Header -->
            <div class="users-header">
                <div class="users-title">
                    <span>👨‍🎓</span>
                    จัดการข้อมูลนักศึกษา
                </div>
                <div class="users-subtitle">
                    จัดการข้อมูลนักศึกษาและเจ้าหน้าที่ในระบบ
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card total">
                    <div class="stat-icon">👥</div>
                    <div class="stat-content">
                        <div class="stat-number" id="totalStudents"><?php echo number_format($stats['total']); ?></div>
                        <div class="stat-label">นักศึกษาทั้งหมด</div>
                    </div>
                </div>
                <div class="stat-card active">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <div class="stat-number" id="activeStudents"><?php echo number_format($stats['active']); ?></div>
                        <div class="stat-label">ใช้งานอยู่</div>
                    </div>
                </div>
                <div class="stat-card suspended">
                    <div class="stat-icon">⛔</div>
                    <div class="stat-content">
                        <div class="stat-number" id="suspendedStudents"><?php echo number_format($stats['suspended']); ?></div>
                        <div class="stat-label">ถูกระงับ</div>
                    </div>
                </div>
                <div class="stat-card today">
                    <div class="stat-icon">📈</div>
                    <div class="stat-content">
                        <div class="stat-number" id="todayStudents"><?php echo number_format($stats['today']); ?></div>
                        <div class="stat-label">สมัครวันนี้</div>
                    </div>
                </div>
            </div>

            <!-- Filters and Actions -->
            <div class="users-controls">
                <div class="filters-section">
                    <div class="filter-group">
                        <input type="text" id="searchInput" placeholder="ค้นหารหัส ชื่อ หรืออีเมล..."
                            value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    </div>
                    <div class="filter-group">
                        <select id="statusFilter">
                            <option value="">ทุกสถานะ</option>
                            <option value="1">ใช้งานอยู่</option>
                            <option value="0">ถูกระงับ</option>
                        </select>
                    </div>
                    <div class="filter-group faculty-select">
                        <select id="facultyFilter">
                            <option value="">ทุกคณะ</option>
                            <?php foreach ($faculties as $faculty): ?>
                                <option value="<?php echo $faculty['Unit_id']; ?>"
                                    data-icon="<?php echo $faculty['Unit_icon'] ?? '🏫'; ?>">
                                    <?php echo isset($faculty['Unit_icon']) ? $faculty['Unit_icon'] . ' ' : '🏫 '; ?>
                                    <?php echo htmlspecialchars($faculty['Unit_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button onclick="searchStudents()" class="btn btn-primary">ค้นหา</button>
                        <button onclick="clearFilters()" class="btn btn-secondary">ล้างตัวกรอง</button>
                        <button onclick="refreshData()" class="refresh-btn" title="รีเฟรชข้อมูล">🔄</button>
                    </div>
                </div>

                <?php if ($canEditUsers): ?>
                    <div class="actions-section">
                        <button onclick="showAddStudentModal()" class="btn btn-success">
                            ➕ เพิ่มนักศึกษา
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Loading Indicator -->
            <div class="loading" id="loadingIndicator">
                <div class="loading-spinner"></div>
                <div>กำลังโหลดข้อมูล...</div>
            </div>

            <!-- Students Table -->
            <div class="users-table-container">
                <div class="table-container" id="tableContainer">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>รหัสนักศึกษา</th>
                                <th>ชื่อ-นามสกุล</th>
                                <th>สาขา/คณะ</th>
                                <th>ติดต่อ</th>
                                <th>สถานะ</th>
                                <th>วันที่สมัคร</th>
                                <th>การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="studentsTableBody">
                            <tr>
                                <td colspan="7" class="no-data">
                                    <div>🔭</div>
                                    <p>กำลังโหลดข้อมูล...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div class="pagination-container" id="paginationContainer" style="display: none;">
                <div class="pagination-info" id="paginationInfo"></div>
                <div class="pagination" id="paginationLinks"></div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <?php if ($canEditUsers): ?>
        <!-- Add Student Modal -->
        <div id="addStudentModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <span>👤</span>
                        เพิ่มนักศึกษาใหม่
                    </h3>
                    <span class="close" onclick="closeModal('addStudentModal')">&times;</span>
                </div>
                <form id="addStudentForm">
                    <div class="form-group" id="add_student_id_group">
                        <label for="add_student_id">รหัสนักศึกษา *</label>
                        <div class="input-wrapper">
                            <input type="text" id="add_student_id" name="student_id" required maxlength="13"
                                pattern="[0-9\-]{13}" title="รหัสนักศึกษา 13 หลักรวมขีด (เช่น 66342310001-1)"
                                placeholder="เช่น 66342310001-1">
                            <span class="validation-spinner"></span>
                            <span class="input-status-icon error-icon">❌</span>
                            <span class="input-status-icon success-icon">✓</span>
                        </div>
                        <div class="inline-alert" id="add_student_id_alert">
                            <span class="alert-icon">⚠️</span>
                            <span class="alert-message"></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="add_student_name">ชื่อ-นามสกุล *</label>
                        <input type="text" id="add_student_name" name="student_name" required
                            placeholder="กรอกชื่อ-นามสกุล">
                    </div>
                    <div class="form-group">
                        <label for="add_student_password">รหัสผ่าน *</label>
                        <input type="password" id="add_student_password" name="student_password" required minlength="6"
                            placeholder="รหัสผ่านอย่างน้อย 6 ตัวอักษร">
                    </div>
                    <div class="form-group">
                        <label for="add_unit_id">สาขา</label>
                        <div class="custom-select" data-name="unit_id">
                            <button type="button" class="custom-select-button">
                                <span class="option-icon">🎓</span>
                                <span class="option-text">เลือกสาขา</span>
                                <span class="custom-select-arrow">▼</span>
                            </button>
                            <div class="custom-select-dropdown">
                                <div class="custom-select-option" data-value="0">
                                    <span class="option-icon">🎓</span>
                                    <span class="option-text">เลือกสาขา</span>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="add_unit_id" name="unit_id" value="0">
                    </div>
                    <div class="form-group">
                        <label for="add_student_tel">เบอร์โทรศัพท์</label>
                        <input type="tel" id="add_student_tel" name="student_tel"
                            placeholder="เช่น 0812345678">
                    </div>
                    <div class="form-group" id="add_student_email_group">
                        <label for="add_student_email">อีเมล</label>
                        <div class="input-wrapper">
                            <input type="email" id="add_student_email" name="student_email"
                                placeholder="เช่น student@rmuti.ac.th">
                            <span class="validation-spinner"></span>
                            <span class="input-status-icon error-icon">❌</span>
                            <span class="input-status-icon success-icon">✓</span>
                        </div>
                        <div class="inline-alert" id="add_student_email_alert">
                            <span class="alert-icon">⚠️</span>
                            <span class="alert-message"></span>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" onclick="closeModal('addStudentModal')" class="btn btn-secondary">ยกเลิก</button>
                        <button type="submit" class="btn btn-success" id="addStudentSubmitBtn">เพิ่มนักศึกษา</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Student Modal -->
        <div id="editStudentModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <span>✏️</span>
                        แก้ไขข้อมูลนักศึกษา
                    </h3>
                    <span class="close" onclick="closeModal('editStudentModal')">&times;</span>
                </div>
                <form id="editStudentForm">
                    <input type="hidden" id="edit_student_id" name="student_id">
                    <div class="form-group">
                        <label for="edit_student_name">ชื่อ-นามสกุล *</label>
                        <input type="text" id="edit_student_name" name="student_name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_unit_id">สาขา</label>
                        <div class="custom-select" data-name="unit_id">
                            <button type="button" class="custom-select-button">
                                <span class="option-icon">🎓</span>
                                <span class="option-text">เลือกสาขา</span>
                                <span class="custom-select-arrow">▼</span>
                            </button>
                            <div class="custom-select-dropdown">
                                <div class="custom-select-option" data-value="0">
                                    <span class="option-icon">🎓</span>
                                    <span class="option-text">เลือกสาขา</span>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="edit_unit_id" name="unit_id" value="0">
                    </div>
                    <div class="form-group">
                        <label for="edit_student_tel">เบอร์โทรศัพท์</label>
                        <input type="tel" id="edit_student_tel" name="student_tel">
                    </div>
                    <div class="form-group">
                        <label for="edit_student_email">อีเมล</label>
                        <input type="email" id="edit_student_email" name="student_email">
                    </div>
                    <div class="form-group full-width">
                        <label for="edit_student_password">รหัสผ่านใหม่ (เว้นว่างหากไม่ต้องการเปลี่ยน)</label>
                        <input type="password" id="edit_student_password" name="student_password" minlength="6">
                    </div>
                    <div class="modal-actions">
                        <button type="button" onclick="closeModal('editStudentModal')" class="btn btn-secondary">ยกเลิก</button>
                        <button type="submit" class="btn btn-success">บันทึกการแก้ไข</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Suspend Student Modal -->
        <div id="suspendStudentModal" class="modal suspend-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <span>⛔</span>
                        ระงับบัญชีนักศึกษา
                    </h3>
                    <span class="close" onclick="closeModal('suspendStudentModal')">&times;</span>
                </div>
                <form id="suspendStudentForm" class="suspend-form">
                    <input type="hidden" id="suspend_student_id" name="student_id">

                    <div class="student-info">
                        <div class="student-avatar">👤</div>
                        <div class="student-name-display" id="suspend_student_name_display"></div>
                        <div class="student-id-display" id="suspend_student_id_display"></div>
                    </div>

                    <div class="form-group">
                        <label for="suspend_reason">เหตุผลในการระงับบัญชี *</label>
                        <textarea id="suspend_reason" name="suspend_reason" required
                            placeholder="กรอกเหตุผลในการระงับบัญชีนักศึกษา เช่น ละเมิดกฎระเบียบของมหาวิทยาลัย, ใช้ระบบในทางที่ผิด, ฯลฯ"></textarea>
                    </div>

                    <div class="modal-actions">
                        <button type="button" onclick="closeModal('suspendStudentModal')" class="btn btn-secondary">ยกเลิก</button>
                        <button type="submit" class="btn btn-success">ยืนยันระงับ</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Release Suspension Modal -->
        <div id="releaseStudentModal" class="modal release-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <span>✅</span>
                        ปลดการระงับบัญชีนักศึกษา
                    </h3>
                    <span class="close" onclick="closeModal('releaseStudentModal')">&times;</span>
                </div>
                <form id="releaseStudentForm" class="release-form">
                    <input type="hidden" id="release_student_id" name="student_id">

                    <div class="release-student-info">
                        <div class="student-avatar">✅</div>
                        <div class="student-name-display" id="release_student_name_display"></div>
                        <div class="student-id-display" id="release_student_id_display"></div>
                    </div>

                    <div class="suspension-info">
                        <div class="suspension-title">
                            <span>⛔</span>
                            ข้อมูลการระงับปัจจุบัน
                        </div>
                        <div class="suspension-details">
                            <div class="suspension-detail">
                                <div class="detail-label-small">วันที่ระงับ:</div>
                                <div class="detail-value-small" id="suspension_date_display">-</div>
                            </div>
                            <div class="suspension-detail">
                                <div class="detail-label-small">ระงับโดย:</div>
                                <div class="detail-value-small" id="suspended_by_display">-</div>
                            </div>
                            <div class="suspension-detail">
                                <div class="detail-label-small">เหตุผล:</div>
                                <div class="detail-value-small">
                                    <div class="suspend-reason-text" id="suspension_reason_display">-</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="release_reason">เหตุผลในการปลดการระงับ</label>
                        <textarea id="release_reason" name="release_reason"
                            placeholder="กรอกเหตุผลในการปลดการระงับ เช่น นักศึกษาได้แก้ไขพฤติกรรม, ได้รับโทษครบตามระยะเวลา, มีการชี้แจงและขอโทษ, ฯลฯ"></textarea>
                    </div>

                    <div class="modal-actions">
                        <button type="button" onclick="closeModal('releaseStudentModal')" class="btn btn-secondary">ยกเลิก</button>
                        <button type="submit" class="btn btn-success">ปลดการระงับ</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Student Detail Modal -->
    <div id="studentDetailModal" class="modal student-detail-modal">
        <div class="modal-content">
            <div class="student-detail-header">
                <h3 class="modal-title">
                    <span>👀</span>
                    รายละเอียดนักศึกษา
                </h3>
                <span class="close" onclick="closeModal('studentDetailModal')">&times;</span>
            </div>
            <div class="student-detail-body">
                <div id="studentDetailContent">
                    <!-- Content will be loaded dynamically -->
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        // Global variables
        let currentFilters = {
            search: '',
            status: '',
            faculty: '',
            page: 1
        };

        const canEditUsers = <?php echo $canEditUsers ? 'true' : 'false'; ?>;
        const canViewOnly = <?php echo $canViewOnly ? 'true' : 'false'; ?>;
        let availableMajors = [];

        // Debug function
        function debugFormData(formData, formType) {
            console.log(`=== ${formType} Form Data ===`);
            if (formData instanceof FormData) {
                for (let [key, value] of formData.entries()) {
                    console.log(`${key}: ${value}`);
                }
            } else {
                console.log(formData);
            }
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            loadInitialData();
            setupEventListeners();
            initializeCustomSelects();
            initializeFacultySelect();
        });

        function setupEventListeners() {
            // Search on Enter key
            document.getElementById('searchInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchStudents();
                }
            });

            // Auto-search after typing (debounced)
            let searchTimeout;
            document.getElementById('searchInput').addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(searchStudents, 500);
            });

            // Filter changes
            document.getElementById('statusFilter').addEventListener('change', searchStudents);
            document.getElementById('facultyFilter').addEventListener('change', searchStudents);

            if (canEditUsers) {
                // Form submissions
                document.getElementById('addStudentForm').addEventListener('submit', handleAddStudent);
                document.getElementById('editStudentForm').addEventListener('submit', handleEditStudent);
                document.getElementById('suspendStudentForm').addEventListener('submit', handleSuspendStudent);
                document.getElementById('releaseStudentForm').addEventListener('submit', handleReleaseStudent);

                // Setup real-time validation for add form
                setupAddFormValidation();
            }
        }

        function initializeCustomSelects() {
            document.querySelectorAll('.custom-select').forEach(select => {
                const button = select.querySelector('.custom-select-button');
                const dropdown = select.querySelector('.custom-select-dropdown');
                const options = select.querySelectorAll('.custom-select-option');
                const hiddenInput = select.parentElement.querySelector('input[type="hidden"]');

                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    closeAllSelects();
                    select.classList.toggle('open');
                });

                options.forEach(option => {
                    option.addEventListener('click', () => {
                        const value = option.dataset.value;
                        const icon = option.querySelector('.option-icon').textContent;
                        const text = option.querySelector('.option-text').textContent;

                        // Update button
                        button.querySelector('.option-icon').textContent = icon;
                        button.querySelector('.option-text').textContent = text;

                        // Update hidden input
                        if (hiddenInput) {
                            hiddenInput.value = value;
                        }

                        // Update selection state
                        options.forEach(opt => opt.classList.remove('selected'));
                        option.classList.add('selected');

                        select.classList.remove('open');
                    });
                });
            });

            // Close selects when clicking outside
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.custom-select')) {
                    closeAllSelects();
                }
            });
        }

        function initializeFacultySelect() {
            const facultySelect = document.getElementById('facultyFilter');
            if (facultySelect) {
                // Faculty select is already styled with icons in PHP
            }
        }

        function closeAllSelects() {
            document.querySelectorAll('.custom-select').forEach(select => {
                select.classList.remove('open');
            });
        }

        function populateCustomSelectWithIcons(selectElement, options, selectedValue = null) {
            const dropdown = selectElement.querySelector('.custom-select-dropdown');
            const button = selectElement.querySelector('.custom-select-button');
            const hiddenInput = selectElement.parentElement.querySelector('input[type="hidden"]');

            // Clear existing options except first
            const existingOptions = dropdown.querySelectorAll('.custom-select-option');
            existingOptions.forEach((option, index) => {
                if (index > 0) option.remove();
            });

            // Add new options with faculty structure
            options.forEach(option => {
                const optionElement = document.createElement('div');
                optionElement.className = 'custom-select-option';
                optionElement.dataset.value = option.value;

                if (selectedValue == option.value) {
                    optionElement.classList.add('selected');
                    button.querySelector('.option-icon').textContent = option.icon;
                    button.querySelector('.option-text').textContent = option.text;
                    if (hiddenInput) hiddenInput.value = option.value;
                }

                optionElement.innerHTML = `
                    <span class="option-icon">${option.icon}</span>
                    <div class="faculty-option">
                        <span class="option-text">${option.text}</span>
                        ${option.faculty ? `<span class="faculty-name">${option.faculty}</span>` : ''}
                    </div>
                `;

                optionElement.addEventListener('click', () => {
                    // Update button
                    button.querySelector('.option-icon').textContent = option.icon;
                    button.querySelector('.option-text').textContent = option.text;

                    // Update hidden input
                    if (hiddenInput) {
                        hiddenInput.value = option.value;
                    }

                    // Update selection state
                    dropdown.querySelectorAll('.custom-select-option').forEach(opt =>
                        opt.classList.remove('selected')
                    );
                    optionElement.classList.add('selected');

                    selectElement.classList.remove('open');
                });

                dropdown.appendChild(optionElement);
            });
        }

        function populateCustomSelects() {
            const options = availableMajors.map(major => ({
                value: major.Unit_id,
                icon: major.Unit_icon || '🎓',
                text: major.Unit_name,
                faculty: major.faculty_name || 'ไม่ระบุคณะ'
            }));

            // Populate add modal
            const addSelect = document.querySelector('#addStudentModal .custom-select');
            if (addSelect) {
                populateCustomSelectWithIcons(addSelect, options);
            }

            // Populate edit modal  
            const editSelect = document.querySelector('#editStudentModal .custom-select');
            if (editSelect) {
                populateCustomSelectWithIcons(editSelect, options);
            }
        }

        async function loadInitialData() {
            await Promise.all([
                loadStudents(),
                loadStats(),
                canEditUsers ? loadFaculties() : Promise.resolve()
            ]);
        }

        async function loadStudents(resetPage = false) {
            if (resetPage) currentFilters.page = 1;

            showLoading(true);

            try {
                const params = new URLSearchParams({
                    action: 'search',
                    search: document.getElementById('searchInput').value || '',
                    status: document.getElementById('statusFilter').value || '',
                    faculty: document.getElementById('facultyFilter').value || '',
                    page: currentFilters.page,
                    limit: 20
                });

                const response = await fetch(`ajax/users_search.php?${params}`);
                const result = await response.json();

                if (result.success) {
                    displayStudents(result.data);
                    displayPagination(result.pagination);
                    currentFilters = result.filters;
                } else {
                    showToast('เกิดข้อผิดพลาด: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Load students error:', error);
                showToast('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
            } finally {
                showLoading(false);
            }
        }

        async function loadStats() {
            try {
                const response = await fetch('ajax/users_search.php?action=get_stats');
                const result = await response.json();

                if (result.success) {
                    updateStats(result.stats);
                }
            } catch (error) {
                console.error('Load stats error:', error);
            }
        }

        async function loadFaculties() {
            try {
                const response = await fetch('ajax/users_search.php?action=get_faculties');
                const result = await response.json();

                if (result.success) {
                    availableMajors = result.majors;
                    populateCustomSelects();
                }
            } catch (error) {
                console.error('Load faculties error:', error);
            }
        }

        function displayStudents(students) {
            const tbody = document.getElementById('studentsTableBody');

            if (students.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="no-data">
                            <div>🔭</div>
                            <p>ไม่พบข้อมูลนักศึกษา</p>
                            <button onclick="clearFilters()" class="btn btn-secondary">ล้างตัวกรอง</button>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = students.map(student => `
                <tr class="student-row ${student.status_class}">
                    <td>
                        <div class="student-id">${student.Stu_id}</div>
                    </td>
                    <td>
                        <div class="student-name">
                            <strong>${escapeHtml(student.Stu_name)}</strong>
                            ${student.Stu_status == 0 ? '<span class="suspended-badge">ระงับ</span>' : ''}
                        </div>
                    </td>
                    <td>
                        <div class="unit-info">
                            ${student.major_name ? `
                                <div class="major">
                                    <span class="unit-icon">${student.major_icon || '📚'}</span>
                                    ${escapeHtml(student.major_name)}
                                </div>
                            ` : ''}
                            ${student.faculty_name ? `
                                <div class="faculty">
                                    <span class="unit-icon">${student.faculty_icon || '🏫'}</span>
                                    ${escapeHtml(student.faculty_name)}
                                </div>
                            ` : ''}
                        </div>
                    </td>
                    <td>
                        <div class="contact-info">
                            ${student.Stu_tel ? `<div class="phone">📞 ${escapeHtml(student.Stu_tel)}</div>` : ''}
                            ${student.Stu_email ? `<div class="email">📧 ${escapeHtml(student.Stu_email)}</div>` : ''}
                        </div>
                    </td>
                    <td>
                        <div class="status-info">
                            <span class="status-badge ${student.status_class}">${student.status_text}</span>
                            ${student.Stu_suspend_reason ? `
                                <div class="suspend-reason">
                                    เหตุผล: ${escapeHtml(student.Stu_suspend_reason)}
                                </div>
                            ` : ''}
                        </div>
                    </td>
                    <td>
                        <div class="date-info">${student.created_at_formatted}</div>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button onclick="viewStudentDetail('${student.Stu_id}')" 
                                    class="btn btn-info btn-sm" title="ดูรายละเอียด">
                                👁️
                            </button>
                            ${canEditUsers ? `
                                <button onclick="editStudent('${student.Stu_id}')" 
                                        class="btn btn-warning btn-sm" title="แก้ไข">
                                    ✏️
                                </button>
                                ${student.Stu_status == 1 ? `
                                    <button onclick="showSuspendModal('${student.Stu_id}', '${escapeHtml(student.Stu_name)}')" 
                                            class="btn btn-warning btn-sm" title="ระงับบัญชี">
                                        ⛔
                                    </button>
                                ` : `
                                    <button onclick="showReleaseModal('${student.Stu_id}', '${escapeHtml(student.Stu_name)}')" 
                                            class="btn btn-success btn-sm" title="ปลดการระงับ">
                                        ✅
                                    </button>
                                `}
                                <button onclick="deleteStudentWithConfirm('${student.Stu_id}', '${escapeHtml(student.Stu_name)}')" 
                                        class="btn btn-danger btn-sm" title="ลบ">
                                    🗑️
                                </button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        function displayPagination(pagination) {
            const container = document.getElementById('paginationContainer');
            const info = document.getElementById('paginationInfo');
            const links = document.getElementById('paginationLinks');

            if (pagination.total_pages <= 1) {
                container.style.display = 'none';
                return;
            }

            container.style.display = 'flex';

            info.textContent = `แสดง ${pagination.start_item.toLocaleString()} - ${pagination.end_item.toLocaleString()} จาก ${pagination.total_items.toLocaleString()} รายการ`;

            let paginationHTML = '';

            // Previous buttons
            if (pagination.current_page > 1) {
                paginationHTML += `<a href="#" onclick="goToPage(1)" class="page-link">หน้าแรก</a>`;
                paginationHTML += `<a href="#" onclick="goToPage(${pagination.current_page - 1})" class="page-link">ก่อนหน้า</a>`;
            }

            // Page numbers
            const startPage = Math.max(1, pagination.current_page - 2);
            const endPage = Math.min(pagination.total_pages, pagination.current_page + 2);

            for (let i = startPage; i <= endPage; i++) {
                const activeClass = i === pagination.current_page ? 'active' : '';
                paginationHTML += `<a href="#" onclick="goToPage(${i})" class="page-link ${activeClass}">${i}</a>`;
            }

            // Next buttons
            if (pagination.current_page < pagination.total_pages) {
                paginationHTML += `<a href="#" onclick="goToPage(${pagination.current_page + 1})" class="page-link">ถัดไป</a>`;
                paginationHTML += `<a href="#" onclick="goToPage(${pagination.total_pages})" class="page-link">หน้าสุดท้าย</a>`;
            }

            links.innerHTML = paginationHTML;
        }

        function updateStats(stats) {
            document.getElementById('totalStudents').textContent = stats.total.toLocaleString();
            document.getElementById('activeStudents').textContent = stats.active.toLocaleString();
            document.getElementById('suspendedStudents').textContent = stats.suspended.toLocaleString();
            document.getElementById('todayStudents').textContent = stats.today.toLocaleString();
        }

        // Navigation functions
        function searchStudents() {
            loadStudents(true);
        }

        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('facultyFilter').value = '';
            loadStudents(true);
        }

        function refreshData() {
            loadInitialData();
        }

        function goToPage(page) {
            currentFilters.page = page;
            loadStudents();
        }

        // Student management functions
        async function viewStudentDetail(studentId) {
            try {
                const response = await fetch(`ajax/users_search.php?action=get_student_detail&student_id=${studentId}`);
                const result = await response.json();

                if (result.success) {
                    displayStudentDetail(result);
                    showModal('studentDetailModal');
                } else {
                    showToast('ไม่สามารถโหลดข้อมูลนักศึกษาได้: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('View student detail error:', error);
                showToast('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
            }
        }

        function displayStudentDetail(data) {
            const content = document.getElementById('studentDetailContent');
            const student = data.student;
            const stats = data.complaint_stats || {};
            const recentRequests = data.recent_requests || [];
            const suspendHistory = data.suspend_history || [];
            const evalStats = data.evaluation_stats || {};

            let html = `
                <!-- ข้อมูลพื้นฐาน -->
                <div class="detail-section">
                    <h4 class="section-title">📋 ข้อมูลพื้นฐาน</h4>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">รหัสนักศึกษา</div>
                            <div class="detail-value">${student.Stu_id}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">ชื่อ-นามสกุล</div>
                            <div class="detail-value">${escapeHtml(student.Stu_name)}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">สาขา</div>
                            <div class="detail-value">${student.major_name ? `${student.major_icon || '📚'} ${escapeHtml(student.major_name)}` : 'ไม่ระบุ'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">คณะ</div>
                            <div class="detail-value">${student.faculty_name ? `${student.faculty_icon || '🏫'} ${escapeHtml(student.faculty_name)}` : 'ไม่ระบุ'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">เบอร์โทรศัพท์</div>
                            <div class="detail-value">${student.Stu_tel ? `📞 ${escapeHtml(student.Stu_tel)}` : 'ไม่ระบุ'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">อีเมล</div>
                            <div class="detail-value">${student.Stu_email ? `📧 ${escapeHtml(student.Stu_email)}` : 'ไม่ระบุ'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">สถานะ</div>
                            <div class="detail-value">
                                <span class="status-badge ${student.status_class}">${student.status_text}</span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">วันที่สมัคร</div>
                            <div class="detail-value">${student.created_at_formatted}</div>
                        </div>
                    </div>
                </div>

                <!-- สถิติข้อร้องเรียน -->
                <div class="detail-section">
                    <h4 class="section-title">📊 สถิติข้อร้องเรียน</h4>
                    <div class="stats-row">
                        <div class="stat-item">
                            <div class="stat-number">${stats.total_requests || 0}</div>
                            <div class="stat-label">ทั้งหมด</div>
                        </div>
                        <div class="stat-item pending">
                            <div class="stat-number">${stats.pending_requests || 0}</div>
                            <div class="stat-label">รอยืนยัน</div>
                        </div>
                        <div class="stat-item confirmed">
                            <div class="stat-number">${stats.confirmed_requests || 0}</div>
                            <div class="stat-label">ยืนยันแล้ว</div>
                        </div>
                        <div class="stat-item processed">
                            <div class="stat-number">${stats.processed_requests || 0}</div>
                            <div class="stat-label">ดำเนินการแล้ว</div>
                        </div>
                        <div class="stat-item completed">
                            <div class="stat-number">${stats.completed_requests || 0}</div>
                            <div class="stat-label">เสร็จสิ้น</div>
                        </div>
                    </div>
                </div>

                <!-- ข้อร้องเรียนล่าสุด -->
                <div class="detail-section">
                    <h4 class="section-title">🕒 ข้อร้องเรียนล่าสุด</h4>
                    <div class="recent-requests">
                        ${recentRequests.length > 0 ? recentRequests.map(req => `
                            <div class="request-item">
                                <div class="request-header">
                                    <span class="request-type">${req.Type_icon || '📝'} ${escapeHtml(req.Type_infor || 'ข้อร้องเรียน')}</span>
                                    <span class="request-date">${req.Re_date_formatted || ''}</span>
                                </div>
                                <div class="request-title">${escapeHtml(req.Re_title || 'ไม่มีหัวข้อ')}</div>
                                <div class="request-content">${escapeHtml((req.Re_infor || '').substring(0, 100))}${(req.Re_infor || '').length > 100 ? '...' : ''}</div>
                                <div class="request-footer">
                                    <span class="request-status status-${req.Re_status || 0}">${req.status_text || 'ไม่ทราบสถานะ'}</span>
                                    <span class="request-level level-${req.Re_level || 1}">${req.level_text || 'ระดับปกติ'}</span>
                                </div>
                            </div>
                        `).join('') : '<div class="no-data">ไม่มีข้อร้องเรียน</div>'}
                    </div>
                </div>`;

            // เพิ่มส่วนประวัติการระงับ (ถ้ามี)
            if (suspendHistory && suspendHistory.length > 0) {
                html += `
                <div class="detail-section">
                    <h4 class="section-title">⛔ ประวัติการระงับ</h4>
                    <div class="suspend-history">
                        ${suspendHistory.map(history => `
                            <div class="suspend-item">
                                <div class="suspend-header">
                                    <strong>ระงับเมื่อ: ${(new Date(history.Sh_suspend_date)).toLocaleDateString('th-TH')}</strong>
                                    ${history.Sh_release_date ? `<span class="release-date">ปลดแล้ว: ${(new Date(history.Sh_release_date)).toLocaleDateString('th-TH')}</span>` : '<span class="still-suspended">ยังคงระงับ</span>'}
                                </div>
                                <div class="suspend-reason">${escapeHtml(history.Sh_reason)}</div>
                                <div class="suspend-by">ระงับโดย: ${escapeHtml(history.suspended_by_name || 'ไม่ระบุ')}</div>
                                ${history.released_by_name ? `<div class="release-by">ปลดโดย: ${escapeHtml(history.released_by_name)}</div>` : ''}
                            </div>
                        `).join('')}
                    </div>
                </div>`;
            }

            // เพิ่มส่วนข้อมูลการประเมิน
            if (evalStats && evalStats.total_evaluations > 0) {
                html += `
                <div class="detail-section">
                    <h4 class="section-title">⭐ ผลการประเมิน</h4>
                    <div class="eval-stats">
                        <div class="eval-item">
                            <div class="eval-score">${parseFloat(evalStats.avg_score).toFixed(1)}/5.0</div>
                            <div class="eval-label">คะแนนเฉลี่ย</div>
                        </div>
                        <div class="eval-item">
                            <div class="eval-count">${evalStats.total_evaluations}</div>
                            <div class="eval-label">ครั้งที่ประเมิน</div>
                        </div>
                    </div>
                </div>`;
            }

            content.innerHTML = html;
        }

        // Student CRUD functions (only for users with edit permissions)
        // Duplicate validation state
        let addFormValidation = {
            student_id: {
                valid: true,
                checking: false
            },
            email: {
                valid: true,
                checking: false
            }
        };

        // Debounce function
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

        // Check duplicate student ID or email
        async function checkDuplicate(field, value, excludeId = '') {
            if (!value || value.trim() === '') {
                return {
                    is_duplicate: false
                };
            }

            try {
                const response = await fetch('ajax/users_crud.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'check_duplicate',
                        field: field,
                        value: value.trim(),
                        exclude_id: excludeId
                    })
                });

                return await response.json();
            } catch (error) {
                console.error('Check duplicate error:', error);
                return {
                    success: false,
                    is_duplicate: false
                };
            }
        }

        // Show inline alert
        function showInlineAlert(elementId, type, message) {
            const alertEl = document.getElementById(elementId);
            const formGroup = alertEl.closest('.form-group');

            // Reset classes
            formGroup.classList.remove('has-error', 'has-success', 'validating');
            alertEl.classList.remove('error', 'success', 'warning');

            if (type === 'error') {
                formGroup.classList.add('has-error');
                alertEl.classList.add('error');
                alertEl.querySelector('.alert-icon').textContent = '❌';
            } else if (type === 'success') {
                formGroup.classList.add('has-success');
                alertEl.classList.add('success');
                alertEl.querySelector('.alert-icon').textContent = '✓';
            } else if (type === 'warning') {
                alertEl.classList.add('warning');
                alertEl.querySelector('.alert-icon').textContent = '⚠️';
            }

            alertEl.querySelector('.alert-message').textContent = message;
            alertEl.style.display = 'flex';
        }

        // Hide inline alert
        function hideInlineAlert(elementId) {
            const alertEl = document.getElementById(elementId);
            if (alertEl) {
                const formGroup = alertEl.closest('.form-group');
                formGroup.classList.remove('has-error', 'has-success', 'validating');
                alertEl.style.display = 'none';
                alertEl.classList.remove('error', 'success', 'warning');
            }
        }

        // Set validating state
        function setValidatingState(formGroupId, isValidating) {
            const formGroup = document.getElementById(formGroupId);
            if (formGroup) {
                if (isValidating) {
                    formGroup.classList.add('validating');
                    formGroup.classList.remove('has-error', 'has-success');
                } else {
                    formGroup.classList.remove('validating');
                }
            }
        }

        // Validate student ID (debounced)
        const validateStudentId = debounce(async function(value) {
            const formGroupId = 'add_student_id_group';
            const alertId = 'add_student_id_alert';

            if (!value || value.trim() === '') {
                hideInlineAlert(alertId);
                addFormValidation.student_id = {
                    valid: true,
                    checking: false
                };
                return;
            }

            // Check format first
            if (!/^[0-9\-]{10,15}$/.test(value)) {
                showInlineAlert(alertId, 'warning', 'รูปแบบรหัสนักศึกษาไม่ถูกต้อง (ต้องเป็นตัวเลขและขีด 10-15 หลัก)');
                addFormValidation.student_id = {
                    valid: false,
                    checking: false
                };
                return;
            }

            setValidatingState(formGroupId, true);
            addFormValidation.student_id.checking = true;

            const result = await checkDuplicate('student_id', value);

            setValidatingState(formGroupId, false);
            addFormValidation.student_id.checking = false;

            if (result.is_duplicate) {
                showInlineAlert(alertId, 'error', result.message);
                addFormValidation.student_id.valid = false;
            } else {
                showInlineAlert(alertId, 'success', 'รหัสนักศึกษานี้สามารถใช้ได้');
                addFormValidation.student_id.valid = true;
            }
        }, 500);

        // Validate email (debounced)
        const validateEmail = debounce(async function(value, excludeId = '') {
            const formGroupId = 'add_student_email_group';
            const alertId = 'add_student_email_alert';

            if (!value || value.trim() === '') {
                hideInlineAlert(alertId);
                addFormValidation.email = {
                    valid: true,
                    checking: false
                };
                return;
            }

            // Check format first
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(value)) {
                showInlineAlert(alertId, 'warning', 'รูปแบบอีเมลไม่ถูกต้อง');
                addFormValidation.email = {
                    valid: false,
                    checking: false
                };
                return;
            }

            setValidatingState(formGroupId, true);
            addFormValidation.email.checking = true;

            const result = await checkDuplicate('email', value, excludeId);

            setValidatingState(formGroupId, false);
            addFormValidation.email.checking = false;

            if (result.is_invalid) {
                showInlineAlert(alertId, 'warning', result.message);
                addFormValidation.email.valid = false;
            } else if (result.is_duplicate) {
                showInlineAlert(alertId, 'error', result.message);
                addFormValidation.email.valid = false;
            } else {
                showInlineAlert(alertId, 'success', 'อีเมลนี้สามารถใช้ได้');
                addFormValidation.email.valid = true;
            }
        }, 500);

        // Setup validation listeners
        function setupAddFormValidation() {
            const studentIdInput = document.getElementById('add_student_id');
            const emailInput = document.getElementById('add_student_email');

            if (studentIdInput) {
                studentIdInput.addEventListener('input', function(e) {
                    validateStudentId(e.target.value);
                });

                studentIdInput.addEventListener('blur', function(e) {
                    if (e.target.value.trim()) {
                        validateStudentId(e.target.value);
                    }
                });
            }

            if (emailInput) {
                emailInput.addEventListener('input', function(e) {
                    validateEmail(e.target.value);
                });

                emailInput.addEventListener('blur', function(e) {
                    if (e.target.value.trim()) {
                        validateEmail(e.target.value);
                    }
                });
            }
        }

        async function handleAddStudent(e) {
            e.preventDefault();

            if (!canEditUsers) {
                showToast('คุณไม่มีสิทธิ์ในการเพิ่มนักศึกษา', 'error');
                return;
            }

            // Wait for any pending validation to complete
            if (addFormValidation.student_id.checking || addFormValidation.email.checking) {
                showToast('กรุณารอการตรวจสอบข้อมูลให้เสร็จสิ้น', 'warning');
                return;
            }

            // Check if there are validation errors
            if (!addFormValidation.student_id.valid) {
                showToast('รหัสนักศึกษาไม่ถูกต้องหรือซ้ำกับที่มีอยู่ในระบบ', 'error');
                document.getElementById('add_student_id').focus();
                return;
            }

            if (!addFormValidation.email.valid) {
                showToast('อีเมลไม่ถูกต้องหรือซ้ำกับที่มีอยู่ในระบบ', 'error');
                document.getElementById('add_student_email').focus();
                return;
            }

            const form = e.target;
            const formData = new FormData(form);

            debugFormData(formData, 'Add Student');

            // Convert FormData to object
            const data = {};
            for (let [key, value] of formData.entries()) {
                data[key] = value.trim();
            }
            data.action = 'add_student';

            console.log('Sending add student data:', data);

            // Disable submit button
            const submitBtn = document.getElementById('addStudentSubmitBtn');
            const originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'กำลังบันทึก...';

            try {
                const response = await fetch('ajax/users_crud.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(data)
                });

                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);

                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('HTTP Error Response:', errorText);
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();
                console.log('Add student result:', result);

                if (result.success) {
                    showToast(result.message, 'success');
                    closeModal('addStudentModal');
                    // Reset validation state
                    addFormValidation = {
                        student_id: {
                            valid: true,
                            checking: false
                        },
                        email: {
                            valid: true,
                            checking: false
                        }
                    };
                    hideInlineAlert('add_student_id_alert');
                    hideInlineAlert('add_student_email_alert');
                    await loadStudents();
                    await loadStats();
                } else {
                    // Show error in appropriate field if it's a duplicate error
                    if (result.message.includes('รหัสนักศึกษา')) {
                        showInlineAlert('add_student_id_alert', 'error', result.message);
                        addFormValidation.student_id.valid = false;
                    } else if (result.message.includes('อีเมล')) {
                        showInlineAlert('add_student_email_alert', 'error', result.message);
                        addFormValidation.email.valid = false;
                    }
                    showToast(result.message || 'เกิดข้อผิดพลาดในการเพิ่มนักศึกษา', 'error');
                }
            } catch (error) {
                console.error('Add student error:', error);
                showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + error.message, 'error');
            } finally {
                // Re-enable submit button
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        }

        async function handleEditStudent(e) {
            e.preventDefault();

            if (!canEditUsers) {
                showToast('คุณไม่มีสิทธิ์ในการแก้ไขข้อมูลนักศึกษา', 'error');
                return;
            }

            const form = e.target;
            const formData = new FormData(form);

            debugFormData(formData, 'Edit Student');

            const data = {};
            for (let [key, value] of formData.entries()) {
                data[key] = value.trim();
            }
            data.action = 'edit_student';

            console.log('Sending edit student data:', data);

            try {
                const response = await fetch('ajax/users_crud.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(data)
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('HTTP Error Response:', errorText);
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();
                console.log('Edit student result:', result);

                if (result.success) {
                    showToast(result.message, 'success');
                    closeModal('editStudentModal');
                    await loadStudents();
                } else {
                    showToast(result.message || 'เกิดข้อผิดพลาดในการแก้ไขข้อมูล', 'error');
                }
            } catch (error) {
                console.error('Edit student error:', error);
                showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + error.message, 'error');
            }
        }

        function showAddStudentModal() {
            if (!canEditUsers) {
                showToast('คุณไม่มีสิทธิ์ในการเพิ่มนักศึกษา', 'error');
                return;
            }

            document.getElementById('addStudentForm').reset();

            // Reset custom select
            const addSelect = document.querySelector('#addStudentModal .custom-select');
            if (addSelect) {
                const button = addSelect.querySelector('.custom-select-button');
                button.querySelector('.option-icon').textContent = '🎓';
                button.querySelector('.option-text').textContent = 'เลือกสาขา';
                document.getElementById('add_unit_id').value = '0';

                // Clear selection state
                addSelect.querySelectorAll('.custom-select-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                addSelect.querySelector('.custom-select-option[data-value="0"]').classList.add('selected');
            }

            showModal('addStudentModal');
        }

        async function editStudent(studentId) {
            if (!canEditUsers) {
                showToast('คุณไม่มีสิทธิ์ในการแก้ไขข้อมูลนักศึกษา', 'error');
                return;
            }

            try {
                const response = await fetch(`ajax/users_search.php?action=get_student&student_id=${studentId}`);
                const result = await response.json();

                if (result.success) {
                    const student = result.student;

                    document.getElementById('edit_student_id').value = student.Stu_id;
                    document.getElementById('edit_student_name').value = student.Stu_name;
                    document.getElementById('edit_student_tel').value = student.Stu_tel || '';
                    document.getElementById('edit_student_email').value = student.Stu_email || '';
                    document.getElementById('edit_student_password').value = '';

                    // Set custom select value
                    const editSelect = document.querySelector('#editStudentModal .custom-select');
                    if (editSelect) {
                        const unitId = student.Unit_id || '0';
                        const options = editSelect.querySelectorAll('.custom-select-option');
                        const targetOption = Array.from(options).find(opt => opt.dataset.value == unitId);

                        if (targetOption) {
                            targetOption.click();
                        }
                    }

                    showModal('editStudentModal');
                } else {
                    showToast('ไม่สามารถโหลดข้อมูลนักศึกษาได้: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Edit student error:', error);
                showToast('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
            }
        }

        function showSuspendModal(studentId, studentName) {
            if (!canEditUsers) {
                showToast('คุณไม่มีสิทธิ์ในการระงับบัญชีนักศึกษา', 'error');
                return;
            }

            document.getElementById('suspend_student_id').value = studentId;
            document.getElementById('suspend_student_name_display').textContent = studentName;
            document.getElementById('suspend_student_id_display').textContent = studentId;
            document.getElementById('suspend_reason').value = '';

            showModal('suspendStudentModal');

            // Focus on textarea
            setTimeout(() => {
                document.getElementById('suspend_reason').focus();
            }, 300);
        }

        async function handleSuspendStudent(e) {
            e.preventDefault();

            if (!canEditUsers) {
                showToast('คุณไม่มีสิทธิ์ในการระงับบัญชีนักศึกษา', 'error');
                return;
            }

            const formData = new FormData(e.target);
            const studentId = formData.get('student_id');
            const suspendReason = formData.get('suspend_reason');

            if (!suspendReason.trim()) {
                showToast('กรุณากรอกเหตุผลในการระงับบัญชี', 'error');
                return;
            }

            await toggleStudentStatus(studentId, 0, suspendReason.trim());
            closeModal('suspendStudentModal');
        }

        // Release suspension functions
        async function showReleaseModal(studentId, studentName) {
            if (!canEditUsers) {
                showToast('คุณไม่มีสิทธิ์ในการปลดการระงับ', 'error');
                return;
            }

            try {
                // Load current suspension info
                const response = await fetch(`ajax/users_search.php?action=get_suspension_info&student_id=${studentId}`);
                const result = await response.json();

                if (result.success) {
                    const suspensionInfo = result.suspension_info;

                    document.getElementById('release_student_id').value = studentId;
                    document.getElementById('release_student_name_display').textContent = studentName;
                    document.getElementById('release_student_id_display').textContent = studentId;
                    document.getElementById('release_reason').value = '';

                    // Populate suspension details
                    document.getElementById('suspension_date_display').textContent =
                        suspensionInfo.suspend_date_formatted || 'ไม่ระบุ';
                    document.getElementById('suspended_by_display').textContent =
                        suspensionInfo.suspended_by_name || 'ไม่ระบุ';
                    document.getElementById('suspension_reason_display').textContent =
                        suspensionInfo.suspend_reason || 'ไม่ระบุเหตุผล';

                    showModal('releaseStudentModal');

                    // Focus on textarea
                    setTimeout(() => {
                        document.getElementById('release_reason').focus();
                    }, 300);
                } else {
                    showToast('ไม่สามารถโหลดข้อมูลการระงับได้: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Load suspension info error:', error);
                showToast('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
            }
        }

        async function handleReleaseStudent(e) {
            e.preventDefault();

            if (!canEditUsers) {
                showToast('คุณไม่มีสิทธิ์ในการปลดการระงับ', 'error');
                return;
            }

            const formData = new FormData(e.target);
            const studentId = formData.get('student_id');
            const releaseReason = formData.get('release_reason');

            await toggleStudentStatus(studentId, 1, null, releaseReason.trim());
            closeModal('releaseStudentModal');
        }

        function deleteStudentWithConfirm(studentId, studentName) {
            if (!canEditUsers) {
                showToast('คุณไม่มีสิทธิ์ในการลบนักศึกษา', 'error');
                return;
            }

            showCustomConfirm(
                `พิมพ์ "DELETE" เพื่อยืนยันการลบนักศึกษา: ${studentName}`,
                'DELETE',
                () => deleteStudent(studentId)
            );
        }

        async function deleteStudent(studentId) {
            try {
                const response = await fetch('ajax/users_crud.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'delete_student',
                        student_id: studentId,
                        confirm_delete: 'DELETE'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    await loadStudents();
                    await loadStats();
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                console.error('Delete student error:', error);
                showToast('เกิดข้อผิดพลาดในการลบนักศึกษา', 'error');
            }
        }

        async function toggleStudentStatus(studentId, newStatus, suspendReason = '', releaseReason = '') {
            try {
                const response = await fetch('ajax/users_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'toggle_status',
                        student_id: studentId,
                        new_status: newStatus,
                        suspend_reason: suspendReason,
                        release_reason: releaseReason
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    await loadStudents();
                    await loadStats();
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                console.error('Toggle status error:', error);
                showToast('เกิดข้อผิดพลาดในการเปลี่ยนสถานะ', 'error');
            }
        }

        // Custom confirm dialog
        function showCustomConfirm(message, confirmText, callback) {
            const confirmDialog = document.createElement('div');
            confirmDialog.className = 'custom-confirm';
            confirmDialog.innerHTML = `
                <div class="confirm-content">
                    <div class="confirm-header">
                        <h3>⚠️ ยืนยันการดำเนินการ</h3>
                    </div>
                    <div class="confirm-body">
                        <p>${message}</p>
                        <input type="text" class="confirm-input" placeholder="พิมพ์ '${confirmText}' เพื่อยืนยัน">
                        <div class="confirm-actions">
                            <button class="btn btn-secondary" onclick="closeCustomConfirm()">ยกเลิก</button>
                            <button class="btn btn-success" onclick="confirmAction()">ยืนยัน</button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(confirmDialog);

            const input = confirmDialog.querySelector('.confirm-input');
            input.focus();

            window.closeCustomConfirm = function() {
                document.body.removeChild(confirmDialog);
                delete window.closeCustomConfirm;
                delete window.confirmAction;
            };

            window.confirmAction = function() {
                if (input.value === confirmText) {
                    callback();
                    window.closeCustomConfirm();
                } else {
                    showToast('กรุณาพิมพ์ข้อความยืนยันให้ถูกต้อง', 'error');
                    input.focus();
                }
            };

            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    window.confirmAction();
                }
            });
        }

        // Utility functions
        function showLoading(show) {
            document.getElementById('loadingIndicator').style.display = show ? 'block' : 'none';
        }

        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';

            setTimeout(() => {
                const firstInput = modal.querySelector('input, textarea, select, button');
                if (firstInput && firstInput.type !== 'hidden') {
                    firstInput.focus();
                }
            }, 300);
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.style.display = 'none';
            document.body.style.overflow = '';

            const form = modal.querySelector('form');
            if (form) {
                form.reset();

                const customSelects = modal.querySelectorAll('.custom-select');
                customSelects.forEach(select => {
                    const button = select.querySelector('.custom-select-button');
                    const firstOption = select.querySelector('.custom-select-option');
                    if (button && firstOption) {
                        button.querySelector('.option-icon').textContent = firstOption.querySelector('.option-icon').textContent;
                        button.querySelector('.option-text').textContent = firstOption.querySelector('.option-text').textContent;

                        const hiddenInput = select.parentElement.querySelector('input[type="hidden"]');
                        if (hiddenInput) {
                            hiddenInput.value = firstOption.dataset.value;
                        }

                        select.querySelectorAll('.custom-select-option').forEach(opt => {
                            opt.classList.remove('selected');
                        });
                        firstOption.classList.add('selected');
                    }
                });
            }

            // Reset validation states for add student modal
            if (modalId === 'addStudentModal') {
                addFormValidation = {
                    student_id: {
                        valid: true,
                        checking: false
                    },
                    email: {
                        valid: true,
                        checking: false
                    }
                };
                hideInlineAlert('add_student_id_alert');
                hideInlineAlert('add_student_email_alert');

                // Remove validation classes
                const studentIdGroup = document.getElementById('add_student_id_group');
                const emailGroup = document.getElementById('add_student_email_group');
                if (studentIdGroup) {
                    studentIdGroup.classList.remove('has-error', 'has-success', 'validating');
                }
                if (emailGroup) {
                    emailGroup.classList.remove('has-error', 'has-success', 'validating');
                }
            }
        }

        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;

            document.body.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('show');
            }, 100);

            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    if (document.body.contains(toast)) {
                        document.body.removeChild(toast);
                    }
                }, 300);
            }, 4000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            });
        }
    </script>
</body>

</html>