<?php
/**
 * Görev Ekleme/Düzenleme Formu
 * @version 2.3.3
 * @date 2025-06-24
 * @author anadolubirlik
 * @description Veritabanı sütun uyumsuzluğu düzeltildi (assigned_to -> representative_id, description -> task_description)
 */

include_once(dirname(__FILE__) . '/template-colors.php');

if (!is_user_logged_in()) {
    return;
}

// Handle URL parameters for pre-filling the form
$preselected_customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : null;
$preselected_policy_id = isset($_GET['policy_id']) ? intval($_GET['policy_id']) : null;

// Veritabanı kontrolü ve task tablosuna gerekli sütunlar eklenmesi
global $wpdb;
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';

// Tablonun varlığını kontrol et
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$tasks_table'");
if (!$table_exists) {
    // Tablo yoksa oluştur
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $tasks_table (
        id int(11) NOT NULL AUTO_INCREMENT,
        task_title varchar(255) NOT NULL,
        customer_id int(11) NOT NULL,
        policy_id int(11) DEFAULT NULL,
        representative_id int(11) NOT NULL,
        task_description text,
        status varchar(50) DEFAULT 'pending',
        priority varchar(20) DEFAULT 'medium',
        due_date datetime DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Form verilerini işle
$message = '';
$message_type = '';

if ($_POST && isset($_POST['action']) && $_POST['action'] === 'add_task') {
    $task_title = sanitize_text_field($_POST['task_title']);
    $customer_id = intval($_POST['customer_id']);
    $policy_id = !empty($_POST['policy_id']) ? intval($_POST['policy_id']) : null;
    $assigned_to = intval($_POST['assigned_to']);
    $description = sanitize_textarea_field($_POST['description']);
    $due_date = !empty($_POST['due_date']) ? sanitize_text_field($_POST['due_date']) : null;
    $priority = sanitize_text_field($_POST['priority']);
    $current_user_id = get_current_user_id();

    // DETAYLI DEBUG LOGGING
    error_log("=== TASK FORM SUBMISSION DEBUG ===");
    error_log("Raw POST data: " . print_r($_POST, true));
    error_log("Sanitized values:");
    error_log("- task_title: '" . $task_title . "' (length: " . strlen($task_title) . ")");
    error_log("- customer_id: " . $customer_id);
    error_log("- policy_id: " . ($policy_id ? $policy_id : 'NULL'));
    error_log("- assigned_to: " . $assigned_to);
    error_log("- description: '" . $description . "'");
    error_log("- due_date: '" . $due_date . "'");
    error_log("- priority: '" . $priority . "'");
    error_log("- current_user_id: " . $current_user_id);

    // Validation
    $errors = [];
    if (empty($task_title)) {
        $errors[] = 'Görev başlığı gereklidir.';
        error_log("VALIDATION ERROR: empty task_title");
    }
    if (empty($customer_id)) {
        $errors[] = 'Müşteri seçimi gereklidir.';
        error_log("VALIDATION ERROR: empty customer_id");
    }
    if (empty($assigned_to)) {
        $errors[] = 'Görev atanacak kişi seçimi gereklidir.';
        error_log("VALIDATION ERROR: empty assigned_to");
    }

    if (empty($errors)) {
        // Insert verilerini hazırla - DOĞRU SÜTUN ADLARI
        $insert_data = [
            'task_title' => $task_title,
            'customer_id' => $customer_id,
            'policy_id' => $policy_id,
            'representative_id' => $assigned_to,  // assigned_to yerine representative_id
            'task_description' => $description,   // description yerine task_description
            'status' => 'pending',               // beklemede yerine pending (varsayılan değer)
            'priority' => $priority,
            'due_date' => $due_date,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
            // created_by sütunu tabloda yok, kaldırıldı
        ];
        
        $insert_format = [
            '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s'
        ];
        
        error_log("CORRECTED INSERT DATA: " . print_r($insert_data, true));
        error_log("CORRECTED INSERT FORMAT: " . print_r($insert_format, true));
        error_log("TABLE NAME: " . $tasks_table);
        
        // Tablo varlığını tekrar kontrol et
        $table_check = $wpdb->get_var("SHOW TABLES LIKE '$tasks_table'");
        error_log("Table exists check: " . ($table_check ? 'YES' : 'NO'));
        
        if (!$table_check) {
            $errors[] = 'Görev tablosu bulunamadı.';
            error_log("ERROR: Tasks table does not exist!");
        } else {
            $result = $wpdb->insert(
                $tasks_table,
                $insert_data,
                $insert_format
            );

            error_log("INSERT RESULT: " . ($result ? 'SUCCESS' : 'FAILED'));
            
            if ($result === false) {
                error_log("WPDB ERROR: " . $wpdb->last_error);
                error_log("WPDB LAST QUERY: " . $wpdb->last_query);
                $message = 'Görev eklenirken bir hata oluştu: ' . $wpdb->last_error;
                $message_type = 'error';
            } else {
                $inserted_id = $wpdb->insert_id;
                error_log("INSERTED TASK ID: " . $inserted_id);
                $message = 'Görev başarıyla eklendi. (ID: ' . $inserted_id . ')';
                $message_type = 'success';
                
                // Form başarılı olunca temizle
                $_POST = array();
            }
        }
    } else {
        error_log("VALIDATION ERRORS: " . print_r($errors, true));
        $message = implode('<br>', $errors);
        $message_type = 'error';
    }
}

// Müşteri temsilcilerini çek (policies-form.php referansı ile)
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users = $wpdb->get_results("
    SELECT r.id, u.display_name, r.title 
    FROM $representatives_table r
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    WHERE r.status = 'active'
    ORDER BY u.display_name
");

// Debug için log ekle
error_log("Task-form temsilci sorgusu çalıştırıldı. Bulunan temsilci sayısı: " . count($users));
if (empty($users)) {
    error_log("Hiç temsilci bulunamadı, alternatif sorgu deneniyor...");
    $users = $wpdb->get_results("
        SELECT r.id, r.user_id, 
               CONCAT(r.first_name, ' ', r.last_name) as display_name, r.title
        FROM $representatives_table r
        WHERE r.status = 'active'
        ORDER BY r.first_name, r.last_name
    ");
    error_log("Alternatif sorgu sonucu: " . count($users) . " temsilci bulundu");
}

// Müşterileri çek - doğru tablo adıyla
$customers = $wpdb->get_results("
    SELECT id, first_name, last_name, tc_identity, phone, category, company_name 
    FROM {$wpdb->prefix}insurance_crm_customers 
    WHERE status = 'aktif' 
    ORDER BY first_name, last_name ASC
");

// Eğer müşteri bulunamazsa alternatif sorgu dene
if (empty($customers)) {
    $customers = $wpdb->get_results("
        SELECT id, first_name, last_name, tc_identity, phone, category, company_name 
        FROM {$wpdb->prefix}insurance_crm_customers 
        ORDER BY first_name, last_name ASC
    ");
}

// Tüm poliçeleri çek - JavaScript değişkenine aktarmak için
$all_policies = $wpdb->get_results("
    SELECT id, customer_id, policy_number, policy_type, insurance_company, status, end_date
    FROM {$wpdb->prefix}insurance_crm_policies 
    WHERE status != 'iptal'
    ORDER BY customer_id, end_date ASC
");

error_log("Task form - Found " . count($users) . " assignable users");
error_log("Task form - Found " . count($customers) . " customers");
error_log("Task form - Found " . count($all_policies) . " policies");
?>

<style>
    /* Policies-form.php tarzında stil */
    .ab-task-form-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }

    .ab-form-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid <?php echo $corporate_color; ?>;
    }

    .ab-form-header h2 {
        color: <?php echo $corporate_color; ?>;
        font-size: 28px;
        font-weight: 600;
        margin: 0;
    }

    .ab-form-section {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        margin-bottom: 25px;
        padding: 25px;
    }

    .ab-form-section h3 {
        margin: 0 0 20px 0;
        color: #333;
        border-bottom: 2px solid <?php echo $corporate_color; ?>;
        padding-bottom: 10px;
        font-size: 18px;
        font-weight: 600;
    }

    .ab-form-row {
        display: flex;
        gap: 20px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .ab-form-group {
        flex: 1;
        min-width: 250px;
        display: flex;
        flex-direction: column;
    }

    .ab-form-group.full-width {
        flex: 100%;
    }

    .ab-form-group label {
        font-weight: 600;
        margin-bottom: 8px;
        color: #333;
        font-size: 14px;
    }

    .ab-form-group label.required::after {
        content: ' *';
        color: #dc3545;
    }

    .ab-input, .ab-select, .ab-textarea {
        padding: 12px;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.3s ease;
        background: #fff;
    }

    .ab-input:focus, .ab-select:focus, .ab-textarea:focus {
        border-color: <?php echo $corporate_color; ?>;
        outline: none;
        box-shadow: 0 0 0 3px <?php echo adjust_color_opacity($corporate_color, 0.1); ?>;
    }

    .ab-textarea {
        min-height: 100px;
        resize: vertical;
    }

    .selected-customer {
        background: <?php echo adjust_color_opacity($corporate_color, 0.1); ?>;
        border: 1px solid <?php echo $corporate_color; ?>;
        border-radius: 6px;
        padding: 15px;
        margin: 10px 0;
    }

    .selected-customer-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .clear-selection-btn {
        background: #dc3545;
        color: white;
        border: none;
        border-radius: 4px;
        padding: 5px 8px;
        cursor: pointer;
        font-size: 12px;
        transition: all 0.3s ease;
    }

    .clear-selection-btn:hover {
        background: #c82333;
    }

    .priority-section {
        border: 2px solid <?php echo $corporate_color; ?>;
        border-radius: 8px;
        background: <?php echo adjust_color_opacity($corporate_color, 0.05); ?>;
    }

    .section-description {
        color: #666;
        font-size: 14px;
        margin-bottom: 15px;
        font-style: italic;
    }

    .policies-section {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #ddd;
    }

    .policies-section h4 {
        color: <?php echo $corporate_color; ?>;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .policy-hint {
        font-size: 13px;
        color: #666;
        margin-bottom: 10px;
    }

    .continue-without-policy {
        margin-top: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        cursor: pointer;
    }

    .policy-radio-item {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        margin-bottom: 8px;
        transition: all 0.3s ease;
        display: flex;
        align-items: flex-start;
        padding: 10px;
    }

    .policy-radio-item:hover {
        background: #e9ecef;
        border-color: <?php echo $corporate_color; ?>;
    }

    .policy-radio-item.selected {
        background: <?php echo adjust_color_opacity($corporate_color, 0.1); ?>;
        border-color: <?php echo $corporate_color; ?>;
    }

    .policy-radio {
        margin: 0;
        margin-right: 12px;
        margin-top: 2px;
        flex-shrink: 0;
    }

    .policy-label {
        cursor: pointer;
        margin: 0;
        font-weight: normal;
        flex: 1;
        line-height: 1.4;
    }

    .policy-radio:checked + .policy-label {
        color: <?php echo $corporate_color; ?>;
        font-weight: 500;
    }

    .task-details-section {
        transition: all 0.3s ease;
    }

    .task-details-section:not(.enabled) {
        opacity: 0.5;
        pointer-events: none;
    }

    .task-details-section.enabled {
        opacity: 1;
        pointer-events: auto;
    }

    /* Kesin çözüm: Task details section açıkken tüm içerikler görünür olsun */
    .task-details-section.enabled .ab-input,
    .task-details-section.enabled .ab-select,
    .task-details-section.enabled .ab-textarea {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }

    .selected-customer-name {
        font-weight: 600;
        color: <?php echo $corporate_color; ?>;
        font-size: 16px;
    }

    .selected-customer-info {
        color: #666;
        font-size: 14px;
        margin-top: 5px;
    }

    .policies-list {
        margin-top: 15px;
    }

    .ab-form-actions {
        text-align: center;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #e9ecef;
    }

    .ab-btn {
        display: inline-block;
        padding: 12px 30px;
        margin: 0 10px;
        border: none;
        border-radius: 6px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-align: center;
    }

    .ab-btn-primary {
        background: linear-gradient(135deg, <?php echo $corporate_color; ?>, <?php echo adjust_color_opacity($corporate_color, 0.8); ?>);
        color: #fff;
    }

    .ab-btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px <?php echo adjust_color_opacity($corporate_color, 0.3); ?>;
    }

    .ab-btn-secondary {
        background: #6c757d;
        color: #fff;
    }

    .ab-btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-2px);
    }

    .notification {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 6px;
        border-left: 4px solid;
    }

    .notification.success {
        background-color: #d4edda;
        border-color: #28a745;
        color: #155724;
    }

    .notification.error {
        background-color: #f8d7da;
        border-color: #dc3545;
        color: #721c24;
    }

    .priority-high { border-left-color: #dc3545 !important; }
    .priority-normal { border-left-color: #28a745 !important; }
    .priority-low { border-left-color: #6c757d !important; }

    @media (max-width: 768px) {
        .ab-form-row {
            flex-direction: column;
        }
        
        .ab-form-group {
            min-width: 100%;
        }
    }
</style>

<div class="ab-task-form-container">
    <div class="ab-form-header">
        <h2><i class="fas fa-tasks"></i> Yeni Görev Ekle</h2>
        <a href="?view=tasks" class="ab-btn ab-btn-secondary">
            <i class="fas fa-arrow-left"></i> Görevlere Dön
        </a>
    </div>

    <?php if ($message): ?>
        <div class="notification <?php echo $message_type; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <form method="post" id="taskForm">
        <input type="hidden" name="action" value="add_task">
        <input type="hidden" name="customer_id" id="selected_customer_id" value="">
        <input type="hidden" name="policy_id" id="selected_policy_id" value="">

        <!-- Müşteri Seçimi -->
        <div class="ab-form-section priority-section">
            <h3><i class="fas fa-user-search"></i> 1. Müşteri Seçimi</h3>
            <div class="section-description">
                Önce görevi ilişkilendireceğiniz müşteriyi seçin
            </div>
            
            <div class="ab-form-group">
                <label for="customer_select" class="required">Müşteri Seçimi</label>
                <select name="customer_select" id="customer_select" class="ab-select" required>
                    <option value="">Müşteri Seçiniz...</option>
                    <?php foreach ($customers as $customer): ?>
                        <?php 
                        $display_name = $customer->first_name . ' ' . $customer->last_name;
                        if ($customer->category === 'kurumsal' && !empty($customer->company_name)) {
                            $display_name = $customer->company_name . ' (' . $customer->first_name . ' ' . $customer->last_name . ')';
                        }
                        ?>
                        <option value="<?php echo esc_attr($customer->id); ?>" 
                                data-type="<?php echo esc_attr($customer->category); ?>"
                                data-name="<?php echo esc_attr($display_name); ?>">
                            <?php echo esc_html($display_name); ?> 
                            (<?php echo esc_html($customer->category === 'kurumsal' ? 'Kurumsal' : 'Bireysel'); ?>)
                            <?php if (!empty($customer->tc_identity)): ?>
                                - TC: <?php echo esc_html($customer->tc_identity); ?>
                            <?php endif; ?>
                            <?php if (!empty($customer->phone)): ?>
                                - Tel: <?php echo esc_html($customer->phone); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="selectedCustomerInfo" class="selected-customer" style="display: none;">
                <div class="selected-customer-header">
                    <div class="selected-customer-name"></div>
                    <button type="button" class="clear-selection-btn" onclick="clearCustomerSelection()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="selected-customer-info"></div>
                <div class="policies-section">
                    <h4><i class="fas fa-file-contract"></i> İlgili Poliçe Seçin (Opsiyonel):</h4>
                    <p class="policy-hint">Görev belirli bir poliçe ile ilgiliyse seçin, aksi takdirde boş bırakabilirsiniz.</p>
                    <div id="customerPolicies"></div>
                    <label class="continue-without-policy">
                        <input type="checkbox" id="continueWithoutPolicy"> 
                        Poliçe seçmeden devam et
                    </label>
                </div>
            </div>
        </div>

        <!-- Görev Bilgileri -->
        <div class="ab-form-section task-details-section" style="display: none;">
            <h3><i class="fas fa-tasks"></i> 2. Görev Bilgileri</h3>
            <div class="section-description">
                Görev detaylarını doldurun
            </div>
            
            <div class="ab-form-row">
                <div class="ab-form-group">
                    <label for="task_title" class="required">Görev Başlığı</label>
                    <input type="text" id="task_title" name="task_title" class="ab-input" 
                           placeholder="Görev başlığını girin..." required>
                </div>
                
                <div class="ab-form-group">
                    <label for="priority" class="required">Öncelik</label>
                    <select id="priority" name="priority" class="ab-select" required>
                        <option value="">Öncelik Seçin</option>
                        <option value="low">Düşük</option>
                        <option value="medium" selected>Orta</option>
                        <option value="high">Yüksek</option>
                    </select>
                </div>
            </div>

            <div class="ab-form-row">
                <div class="ab-form-group">
                    <label for="assigned_to" class="required">Atanacak Kişi</label>
                    <select id="assigned_to" name="assigned_to" class="ab-select" required>
                        <option value="">Kişi Seçin</option>
                        <?php if (!empty($users)): ?>
                            <?php foreach ($users as $user): 
                                $title = !empty($user->title) ? $user->title : '';
                                $display_name = !empty($user->display_name) ? $user->display_name : '';
                            ?>
                                <option value="<?php echo $user->id; ?>">
                                    <?php echo esc_html($display_name); ?> 
                                    <?php if ($title): ?>
                                        (<?php echo esc_html($title); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" disabled>Müşteri temsilcisi bulunamadı</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="ab-form-group">
                    <label for="due_date">Görev Son Tarihi</label>
                    <input type="datetime-local" id="due_date" name="due_date" class="ab-input" 
                           min="<?php echo date('Y-m-d\TH:i'); ?>">
                </div>
            </div>

            <div class="ab-form-row">
                <div class="ab-form-group full-width">
                    <label for="description">Görev Açıklaması</label>
                    <textarea id="description" name="description" class="ab-textarea" 
                              placeholder="Görev detaylarını girin..."></textarea>
                </div>
            </div>
        </div>

        <div class="ab-form-actions">
            <input type="submit" class="ab-btn ab-btn-primary" value="Görev Ekle" disabled id="submitBtn">
            <a href="?view=tasks" class="ab-btn ab-btn-secondary">İptal</a>
        </div>
    </form>
</div>

<script>
const ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';

// Tüm poliçe verilerini JavaScript değişkenine aktar
const allPolicies = <?php echo json_encode($all_policies); ?>;

jQuery(document).ready(function($) {
    let selectedCustomer = null;
    
    // Handle URL parameter pre-selection
    <?php if ($preselected_customer_id): ?>
    // Pre-select customer from URL parameter
    const preselectedCustomerId = <?php echo $preselected_customer_id; ?>;
    $('#customer_select').val(preselectedCustomerId).trigger('change');
    
    <?php if ($preselected_policy_id): ?>
    // Also pre-select policy if provided
    setTimeout(function() {
        const preselectedPolicyId = <?php echo $preselected_policy_id; ?>;
        $('input[name="policy_selection"][value="' + preselectedPolicyId + '"]').prop('checked', true).trigger('change');
    }, 500);
    <?php endif; ?>
    <?php endif; ?>
    
    // Müşteri seçimi dropdown
    $('#customer_select').on('change', function() {
        const customerId = $(this).val();
        const selectedOption = $(this).find('option:selected');
        const customerName = selectedOption.data('name');
        const customerType = selectedOption.data('type');
        
        console.log('Müşteri seçildi:', customerId, customerName, customerType);
        
        if (customerId && customerName) {
            selectCustomer(customerId, customerName, customerType);
        } else {
            clearCustomerSelection();
        }
    });
    
    function selectCustomer(customerId, customerName, customerType) {
        console.log('👤 Müşteri seçme işlemi başlatılıyor:');
        console.log('  🆔 Müşteri ID:', customerId);
        console.log('  👤 Müşteri Adı:', customerName);
        console.log('  🏢 Müşteri Türü:', customerType);
        
        selectedCustomer = {
            id: customerId,
            name: customerName,
            type: customerType
        };
        
        // Hidden field'ı güncelle
        $('#selected_customer_id').val(customerId);
        console.log('🔑 Hidden field güncellendi. Değer:', $('#selected_customer_id').val());
        
        // Display customer info
        $('.selected-customer-name').text(customerName);
        $('.selected-customer-info').text(`Müşteri Tipi: ${customerType === 'kurumsal' ? 'Kurumsal' : 'Bireysel'}`);
        $('#selectedCustomerInfo').show();
        
        // Enable task details section - DÜZELTİLDİ: önce göster, sonra enable et
        $('.task-details-section').show();
        setTimeout(function() {
            $('.task-details-section').addClass('enabled');
            $('#submitBtn').prop('disabled', false);
        }, 100);
        
        console.log('🎯 UI güncellendi:');
        console.log('  📊 Müşteri bilgisi paneli gösterildi');
        console.log('  ⚙️ Görev detayları bölümü etkinleştirildi');
        console.log('  🔘 Submit butonu etkinleştirildi');
        
        // Müşterinin poliçelerini yükle
        console.log('📋 Müşteri poliçeleri yükleniyor...');
        loadCustomerPolicies(customerId);
    }
    
    // Clear customer selection function
    window.clearCustomerSelection = function() {
        selectedCustomer = null;
        $('#selected_customer_id').val('');
        $('#selected_policy_id').val('');
        $('#selectedCustomerInfo').hide();
        $('#customer_select').val('');
        $('.task-details-section').hide().removeClass('enabled');
        $('#submitBtn').prop('disabled', true);
        $('#continueWithoutPolicy').prop('checked', false);
        $('.policy-radio').prop('checked', false);
    };
    
    function loadCustomerPolicies(customerId) {
        console.log('🔍 Müşteri poliçeleri yükleniyor - ID:', customerId);
        
        // Tüm poliçeler arasından ilgili müşterinin poliçelerini filtrele
        const customerPolicies = allPolicies.filter(policy => policy.customer_id == customerId);
        
        console.log('📋 Bulunan poliçe sayısı:', customerPolicies.length);
        displayCustomerPolicies(customerPolicies);
    }
    
    function displayCustomerPolicies(policies) {
        let html = '';
        console.log('🖼️ Poliçe listesi oluşturuluyor:', policies);
        
        if (policies.length > 0) {
            policies.forEach(function(policy, index) {
                console.log('📄 Poliçe işleniyor:', policy);
                const endDate = policy.end_date ? new Date(policy.end_date).toLocaleDateString('tr-TR') : 'Belirtilmemiş';
                const radioId = 'policy_' + policy.id;
                
                html += `<div class="policy-radio-item">
                            <input type="radio" id="${radioId}" name="policy_selection" value="${policy.id}" class="policy-radio">
                            <label for="${radioId}" class="policy-label">
                                <strong>${policy.policy_number || 'Poliçe No Belirtilmemiş'}</strong> - ${policy.policy_type || 'Tip Belirtilmemiş'}<br>
                                <small>Şirket: ${policy.insurance_company || 'Belirtilmemiş'} | Durum: ${policy.status || 'Belirtilmemiş'} | 
                                Bitiş: ${endDate}</small>
                            </label>
                         </div>`;
            });
            console.log('✅ HTML oluşturuldu, toplam poliçe:', policies.length);
        } else {
            html = '<p class="no-policies">Bu müşteriye ait aktif poliçe bulunamadı.</p>';
            console.log('ℹ️ Poliçe bulunamadı mesajı gösteriliyor');
        }
        
        $('#customerPolicies').html(html);
    }
    
    // Poliçe seçimi - radio button değişikliği
    $(document).on('change', '.policy-radio', function() {
        const selectedPolicyId = $(this).val();
        $('#selected_policy_id').val(selectedPolicyId);
        console.log('📋 Seçilen poliçe ID:', selectedPolicyId);
        
        // Remove selected class from all policy items and add to current one
        $('.policy-radio-item').removeClass('selected');
        $(this).closest('.policy-radio-item').addClass('selected');
        
        // Uncheck continue without policy
        $('#continueWithoutPolicy').prop('checked', false);
    });
    
    // Continue without policy checkbox
    $('#continueWithoutPolicy').on('change', function() {
        if ($(this).is(':checked')) {
            $('.policy-radio').prop('checked', false);
            $('.policy-radio-item').removeClass('selected');
            $('#selected_policy_id').val('');
            console.log('✅ Poliçe seçmeden devam et işaretlendi');
        }
    });
    
    // Form gönderimi kontrolü - BASIT VE ETKİLİ ÇÖZÜM
    $('#taskForm').on('submit', function(e) {
        console.log('📝 Form gönderimi başlatıldı...');
        
        // 1. Müşteri seçim kontrolü
        const selectedCustomerId = $('#selected_customer_id').val();
        if (!selectedCustomerId) {
            e.preventDefault();
            alert('Lütfen önce bir müşteri seçin.');
            $('#customer_select').focus();
            return false;
        }
        
        // 2. Task details bölümünün görünür olduğunu kontrol et
        if (!$('.task-details-section').is(':visible') || !$('.task-details-section').hasClass('enabled')) {
            e.preventDefault();
            console.log('⚠️ Task details bölümü henüz hazır değil, hazırlık yapılıyor...');
            $('.task-details-section').show().addClass('enabled');
            setTimeout(function() {
                $('#taskForm').submit();
            }, 300);
            return false;
        }
        
        // 3. Değerleri güvenli şekilde al (element görünürlüğünü kontrol etme)
        let taskTitleValue = '';
        let assignedToValue = '';
        
        // Farklı yöntemlerle değer alma
        try {
            // Method 1: jQuery
            taskTitleValue = $('#task_title').val() || '';
            assignedToValue = $('#assigned_to').val() || '';
            
            // Method 2: DOM (backup)
            if (!taskTitleValue) {
                const elem = document.getElementById('task_title');
                if (elem) taskTitleValue = elem.value || '';
            }
            
            if (!assignedToValue) {
                const elem = document.getElementById('assigned_to');
                if (elem) assignedToValue = elem.value || '';
            }
            
            // Method 3: Form serialization (final backup)
            if (!taskTitleValue || !assignedToValue) {
                const formData = new FormData(document.getElementById('taskForm'));
                if (!taskTitleValue) taskTitleValue = formData.get('task_title') || '';
                if (!assignedToValue) assignedToValue = formData.get('assigned_to') || '';
            }
            
        } catch (error) {
            console.error('❌ Değer alma hatası:', error);
        }
        
        console.log('📊 Form değerleri:');
        console.log('  Müşteri ID:', selectedCustomerId);
        console.log('  Görev başlığı:', `"${taskTitleValue}"`);
        console.log('  Atanacak kişi:', `"${assignedToValue}"`);
        console.log('  Poliçe ID:', $('#selected_policy_id').val());
        
        // 4. Validasyon
        if (!taskTitleValue || taskTitleValue.trim() === '') {
            e.preventDefault();
            alert('Lütfen görev başlığını girin.');
            
            // Element'i focus etmeye çalış
            try {
                const titleElement = document.getElementById('task_title');
                if (titleElement) {
                    titleElement.focus();
                    titleElement.style.borderColor = 'red';
                    setTimeout(() => titleElement.style.borderColor = '', 3000);
                }
            } catch (error) {
                console.error('Focus hatası:', error);
            }
            
            return false;
        }
        
        if (!assignedToValue || assignedToValue.trim() === '') {
            e.preventDefault();
            alert('Lütfen görevin atanacağı kişiyi seçin.');
            
            try {
                const assignedElement = document.getElementById('assigned_to');
                if (assignedElement) {
                    assignedElement.focus();
                }
            } catch (error) {
                console.error('Focus hatası:', error);
            }
            
            return false;
        }
        
        console.log('✅ Form validasyon başarılı, gönderiliyor...');
        return true;
    });
});
</script>

<?php
// AJAX handlers - Keep only the ones we still need
add_action('wp_ajax_search_customers_for_tasks', 'handle_search_customers_for_tasks');

function handle_search_customers_for_tasks() {
    if (!wp_verify_nonce($_POST['nonce'], 'search_customers_nonce')) {
        wp_die('Security check failed');
    }
    
    global $wpdb;
    $search_term = sanitize_text_field($_POST['search_term']);
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    
    $search_term_like = '%' . $wpdb->esc_like($search_term) . '%';
    
    $query = $wpdb->prepare("
        SELECT id, customer_type, first_name, last_name, company_name, tc_identity, tax_number
        FROM $customers_table 
        WHERE (
            CONCAT(first_name, ' ', last_name) LIKE %s OR
            company_name LIKE %s OR
            tc_identity LIKE %s OR
            tax_number LIKE %s OR
            phone LIKE %s
        )
        ORDER BY 
            CASE 
                WHEN customer_type = 'kurumsal' THEN company_name 
                ELSE CONCAT(first_name, ' ', last_name) 
            END
        LIMIT 20
    ", $search_term_like, $search_term_like, $search_term_like, $search_term_like, $search_term_like);
    
    $customers = $wpdb->get_results($query, ARRAY_A);
    
    if ($customers) {
        wp_send_json_success($customers);
    } else {
        wp_send_json_error('Müşteri bulunamadı');
    }
}
?>