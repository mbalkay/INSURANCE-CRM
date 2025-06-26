<?php
/**
 * Müşteri Detay Sayfası
 * @version 3.10.1
 * @description Görüşme notu silme JavaScript nonce hatası düzeltildi
 */

// Yetki kontrolü
if (!is_user_logged_in() || !isset($_GET['id'])) {
    return;
}

// Hatırlatma görevi oluşturma işlemi
if (isset($_POST['action']) && $_POST['action'] === 'create_reminder_task' && isset($_POST['customer_id'])) {
    $customer_id = intval($_POST['customer_id']);
    
    // Müşteri bilgilerini al
    $customer_data = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$customers_table} WHERE id = %d",
        $customer_id
    ));
    
    if ($customer_data && $customer_data->has_offer == 1) {
        // Teklif verilerini hazırla
        $offer_data = array(
            'offer_reminder' => 1, // Hatırlatıcı aktif
            'offer_expiry_date' => $customer_data->offer_expiry_date,
            'offer_insurance_type' => $customer_data->offer_insurance_type,
            'offer_amount' => $customer_data->offer_amount
        );
        
        // create_offer_reminder_task fonksiyonunu include et
        if (file_exists(dirname(__FILE__) . '/customers-form.php')) {
            require_once(dirname(__FILE__) . '/customers-form.php');
        }
        
        // Eğer fonksiyon hala tanımlı değilse, burada tanımla
        if (!function_exists('create_offer_reminder_task')) {
            function create_offer_reminder_task($customer_id, $offer_data) {
                global $wpdb;
                
                // Debug log
                error_log("create_offer_reminder_task called for customer $customer_id with data: " . print_r($offer_data, true));
                
                // Teklif hatırlatması aktif değilse veya vade tarihi yoksa çık
                if (empty($offer_data['offer_reminder']) || empty($offer_data['offer_expiry_date'])) {
                    error_log("create_offer_reminder_task: Early exit - offer_reminder: " . ($offer_data['offer_reminder'] ?? 'empty') . ", offer_expiry_date: " . ($offer_data['offer_expiry_date'] ?? 'empty'));
                    return;
                }
                
                $tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
                $customers_table = $wpdb->prefix . 'insurance_crm_customers';
                
                // Önce tasks tablosunun yapısını kontrol et
                $columns = $wpdb->get_results("DESCRIBE {$tasks_table}");
                $column_names = array();
                foreach ($columns as $col) {
                    $column_names[] = $col->Field;
                }
                error_log("Tasks table columns: " . implode(', ', $column_names));
                
                // Müşteri bilgilerini al (temsilci ID'si dahil)
                $customer = $wpdb->get_row($wpdb->prepare(
                    "SELECT first_name, last_name, phone, representative_id FROM {$customers_table} WHERE id = %d",
                    $customer_id
                ));
                
                if (!$customer) {
                    error_log("create_offer_reminder_task: Customer not found for ID $customer_id");
                    return;
                }
                
                error_log("Customer found: " . $customer->first_name . " " . $customer->last_name . ", rep_id: " . $customer->representative_id);
                
                // Hatırlatma tarihini hesapla (vade tarihinden 1 gün önce saat 10:00)
                $expiry_date = date('Y-m-d', strtotime($offer_data['offer_expiry_date']));
                $reminder_date = date('Y-m-d', strtotime($expiry_date . ' -1 day'));
                $reminder_datetime = $reminder_date . ' 10:00:00';
                
                // Görev başlığı ve açıklaması
                $task_title = "Teklif hatırlatma görevi";
                $task_description = "Teklif hatırlatma görevi\n\n";
                $task_description .= "Müşteri Adı Soyadı: " . $customer->first_name . " " . $customer->last_name . "\n";
                $task_description .= "Telefon: " . $customer->phone . "\n";
                $task_description .= "Teklif İçeriği: " . ($offer_data['offer_insurance_type'] ?? 'TSS') . " - " . ($offer_data['offer_amount'] ? number_format($offer_data['offer_amount'], 2, ',', '.') . ' TL' : '20,000.00 TL') . "\n";
                $task_description .= "Teklif Vade Tarihi: " . date('d.m.Y', strtotime($expiry_date)) . "\n\n";
                $task_description .= "Müşteriyi arayarak teklif durumunu kontrol ediniz.";
                
                // Temsilci atama mantığı: 
                // 1. Müşteriye zaten bir temsilci atanmışsa o temsilciyi kullan
                // 2. Yoksa işlemi yapan kullanıcıyı ata
                $assigned_representative_id = $customer->representative_id;
                if (!$assigned_representative_id) {
                    $assigned_representative_id = get_current_user_rep_id();
                }
                
                error_log("Assigned representative ID: $assigned_representative_id");
                
                // Sütun adlarına göre task_data'yı dinamik olarak oluştur
                $task_data = array(
                    'customer_id' => $customer_id,
                    'representative_id' => $assigned_representative_id,
                    'priority' => 'medium',
                    'status' => 'pending', 
                    'due_date' => $reminder_datetime
                );
                
                // task_title sütunu varsa ekle
                if (in_array('task_title', $column_names)) {
                    $task_data['task_title'] = $task_title;
                }
                
                // task_description sütunu varsa ekle
                if (in_array('task_description', $column_names)) {
                    $task_data['task_description'] = $task_description;
                }
                
                // title sütunu varsa ekle (eski sistem için)
                if (in_array('title', $column_names)) {
                    $task_data['title'] = $task_title;
                }
                
                // description sütunu varsa ekle (eski sistem için)
                if (in_array('description', $column_names)) {
                    $task_data['description'] = $task_description;
                }
                
                // task_type sütunu varsa ekle
                if (in_array('task_type', $column_names)) {
                    $task_data['task_type'] = 'teklif_hatirlatma';
                }
                
                // created_at sütunu varsa ekle
                if (in_array('created_at', $column_names)) {
                    $task_data['created_at'] = current_time('mysql');
                }
                
                // updated_at sütunu varsa ekle
                if (in_array('updated_at', $column_names)) {
                    $task_data['updated_at'] = current_time('mysql');
                }
                
                error_log("Task data prepared: " . print_r($task_data, true));
                
                // Önceki bekleyen teklif hatırlatma görevlerini kontrol et ve sil
                $delete_conditions = array(
                    'customer_id' => $customer_id,
                    'status' => 'pending'
                );
                
                // task_type sütunu varsa koşula ekle
                if (in_array('task_type', $column_names)) {
                    $delete_conditions['task_type'] = 'teklif_hatirlatma';
                }
                
                $deleted = $wpdb->delete($tasks_table, $delete_conditions);
                error_log("Deleted previous tasks: $deleted");
                
                // Yeni görevi ekle
                $result = $wpdb->insert($tasks_table, $task_data);
                
                if ($result) {
                    error_log("SUCCESS: Offer reminder task created for customer {$customer_id}, assigned to representative {$assigned_representative_id}, due date: {$reminder_datetime}");
                } else {
                    error_log("FAILED to create offer reminder task for customer {$customer_id}: " . $wpdb->last_error);
                    error_log("Last query: " . $wpdb->last_query);
                }
            }
        }
        
        // Hatırlatma görevi oluştur
        create_offer_reminder_task($customer_id, $offer_data);
        
        // Başarı mesajı
        $_SESSION['crm_notice'] = '<div class="ab-notice ab-success">Hatırlatma görevi başarıyla oluşturuldu.</div>';
    } else {
        $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">Hatırlatma görevi oluşturulamadı. Müşterinin aktif teklifi bulunmuyor.</div>';
    }
    
    // Sayfayı yenile
    echo '<script>window.location.href = "?view=customers&action=view&id=' . $customer_id . '";</script>';
    exit;
}

// Teklif durumu güncelleme işlemi (txt dosyasından)
if (isset($_POST['action']) && $_POST['action'] === 'update_quote_status' && isset($_POST['customer_id'])) {
    if (!wp_verify_nonce($_POST['quote_nonce'], 'update_customer_quote')) {
        wp_die('Security check failed');
    }
    
    $customer_id = intval($_POST['customer_id']);
    
    // Debug logging
    error_log("Quote update - POST customer_id: " . $_POST['customer_id']);
    error_log("Quote update - Processed customer_id: " . $customer_id);
    $quote_data = array(
        'has_offer' => 1,
        'offer_insurance_type' => sanitize_text_field($_POST['offer_insurance_type']),
        'offer_amount' => floatval($_POST['offer_amount']),
        'offer_expiry_date' => sanitize_text_field($_POST['offer_expiry_date']),
        'offer_reminder' => intval($_POST['offer_reminder']),
        'offer_notes' => sanitize_textarea_field($_POST['offer_notes'])
    );
    
    $result = $wpdb->update($customers_table, $quote_data, array('id' => $customer_id));
    
    if ($result !== false) {
        // Create reminder task if requested
        if ($quote_data['offer_reminder'] == 1 && !empty($quote_data['offer_expiry_date'])) {
            if (!function_exists('create_offer_reminder_task')) {
                require_once(dirname(__FILE__) . '/customers-form.php');
            }
            create_offer_reminder_task($customer_id, $quote_data);
        }
        
        $_SESSION['crm_notice'] = '<div class="ab-notice ab-success">Teklif bilgileri başarıyla güncellendi.</div>';
    } else {
        $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">Teklif bilgileri güncellenirken hata oluştu.</div>';
    }
    
    // Sayfayı yenile
    echo '<script>window.location.href = "?view=customers&action=view&id=' . $customer_id . '";</script>';
    exit;
}

$customer_id = intval($_GET['id']);
global $wpdb;
$customers_table = $wpdb->prefix . 'insurance_crm_customers';

// Temsilci yetkisi kontrolü - Fonksiyon zaten ana dosyada tanımlı

$current_user_rep_id = get_current_user_rep_id();

// Kullanıcının rolünü belirle - poliçe tabanlı görünürlük için
$user_role = 'representative'; // Varsayılan
if (current_user_can('administrator') || current_user_can('insurance_manager')) {
    $user_role = 'patron';
} elseif (function_exists('get_user_role_in_hierarchy')) {
    $user_role = get_user_role_in_hierarchy(get_current_user_id());
    if (empty($user_role)) {
        $user_role = 'representative';
    }
}

// Backward compatibility function
if (!function_exists('get_user_access_level')) {
    function get_user_access_level($user_role) {
        switch ($user_role) {
            case 'patron':
                return 'patron';
            case 'manager':
                return 'mudur';
            case 'assistant_manager':
                return 'mudur_yardimcisi';
            case 'team_leader':
                return 'ekip_lideri';
            case 'representative':
            default:
                return 'temsilci';
        }
    }
}

// Access level belirle
$access_level = get_user_access_level($user_role);

// Ekip bilgilerini al (ekip lideri için)
$team_members = array();
if ($access_level == 'ekip_lideri' && function_exists('get_team_members')) {
    $team_members = get_team_members(get_current_user_id());
}

$where_clause = "";
$where_params = array($customer_id);

// Poliçe tabanlı görünürlük kontrolü - But first check if access to all customer details is allowed
$settings = get_option('insurance_crm_settings', array());
$allow_customer_details_access = isset($settings['permission_settings']['allow_customer_details_access']) && $settings['permission_settings']['allow_customer_details_access'];

if (!current_user_can('administrator') && !current_user_can('insurance_manager') && $current_user_rep_id && !$allow_customer_details_access) {
    // Fonksiyonun yüklendiğinden emin ol
    if (!function_exists('build_policy_based_customer_visibility')) {
        require_once(dirname(__FILE__) . '/../../includes/functions.php');
    }
    $visibility_config = build_policy_based_customer_visibility($access_level, $current_user_rep_id, $team_members);
    
    if (!empty($visibility_config['where_clause'])) {
        $where_clause = $visibility_config['where_clause'];
    }
}

// Müşteri bilgilerini al
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$base_query = "
    SELECT c.*,
           r.id AS rep_id,
           u.display_name AS rep_name,
           fr.id AS first_registrar_id,
           fu.display_name AS first_registrar_name,
           CASE 
               WHEN c.representative_id != " . intval($current_user_rep_id) . " 
                    AND EXISTS (
                        SELECT 1 FROM $policies_table p 
                        WHERE p.customer_id = c.id 
                        AND p.representative_id = " . intval($current_user_rep_id) . "
                    ) THEN 1
               ELSE 0
           END as is_policy_customer
    FROM $customers_table c
    LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON c.representative_id = r.id
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    LEFT JOIN {$wpdb->prefix}insurance_crm_representatives fr ON c.ilk_kayit_eden = fr.id
    LEFT JOIN {$wpdb->users} fu ON fr.user_id = fu.ID
    WHERE c.id = %d" . $where_clause;

$customer = $wpdb->get_row($wpdb->prepare($base_query, $where_params));

if (!$customer) {
    // Check if this is a permission issue due to admin settings
    $settings = get_option('insurance_crm_settings', array());
    $allow_customer_details_access = isset($settings['permission_settings']['allow_customer_details_access']) && $settings['permission_settings']['allow_customer_details_access'];
    
    if (!current_user_can('administrator') && !current_user_can('insurance_manager') && !$allow_customer_details_access) {
        // Show permission-specific error message with an information box
        echo '
        <div class="customer-permission-error-container">
            <div class="customer-permission-error-box">
                <div class="error-icon">
                    <i class="dashicons dashicons-info"></i>
                </div>
                <h3>Erişim Kısıtlı</h3>
                <p>Müşteri detaylarını görme aktif edilmemiş. Lütfen yöneticinizle görüşün.</p>
                <div class="error-actions">
                    <a href="?view=dashboard" class="btn-return">Dashboard\'a Dön</a>
                </div>
            </div>
        </div>
        <style>
        .customer-permission-error-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 400px;
            padding: 40px 20px;
        }
        .customer-permission-error-box {
            background: #ffffff;
            border: 1px solid #e3f2fd;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            max-width: 500px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .customer-permission-error-box .error-icon {
            margin-bottom: 20px;
        }
        .customer-permission-error-box .error-icon i {
            font-size: 48px;
            color: #2196f3;
        }
        .customer-permission-error-box h3 {
            color: #1565c0;
            font-size: 24px;
            margin: 0 0 15px 0;
            font-weight: 600;
        }
        .customer-permission-error-box p {
            color: #666;
            font-size: 16px;
            line-height: 1.5;
            margin: 0 0 25px 0;
        }
        .btn-return {
            display: inline-block;
            background-color: #2196f3;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            transition: background-color 0.3s ease;
        }
        .btn-return:hover {
            background-color: #1976d2;
            color: white;
            text-decoration: none;
        }
        </style>';
    } else {
        echo '<div class="ab-notice ab-error">Müşteri bulunamadı veya görüntüleme yetkiniz yok.</div>';
    }
    return;
}

// Müşterinin poliçelerini al
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$policies = $wpdb->get_results($wpdb->prepare("
    SELECT p.*, u.display_name as representative_name
    FROM $policies_table p
    LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON p.representative_id = r.id
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    WHERE p.customer_id = %d
    ORDER BY p.end_date ASC
", $customer_id));

// Müşterinin görevlerini al
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
$tasks = $wpdb->get_results($wpdb->prepare("
    SELECT * FROM $tasks_table 
    WHERE customer_id = %d
    ORDER BY due_date ASC
", $customer_id));

// Müşteri dosyalarını al
$files_table = $wpdb->prefix . 'insurance_crm_customer_files';
$files = $wpdb->get_results($wpdb->prepare("
    SELECT * FROM $files_table
    WHERE customer_id = %d
    ORDER BY upload_date DESC
", $customer_id));

// Admin panelinden izin verilen dosya türlerini al
$settings = get_option('insurance_crm_settings', array());
$allowed_file_types = !empty($settings['file_upload_settings']['allowed_file_types']) 
    ? $settings['file_upload_settings']['allowed_file_types'] 
    : array('jpg', 'jpeg', 'pdf', 'docx'); // Varsayılan türler

// Dosya türleri için MIME tiplerini tanımla
$file_type_mime_mapping = array(
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'txt' => 'text/plain',
    'zip' => 'application/zip'
);

// İzin verilen MIME tiplerini oluştur
$allowed_mime_types = array();
foreach ($allowed_file_types as $type) {
    if (isset($file_type_mime_mapping[$type])) {
        $allowed_mime_types[] = $file_type_mime_mapping[$type];
    }
}

// Modal için desteklenen formatlar metnini oluştur
$supported_formats_text = implode(', ', array_map('strtoupper', $allowed_file_types));

// Dosya Yükleme için accept özelliğini oluştur
$accept_attribute = '.' . implode(',.', $allowed_file_types);

// AJAX Dosya Yükleme İşlemi
if (isset($_POST['ajax_upload_files']) && wp_verify_nonce($_POST['file_upload_nonce'], 'file_upload_action')) {
    $response = array('success' => false, 'message' => '', 'files' => array());
    
    if (handle_customer_file_uploads($customer_id)) {
        $response['success'] = true;
        $response['message'] = 'Dosyalar başarıyla yüklendi.';
        
        // Yeni dosya listesini al
        $new_files = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $files_table
            WHERE customer_id = %d
            ORDER BY upload_date DESC
        ", $customer_id));
        
        // Dosya bilgilerini ekle
        foreach ($new_files as $file) {
            $response['files'][] = array(
                'id' => $file->id,
                'name' => $file->file_name,
                'type' => $file->file_type,
                'path' => $file->file_path,
                'size' => format_file_size($file->file_size),
                'date' => date('d.m.Y H:i', strtotime($file->upload_date)),
                'description' => $file->description
            );
        }
    } else {
        $response['message'] = 'Dosya yüklenirken bir hata oluştu.';
    }
    
    echo json_encode($response);
    exit;
}

// AJAX Dosya Silme İşlemi
if (isset($_POST['ajax_delete_file']) && wp_verify_nonce($_POST['file_delete_nonce'], 'file_delete_action')) {
    $response = array('success' => false, 'message' => '');
    $file_id = intval($_POST['file_id']);
    
    if (delete_customer_file($file_id, $customer_id)) {
        $response['success'] = true;
        $response['message'] = 'Dosya başarıyla silindi.';
    } else {
        $response['message'] = 'Dosya silinirken bir hata oluştu.';
    }
    
    echo json_encode($response);
    exit;
}

// Normal Teklif Güncelleme İşlemi (AJAX yerine)
if (isset($_POST['action']) && $_POST['action'] === 'update_offer' && isset($_POST['offer_nonce']) && wp_verify_nonce($_POST['offer_nonce'], 'update_customer_offer')) {
    $customer_id = intval($_POST['customer_id']);
    
    // Müşteri bilgilerini al (hem hatırlatma görev açıklaması hem de yetki kontrolü için)
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $customers_table WHERE id = %d", 
        $customer_id
    ));
    
    // Yetki kontrolü - sadece müşterinin atanmış temsilcisi veya patron/müdür teklif düzenleyebilir
    if (!can_edit_customer_view($customer)) {
        $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">Bu müşterinin teklif bilgilerini düzenleme yetkiniz bulunmamaktadır.</div>';
        echo '<script>window.location.href = "?view=customers&action=view&id=' . $customer_id . '";</script>';
        exit;
    }
    
    // Verileri sanitize et
    $offer_data = array(
        'has_offer' => 1,
        'offer_insurance_type' => sanitize_text_field($_POST['offer_insurance_type']),
        'offer_amount' => floatval($_POST['offer_amount']),
        'offer_expiry_date' => sanitize_text_field($_POST['offer_expiry_date']),
        'offer_reminder' => intval($_POST['offer_reminder']),
        'offer_notes' => sanitize_textarea_field($_POST['offer_notes'])
    );
    
    // Müşteri verisini güncelle
    $update_result = $wpdb->update(
        $customers_table,
        $offer_data,
        array('id' => $customer_id),
        array('%d', '%s', '%f', '%s', '%d', '%s'),
        array('%d')
    );
    
    if ($update_result !== false) {
        $message = 'Teklif bilgileri başarıyla kaydedildi.';
        $message_type = 'success';
        
        // Hatırlatma görevi oluştur
        if (!empty($offer_data['offer_reminder']) && !empty($offer_data['offer_expiry_date'])) {
            // Hatırlatma görevi oluştur - işlevsellik eklendi
            $task_title = 'Teklif Takip Hatırlatması';
            $task_description = 'Müşteri: ' . $customer->first_name . ' ' . $customer->last_name . ' için teklif son tarihi: ' . $offer_data['offer_expiry_date'];
            
            $tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
            $reminder_result = $wpdb->insert(
                $tasks_table,
                array(
                    'task_title' => $task_title,
                    'customer_id' => $customer_id,
                    'assigned_to' => get_current_user_id(),
                    'description' => $task_description,
                    'status' => 'beklemede',
                    'priority' => 'high',
                    'due_date' => $offer_data['offer_expiry_date'],
                    'created_by' => get_current_user_id()
                ),
                array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d')
            );
            
            if ($reminder_result) {
                error_log("Teklif hatırlatma görevi başarıyla oluşturuldu. Görev ID: " . $wpdb->insert_id);
            } else {
                error_log("Teklif hatırlatma görevi oluşturulamadı. Hata: " . $wpdb->last_error);
            }
        }
    } else {
        $message = 'Teklif bilgileri kaydedilirken bir hata oluştu.';
        $message_type = 'error';
    }
    
    // Başarı mesajını session'a kaydet ve sayfayı yenile
    $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
    echo '<script>window.location.href = "?view=customers&action=view&id=' . $customer_id . '&offer_updated=1";</script>';
    exit;
}

// Müşteri notları güncelleme işlemi (tek not sistemi)
if (isset($_POST['save_customer_notes']) && isset($_POST['customer_notes_nonce']) && wp_verify_nonce($_POST['customer_notes_nonce'], 'save_customer_notes')) {
    $customer_id = intval($_POST['customer_id']);
    $customer_notes = sanitize_textarea_field($_POST['customer_notes_content']);
    
    // Önce müşteri verilerini al
    $temp_customer = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $customers_table WHERE id = %d",
        $customer_id
    ));
    
    // Yetki kontrolü
    if (!can_edit_customer_notes($temp_customer)) {
        $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">Bu müşterinin notlarını düzenleme yetkiniz bulunmamaktadır.</div>';
        echo '<script>window.location.href = "?view=customers&action=view&id=' . $customer_id . '";</script>';
        exit;
    }
    
    $update_result = $wpdb->update(
        $customers_table,
        array(
            'customer_notes' => $customer_notes,
            'customer_notes_updated_at' => current_time('mysql')
        ),
        array('id' => $customer_id),
        array('%s', '%s'),
        array('%d')
    );
    
    if ($update_result !== false) {
        $success_message = 'Müşteri notu başarıyla güncellendi.';
    } else {
        $error_message = 'Müşteri notu güncellenirken bir hata oluştu.';
    }
    
    // Sayfayı yenile
    echo '<script>window.location.href = "?view=customers&action=view&id=' . $customer_id . '&customer_note_updated=1";</script>';
    exit;
}

// Not ekleme işlemi
if (isset($_POST['add_note']) && isset($_POST['note_nonce']) && wp_verify_nonce($_POST['note_nonce'], 'add_customer_note')) {
    $note_data = array(
        'customer_id' => $customer_id,
        'note_content' => sanitize_textarea_field($_POST['note_content']),
        'note_type' => sanitize_text_field($_POST['note_type']),
        'created_by' => get_current_user_id(),
        'created_at' => current_time('mysql')
    );
    
    if ($note_data['note_type'] === 'negative' && !empty($_POST['rejection_reason'])) {
        $note_data['rejection_reason'] = sanitize_text_field($_POST['rejection_reason']);
        
        // Müşteri durumunu Pasif olarak güncelle
        $wpdb->update(
            $customers_table,
            array('status' => 'pasif'),
            array('id' => $customer_id)
        );
    }
    // Olumlu not ise müşteriyi aktif yap
    elseif ($note_data['note_type'] === 'positive') {
        $wpdb->update(
            $customers_table,
            array('status' => 'aktif'),
            array('id' => $customer_id)
        );
    }
    
    $notes_table = $wpdb->prefix . 'insurance_crm_customer_notes';
    $wpdb->insert($notes_table, $note_data);
    
    // Sayfayı yenile
    echo '<script>window.location.href = "?view=customers&action=view&id=' . $customer_id . '&note_added=1";</script>';
}

// Görüşme notu silme işlemi
if (isset($_POST['action']) && $_POST['action'] === 'delete_conversation_note' && isset($_POST['delete_note_nonce']) && wp_verify_nonce($_POST['delete_note_nonce'], 'delete_conversation_note')) {
    $note_id = intval($_POST['note_id']);
    
    // Silme yetkisi kontrolü - sadece Patron (1) ve Müdür (2) silebilir
    $current_user_id = get_current_user_id();
    $can_delete_note = false;
    
    // Admin herzaman silebilir
    if (current_user_can('administrator')) {
        $can_delete_note = true;
    } else {
        // Kullanıcının rol bilgisini al
        $rep_data = $wpdb->get_row($wpdb->prepare(
            "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives 
             WHERE user_id = %d AND status = 'active'",
            $current_user_id
        ));
        
        if ($rep_data && ($rep_data->role == 1 || $rep_data->role == 2)) { // Patron veya Müdür
            $can_delete_note = true;
        }
    }
    
    if ($can_delete_note) {
        $notes_table = $wpdb->prefix . 'insurance_crm_customer_notes';
        $delete_result = $wpdb->delete(
            $notes_table,
            array('id' => $note_id, 'customer_id' => $customer_id),
            array('%d', '%d')
        );
        
        if ($delete_result !== false) {
            $message = 'Görüşme notu başarıyla silindi.';
            $message_type = 'success';
        } else {
            $message = 'Görüşme notu silinirken bir hata oluştu.';
            $message_type = 'error';
        }
    } else {
        $message = 'Görüşme notu silme yetkiniz bulunmamaktadır.';
        $message_type = 'error';
    }
    
    // Sayfayı yenile
    $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
    echo '<script>window.location.href = "?view=customers&action=view&id=' . $customer_id . '&note_deleted=1";</script>';
    exit;
}

// Teklif sonlandırma işlemi
if (isset($_POST['action']) && $_POST['action'] === 'terminate_offer' && isset($_POST['terminate_nonce']) && wp_verify_nonce($_POST['terminate_nonce'], 'terminate_offer')) {
    $customer_id = intval($_POST['customer_id']);
    $terminate_reason = sanitize_textarea_field($_POST['terminate_reason']);
    
    // Teklifi temizle ve müşteriyi pasif yap
    $update_data = array(
        'has_offer' => 0,
        'offer_insurance_type' => '',
        'offer_amount' => 0,
        'offer_expiry_date' => null,
        'offer_reminder' => 0,
        'offer_notes' => '',
        'status' => 'pasif'
    );
    
    $update_result = $wpdb->update(
        $customers_table,
        $update_data,
        array('id' => $customer_id),
        array('%d', '%s', '%f', '%s', '%d', '%s', '%s'),
        array('%d')
    );
    
    if ($update_result !== false) {
        // Sonlandırma notunu ekle
        $notes_table = $wpdb->prefix . 'insurance_crm_customer_notes';
        $note_data = array(
            'customer_id' => $customer_id,
            'note_content' => 'Teklif sonlandırıldı. Sebep: ' . $terminate_reason,
            'note_type' => 'negative',
            'rejection_reason' => 'other',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql')
        );
        $wpdb->insert($notes_table, $note_data);
        
        $message = 'Teklif başarıyla sonlandırıldı ve müşteri pasif duruma alındı.';
        $message_type = 'success';
    } else {
        $message = 'Teklif sonlandırılırken bir hata oluştu.';
        $message_type = 'error';
    }
    
    // Sayfayı yenile
    $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
    echo '<script>window.location.href = "?view=customers&action=view&id=' . $customer_id . '&offer_terminated=1";</script>';
    exit;
}

// Normal dosya silme işlemi
if (isset($_POST['delete_file']) && isset($_POST['file_nonce']) && wp_verify_nonce($_POST['file_nonce'], 'delete_file_view')) {
    $file_id = intval($_POST['file_id']);
    
    if (delete_customer_file($file_id, $customer_id)) {
        $message = 'Dosya başarıyla silindi.';
        $message_type = 'success';
    } else {
        $message = 'Dosya bulunamadı veya silme yetkiniz yok.';
        $message_type = 'error';
    }
    
    // Sayfayı yenile
    $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
    echo '<script>window.location.href = "?view=customers&action=view&id=' . $customer_id . '&file_deleted=1";</script>';
    exit;
}

// Görüşme notlarını al (tarihe göre sıralı)
$notes_table = $wpdb->prefix . 'insurance_crm_customer_notes';
$customer_notes = $wpdb->get_results($wpdb->prepare("
    SELECT n.*, 
           u.display_name AS user_name
    FROM $notes_table n
    LEFT JOIN {$wpdb->users} u ON n.created_by = u.ID
    WHERE n.customer_id = %d
    ORDER BY n.created_at DESC
", $customer_id));

// Kullanıcının kayıtlı renk tercihlerini al
$current_user_id = get_current_user_id();
$personal_color = get_user_meta($current_user_id, 'crm_personal_color', true) ?: '#3498db';
$corporate_color = get_user_meta($current_user_id, 'crm_corporate_color', true) ?: '#4caf50';
$family_color = get_user_meta($current_user_id, 'crm_family_color', true) ?: '#ff9800';
$vehicle_color = get_user_meta($current_user_id, 'crm_vehicle_color', true) ?: '#e74c3c';
$home_color = get_user_meta($current_user_id, 'crm_home_color', true) ?: '#9c27b0';
$pet_color = '#e91e63'; // Evcil hayvan paneli için renk
$doc_color = '#607d8b'; // Dosya paneli için renk
$offer_color = '#00bcd4'; // Teklif paneli için renk

// Kullanıcının düzenleme yetkisi olup olmadığını kontrol et
function can_edit_customer_view($customer) {
    global $wpdb;
    $current_user_id = get_current_user_id();
    
    // Poliçe müşterisi kontrolü - HİÇ KİMSE poliçe müşterisini düzenleyemez
    if ($customer && isset($customer->is_policy_customer) && $customer->is_policy_customer == 1) {
        return false;
    }
    
    // Administrator herzaman düzenleyebilir
    if (current_user_can('administrator')) {
        return true;
    }
    
    // Kullanıcının rep verilerini al
    $rep_data = $wpdb->get_row($wpdb->prepare(
        "SELECT id, role, customer_edit, customer_delete FROM {$wpdb->prefix}insurance_crm_representatives 
         WHERE user_id = %d AND status = 'active'",
        $current_user_id
    ));
    
    if (!$rep_data) {
        return false;
    }
    
    // Müşteri detayları erişim ayarını kontrol et
    $settings = get_option('insurance_crm_settings', array());
    $allow_customer_details_access = isset($settings['permission_settings']['allow_customer_details_access']) && $settings['permission_settings']['allow_customer_details_access'];
    
    // Eğer genel erişim açıksa, müşteri düzenleme yapılamaz (sadece görüşme notu eklenebilir)
    if ($allow_customer_details_access) {
        // Sadece Patron her zaman düzenleyebilir (yönetim yetkisi)
        if ($rep_data->role == 1) return true;
        
        // Diğer kullanıcılar düzenleyemez, sadece görüşme notu ekleyebilir
        return false;
    }
    
    // Genel erişim kapalıysa, normal yetki kontrolü
    // Rol kontrolü yap
    if ($rep_data->role == 1) { // Patron
        return true;
    }
    
    if ($rep_data->role == 2 && $rep_data->customer_edit == 1) { // Müdür + düzenleme yetkisi var
        return true;
    }
    
    if ($rep_data->role == 3 && $rep_data->customer_edit == 1) { // Müdür Yardımcısı + düzenleme yetkisi var
        return true;
    }
    
    if ($rep_data->role == 4 && $rep_data->customer_edit == 1) { // Ekip Lideri + düzenleme yetkisi var
        // Ekip liderinin kendi ekibi kontrolü
        if (function_exists('get_team_members')) {
            $members = get_team_members($current_user_id);
            return in_array($customer->representative_id, $members);
        }
    }
    
    // Temsilci sadece kendi müşterilerini düzenleyebilir
    if ($rep_data->role == 5 && $customer && $customer->representative_id == $rep_data->id) {
        return true;
    }
    
    return false;
}

/**
 * Görüşme notları silme yetkisi kontrolü (sadece Patron ve Müdür)
 */
function can_delete_conversation_notes() {
    global $wpdb;
    $current_user_id = get_current_user_id();
    
    // Administrator herzaman silebilir
    if (current_user_can('administrator')) {
        return true;
    }
    
    // Kullanıcının rep verilerini al
    $rep_data = $wpdb->get_row($wpdb->prepare(
        "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives 
         WHERE user_id = %d AND status = 'active'",
        $current_user_id
    ));
    
    if (!$rep_data) {
        return false;
    }
    
    // Sadece Patron (1) ve Müdür (2) silebilir
    return ($rep_data->role == 1 || $rep_data->role == 2);
}

/**
 * Müşteri notlarını düzenleme yetkisi kontrolü
 */
function can_edit_customer_notes($customer) {
    global $wpdb;
    $current_user_id = get_current_user_id();
    
    // Poliçe müşterisi kontrolü - HİÇ KİMSE poliçe müşterisinin customer notlarını düzenleyemez
    if ($customer && isset($customer->is_policy_customer) && $customer->is_policy_customer == 1) {
        return false;
    }
    
    // Administrator herzaman düzenleyebilir
    if (current_user_can('administrator')) {
        return true;
    }
    
    // Kullanıcının rep verilerini al
    $rep_data = $wpdb->get_row($wpdb->prepare(
        "SELECT id, role FROM {$wpdb->prefix}insurance_crm_representatives 
         WHERE user_id = %d AND status = 'active'",
        $current_user_id
    ));
    
    if (!$rep_data) {
        return false;
    }
    
    // Patron ve Müdür herzaman düzenleyebilir
    if ($rep_data->role == 1 || $rep_data->role == 2) { // Patron veya Müdür
        return true;
    }
    
    // Sadece müşterinin atanmış temsilcisi düzenleyebilir
    return ($customer && $customer->representative_id == $rep_data->id);
}

/**
 * Poliçe müşterisi olup olmadığını kontrol et
 */
function is_policy_only_customer($customer, $current_user_rep_id) {
    return ($customer && $customer->representative_id != $current_user_rep_id);
}

/**
 * Görüşme notları ekleme yetkisi kontrolü (policy customers için özel)
 */
function can_add_conversation_notes($customer) {
    global $wpdb;
    $current_user_id = get_current_user_id();
    
    // Administrator herzaman not ekleyebilir
    if (current_user_can('administrator')) {
        return true;
    }
    
    // Kullanıcının rep verilerini al
    $rep_data = $wpdb->get_row($wpdb->prepare(
        "SELECT id, role FROM {$wpdb->prefix}insurance_crm_representatives 
         WHERE user_id = %d AND status = 'active'",
        $current_user_id
    ));
    
    if (!$rep_data) {
        return false;
    }
    
    // Patron ve Müdür herzaman not ekleyebilir
    if ($rep_data->role == 1 || $rep_data->role == 2) { // Patron veya Müdür
        return true;
    }
    
    // Müşterinin atanmış temsilcisi herzaman not ekleyebilir
    if ($customer && $customer->representative_id == $rep_data->id) {
        return true;
    }
    
    // Poliçe müşterisi ise (başka bir temsilcinin müşterisi + bu temsilcinin poliçesi var)
    // sadece görüşme notu ekleyebilir
    if ($customer && isset($customer->is_policy_customer) && $customer->is_policy_customer == 1) {
        return true;
    }
    
    return false;
}

/**
 * Müşteri dosyalarını yükler
 */
function handle_customer_file_uploads($customer_id) {
    global $wpdb;
    $files_table = $wpdb->prefix . 'insurance_crm_customer_files';
    $upload_dir = wp_upload_dir();
    $customer_dir = $upload_dir['basedir'] . '/customer_files/' . $customer_id;
    
    // Klasör yoksa oluştur
    if (!file_exists($customer_dir)) {
        wp_mkdir_p($customer_dir);
    }
    
    // Admin panelinden izin verilen dosya türlerini al
    $settings = get_option('insurance_crm_settings', array());
    $allowed_types = !empty($settings['file_upload_settings']['allowed_file_types']) 
        ? $settings['file_upload_settings']['allowed_file_types'] 
        : array('jpg', 'jpeg', 'pdf', 'docx'); // Varsayılan türler

    $max_file_size = 5 * 1024 * 1024; // 5MB
    $max_file_count = 5; // Maksimum dosya sayısı
    
    $file_count = count($_FILES['customer_files']['name']);
    
    // Dosya sayısını kontrol et
    if ($file_count > $max_file_count) {
        $_SESSION['crm_notice'] = '<div class="ab-notice ab-error">En fazla ' . $max_file_count . ' dosya yükleyebilirsiniz.</div>';
        return false;
    }
    
    $upload_count = 0;
    $success = false;
    
    for ($i = 0; $i < $file_count; $i++) {
        if ($_FILES['customer_files']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        
        $file_name = sanitize_file_name($_FILES['customer_files']['name'][$i]);
        $file_tmp = $_FILES['customer_files']['tmp_name'][$i];
        $file_size = $_FILES['customer_files']['size'][$i];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $file_description = isset($_POST['file_descriptions'][$i]) ? sanitize_text_field($_POST['file_descriptions'][$i]) : '';
        
        // Dosya türü ve boyutu kontrolü
        if (!in_array($file_ext, $allowed_types)) {
            continue;
        }
        
        if ($file_size > $max_file_size) {
            continue;
        }
        
        // Upload sayısını kontrol et
        if ($upload_count >= $max_file_count) {
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-warning">Maksimum ' . $max_file_count . ' dosya sınırına ulaşıldı. Diğer dosyalar yüklenmedi.</div>';
            break;
        }
        
        // Benzersiz dosya adı oluştur
        $new_file_name = time() . '-' . $file_name;
        $file_path = $customer_dir . '/' . $new_file_name;
        $file_url = $upload_dir['baseurl'] . '/customer_files/' . $customer_id . '/' . $new_file_name;
        
        // Dosyayı taşı
        if (move_uploaded_file($file_tmp, $file_path)) {
            // Dosya bilgilerini veritabanına kaydet
            $wpdb->insert(
                $files_table,
                array(
                    'customer_id' => $customer_id,
                    'file_name' => $file_name,
                    'file_path' => $file_url,
                    'file_type' => $file_ext,
                    'file_size' => $file_size,
                    'upload_date' => current_time('mysql'),
                    'description' => $file_description
                )
            );
            
            $upload_count++;
            $success = true;
        }
    }
    
    return $success;
}

/**
 * Müşteri dosyasını siler
 */
function delete_customer_file($file_id, $customer_id) {
    global $wpdb;
    $files_table = $wpdb->prefix . 'insurance_crm_customer_files';
    
    // Dosya bilgilerini al
    $file = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $files_table WHERE id = %d AND customer_id = %d",
        $file_id, $customer_id
    ));
    
    if (!$file) {
        error_log("File not found: file_id=$file_id, customer_id=$customer_id");
        return false;
    }
    
    // Dosyayı fiziksel olarak sil
    $upload_dir = wp_upload_dir();
    $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file->file_path);
    if (file_exists($file_path)) {
        if (!unlink($file_path)) {
            error_log("Failed to delete physical file: $file_path");
            return false;
        }
    }
    
    // Veritabanından dosya kaydını sil
    $result = $wpdb->delete(
        $files_table,
        array('id' => $file_id, 'customer_id' => $customer_id),
        array('%d', '%d')
    );
    
    if ($result === false) {
        error_log("Failed to delete file record from database: file_id=$file_id");
        return false;
    }
    
    return true;
}

// Dosya türüne göre ikon belirleme
function get_file_icon($file_type) {
    switch ($file_type) {
        case 'pdf':
            return 'fa-file-pdf';
        case 'doc':
        case 'docx':
            return 'fa-file-word';
        case 'jpg':
        case 'jpeg':
        case 'png':
            return 'fa-file-image';
        case 'xls':
        case 'xlsx':
            return 'fa-file-excel';
        case 'txt':
            return 'fa-file-alt';
        case 'zip':
            return 'fa-file-archive';
        default:
            return 'fa-file';
    }
}

// Dosya boyutu formatını düzenleme
function format_file_size($size) {
    if ($size < 1024) {
        return $size . ' B';
    } elseif ($size < 1048576) {
        return round($size / 1024, 2) . ' KB';
    } else {
        return round($size / 1048576, 2) . ' MB';
    }
}
?>

<!-- Font Awesome CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<div class="ab-customer-details">
    
    <!-- Müşteri Başlık Bilgisi -->
    <div class="ab-customer-header" style="padding: 20px 40px;">
        <div class="ab-customer-title">
            <h1><i class="fas fa-user"></i> <?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?></h1>
            <div class="ab-customer-meta">
                <span class="ab-badge ab-badge-category-<?php echo $customer->category; ?>">
                    <?php echo $customer->category == 'bireysel' ? 'Bireysel' : 'Kurumsal'; ?>
                </span>
                <span class="ab-badge ab-badge-status-<?php echo $customer->status; ?>">
                    <?php 
                    switch ($customer->status) {
                        case 'aktif': echo 'Aktif'; break;
                        case 'pasif': echo 'Pasif'; break;
                        case 'belirsiz': echo 'Belirsiz'; break;
                        default: echo ucfirst($customer->status);
                    }
                    ?>
                </span>
                <span>
                    <i class="fas fa-user-tie"></i>
                    <?php echo !empty($customer->rep_name) ? esc_html($customer->rep_name) : 'Atanmamış'; ?>
                </span>
                <span>
                    <i class="fas fa-user-check"></i>
                    İlk Kayıt Eden: <?php echo !empty($customer->first_registrar_name) ? esc_html($customer->first_registrar_name) : 'Belirtilmemiş'; ?>
                </span>
            </div>
        </div>
        <div class="ab-customer-actions">
            <!-- Düzenleme yetkisi kontrolü -->
            <?php if (can_edit_customer_view($customer)): ?>
            <a href="?view=customers&action=edit&id=<?php echo $customer_id; ?>" class="ab-btn ab-btn-primary">
                <i class="fas fa-edit"></i> Düzenle
            </a>
            <?php endif; ?>
            <a href="?view=tasks&action=new&customer_id=<?php echo $customer_id; ?>" class="ab-btn">
                <i class="fas fa-tasks"></i> Yeni Görev
            </a>
            <a href="?view=policies&action=new&customer_id=<?php echo $customer_id; ?>" class="ab-btn">
                <i class="fas fa-file-contract"></i> Yeni Poliçe
            </a>
            <a href="?view=customers" class="ab-btn ab-btn-secondary">
                <i class="fas fa-arrow-left"></i> Listeye Dön
            </a>
        </div>
    </div>
    
    <?php if (isset($_SESSION['crm_notice'])): ?>
        <?php echo $_SESSION['crm_notice']; ?>
        <?php unset($_SESSION['crm_notice']); ?>
    <?php endif; ?>

    <div id="ajax-response-container"></div>
    
    <!-- Müşteri Bilgileri -->
    <div class="ab-panels" style="padding: 0 40px; max-width: 100%; box-sizing: border-box;">
        <div class="ab-panel ab-panel-personal" style="--panel-color: <?php echo esc_attr($personal_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-user-circle"></i> Kişisel Bilgiler</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Ad Soyad</div>
                        <div class="ab-info-value"><?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?></div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">TC Kimlik No</div>
                        <div class="ab-info-value"><?php echo esc_html($customer->tc_identity); ?></div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">E-posta</div>
                        <div class="ab-info-value">
                            <?php if (!empty($customer->email)): ?>
                            <a href="mailto:<?php echo esc_attr($customer->email); ?>">
                                <i class="fas fa-envelope"></i> <?php echo esc_html($customer->email); ?>
                            </a>
                            <?php else: ?>
                            <span class="no-value">E-posta Bilgisi Eksik</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Telefon</div>
                        <div class="ab-info-value">
                            <a href="tel:<?php echo esc_attr($customer->phone); ?>">
                                <i class="fas fa-phone"></i> <?php echo esc_html($customer->phone); ?>
                            </a>
                        </div>
                    </div>
                    <?php if (!empty($customer->phone2)): ?>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Telefon Numarası 2</div>
                        <div class="ab-info-value">
                            <a href="tel:<?php echo esc_attr($customer->phone2); ?>">
                                <i class="fas fa-phone"></i> <?php echo esc_html($customer->phone2); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="ab-info-item ab-full-width">
                        <div class="ab-info-label">Adres</div>
                        <div class="ab-info-value"><?php echo nl2br(esc_html($customer->address)); ?></div>
                    </div>
                    <?php if (!empty($customer->uavt_code)): ?>
                    <div class="ab-info-item">
                        <div class="ab-info-label">UAVT Kodu</div>
                        <div class="ab-info-value"><?php echo esc_html($customer->uavt_code); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Doğum Tarihi</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->birth_date) ? date('d.m.Y', strtotime($customer->birth_date)) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>

                    <div class="ab-info-item">
                        <div class="ab-info-label">Cinsiyet</div>
                        <div class="ab-info-value">
                            <?php 
                            if (!empty($customer->gender)) {
                                if ($customer->gender === 'male') {
                                    echo 'Erkek';
                                } elseif ($customer->gender === 'female') {
                                    echo 'Kadın';
                                } else {
                                    echo esc_html($customer->gender);
                                }
                            } else {
                                echo '<span class="no-value">Belirtilmemiş</span>';
                            }
                            ?>
                        </div>
                    </div>

                    <div class="ab-info-item">
                        <div class="ab-info-label">Medeni Durum</div>
                        <div class="ab-info-value">
                            <?php 
                            if (!empty($customer->marital_status)) {
                                if ($customer->marital_status === 'single') {
                                    echo 'Bekar';
                                } elseif ($customer->marital_status === 'married') {
                                    echo 'Evli';
                                } else {
                                    echo esc_html($customer->marital_status);
                                }
                            } else {
                                echo '<span class="no-value">Belirtilmemiş</span>';
                            }
                            ?>
                        </div>
                    </div>

                    <div class="ab-info-item">
                        <div class="ab-info-label">Meslek</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->occupation) ? esc_html($customer->occupation) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Kayıt Tarihi</div>
                        <div class="ab-info-value"><?php echo date('d.m.Y', strtotime($customer->created_at)); ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($customer->category === 'kurumsal'): ?>
        <!-- Kurumsal Müşteri için Firma Bilgileri -->
        <div class="ab-panel ab-panel-corporate" style="--panel-color: <?php echo esc_attr($corporate_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-building"></i> Firma Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Firma Adı</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->company_name) ? esc_html($customer->company_name) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Vergi Dairesi</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->tax_office) ? esc_html($customer->tax_office) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Vergi Kimlik Numarası</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->tax_number) ? esc_html($customer->tax_number) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="ab-panel ab-panel-family" style="--panel-color: <?php echo esc_attr($family_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-users"></i> Aile Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Eş Adı</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->spouse_name) ? esc_html($customer->spouse_name) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Eş TC Kimlik No</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->spouse_tc_identity) ? esc_html($customer->spouse_tc_identity) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Eşin Doğum Tarihi</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->spouse_birth_date) ? date('d.m.Y', strtotime($customer->spouse_birth_date)) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Çocuk Sayısı</div>
                        <div class="ab-info-value">
                            <?php echo isset($customer->children_count) && $customer->children_count > 0 ? $customer->children_count : '0'; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($customer->children_names)): ?>
                    <div class="ab-info-item ab-full-width">
                        <div class="ab-info-label">Çocuklar</div>
                        <div class="ab-info-value">
                            <?php
                            $children_names = explode(',', $customer->children_names);
                            $children_birth_dates = !empty($customer->children_birth_dates) ? explode(',', $customer->children_birth_dates) : [];
                            $children_tc_identities = !empty($customer->children_tc_identities) ? explode(',', $customer->children_tc_identities) : [];
                            
                            echo '<ul class="ab-children-list">';
                            for ($i = 0; $i < count($children_names); $i++) {
                                echo '<li>' . esc_html(trim($children_names[$i]));
                                
                                if (isset($children_tc_identities[$i]) && !empty(trim($children_tc_identities[$i]))) {
                                    echo ' - TC: ' . esc_html(trim($children_tc_identities[$i]));
                                }
                                
                                if (isset($children_birth_dates[$i]) && !empty(trim($children_birth_dates[$i]))) {
                                    echo ' - Doğum: ' . date('d.m.Y', strtotime(trim($children_birth_dates[$i])));
                                }
                                
                                echo '</li>';
                            }
                            echo '</ul>';
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="ab-panel ab-panel-vehicle" style="--panel-color: <?php echo esc_attr($vehicle_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-car"></i> Araç Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Aracı Var mı?</div>
                        <div class="ab-info-value">
                            <?php echo isset($customer->has_vehicle) && $customer->has_vehicle == 1 ? 'Evet' : 'Hayır'; ?>
                        </div>
                    </div>
                    
                    <?php if (isset($customer->has_vehicle) && $customer->has_vehicle == 1): ?>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Araç Plakası</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->vehicle_plate) ? esc_html($customer->vehicle_plate) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    <?php if (!empty($customer->vehicle_document_serial)): ?>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Belge Seri No</div>
                        <div class="ab-info-value"><?php echo esc_html($customer->vehicle_document_serial); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="ab-panel ab-panel-home" style="--panel-color: <?php echo esc_attr($home_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-home"></i> Ev Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Evi Kendisine mi Ait?</div>
                        <div class="ab-info-value">
                            <?php echo isset($customer->owns_home) && $customer->owns_home == 1 ? 'Evet' : 'Hayır'; ?>
                        </div>
                    </div>
                    
                    <?php if (isset($customer->owns_home) && $customer->owns_home == 1): ?>
                    <div class="ab-info-item">
                        <div class="ab-info-label">DASK Poliçesi</div>
                        <div class="ab-info-value">
                            <?php 
                            if (isset($customer->has_dask_policy)) {
                                if ($customer->has_dask_policy == 1) {
                                    echo '<span class="ab-positive">Var</span>';
                                    if (!empty($customer->dask_policy_expiry)) {
                                        echo ' (Vade: ' . date('d.m.Y', strtotime($customer->dask_policy_expiry)) . ')';
                                    }
                                } else {
                                    echo '<span class="ab-negative">Yok</span>';
                                }
                            } else {
                                echo '<span class="no-value">Belirtilmemiş</span>';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Konut Poliçesi</div>
                        <div class="ab-info-value">
                            <?php 
                            if (isset($customer->has_home_policy)) {
                                if ($customer->has_home_policy == 1) {
                                    echo '<span class="ab-positive">Var</span>';
                                    if (!empty($customer->home_policy_expiry)) {
                                        echo ' (Vade: ' . date('d.m.Y', strtotime($customer->home_policy_expiry)) . ')';
                                    }
                                } else {
                                    echo '<span class="ab-negative">Yok</span>';
                                }
                            } else {
                                echo '<span class="no-value">Belirtilmemiş</span>';
                            }
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Evcil Hayvan Bilgileri Paneli -->
        <div class="ab-panel ab-panel-pet" style="--panel-color: <?php echo esc_attr($pet_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-paw"></i> Evcil Hayvan Bilgileri</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Evcil Hayvanı Var mı?</div>
                        <div class="ab-info-value">
                            <?php echo isset($customer->has_pet) && $customer->has_pet == 1 ? 'Evet' : 'Hayır'; ?>
                        </div>
                    </div>
                    
                    <?php if (isset($customer->has_pet) && $customer->has_pet == 1): ?>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Evcil Hayvan Adı</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->pet_name) ? esc_html($customer->pet_name) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Evcil Hayvan Cinsi</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->pet_type) ? esc_html($customer->pet_type) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Evcil Hayvan Yaşı</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->pet_age) ? esc_html($customer->pet_age) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Teklif Bilgileri Paneli -->
        <div class="ab-panel ab-panel-offer" style="--panel-color: <?php echo esc_attr($offer_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-file-invoice-dollar"></i> Teklif Verildi mi?</h3>
            </div>
            <div class="ab-panel-body">
                <div class="ab-info-grid">
                    <div class="ab-info-item">
                        <div class="ab-info-label">Teklif Durumu</div>
                        <div class="ab-info-value">
                            <?php 
                            $has_offer = isset($customer->has_offer) && $customer->has_offer == 1;
                            if ($has_offer) {
                                echo '<span class="ab-positive">Evet</span>';
                                if (can_edit_customer_view($customer)) {
                                    echo ' <button type="button" onclick="toggleOfferStatus(0)" class="btn-small btn-outline" title="Hayır olarak değiştir">
                                            <i class="fas fa-edit"></i>
                                          </button>';
                                }
                            } else {
                                echo '<span class="ab-negative">Hayır</span>';
                                if (can_edit_customer_view($customer)) {
                                    echo ' <button type="button" onclick="toggleOfferStatus(1)" class="btn-small btn-primary" title="Evet olarak değiştir">
                                            <i class="fas fa-plus"></i> Teklif Ver
                                          </button>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                    
                    <?php if ($has_offer): ?>
                    <div class="ab-info-item">
                        <div class="ab-info-label">Sigorta Tipi</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->offer_insurance_type) ? esc_html($customer->offer_insurance_type) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Teklif Tutarı</div>
                        <div class="ab-info-value ab-amount">
                            <?php 
                            if (!empty($customer->offer_amount)) {
                                echo number_format($customer->offer_amount, 2, ',', '.') . ' ₺';
                            } else {
                                echo '<span class="no-value">Belirtilmemiş</span>';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Teklif Vadesi</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->offer_expiry_date) ? date('d.m.Y', strtotime($customer->offer_expiry_date)) : '<span class="no-value">Belirtilmemiş</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Teklif Dosyası</div>
                        <div class="ab-info-value ab-offer-file">
                        <?php 
                        // Teklif dosyasını bulmak için dosya arşivini kontrol et
                        $offer_file = null;
                        if (!empty($files)) {
                            foreach ($files as $file) {
                                if (strpos(strtolower($file->description), 'teklif') !== false) {
                                    $offer_file = $file;
                                    break;
                                }
                            }
                        }
                        
                        if ($offer_file): 
                        ?>
                            <a href="<?php echo esc_url($offer_file->file_path); ?>" target="_blank" class="ab-btn ab-btn-sm">
                                <i class="fas <?php echo get_file_icon($offer_file->file_type); ?>"></i> 
                                <?php echo esc_html($offer_file->file_name); ?>
                            </a>
                        <?php else: ?>
                            <span class="no-value">Dosya yüklenmemiş</span>
                            <a href="#" class="ab-btn ab-btn-sm open-file-upload-modal">
                                <i class="fas fa-upload"></i> Teklif Dosyası Yükle
                            </a>
                        <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="ab-info-item">
                        <div class="ab-info-label">Teklif Notları</div>
                        <div class="ab-info-value">
                            <?php echo !empty($customer->offer_notes) ? nl2br(esc_html($customer->offer_notes)) : '<span class="no-value">Not eklenmemiş</span>'; ?>
                        </div>
                    </div>
                    
                    <!-- Teklif Aksiyon Butonları -->
                    <div class="ab-info-actions" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; display: flex; gap: 15px; justify-content: center;">
                        <a href="?view=policies&action=create_from_offer&customer_id=<?php echo $customer_id; ?>&offer_amount=<?php echo !empty($customer->offer_amount) ? $customer->offer_amount : '0'; ?>&offer_type=<?php echo !empty($customer->offer_insurance_type) ? urlencode($customer->offer_insurance_type) : ''; ?>" class="ab-btn ab-btn-success">
                            <i class="fas fa-file-contract"></i> Poliçeleştir
                        </a>
                        <button type="button" class="ab-btn ab-btn-danger" onclick="showTerminateModal(<?php echo $customer->id; ?>)">
                            <i class="fas fa-times-circle"></i> Sonlandır
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Teklif Ekleme Formu -->
        <?php if (can_edit_customer_view($customer)): ?>
        <div id="quote-form-section" class="ab-panel ab-panel-offer modern-quote-form" style="--panel-color: <?php echo esc_attr($offer_color); ?>; display: none;">
            <div class="quote-form-header">
                <div class="quote-form-icon">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div class="quote-form-title">
                    <h4>Teklif Bilgileri Ekle</h4>
                    <p>Müşteri için teklif bilgilerini girin ve hatırlatma ayarlarını yapın</p>
                </div>
            </div>
            <div class="modern-form-container">
                <form method="post" action="">
                    <input type="hidden" name="action" value="update_offer">
                    <input type="hidden" name="customer_id" value="<?php echo $customer->id; ?>">
                    <?php wp_nonce_field('update_customer_offer', 'offer_nonce'); ?>
                    
                    <div class="form-grid">
                        <div class="form-field">
                            <label class="modern-label" for="offer_insurance_type">
                                <i class="fas fa-shield-alt"></i>
                                Sigorta Türü
                            </label>
                            <select name="offer_insurance_type" id="offer_insurance_type" class="modern-input modern-select" required>
                                <option value="">Sigorta türü seçin</option>
                                <option value="TSS">Trafik Sigortası (TSS)</option>
                                <option value="Kasko">Kasko</option>
                                <option value="DASK">DASK</option>
                                <option value="Konut">Konut Sigortası</option>
                                <option value="İşyeri">İşyeri Sigortası</option>
                                <option value="Sağlık">Sağlık Sigortası</option>
                                <option value="Yaşam">Yaşam Sigortası</option>
                                <option value="Seyahat">Seyahat Sigortası</option>
                                <option value="Diğer">Diğer</option>
                            </select>
                        </div>
                        
                        <div class="form-field">
                            <label class="modern-label" for="offer_amount">
                                <i class="fas fa-lira-sign"></i>
                                Teklif Tutarı (₺)
                            </label>
                            <input type="number" name="offer_amount" id="offer_amount" class="modern-input" 
                                   placeholder="0.00" step="0.01" min="0" required>
                        </div>
                        
                        <div class="form-field">
                            <label class="modern-label" for="offer_expiry_date">
                                <i class="fas fa-calendar-alt"></i>
                                Teklif Geçerlilik Tarihi
                            </label>
                            <input type="date" name="offer_expiry_date" id="offer_expiry_date" class="modern-input" required>
                        </div>
                        
                        <div class="form-field">
                            <label class="modern-label">
                                <i class="fas fa-bell"></i>
                                Hatırlatma Ayarı
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; margin-top: 8px;">
                                <input type="checkbox" name="offer_reminder" id="offer_reminder" value="1" checked>
                                <span>Vade tarihinden 1 gün önce hatırlatma görevi oluştur</span>
                            </label>
                        </div>
                        
                        <div class="form-field full-width">
                            <label class="modern-label" for="offer_notes">
                                <i class="fas fa-sticky-note"></i>
                                Teklif Notları
                            </label>
                            <textarea name="offer_notes" id="offer_notes" class="modern-input modern-textarea" 
                                      placeholder="Teklif ile ilgili notlarınızı buraya yazın..."></textarea>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn-large btn-primary">
                            <i class="fas fa-save"></i>
                            Teklif Kaydet
                        </button>
                        <button type="button" class="btn-large btn-secondary" onclick="cancelQuoteForm()">
                            <i class="fas fa-times"></i>
                            İptal
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        

        <!-- Müşteri Notu Paneli (Tek Not Sistemi) -->
        <div class="ab-panel ab-full-panel" id="single-customer-note-section">
            <div class="ab-panel-header">
                <h3><i class="fas fa-note-sticky"></i> Müşteri Notu</h3>
                <div class="ab-panel-actions">
                    <?php if (can_edit_customer_notes($customer)): ?>
                        <?php if (!empty($customer->customer_notes)): ?>
                            <button type="button" class="ab-btn ab-btn-sm ab-btn-outline" id="edit-customer-note-btn">
                                <i class="fas fa-edit"></i> Düzenle
                            </button>
                            <button type="button" class="ab-btn ab-btn-sm ab-btn-danger" id="delete-customer-note-btn">
                                <i class="fas fa-trash"></i> Sil
                            </button>
                        <?php else: ?>
                            <button type="button" class="ab-btn ab-btn-sm" id="add-customer-note-btn">
                                <i class="fas fa-plus"></i> Not Ekle
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ab-panel-body">
                
                <!-- Mevcut Not Görüntüleme -->
                <?php if (!empty($customer->customer_notes)): ?>
                    <div class="single-customer-note-display" id="customer-note-display">
                        <div class="note-content" style="text-align: left;">
                            <?php echo nl2br(esc_html($customer->customer_notes)); ?>
                        </div>
                        <?php if (!empty($customer->customer_notes_updated_at)): ?>
                            <div class="note-timestamp" style="font-size: 11px; color: #666; margin-top: 8px; text-align: right;">
                                Son güncelleme: <?php echo date('d.m.Y H:i', strtotime($customer->customer_notes_updated_at)); ?>
                            </div>
                        <?php elseif (!empty($customer->created_at)): ?>
                            <div class="note-timestamp" style="font-size: 11px; color: #666; margin-top: 8px; text-align: right;">
                                Oluşturulma: <?php echo date('d.m.Y H:i', strtotime($customer->created_at)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="single-customer-note-empty" id="customer-note-empty">
                        <i class="fas fa-info-circle"></i> Henüz müşteri notu bulunmamaktadır.
                    </div>
                <?php endif; ?>

                <!-- Not Düzenleme/Ekleme Formu -->
                <div class="ab-add-note-form" id="customer-note-form" style="display:none;">
                    <form method="post" action="">
                        <?php wp_nonce_field('save_customer_notes', 'customer_notes_nonce'); ?>
                        <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                        <div class="ab-form-row">
                            <div class="ab-form-group ab-full-width">
                                <label for="customer_notes_content">Müşteri Notu</label>
                                <textarea name="customer_notes_content" id="customer_notes_content" rows="6" placeholder="Müşteri ile ilgili önemli notlar, hatırlatmalar..."><?php echo esc_textarea($customer->customer_notes); ?></textarea>
                                <div class="ab-form-help">Bu alanda müşteri ile ilgili genel notlar ve hatırlatmalar tutabilirsiniz.</div>
                            </div>
                        </div>
                        <div class="ab-form-actions">
                            <button type="submit" name="save_customer_notes" class="ab-btn ab-btn-primary">
                                <i class="fas fa-save"></i> Kaydet
                            </button>
                            <button type="button" class="ab-btn ab-btn-secondary" id="cancel-customer-note">
                                <i class="fas fa-times"></i> İptal
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Not Silme Onay Formu -->
                <div class="customer-note-delete-form" id="customer-note-delete-form" style="display:none;">
                    <div class="ab-notice ab-warning">
                        <strong>Dikkat:</strong> Müşteri notunu silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.
                    </div>
                    <form method="post" action="">
                        <?php wp_nonce_field('save_customer_notes', 'customer_notes_nonce'); ?>
                        <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                        <input type="hidden" name="customer_notes_content" value="">
                        <div class="ab-form-actions">
                            <button type="submit" name="save_customer_notes" class="ab-btn ab-btn-danger">
                                <i class="fas fa-trash"></i> Evet, Sil
                            </button>
                            <button type="button" class="ab-btn ab-btn-secondary" id="cancel-customer-note-delete">
                                <i class="fas fa-times"></i> İptal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Görüşme Notları Paneli -->
        <div class="ab-panel ab-full-panel" id="customer-notes-section">
            <div class="ab-panel-header">
                <h3><i class="fas fa-comments"></i> Görüşme Notları</h3>
                <div class="ab-panel-actions">
                    <?php if (can_add_conversation_notes($customer)): ?>
                    <button type="button" class="ab-btn ab-btn-sm" id="toggle-note-form">
                        <i class="fas fa-plus"></i> Görüşme Notu Ekle
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ab-panel-body">

                <!-- Not Ekleme Formu -->
                <?php if (can_add_conversation_notes($customer)): ?>
                <div class="ab-add-note-form" style="display:none;">
                    <form method="post" action="">
                        <?php wp_nonce_field('add_customer_note', 'note_nonce'); ?>
                        <div class="ab-form-row">
                            <div class="ab-form-group ab-full-width">
                                <label for="note_content">Not İçeriği</label>
                                <textarea name="note_content" id="note_content" rows="4" required></textarea>
                            </div>
                        </div>
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label for="note_type">Görüşme Sonucu</label>
                                <select name="note_type" id="note_type" required>
                                    <option value="">Seçiniz</option>
                                    <option value="positive">Olumlu</option>
                                    <option value="neutral">Durumu Belirsiz</option>
                                    <option value="negative">Olumsuz</option>
                                </select>
                            </div>
                            <div class="ab-form-group" id="rejection_reason_container" style="display:none;">
                                <label for="rejection_reason">Olumsuz Olma Sebebi</label>
                                <select name="rejection_reason" id="rejection_reason">
                                    <option value="">Seçiniz</option>
                                    <option value="price">Fiyat</option>
                                    <option value="wrong_application">Yanlış Başvuru</option>
                                    <option value="existing_policy">Mevcut Poliçesi Var</option>
                                    <option value="other">Diğer</option>
                                </select>
                            </div>
                        </div>
                        <div class="ab-form-actions">
                            <button type="submit" name="add_note" class="ab-btn ab-btn-primary">
                                <i class="fas fa-save"></i> Kaydet
                            </button>
                            <button type="button" class="ab-btn ab-btn-secondary" id="cancel-note-form">
                                <i class="fas fa-times"></i> Vazgeç
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Mevcut Notlar -->
                <div class="ab-notes-list">
                    <?php if (empty($customer_notes)): ?>
                    <div class="ab-empty-state">
                        <p><i class="fas fa-comments"></i><br>Henüz görüşme notu eklenmemiş.</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($customer_notes as $note): ?>
                        <div class="ab-sticky-note sticky-note-<?php echo esc_attr($note->note_type); ?>">
                            <div class="sticky-note-header">
                                <div class="sticky-note-meta">
                                    <span class="sticky-note-user">
                                        <i class="fas fa-user"></i> <?php echo esc_html($note->user_name); ?>
                                    </span>
                                    <span class="sticky-note-date">
                                        <i class="fas fa-clock"></i> <?php echo date('d.m.Y H:i', strtotime($note->created_at)); ?>
                                    </span>
                                </div>
                                <div class="sticky-note-actions">
                                    <div class="sticky-note-type-badge sticky-note-type-<?php echo esc_attr($note->note_type); ?>">
                                        <?php 
                                        switch ($note->note_type) {
                                            case 'positive': echo 'Olumlu'; break;
                                            case 'neutral': echo 'Belirsiz'; break;
                                            case 'negative': echo 'Olumsuz'; break;
                                            default: echo ucfirst($note->note_type); break;
                                        }
                                        ?>
                                    </div>
                                    <?php if (can_delete_conversation_notes()): ?>
                                    <button type="button" class="btn-delete-note" data-note-id="<?php echo $note->id; ?>" title="Notu Sil">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="sticky-note-content">
                                <?php echo nl2br(esc_html($note->note_content)); ?>
                            </div>
                            <?php if (!empty($note->rejection_reason)): ?>
                            <div class="sticky-note-reason">
                                <strong>Sebep:</strong> 
                                <?php 
                                switch ($note->rejection_reason) {
                                    case 'price': echo 'Fiyat'; break;
                                    case 'wrong_application': echo 'Yanlış Başvuru'; break;
                                    case 'existing_policy': echo 'Mevcut Poliçesi Var'; break;
                                    case 'other': echo 'Diğer'; break;
                                    default: echo ucfirst($note->rejection_reason); break;
                                }
                                ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Poliçeler Paneli -->
        <div class="ab-panel ab-full-panel">
            <div class="ab-panel-header">
                <h3><i class="fas fa-file-contract"></i> Poliçeler</h3>
                <div class="ab-panel-actions">
                    <a href="?view=policies&action=new&customer_id=<?php echo $customer_id; ?>" class="ab-btn ab-btn-sm">
                        <i class="fas fa-plus"></i> Yeni Poliçe
                    </a>
                </div>
            </div>
            <div class="ab-panel-body">
                <?php if (empty($policies)): ?>
                <div class="ab-empty-state">
                    <p>Henüz poliçe bulunmuyor.</p>
                </div>
                <?php else: ?>
                <div class="ab-table-container">
                    <table class="ab-crm-table">
                        <thead>
                            <tr>
                                <th>Poliçe No</th>
                                <th>Tür</th>
                                <th>Başlangıç</th>
                                <th>Bitiş</th>
                                <th>Prim</th>
                                <th>Durum</th>
                                <th>Temsilci</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($policies as $policy):
                                $is_expired = strtotime($policy->end_date) < time();
                                $is_expiring_soon = !$is_expired && (strtotime($policy->end_date) - time()) < (30 * 24 * 60 * 60); // 30 gün
                                $row_class = $is_expired ? 'expired' : ($is_expiring_soon ? 'expiring-soon' : '');
                            ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td>
                                        <a href="?view=policies&action=edit&id=<?php echo $policy->id; ?>">
                                            <?php echo esc_html($policy->policy_number); ?>
                                        </a>
                                        <?php if ($is_expired): ?>
                                            <span class="ab-badge ab-badge-expired">Süresi Dolmuş</span>
                                        <?php elseif ($is_expiring_soon): ?>
                                            <span class="ab-badge ab-badge-expiring">Yakında Bitiyor</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($policy->policy_type); ?></td>
                                    <td><?php echo date('d.m.Y', strtotime($policy->start_date)); ?></td>
                                    <td><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></td>
                                    <td class="ab-amount"><?php echo number_format($policy->premium_amount, 2, ',', '.'); ?> ₺</td>
                                    <td>
                                        <span class="ab-badge ab-badge-status-<?php echo esc_attr($policy->status); ?>">
                                            <?php echo esc_html($policy->status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo !empty($policy->representative_name) ? esc_html($policy->representative_name) : '—'; ?>
                                    </td>
                                    <td>
                                        <div class="ab-actions">
                                            <a href="?view=policies&action=edit&id=<?php echo $policy->id; ?>" title="Düzenle" class="ab-action-btn">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="?view=policies&action=renew&id=<?php echo $policy->id; ?>" title="Yenile" class="ab-action-btn">
                                                <i class="fas fa-sync-alt"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Dosya Arşivi Paneli -->
        <div class="ab-panel ab-full-panel ab-panel-documents" style="--panel-color: <?php echo esc_attr($doc_color); ?>">
            <div class="ab-panel-header">
                <h3><i class="fas fa-file-archive"></i> Dosya Arşivi</h3>
                <div class="ab-panel-actions">
                    <button type="button" class="ab-btn ab-btn-sm" id="open-file-upload-modal">
                        <i class="fas fa-plus"></i> Yeni Dosya Ekle
                    </button>
                </div>
            </div>
            <div class="ab-panel-body">
                <div id="files-container">
                <?php if (empty($files)): ?>
                <div class="ab-empty-state">
                    <p><i class="fas fa-file-upload"></i><br>Henüz yüklenmiş dosya bulunmuyor.</p>
                    <button type="button" class="ab-btn open-file-upload-modal">
                        <i class="fas fa-plus"></i> Dosya Yükle
                    </button>
                </div>
                <?php else: ?>
                <div class="ab-files-gallery">
                    <?php foreach ($files as $file): ?>
                    <div class="ab-file-card" data-file-id="<?php echo $file->id; ?>">
                        <div class="ab-file-card-header">
                            <div class="ab-file-type-icon">
                                <i class="fas <?php echo get_file_icon($file->file_type); ?>"></i>
                            </div>
                            <div class="ab-file-meta">
                                <div class="ab-file-name"><?php echo esc_html($file->file_name); ?></div>
                                <div class="ab-file-info">
                                    <span><i class="fas fa-calendar-alt"></i> <?php echo date('d.m.Y', strtotime($file->upload_date)); ?></span>
                                    <span><i class="fas fa-weight"></i> <?php echo format_file_size($file->file_size); ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($file->file_type == 'jpg' || $file->file_type == 'jpeg' || $file->file_type == 'png'): ?>
                        <div class="ab-file-preview">
                            <img src="<?php echo esc_url($file->file_path); ?>" alt="<?php echo esc_attr($file->file_name); ?>">
                        </div>
                        <?php else: ?>
                        <div class="ab-file-icon-large">
                            <i class="fas <?php echo get_file_icon($file->file_type); ?>"></i>
                            <span>.<?php echo esc_html($file->file_type); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($file->description)): ?>
                        <div class="ab-file-description">
                            <p><?php echo esc_html($file->description); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="ab-file-card-actions">
                            <a href="<?php echo esc_url($file->file_path); ?>" target="_blank" class="ab-btn ab-btn-sm ab-btn-primary">
                                <i class="fas <?php echo ($file->file_type === 'jpg' || $file->file_type === 'jpeg' || $file->file_type === 'png') ? 'fa-eye' : 'fa-download'; ?>"></i>
                                <?php echo ($file->file_type === 'jpg' || $file->file_type === 'jpeg' || $file->file_type === 'png') ? 'Görüntüle' : 'İndir'; ?>
                            </a>
                            <button type="button" class="ab-btn ab-btn-sm ab-btn-danger delete-file" data-file-id="<?php echo $file->id; ?>">
                                <i class="fas fa-trash"></i> Sil
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Görevler Paneli -->
        <div class="ab-panel ab-full-panel">
            <div class="ab-panel-header">
                <h3><i class="fas fa-tasks"></i> Görevler</h3>
                <div class="ab-panel-actions">
                    <a href="?view=tasks&action=new&customer_id=<?php echo $customer_id; ?>" class="ab-btn ab-btn-sm">
                        <i class="fas fa-plus"></i> Yeni Görev
                    </a>
                </div>
            </div>
            <div class="ab-panel-body">
                <?php if (empty($tasks)): ?>
                <div class="ab-empty-state">
                    <p>Henüz görev bulunmuyor.</p>
                </div>
                <?php else: ?>
                <div class="ab-table-container">
                    <table class="ab-crm-table">
                        <thead>
                            <tr>
                                <th>Görev Açıklaması</th>
                                <th>Son Tarih</th>
                                <th>Öncelik</th>
                                <th>Durum</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task):
                                $is_overdue = strtotime($task->due_date) < time() && $task->status != 'completed';
                                $row_class = $is_overdue ? 'overdue' : '';
                            ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td>
                                        <a href="?view=tasks&action=edit&id=<?php echo $task->id; ?>">
                                            <?php echo esc_html($task->task_description); ?>
                                        </a>
                                        <?php if ($is_overdue): ?>
                                            <span class="ab-badge ab-badge-overdue">Gecikmiş</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d.m.Y', strtotime($task->due_date)); ?></td>
                                    <td>
                                        <span class="ab-badge ab-badge-priority-<?php echo esc_attr($task->priority); ?>">
                                            <?php 
                                                switch ($task->priority) {
                                                    case 'low': echo 'Düşük'; break;
                                                    case 'medium': echo 'Orta'; break;
                                                    case 'high': echo 'Yüksek'; break;
                                                    default: echo ucfirst($task->priority); break;
                                                }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="ab-badge ab-badge-status-<?php echo esc_attr($task->status); ?>">
                                            <?php 
                                                switch ($task->status) {
                                                    case 'pending': echo 'Beklemede'; break;
                                                    case 'in_progress': echo 'İşlemde'; break;
                                                    case 'completed': echo 'Tamamlandı'; break;
                                                    case 'cancelled': echo 'İptal'; break;
                                                    default: echo ucfirst($task->status); break;
                                                }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="ab-actions">
                                            <a href="?view=tasks&action=edit&id=<?php echo $task->id; ?>" title="Düzenle" class="ab-action-btn">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if ($task->status != 'completed'): ?>
                                            <a href="?view=tasks&action=complete&id=<?php echo $task->id; ?>" title="Tamamla" class="ab-action-btn">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
    
<!-- Dosya Yükleme Modal -->
<div id="file-upload-modal" class="ab-modal">
    <div class="ab-modal-content">
        <div class="ab-modal-header">
            <h3><i class="fas fa-cloud-upload-alt"></i> Dosya Yükle</h3>
            <button type="button" class="ab-modal-close">&times;</button>
        </div>
        <div class="ab-modal-body">
            <form id="file-upload-form" enctype="multipart/form-data">
                <?php wp_nonce_field('file_upload_action', 'file_upload_nonce'); ?>
                <input type="hidden" name="ajax_upload_files" value="1">
                <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                
                <div class="ab-file-upload-container">
                    <div class="ab-file-upload-area" id="file-upload-area-modal">
                        <div class="ab-file-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="ab-file-upload-text">
                            Dosya yüklemek için tıklayın veya sürükleyin
                            <div class="ab-file-upload-info"><?php echo esc_html($supported_formats_text); ?> formatları desteklenir (Maks. 5MB, maksimum 5 dosya)</div>
                        </div>
                        <input type="file" name="customer_files[]" id="customer_files_modal" class="ab-file-upload" multiple
                            accept="<?php echo esc_attr($accept_attribute); ?>">
                    </div>
                    
                    <div class="ab-file-preview-container">
                        <div id="file-count-warning-modal" class="ab-file-warning" style="display:none;">
                            <i class="fas fa-exclamation-triangle"></i> En fazla 5 dosya yükleyebilirsiniz.
                        </div>
                        <div class="ab-selected-files" id="selected-files-container-modal"></div>
                    </div>
                </div>
                
                <div class="ab-progress-container" style="display:none;">
                    <div class="ab-progress-bar">
                        <div class="ab-progress-fill"></div>
                    </div>
                    <div class="ab-progress-text">Yükleniyor... 0%</div>
                </div>
            </form>
        </div>
        <div class="ab-modal-footer">
            <button type="button" class="ab-btn ab-btn-secondary" id="close-upload-modal-btn">
                <i class="fas fa-times"></i> Kapat
            </button>
            <button type="button" class="ab-btn ab-btn-primary" id="upload-files-btn">
                <i class="fas fa-upload"></i> Yükle
            </button>
        </div>
    </div>
</div>

<!-- Dosya Silme Onay Modal -->
<div id="file-delete-confirm-modal" class="ab-modal">
    <div class="ab-modal-content">
        <div class="ab-modal-header">
            <h3><i class="fas fa-trash"></i> Dosya Sil</h3>
            <button type="button" class="ab-modal-close">&times;</button>
        </div>
        <div class="ab-modal-body">
            <p>Bu dosyayı silmek istediğinizden emin misiniz?</p>
            <p>Bu işlem geri alınamaz.</p>
            <form id="file-delete-form">
                <?php wp_nonce_field('file_delete_action', 'file_delete_nonce'); ?>
                <input type="hidden" name="ajax_delete_file" value="1">
                <input type="hidden" name="file_id" id="delete_file_id" value="">
            </form>
        </div>
        <div class="ab-modal-footer">
            <button type="button" class="ab-btn ab-btn-secondary ab-modal-close-btn">
                <i class="fas fa-times"></i> İptal
            </button>
            <button type="button" class="ab-btn ab-btn-danger" id="confirm-delete-btn">
                <i class="fas fa-trash"></i> Sil
            </button>
        </div>
    </div>
</div>
</div>


        <!-- Sonlandırma Modal -->
        <div id="terminate-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 20px; border-radius: 8px; max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h3><i class="fas fa-times-circle"></i> Teklif Sonlandırma</h3>
                            <button type="button" onclick="closeTerminateModal()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
                        </div>
                        <form id="terminate-form" method="post">
                            <input type="hidden" name="customer_id" value="<?php echo $customer->id; ?>">
                            <input type="hidden" name="action" value="terminate_offer">
                            <?php wp_nonce_field('terminate_offer', 'terminate_nonce'); ?>
                            
                            <div style="margin-bottom: 20px;">
                                <label for="terminate_reason" style="display: block; margin-bottom: 8px; font-weight: bold;">Sonlandırma Sebebi *</label>
                                <textarea name="terminate_reason" id="terminate_reason" rows="4" required
                                          placeholder="Teklifin neden sonlandırıldığını açıklayınız..." 
                                          style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; resize: vertical;"></textarea>
                            </div>
                            
                            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                                <button type="submit" class="ab-btn ab-btn-danger">
                                    <i class="fas fa-times-circle"></i> Sonlandır ve Müşteriyi Pasif Yap
                                </button>
                                <button type="button" class="ab-btn ab-btn-secondary" onclick="closeTerminateModal()">
                                    <i class="fas fa-times"></i> İptal
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

<style>
/* Temel Stiller */
.ab-customer-details {
    margin-top: 20px;
    font-family: inherit;
    color: #333;
}

/* Geri dön butonu */
.ab-back-button {
    margin-bottom: 15px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 13px;
    padding: 6px 12px;
    background-color: #f7f7f7;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #444;
    text-decoration: none;
    transition: all 0.2s;
}

.ab-back-button:hover {
    background-color: #eaeaea;
    text-decoration: none;
    color: #333;
}

/* Material Design Customer Header */
.ab-customer-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 24px;
    padding: 24px;
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border-radius: 16px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(0, 0, 0, 0.04);
}

.ab-customer-title h1 {
    font-size: 28px;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 700;
    color: #1a1a1a;
    letter-spacing: -0.02em;
}

.ab-customer-title h1 i {
    color: #4caf50;
    background: rgba(76, 175, 80, 0.1);
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.ab-customer-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
}

.ab-customer-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    padding : 20px;
    align-items: center;
}

.ab-customer-meta i {
    color: #666;
    margin-right: 3px;
}

.ab-customer-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

/* Material Design Panel Stilleri */
.ab-panels {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
    margin-bottom: 24px;
    max-width: 100%;
    overflow: hidden;
}

.ab-panel {
    background-color: #fff;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(0, 0, 0, 0.04);
    transition: box-shadow 0.3s cubic-bezier(0.4, 0, 0.2, 1), transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    max-width: 100%;
    min-width: 0;
}

.ab-panel::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--panel-color, #ddd), rgba(var(--panel-color-rgb, 221, 221, 221), 0.7));
    border-radius: 12px 12px 0 0;
}

.ab-panel:hover {
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12), 0 2px 6px rgba(0, 0, 0, 0.08);
    transform: translateY(-2px);
}

/* Panel tiplerine göre renk şemaları, CSS değişkeni (--panel-color) kullanılır */
.ab-panel-personal {
    background-color: rgba(var(--panel-color-rgb, 52, 152, 219), 0.02);
}
.ab-panel-personal .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 52, 152, 219), 0.05);
}
.ab-panel-personal .ab-panel-header h3 i {
    color: var(--panel-color, #3498db);
}

.ab-panel-corporate {
    background-color: rgba(var(--panel-color-rgb, 76, 175, 80), 0.02);
}
.ab-panel-corporate .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 76, 175, 80), 0.05);
}
.ab-panel-corporate .ab-panel-header h3 i {
    color: var(--panel-color, #4caf50);
}

.ab-panel-family {
    background-color: rgba(var(--panel-color-rgb, 255, 152, 0), 0.02);
}
.ab-panel-family .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 255, 152, 0), 0.05);
}
.ab-panel-family .ab-panel-header h3 i {
    color: var(--panel-color, #ff9800);
}

.ab-panel-vehicle {
    background-color: rgba(var(--panel-color-rgb, 231, 76, 60), 0.02);
}
.ab-panel-vehicle .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 231, 76, 60), 0.05);
}
.ab-panel-vehicle .ab-panel-header h3 i {
    color: var(--panel-color, #e74c3c);
}

.ab-panel-home {
    background-color: rgba(var(--panel-color-rgb, 156, 39, 176), 0.02);
}
.ab-panel-home .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 156, 39, 176), 0.05);
}
.ab-panel-home .ab-panel-header h3 i {
    color: var(--panel-color, #9c27b0);
}

/* Evcil Hayvan panel stili */
.ab-panel-pet {
    background-color: rgba(var(--panel-color-rgb, 233, 30, 99), 0.02);
}
.ab-panel-pet .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 233, 30, 99), 0.05);
}
.ab-panel-pet .ab-panel-header h3 i {
    color: var(--panel-color, #e91e63);
}

/* Dosya Arşivi panel stili */
.ab-panel-documents {
    background-color: rgba(var(--panel-color-rgb, 96, 125, 139), 0.02);
}
.ab-panel-documents .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 96, 125, 139), 0.05);
}
.ab-panel-documents .ab-panel-header h3 i {
    color: var(--panel-color, #607d8b);
}

/* Teklif panel stili */
.ab-panel-offer {
    background-color: rgba(var(--panel-color-rgb, 0, 188, 212), 0.02);
}
.ab-panel-offer .ab-panel-header {
    background-color: rgba(var(--panel-color-rgb, 0, 188, 212), 0.05);
}
.ab-panel-offer .ab-panel-header h3 i {
    color: var(--panel-color, #00bcd4);
}

.ab-full-panel {
    grid-column: 1 / -1;
}

.ab-panel-header {
    padding: 20px 24px 16px;
    background: linear-gradient(135deg, rgba(var(--panel-color-rgb, 52, 152, 219), 0.08) 0%, rgba(var(--panel-color-rgb, 52, 152, 219), 0.03) 100%);
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
}

.ab-panel-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 24px;
    right: 24px;
    height: 1px;
    background: linear-gradient(90deg, var(--panel-color, #ddd), transparent);
    opacity: 0.3;
}

.ab-panel-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    color: #1a1a1a;
    letter-spacing: -0.02em;
}

.ab-panel-header h3 i {
    font-size: 20px;
    color: var(--panel-color, #3498db);
    background: rgba(var(--panel-color-rgb, 52, 152, 219), 0.1);
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ab-panel-actions {
    display: flex;
    gap: 8px;
}

.ab-panel-body {
    padding: 24px;
}

/* Material Design Info Grid */
.ab-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 4px;
    max-width: 100%;
    overflow: hidden;
}

.ab-info-item {
    background: rgba(var(--panel-color-rgb, 52, 152, 219), 0.02);
    border-radius: 8px;
    padding: 16px;
    border: 1px solid rgba(var(--panel-color-rgb, 52, 152, 219), 0.08);
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 0;
    overflow: hidden;
}

.ab-info-item:hover {
    background: rgba(var(--panel-color-rgb, 52, 152, 219), 0.04);
    border-color: rgba(var(--panel-color-rgb, 52, 152, 219), 0.12);
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.ab-full-width {
    grid-column: 1 / -1;
}

.ab-info-label {
    font-weight: 600;
    font-size: 12px;
    color: #666;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.ab-info-value {
    font-size: 15px;
    font-weight: 500;
    color: #1a1a1a;
    line-height: 1.4;
    word-wrap: break-word;
    overflow-wrap: break-word;
    max-width: 100%;
}

.ab-info-value a {
    color: var(--panel-color, #2271b1);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 6px;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.ab-info-value a:hover {
    background: rgba(var(--panel-color-rgb, 52, 152, 219), 0.1);
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.no-value {
    color: #999;
    font-style: italic;
    font-size: 14px;
}

/* Teklif dosyası stilleri */
.ab-offer-file {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ab-offer-actions {
    display: flex;
    gap: 8px;
    margin-top: 5px;
}

/* Material Design Badge stilleri */
.ab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 12px;
    border-radius: 16px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.ab-badge:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.16);
}

.ab-badge i {
    margin-right: 4px;
    font-size: 10px;
}

.ab-badge-status-aktif {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    color: #2e7d32;
    border: 1px solid #81c784;
}

.ab-badge-status-pasif {
    background: linear-gradient(135deg, #f5f5f5 0%, #eeeeee 100%);
    color: #616161;
    border: 1px solid #bdbdbd;
}

.ab-badge-status-belirsiz {
    background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%);
    color: #f57c00;
    border: 1px solid #ffb74d;
}

.ab-badge-status-pending {
    background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
    color: #f57c00;
    border: 1px solid #ffb74d;
}

.ab-badge-status-in_progress {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    color: #1976d2;
    border: 1px solid #64b5f6;
}

.ab-badge-status-completed {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    color: #388e3c;
    border: 1px solid #81c784;
}

.ab-badge-status-cancelled {
    background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
    color: #d32f2f;
    border: 1px solid #ef5350;
}

.ab-badge-category-bireysel {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    color: #1565c0;
    border: 1px solid #42a5f5;
}

.ab-badge-category-kurumsal {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    color: #2e7d32;
    border: 1px solid #66bb6a;
}

/* Notlar Stilleri - Sticker/Baloncuk Tasarımı */
/* Sticker Not Kağıdı Stilleri */
.ab-sticky-note {
    position: relative;
    background: #ffd700;
    padding: 15px 20px 20px 20px;
    margin: 15px 10px;
    border-radius: 0 0 10px 10px;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    transform: rotate(-1deg);
    transition: all 0.3s ease;
    max-width: 300px;
    min-height: 120px;
    font-family: 'Comic Sans MS', cursive, sans-serif;
}

.ab-sticky-note:nth-child(even) {
    transform: rotate(1deg);
    background: #ffb3ba;
}

.ab-sticky-note:nth-child(odd) {
    transform: rotate(-1deg);
    background: #baffc9;
}

.ab-sticky-note:nth-child(3n) {
    background: #bae1ff;
    transform: rotate(0.5deg);
}

.ab-sticky-note:hover {
    transform: rotate(0deg) scale(1.05);
    z-index: 10;
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
}

.ab-sticky-note::before {
    content: '';
    position: absolute;
    top: -5px;
    left: 50%;
    transform: translateX(-50%);
    width: 40px;
    height: 20px;
    background: rgba(0, 0, 0, 0.1);
    border-radius: 20px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.sticky-note-header {
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px dashed rgba(0, 0, 0, 0.3);
}

.sticky-note-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 5px;
    font-size: 11px;
    color: #555;
    margin-bottom: 5px;
}

.sticky-note-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 5px;
}

.btn-delete-note {
    background: rgba(220, 53, 69, 0.2);
    border: 1px solid #dc3545;
    color: #dc3545;
    padding: 4px 6px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 11px;
    transition: all 0.2s ease;
}

.btn-delete-note:hover {
    background: #dc3545;
    color: white;
}

.sticky-note-user, .sticky-note-date {
    display: flex;
    align-items: center;
    gap: 3px;
}

.sticky-note-type-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: bold;
    text-transform: uppercase;
}

.sticky-note-type-positive {
    background: #28a745;
    color: white;
}

.sticky-note-type-neutral {
    background: #ffc107;
    color: #333;
}

.sticky-note-type-negative {
    background: #dc3545;
    color: white;
}

.sticky-note-content {
    font-size: 13px;
    line-height: 1.4;
    color: #333;
    margin: 10px 0;
    word-wrap: break-word;
}

.sticky-note-reason {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px dashed rgba(0, 0, 0, 0.2);
    font-size: 11px;
    color: #666;
}

/* Renk varyasyonları */
.sticky-note-positive {
    background: #d4edda !important;
}

.sticky-note-neutral {
    background: #fff3cd !important;
}

.sticky-note-negative {
    background: #f8d7da !important;
}

.ab-notes-list {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-top: 15px;
    max-width: 100%;
    box-sizing: border-box;
    align-items: flex-start;
    justify-content: flex-start;
}

.ab-empty-state {
    width: 100%;
    text-align: center;
    padding: 20px;
}

.ab-sticky-note {
    max-width: 100%;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.ab-note-item {
    position: relative;
    padding: 15px 20px;
    border-radius: 18px;
    margin-bottom: 15px;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    max-width: 85%;
}

.ab-note-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
}

/* Sağa ve sola yerleştirme efekti */
.ab-note-item:nth-child(even) {
    align-self: flex-end;
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    border: 2px solid #2196f3;
}

.ab-note-item:nth-child(odd) {
    align-self: flex-start;
    background: linear-gradient(135deg, #f1f8e9 0%, #dcedc8 100%);
    border: 2px solid #4caf50;
}

/* Tür bazlı renkler korunuyor */
.ab-note-positive {
    background: linear-gradient(135deg, #e8f5e8 0%, #c8e6c9 100%) !important;
    border: 2px solid #4caf50 !important;
}

.ab-note-negative {
    background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%) !important;
    border: 2px solid #f44336 !important;
}

.ab-note-neutral {
    background: linear-gradient(135deg, #fff8e1 0%, #fff3c4 100%) !important;
    border: 2px solid #ff9800 !important;
}

/* Baloncuk okları */
.ab-note-item:nth-child(even)::before {
    content: "";
    position: absolute;
    right: -10px;
    top: 20px;
    width: 0;
    height: 0;
    border-left: 10px solid;
    border-top: 10px solid transparent;
    border-bottom: 10px solid transparent;
    border-left-color: inherit;
}

.ab-note-item:nth-child(odd)::before {
    content: "";
    position: absolute;
    left: -10px;
    top: 20px;
    width: 0;
    height: 0;
    border-right: 10px solid;
    border-top: 10px solid transparent;
    border-bottom: 10px solid transparent;
    border-right-color: inherit;
}

.ab-note-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.ab-note-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    font-size: 12px;
    color: #666;
    font-weight: 500;
}

.ab-note-meta i {
    margin-right: 3px;
    color: #888;
}

.ab-note-content {
    margin-bottom: 10px;
    line-height: 1.6;
    font-size: 14px;
    color: #333;
    font-weight: 400;
}

.ab-note-reason {
    font-size: 12px;
    color: #666;
    padding-top: 8px;
    border-top: 1px dashed rgba(0,0,0,0.2);
    font-style: italic;
}

/* Badge Stilleri */
.ab-badge-note-positive {
    background-color: #e6ffed;
    color: #22863a;
}

.ab-badge-note-negative {
    background-color: #ffeef0;
    color: #cb2431;
}

.ab-badge-note-neutral {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-badge-priority-high {
    background-color: #ffeef0;
    color: #cb2431;
}

.ab-badge-priority-medium {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-badge-priority-low {
    background-color: #e6ffed;
    color: #22863a;
}

.ab-badge-expired {
    background-color: #ffeef0;
    color: #cb2431;
}

.ab-badge-expiring {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-badge-overdue {
    background-color: #ffeef0;
    color: #cb2431;
}

/* Çocuk listesi */
.ab-children-list {
    margin: 0;
    padding-left: 20px;
}

.ab-children-list li {
    margin-bottom: 5px;
}

/* Form stilleri */
.ab-add-note-form {
    background-color: #f9f9f9;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 15px;
    border: 1px solid #eee;
}

.ab-form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 15px;
}

.ab-form-group {
    flex: 1;
    min-width: 200px;
}

.ab-form-group.ab-full-width {
    flex-basis: 100%;
    width: 100%;
}

.ab-form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    font-size: 13px;
}

.ab-form-group select,
.ab-form-group textarea {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.ab-form-group textarea {
    min-height: 80px;
    resize: vertical;
}

.ab-form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 15px;
}

/* Tablo ve panel genişlik tutarlılığı */
.ab-panels {
    max-width: 100%;
    width: 100%;
    box-sizing: border-box;
}

.ab-panel, .ab-full-panel {
    max-width: 100%;
    width: 100%;
    box-sizing: border-box;
    overflow: hidden;
}

/* Tablo stilleri */
.ab-table-container {
    overflow-x: auto;
    margin-bottom: 15px;
    max-width: 100%;
    box-sizing: border-box;
}

.ab-crm-table {
    width: 100%;
    max-width: 100%;
    border-collapse: collapse;
    table-layout: auto;
}

.ab-crm-table th,
.ab-crm-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #eee;
    text-align: left;
    font-size: 13px;
}

.ab-crm-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #444;
}

.ab-crm-table tr:hover td {
    background-color: #f5f5f5;
}

.ab-crm-table tr:last-child td {
    border-bottom: none;
}

tr.expired td {
    background-color: #fff8f8;
}

tr.expiring-soon td {
    background-color: #fffbf0;
}

tr.overdue td {
    background-color: #fff8f8;
}

.ab-amount {
    font-weight: 600;
    color: #0366d6;
}

/* Pozitif/Negatif değerler */
.ab-positive {
    color: #22863a;
    font-weight: 500;
}

.ab-negative {
    color: #cb2431;
    font-weight: 500;
}

/* Boş durum gösterimi */
.ab-empty-state {
    text-align: center;
    padding: 20px;
    color: #666;
    font-style: italic;
}

.ab-empty-state p {
    margin-bottom: 15px;
}

.ab-empty-state i {
    font-size: 32px;
    color: #ddd;
    margin-bottom: 10px;
}

/* Material Design Buton stilleri */
.ab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    color: #495057;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.ab-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
    transition: left 0.5s;
}

.ab-btn:hover::before {
    left: 100%;
}

.ab-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    text-decoration: none;
    color: #495057;
}

.ab-btn-primary {
    background: linear-gradient(135deg, #4caf50 0%, #43a047 100%);
    border-color: #43a047;
    color: white;
    box-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
}

.ab-btn-primary:hover {
    background: linear-gradient(135deg, #43a047 0%, #388e3c 100%);
    color: white;
    box-shadow: 0 4px 16px rgba(76, 175, 80, 0.4);
}

.ab-btn-secondary {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border-color: #dee2e6;
    color: #6c757d;
}

.ab-btn-secondary:hover {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    color: #495057;
}

.ab-btn-warning {
    background: linear-gradient(135deg, #ff9800 0%, #fb8c00 100%);
    border-color: #fb8c00;
    color: white;
    box-shadow: 0 2px 8px rgba(255, 152, 0, 0.3);
}

.ab-btn-warning:hover {
    background: linear-gradient(135deg, #fb8c00 0%, #f57c00 100%);
    color: white;
    box-shadow: 0 4px 16px rgba(255, 152, 0, 0.4);
}

.ab-btn-danger {
    background: linear-gradient(135deg, #f44336 0%, #e53935 100%);
    border-color: #e53935;
    color: white;
    box-shadow: 0 2px 8px rgba(244, 67, 54, 0.3);
}

.ab-btn-danger:hover {
    background: linear-gradient(135deg, #e53935 0%, #d32f2f 100%);
    color: white;
    box-shadow: 0 4px 16px rgba(244, 67, 54, 0.4);
}

.ab-btn-info {
    background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
    border-color: #138496;
    color: white;
    box-shadow: 0 2px 8px rgba(23, 162, 184, 0.3);
}

.ab-btn-info:hover {
    background: linear-gradient(135deg, #138496 0%, #117a8b 100%);
    color: white;
    box-shadow: 0 4px 16px rgba(23, 162, 184, 0.4);
}

.ab-btn-sm {
    padding: 8px 16px;
    font-size: 12px;
    border-radius: 6px;
}

/* İşlem Butonları */
.ab-actions {
    display: flex;
    gap: 6px;
    justify-content: center;
}

.ab-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 4px;
    color: #555;
    background-color: #f8f9fa;
    border: 1px solid #eee;
    transition: all 0.2s;
    text-decoration: none;
}

.ab-action-btn:hover {
    background-color: #eee;
    color: #333;
    text-decoration: none;
}

/* Dosya Arşivi Stilleri */
.ab-files-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 20px;
}

.ab-file-card {
    border: 1px solid #eee;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    background-color: #fff;
    display: flex;
    flex-direction: column;
}

.ab-file-card-header {
    padding: 12px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.ab-file-meta {
    flex: 1;
    min-width: 0; /* Önemli: metin taşmasını önlemek için */
}

.ab-file-type-icon {
    font-size: 20px;
    color: #666;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #f8f9fa;
    border-radius: 4px;
}

.ab-file-type-icon .fa-file-pdf { color: #f44336; }
.ab-file-type-icon .fa-file-word { color: #2196f3; }
.ab-file-type-icon .fa-file-image { color: #4caf50; }
.ab-file-type-icon .fa-file-excel { color: #28a745; }
.ab-file-type-icon .fa-file-alt { color: #6c757d; }
.ab-file-type-icon .fa-file-archive { color: #ff9800; }

.ab-file-name {
    font-weight: 500;
    margin-bottom: 3px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 14px;
    color: #333;
}

.ab-file-info {
    font-size: 11px;
    color: #666;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.ab-file-info i {
    color: #999;
    font-size: 10px;
    margin-right: 2px;
}

.ab-file-preview {
    height: 180px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #f8f9fa;
    padding: 10px;
}

.ab-file-preview img {
    max-width: 100%;
    max-height: 180px;
    object-fit: contain;
}

.ab-file-icon-large {
    height: 180px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background-color: #f8f9fa;
    color: #666;
}

.ab-file-icon-large i {
    font-size: 64px;
    margin-bottom: 10px;
}

.ab-file-icon-large .fa-file-pdf { color: #f44336; }
.ab-file-icon-large .fa-file-word { color: #2196f3; }
.ab-file-icon-large .fa-file-image { color: #4caf50; }
.ab-file-icon-large .fa-file-excel { color: #28a745; }
.ab-file-icon-large .fa-file-alt { color: #6c757d; }
.ab-file-icon-large .fa-file-archive { color: #ff9800; }

.ab-file-icon-large span {
    font-size: 12px;
    color: #666;
    text-transform: uppercase;
}

.ab-file-description {
    padding: 10px 12px;
    font-size: 13px;
    color: #666;
    border-top: 1px solid #f0f0f0;
    background-color: #fafafa;
}

.ab-file-description p {
    margin: 0;
    font-style: italic;
}

.ab-file-card-actions {
    padding: 8px 12px;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: auto;
    border-top: 1px solid #f0f0f0;
    background-color: #f8f9fa;
}

.ab-file-delete-form {
    margin: 0;
}

/* Modal Stilleri */
.ab-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    overflow-y: auto;
    padding: 20px;
    box-sizing: border-box;
}

.ab-modal-content {
    position: relative;
    background-color: #fff;
    margin: 30px auto;
    max-width: 600px;
    border-radius: 6px;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
    animation: modalFadeIn 0.3s;
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

.ab-modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #eee;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.ab-modal-header h3 {
    margin: 0;
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-modal-header h3 i {
    color: #4caf50;
}

.ab-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    line-height: 1;
    padding: 0;
    cursor: pointer;
    color: #999;
}

.ab-modal-close:hover {
    color: #333;
}

.ab-modal-body {
    padding: 20px;
}

.ab-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #eee;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* File Upload Area için Modal Stilleri */
.ab-file-upload-area {
    border: 2px dashed #ddd;
    padding: 30px 20px;
    border-radius: 6px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    background-color: #fafafa;
    position: relative;
}

.ab-file-upload-area:hover, .ab-file-upload-area.ab-drag-over {
    border-color: #4caf50;
    background-color: #f0f8f1;
}

.ab-file-upload-icon {
    font-size: 36px;
    color: #999;
    margin-bottom: 10px;
}

.ab-file-upload-area:hover .ab-file-upload-icon {
    color: #4caf50;
}

.ab-file-upload-text {
    font-size: 15px;
    font-weight: 500;
    color: #555;
}

.ab-file-upload-info {
    font-size: 12px;
    color: #888;
    margin-top: 5px;
}

.ab-file-upload {
    position: absolute;
    width: 100%;
    height: 100%;
    top: 0;
    left: 0;
    opacity: 0;
    cursor: pointer;
}

.ab-file-upload-container {
    position: relative;
    margin-bottom: 15px;
}

.ab-file-preview-container {
    margin-top: 15px;
}

.ab-file-warning {
    margin-bottom: 15px;
    padding: 10px;
    background-color: #fffde7;
    border-left: 3px solid #ffc107;
    color: #856404;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
    border-radius: 4px;
}

.ab-file-warning i {
    color: #ffc107;
}

.ab-selected-files {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 15px;
}

.ab-file-item-preview {
    position: relative;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 12px;
    background-color: #fff;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.ab-file-name-preview {
    font-weight: 500;
    margin-bottom: 8px;
    word-break: break-all;
    color: #333;
}

.ab-file-size-preview {
    font-size: 11px;
    color: #777;
    margin-bottom: 8px;
}

.ab-file-desc-input {
    margin-top: 10px;
}

.ab-file-desc-input input {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 12px;
}

.ab-file-remove {
    position: absolute;
    top: 8px;
    right: 8px;
    background-color: #f44336;
    color: white;
    border: none;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 10px;
    transition: all 0.2s;
}

.ab-file-remove:hover {
    background-color: #d32f2f;
}

.ab-file-icon-preview {
    font-size: 24px;
    margin-right: 10px;
    color: #666;
}

.ab-file-icon-pdf { color: #f44336; }
.ab-file-icon-word { color: #2196f3; }
.ab-file-icon-image { color: #4caf50; }
.ab-file-icon-excel { color: #28a745; }
.ab-file-icon-alt { color: #6c757d; }
.ab-file-icon-archive { color: #ff9800; }

.ab-file-icon-preview i {
    margin-bottom: 10px;
}

/* İlerleme Çubuğu */
.ab-progress-container {
    margin-top: 20px;
}

.ab-progress-bar {
    height: 8px;
    background-color: #f0f0f0;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 8px;
}

.ab-progress-fill {
    height: 100%;
    background-color: #4caf50;
    width: 0;
    transition: width 0.3s;
}

.ab-progress-text {
    font-size: 12px;
    color: #666;
    text-align: center;
}

/* Ajax Cevap Konteyneri */
#ajax-response-container {
    margin-bottom: 20px;
}

/* Lightbox */
.ab-lightbox {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.85);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.ab-lightbox-content {
    position: relative;
    max-width: 90%;
    max-height: 90%;
    border-radius: 6px;
    overflow: hidden;
    background-color: #fff;
    padding: 5px;
}

.ab-lightbox-content img {
    max-width: 100%;
    max-height: calc(90vh - 60px);
    display: block;
    object-fit: contain;
}

.ab-lightbox-caption {
    padding: 10px;
    text-align: center;
    color: #333;
    font-weight: 500;
    font-size: 14px;
    background-color: #f8f9fa;
    border-top: 1px solid #eee;
}

.ab-lightbox-close {
    position: absolute;
    top: 0;
    right: 0;
    font-size: 24px;
    color: white;
    cursor: pointer;
    width: 32px;
    height: 32px;
    background-color: rgba(0, 0, 0, 0.5);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 10px;
}

.ab-lightbox-close:hover {
    background-color: rgba(0, 0, 0, 0.8);
}

/* Material Design Responsive */
@media (max-width: 1200px) {
    .ab-panels {
        gap: 20px;
    }
    
    .ab-panel-body {
        padding: 20px;
    }
    
    .ab-panel-header {
        padding: 16px 20px 12px;
    }
}

@media (max-width: 992px) {
    .ab-panels {
        grid-template-columns: 1fr;
        gap: 16px;
        padding: 0 20px !important;
    }
    
    .ab-panel {
        width: 100%;
        max-width: 100%;
    }
    
    .ab-customer-header {
        flex-direction: column;
        align-items: flex-start;
        padding: 20px;
        gap: 16px;
    }
    
    .ab-customer-actions {
        width: 100%;
        justify-content: flex-start;
    }
    
    .ab-info-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .ab-files-gallery {
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 16px;
    }
    
    .ab-modal-content {
        max-width: 95%;
        margin: 10px auto;
    }
}

@media (max-width: 768px) {
    .ab-customer-title h1 {
        font-size: 24px;
    }
    
    .ab-customer-title h1 i {
        width: 40px;
        height: 40px;
        font-size: 18px;
    }
    
    .ab-panel-header h3 {
        font-size: 16px;
    }
    
    .ab-panel-header h3 i {
        width: 32px;
        height: 32px;
        font-size: 16px;
    }
    
    .ab-panel-body {
        padding: 16px;
    }
    
    .ab-panel-header {
        padding: 12px 16px 8px;
    }
    
    .ab-btn {
        padding: 10px 16px;
        font-size: 13px;
    }
    
    .ab-btn-sm {
        padding: 6px 12px;
        font-size: 11px;
    }
    
    .ab-info-item {
        padding: 12px;
    }
    
    .ab-customer-header {
        padding: 16px;
    }
}

@media (max-width: 576px) {
    .ab-customer-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .ab-customer-actions {
        flex-direction: column;
        width: 100%;
    }
    
    .ab-btn {
        width: 100%;
        justify-content: center;
    }
    
    .ab-panel-actions {
        flex-direction: column;
        gap: 6px;
    }
    
    .ab-files-gallery {
        grid-template-columns: 1fr;
    }
    
    .ab-modal-content {
        max-width: 98%;
        margin: 5px auto;
        border-radius: 8px;
    }
    
    .ab-table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
}
}

@media (max-width: 768px) {
    .ab-customer-header {
        flex-direction: column;
    }

    .ab-customer-actions {
        width: 100%;
        justify-content: flex-start;
    }

    .ab-info-grid {
        grid-template-columns: 1fr;
    }

    .ab-form-row {
        flex-direction: column;
        gap: 10px;
    }

    .ab-form-group {
        width: 100%;
    }

    .ab-notes-list {
        flex-direction: column;
    }

    .ab-crm-table {
        font-size: 12px;
    }

    .ab-crm-table th,
    .ab-crm-table td {
        padding: 8px 6px;
    }
    
    .ab-files-gallery {
        grid-template-columns: 1fr;
    }
    
    .ab-selected-files {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 576px) {
    .ab-customer-title h1 {
        font-size: 20px;
    }

    .ab-btn {
        padding: 6px 10px;
        font-size: 12px;
    }

    .ab-action-btn {
        width: 26px;
        height: 26px;
    }
    
    .ab-file-card-actions {
        flex-direction: column;
    }
    
    .ab-file-card-actions .ab-btn {
        width: 100%;
        justify-content: center;
    }
    
    .ab-modal-header, .ab-modal-body, .ab-modal-footer {
        padding: 12px;
    }
    
    .ab-modal-footer {
        flex-direction: column;
    }
    
    .ab-modal-footer .ab-btn {
        width: 100%;
        justify-content: center;
    }
}

/* Modern Quote Form Styles (txt dosyasından) */
.modern-quote-form {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 16px;
    padding: 0;
    margin-bottom: 30px;
    box-shadow: 0 20px 40px rgba(102, 126, 234, 0.15);
    overflow: hidden;
    position: relative;
}

.modern-quote-form::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0.05) 100%);
    pointer-events: none;
}

.quote-form-header {
    background: rgba(255, 255, 255, 0.95);
    padding: 24px 30px;
    display: flex;
    align-items: center;
    gap: 20px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    backdrop-filter: blur(10px);
}

.quote-form-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 24px;
    box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
}

.quote-form-title h4 {
    margin: 0 0 5px 0;
    font-size: 20px;
    font-weight: 600;
    color: #2d3748;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.quote-form-title p {
    margin: 0;
    color: #718096;
    font-size: 14px;
    line-height: 1.4;
}

.modern-form-container {
    background: white;
    padding: 30px;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

.form-field {
    position: relative;
}

.form-field.full-width {
    grid-column: 1 / -1;
}

.modern-label {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    font-weight: 600;
    font-size: 14px;
    color: #4a5568;
}

.modern-label i {
    color: #667eea;
    width: 16px;
    text-align: center;
}

.modern-input {
    width: 100%;
    padding: 14px 18px;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 15px;
    background: #fafafa;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-sizing: border-box;
}

.modern-input:focus {
    outline: none;
    border-color: #667eea;
    background: white;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    transform: translateY(-1px);
}

.modern-input:hover {
    border-color: #cbd5e0;
    background: white;
}

.modern-select {
    cursor: pointer;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
    background-position: right 12px center;
    background-repeat: no-repeat;
    background-size: 16px;
    padding-right: 40px;
}

.modern-textarea {
    resize: vertical;
    min-height: 100px;
    font-family: inherit;
    line-height: 1.5;
}

.form-actions {
    display: flex;
    gap: 16px;
    justify-content: flex-end;
    padding-top: 24px;
    border-top: 1px solid #f1f5f9;
    margin-top: 30px;
}

.btn-large {
    padding: 16px 32px;
    font-size: 15px;
    font-weight: 600;
    border-radius: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
    border: none;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    text-decoration: none;
    min-width: 160px;
    justify-content: center;
}

.btn-primary.btn-large {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.btn-primary.btn-large:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
}

.btn-secondary.btn-large {
    background: #f8f9fa;
    color: #495057;
    border: 2px solid #e9ecef;
}

.btn-secondary.btn-large:hover {
    background: #e9ecef;
    transform: translateY(-1px);
}

.btn-small {
    padding: 4px 8px;
    font-size: 12px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    margin-left: 8px;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-outline {
    background: transparent;
    color: #667eea;
    border: 1px solid #667eea;
}

/* Responsive Design for Quote Form */
@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .quote-form-header {
        padding: 20px;
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .quote-form-icon {
        width: 50px;
        height: 50px;
        font-size: 20px;
    }
    
    .modern-form-container {
        padding: 20px;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .btn-large {
        width: 100%;
    }
}

/* Tek Müşteri Notu Stil */
.single-customer-note-display, .single-customer-note-empty {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.single-customer-note-display .note-content {
    font-size: 14px;
    line-height: 1.6;
    color: #495057;
    white-space: pre-line;
    word-wrap: break-word;
}

.single-customer-note-empty {
    text-align: center;
    color: #6c757d;
    font-style: italic;
}

.single-customer-note-empty i {
    margin-right: 8px;
    color: #adb5bd;
}

.customer-note-delete-form {
    margin-top: 16px;
}

.customer-note-delete-form .ab-notice {
    margin-bottom: 16px;
}
    border-radius: 8px;
    padding: 16px;
    position: relative;
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

/* Responsive tasarım */
@media (max-width: 768px) {
    .ab-panels {
        grid-template-columns: 1fr;
    }
    
    .ab-customer-header {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Görüşme Notları - Not ekleme formu aç/kapat
    $('#toggle-note-form').on('click', function() {
        $('#customer-notes-section .ab-add-note-form').slideToggle();
        $('#note_content').focus();
    });

    // Görüşme Notları - Vazgeç butonu düzeltildi
    $('#cancel-note-form').on('click', function() {
        $('#customer-notes-section .ab-add-note-form').slideUp();
        $('#note_content').val('');
        $('#note_type').val('');
        $('#rejection_reason').val('');
        $('#rejection_reason_container').slideUp();
        $('#rejection_reason').prop('required', false);
    });

    // Görüşme Notları - Not türü değiştiğinde, olumsuz olma sebebi göster/gizle
    $('#note_type').on('change', function() {
        if ($(this).val() === 'negative') {
            $('#rejection_reason_container').slideDown();
            $('#rejection_reason').prop('required', true);
        } else {
            $('#rejection_reason_container').slideUp();
            $('#rejection_reason').prop('required', false);
        }
    });
    
    // Müşteri Notu (Tek Not) - Düzenleme butonları
    $('#add-customer-note-btn, #edit-customer-note-btn').on('click', function() {
        $('#customer-note-display, #customer-note-empty').hide();
        $('#customer-note-form').slideDown();
        $('#customer_notes_content').focus();
        $(this).closest('.ab-panel-actions').hide();
    });
    
    // Müşteri Notu (Tek Not) - İptal butonu
    $('#cancel-customer-note').on('click', function() {
        $('#customer-note-form').slideUp();
        $('#customer-note-display, #customer-note-empty').show();
        $('.ab-panel-actions').show();
    });
    
    // Müşteri Notu (Tek Not) - Silme butonu
    $('#delete-customer-note-btn').on('click', function() {
        $('#customer-note-display').hide();
        $('#customer-note-delete-form').slideDown();
        $(this).closest('.ab-panel-actions').hide();
    });
    
    // Müşteri Notu (Tek Not) - Silme iptal butonu
    $('#cancel-customer-note-delete').on('click', function() {
        $('#customer-note-delete-form').slideUp();
        $('#customer-note-display').show();
        $('.ab-panel-actions').show();
    });
    
    // Panel renklerini CSS değişkenlerine dönüştür
    function hexToRgb(hex) {
        var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return result ? parseInt(result[1], 16) + ',' + parseInt(result[2], 16) + ',' + parseInt(result[3], 16) : null;
    }
    
    // Panel renklerini uygula
    $('.ab-panel-personal').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($personal_color); ?>'));
    $('.ab-panel-corporate').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($corporate_color); ?>'));
    $('.ab-panel-family').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($family_color); ?>'));
    $('.ab-panel-vehicle').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($vehicle_color); ?>'));
    $('.ab-panel-home').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($home_color); ?>'));
    $('.ab-panel-pet').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($pet_color); ?>'));
    $('.ab-panel-documents').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($doc_color); ?>'));
    $('.ab-panel-offer').css('--panel-color-rgb', hexToRgb('<?php echo esc_js($offer_color); ?>'));
    
    // Teklif sonlandır butonu
    $('#btn-finalize-offer').on('click', function(e) {
        e.preventDefault();
        $('#toggle-note-form').click(); // Not formunu aç
        $('#note_type').val('negative').trigger('change'); // Olumsuz olarak seç
        $('#rejection_reason').val('existing_policy'); // Varsayılan sebep
        $('html, body').animate({
            scrollTop: $('#customer-notes-section').offset().top - 50
        }, 500);
    });
    
    // Resim önizlemeleri için lightbox
    $(document).on('click', '.ab-file-preview img', function() {
        var imgSrc = $(this).attr('src');
        var imgTitle = $(this).attr('alt');
        
        $('body').append('<div class="ab-lightbox"><div class="ab-lightbox-content"><img src="' + imgSrc + 
                        '" alt="' + imgTitle + '"><div class="ab-lightbox-caption">' + imgTitle + 
                        '</div><div class="ab-lightbox-close">&times;</div></div></div>');
        
        $('.ab-lightbox').fadeIn(300);
    });
    
    // Lightbox kapat
    $(document).on('click', '.ab-lightbox-close, .ab-lightbox', function(e) {
        if (e.target === this) {
            $('.ab-lightbox').fadeOut(300, function() {
                $(this).remove();
            });
        }
    });
    
    // Modal Açma Kapama İşlemleri
    function openModal(modalId) {
        $('#' + modalId).fadeIn(300);
        $('body').addClass('modal-open');
    }
    
    function closeModal(modalId) {
        $('#' + modalId).fadeOut(300);
        $('body').removeClass('modal-open');
    }
    
    // Dosya Yükleme Modal
    $('#open-file-upload-modal, .open-file-upload-modal').on('click', function() {
        openModal('file-upload-modal');
    });
    
    $('.ab-modal-close, .ab-modal-close-btn').on('click', function() {
        closeModal($(this).closest('.ab-modal').attr('id'));
    });
    
    // Kapat butonu için olay
    $('#close-upload-modal-btn').on('click', function() {
        closeModal('file-upload-modal');
        window.location.reload();
    });
    
    // ESC tuşu ile modalı kapat
    $(document).keydown(function(e) {
        if (e.keyCode === 27) { // ESC
            $('.ab-modal').fadeOut(300);
            $('body').removeClass('modal-open');
        }
    });
    
    // Modal dışına tıklayınca kapat
    $('.ab-modal').on('click', function(e) {
        if (e.target === this) {
            closeModal($(this).attr('id'));
        }
    });
    
    // Dosya yükleme alanı sürükle bırak - Modal içinde
    var fileUploadAreaModal = document.getElementById('file-upload-area-modal');
    var fileInputModal = document.getElementById('customer_files_modal');
    
    if (fileUploadAreaModal && fileInputModal) {
        fileUploadAreaModal.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileInputModal.click();
        });
        
        fileUploadAreaModal.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileUploadAreaModal.classList.add('ab-drag-over');
        });
        
        fileUploadAreaModal.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileUploadAreaModal.classList.remove('ab-drag-over');
        });
        
        fileUploadAreaModal.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileUploadAreaModal.classList.remove('ab-drag-over');
            
            var files = e.dataTransfer.files;
            
            // Dosya sayısı kontrolü
            if (files.length > 5) {
                showFileCountWarningModal();
                // Sadece ilk 5 dosyayı al
                var maxFiles = [];
                for (var i = 0; i < 5; i++) {
                    maxFiles.push(files[i]);
                }
                
                // FileList kopyalanamaz, o yüzden Data Transfer kullanarak yeni bir dosya listesi oluştur
                const dataTransfer = new DataTransfer();
                maxFiles.forEach(file => dataTransfer.items.add(file));
                fileInputModal.files = dataTransfer.files;
            } else {
                fileInputModal.files = files;
            }
            
            updateFilePreviewModal();
        });
        
        // Dosya seçildiğinde önizleme göster
        fileInputModal.addEventListener('change', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Dosya sayısı kontrolü
            if (this.files.length > 5) {
                showFileCountWarningModal();
                
                // Sadece ilk 5 dosyayı al
                const dataTransfer = new DataTransfer();
                for (var i = 0; i < 5; i++) {
                    dataTransfer.items.add(this.files[i]);
                }
                this.files = dataTransfer.files;
            } else {
                hideFileCountWarningModal();
            }
            
            updateFilePreviewModal();
        });
    }
    
    function showFileCountWarningModal() {
        $('#file-count-warning-modal').slideDown();
    }
    
    function hideFileCountWarningModal() {
        $('#file-count-warning-modal').slideUp();
    }
    
    function updateFilePreviewModal() {
        var filesContainer = document.getElementById('selected-files-container-modal');
        filesContainer.innerHTML = '';
        
        var files = document.getElementById('customer_files_modal').files;
        var allowedTypes = <?php echo json_encode($allowed_mime_types); ?>;
        var allowedExtensions = <?php echo json_encode($allowed_file_types); ?>;
        var maxSize = 5 * 1024 * 1024; // 5MB
        
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var fileSize = formatFileSize(file.size);
            var fileType = file.type;
            var fileExt = getFileExtFromType(fileType);
            var isValidType = allowedTypes.includes(fileType);
            var isValidSize = file.size <= maxSize;
            
            var itemDiv = document.createElement('div');
            itemDiv.className = 'ab-file-item-preview' + (!isValidType || !isValidSize ? ' ab-file-invalid' : '');
            
            var iconClass = 'fa-file';
            if (fileType === 'application/pdf') iconClass = 'fa-file-pdf ab-file-icon-pdf';
            else if (fileType === 'application/msword' || fileType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') iconClass = 'fa-file-word ab-file-icon-word';
            else if (fileType === 'application/vnd.ms-excel' || fileType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') iconClass = 'fa-file-excel ab-file-icon-excel';
            else if (fileType === 'text/plain') iconClass = 'fa-file-alt ab-file-icon-alt';
            else if (fileType === 'application/zip') iconClass = 'fa-file-archive ab-file-icon-archive';
            else if (fileType.startsWith('image/')) iconClass = 'fa-file-image ab-file-icon-image';
            
            var content = '<div class="ab-file-icon-preview"><i class="fas ' + iconClass + '"></i></div>';
            content += '<div class="ab-file-name-preview">' + file.name + '</div>';
            content += '<div class="ab-file-size-preview">' + fileSize + '</div>';
            
            if (!isValidType) {
                content += '<div class="ab-file-error"><i class="fas fa-exclamation-triangle"></i> Geçersiz dosya formatı. Sadece ' + allowedExtensions.map(ext => ext.toUpperCase()).join(', ') + ' dosyaları yüklenebilir.</div>';
            } else if (!isValidSize) {
                content += '<div class="ab-file-error"><i class="fas fa-exclamation-triangle"></i> Dosya boyutu çok büyük. Maksimum 5MB olmalıdır.</div>';
            } else {
                content += '<div class="ab-file-desc-input">';
                content += '<input type="text" name="file_descriptions[]" placeholder="Dosya açıklaması (isteğe bağlı)" class="ab-input">';
                content += '</div>';
            }
            
            var removeBtn = document.createElement('button');
            removeBtn.className = 'ab-file-remove';
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            removeBtn.dataset.index = i;
            removeBtn.addEventListener('click', function(e) {
                removeSelectedFileModal(parseInt(this.dataset.index));
            });
            
            itemDiv.innerHTML = content;
            itemDiv.appendChild(removeBtn);
            
            filesContainer.appendChild(itemDiv);
        }
    }
    
    function removeSelectedFileModal(index) {
        const dt = new DataTransfer();
        const files = document.getElementById('customer_files_modal').files;
        
        for (let i = 0; i < files.length; i++) {
            if (i !== index) dt.items.add(files[i]);
        }
        
        document.getElementById('customer_files_modal').files = dt.files;
        hideFileCountWarningModal();
        updateFilePreviewModal();
    }
    
    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        else if (bytes < 1048576) return (bytes / 1024).toFixed(2) + ' KB';
        else return (bytes / 1048576).toFixed(2) + ' MB';
    }
    
    function getFileExtFromType(type) {
        switch (type) {
            case 'image/jpeg':
            case 'image/jpg':
                return 'jpg';
            case 'image/png':
                return 'png';
            case 'application/pdf':
                return 'pdf';
            case 'application/msword':
                return 'doc';
            case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                return 'docx';
            case 'application/vnd.ms-excel':
                return 'xls';
            case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                return 'xlsx';
            case 'text/plain':
                return 'txt';
            case 'application/zip':
                return 'zip';
            default:
                return '';
        }
    }
    
    // AJAX Dosya Yükleme
    $('#upload-files-btn').on('click', function() {
        var fileInput = document.getElementById('customer_files_modal');
        var files = fileInput.files;
        
        if (files.length === 0) {
            showResponse('Lütfen yüklenecek dosyaları seçin.', 'error');
            return;
        }
        
        var formData = new FormData($('#file-upload-form')[0]);
        
        // İlerleme çubuğunu göster
        $('.ab-progress-container').show();
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        var percent = Math.round((e.loaded / e.total) * 100);
                        $('.ab-progress-fill').css('width', percent + '%');
                        $('.ab-progress-text').text('Yükleniyor... ' + percent + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                try {
                    var data = JSON.parse(response);
                    
                    if (data.success) {
                        showResponse('Yükleme Tamamlandı.', 'success');
                        updateFilesGallery(data.files);
                        
                        // Formu sıfırla
                        $('#file-upload-form')[0].reset();
                        $('#selected-files-container-modal').empty();
                    } else {
                        showResponse(data.message, 'error');
                    }
                } catch (e) {
                    showResponse('Bir hata oluştu.', 'error');
                }
                
                // İlerleme çubuğunu sıfırla ve gizle
                $('.ab-progress-fill').css('width', '0%');
                $('.ab-progress-text').text('Yükleniyor... 0%');
                $('.ab-progress-container').hide();
            },
            error: function() {
                showResponse('Sunucu hatası. Lütfen daha sonra tekrar deneyin.', 'error');
                
                // İlerleme çubuğunu sıfırla ve gizle
                $('.ab-progress-fill').css('width', '0%');
                $('.ab-progress-text').text('Yükleniyor... 0%');
                $('.ab-progress-container').hide();
            }
        });
    });
    
    // Dosya Silme İşlemi
    $(document).on('click', '.delete-file', function() {
        var fileId = $(this).data('file-id');
        $('#delete_file_id').val(fileId);
        openModal('file-delete-confirm-modal');
    });
    
    $('#confirm-delete-btn').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        var fileId = $('#delete_file_id').val();
        
        if (!fileId) {
            showResponse('Silinecek dosya seçilmedi.', 'error');
            return;
        }
        
        // Butonu devre dışı bırak
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Siliniyor...');
        
        var formData = new FormData($('#file-delete-form')[0]);
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                try {
                    // Clean response if needed
                    var cleanResponse = response.trim();
                    if (cleanResponse.charAt(0) !== '{') {
                        // Find the first { to handle potential PHP warnings/output before JSON
                        var jsonStart = cleanResponse.indexOf('{');
                        if (jsonStart !== -1) {
                            cleanResponse = cleanResponse.substring(jsonStart);
                        }
                    }
                    
                    var data = JSON.parse(cleanResponse);
                    
                    if (data.success) {
                        showResponse(data.message, 'success');
                        removeFileFromGallery(fileId);
                        
                        // Modal'ı kapat
                        setTimeout(function() {
                            closeModal('file-delete-confirm-modal');
                        }, 500);
                    } else {
                        showResponse(data.message, 'error');
                    }
                } catch (e) {
                    console.error('Response parsing error:', e);
                    console.log('Raw response:', response);
                    showResponse('Dosya silme işlemi tamamlandi ancak sayfa güncelleme hatası oluştu.', 'warning');
                    // Try to remove the file anyway
                    removeFileFromGallery(fileId);
                    setTimeout(function() {
                        closeModal('file-delete-confirm-modal');
                    }, 500);
                }
                
                // Butonu tekrar etkinleştir
                $btn.prop('disabled', false).html('<i class="fas fa-trash"></i> Sil');
            },
            error: function() {
                showResponse('Sunucu hatası. Lütfen daha sonra tekrar deneyin.', 'error');
                
                // Butonu tekrar etkinleştir
                $btn.prop('disabled', false).html('<i class="fas fa-trash"></i> Sil');
            }
        });
    });
    
    function showResponse(message, type) {
        $('#ajax-response-container').html('<div class="ab-notice ab-' + type + '">' + message + '</div>');
        
        setTimeout(function() {
            $('#ajax-response-container .ab-notice').fadeOut(500);
        }, 5000);
    }
    
    function updateFilesGallery(files) {
        var container = $('#files-container');
        
        if (files.length > 0) {
            // Boş durum mesajını kaldır
            container.find('.ab-empty-state').remove();
            
            // Dosya galerisi yoksa oluştur
            if (container.find('.ab-files-gallery').length === 0) {
                container.append('<div class="ab-files-gallery"></div>');
            }
            
            var gallery = container.find('.ab-files-gallery');
            
            // Dosyaları ekle
            files.forEach(function(file) {
                var fileCard = createFileCard(file);
                gallery.prepend(fileCard); // Yeni dosyaları başa ekle
            });
        }
    }
    
    function createFileCard(file) {
        var fileCard = $('<div class="ab-file-card" data-file-id="' + file.id + '"></div>');
        
        var header = $('<div class="ab-file-card-header"></div>');
        var typeIcon = $('<div class="ab-file-type-icon"><i class="fas ' + getIconClassForType(file.type) + '"></i></div>');
        var meta = $('<div class="ab-file-meta"></div>');
        meta.append('<div class="ab-file-name">' + file.name + '</div>');
        meta.append('<div class="ab-file-info"><span><i class="fas fa-calendar-alt"></i> ' + file.date + '</span><span><i class="fas fa-weight"></i> ' + file.size + '</span></div>');
        header.append(typeIcon).append(meta);
        fileCard.append(header);
        
        if (file.type === 'jpg' || file.type === 'jpeg' || file.type === 'png') {
            fileCard.append('<div class="ab-file-preview"><img src="' + file.path + '" alt="' + file.name + '"></div>');
        } else {
            fileCard.append('<div class="ab-file-icon-large"><i class="fas ' + getIconClassForType(file.type) + '"></i><span>.' + file.type + '</span></div>');
        }
        
        if (file.description) {
            fileCard.append('<div class="ab-file-description"><p>' + file.description + '</p></div>');
        }
        
        var actions = $('<div class="ab-file-card-actions"></div>');
        actions.append('<a href="' + file.path + '" target="_blank" class="ab-btn ab-btn-sm ab-btn-primary"><i class="fas ' + (file.type === 'jpg' || file.type === 'jpeg' || file.type === 'png' ? 'fa-eye' : 'fa-download') + '"></i> ' + (file.type === 'jpg' || file.type === 'jpeg' || file.type === 'png' ? 'Görüntüle' : 'İndir') + '</a>');
        actions.append('<button type="button" class="ab-btn ab-btn-sm ab-btn-danger delete-file" data-file-id="' + file.id + '"><i class="fas fa-trash"></i> Sil</button>');
        
        fileCard.append(actions);
        
        return fileCard;
    }
    
    function getIconClassForType(type) {
        switch (type) {
            case 'pdf':
                return 'fa-file-pdf';
            case 'doc':
            case 'docx':
                return 'fa-file-word';
            case 'jpg':
            case 'jpeg':
            case 'png':
                return 'fa-file-image';
            case 'xls':
            case 'xlsx':
                return 'fa-file-excel';
            case 'txt':
                return 'fa-file-alt';
            case 'zip':
                return 'fa-file-archive';
            default:
                return 'fa-file';
        }
    }
    
    function removeFileFromGallery(fileId) {
        var fileCard = $('.ab-file-card[data-file-id="' + fileId + '"]');
        fileCard.fadeOut(300, function() {
            $(this).remove();
            
            // Eğer daha dosya kalmadıysa boş durum mesajı göster
            if ($('.ab-files-gallery').children().length === 0) {
                $('.ab-files-gallery').remove();
                $('#files-container').html(`
                    <div class="ab-empty-state">
                        <p><i class="fas fa-file-upload"></i><br>Henüz yüklenmiş dosya bulunmuyor.</p>
                        <button type="button" class="ab-btn open-file-upload-modal">
                            <i class="fas fa-plus"></i> Dosya Yükle
                        </button>
                    </div>
                `);
                
                // Dosya yükleme butonu tıklama olayını tekrar ekle
                $('.open-file-upload-modal').on('click', function() {
                    openModal('file-upload-modal');
                });
            }
        });
    }
    
    // Hatırlatma görevi oluşturma fonksiyonu
    window.createReminderTask = function(customerId) {
        if (confirm('Bu müşteri için hatırlatma görevi oluşturulsun mu?')) {
            // Hidden form oluştur ve submit et
            var form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            var actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'create_reminder_task';
            
            var customerIdInput = document.createElement('input');
            customerIdInput.type = 'hidden';
            customerIdInput.name = 'customer_id';
            customerIdInput.value = customerId;
            
            form.appendChild(actionInput);
            form.appendChild(customerIdInput);
            document.body.appendChild(form);
            form.submit();
        }
    };

    // Quote toggle functionality
    window.toggleOfferStatus = function(newStatus) {
        if (newStatus === 1) {
            // Show quote form
            const quoteFormSection = document.getElementById('quote-form-section');
            if (quoteFormSection) {
                quoteFormSection.style.display = 'block';
                quoteFormSection.scrollIntoView({ behavior: 'smooth' });
                
                // Set default expiry date to 1 month from today
                const today = new Date();
                const nextMonth = new Date(today.getFullYear(), today.getMonth() + 1, today.getDate());
                const expDateField = document.getElementById('offer_expiry_date');
                if (expDateField) {
                    expDateField.value = nextMonth.toISOString().split('T')[0];
                }
            } else {
                console.error('quote-form-section element not found');
                alert('Teklif formu yüklenemedi. Sayfa yenilenerek tekrar deneyin.');
            }
        } else {
            // Change status to No without showing form
            if (confirm('Teklif durumunu "Hayır" olarak değiştirmek istediğinizden emin misiniz?')) {
                updateOfferStatusDirectly(0);
            }
        }
    };
    
    window.cancelQuoteForm = function() {
        const quoteFormSection = document.getElementById('quote-form-section');
        if (quoteFormSection) {
            quoteFormSection.style.display = 'none';
            // Reset the form
            const form = quoteFormSection.querySelector('form');
            if (form) {
                form.reset();
            }
        }
    };
    
    function updateOfferStatusDirectly(status) {
        const formData = new FormData();
        formData.append('action', 'toggle_offer_status');
        formData.append('customer_id', '<?php echo $customer->id; ?>');
        formData.append('has_offer', status);
        formData.append('nonce', '<?php echo wp_create_nonce("toggle_offer_status"); ?>');
        
        fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert('Hata: ' + (data.data || 'Bilinmeyen hata'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Bir hata oluştu: ' + error.message);
        });
    }

    // Sonlandırma modal fonksiyonları
    window.showTerminateModal = function(customerId) {
        var modal = document.getElementById('terminate-modal');
        modal.style.display = 'flex'; // flex kullanarak center align
    };
    
    window.closeTerminateModal = function() {
        document.getElementById('terminate-modal').style.display = 'none';
        document.getElementById('terminate_reason').value = '';
    };
    
    // Modal dış tıklama ile kapatma
    document.getElementById('terminate-modal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeTerminateModal();
        }
    });
});

// Eski not sisteminden yeni sisteme göç etme fonksiyonu
function migrateOldNote() {
    if (confirm('Eski sistemdeki notu yeni sisteme aktarmak istediğinizden emin misiniz?')) {
        // AJAX ile eski notu yeni sisteme aktar
        var formData = new FormData();
        formData.append('action', 'migrate_old_customer_note');
        formData.append('customer_id', '<?php echo $customer_id; ?>');
        formData.append('nonce', '<?php echo wp_create_nonce("migrate_old_note"); ?>');
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        }).then(response => {
            if (response.ok) {
                window.location.reload();
            } else {
                alert('Not aktarma işlemi sırasında bir hata oluştu.');
            }
        }).catch(error => {
            console.error('Error:', error);
            alert('Not aktarma işlemi sırasında bir hata oluştu.');
        });
    }
}

// Görüşme notu silme işlemi - AJAX
$(document).ready(function() {
    $('.btn-delete-note').on('click', function() {
        var noteId = $(this).data('note-id');
        var confirmDelete = confirm('Bu görüşme notunu silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.');
        
        if (confirmDelete) {
            // AJAX ile silme işlemi yap
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'delete_conversation_note_ajax',
                    note_id: noteId,
                    customer_id: <?php echo $customer_id; ?>,
                    nonce: '<?php echo wp_create_nonce("delete_conversation_note_ajax"); ?>'
                },
                beforeSend: function() {
                    // Butonu devre dışı bırak
                    $('.btn-delete-note[data-note-id="' + noteId + '"]').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        // Başarılı mesajı göster
                        alert('Görüşme notu başarıyla silindi.');
                        // Sayfayı yenile
                        window.location.reload();
                    } else {
                        alert('Hata: ' + (response.data || 'Görüşme notu silinirken bir hata oluştu.'));
                        // Butonu tekrar etkinleştir
                        $('.btn-delete-note[data-note-id="' + noteId + '"]').prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Görüşme notu silinirken bir hata oluştu.');
                    // Butonu tekrar etkinleştir
                    $('.btn-delete-note[data-note-id="' + noteId + '"]').prop('disabled', false);
                }
            });
        }
    });
});
</script>