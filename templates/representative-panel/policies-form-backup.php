<?php
/**
 * Poliçe Ekleme/Düzenleme Formu - Multi-Step Wizard
 * @version 6.0.0
 * @updated 2025-01-XX XX:XX
 */

include_once(dirname(__FILE__) . '/template-colors.php');

if (!is_user_logged_in()) {
    return;
}

// Veritabanında gerekli sütunların varlığını kontrol et ve yoksa ekle
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';

// insured_party sütunu kontrolü
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'insured_party'");
if (!$column_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN insured_party VARCHAR(255) DEFAULT NULL AFTER status");
}

// **NEW**: Insurance holder and insured parties columns for new system
$insurance_holder_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'insurance_holder'");
if (!$insurance_holder_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN insurance_holder TEXT DEFAULT NULL AFTER insured_party");
}

$insured_parties_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'insured_parties'");
if (!$insured_parties_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN insured_parties TEXT DEFAULT NULL AFTER insurance_holder");
}

$uploaded_files_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'uploaded_files'");
if (!$uploaded_files_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN uploaded_files TEXT DEFAULT NULL AFTER insured_parties");
}

// İptal bilgileri için sütunlar
$cancellation_date_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'cancellation_date'");
if (!$cancellation_date_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN cancellation_date DATE DEFAULT NULL AFTER status");
}

$refunded_amount_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'refunded_amount'");
if (!$refunded_amount_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN refunded_amount DECIMAL(10,2) DEFAULT NULL AFTER cancellation_date");
}

// YENİ: İptal nedeni için sütun
$cancellation_reason_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'cancellation_reason'");
if (!$cancellation_reason_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN cancellation_reason VARCHAR(100) DEFAULT NULL AFTER refunded_amount");
}

// YENİ: Silinen poliçeler için sütunlar
$is_deleted_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'is_deleted'");
if (!$is_deleted_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN is_deleted TINYINT(1) DEFAULT 0");
}

$deleted_by_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'deleted_by'");
if (!$deleted_by_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN deleted_by INT(11) DEFAULT NULL");
}

$deleted_at_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'deleted_at'");
if (!$deleted_at_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}

// YENİ: Yeni İş - Yenileme bilgisi için sütun
$policy_category_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'policy_category'");
if (!$policy_category_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN policy_category VARCHAR(50) DEFAULT 'Yeni İş' AFTER policy_type");
}

// YENİ: Network bilgisi için sütun
$network_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'network'");
if (!$network_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN network VARCHAR(255) DEFAULT NULL AFTER premium_amount");
}

// YENİ: Durum bilgisi notu için sütun
$status_note_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'status_note'");
if (!$status_note_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN status_note TEXT DEFAULT NULL AFTER status");
}

// YENİ: Ödeme bilgisi için sütun
$payment_info_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'payment_info'");
if (!$payment_info_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN payment_info VARCHAR(255) DEFAULT NULL AFTER premium_amount");
}

// YENİ: Plaka bilgisi için sütun (Kasko/Trafik için gerekli)
$plate_number_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'plate_number'");
if (!$plate_number_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN plate_number VARCHAR(20) DEFAULT NULL AFTER insured_party");
}

$editing = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0;
$renewing = isset($_GET['action']) && $_GET['action'] === 'renew' && isset($_GET['id']) && intval($_GET['id']) > 0;
$cancelling = isset($_GET['action']) && $_GET['action'] === 'cancel' && isset($_GET['id']) && intval($_GET['id']) > 0;
$create_from_offer = isset($_GET['action']) && $_GET['action'] === 'create_from_offer' && isset($_GET['customer_id']);
$policy_id = $editing || $renewing || $cancelling ? intval($_GET['id']) : 0;

// YENİ: Tanımlı iptal nedenleri
$cancellation_reasons = ['Araç Satışı', 'İsteğe Bağlı', 'Tahsilattan İptal', 'Diğer Sebepler'];

// Teklif verilerini al
$offer_amount = isset($_GET['offer_amount']) ? floatval($_GET['offer_amount']) : '';
$offer_type = isset($_GET['offer_type']) ? sanitize_text_field(urldecode($_GET['offer_type'])) : '';
$offer_file_id = isset($_GET['file_id']) ? intval($_GET['file_id']) : 0;
$selected_customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

// Oturum açmış temsilcinin ID'sini al
$current_user_rep_id = function_exists('get_current_user_rep_id') ? get_current_user_rep_id() : 0;

/**
 * Kullanıcının patron veya müdür olup olmadığını kontrol et
 * GÜNCELLEME: Artık temsilci tablosundaki role değerini kontrol ediyoruz
 */
function is_patron_or_manager() {
    global $wpdb;
    $current_user_rep_id = function_exists('get_current_user_rep_id') ? get_current_user_rep_id() : 0;
    
    if (!$current_user_rep_id) {
        return false;
    }
    
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $role = $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM $representatives_table WHERE id = %d",
        $current_user_rep_id
    ));
    
    // 1: Patron, 2: Müdür
    return ($role == 1 || $role == 2);
}

// Kullanıcının rolünü öğren (1: Patron, 2: Müdür, vb.)
function get_current_user_role() {
    global $wpdb;
    $current_user_rep_id = function_exists('get_current_user_rep_id') ? get_current_user_rep_id() : 0;
    
    if (!$current_user_rep_id) {
        return 0; // Temsilci değil
    }
    
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $role = $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM $representatives_table WHERE id = %d",
        $current_user_rep_id
    ));
    
    return intval($role);
}

// Kullanıcının poliçe düzenleme/silme yetkileri var mı?
function has_policy_permissions() {
    global $wpdb;
    $current_user_rep_id = function_exists('get_current_user_rep_id') ? get_current_user_rep_id() : 0;
    
    if (!$current_user_rep_id) {
        return false;
    }
    
    // Patron/müdür kontrolü
    $user_role = get_current_user_role();
    if ($user_role == 1 || $user_role == 2) {
        return true; // Patron ve müdür her türlü yetkiye sahip
    }
    
    // Diğer kullanıcılar için yetki kontrolü
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $permissions = $wpdb->get_row($wpdb->prepare(
        "SELECT policy_edit, policy_delete FROM $representatives_table WHERE id = %d",
        $current_user_rep_id
    ));
    
    if ($permissions) {
        return ($permissions->policy_edit == 1 || $permissions->policy_delete == 1);
    }
    
    return false;
}

// Müşteri temsilcileri listesini al (Patron ve Müdür için)
$customer_representatives = [];
if (is_patron_or_manager()) {
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $customer_representatives = $wpdb->get_results("
        SELECT r.id, u.display_name 
        FROM $representatives_table r
        LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
        WHERE r.status = 'active'
        ORDER BY u.display_name ASC
    ");
}

/**
 * Build redirect URL preserving filter parameters from HTTP_REFERER
 */
function build_redirect_url_with_filters($base_params = []) {
    $filter_params = [];
    
    // Parse referer URL to extract filter parameters
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $referer_parts = parse_url($_SERVER['HTTP_REFERER']);
        if (!empty($referer_parts['query'])) {
            parse_str($referer_parts['query'], $referer_params);
            
            // List of filter parameters to preserve
            $preserve_params = [
                'policy_number', 'customer_id', 'policy_type', 'insurance_company', 
                'status', 'insured_party', 'policy_category', 'start_date', 'end_date',
                'date_range', 'period', 'representative_id_filter', 'payment_info',
                'status_note', 'expiring_soon', 'show_passive', 'show_cancelled',
                'cancellation_reason', 'include_passive_dates', 'view_type'
            ];
            
            foreach ($preserve_params as $param) {
                if (!empty($referer_params[$param])) {
                    $filter_params[$param] = $referer_params[$param];
                }
            }
        }
    }
    
    // Merge base params with filter params
    $all_params = array_merge($filter_params, $base_params);
    
    // Build URL manually to prevent double HTML encoding
    $base_url = strtok($_SERVER['REQUEST_URI'], '?');
    if (!empty($all_params)) {
        $query_string = http_build_query($all_params);
        return $base_url . '?' . $query_string;
    }
    
    return $base_url;
}

if (isset($_POST['save_policy']) && isset($_POST['policy_nonce']) && wp_verify_nonce($_POST['policy_nonce'], 'save_policy')) {
    $policy_data = array(
        'customer_id' => intval($_POST['customer_id']),
        'policy_number' => sanitize_text_field($_POST['policy_number']),
        'policy_type' => sanitize_text_field($_POST['policy_type']),
        'policy_category' => sanitize_text_field($_POST['policy_category']),
        'insurance_company' => sanitize_text_field($_POST['insurance_company']),
        'start_date' => sanitize_text_field($_POST['start_date']),
        'end_date' => sanitize_text_field($_POST['end_date']),
        'premium_amount' => floatval($_POST['premium_amount']),
        'payment_info' => isset($_POST['payment_info']) ? sanitize_text_field($_POST['payment_info']) : '',
        'network' => isset($_POST['network']) ? sanitize_text_field($_POST['network']) : '',
        'status' => isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'aktif',
        'status_note' => isset($_POST['status_note']) ? sanitize_textarea_field($_POST['status_note']) : '',
        'insured_party' => isset($_POST['same_as_insured']) && $_POST['same_as_insured'] === 'yes' ? '' : sanitize_text_field($_POST['insured_party']),
        'representative_id' => $current_user_rep_id // Otomatik olarak mevcut temsilci
    );
    
    // **NEW**: Handle multi-step form data for insurance holder and insured parties
    if (isset($_POST['insurance_holder_data']) && !empty($_POST['insurance_holder_data'])) {
        $insurance_holder_data = json_decode(stripslashes($_POST['insurance_holder_data']), true);
        if ($insurance_holder_data) {
            $policy_data['insurance_holder'] = wp_json_encode($insurance_holder_data);
            // Set customer_id from insurance holder for backward compatibility
            $policy_data['customer_id'] = intval($insurance_holder_data['id']);
        }
    }
    
    if (isset($_POST['insured_parties_data']) && !empty($_POST['insured_parties_data'])) {
        $insured_parties_data = json_decode(stripslashes($_POST['insured_parties_data']), true);
        if ($insured_parties_data) {
            $policy_data['insured_parties'] = wp_json_encode($insured_parties_data);
        }
    }
    
    // Set default values for fields not present in multi-step form
    if (!isset($_POST['policy_category']) || empty($_POST['policy_category'])) {
        $policy_data['policy_category'] = 'Yeni İş'; // Default for new policies
    }
    
    // Müşteri temsilcisi seçimi (sadece Patron ve Müdür için)
    if (is_patron_or_manager() && isset($_POST['customer_representative_id']) && !empty($_POST['customer_representative_id'])) {
        $policy_data['representative_id'] = intval($_POST['customer_representative_id']);
    }
    
    // Plaka bilgisi kontrolü (Kasko/Trafik için)
    if (in_array(strtolower($policy_data['policy_type']), ['kasko', 'trafik']) && isset($_POST['plate_number'])) {
        $policy_data['plate_number'] = sanitize_text_field($_POST['plate_number']);
    }

    // İptal bilgilerini ekle
    if (isset($_POST['is_cancelled']) && $_POST['is_cancelled'] === 'yes') {
        $policy_data['cancellation_date'] = sanitize_text_field($_POST['cancellation_date']);
        $policy_data['refunded_amount'] = !empty($_POST['refunded_amount']) ? floatval($_POST['refunded_amount']) : 0;
        $policy_data['cancellation_reason'] = sanitize_text_field($_POST['cancellation_reason']);
        $policy_data['status'] = 'Zeyil'; // İptal edilen poliçeyi Zeyil olarak işaretle
    }

    if (!empty($_FILES['document']['name'])) {
        $upload_dir = wp_upload_dir();
        $policy_upload_dir = $upload_dir['basedir'] . '/insurance-crm-docs';
        
        if (!file_exists($policy_upload_dir)) {
            wp_mkdir_p($policy_upload_dir);
        }
        
        $allowed_file_types = array('pdf', 'doc', 'docx');
        $file_ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        
        if (in_array($file_ext, $allowed_file_types)) {
            $file_name = 'policy-' . time() . '-' . sanitize_file_name($_FILES['document']['name']);
            $file_path = $policy_upload_dir . '/' . $file_name;
            
            if (move_uploaded_file($_FILES['document']['tmp_name'], $file_path)) {
                $policy_data['document_path'] = $upload_dir['baseurl'] . '/insurance-crm-docs/' . $file_name;
            } else {
                $upload_error = true;
            }
        } else {
            $file_type_error = true;
        }
    }
    
    // **NEW**: Handle multiple file uploads from multi-step form
    if (!empty($_FILES['policy_files']['name'][0])) {
        $upload_dir = wp_upload_dir();
        $policy_upload_dir = $upload_dir['basedir'] . '/insurance-crm-docs';
        
        if (!file_exists($policy_upload_dir)) {
            wp_mkdir_p($policy_upload_dir);
        }
        
        $allowed_file_types = array('pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png');
        $max_file_size = 5 * 1024 * 1024; // 5MB
        $uploaded_files = array();
        $file_upload_errors = array();
        
        $file_count = count($_FILES['policy_files']['name']);
        
        for ($i = 0; $i < $file_count && $i < 5; $i++) {
            if ($_FILES['policy_files']['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            
            $file_name = sanitize_file_name($_FILES['policy_files']['name'][$i]);
            $file_tmp = $_FILES['policy_files']['tmp_name'][$i];
            $file_size = $_FILES['policy_files']['size'][$i];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            // File type and size validation
            if (!in_array($file_ext, $allowed_file_types)) {
                $file_upload_errors[] = $file_name . ' - desteklenmeyen dosya türü';
                continue;
            }
            
            if ($file_size > $max_file_size) {
                $file_upload_errors[] = $file_name . ' - dosya boyutu çok büyük (max 5MB)';
                continue;
            }
            
            // Create unique file name
            $new_file_name = 'policy-' . time() . '-' . $i . '-' . $file_name;
            $file_path = $policy_upload_dir . '/' . $new_file_name;
            $file_url = $upload_dir['baseurl'] . '/insurance-crm-docs/' . $new_file_name;
            
            if (move_uploaded_file($file_tmp, $file_path)) {
                $uploaded_files[] = $file_url;
            } else {
                $file_upload_errors[] = $file_name . ' - yükleme hatası';
            }
        }
        
        // Store first uploaded file in document_path for backward compatibility
        if (!empty($uploaded_files)) {
            $policy_data['document_path'] = $uploaded_files[0];
        }
        
        // Store all uploaded files in a JSON field if multiple files
        if (count($uploaded_files) > 1) {
            $policy_data['uploaded_files'] = wp_json_encode($uploaded_files);
        }
        
        // Add upload errors to session if any
        if (!empty($file_upload_errors)) {
            $_SESSION['file_upload_errors'] = $file_upload_errors;
        }
    }
    
    // Teklif dosyası kullanılıyorsa
    if (isset($_POST['use_offer_file']) && $_POST['use_offer_file'] == 'yes' && !empty($_POST['offer_file_path'])) {
        $policy_data['document_path'] = $_POST['offer_file_path'];
    }

    $table_name = $wpdb->prefix . 'insurance_crm_policies';

    if ($editing || $cancelling) {
        // Yetki kontrolü - Patron ve Müdür için her zaman izin ver
        $can_edit = true;
        $user_role = get_current_user_role();
        
        // Patron/Müdür DEĞİLSE, yetki kontrolü ve kendi poliçesi mi kontrolü yap
        if ($user_role != 1 && $user_role != 2) {
            $policy_check = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $policy_id));
            
            // Kendi poliçesi değilse yetkisi yoksa işlemi reddet
            if ($policy_check && $policy_check->representative_id != $current_user_rep_id && !has_policy_permissions()) {
                $can_edit = false;
                $message = 'Bu poliçeyi düzenleme/iptal etme yetkiniz yok.';
                $message_type = 'error';
            }
        }

        if ($can_edit) {
            $policy_data['updated_at'] = current_time('mysql');
            $result = $wpdb->update($table_name, $policy_data, ['id' => $policy_id]);

            if ($result !== false) {
                $action_text = isset($_POST['is_cancelled']) && $_POST['is_cancelled'] === 'yes' ? 'iptal edildi' : 'güncellendi';
                $message = 'Poliçe başarıyla ' . $action_text . '.';
                $message_type = 'success';
                $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
                $redirect_url = build_redirect_url_with_filters(['view' => 'policies', 'updated' => 'true']);
                wp_redirect($redirect_url);
                exit;
            } else {
                $message = 'Poliçe işlenirken bir hata oluştu.';
                $message_type = 'error';
            }
        }
    } elseif ($renewing) {
        // Yenileme işlemi için eski poliçenin bilgilerini çek
        $old_policy = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $policy_id));
        
        if ($old_policy) {
            // Eski poliçeyi pasif olarak işaretle
            $wpdb->update($table_name, ['status' => 'pasif'], ['id' => $policy_id]);
            
            // Yeni poliçeye policy_category olarak 'Yenileme' ekle
            $policy_data['policy_category'] = 'Yenileme';
            $policy_data['created_at'] = current_time('mysql');
            $policy_data['updated_at'] = current_time('mysql');
            
            // İptal bilgileri varsa temizle
            $policy_data['cancellation_date'] = null;
            $policy_data['refunded_amount'] = null;
            $policy_data['cancellation_reason'] = null;
            
            $result = $wpdb->insert($table_name, $policy_data);
            
            if ($result) {
                $new_policy_id = $wpdb->insert_id;
                $message = 'Poliçe başarıyla yenilendi. Yeni poliçe numarası: ' . $policy_data['policy_number'];
                $message_type = 'success';
                $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
                $redirect_url = build_redirect_url_with_filters(['view' => 'policies', 'action' => 'view', 'id' => $new_policy_id]);
                wp_redirect($redirect_url);
                exit;
            } else {
                $message = 'Poliçe yenilenirken bir hata oluştu.';
                $message_type = 'error';
            }
        } else {
            $message = 'Yenilenecek poliçe bulunamadı.';
            $message_type = 'error';
        }
    } else {
        // Yeni poliçe ekleme
        $policy_data['created_at'] = current_time('mysql');
        $policy_data['updated_at'] = current_time('mysql');
        
        $result = $wpdb->insert($table_name, $policy_data);
        
        if ($result) {
            $new_policy_id = $wpdb->insert_id;
            $message = 'Poliçe başarıyla eklendi.';
            $message_type = 'success';
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
            $redirect_url = build_redirect_url_with_filters(['view' => 'policies', 'added' => 'true']);
            wp_redirect($redirect_url);
            exit;
        } else {
            $message = 'Poliçe eklenirken bir hata oluştu.';
            $message_type = 'error';
        }
    }
}

// Müşterileri getir
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$customers = $wpdb->get_results("SELECT * FROM $customers_table ORDER BY first_name ASC");

// Düzenlenen veya iptal edilen poliçenin bilgilerini getir
$policy = null;
if ($policy_id > 0) {
    $policies_table = $wpdb->prefix . 'insurance_crm_policies';
    $policy = $wpdb->get_row($wpdb->prepare("SELECT * FROM $policies_table WHERE id = %d", $policy_id));
    
    if (!$policy) {
        echo '<div class="ab-notice ab-error">Poliçe bulunamadı.</div>';
        return;
    }
    
    if ($renewing) {
        // Yenileme işleminde yeni poliçe için bilgileri varsayılan olarak ayarla
        $policy->policy_number = '';  // Yeni poliçe numarası boş olmalı
        $policy->status = 'aktif';    // Yeni poliçe aktif olmalı
        $policy->start_date = date('Y-m-d', strtotime($policy->end_date . ' +1 day')); // Bitişten sonraki gün
        $policy->end_date = date('Y-m-d', strtotime($policy->end_date . ' +1 year')); // Bir yıl sonrası
        $policy->cancellation_date = null;
        $policy->refunded_amount = null;
        $policy->cancellation_reason = null;
    }
    
    // Poliçe sahibi müşteriyi getir
    $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $policy->customer_id));
}

// Varsayılan poliçe türleri
$settings = get_option('insurance_crm_settings', []);
$policy_types = $settings['default_policy_types'] ?? ['Kasko', 'Trafik', 'Konut', 'DASK', 'Sağlık', 'Hayat', 'Seyahat', 'Diğer'];

// Sigorta şirketleri
$insurance_companies = array_unique($settings['insurance_companies'] ?? ['Sompo']);
sort($insurance_companies);

// Poliçe kategorileri
$policy_categories = ['Yeni İş', 'Yenileme', 'Zeyil', 'Diğer'];

// Ödeme seçenekleri - ayarlardan al
$payment_options = $settings['payment_options'] ?? ['Peşin', '3 Taksit', '6 Taksit', '8 Taksit', '9 Taksit', '12 Taksit', 'Ödenmedi', 'Nakit', 'Kredi Kartı', 'Havale', 'Diğer'];

// Form action URL'sini hazırla
$form_action = sanitize_url($_SERVER['REQUEST_URI']);

// Başlık ve açıklama
$title = '';
$description = '';

if ($editing) {
    $title = 'Poliçe Düzenle';
    $description = 'Mevcut poliçe bilgilerini düzenleyebilirsiniz.';
} elseif ($renewing) {
    $title = 'Poliçe Yenile';
    $description = 'Mevcut poliçenin yeni versiyonunu oluşturabilirsiniz.';
} elseif ($cancelling) {
    $title = 'Poliçe İptal';
    $description = 'Poliçeyi iptal etmek için aşağıdaki bilgileri doldurunuz.';
} elseif ($create_from_offer) {
    $title = 'Tekliften Poliçe Oluştur';
    $description = 'Teklif bilgilerinden yeni bir poliçe oluşturabilirsiniz.';
} else {
    $title = 'Yeni Poliçe Ekle';
    $description = 'Yeni bir poliçe kaydı oluşturmak için aşağıdaki formu doldurunuz.';
}

// Müşteri bilgilerini getir (tekliften oluşturma durumunda)
if ($create_from_offer && $selected_customer_id > 0) {
    $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $selected_customer_id));
}

// Temsilcinin müşteri olup olmadığını kontrol et
$is_customer = false;
if ($current_user_rep_id) {
    // Temsilcinin bağlı olduğu kullanıcı bilgisini getir
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $rep = $wpdb->get_row($wpdb->prepare("SELECT * FROM $representatives_table WHERE id = %d", $current_user_rep_id));
    
    if ($rep) {
        $is_customer = true;
    }
}

// Kullanıcının rolünü ve izinlerini logla - hata ayıklama için
$user_role = get_current_user_role();
$has_permissions = has_policy_permissions();
error_log("Kullanıcı Rol: $user_role, Poliçe İzinleri: " . ($has_permissions ? 'Var' : 'Yok'));
?>

<!-- Include FontAwesome and modern CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<style>
/* MODERN AB-* CSS CLASSES FROM CUSTOMERS-FORM.PHP */
.ab-policy-form-container {
    background: #f8f9fa;
    min-height: 100vh;
    padding: 20px;
}

.ab-form-header {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.ab-header-left h2 {
    margin: 0 0 5px 0;
    color: #333;
    font-size: 24px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.ab-breadcrumbs {
    font-size: 14px;
    color: #666;
    display: flex;
    align-items: center;
    gap: 5px;
}

.ab-breadcrumbs a {
    color: #007cba;
    text-decoration: none;
}

.ab-breadcrumbs a:hover {
    text-decoration: underline;
}

/* MULTI-STEP WIZARD STYLES */
.ab-step-wizard {
    background: white;
    border-radius: 8px;
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.ab-step-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    text-align: center;
}

.ab-step-progress {
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 20px 0;
    gap: 20px;
}

.ab-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    flex: 1;
    max-width: 150px;
}

.ab-step-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: rgba(255,255,255,0.3);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    margin-bottom: 8px;
    transition: all 0.3s ease;
}

.ab-step.active .ab-step-number {
    background: #4caf50;
    transform: scale(1.1);
}

.ab-step.completed .ab-step-number {
    background: #2196f3;
}

.ab-step-title {
    font-size: 12px;
    text-align: center;
    opacity: 0.8;
}

.ab-step.active .ab-step-title {
    opacity: 1;
    font-weight: 600;
}

.ab-step-line {
    position: absolute;
    top: 20px;
    left: 50%;
    width: 100%;
    height: 2px;
    background: rgba(255,255,255,0.3);
    z-index: -1;
}

.ab-step.completed .ab-step-line {
    background: #2196f3;
}

/* FORM SECTIONS */
.ab-form-content {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    overflow: hidden;
}

.ab-step-content {
    display: none;
    padding: 30px;
}

.ab-step-content.active {
    display: block;
}

.ab-section-wrapper {
    margin-bottom: 30px;
}

.ab-section-header {
    border-bottom: 2px solid #f0f0f0;
    margin-bottom: 20px;
    padding-bottom: 10px;
}

.ab-section-header h3 {
    margin: 0;
    color: #333;
    font-size: 18px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
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
}

.ab-form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: #333;
    font-size: 14px;
}

.ab-input, .ab-select, .ab-textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    transition: all 0.3s ease;
    box-sizing: border-box;
}

.ab-input:focus, .ab-select:focus, .ab-textarea:focus {
    border-color: #007cba;
    outline: none;
    box-shadow: 0 0 0 2px rgba(0, 124, 186, 0.2);
}

.ab-textarea {
    min-height: 100px;
    resize: vertical;
}

/* CUSTOMER SEARCH */
.ab-customer-search {
    position: relative;
    margin-bottom: 20px;
}

.ab-search-input {
    width: 100%;
    padding: 15px;
    border: 2px solid #ddd;
    border-radius: 8px;
    font-size: 16px;
    background: #f9f9f9;
}

.ab-search-input:focus {
    border-color: #007cba;
    background: white;
}

.ab-search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #ddd;
    border-top: none;
    border-radius: 0 0 8px 8px;
    max-height: 300px;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.ab-search-result {
    padding: 15px;
    border-bottom: 1px solid #eee;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.ab-search-result:hover {
    background: #f5f5f5;
}

.ab-search-result:last-child {
    border-bottom: none;
}

.ab-customer-info {
    background: #e3f2fd;
    border: 1px solid #2196f3;
    border-radius: 8px;
    padding: 20px;
    margin: 20px 0;
}

.ab-customer-info h4 {
    margin: 0 0 10px 0;
    color: #1976d2;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* FAMILY/PET SELECTION */
.ab-selection-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin: 20px 0;
}

.ab-selection-card {
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: white;
}

.ab-selection-card:hover {
    border-color: #007cba;
    box-shadow: 0 2px 8px rgba(0,124,186,0.2);
}

.ab-selection-card.selected {
    border-color: #4caf50;
    background: #f1f8e9;
}

.ab-selection-card input[type="checkbox"] {
    margin-right: 10px;
}

/* BUTTONS */
.ab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    color: #333;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s ease;
    background: white;
    line-height: 1.4;
}

.ab-btn:hover {
    background-color: #eaeaea;
    text-decoration: none;
    color: #333;
    border-color: #ccc;
}

.ab-btn-primary {
    background-color: #4caf50;
    border-color: #43a047;
    color: white;
}

.ab-btn-primary:hover {
    background-color: #3d9140;
    color: white;
    border-color: #357a38;
}

.ab-btn-secondary {
    background-color: #f8f9fa;
    border-color: #ddd;
}

.ab-btn-secondary:hover {
    background-color: #e9ecef;
    border-color: #ccc;
}

.ab-form-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #eee;
}

.ab-form-actions-left, .ab-form-actions-right {
    display: flex;
    gap: 10px;
}

/* NOTICES */
.ab-notice {
    padding: 15px 20px;
    border-radius: 4px;
    margin-bottom: 20px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.ab-notice.ab-error {
    background: #ffeaea;
    border-left: 4px solid #f44336;
    color: #c62828;
}

.ab-notice.ab-success {
    background: #e8f5e8;
    border-left: 4px solid #4caf50;
    color: #2e7d32;
}

.ab-notice.ab-warning {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    color: #856404;
}

/* ROLE INFO BANNER */
.role-info-banner {
    background-color: #e3f2fd;
    color: #0d47a1;
    padding: 10px 15px;
    margin-bottom: 20px;
    border-radius: 4px;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    border-left: 4px solid #1976d2;
}

.role-info-banner.patron {
    background-color: #fff8e1;
    color: #ff6f00;
    border-left: 4px solid #ff9800;
}

.role-info-banner.mudur {
    background-color: #e8f5e9;
    color: #2e7d32;
    border-left: 4px solid #4caf50;
}

/* FILE UPLOAD STYLES */
.ab-file-upload-container {
    margin: 20px 0;
}

.ab-file-upload-area {
    border: 2px dashed #ccc;
    border-radius: 8px;
    padding: 40px 20px;
    text-align: center;
    background: #fafafa;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
}

.ab-file-upload-area:hover, .ab-file-upload-area.ab-drag-over {
    border-color: #007cba;
    background: #f0f8ff;
}

.ab-file-upload-icon {
    font-size: 48px;
    color: #ccc;
    margin-bottom: 10px;
}

.ab-file-upload-text {
    font-size: 16px;
    color: #666;
    margin-bottom: 10px;
}

.ab-file-upload-info {
    font-size: 12px;
    color: #999;
}

.ab-file-upload {
    display: none;
}

.ab-file-preview-container {
    margin-top: 20px;
}

.ab-file-preview {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.ab-file-preview-item {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    position: relative;
    text-align: center;
}

.ab-file-preview-item .ab-file-icon {
    font-size: 32px;
    color: #666;
    margin-bottom: 10px;
}

.ab-file-preview-item .ab-file-name {
    font-size: 14px;
    color: #333;
    word-break: break-word;
}

/* RESPONSIVE */
@media (max-width: 768px) {
    .ab-form-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .ab-form-row {
        flex-direction: column;
        gap: 15px;
    }
    
    .ab-form-group {
        min-width: auto;
    }
    
    .ab-form-actions {
        flex-direction: column;
        gap: 15px;
    }
    
    .ab-form-actions-left, .ab-form-actions-right {
        width: 100%;
        justify-content: center;
    }
    
    .ab-step-progress {
        flex-direction: column;
        gap: 10px;
    }
    
    .ab-step {
        max-width: none;
        width: 100%;
    }
    
    .ab-step-line {
        display: none;
    }
}

/* ADDITIONAL STYLES FOR RADIO BUTTONS AND SELECTIONS */
input[type="radio"] {
    margin: 0 !important;
}

label:has(input[type="radio"]) {
    cursor: pointer;
    transition: all 0.3s ease;
}

label:has(input[type="radio"]:checked) {
    border-color: #4caf50 !important;
    background: #f1f8e9 !important;
    box-shadow: 0 2px 8px rgba(76, 175, 80, 0.2);
}

.ab-selected-info {
    background: #f5f5f5;
    padding: 8px 12px;
    border-radius: 4px;
    border: 1px solid #ddd;
    font-weight: 500;
    color: #333;
}

/* LEGACY SUPPORT - Keep some existing styles for compatibility */
    .policy-form-container {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        padding: 20px 30px;
        max-width: 1200px;
        margin: 20px auto;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    
    .policy-form-header {
        margin-bottom: 30px;
        border-bottom: 1px solid #e0e0e0;
        padding-bottom: 15px;
    }
    
    .policy-form-header h2 {
        font-size: 24px;
        color: #333;
        margin-bottom: 5px;
        font-weight: 600;
    }
    
    .policy-form-header p {
        font-size: 14px;
        color: #666;
        margin: 0;
    }
    
    .policy-form-content {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }
    
    .policy-form-section {
        background: #f9f9f9;
        border-radius: 6px;
        padding: 20px;
        border: 1px solid #eee;
    }
    
    .policy-form-section h3 {
        margin-top: 0;
        font-size: 18px;
        color: #333;
        margin-bottom: 15px;
        font-weight: 600;
        border-bottom: 1px solid #ddd;
        padding-bottom: 10px;
    }
    
    .form-row {
        margin-bottom: 15px;
    }
    
    .form-row label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
        font-size: 14px;
        color: #444;
    }
    
    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }
    
    .form-textarea {
        min-height: 100px;
    }
    
    .form-input:focus, .form-select:focus, .form-textarea:focus {
        border-color: #1976d2;
        outline: none;
        box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.2);
    }
    
    .form-actions {
        margin-top: 30px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    
    .btn {
        padding: 10px 20px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.2s ease;
    }
    
    .btn-primary {
        background-color: #1976d2;
        color: white;
    }
    
    .btn-primary:hover {
        background-color: #1565c0;
    }
    
    .btn-secondary {
        background-color: #f5f5f5;
        color: #333;
        border: 1px solid #ddd;
    }
    
    .btn-secondary:hover {
        background-color: #e0e0e0;
    }
    
    .btn-danger {
        background-color: #f44336;
        color: white;
    }
    
    .btn-danger:hover {
        background-color: #d32f2f;
    }
    
    .checkbox-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 10px;
    }
    
    .checkbox-row input[type="checkbox"] {
        width: 16px;
        height: 16px;
    }
    
    .notification {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
    }
    
    .notification.success {
        background-color: #e8f5e9;
        border-left: 4px solid #4caf50;
        color: #2e7d32;
    }
    
    .notification.error {
        background-color: #ffebee;
        border-left: 4px solid #f44336;
        color: #c62828;
    }
    
    .notification.warning {
        background-color: #fff3e0;
        border-left: 4px solid #ff9800;
        color: #e65100;
    }
    
    /* Cancellation Section Styles */
    .cancellation-section {
        background-color: #ffebee;
        border: 1px solid #ffcdd2;
    }
    
    .cancellation-section h3 {
        color: #c62828;
    }
    
    .cancellation-section .form-row label {
        color: #c62828;
    }
    
    /* New Status Note Section */
    .status-note-row {
        margin-top: 15px;
    }

    .status-badge {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 50px;
        font-size: 12px;
        font-weight: 600;
        margin-left: 10px;
    }
    
    .status-aktif {
        background-color: #e8f5e9;
        color: #2e7d32;
    }
    
    .status-pasif {
        background-color: #f5f5f5;
        color: #757575;
    }
    
    .status-iptal {
        background-color: #ffebee;
        color: #c62828;
    }
    
    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        margin-left: 8px;
    }
    
    .badge-primary {
        background-color: #e3f2fd;
        color: #1976d2;
    }
    
    .badge-success {
        background-color: #e8f5e9;
        color: #2e7d32;
    }
    
    .badge-warning {
        background-color: #fff3e0;
        color: #f57c00;
    }

    /* Network Field */
    .network-field {
        margin-top: 15px;
    }
    
    /* Payment Info Field */
    .payment-info-field {
        margin-top: 15px;
    }
    
    /* Document Upload */
    .file-upload-wrapper {
        position: relative;
    }
    
    .section-description {
        color: #666;
        font-size: 14px;
        margin-bottom: 15px;
        line-height: 1.4;
        background: #f8f9fa;
        padding: 10px 15px;
        border-radius: 4px;
        border-left: 3px solid #007cba;
    }
    
    .file-upload-wrapper input[type=file] {
        opacity: 0;
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        left: 0;
        z-index: 99;
        height: 40px;
        cursor: pointer;
    }
    
    .file-upload-input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        background-color: white;
    }
    
    .file-upload-wrapper:hover .file-upload-input {
        border-color: #1976d2;
    }
    
    /* Responsive designs */
    @media (max-width: 768px) {
        .policy-form-container {
            padding: 15px;
        }
        
        .policy-form-content {
            grid-template-columns: 1fr;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .btn {
            width: 100%;
        }
    }

    /* Tam genişlik bölümü için CSS */
    .full-width-section {
        grid-column: 1 / -1; /* Tüm grid sütunlarını kapla */
        margin-top: 20px;
    }

    /* Dosya upload alanını daha kullanışlı hale getir */
    .full-width-section .file-upload-wrapper {
        max-width: 600px; /* Dosya seçme alanını kontrollü bir genişlikte tutar */
    }

    /* Müşteri Bilgileri Yükleniyor Spinner */
    .customer-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(0,0,0,.1);
        border-radius: 50%;
        border-top-color: #1976d2;
        animation: spin 1s ease-in-out infinite;
        margin-left: 10px;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    /* Plaka alanı için özel stil */
    .plate-input {
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 1px;
    }

    /* Seçilmemiş alanlar için belirginlik */
    select:invalid, .form-select option:first-child {
        color: #757575;
    }
    
    /* Rol Bilgisi Banner */
    .role-info-banner {
        background-color: #e3f2fd;
        color: #0d47a1;
        padding: 10px 15px;
        margin-bottom: 20px;
        border-radius: 4px;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-left: 4px solid #1976d2;
    }
    
    .role-info-banner.patron {
        background-color: #fff8e1;
        color: #ff6f00;
        border-left: 4px solid #ff9800;
    }
    
    .role-info-banner.mudur {
        background-color: #e8f5e9;
        color: #2e7d32;
        border-left: 4px solid #4caf50;
    }
    
    .role-info-banner i {
        font-size: 18px;
    }
</style>

<?php if (isset($message)): ?>
<div class="notification <?php echo $message_type; ?>">
    <?php echo $message; ?>
</div>
<?php endif; ?>

<?php
// Rol bilgisi banner'ı göster
$user_role = get_current_user_role();
if ($user_role == 1 || $user_role == 2):
?>
<div class="role-info-banner <?php echo $user_role == 1 ? 'patron' : 'mudur'; ?>">
    <i class="fas fa-<?php echo $user_role == 1 ? 'crown' : 'user-tie'; ?>"></i>
    <div>
        <strong>
            <?php echo $user_role == 1 ? 'Patron' : 'Müdür'; ?> olarak yetkilendirildiniz.
        </strong>
        <span>Tüm poliçeleri düzenleme ve iptal etme yetkisine sahipsiniz.</span>
    </div>
</div>
<?php endif; ?>

<!-- MODERN MULTI-STEP POLICY FORM -->
<div class="ab-policy-form-container">
    <!-- Form Header -->
    <div class="ab-form-header">
        <div class="ab-header-left">
            <h2><i class="fas fa-file-contract"></i> <?php echo $title; ?></h2>
            <div class="ab-breadcrumbs">
                <a href="?view=policies">Poliçeler</a> <i class="fas fa-chevron-right"></i> 
                <span><?php echo $editing ? 'Düzenle' : ($renewing ? 'Yenile' : ($cancelling ? 'İptal' : 'Yeni Poliçe')); ?></span>
            </div>
        </div>
        <a href="?view=policies" class="ab-btn ab-btn-secondary">
            <i class="fas fa-arrow-left"></i> Listeye Dön
        </a>
    </div>

    <?php if (isset($message)): ?>
    <div class="ab-notice ab-<?php echo $message_type; ?>">
        <?php echo $message; ?>
    </div>
    <?php endif; ?>

    <?php
    // Check if this is a new policy (not editing, renewing, or cancelling)
    $is_new_policy = !$editing && !$renewing && !$cancelling && !$create_from_offer;
    ?>

    <?php if ($is_new_policy): ?>
    <!-- MULTI-STEP WIZARD FOR NEW POLICIES -->
    <div class="ab-step-wizard">
        <div class="ab-step-header">
            <h3>Yeni Poliçe Oluşturma Süreci</h3>
            <p>Poliçenizi oluşturmak için aşağıdaki adımları takip edin</p>
            
            <!-- Progress Steps -->
            <div class="ab-step-progress">
                <div class="ab-step active" data-step="1">
                    <div class="ab-step-number">1</div>
                    <div class="ab-step-title">Sigorta Ettiren</div>
                    <div class="ab-step-line"></div>
                </div>
                <div class="ab-step" data-step="2">
                    <div class="ab-step-number">2</div>
                    <div class="ab-step-title">Sigortalı Seçimi</div>
                    <div class="ab-step-line"></div>
                </div>
                <div class="ab-step" data-step="3">
                    <div class="ab-step-number">3</div>
                    <div class="ab-step-title">Poliçe Bilgileri</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Multi-Step Form Content -->
    <div class="ab-form-content">
        <form action="<?php echo $form_action; ?>" method="post" enctype="multipart/form-data" id="multi-step-policy-form">
            <?php wp_nonce_field('save_policy', 'policy_nonce'); ?>
            <input type="hidden" name="save_policy" value="1">
            <input type="hidden" name="current_step" id="current_step" value="1">
            <input type="hidden" name="insurance_holder_data" id="insurance_holder_data" value="">
            <input type="hidden" name="insured_parties_data" id="insured_parties_data" value="">

            <!-- STEP 1: Insurance Holder Selection -->
            <div class="ab-step-content active" id="step-1">
                <div class="ab-section-wrapper">
                    <div class="ab-section-header">
                        <h3><i class="fas fa-user-shield"></i> Sigorta Ettiren Seçimi</h3>
                        <p>Poliçeyi yaptıracak kişi veya kurumu seçiniz</p>
                    </div>
                    
                    <div class="ab-customer-search">
                        <input type="text" 
                               class="ab-search-input" 
                               id="insurance-holder-search" 
                               placeholder="Ad Soyad, TC No, Şirket Adı veya Vergi Kimlik No ile arama yapınız..."
                               autocomplete="off">
                        <div class="ab-search-results" id="insurance-holder-results" style="display: none;"></div>
                    </div>
                    
                    <div class="ab-customer-info" id="selected-insurance-holder" style="display: none;">
                        <h4><i class="fas fa-user-check"></i> Seçilen Sigorta Ettiren</h4>
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label>Ad Soyad / Şirket Adı</label>
                                <div id="holder-name" class="ab-selected-info"></div>
                            </div>
                            <div class="ab-form-group">
                                <label>TC No / Vergi Kimlik No</label>
                                <div id="holder-identity" class="ab-selected-info"></div>
                            </div>
                        </div>
                        <div class="ab-form-row">
                            <div class="ab-form-group">
                                <label>Telefon</label>
                                <div id="holder-phone" class="ab-selected-info"></div>
                            </div>
                            <div class="ab-form-group">
                                <label>E-posta</label>
                                <div id="holder-email" class="ab-selected-info"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="ab-form-actions">
                    <div class="ab-form-actions-left"></div>
                    <div class="ab-form-actions-right">
                        <button type="button" class="ab-btn ab-btn-primary" id="step1-next" disabled>
                            İleri <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- STEP 2: Insured Party Question -->
            <div class="ab-step-content" id="step-2">
                <div class="ab-section-wrapper">
                    <div class="ab-section-header">
                        <h3><i class="fas fa-users"></i> Sigortalı Kişi Seçimi</h3>
                        <p>Sigortalı ile sigorta ettiren aynı kişi mi?</p>
                    </div>
                    
                    <div class="ab-form-row">
                        <div class="ab-form-group">
                            <div style="background: #f0f8ff; padding: 20px; border-radius: 8px; border: 1px solid #2196f3;">
                                <h4 style="margin: 0 0 15px 0; color: #1976d2;">
                                    <i class="fas fa-question-circle"></i> Sigorta Ettiren ile Sigortalı aynı kişi mi?
                                </h4>
                                <div style="display: flex; gap: 20px;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 10px; background: white; border-radius: 4px; border: 2px solid #e0e0e0; transition: all 0.3s ease;">
                                        <input type="radio" name="same_person" value="yes" id="same-person-yes" style="margin: 0;">
                                        <span>Evet, aynı kişi</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 10px; background: white; border-radius: 4px; border: 2px solid #e0e0e0; transition: all 0.3s ease;">
                                        <input type="radio" name="same_person" value="no" id="same-person-no" style="margin: 0;">
                                        <span>Hayır, farklı kişiler</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Family/Pet Selection (shown when "No" is selected) -->
                    <div id="family-selection" style="display: none;">
                        <div class="ab-section-header">
                            <h3><i class="fas fa-family"></i> Aile Üyeleri ve Evcil Hayvan Seçimi</h3>
                            <p>Sigortalı olacak kişi/hayvanları seçiniz</p>
                        </div>
                        
                        <div id="family-members" class="ab-selection-grid">
                            <!-- Will be populated via JavaScript -->
                        </div>
                    </div>
                </div>
                
                <div class="ab-form-actions">
                    <div class="ab-form-actions-left">
                        <button type="button" class="ab-btn ab-btn-secondary" id="step2-back">
                            <i class="fas fa-arrow-left"></i> Geri
                        </button>
                    </div>
                    <div class="ab-form-actions-right">
                        <button type="button" class="ab-btn ab-btn-primary" id="step2-next" disabled>
                            İleri <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- STEP 3: Policy Details -->
            <div class="ab-step-content" id="step-3">
                <div class="ab-section-wrapper">
                    <div class="ab-section-header">
                        <h3><i class="fas fa-file-contract"></i> Poliçe Bilgileri</h3>
                        <p>Poliçe detaylarını doldurunuz</p>
                    </div>
                    
                    <!-- Policy Information Form - Based on existing structure -->
                    <div class="ab-form-row">
                        <div class="ab-form-group">
                            <label for="policy_number">Poliçe Numarası <span style="color: red;">*</span></label>
                            <input type="text" name="policy_number" id="policy_number" class="ab-input" 
                                   value="<?php echo isset($policy) ? esc_attr($policy->policy_number) : ''; ?>" 
                                   required placeholder="Poliçe numarasını giriniz">
                        </div>
                        
                        <div class="ab-form-group">
                            <label for="policy_type">Poliçe Türü <span style="color: red;">*</span></label>
                            <select name="policy_type" id="policy_type" class="ab-select" required>
                                <option value="">Seçiniz...</option>
                                <option value="Kasko" <?php if (isset($policy)) selected($policy->policy_type, 'Kasko'); ?>>Kasko</option>
                                <option value="Trafik" <?php if (isset($policy)) selected($policy->policy_type, 'Trafik'); ?>>Trafik</option>
                                <option value="Dask" <?php if (isset($policy)) selected($policy->policy_type, 'Dask'); ?>>DASK</option>
                                <option value="Konut" <?php if (isset($policy)) selected($policy->policy_type, 'Konut'); ?>>Konut</option>
                                <option value="İşyeri" <?php if (isset($policy)) selected($policy->policy_type, 'İşyeri'); ?>>İşyeri</option>
                                <option value="Sağlık" <?php if (isset($policy)) selected($policy->policy_type, 'Sağlık'); ?>>Sağlık</option>
                                <option value="Hayat" <?php if (isset($policy)) selected($policy->policy_type, 'Hayat'); ?>>Hayat</option>
                                <option value="Seyahat" <?php if (isset($policy)) selected($policy->policy_type, 'Seyahat'); ?>>Seyahat</option>
                                <option value="Diğer" <?php if (isset($policy)) selected($policy->policy_type, 'Diğer'); ?>>Diğer</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Vehicle Plate (for Kasko/Trafik) -->
                    <div class="ab-form-row" id="plate_field" style="display: none;">
                        <div class="ab-form-group">
                            <label for="plate_number">Araç Plakası <span style="color: red;">*</span></label>
                            <input type="text" name="plate_number" id="plate_number" class="ab-input" 
                                   value="<?php echo isset($policy) ? esc_attr($policy->plate_number) : ''; ?>"
                                   placeholder="34ABC123" maxlength="10" style="text-transform: uppercase; font-weight: 600; letter-spacing: 1px;">
                        </div>
                    </div>
                    
                    <div class="ab-form-row">
                        <div class="ab-form-group">
                            <label for="insurance_company">Sigorta Şirketi <span style="color: red;">*</span></label>
                            <select name="insurance_company" id="insurance_company" class="ab-select" required>
                                <option value="">Seçiniz...</option>
                                <?php foreach ($insurance_companies as $company): ?>
                                <option value="<?php echo esc_attr($company); ?>" <?php if (isset($policy)) selected($policy->insurance_company, $company); ?>>
                                    <?php echo esc_html($company); ?>
                                </option>
                                <?php endforeach; ?>
                                <option value="Diğer">Diğer</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="ab-form-row">
                        <div class="ab-form-group">
                            <label for="start_date">Başlangıç Tarihi <span style="color: red;">*</span></label>
                            <input type="date" name="start_date" id="start_date" class="ab-input" 
                                   value="<?php echo isset($policy) ? esc_attr($policy->start_date) : date('Y-m-d'); ?>" 
                                   required>
                        </div>
                        
                        <div class="ab-form-group">
                            <label for="end_date">Bitiş Tarihi <span style="color: red;">*</span></label>
                            <input type="date" name="end_date" id="end_date" class="ab-input" 
                                   value="<?php echo isset($policy) ? esc_attr($policy->end_date) : date('Y-m-d', strtotime('+1 year')); ?>" 
                                   required>
                        </div>
                    </div>
                    
                    <div class="ab-form-row">
                        <div class="ab-form-group">
                            <label for="premium_amount">Prim Tutarı (₺) <span style="color: red;">*</span></label>
                            <input type="number" name="premium_amount" id="premium_amount" class="ab-input" 
                                   value="<?php echo isset($policy) && $policy->premium_amount > 0 ? esc_attr($policy->premium_amount) : ''; ?>" 
                                   step="0.01" min="0" required placeholder="Prim tutarı giriniz">
                        </div>
                        
                        <div class="ab-form-group">
                            <label for="payment_info">Ödeme Bilgisi</label>
                            <select name="payment_info" id="payment_info" class="ab-select">
                                <option value="">Seçiniz...</option>
                                <?php foreach ($payment_options as $option): ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php if (isset($policy) && $policy->payment_info === $option) echo 'selected'; ?>><?php echo esc_html($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- File Upload Section -->
                    <div class="ab-section-wrapper">
                        <div class="ab-section-header">
                            <h3><i class="fas fa-paperclip"></i> Belgeler</h3>
                            <p>Poliçe ile ilgili belgeleri yükleyebilirsiniz</p>
                        </div>
                        
                        <div class="ab-file-upload-container">
                            <div class="ab-file-upload-area" id="policy-file-upload-area">
                                <div class="ab-file-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                <div class="ab-file-upload-text">
                                    Dosya yüklemek için tıklayın veya sürükleyin
                                </div>
                                <div class="ab-file-upload-info">
                                    PDF, DOC, DOCX, JPG, PNG formatları desteklenir (Maks. 5MB, maksimum 5 dosya)
                                </div>
                                <input type="file" name="policy_files[]" id="policy_files" class="ab-file-upload" multiple
                                       accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            </div>
                            
                            <div class="ab-file-preview-container">
                                <div class="ab-file-preview" id="policy-file-preview"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="ab-form-actions">
                    <div class="ab-form-actions-left">
                        <button type="button" class="ab-btn ab-btn-secondary" id="step3-back">
                            <i class="fas fa-arrow-left"></i> Geri
                        </button>
                    </div>
                    <div class="ab-form-actions-right">
                        <button type="submit" class="ab-btn ab-btn-primary">
                            <i class="fas fa-save"></i> Poliçe Oluştur
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <?php else: ?>
    <!-- LEGACY FORM FOR EDITING/RENEWING/CANCELLING -->
    <div class="policy-form-container" id="policy-form">
        <div class="policy-form-header">
            <h2><?php echo $title; ?></h2>
            <p><?php echo $description; ?></p>
        </div>
        
        <form action="<?php echo $form_action; ?>" method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('save_policy', 'policy_nonce'); ?>
            <input type="hidden" name="save_policy" value="1">
            
            <div class="policy-form-content">
            
            <?php if ($cancelling || (isset($policy) && $policy->cancellation_date)): ?>
            <!-- İPTAL BİLGİLERİ - EN ÜSTTE TAM GENİŞLİKTE -->
            <div class="policy-form-section cancellation-section full-width-section">
                <h3>İptal Bilgileri</h3>
                <input type="hidden" name="is_cancelled" value="yes">
                
                <div class="form-row">
                    <label for="cancellation_date">İptal Tarihi <span style="color: red;">*</span></label>
                    <input type="date" name="cancellation_date" id="cancellation_date" class="form-input" 
                           value="<?php echo isset($policy) && $policy->cancellation_date ? esc_attr($policy->cancellation_date) : date('Y-m-d'); ?>" 
                           required>
                </div>
                
                <div class="form-row">
                    <label for="refunded_amount">İade Tutarı (₺)</label>
                    <input type="number" name="refunded_amount" id="refunded_amount" class="form-input" 
                           value="<?php echo isset($policy) && $policy->refunded_amount ? esc_attr($policy->refunded_amount) : ''; ?>" 
                           step="0.01" min="0" placeholder="Varsa iade tutarı">
                </div>
                
                <!-- İptal nedeni seçimi -->
                <div class="form-row">
                    <label for="cancellation_reason">İptal Nedeni <span style="color: red;">*</span></label>
                    <select name="cancellation_reason" id="cancellation_reason" class="form-select" required>
                        <option value="">Seçiniz...</option>
                        <?php foreach ($cancellation_reasons as $reason): ?>
                        <option value="<?php echo esc_attr($reason); ?>" <?php if (isset($policy) && $policy->cancellation_reason === $reason) echo 'selected'; ?>>
                            <?php echo esc_html($reason); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <p style="color: #c62828; font-weight: 500; font-size: 14px;">
                        <i class="fas fa-exclamation-triangle"></i> 
                        Dikkat: İptal işlemi geri alınamaz. İptal edilen poliçeler sistemde kalacak ancak Zeyil olarak işaretlenecektir.
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Müşteri Bilgileri -->
            <div class="policy-form-section">
                <h3>Müşteri Bilgileri</h3>
                <div class="form-row">
                    <label for="customer_id">Müşteri Seçin <span style="color: red;">*</span></label>
                    <select name="customer_id" id="customer_id" class="form-select" required <?php echo ($editing || $cancelling || $renewing || $create_from_offer) ? 'disabled' : ''; ?>>
                        <option value="">Müşteri Seçin</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?php echo $c->id; ?>" <?php selected(isset($policy) ? $policy->customer_id : ($selected_customer_id ?: 0), $c->id); ?>>
                            <?php echo esc_html($c->first_name . ' ' . $c->last_name); ?>
                            <?php if (!empty($c->tc_identity)): ?>
                                (<?php echo esc_html($c->tc_identity); ?>)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($editing || $cancelling || $renewing || $create_from_offer): ?>
                    <input type="hidden" name="customer_id" value="<?php echo isset($policy) ? $policy->customer_id : $selected_customer_id; ?>">
                    <?php endif; ?>
                </div>
                
                <div id="customer_details">
                    <?php if (isset($customer) && $customer): ?>
                    <div class="form-row">
                        <label>TC Kimlik No</label>
                        <input type="text" id="customer_tc" class="form-input" value="<?php echo esc_attr($customer->tc_identity); ?>" readonly>
                    </div>
                    
                    <div class="form-row">
                        <label>Telefon</label>
                        <input type="text" id="customer_phone" class="form-input" value="<?php echo esc_attr($customer->phone); ?>" readonly>
                    </div>
                    
                    <div class="form-row">
                        <label>E-posta</label>
                        <input type="text" id="customer_email" class="form-input" value="<?php echo esc_attr($customer->email); ?>" readonly>
                    </div>
                    
                    <div class="form-row">
                        <label for="insured_party">Sigortalayan</label>
                        <input type="text" name="insured_party" id="insured_party" class="form-input" 
                               value="<?php echo isset($policy) && !empty($policy->insured_party) ? esc_attr($policy->insured_party) : ''; ?>" 
                               placeholder="Sigortalayan farklıysa lütfen isim soyisim girin">
                        
                        <div class="checkbox-row">
                            <input type="checkbox" name="same_as_insured" id="same_as_insured" value="yes" 
                               <?php echo isset($policy) && empty($policy->insured_party) ? 'checked' : ''; ?>>
                            <label for="same_as_insured">Sigortalı ile Sigortalayan Aynı Kişi mi?</label>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Poliçe Bilgileri -->
            <div class="policy-form-section">
                <h3>Poliçe Bilgileri</h3>
                <div class="form-row">
                    <label for="policy_number">Poliçe Numarası <span style="color: red;">*</span></label>
                    <input type="text" name="policy_number" id="policy_number" class="form-input" 
                           value="<?php echo isset($policy) ? esc_attr($policy->policy_number) : ''; ?>" 
                           required>
                </div>
                
                <div class="form-row">
                    <label for="policy_type">Poliçe Türü <span style="color: red;">*</span></label>
                    <select name="policy_type" id="policy_type" class="form-select" required>
                        <option value="">Seçiniz...</option>
                        <?php foreach ($policy_types as $type): ?>
                        <option value="<?php echo esc_attr($type); ?>" <?php if (isset($policy)) selected($policy->policy_type, $type); else if ($offer_type === $type) echo 'selected'; ?>>
                            <?php echo esc_html($type); ?>
                        </option>
                        <?php endforeach; ?>
                        <option value="Diğer">Diğer</option>
                    </select>
                </div>
                
                <!-- Plaka alanı (Kasko/Trafik için) -->
                <div class="form-row" id="plate_field" style="display: none;">
                    <label for="plate_number">Araç Plakası <span style="color: red;">*</span></label>
                    <input type="text" name="plate_number" id="plate_number" class="form-input plate-input" 
                           value="<?php echo isset($policy) ? esc_attr($policy->plate_number) : ''; ?>"
                           placeholder="34ABC123" maxlength="10">
                </div>
                
                <!-- Poliçe Kategorisi seçimi -->
                <div class="form-row">
                    <label for="policy_category">Poliçe Kategorisi</label>
                    <select name="policy_category" id="policy_category" class="form-select">
                        <option value="">Seçiniz...</option>
                        <?php foreach ($policy_categories as $category): ?>
                        <option value="<?php echo esc_attr($category); ?>" <?php if (isset($policy)) selected($policy->policy_category, $category); else if ($renewing && $category === 'Yenileme') echo 'selected'; ?>>
                            <?php echo esc_html($category); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <label for="insurance_company">Sigorta Şirketi <span style="color: red;">*</span></label>
                    <select name="insurance_company" id="insurance_company" class="form-select" required>
                        <option value="">Seçiniz...</option>
                        <?php foreach ($insurance_companies as $company): ?>
                        <option value="<?php echo esc_attr($company); ?>" <?php if (isset($policy)) selected($policy->insurance_company, $company); ?>>
                            <?php echo esc_html($company); ?>
                        </option>
                        <?php endforeach; ?>
                        <option value="Diğer">Diğer</option>
                    </select>
                </div>
                
                <div class="form-row">
                    <label for="start_date">Başlangıç Tarihi <span style="color: red;">*</span></label>
                    <input type="date" name="start_date" id="start_date" class="form-input" 
                           value="<?php echo isset($policy) ? esc_attr($policy->start_date) : date('Y-m-d'); ?>" 
                           required>
                </div>
                
                <div class="form-row">
                    <label for="end_date">Bitiş Tarihi <span style="color: red;">*</span></label>
                    <input type="date" name="end_date" id="end_date" class="form-input" 
                           value="<?php echo isset($policy) ? esc_attr($policy->end_date) : date('Y-m-d', strtotime('+1 year')); ?>" 
                           required>
                </div>
                
                <?php if (is_patron_or_manager()): ?>
                <div class="form-row">
                    <label for="customer_representative_id">
                        <i class="fas fa-user-tie"></i>
                        Müşteri Temsilcisi
                    </label>
                    <select name="customer_representative_id" id="customer_representative_id" class="form-select">
                        <option value="">Müşteri Temsilcisi Seçin (Opsiyonel)</option>
                        <?php foreach ($customer_representatives as $rep): ?>
                            <option value="<?php echo esc_attr($rep->id); ?>" 
                                    <?php echo (isset($policy) && $policy->representative_id == $rep->id) ? 'selected' : ''; ?>>
                                <?php echo esc_html($rep->display_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="input-help" style="font-size: 10px;"><i class="fas fa-info-circle"></i> Bu alan sadece Patron ve Müdür tarafından düzenlenebilir.</p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Ödeme Bilgileri -->
            <div class="policy-form-section">
                <h3>Ödeme ve Durum Bilgileri</h3>
                <div class="form-row">
                    <label for="premium_amount">Prim Tutarı (₺) <span style="color: red;">*</span></label>
                    <input type="number" name="premium_amount" id="premium_amount" class="form-input" 
                           value="<?php echo isset($policy) && $policy->premium_amount > 0 ? esc_attr($policy->premium_amount) : ''; ?>" 
                           step="0.01" min="0" required placeholder="Prim tutarı giriniz">
                </div>
                
                <!-- Ödeme Bilgisi alanı -->
                <div class="form-row payment-info-field">
                    <label for="payment_info">Ödeme Bilgisi</label>
                    <select name="payment_info" id="payment_info" class="form-select">
                        <option value="">Seçiniz...</option>
                        <?php foreach ($payment_options as $option): ?>
                        <option value="<?php echo esc_attr($option); ?>" <?php if (isset($policy) && $policy->payment_info === $option) echo 'selected'; ?>><?php echo esc_html($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Network bilgisi alanı -->
                <div class="form-row network-field">
                    <label for="network">Network/Anlaşmalı Kurum</label>
                    <input type="text" name="network" id="network" class="form-input" 
                           value="<?php echo isset($policy) ? esc_attr($policy->network) : ''; ?>" 
                           placeholder="Varsa anlaşmalı kurum bilgisi">
                </div>
                
                <div class="form-row">
                    <label for="status">Durum</label>
                    <select name="status" id="status" class="form-select" <?php if ($cancelling) echo 'disabled'; ?>>
                        <option value="">Seçiniz...</option>
                        <option value="aktif" <?php if (isset($policy) && $policy->status === 'aktif') echo 'selected'; ?>>Aktif</option>
                        <option value="pasif" <?php if (isset($policy) && $policy->status === 'pasif') echo 'selected'; ?>>Pasif</option>
                        <option value="Zeyil" <?php if (isset($policy) && $policy->status === 'Zeyil') echo 'selected'; ?>>Zeyil</option>
                    </select>
                    <?php if ($cancelling): ?>
                    <input type="hidden" name="status" value="Zeyil">
                    <?php endif; ?>
                </div>
                
                <!-- Durum notu alanı -->
                <div class="form-row status-note-row">
                    <label for="status_note">Durum Notu</label>
                    <textarea name="status_note" id="status_note" class="form-textarea" 
                           placeholder="Poliçe durumu hakkında ekstra bilgi"><?php echo isset($policy) ? esc_textarea($policy->status_note) : ''; ?></textarea>
                </div>
            </div>
            
            <!-- Döküman Yükleme - Tam Genişlik -->
            <div class="policy-form-section full-width-section">
                <h3>Dökümanlar</h3>
                <p class="section-description">Bu bölümde poliçe ile ilgili dökümanları (poliçe kopyası, teklif formu, imzalı evraklar) yükleyebilirsiniz. PDF, DOC veya DOCX formatında dosyalar kabul edilmektedir.</p>
                <div class="form-row">
                    <label>Poliçe Dökümantasyonu</label>
                    <div class="file-upload-wrapper">
                        <div class="file-upload-input">
                            <?php if (isset($policy) && $policy->document_path): ?>
                                Mevcut Döküman: 
                                <a href="<?php echo esc_url($policy->document_path); ?>" target="_blank">
                                    <?php echo basename($policy->document_path); ?>
                                </a>
                            <?php else: ?>
                                Döküman seçmek için tıklayın (PDF, DOC, DOCX)
                            <?php endif; ?>
                        </div>
                        <input type="file" name="document" accept=".pdf,.doc,.docx">
                    </div>
                </div>
                
                <?php if (isset($offer_file_id) && $offer_file_id > 0): ?>
                    <?php 
                    $offer_file_path = get_attached_file($offer_file_id);
                    $offer_file_url = wp_get_attachment_url($offer_file_id);
                    if ($offer_file_path && $offer_file_url):
                    ?>
                    <div class="form-row">
                        <div class="checkbox-row">
                            <input type="checkbox" name="use_offer_file" id="use_offer_file" value="yes">
                            <label for="use_offer_file">Teklif dökümantasyonunu kullan</label>
                        </div>
                        <input type="hidden" name="offer_file_path" value="<?php echo esc_url($offer_file_url); ?>">
                        <div style="margin-top: 5px;">
                            <a href="<?php echo esc_url($offer_file_url); ?>" target="_blank">
                                <?php echo basename($offer_file_path); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="form-actions">
            <a href="<?php echo esc_url(build_redirect_url_with_filters(['view' => 'policies'])); ?>" class="btn btn-secondary">
                İptal
            </a>
            
            <?php if ($cancelling): ?>
                <button type="submit" class="btn btn-danger" onclick="return confirm('Poliçeyi iptal etmek istediğinizden emin misiniz?');">
                    Poliçeyi İptal Et
                </button>
            <?php else: ?>
                <button type="submit" class="btn btn-primary">
                    <?php echo $editing ? 'Güncelle' : ($renewing ? 'Yenile' : 'Kaydet'); ?>
                </button>
            <?php endif; ?>
        </div>
    </form>
</div>

    <?php endif; ?>
</div>

<script>
// MODERN MULTI-STEP POLICY FORM JAVASCRIPT
document.addEventListener('DOMContentLoaded', function() {
    // Check if this is the multi-step form
    const multiStepForm = document.getElementById('multi-step-policy-form');
    if (multiStepForm) {
        initMultiStepForm();
    }
    
    // Legacy functionality for existing forms
    updatePlateField();
    setupInsuredCheckbox();
});

// Multi-Step Form Management
function initMultiStepForm() {
    let currentStep = 1;
    let selectedInsuranceHolder = null;
    let selectedInsuredParties = [];
    
    // Customer search functionality
    const insuranceHolderSearch = document.getElementById('insurance-holder-search');
    const insuranceHolderResults = document.getElementById('insurance-holder-results');
    const selectedInsuranceHolderDiv = document.getElementById('selected-insurance-holder');
    
    // Step navigation buttons
    const step1Next = document.getElementById('step1-next');
    const step2Back = document.getElementById('step2-back');
    const step2Next = document.getElementById('step2-next');
    const step3Back = document.getElementById('step3-back');
    
    // Same person radio buttons
    const samePersonYes = document.getElementById('same-person-yes');
    const samePersonNo = document.getElementById('same-person-no');
    const familySelection = document.getElementById('family-selection');
    
    // Search functionality
    let searchTimeout;
    insuranceHolderSearch.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        
        if (query.length < 2) {
            insuranceHolderResults.style.display = 'none';
            return;
        }
        
        searchTimeout = setTimeout(() => {
            searchCustomers(query);
        }, 300);
    });
    
    // Search customers via AJAX
    function searchCustomers(query) {
        const formData = new FormData();
        formData.append('action', 'search_customers');
        formData.append('query', query);
        formData.append('nonce', '<?php echo wp_create_nonce('search_customers'); ?>');
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySearchResults(data.data);
            }
        })
        .catch(error => {
            console.error('Search error:', error);
        });
    }
    
    // Display search results
    function displaySearchResults(customers) {
        if (customers.length === 0) {
            insuranceHolderResults.innerHTML = '<div class="ab-search-result">Müşteri bulunamadı</div>';
        } else {
            insuranceHolderResults.innerHTML = customers.map(customer => `
                <div class="ab-search-result" onclick="selectInsuranceHolder(${customer.id})">
                    <strong>${customer.first_name} ${customer.last_name}</strong>
                    ${customer.company_name ? `<br><em>${customer.company_name}</em>` : ''}
                    <br><small>${customer.tc_identity || customer.tax_number || ''} - ${customer.phone}</small>
                </div>
            `).join('');
        }
        insuranceHolderResults.style.display = 'block';
    }
    
    // Select insurance holder
    window.selectInsuranceHolder = function(customerId) {
        // Fetch full customer details
        const formData = new FormData();
        formData.append('action', 'get_customer_details');
        formData.append('customer_id', customerId);
        formData.append('nonce', '<?php echo wp_create_nonce('get_customer_details'); ?>');
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                selectedInsuranceHolder = data.data;
                displaySelectedInsuranceHolder(selectedInsuranceHolder);
                step1Next.disabled = false;
                insuranceHolderResults.style.display = 'none';
                insuranceHolderSearch.value = selectedInsuranceHolder.first_name + ' ' + selectedInsuranceHolder.last_name;
            }
        })
        .catch(error => {
            console.error('Customer fetch error:', error);
        });
    };
    
    // Display selected insurance holder
    function displaySelectedInsuranceHolder(customer) {
        document.getElementById('holder-name').textContent = 
            customer.first_name + ' ' + customer.last_name + (customer.company_name ? ' (' + customer.company_name + ')' : '');
        document.getElementById('holder-identity').textContent = customer.tc_identity || customer.tax_number || '';
        document.getElementById('holder-phone').textContent = customer.phone || '';
        document.getElementById('holder-email').textContent = customer.email || '';
        
        selectedInsuranceHolderDiv.style.display = 'block';
        
        // Store in hidden field
        document.getElementById('insurance_holder_data').value = JSON.stringify(customer);
    }
    
    // Step navigation
    step1Next.addEventListener('click', function() {
        if (selectedInsuranceHolder) {
            goToStep(2);
        }
    });
    
    step2Back.addEventListener('click', function() {
        goToStep(1);
    });
    
    step2Next.addEventListener('click', function() {
        const samePersonChoice = document.querySelector('input[name="same_person"]:checked');
        if (samePersonChoice) {
            if (samePersonChoice.value === 'yes') {
                // Same person - set insurance holder as insured party
                selectedInsuredParties = [selectedInsuranceHolder];
            }
            // Store insured parties data
            document.getElementById('insured_parties_data').value = JSON.stringify(selectedInsuredParties);
            goToStep(3);
        }
    });
    
    step3Back.addEventListener('click', function() {
        goToStep(2);
    });
    
    // Radio button handling
    samePersonYes.addEventListener('change', function() {
        if (this.checked) {
            familySelection.style.display = 'none';
            selectedInsuredParties = [selectedInsuranceHolder];
            step2Next.disabled = false;
        }
    });
    
    samePersonNo.addEventListener('change', function() {
        if (this.checked) {
            loadFamilyMembers();
            familySelection.style.display = 'block';
            selectedInsuredParties = [];
            step2Next.disabled = true;
        }
    });
    
    // Load family members
    function loadFamilyMembers() {
        if (!selectedInsuranceHolder) return;
        
        const familyMembersDiv = document.getElementById('family-members');
        let familyHTML = '';
        
        // Spouse
        if (selectedInsuranceHolder.spouse_name) {
            familyHTML += `
                <div class="ab-selection-card" onclick="toggleFamilyMember(this, 'spouse')">
                    <input type="checkbox" class="family-member-checkbox" data-type="spouse">
                    <div>
                        <h4><i class="fas fa-heart"></i> Eş</h4>
                        <p><strong>${selectedInsuranceHolder.spouse_name}</strong></p>
                        <small>Doğum Tarihi: ${selectedInsuranceHolder.spouse_birth_date || 'Bilinmiyor'}</small>
                    </div>
                </div>
            `;
        }
        
        // Children
        if (selectedInsuranceHolder.children_names) {
            const childrenNames = selectedInsuranceHolder.children_names.split(',');
            const childrenBirthDates = selectedInsuranceHolder.children_birth_dates ? 
                selectedInsuranceHolder.children_birth_dates.split(',') : [];
            
            childrenNames.forEach((childName, index) => {
                if (childName.trim()) {
                    familyHTML += `
                        <div class="ab-selection-card" onclick="toggleFamilyMember(this, 'child', ${index})">
                            <input type="checkbox" class="family-member-checkbox" data-type="child" data-index="${index}">
                            <div>
                                <h4><i class="fas fa-child"></i> Çocuk</h4>
                                <p><strong>${childName.trim()}</strong></p>
                                <small>Doğum Tarihi: ${childrenBirthDates[index] ? childrenBirthDates[index].trim() : 'Bilinmiyor'}</small>
                            </div>
                        </div>
                    `;
                }
            });
        }
        
        // Pet
        if (selectedInsuranceHolder.pet_name) {
            familyHTML += `
                <div class="ab-selection-card" onclick="toggleFamilyMember(this, 'pet')">
                    <input type="checkbox" class="family-member-checkbox" data-type="pet">
                    <div>
                        <h4><i class="fas fa-paw"></i> Evcil Hayvan</h4>
                        <p><strong>${selectedInsuranceHolder.pet_name}</strong></p>
                        <small>Tür: ${selectedInsuranceHolder.pet_type || 'Bilinmiyor'} - Yaş: ${selectedInsuranceHolder.pet_age || 'Bilinmiyor'}</small>
                    </div>
                </div>
            `;
        }
        
        if (!familyHTML) {
            familyHTML = '<p>Bu müşterinin kayıtlı aile üyesi veya evcil hayvanı bulunmamaktadır.</p>';
        }
        
        familyMembersDiv.innerHTML = familyHTML;
    }
    
    // Toggle family member selection
    window.toggleFamilyMember = function(card, type, index = null) {
        const checkbox = card.querySelector('input[type="checkbox"]');
        checkbox.checked = !checkbox.checked;
        
        if (checkbox.checked) {
            card.classList.add('selected');
        } else {
            card.classList.remove('selected');
        }
        
        updateSelectedInsuredParties();
    };
    
    // Update selected insured parties
    function updateSelectedInsuredParties() {
        const checkboxes = document.querySelectorAll('.family-member-checkbox:checked');
        selectedInsuredParties = [];
        
        checkboxes.forEach(checkbox => {
            const type = checkbox.getAttribute('data-type');
            const index = checkbox.getAttribute('data-index');
            
            if (type === 'spouse') {
                selectedInsuredParties.push({
                    type: 'spouse',
                    name: selectedInsuranceHolder.spouse_name,
                    birth_date: selectedInsuranceHolder.spouse_birth_date
                });
            } else if (type === 'child') {
                const childrenNames = selectedInsuranceHolder.children_names.split(',');
                const childrenBirthDates = selectedInsuranceHolder.children_birth_dates ? 
                    selectedInsuranceHolder.children_birth_dates.split(',') : [];
                
                selectedInsuredParties.push({
                    type: 'child',
                    name: childrenNames[index] ? childrenNames[index].trim() : '',
                    birth_date: childrenBirthDates[index] ? childrenBirthDates[index].trim() : ''
                });
            } else if (type === 'pet') {
                selectedInsuredParties.push({
                    type: 'pet',
                    name: selectedInsuranceHolder.pet_name,
                    pet_type: selectedInsuranceHolder.pet_type,
                    age: selectedInsuranceHolder.pet_age
                });
            }
        });
        
        step2Next.disabled = selectedInsuredParties.length === 0;
    }
    
    // Step navigation function
    function goToStep(step) {
        // Hide all steps
        document.querySelectorAll('.ab-step-content').forEach(content => {
            content.classList.remove('active');
        });
        
        // Remove active class from all steps
        document.querySelectorAll('.ab-step').forEach(stepEl => {
            stepEl.classList.remove('active', 'completed');
        });
        
        // Show current step
        document.getElementById('step-' + step).classList.add('active');
        
        // Update step progress
        for (let i = 1; i <= 3; i++) {
            const stepEl = document.querySelector(`.ab-step[data-step="${i}"]`);
            if (i < step) {
                stepEl.classList.add('completed');
            } else if (i === step) {
                stepEl.classList.add('active');
            }
        }
        
        currentStep = step;
        document.getElementById('current_step').value = step;
    }
    
    // File upload functionality
    const fileUploadArea = document.getElementById('policy-file-upload-area');
    const fileInput = document.getElementById('policy_files');
    const filePreview = document.getElementById('policy-file-preview');
    
    if (fileUploadArea && fileInput) {
        fileUploadArea.addEventListener('click', () => fileInput.click());
        
        fileUploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            fileUploadArea.classList.add('ab-drag-over');
        });
        
        fileUploadArea.addEventListener('dragleave', (e) => {
            e.preventDefault();
            fileUploadArea.classList.remove('ab-drag-over');
        });
        
        fileUploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            fileUploadArea.classList.remove('ab-drag-over');
            const files = e.dataTransfer.files;
            handleFileSelection(files);
        });
        
        fileInput.addEventListener('change', (e) => {
            handleFileSelection(e.target.files);
        });
    }
    
    function handleFileSelection(files) {
        if (files.length > 5) {
            alert('En fazla 5 dosya yükleyebilirsiniz.');
            return;
        }
        
        filePreview.innerHTML = '';
        Array.from(files).forEach((file, index) => {
            if (file.size > 5 * 1024 * 1024) {
                alert(`${file.name} dosyası 5MB'dan büyük.`);
                return;
            }
            
            const fileItem = document.createElement('div');
            fileItem.className = 'ab-file-preview-item';
            fileItem.innerHTML = `
                <div class="ab-file-icon"><i class="fas fa-file"></i></div>
                <div class="ab-file-name">${file.name}</div>
            `;
            filePreview.appendChild(fileItem);
        });
    }
    
    // Policy type change handler
    const policyTypeSelect = document.getElementById('policy_type');
    if (policyTypeSelect) {
        policyTypeSelect.addEventListener('change', updatePlateField);
    }
}

// Legacy functions for backward compatibility
function updatePlateField() {
    const policyTypeSelect = document.getElementById('policy_type');
    const plateField = document.getElementById('plate_field');
    
    if (!policyTypeSelect || !plateField) return;
    
    const policyType = policyTypeSelect.value.toLowerCase();
    const plateInput = document.getElementById('plate_number');
    
    if (policyType === 'kasko' || policyType === 'trafik') {
        plateField.style.display = 'block';
        if (plateInput) {
            plateInput.setAttribute('required', 'required');
        }
    } else {
        plateField.style.display = 'none';
        if (plateInput) {
            plateInput.removeAttribute('required');
            plateInput.value = '';
        }
    }
}

function setupInsuredCheckbox() {
    const sameAsInsuredCheckbox = document.getElementById('same_as_insured');
    const insuredPartyInput = document.getElementById('insured_party');
    
    if (sameAsInsuredCheckbox && insuredPartyInput) {
        sameAsInsuredCheckbox.addEventListener('change', function() {
            insuredPartyInput.disabled = this.checked;
            if (this.checked) {
                insuredPartyInput.value = '';
            }
        });
        
        if (sameAsInsuredCheckbox.checked) {
            insuredPartyInput.disabled = true;
        }
    }
}

function fetchCustomerDetails(customerId) {
    if (!customerId) return;
    
    // Sigorta şirketi ve poliçe kategorisi önceden seçili ise kontrol et
    const policyCategory = document.getElementById('policy_category');
    if (policyCategory && policyCategory.value === '') {
        const firstOption = policyCategory.querySelector('option:not([value=""])');
        if (firstOption) {
            policyCategory.value = firstOption.value;
        }
    }
    
    // Checkbox durumunu kontrol et
    setupInsuredCheckbox();
    
    // Dosya seçildiğinde etiketi güncelle
    const fileUpload = document.querySelector('input[type="file"]');
    const fileUploadLabel = document.querySelector('.file-upload-input');
    
    if (fileUpload && fileUploadLabel) {
        fileUpload.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                fileUploadLabel.textContent = this.files[0].name;
            }
        });
    }
    
    // İptal durumu seçildiğinde uyarı
    const statusSelect = document.getElementById('status');
    if (statusSelect) {
        statusSelect.addEventListener('change', function() {
            if (this.value === 'Zeyil') {
                alert('Dikkat: Zeyil durumu seçtiniz. Bu işlem genellikle İptal Et butonunu kullanarak yapılmalıdır. Buradan yapılan değişiklikler iptal bilgilerini içermeyecektir.');
            }
        });
    }
    
    // Tarih aralığı kontrolleri
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    if (startDateInput && endDateInput) {
        startDateInput.addEventListener('change', function() {
            // Başlangıç tarihi değiştiğinde, bitiş tarihini otomatik olarak 1 yıl sonraya ayarla
            const startDate = new Date(this.value);
            const endDate = new Date(startDate);
            endDate.setFullYear(endDate.getFullYear() + 1);
            
            // Bitiş tarihini ayarla
            endDateInput.valueAsDate = endDate;
            
            // Eski kontrol: bitiş tarihi başlangıç tarihinden önce veya aynı olamaz
            if (endDateInput.value && new Date(endDateInput.value) <= new Date(this.value)) {
                endDateInput.valueAsDate = endDate;
            }
        });
    }
    
    // İptal tarihi kontrolü
    const cancellationDateInput = document.getElementById('cancellation_date');
    
    if (cancellationDateInput && startDateInput && endDateInput) {
        cancellationDateInput.addEventListener('change', function() {
            const cancellationDate = new Date(this.value);
            const startDate = new Date(startDateInput.value);
            const endDate = new Date(endDateInput.value);
            
            if (cancellationDate < startDate || cancellationDate > endDate) {
                alert('İptal tarihi, poliçe başlangıç ve bitiş tarihleri arasında olmalıdır.');
                this.valueAsDate = new Date();
            }
        });
    }
    
    // İade tutarı kontrolleri
    const premiumAmountInput = document.getElementById('premium_amount');
    const refundedAmountInput = document.getElementById('refunded_amount');
    
    if (premiumAmountInput && refundedAmountInput) {
        refundedAmountInput.addEventListener('change', function() {
            const premiumAmount = parseFloat(premiumAmountInput.value);
            const refundedAmount = parseFloat(this.value);
            
            if (refundedAmount > premiumAmount) {
                alert('İade tutarı, prim tutarından büyük olamaz.');
                this.value = premiumAmount;
            }
        });
    }
    
    // Plaka formatı kontrolü
    const plateInput = document.getElementById('plate_number');
    if (plateInput) {
        plateInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }
    
    // Poliçe türü değişince plaka alanını güncelle
    const policyType = document.getElementById('policy_type');
    if (policyType) {
        policyType.addEventListener('change', updatePlateField);
    }
    
    // Form validasyonu
    const form = document.querySelector('form');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let valid = true;
            
            requiredFields.forEach(function(field) {
                if (!field.value.trim()) {
                    valid = false;
                    field.style.borderColor = '#f44336';
                    
                    // Form alanının üstüne kaydır
                    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    field.style.borderColor = '';
                }
            });
            
            if (!valid) {
                e.preventDefault();
                alert('Lütfen tüm zorunlu alanları doldurun.');
            }
        });
    }
    
    // Müşteri bilgilerini al (eğer müşteri seçilmişse)
    const customerSelect = document.getElementById('customer_id');
    if (customerSelect && customerSelect.value) {
        fetchCustomerDetails(customerSelect.value);
    }
    
    // Müşteri değiştiğinde bilgileri yenile
    if (customerSelect) {
        customerSelect.addEventListener('change', function() {
            fetchCustomerDetails(this.value);
        });
    }
});

// Sigortalı ile Sigortalayan Aynı Kişi mi? seçeneği için
function setupInsuredCheckbox() {
    const sameAsInsuredCheckbox = document.getElementById('same_as_insured');
    const insuredPartyInput = document.getElementById('insured_party');
    
    if (sameAsInsuredCheckbox && insuredPartyInput) {
        sameAsInsuredCheckbox.addEventListener('change', function() {
            insuredPartyInput.disabled = this.checked;
            if (this.checked) {
                insuredPartyInput.value = '';
            }
        });
        
        // Sayfa yüklendiğinde kontrol
        if (sameAsInsuredCheckbox.checked) {
            insuredPartyInput.disabled = true;
        }
    }
}

// Müşteri detaylarını getir
function fetchCustomerDetails(customerId) {
    if (!customerId) return;
    
    // AJAX isteği için endpoint
    const endpoint = ajaxurl || (window.location.href.split('?')[0] + '?action=get_customer_info');
    
    // Form verisi oluştur
    const formData = new FormData();
    formData.append('action', 'get_customer_info');
    formData.append('customer_id', customerId);
    
    // AJAX isteği başlat
    fetch(endpoint, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCustomerDetails(data.data);
        } else {
            console.error('Müşteri bilgileri alınamadı:', data.message);
        }
    })
    .catch(error => {
        console.error('AJAX isteği başarısız oldu:', error);
    });
}

// Müşteri bilgilerini güncelle
function updateCustomerDetails(customer) {
    const customerDetails = document.getElementById('customer_details');
    if (!customerDetails) return;
    
    customerDetails.innerHTML = `
        <div class="form-row">
            <label>TC Kimlik No</label>
            <input type="text" id="customer_tc" class="form-input" value="${customer.tc_identity || ''}" readonly>
        </div>
        
        <div class="form-row">
            <label>Telefon</label>
            <input type="text" id="customer_phone" class="form-input" value="${customer.phone || ''}" readonly>
        </div>
        
        <div class="form-row">
            <label>E-posta</label>
            <input type="text" id="customer_email" class="form-input" value="${customer.email || ''}" readonly>
        </div>
        
        <div class="form-row">
            <label for="insured_party">Sigortalayan</label>
            <input type="text" name="insured_party" id="insured_party" class="form-input" 
                   value="" 
                   placeholder="Sigortalayan farklıysa lütfen isim soyisim girin">
            
            <div class="checkbox-row">
                <input type="checkbox" name="same_as_insured" id="same_as_insured" value="yes" checked>
                <label for="same_as_insured">Sigortalı ile Sigortalayan Aynı Kişi mi?</label>
            </div>
        </div>
    `;
    
    // Checkbox işlevini tekrar tanımla
    setupInsuredCheckbox();
}

// Kasko/Trafik seçiminde plaka alanını göster/gizle
function updatePlateField() {
    const policyTypeSelect = document.getElementById('policy_type');
    const plateField = document.getElementById('plate_field');
    
    if (!policyTypeSelect || !plateField) return;
    
    const policyType = policyTypeSelect.value.toLowerCase();
    const plateInput = document.getElementById('plate_number');
    
    // Kasko veya Trafik seçiliyse plaka alanını göster ve zorunlu yap
    if (policyType === 'kasko' || policyType === 'trafik') {
        plateField.style.display = 'block';
        if (plateInput) {
            plateInput.setAttribute('required', 'required');
        }
    } else {
        // Diğer poliçe türleri için plaka alanını gizle ve zorunlu olma özelliğini kaldır
        plateField.style.display = 'none';
        if (plateInput) {
            plateInput.removeAttribute('required');
            plateInput.value = ''; // Plaka değerini temizle
        }
    }
}

// AJAX endpoint'i için destek
var ajaxurl = "<?php echo admin_url('admin-ajax.php'); ?>";

// Sayfa yüklendiğinde ve DOM hazır olduğunda yeniden çalıştır
window.addEventListener('load', function() {
    setTimeout(function() {
        updatePlateField(); // Poliçe türüne göre plaka alanını kontrol et
        
        // Sigorta şirketi ve poliçe kategorisi için varsayılan değerler
        const insuranceCompany = document.getElementById('insurance_company');
        const policyCategory = document.getElementById('policy_category');
        
        // Sigorta şirketi seçili değilse ve DB'den gelen değer varsa otomatik doldur
        <?php if (isset($policy) && !empty($policy->insurance_company)): ?>
        if (insuranceCompany && !insuranceCompany.value) {
            // Şirket adının tam eşleşmesini kontrol et
            const companyOptions = Array.from(insuranceCompany.options);
            for (let i = 0; i < companyOptions.length; i++) {
                if (companyOptions[i].value.toLowerCase() === '<?php echo strtolower($policy->insurance_company); ?>') {
                    insuranceCompany.selectedIndex = i;
                    break;
                }
            }
        }
        <?php endif; ?>
        
        // Poliçe kategorisi seçili değilse ve DB'den gelen değer varsa otomatik doldur
        <?php if (isset($policy) && !empty($policy->policy_category)): ?>
        if (policyCategory && !policyCategory.value) {
            // Kategori adının tam eşleşmesini kontrol et
            const categoryOptions = Array.from(policyCategory.options);
            for (let i = 0; i < categoryOptions.length; i++) {
                if (categoryOptions[i].value.toLowerCase() === '<?php echo strtolower($policy->policy_category); ?>') {
                    policyCategory.selectedIndex = i;
                    break;
                }
            }
        }
        <?php endif; ?>
    }, 100);
});
</script>

<!-- WordPress AJAX handler için gerekli kod -->
<?php
add_action('wp_ajax_get_customer_info', 'get_customer_info_callback');
function get_customer_info_callback() {
    global $wpdb;
    
    // Güvenlik kontrolü (isteğe bağlı)
    // check_ajax_referer('customer_nonce', 'nonce');
    
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    
    if (!$customer_id) {
        wp_send_json_error(['message' => 'Geçersiz müşteri ID']);
        return;
    }
    
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $customer_id));
    
    if (!$customer) {
        wp_send_json_error(['message' => 'Müşteri bulunamadı']);
        return;
    }
    
    // Müşteri verilerini döndür
    wp_send_json_success([
        'id' => $customer->id,
        'first_name' => $customer->first_name,
        'last_name' => $customer->last_name,
        'tc_identity' => $customer->tc_identity,
        'phone' => $customer->phone,
        'email' => $customer->email,
        'address' => $customer->address
    ]);
}

// **NEW**: AJAX handler for customer search in multi-step form
add_action('wp_ajax_search_customers', 'search_customers_callback');
function search_customers_callback() {
    global $wpdb;
    
    // Security check
    if (!wp_verify_nonce($_POST['nonce'], 'search_customers')) {
        wp_send_json_error(['message' => 'Güvenlik kontrolü başarısız']);
        return;
    }
    
    $query = sanitize_text_field($_POST['query']);
    
    if (strlen($query) < 2) {
        wp_send_json_error(['message' => 'En az 2 karakter giriniz']);
        return;
    }
    
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    
    // Search in multiple fields
    $customers = $wpdb->get_results($wpdb->prepare(
        "SELECT id, first_name, last_name, company_name, tc_identity, tax_number, phone, email 
         FROM $customers_table 
         WHERE (first_name LIKE %s 
                OR last_name LIKE %s 
                OR company_name LIKE %s 
                OR tc_identity LIKE %s 
                OR tax_number LIKE %s 
                OR phone LIKE %s) 
         AND status = 'active'
         ORDER BY first_name, last_name
         LIMIT 10",
        '%' . $query . '%',
        '%' . $query . '%',
        '%' . $query . '%',
        '%' . $query . '%',
        '%' . $query . '%',
        '%' . $query . '%'
    ));
    
    wp_send_json_success($customers);
}

// **NEW**: AJAX handler for getting full customer details
add_action('wp_ajax_get_customer_details', 'get_customer_details_callback');
function get_customer_details_callback() {
    global $wpdb;
    
    // Security check
    if (!wp_verify_nonce($_POST['nonce'], 'get_customer_details')) {
        wp_send_json_error(['message' => 'Güvenlik kontrolü başarısız']);
        return;
    }
    
    $customer_id = intval($_POST['customer_id']);
    
    if (!$customer_id) {
        wp_send_json_error(['message' => 'Geçersiz müşteri ID']);
        return;
    }
    
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $customer_id));
    
    if (!$customer) {
        wp_send_json_error(['message' => 'Müşteri bulunamadı']);
        return;
    }
    
    // Return full customer data including family information
    wp_send_json_success((array) $customer);
}
?>
?>