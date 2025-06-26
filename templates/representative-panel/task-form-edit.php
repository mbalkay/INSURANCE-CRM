<?php
/**
 * Görev Düzenleme Formu
 * @version 1.0.0
 * @date 2025-06-26
 * @author anadolubirlik
 * @description Var olan görevleri düzenlemek için form
 */

include_once(dirname(__FILE__) . '/template-colors.php');

if (!is_user_logged_in()) {
    return;
}

// Görev ID'sini al
$task_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$task_id) {
    echo '<div class="notice notice-error"><p>Geçersiz görev ID\'si.</p></div>';
    return;
}

global $wpdb;
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';

// Yetki kontrolleri için gerekli fonksiyonlar
if (!function_exists('get_user_role_level')) {
    function get_user_role_level() {
        global $wpdb;
        $current_user_id = get_current_user_id();
        $rep = $wpdb->get_row($wpdb->prepare(
            "SELECT id, role FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
            $current_user_id
        ));
        
        if (!$rep) return 5; // Varsayılan olarak en düşük yetki
        return intval($rep->role); // 1: Patron, 2: Müdür, 3: Müdür Yard., 4: Ekip Lideri, 5: Müş. Temsilcisi
    }
}

if (!function_exists('get_team_members_ids')) {
    function get_team_members_ids($leader_user_id) {
        global $wpdb;
        $leader_rep = $wpdb->get_row($wpdb->prepare(
            "SELECT id, team_id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
            $leader_user_id
        ));
        
        if (!$leader_rep || !$leader_rep->team_id) {
            return array();
        }
        
        $team_members = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE team_id = %d AND status = 'active'",
            $leader_rep->team_id
        ));
        
        return array_map(function($member) { return $member->id; }, $team_members);
    }
}

// Görev bilgilerini al ve yetki kontrolü yap
$current_user_id = get_current_user_id();
$current_user_rep_id = get_current_user_rep_id();
$user_role_level = get_user_role_level();
$is_wp_admin_or_manager = current_user_can('administrator') || current_user_can('insurance_manager');

// Görev bilgilerini al
$task = $wpdb->get_row($wpdb->prepare(
    "SELECT t.*, c.first_name, c.last_name, c.phone, c.email, c.tc_identity
     FROM $tasks_table t
     LEFT JOIN $customers_table c ON t.customer_id = c.id
     WHERE t.id = %d",
    $task_id
));

if (!$task) {
    echo '<div class="notice notice-error"><p>Görev bulunamadı.</p></div>';
    return;
}

// Yetki kontrolü
$can_edit = false;

// Yönetici veya Insurance Manager her zaman düzenleyebilir
if ($is_wp_admin_or_manager) {
    $can_edit = true;
}
// Patron (1) ve Müdür (2) tüm görevleri düzenleyebilir
elseif ($user_role_level == 1 || $user_role_level == 2) {
    $can_edit = true;
}
// Müdür Yardımcısı (3) tüm görevleri düzenleyebilir
elseif ($user_role_level == 3) {
    $can_edit = true;
}
// Ekip Lideri (4) kendi ekibindeki üyelere atanmış görevleri düzenleyebilir
elseif ($user_role_level == 4) {
    $team_members = get_team_members_ids($current_user_id);
    if (in_array($task->representative_id, $team_members)) {
        $can_edit = true;
    }
}
// Müşteri Temsilcisi (5) sadece kendisine atanmış görevleri düzenleyebilir
elseif ($user_role_level == 5 && $current_user_rep_id == $task->representative_id) {
    $can_edit = true;
}

// Herkes kendi görevini düzenleyebilir (rol fark etmeksizin)
if (!$can_edit && $current_user_rep_id == $task->representative_id) {
    $can_edit = true;
}

if (!$can_edit) {
    echo '<div class="notice notice-error"><p>Bu görevi düzenleme yetkiniz bulunmamaktadır.</p></div>';
    return;
}

// Form verilerini işle
$message = '';
$message_type = '';

if ($_POST && isset($_POST['action']) && $_POST['action'] === 'edit_task') {
    $task_title = sanitize_text_field($_POST['task_title']);
    $customer_id = intval($_POST['customer_id']);
    $policy_id = !empty($_POST['policy_id']) ? intval($_POST['policy_id']) : null;
    $assigned_to = intval($_POST['assigned_to']);
    $description = sanitize_textarea_field($_POST['description']);
    $due_date = !empty($_POST['due_date']) ? sanitize_text_field($_POST['due_date']) : null;
    $priority = sanitize_text_field($_POST['priority']);
    $status = sanitize_text_field($_POST['status']);

    // Validation
    $errors = [];
    if (empty($task_title)) {
        $errors[] = 'Görev başlığı gereklidir.';
    }
    if (empty($customer_id)) {
        $errors[] = 'Müşteri seçimi gereklidir.';
    }
    if (empty($assigned_to)) {
        $errors[] = 'Görev atanacak kişi seçimi gereklidir.';
    }

    if (empty($errors)) {
        // Update verilerini hazırla
        $update_data = [
            'task_title' => $task_title,
            'customer_id' => $customer_id,
            'policy_id' => $policy_id,
            'representative_id' => $assigned_to,
            'task_description' => $description,
            'status' => $status,
            'priority' => $priority,
            'due_date' => $due_date,
            'updated_at' => current_time('mysql')
        ];

        error_log("Updating task ID: $task_id with data: " . print_r($update_data, true));

        $update_result = $wpdb->update(
            $tasks_table,
            $update_data,
            ['id' => $task_id],
            [
                '%s', // task_title
                '%d', // customer_id
                '%d', // policy_id
                '%d', // representative_id
                '%s', // task_description
                '%s', // status
                '%s', // priority
                '%s', // due_date
                '%s'  // updated_at
            ],
            ['%d'] // where format
        );

        if ($update_result !== false) {
            $message = 'Görev başarıyla güncellendi!';
            $message_type = 'success';
            
            // Güncellenmiş görev bilgilerini yeniden al
            $task = $wpdb->get_row($wpdb->prepare(
                "SELECT t.*, c.first_name, c.last_name, c.phone, c.email, c.tc_identity
                 FROM $tasks_table t
                 LEFT JOIN $customers_table c ON t.customer_id = c.id
                 WHERE t.id = %d",
                $task_id
            ));
        } else {
            $message = 'Görev güncellenirken bir hata oluştu: ' . $wpdb->last_error;
            $message_type = 'error';
            error_log("Task update failed: " . $wpdb->last_error);
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = 'error';
    }
}

// Müşterileri al
$customers = $wpdb->get_results("SELECT id, CONCAT(first_name, ' ', last_name) as customer_name FROM $customers_table WHERE status = 'aktif' ORDER BY first_name, last_name");

// Temsilcileri al
$representatives = $wpdb->get_results("SELECT r.id, r.title, u.display_name FROM $representatives_table r LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID WHERE r.status = 'active' ORDER BY r.title");

// Poliçeleri al (seçilen müşteriye göre)
$policies = [];
if ($task->customer_id) {
    $policies_table = $wpdb->prefix . 'insurance_crm_policies';
    $policies = $wpdb->get_results($wpdb->prepare(
        "SELECT id, policy_number, policy_type FROM $policies_table WHERE customer_id = %d ORDER BY policy_number",
        $task->customer_id
    ));
}


?>

<style>
    /* Task-form.php tarzında stil */
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
        margin-bottom: 20px;
    }

    .selected-customer h4 {
        margin: 0 0 10px 0;
        color: <?php echo $corporate_color; ?>;
    }

    .customer-info {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 10px;
        font-size: 14px;
    }

    .customer-info span {
        display: flex;
        align-items: center;
    }

    .customer-info i {
        margin-right: 8px;
        color: <?php echo $corporate_color; ?>;
        width: 16px;
    }

    .form-buttons {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #e9ecef;
    }

    .ab-btn {
        padding: 12px 24px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
    }

    .ab-btn-primary {
        background: <?php echo $corporate_color; ?>;
        color: white;
    }

    .ab-btn-primary:hover {
        background: <?php echo adjust_color_opacity($corporate_color, 0.8); ?>;
        transform: translateY(-1px);
    }

    .ab-btn-secondary {
        background: #6c757d;
        color: white;
    }

    .ab-btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-1px);
    }

    .notice {
        padding: 12px 15px;
        margin: 20px 0;
        border-radius: 6px;
        font-weight: 500;
    }

    .notice-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .notice-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .priority-preview, .status-preview {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        text-align: center;
        margin-left: 10px;
    }

    .priority-low { background: #d4edda; color: #155724; }
    .priority-medium { background: #fff3cd; color: #856404; }
    .priority-high { background: #f8d7da; color: #721c24; }

    .status-pending { background: #cce5ff; color: #0066cc; }
    .status-in_progress { background: #fff3cd; color: #856404; }
    .status-completed { background: #d4edda; color: #155724; }
    .status-cancelled { background: #f8d7da; color: #721c24; }

    .ab-readonly-field {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 6px;
        padding: 12px 15px;
        color: #495057;
        font-size: 14px;
        line-height: 1.4;
        min-height: 20px;
    }
</style>

<div class="ab-task-form-container">
    <div class="ab-form-header">
        <h2><i class="fas fa-edit"></i> Görev Düzenle</h2>
        <a href="?view=tasks" class="ab-btn ab-btn-secondary">
            <i class="fas fa-arrow-left"></i> Görevlere Dön
        </a>
    </div>

    <?php if ($message): ?>
        <div class="notice notice-<?php echo $message_type; ?>">
            <p><?php echo $message; ?></p>
        </div>
    <?php endif; ?>

    <form method="post" class="ab-task-form">
        <input type="hidden" name="action" value="edit_task">
        
        <div class="ab-form-section">
            <h3><i class="fas fa-info-circle"></i> Görev Bilgileri</h3>
            
            <div class="ab-form-row">
                <div class="ab-form-group">
                    <label for="task_title" class="required">Görev Başlığı</label>
                    <input type="text" id="task_title" name="task_title" class="ab-input" 
                           value="<?php echo esc_attr($task->task_title); ?>" required>
                </div>
                
                <div class="ab-form-group">
                    <label for="priority">Öncelik</label>
                    <select id="priority" name="priority" class="ab-select">
                        <option value="low" <?php selected($task->priority, 'low'); ?>>Düşük</option>
                        <option value="medium" <?php selected($task->priority, 'medium'); ?>>Orta</option>
                        <option value="high" <?php selected($task->priority, 'high'); ?>>Yüksek</option>
                    </select>
                    <span class="priority-preview"></span>
                </div>
            </div>

            <div class="ab-form-row">
                <div class="ab-form-group">
                    <label for="status">Durum</label>
                    <select id="status" name="status" class="ab-select">
                        <option value="pending" <?php selected($task->status, 'pending'); ?>>Beklemede</option>
                        <option value="in_progress" <?php selected($task->status, 'in_progress'); ?>>İşlemde</option>
                        <option value="completed" <?php selected($task->status, 'completed'); ?>>Tamamlandı</option>
                        <option value="cancelled" <?php selected($task->status, 'cancelled'); ?>>İptal Edildi</option>
                    </select>
                    <span class="status-preview"></span>
                </div>
                
                <div class="ab-form-group">
                    <label for="due_date">Son Tarih</label>
                    <input type="datetime-local" id="due_date" name="due_date" class="ab-input" 
                           value="<?php echo $task->due_date ? date('Y-m-d\TH:i', strtotime($task->due_date)) : ''; ?>">
                </div>
            </div>
            
            <div class="ab-form-row">
                <div class="ab-form-group full-width">
                    <label for="description">Görev Açıklaması</label>
                    <textarea id="description" name="description" class="ab-textarea" rows="4"><?php echo esc_textarea($task->task_description); ?></textarea>
                </div>
            </div>
        </div>

        <div class="ab-form-section">
            <h3><i class="fas fa-user"></i> Müşteri Bilgileri</h3>
            
            <?php if ($task->customer_id): ?>
                <div class="selected-customer">
                    <h4><i class="fas fa-user-check"></i> Seçili Müşteri</h4>
                    <div class="customer-info">
                        <span><i class="fas fa-user"></i> <?php echo esc_html($task->first_name . ' ' . $task->last_name); ?></span>
                        <?php if ($task->phone): ?>
                            <span><i class="fas fa-phone"></i> <?php echo esc_html($task->phone); ?></span>
                        <?php endif; ?>
                        <?php if ($task->email): ?>
                            <span><i class="fas fa-envelope"></i> <?php echo esc_html($task->email); ?></span>
                        <?php endif; ?>
                        <?php if ($task->tc_identity): ?>
                            <span><i class="fas fa-id-card"></i> <?php echo esc_html($task->tc_identity); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="ab-form-row">
                <div class="ab-form-group">
                    <label for="customer_id" class="required">Müşteri Seçimi</label>
                    <select id="customer_id" name="customer_id" class="ab-select" required>
                        <option value="">Müşteri seçiniz...</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer->id; ?>" <?php selected($task->customer_id, $customer->id); ?>>
                                <?php echo esc_html($customer->customer_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-form-group">
                    <label for="policy_id">Poliçe (Opsiyonel)</label>
                    <select id="policy_id" name="policy_id" class="ab-select">
                        <option value="">Poliçe seçiniz...</option>
                        <?php foreach ($policies as $policy): ?>
                            <option value="<?php echo $policy->id; ?>" <?php selected($task->policy_id, $policy->id); ?>>
                                <?php echo esc_html($policy->policy_number . ' - ' . $policy->policy_type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <?php if ($user_role_level <= 4): // Patron, Müdür, Müdür Yrd., Ekip Lideri ?>
        <div class="ab-form-section">
            <h3><i class="fas fa-user-tie"></i> Atama Bilgileri</h3>
            
            <div class="ab-form-row">
                <div class="ab-form-group">
                    <label for="assigned_to" class="required">Atanacak Kişi</label>
                    <select id="assigned_to" name="assigned_to" class="ab-select" required>
                        <option value="">Temsilci seçiniz...</option>
                        <?php 
                        foreach ($representatives as $rep): 
                            // Ekip lideri için sadece kendi ekibini göster
                            if ($user_role_level == 4) {
                                $team_members = get_team_members_ids($current_user_id);
                                if (!in_array($rep->id, $team_members)) {
                                    continue;
                                }
                            }
                        ?>
                            <option value="<?php echo $rep->id; ?>" <?php selected($task->representative_id, $rep->id); ?>>
                                <?php echo esc_html($rep->title . ' - ' . $rep->display_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <?php else: // Müşteri Temsilcisi - sadece mevcut atama gösterilir, değiştirilemez ?>
        <div class="ab-form-section">
            <h3><i class="fas fa-user-tie"></i> Atama Bilgileri</h3>
            
            <div class="ab-form-row">
                <div class="ab-form-group">
                    <label>Atanmış Kişi</label>
                    <div class="ab-readonly-field">
                        <?php 
                        foreach ($representatives as $rep): 
                            if ($rep->id == $task->representative_id):
                                echo esc_html($rep->title . ' - ' . $rep->display_name);
                                break;
                            endif;
                        endforeach;
                        ?>
                    </div>
                    <!-- Hidden field to maintain the original assignment -->
                    <input type="hidden" id="assigned_to" name="assigned_to" value="<?php echo esc_attr($task->representative_id); ?>">
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-buttons">
            <a href="?view=tasks" class="ab-btn ab-btn-secondary">
                <i class="fas fa-times"></i> İptal
            </a>
            <button type="submit" class="ab-btn ab-btn-primary">
                <i class="fas fa-save"></i> Güncelle
            </button>
        </div>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Öncelik önizleme
    function updatePriorityPreview() {
        var priority = $('#priority').val();
        var preview = $('.priority-preview');
        var text = '';
        var className = '';
        
        switch(priority) {
            case 'low':
                text = 'Düşük';
                className = 'priority-low';
                break;
            case 'medium':
                text = 'Orta';
                className = 'priority-medium';
                break;
            case 'high':
                text = 'Yüksek';
                className = 'priority-high';
                break;
        }
        
        preview.removeClass('priority-low priority-medium priority-high')
            .addClass(className)
            .text(text);
    }
    
    // Durum önizleme
    function updateStatusPreview() {
        var status = $('#status').val();
        var preview = $('.status-preview');
        var text = '';
        var className = '';
        
        switch(status) {
            case 'pending':
                text = 'Beklemede';
                className = 'status-pending';
                break;
            case 'in_progress':
                text = 'İşlemde';
                className = 'status-in_progress';
                break;
            case 'completed':
                text = 'Tamamlandı';
                className = 'status-completed';
                break;
            case 'cancelled':
                text = 'İptal Edildi';
                className = 'status-cancelled';
                break;
        }
        
        preview.removeClass('status-pending status-in_progress status-completed status-cancelled')
            .addClass(className)
            .text(text);
    }
    
    // Sayfa yüklendiğinde önizlemeleri başlat
    updatePriorityPreview();
    updateStatusPreview();
    
    // Değişiklikler olduğunda önizlemeleri güncelle
    $('#priority').change(updatePriorityPreview);
    $('#status').change(updateStatusPreview);
    
    // Müşteri değiştiğinde poliçeleri güncelle
    $('#customer_id').change(function() {
        var customer_id = $(this).val();
        var policy_select = $('#policy_id');
        
        policy_select.html('<option value="">Yükleniyor...</option>');
        
        if (customer_id) {
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'get_customer_policies',
                    customer_id: customer_id
                },
                success: function(response) {
                    if (response.success) {
                        var options = '<option value="">Poliçe seçiniz...</option>';
                        response.data.forEach(function(policy) {
                            options += '<option value="' + policy.id + '">' + policy.policy_number + ' - ' + policy.policy_type + '</option>';
                        });
                        policy_select.html(options);
                    } else {
                        policy_select.html('<option value="">Poliçe bulunamadı</option>');
                    }
                },
                error: function() {
                    policy_select.html('<option value="">Hata oluştu</option>');
                }
            });
        } else {
            policy_select.html('<option value="">Önce müşteri seçiniz</option>');
        }
    });
    
    // Form gönderimi - doğrulama uyarısı kaldırıldı
    $('.ab-task-form').on('submit', function(e) {
        // Form doğrudan gönderilir, client-side validation kaldırıldı
        return true;
    });
});
</script>