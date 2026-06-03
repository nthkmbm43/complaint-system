<!-- Advanced Search UI Component -->
<div id="advancedSearchModal" class="search-modal" style="display: none;">
    <div class="search-modal-content">
        <div class="search-modal-header">
            <h3>🔍 ค้นหาขั้นสูง</h3>
            <button class="search-modal-close">&times;</button>
        </div>

        <form id="advancedSearchForm" class="search-form">
            <!-- ค้นหาพื้นฐาน -->
            <div class="search-section">
                <h4>🔎 ค้นหาทั่วไป</h4>
                <div class="search-row">
                    <div class="search-field full-width">
                        <label>คำค้นหา</label>
                        <div class="search-input-container">
                            <input type="text" name="q" id="searchQuery" placeholder="พิมพ์คำค้นหา... (เช่น ปัญหาเครื่องปรับอากาศ)"
                                autocomplete="off">
                            <div id="searchSuggestions" class="search-suggestions"></div>
                        </div>
                        <small>ค้นหาจาก: เนื้อหาข้อร้องเรียน, ชื่อผู้ส่ง, การตอบกลับของเจ้าหน้าที่</small>
                    </div>
                </div>
            </div>

            <!-- ตัวกรอง -->
            <div class="search-section">
                <h4>🎯 ตัวกรอง</h4>
                <div class="search-row">
                    <div class="search-field">
                        <label>ประเภทข้อร้องเรียน</label>
                        <select name="category" multiple>
                            <option value="">ทั้งหมด</option>
                            <!-- จะถูกเติมด้วย JavaScript -->
                        </select>
                    </div>
                    <div class="search-field">
                        <label>สถานะ</label>
                        <select name="status" multiple>
                            <option value="">ทั้งหมด</option>
                            <option value="0">รอดำเนินการ</option>
                            <option value="1">กำลังดำเนินการ</option>
                            <option value="2">เสร็จสิ้น</option>
                            <option value="3">ประเมินแล้ว</option>
                        </select>
                    </div>
                    <div class="search-field">
                        <label>ระดับความสำคัญ</label>
                        <select name="priority" multiple>
                            <option value="">ทั้งหมด</option>
                            <option value="1">🟢 ปกติ</option>
                            <option value="2">🔵 สำคัญ</option>
                            <option value="3">🟡 เร่งด่วน</option>
                            <option value="4">🔴 เร่งด่วนมาก</option>
                            <option value="5">🟣 วิกฤต/ฉุกเฉิน</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ช่วงวันที่ -->
            <div class="search-section">
                <h4>📅 ช่วงวันที่</h4>
                <div class="search-row">
                    <div class="search-field">
                        <label>วันที่เริ่มต้น</label>
                        <input type="date" name="date_from">
                    </div>
                    <div class="search-field">
                        <label>วันที่สิ้นสุด</label>
                        <input type="date" name="date_to">
                    </div>
                    <div class="search-field">
                        <label>ช่วงเวลาด่วน</label>
                        <div class="quick-date-buttons">
                            <button type="button" onclick="setQuickDate('today')">วันนี้</button>
                            <button type="button" onclick="setQuickDate('week')">7 วันล่าสุด</button>
                            <button type="button" onclick="setQuickDate('month')">เดือนนี้</button>
                            <button type="button" onclick="setQuickDate('quarter')">3 เดือนล่าสุด</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ตัวกรองขั้นสูง -->
            <div class="search-section">
                <h4>⚙️ ตัวกรองขั้นสูง</h4>
                <div class="search-row">
                    <div class="search-field">
                        <label>การระบุตัวตน</label>
                        <select name="identity">
                            <option value="">ทั้งหมด</option>
                            <option value="0">ระบุตัวตน</option>
                            <option value="1">ไม่ระบุตัวตน</option>
                        </select>
                    </div>
                    <div class="search-field">
                        <label>เวลาตอบสนอง</label>
                        <select name="response_time">
                            <option value="">ทั้งหมด</option>
                            <option value="fast">🟢 เร็ว (≤ 24 ชม.)</option>
                            <option value="normal">🟡 ปกติ (24-72 ชม.)</option>
                            <option value="slow">🔴 ช้า (> 72 ชม.)</option>
                            <option value="no_response">⚫ ยังไม่ตอบ</option>
                        </select>
                    </div>
                    <div class="search-field">
                        <label>มีไฟล์แนบ</label>
                        <select name="has_files">
                            <option value="">ทั้งหมด</option>
                            <option value="1">มีไฟล์แนบ</option>
                            <option value="0">ไม่มีไฟล์แนบ</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- คะแนนประเมิน -->
            <div class="search-section">
                <h4>⭐ คะแนนประเมิน</h4>
                <div class="search-row">
                    <div class="search-field">
                        <label>คะแนนต่ำสุด</label>
                        <select name="rating_min">
                            <option value="">ไม่จำกัด</option>
                            <option value="1">⭐ 1 ดาว</option>
                            <option value="2">⭐⭐ 2 ดาว</option>
                            <option value="3">⭐⭐⭐ 3 ดาว</option>
                            <option value="4">⭐⭐⭐⭐ 4 ดาว</option>
                            <option value="5">⭐⭐⭐⭐⭐ 5 ดาว</option>
                        </select>
                    </div>
                    <div class="search-field">
                        <label>คะแนนสูงสุด</label>
                        <select name="rating_max">
                            <option value="">ไม่จำกัด</option>
                            <option value="1">⭐ 1 ดาว</option>
                            <option value="2">⭐⭐ 2 ดาว</option>
                            <option value="3">⭐⭐⭐ 3 ดาว</option>
                            <option value="4">⭐⭐⭐⭐ 4 ดาว</option>
                            <option value="5">⭐⭐⭐⭐⭐ 5 ดาว</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- เจ้าหน้าที่ (สำหรับ Staff เท่านั้น) -->
            <div class="search-section staff-only" style="display: none;">
                <h4>👤 เจ้าหน้าที่</h4>
                <div class="search-row">
                    <div class="search-field full-width">
                        <label>เจ้าหน้าที่ที่รับผิดชอบ</label>
                        <select name="assigned_to">
                            <option value="">ทั้งหมด</option>
                            <!-- จะถูกเติมด้วย JavaScript -->
                        </select>
                    </div>
                </div>
            </div>

            <!-- การเรียงลำดับ -->
            <div class="search-section">
                <h4>📋 การเรียงลำดับ</h4>
                <div class="search-row">
                    <div class="search-field">
                        <label>เรียงตาม</label>
                        <select name="sort_by">
                            <option value="Re_date">วันที่</option>
                            <option value="Re_id">รหัสข้อร้องเรียน</option>
                            <option value="Re_level">ระดับความสำคัญ</option>
                            <option value="Re_status">สถานะ</option>
                            <option value="Type_infor">ประเภท</option>
                            <option value="Eva_score">คะแนนประเมิน</option>
                            <option value="response_time">เวลาตอบสนอง</option>
                        </select>
                    </div>
                    <div class="search-field">
                        <label>ลำดับ</label>
                        <select name="sort_order">
                            <option value="DESC">ใหม่ไปเก่า / มากไปน้อย</option>
                            <option value="ASC">เก่าไปใหม่ / น้อยไปมาก</option>
                        </select>
                    </div>
                    <div class="search-field">
                        <label>จำนวนต่อหน้า</label>
                        <select name="per_page">
                            <option value="10">10 รายการ</option>
                            <option value="20" selected>20 รายการ</option>
                            <option value="50">50 รายการ</option>
                            <option value="100">100 รายการ</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ปุ่มค้นหา -->
            <div class="search-actions">
                <button type="submit" class="btn btn-primary">
                    🔍 ค้นหา
                </button>
                <button type="button" class="btn btn-secondary" onclick="resetSearchForm()">
                    🔄 รีเซต
                </button>
                <button type="button" class="btn btn-warning" onclick="saveSearchPreset()">
                    💾 บันทึกการค้นหา
                </button>
                <button type="button" class="btn btn-info" onclick="loadSearchPreset()">
                    📁 โหลดการค้นหา
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Quick Search Bar -->
<div class="quick-search-container">
    <div class="quick-search-bar">
        <input type="text" id="quickSearchInput" placeholder="ค้นหาอย่างรวดเร็ว..." autocomplete="off">
        <button type="button" onclick="openAdvancedSearch()" title="ค้นหาขั้นสูง">⚙️</button>
    </div>
    <div id="quickSearchResults" class="quick-search-results" style="display: none;"></div>
</div>

<!-- Search Results Container -->
<div id="searchResultsContainer" class="search-results-container" style="display: none;">
    <div class="search-results-header">
        <div class="search-results-info">
            <span id="searchResultsCount">0</span> ผลการค้นหา
            <span id="searchQueryInfo"></span>
        </div>
        <div class="search-results-actions">
            <button onclick="exportSearchResults('excel')" class="btn btn-sm">📊 Excel</button>
            <button onclick="exportSearchResults('csv')" class="btn btn-sm">📋 CSV</button>
            <button onclick="clearSearchResults()" class="btn btn-sm">✕ ปิด</button>
        </div>
    </div>

    <div id="searchResults" class="search-results">
        <!-- Results will be populated by JavaScript -->
    </div>

    <div id="searchPagination" class="search-pagination">
        <!-- Pagination will be populated by JavaScript -->
    </div>
</div>

<style>
    /* Advanced Search Styles */
    .search-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        backdrop-filter: blur(5px);
    }

    .search-modal-content {
        background: white;
        border-radius: 20px;
        padding: 0;
        max-width: 90vw;
        max-height: 90vh;
        width: 800px;
        overflow: hidden;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    .search-modal-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 20px 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .search-modal-header h3 {
        margin: 0;
        font-size: 20px;
    }

    .search-modal-close {
        background: none;
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
        padding: 5px;
        border-radius: 50%;
        width: 35px;
        height: 35px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .search-modal-close:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .search-form {
        padding: 30px;
        max-height: 70vh;
        overflow-y: auto;
    }

    .search-section {
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 1px solid #e2e8f0;
    }

    .search-section:last-child {
        border-bottom: none;
    }

    .search-section h4 {
        color: #2d3748;
        margin-bottom: 15px;
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .search-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }

    .search-field {
        display: flex;
        flex-direction: column;
    }

    .search-field.full-width {
        grid-column: 1 / -1;
    }

    .search-field label {
        font-weight: 600;
        color: #4a5568;
        margin-bottom: 5px;
        font-size: 14px;
    }

    .search-field input,
    .search-field select {
        padding: 10px 12px;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .search-field input:focus,
    .search-field select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .search-field small {
        color: #718096;
        font-size: 12px;
        margin-top: 5px;
    }

    .search-input-container {
        position: relative;
    }

    .search-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        z-index: 1000;
        max-height: 200px;
        overflow-y: auto;
    }

    .search-suggestion-item {
        padding: 10px 15px;
        cursor: pointer;
        border-bottom: 1px solid #f7fafc;
        transition: background 0.2s ease;
    }

    .search-suggestion-item:hover {
        background: #f7fafc;
    }

    .search-suggestion-item:last-child {
        border-bottom: none;
    }

    .quick-date-buttons {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
    }

    .quick-date-buttons button {
        padding: 5px 10px;
        border: 1px solid #e2e8f0;
        background: white;
        border-radius: 5px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .quick-date-buttons button:hover {
        background: #f7fafc;
        border-color: #cbd5e0;
    }

    .search-actions {
        display: flex;
        gap: 10px;
        justify-content: center;
        padding-top: 20px;
        border-top: 1px solid #e2e8f0;
        margin-top: 20px;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .btn-primary {
        background: linear-gradient(145deg, #667eea, #764ba2);
        color: white;
    }

    .btn-secondary {
        background: #e2e8f0;
        color: #4a5568;
    }

    .btn-warning {
        background: #ed8936;
        color: white;
    }

    .btn-info {
        background: #3182ce;
        color: white;
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    }

    /* Quick Search */
    .quick-search-container {
        position: relative;
        margin-bottom: 20px;
    }

    .quick-search-bar {
        display: flex;
        align-items: center;
        background: white;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        padding: 5px;
        transition: all 0.3s ease;
    }

    .quick-search-bar:focus-within {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .quick-search-bar input {
        flex: 1;
        border: none;
        outline: none;
        padding: 10px 15px;
        font-size: 16px;
    }

    .quick-search-bar button {
        background: #667eea;
        color: white;
        border: none;
        border-radius: 8px;
        padding: 10px 15px;
        cursor: pointer;
        font-size: 16px;
        transition: all 0.2s ease;
    }

    .quick-search-bar button:hover {
        background: #5a67d8;
    }

    .quick-search-results {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        z-index: 1000;
        max-height: 400px;
        overflow-y: auto;
        margin-top: 5px;
    }

    .quick-search-item {
        padding: 15px;
        border-bottom: 1px solid #f7fafc;
        cursor: pointer;
        transition: background 0.2s ease;
    }

    .quick-search-item:hover {
        background: #f7fafc;
    }

    .quick-search-item:last-child {
        border-bottom: none;
    }

    .quick-search-item-title {
        font-weight: 600;
        color: #2d3748;
        margin-bottom: 5px;
    }

    .quick-search-item-meta {
        font-size: 12px;
        color: #718096;
        display: flex;
        gap: 15px;
    }

    /* Search Results */
    .search-results-container {
        background: white;
        border-radius: 15px;
        padding: 20px;
        margin-top: 20px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
    }

    .search-results-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid #e2e8f0;
    }

    .search-results-info {
        font-size: 16px;
        color: #2d3748;
    }

    .search-results-actions {
        display: flex;
        gap: 10px;
    }

    .btn-sm {
        padding: 5px 12px;
        font-size: 12px;
    }

    .search-results {
        display: grid;
        gap: 15px;
    }

    .search-result-item {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 20px;
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .search-result-item:hover {
        border-color: #667eea;
        box-shadow: 0 5px 20px rgba(102, 126, 234, 0.1);
        transform: translateY(-2px);
    }

    .search-result-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 10px;
    }

    .search-result-title {
        font-weight: 600;
        color: #2d3748;
        font-size: 16px;
    }

    .search-result-badges {
        display: flex;
        gap: 5px;
    }

    .badge {
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
    }

    .search-result-content {
        color: #4a5568;
        line-height: 1.6;
        margin-bottom: 15px;
    }

    .search-result-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
        color: #718096;
    }

    .search-pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #e2e8f0;
    }

    .pagination-btn {
        padding: 8px 12px;
        border: 1px solid #e2e8f0;
        background: white;
        border-radius: 5px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .pagination-btn:hover {
        background: #f7fafc;
        border-color: #cbd5e0;
    }

    .pagination-btn.active {
        background: #667eea;
        color: white;
        border-color: #667eea;
    }

    .pagination-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .search-modal-content {
            width: 95vw;
            max-height: 95vh;
        }

        .search-row {
            grid-template-columns: 1fr;
        }

        .search-actions {
            flex-direction: column;
        }

        .search-results-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .quick-date-buttons {
            justify-content: flex-start;
        }
    }

    /* Loading and Empty States */
    .search-loading {
        text-align: center;
        padding: 50px;
        color: #718096;
    }

    .search-empty {
        text-align: center;
        padding: 50px;
        color: #a0aec0;
    }

    .search-error {
        text-align: center;
        padding: 50px;
        color: #e53e3e;
    }
</style>

<script>
    /**
     * Advanced Search System JavaScript
     */
    class AdvancedSearchSystem {
        constructor() {
            this.currentResults = [];
            this.currentPage = 1;
            this.totalPages = 1;
            this.searchParams = {};
            this.suggestions = [];

            this.init();
        }

        init() {
            this.bindEvents();
            this.loadFacets();
            this.initializeUserRole();
        }

        bindEvents() {
            // Advanced Search Modal
            document.addEventListener('click', (e) => {
                if (e.target.closest('.search-modal-close') ||
                    (e.target.classList.contains('search-modal') && !e.target.closest('.search-modal-content'))) {
                    this.closeAdvancedSearch();
                }
            });

            // Advanced Search Form
            const advancedForm = document.getElementById('advancedSearchForm');
            if (advancedForm) {
                advancedForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.performAdvancedSearch();
                });
            }

            // Quick Search
            const quickSearchInput = document.getElementById('quickSearchInput');
            if (quickSearchInput) {
                quickSearchInput.addEventListener('input', (e) => {
                    this.handleQuickSearch(e.target.value);
                });

                quickSearchInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.performQuickSearch(e.target.value);
                    }
                });
            }

            // Search Suggestions
            const searchQuery = document.getElementById('searchQuery');
            if (searchQuery) {
                searchQuery.addEventListener('input', (e) => {
                    this.getSuggestions(e.target.value);
                });
            }

            // Close suggestions when clicking outside
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.search-input-container')) {
                    this.hideSuggestions();
                }
            });
        }

        initializeUserRole() {
            // Show staff-only sections for teachers
            const userRole = this.getCurrentUserRole();
            if (userRole === 'teacher') {
                const staffSections = document.querySelectorAll('.staff-only');
                staffSections.forEach(section => {
                    section.style.display = 'block';
                });
            }
        }

        getCurrentUserRole() {
            // This should be set by PHP
            return window.currentUserRole || 'student';
        }

        async loadFacets() {
            try {
                const response = await fetch('../includes/advanced_search.php?ajax=1&action=stats');
                const data = await response.json();

                if (data.stats) {
                    // Load categories into select
                    await this.loadCategories();
                    // Load staff if user is teacher
                    if (this.getCurrentUserRole() === 'teacher') {
                        await this.loadStaff();
                    }
                }
            } catch (error) {
                console.error('Error loading facets:', error);
            }
        }

        async loadCategories() {
            try {
                // This should be loaded from the facets API
                const categories = [{
                        Type_id: 1,
                        Type_infor: 'เรื่องการเรียนการสอน',
                        Type_icon: '📚'
                    },
                    {
                        Type_id: 2,
                        Type_infor: 'สิ่งอำนวยความสะดวก',
                        Type_icon: '🏢'
                    },
                    {
                        Type_id: 3,
                        Type_infor: 'เรื่องการเงิน',
                        Type_icon: '💰'
                    },
                    {
                        Type_id: 4,
                        Type_infor: 'บุคลากร/เจ้าหน้าที่',
                        Type_icon: '👥'
                    },
                    {
                        Type_id: 5,
                        Type_infor: 'ระบบเทคโนโลยี',
                        Type_icon: '🌐'
                    },
                    {
                        Type_id: 6,
                        Type_infor: 'การคมนาคม',
                        Type_icon: '🚌'
                    },
                    {
                        Type_id: 7,
                        Type_infor: 'บริการสุขภาพ',
                        Type_icon: '🏥'
                    },
                    {
                        Type_id: 8,
                        Type_infor: 'อื่นๆ',
                        Type_icon: '📋'
                    }
                ];

                const select = document.querySelector('select[name="category"]');
                if (select) {
                    categories.forEach(cat => {
                        const option = document.createElement('option');
                        option.value = cat.Type_id;
                        option.textContent = `${cat.Type_icon} ${cat.Type_infor}`;
                        select.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Error loading categories:', error);
            }
        }

        async loadStaff() {
            try {
                // This should be loaded from the facets API
                const select = document.querySelector('select[name="assigned_to"]');
                if (select) {
                    const option = document.createElement('option');
                    option.value = '1';
                    option.textContent = 'อาจารย์สมชาย ใจดี';
                    select.appendChild(option);
                }
            } catch (error) {
                console.error('Error loading staff:', error);
            }
        }

        openAdvancedSearch() {
            const modal = document.getElementById('advancedSearchModal');
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }

        closeAdvancedSearch() {
            const modal = document.getElementById('advancedSearchModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }

        async handleQuickSearch(query, delay = 300) {
            clearTimeout(this.quickSearchTimeout);

            if (query.length < 2) {
                this.hideQuickSearchResults();
                return;
            }

            this.quickSearchTimeout = setTimeout(async () => {
                try {
                    const response = await fetch('../includes/advanced_search.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `ajax=1&action=quick_search&q=${encodeURIComponent(query)}&limit=5`
                    });

                    const data = await response.json();

                    if (data.results) {
                        this.showQuickSearchResults(data.results);
                    }
                } catch (error) {
                    console.error('Quick search error:', error);
                }
            }, delay);
        }

        showQuickSearchResults(results) {
            const container = document.getElementById('quickSearchResults');
            if (!container) return;

            if (results.length === 0) {
                container.innerHTML = '<div class="quick-search-item">ไม่พบผลลัพธ์</div>';
                container.style.display = 'block';
                return;
            }

            const html = results.map(result => `
            <div class="quick-search-item" onclick="viewComplaint(${result.Re_id})">
                <div class="quick-search-item-title">
                    ${result.Type_icon || '📋'} #${result.Re_id} - ${this.truncateText(result.Re_infor, 50)}
                </div>
                <div class="quick-search-item-meta">
                    <span>${result.requester_name || 'ไม่ระบุตัวตน'}</span>
                    <span>${result.time_ago}</span>
                    <span class="badge" style="background: ${this.getStatusColor(result.Re_status)}">
                        ${this.getStatusText(result.Re_status)}
                    </span>
                </div>
            </div>
        `).join('');

            container.innerHTML = html;
            container.style.display = 'block';
        }

        hideQuickSearchResults() {
            const container = document.getElementById('quickSearchResults');
            if (container) {
                container.style.display = 'none';
            }
        }

        async performQuickSearch(query) {
            if (!query.trim()) return;

            // Set the query in advanced search and perform search
            document.getElementById('searchQuery').value = query;
            this.hideQuickSearchResults();
            await this.performAdvancedSearch({
                q: query
            });
        }

        async performAdvancedSearch(overrideParams = {}) {
            const form = document.getElementById('advancedSearchForm');
            const formData = new FormData(form);

            // Convert FormData to object
            const params = Object.fromEntries(formData.entries());

            // Override with any provided params
            Object.assign(params, overrideParams);

            // Add pagination
            params.page = this.currentPage;
            params.ajax = '1';
            params.action = 'search';

            this.searchParams = params;

            try {
                this.showLoading();

                const response = await fetch('../includes/advanced_search.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams(params)
                });

                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                this.currentResults = data.results || [];
                this.totalPages = data.total_pages || 1;

                this.displaySearchResults(data);
                this.closeAdvancedSearch();

            } catch (error) {
                console.error('Advanced search error:', error);
                this.showError('เกิดข้อผิดพลาดในการค้นหา: ' + error.message);
            }
        }

        displaySearchResults(data) {
            const container = document.getElementById('searchResultsContainer');
            const resultsDiv = document.getElementById('searchResults');
            const countSpan = document.getElementById('searchResultsCount');
            const queryInfo = document.getElementById('searchQueryInfo');

            if (!container || !resultsDiv) return;

            // Update count and query info
            if (countSpan) {
                countSpan.textContent = data.total || 0;
            }

            if (queryInfo && data.query_info) {
                queryInfo.textContent = data.query_info.length > 0 ? ' สำหรับ: ' + data.query_info.join(', ') : '';
            }

            // Display results
            if (data.results && data.results.length > 0) {
                const html = data.results.map(result => this.renderSearchResultItem(result)).join('');
                resultsDiv.innerHTML = html;

                // Update pagination
                this.renderPagination(data);
            } else {
                resultsDiv.innerHTML = '<div class="search-empty">ไม่พบผลลัพธ์ที่ตรงกับเงื่อนไขการค้นหา</div>';
                document.getElementById('searchPagination').innerHTML = '';
            }

            // Show results container
            container.style.display = 'block';

            // Scroll to results
            container.scrollIntoView({
                behavior: 'smooth'
            });
        }

        renderSearchResultItem(result) {
            const statusColor = this.getStatusColor(result.Re_status);
            const priorityColor = this.getPriorityColor(result.Re_level);

            return `
            <div class="search-result-item" onclick="viewComplaint(${result.Re_id})">
                <div class="search-result-header">
                    <div class="search-result-title">
                        ${result.Type_icon || '📋'} #${result.Re_id} - ${this.truncateText(result.Re_infor, 80)}
                    </div>
                    <div class="search-result-badges">
                        <span class="badge" style="background: ${statusColor}; color: white;">
                            ${result.status_text}
                        </span>
                        <span class="badge" style="background: ${priorityColor}; color: white;">
                            ${result.priority_text}
                        </span>
                        ${result.Eva_score ? `<span class="badge" style="background: #48bb78; color: white;">⭐ ${result.Eva_score}</span>` : ''}
                    </div>
                </div>
                
                <div class="search-result-content">
                    ${result.Re_infor_excerpt}
                </div>
                
                <div class="search-result-meta">
                    <div>
                        <span>📅 ${this.formatDate(result.Re_date)}</span>
                        <span>👤 ${result.requester_name}</span>
                        ${result.assigned_staff_name ? `<span>🧑‍💼 ${result.assigned_staff_name}</span>` : ''}
                        ${result.response_time_text ? `<span>⏱️ ${result.response_time_text}</span>` : ''}
                    </div>
                    <div>
                        <span>${result.time_ago}</span>
                        ${result.file_count > 0 ? `<span>📎 ${result.file_count} ไฟล์</span>` : ''}
                        ${result.response_count > 0 ? `<span>💬 ${result.response_count} การตอบ</span>` : ''}
                    </div>
                </div>
            </div>
        `;
        }

        renderPagination(data) {
            const container = document.getElementById('searchPagination');
            if (!container) return;

            const currentPage = data.page || 1;
            const totalPages = data.total_pages || 1;

            if (totalPages <= 1) {
                container.innerHTML = '';
                return;
            }

            let paginationHTML = '';

            // Previous button
            paginationHTML += `
            <button class="pagination-btn" ${currentPage <= 1 ? 'disabled' : ''} 
                    onclick="searchSystem.goToPage(${currentPage - 1})">
                ← ก่อนหน้า
            </button>
        `;

            // Page numbers
            const startPage = Math.max(1, currentPage - 2);
            const endPage = Math.min(totalPages, currentPage + 2);

            if (startPage > 1) {
                paginationHTML += `<button class="pagination-btn" onclick="searchSystem.goToPage(1)">1</button>`;
                if (startPage > 2) {
                    paginationHTML += '<span>...</span>';
                }
            }

            for (let i = startPage; i <= endPage; i++) {
                paginationHTML += `
                <button class="pagination-btn ${i === currentPage ? 'active' : ''}" 
                        onclick="searchSystem.goToPage(${i})">
                    ${i}
                </button>
            `;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    paginationHTML += '<span>...</span>';
                }
                paginationHTML += `<button class="pagination-btn" onclick="searchSystem.goToPage(${totalPages})">${totalPages}</button>`;
            }

            // Next button
            paginationHTML += `
            <button class="pagination-btn" ${currentPage >= totalPages ? 'disabled' : ''} 
                    onclick="searchSystem.goToPage(${currentPage + 1})">
                ถัดไป →
            </button>
        `;

            container.innerHTML = paginationHTML;
        }

        async goToPage(page) {
            if (page < 1 || page > this.totalPages || page === this.currentPage) return;

            this.currentPage = page;
            await this.performAdvancedSearch();
        }

        async getSuggestions(query) {
            if (query.length < 2) {
                this.hideSuggestions();
                return;
            }

            clearTimeout(this.suggestionTimeout);

            this.suggestionTimeout = setTimeout(async () => {
                try {
                    const response = await fetch('../includes/advanced_search.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `ajax=1&action=suggestions&q=${encodeURIComponent(query)}`
                    });

                    const data = await response.json();

                    if (data.suggestions) {
                        this.showSuggestions(data.suggestions);
                    }
                } catch (error) {
                    console.error('Suggestions error:', error);
                }
            }, 300);
        }

        showSuggestions(suggestions) {
            const container = document.getElementById('searchSuggestions');
            if (!container) return;

            if (suggestions.length === 0) {
                this.hideSuggestions();
                return;
            }

            const html = suggestions.map(suggestion => `
            <div class="search-suggestion-item" onclick="searchSystem.selectSuggestion('${this.escapeHtml(suggestion)}')">
                ${this.highlightQuery(suggestion, document.getElementById('searchQuery').value)}
            </div>
        `).join('');

            container.innerHTML = html;
            container.style.display = 'block';
        }

        hideSuggestions() {
            const container = document.getElementById('searchSuggestions');
            if (container) {
                container.style.display = 'none';
            }
        }

        selectSuggestion(suggestion) {
            document.getElementById('searchQuery').value = suggestion;
            this.hideSuggestions();
        }

        showLoading() {
            const resultsDiv = document.getElementById('searchResults');
            if (resultsDiv) {
                resultsDiv.innerHTML = '<div class="search-loading">🔍 กำลังค้นหา...</div>';
            }

            const container = document.getElementById('searchResultsContainer');
            if (container) {
                container.style.display = 'block';
            }
        }

        showError(message) {
            const resultsDiv = document.getElementById('searchResults');
            if (resultsDiv) {
                resultsDiv.innerHTML = `<div class="search-error">❌ ${message}</div>`;
            }

            const container = document.getElementById('searchResultsContainer');
            if (container) {
                container.style.display = 'block';
            }
        }

        clearSearchResults() {
            const container = document.getElementById('searchResultsContainer');
            if (container) {
                container.style.display = 'none';
            }

            // Clear quick search
            document.getElementById('quickSearchInput').value = '';
            this.hideQuickSearchResults();

            // Reset pagination
            this.currentPage = 1;
            this.currentResults = [];
        }

        resetSearchForm() {
            const form = document.getElementById('advancedSearchForm');
            if (form) {
                form.reset();
            }

            // Clear suggestions
            this.hideSuggestions();
        }

        // Utility functions
        truncateText(text, maxLength) {
            if (text.length <= maxLength) return text;
            return text.substring(0, maxLength) + '...';
        }

        formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('th-TH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        getStatusColor(status) {
            const colors = {
                '0': '#ed8936', // orange
                '1': '#3182ce', // blue
                '2': '#48bb78', // green
                '3': '#718096' // gray
            };
            return colors[status] || '#a0aec0';
        }

        getStatusText(status) {
            const texts = {
                '0': 'รอดำเนินการ',
                '1': 'กำลังดำเนินการ',
                '2': 'เสร็จสิ้น',
                '3': 'ประเมินแล้ว'
            };
            return texts[status] || 'ไม่ระบุ';
        }

        getPriorityColor(level) {
            const colors = {
                '1': '#48bb78', // green
                '2': '#3182ce', // blue
                '3': '#ed8936', // orange
                '4': '#e53e3e', // red
                '5': '#9f7aea' // purple
            };
            return colors[level] || '#a0aec0';
        }

        highlightQuery(text, query) {
            if (!query || query.length < 2) return this.escapeHtml(text);

            const escapedQuery = this.escapeRegex(query);
            const regex = new RegExp(`(${escapedQuery})`, 'gi');
            return this.escapeHtml(text).replace(regex, '<mark>$1</mark>');
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        escapeRegex(string) {
            if (!string) return '';
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        // Export functions
        async exportSearchResults(format) {
            if (!this.searchParams.q && !Object.keys(this.searchParams).some(key => this.searchParams[key])) {
                alert('กรุณาทำการค้นหาก่อนการ Export');
                return;
            }

            const params = new URLSearchParams(this.searchParams);
            params.set('export', format);
            params.set('all_results', '1'); // Export all results, not just current page

            // Create a temporary form to submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '../includes/advanced_search.php';
            form.style.display = 'none';

            // Add all parameters as hidden inputs
            for (const [key, value] of params.entries()) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        // Search presets
        saveSearchPreset() {
            const form = document.getElementById('advancedSearchForm');
            const formData = new FormData(form);
            const preset = Object.fromEntries(formData.entries());

            const name = prompt('ชื่อการค้นหาที่บันทึก:');
            if (!name) return;

            const presets = JSON.parse(localStorage.getItem('searchPresets') || '{}');
            presets[name] = preset;
            localStorage.setItem('searchPresets', JSON.stringify(presets));

            alert('บันทึกการค้นหาเรียบร้อย');
        }

        loadSearchPreset() {
            const presets = JSON.parse(localStorage.getItem('searchPresets') || '{}');
            const presetNames = Object.keys(presets);

            if (presetNames.length === 0) {
                alert('ไม่มีการค้นหาที่บันทึกไว้');
                return;
            }

            const selectedPreset = prompt('เลือกการค้นหาที่บันทึก:\n' + presetNames.map((name, index) => `${index + 1}. ${name}`).join('\n'));
            if (!selectedPreset) return;

            const index = parseInt(selectedPreset) - 1;
            if (index < 0 || index >= presetNames.length) {
                alert('เลือกหมายเลขไม่ถูกต้อง');
                return;
            }

            const presetName = presetNames[index];
            const preset = presets[presetName];

            // Load preset into form
            const form = document.getElementById('advancedSearchForm');
            Object.keys(preset).forEach(key => {
                const field = form.querySelector(`[name="${key}"]`);
                if (field) {
                    field.value = preset[key];
                }
            });

            alert(`โหลดการค้นหา "${presetName}" เรียบร้อย`);
        }
    }

    // Utility functions for external use
    function openAdvancedSearch() {
        if (window.searchSystem) {
            window.searchSystem.openAdvancedSearch();
        }
    }

    function viewComplaint(complaintId) {
        // This should navigate to the complaint detail page
        const userRole = window.currentUserRole || 'student';
        if (userRole === 'student') {
            window.location.href = `detail.php?id=${complaintId}`;
        } else {
            window.location.href = `complaint-detail.php?id=${complaintId}`;
        }
    }

    function setQuickDate(range) {
        const dateFrom = document.querySelector('input[name="date_from"]');
        const dateTo = document.querySelector('input[name="date_to"]');
        const today = new Date();

        function formatDate(date) {
            return date.toISOString().split('T')[0];
        }

        switch (range) {
            case 'today':
                dateFrom.value = formatDate(today);
                dateTo.value = formatDate(today);
                break;
            case 'week':
                const weekAgo = new Date(today);
                weekAgo.setDate(weekAgo.getDate() - 7);
                dateFrom.value = formatDate(weekAgo);
                dateTo.value = formatDate(today);
                break;
            case 'month':
                const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
                dateFrom.value = formatDate(monthStart);
                dateTo.value = formatDate(today);
                break;
            case 'quarter':
                const quarterAgo = new Date(today);
                quarterAgo.setMonth(quarterAgo.getMonth() - 3);
                dateFrom.value = formatDate(quarterAgo);
                dateTo.value = formatDate(today);
                break;
        }
    }

    function resetSearchForm() {
        if (window.searchSystem) {
            window.searchSystem.resetSearchForm();
        }
    }

    function saveSearchPreset() {
        if (window.searchSystem) {
            window.searchSystem.saveSearchPreset();
        }
    }

    function loadSearchPreset() {
        if (window.searchSystem) {
            window.searchSystem.loadSearchPreset();
        }
    }

    function exportSearchResults(format) {
        if (window.searchSystem) {
            window.searchSystem.exportSearchResults(format);
        }
    }

    function clearSearchResults() {
        if (window.searchSystem) {
            window.searchSystem.clearSearchResults();
        }
    }

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        // Set current user role for JavaScript access
        window.currentUserRole = '<?php echo $_SESSION["user_role"] ?? "student"; ?>';

        // Initialize search system
        window.searchSystem = new AdvancedSearchSystem();

        // Initialize any existing search parameters from URL
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('search')) {
            document.getElementById('quickSearchInput').value = urlParams.get('search');
            window.searchSystem.performQuickSearch(urlParams.get('search'));
        }
    });

    // Add keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + K to open advanced search
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            openAdvancedSearch();
        }

        // Escape to close modals
        if (e.key === 'Escape') {
            const modal = document.getElementById('advancedSearchModal');
            if (modal && modal.style.display !== 'none') {
                window.searchSystem.closeAdvancedSearch();
            }
        }
    });
</script>