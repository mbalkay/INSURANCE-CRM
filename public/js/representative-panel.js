/**
 * Representative Panel JavaScript
 */
jQuery(document).ready(function($) {
    // Session timeout monitoring (60 minutes)
    var sessionTimeoutInterval;
    var sessionWarningShown = false;
    
    function initSessionTimeout() {
        // Check session every 5 minutes
        sessionTimeoutInterval = setInterval(checkSession, 5 * 60 * 1000);
        
        // Also check session on user activity
        $(document).on('click keypress mousemove', function() {
            if (!sessionWarningShown) {
                checkSession();
            }
        });
    }
    
    function checkSession() {
        $.ajax({
            url: insurance_crm_ajax.ajax_url || (window.location.origin + '/wp-admin/admin-ajax.php'),
            type: 'POST',
            data: {
                action: 'insurance_crm_check_session'
            },
            timeout: 10000, // 10 second timeout
            success: function(response) {
                if (!response.success) {
                    handleSessionTimeout();
                } else {
                    sessionWarningShown = false;
                    // Show warning if less than 10 minutes remaining
                    var remainingMinutes = Math.floor(response.data.remaining_seconds / 60);
                    if (remainingMinutes <= 10 && remainingMinutes > 0 && !sessionWarningShown) {
                        showSessionWarning(remainingMinutes);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.warn('Session check failed:', status, error);
                // Only handle as timeout if it's a clear authentication issue
                if (xhr.status === 401 || xhr.status === 403) {
                    handleSessionTimeout();
                }
                // For other errors (network issues, etc.), retry later
            }
        });
    }
    
    function showSessionWarning(remainingMinutes) {
        sessionWarningShown = true;
        var message = 'Oturumunuz ' + remainingMinutes + ' dakika sonra sona erecek. Devam etmek için herhangi bir yere tıklayın.';
        
        if (confirm(message)) {
            // User wants to continue, check session again
            sessionWarningShown = false;
            checkSession();
        } else {
            // User chose to logout or close
            window.location.href = '/temsilci-girisi/?logout=1';
        }
    }
    
    function handleSessionTimeout() {
        clearInterval(sessionTimeoutInterval);
        alert('Oturumunuz 60 dakika hareketsizlik nedeniyle sona erdi. Tekrar giriş yapmanız gerekiyor.');
        window.location.href = '/temsilci-girisi/?timeout=1';
    }
    
    // Initialize session timeout monitoring
    initSessionTimeout();
    
    // Hızlı Ekle dropdown
    $('#quick-add-toggle').on('click', function(e) {
        e.preventDefault();
        $('.quick-add-dropdown .dropdown-content').toggleClass('show');
    });
    
    // Bildirimler dropdown
    $('#notifications-toggle').on('click', function(e) {
        e.preventDefault();
        $('.notifications-dropdown .dropdown-content').toggleClass('show');
    });
    
    // Dropdown dışına tıklandığında kapat
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.quick-add-dropdown').length) {
            $('.quick-add-dropdown .dropdown-content').removeClass('show');
        }
        
        if (!$(e.target).closest('.notifications-dropdown').length) {
            $('.notifications-dropdown .dropdown-content').removeClass('show');
        }
    });
    
    // Eğer ChartJS ve productionChart elementi varsa grafik çiz
    if (typeof Chart !== 'undefined' && $('#productionChart').length > 0) {
        const ctx = document.getElementById('productionChart').getContext('2d');
        
    
    // Form submit butonları için onay sorgusu
    $('.confirm-action').on('click', function(e) {
        if (!confirm($(this).data('confirm') || 'Bu işlemi onaylıyor musunuz?')) {
            e.preventDefault();
        }
    });
    
    // DatePicker eklentisi
    if ($.fn.datepicker) {
        $('.datepicker').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true
        });
    // ===================================
    // HELPDESK SYSTEM FUNCTIONALITY
    // ===================================
    
    // Helpdesk Manager Class
    class HelpdeskManager {
        constructor() {
            this.selectedFiles = [];
            this.maxFiles = 5;
            this.maxFileSize = 5 * 1024 * 1024; // 5MB
            this.allowedTypes = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'txt', 'zip'];
            this.init();
        }
        
        init() {
            if ($('#helpdesk-form').length === 0) return; // Only run on helpdesk page
            
            this.bindEvents();
            this.initFileUpload();
            this.initFormValidation();
            this.updatePriorityDisplay();
        }
        
        bindEvents() {
            // Priority selection change
            $('#priority').on('change', () => {
                this.updatePriorityDisplay();
            });
            
            // Form validation on input changes
            $('.form-control[required]').on('blur input', () => {
                this.validateFormState();
            });
            
            // Representative selection change
            $('#representative_id').on('change', () => {
                this.validateFormState();
            });
            
            // Debug log checkbox change
            $('#include_debug_log').on('change', (e) => {
                this.toggleDebugLogInfo(e.target.checked);
            });
            
            // File input change
            $('#helpdesk-files').on('change', (e) => {
                this.handleFileSelection(e.target.files);
            });
        }
        
        initFileUpload() {
            const uploadArea = $('.file-upload-area');
            const fileInput = $('#helpdesk-files');
            
            // Click to select file
            uploadArea.on('click', () => {
                fileInput.trigger('click');
            });
            
            // Drag and drop events
            uploadArea.on('dragover', (e) => {
                e.preventDefault();
                uploadArea.addClass('drag-over');
            });
            
            uploadArea.on('dragleave', (e) => {
                e.preventDefault();
                uploadArea.removeClass('drag-over');
            });
            
            uploadArea.on('drop', (e) => {
                e.preventDefault();
                uploadArea.removeClass('drag-over');
                const files = e.originalEvent.dataTransfer.files;
                this.handleFileSelection(files);
            });
        }
        
        initFormValidation() {
            // Add validation event listeners
            $('.form-control[required]').each((index, element) => {
                $(element).on('blur', () => {
                    this.validateFormState();
                });
            });
            
            // Initial validation
            this.validateFormState();
        }
        
        validateFormState() {
            const submitBtn = $('#submit-btn');
            let isValid = true;
            
            $('.form-control[required]').each(function() {
                const $this = $(this);
                const value = $this.val().trim();
                
                if (!value) {
                    $this.addClass('error');
                    isValid = false;
                } else {
                    $this.removeClass('error');
                }
            });
            
            // Enable/disable submit button based on validation
            submitBtn.prop('disabled', !isValid);
            
            if (isValid) {
                submitBtn.removeClass('disabled');
            } else {
                submitBtn.addClass('disabled');
            }
        }
        
        updatePriorityDisplay() {
            const prioritySelect = $('#priority');
            const priorityPreview = $('#priority-preview');
            const selectedValue = prioritySelect.val();
            
            if (!selectedValue) {
                priorityPreview.hide();
                return;
            }
            
            const priorityTexts = {
                'low': 'Düşük Öncelik - 5-7 iş günü içinde yanıtlanır',
                'normal': 'Normal Öncelik - 2-3 iş günü içinde yanıtlanır',
                'high': 'Yüksek Öncelik - 24 saat içinde yanıtlanır',
                'urgent': 'Acil - 4 saat içinde yanıtlanır'
            };
            
            priorityPreview
                .text(priorityTexts[selectedValue] || '')
                .removeClass('low normal high urgent')
                .addClass(selectedValue)
                .show();
        }
        
        handleFileSelection(files) {
            if (!files || files.length === 0) return;
            
            Array.from(files).forEach(file => {
                if (this.selectedFiles.length >= this.maxFiles) {
                    this.showNotification('En fazla ' + this.maxFiles + ' dosya seçebilirsiniz.', 'error');
                    return;
                }
                
                if (file.size > this.maxFileSize) {
                    this.showNotification(`"${file.name}" dosyası çok büyük. Maksimum 5MB olmalıdır.`, 'error');
                    return;
                }
                
                const extension = file.name.split('.').pop().toLowerCase();
                if (!this.allowedTypes.includes(extension)) {
                    this.showNotification(`"${file.name}" dosya türü desteklenmiyor.`, 'error');
                    return;
                }
                
                this.selectedFiles.push(file);
            });
            
            this.updateFilePreview();
            this.updateFileInput();
        }
        
        updateFilePreview() {
            const preview = $('#file-preview');
            
            if (this.selectedFiles.length === 0) {
                preview.hide();
                return;
            }
            
            let html = '';
            this.selectedFiles.forEach((file, index) => {
                html += `
                    <div class="file-item" data-index="${index}">
                        <i class="fas fa-file"></i>
                        <span class="file-name">${file.name}</span>
                        <i class="fas fa-times file-remove" onclick="helpdeskManager.removeFile(${index})"></i>
                    </div>
                `;
            });
            
            preview.html(html).show();
        }
        
        updateFileInput() {
            const fileInput = $('#helpdesk-files')[0];
            const dt = new DataTransfer();
            
            this.selectedFiles.forEach(file => {
                dt.items.add(file);
            });
            
            fileInput.files = dt.files;
        }
        
        removeFile(index) {
            this.selectedFiles.splice(index, 1);
            this.updateFilePreview();
            this.updateFileInput();
        }
        
        toggleDebugLogInfo(checked) {
            const debugInfo = $('.debug-info');
            if (checked) {
                debugInfo.css('opacity', '1');
            } else {
                debugInfo.css('opacity', '0.7');
            }
        }
        
        showNotification(message, type = 'info') {
            const notification = $(`
                <div class="helpdesk-notification ${type}">
                    <i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                    <span>${message}</span>
                    <button type="button" class="notification-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `);
            
            $('.helpdesk-container').prepend(notification);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                notification.fadeOut(() => notification.remove());
            }, 5000);
            
            // Manual close
            notification.find('.notification-close').on('click', () => {
                notification.fadeOut(() => notification.remove());
            });
        }
        
        resetForm() {
            $('#helpdesk-form')[0].reset();
            this.selectedFiles = [];
            this.updateFilePreview();
            $('#priority-preview').hide();
            $('.form-control').removeClass('error');
            this.validateFormState(); // Re-validate form state after reset
        }
    }
    
    // Initialize Helpdesk Manager
    let helpdeskManager;
    if ($('#helpdesk-form').length > 0) {
        helpdeskManager = new HelpdeskManager();
        
        // Make it globally accessible for file removal
        window.helpdeskManager = helpdeskManager;
    }
    }
});