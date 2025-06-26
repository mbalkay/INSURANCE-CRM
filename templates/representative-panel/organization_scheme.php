<?php
/**
 * Insurance CRM - Organization Scheme Management
 *
 * @package     Insurance_CRM
 * @author      Mehmet BALKAY | Anadolu Birlik
 * @copyright   2025 Anadolu Birlik
 * @license     GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit;
}

// Güvenlik kontrolleri
if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/temsilci-girisi/'));
    exit;
}

$user = wp_get_current_user();
if (!in_array('insurance_representative', (array)$user->roles)) {
    wp_safe_redirect(home_url());
    exit;
}

// Yetki kontrolü - sadece full admin erişimi
if (!has_full_admin_access($user->ID)) {
    wp_die('Bu sayfaya erişim yetkiniz bulunmuyor.');
}

// CSRF koruması
$nonce_action = 'update_organization_hierarchy';
$nonce_field = 'organization_nonce';

global $wpdb;

// Mevcut temsilciler ve rolleri al
$representatives = $wpdb->get_results(
    "SELECT r.*, u.display_name, u.user_email 
     FROM {$wpdb->prefix}insurance_crm_representatives r 
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
     WHERE r.status = 'active'
     ORDER BY r.role ASC, u.display_name ASC"
);

// Null check
if (!$representatives) {
    $representatives = [];
}

$current_hierarchy = get_management_hierarchy();
if (!$current_hierarchy) {
    $current_hierarchy = [
        'patron_id' => 0,
        'manager_id' => 0,
        'assistant_manager_ids' => []
    ];
}

// Ekip verilerini al
$settings = get_option('insurance_crm_settings', []);
$teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : [];

// Form işleme
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_organization'])) {
    // CSRF kontrolü
    if (!wp_verify_nonce($_POST[$nonce_field] ?? '', $nonce_action)) {
        wp_die('Güvenlik kontrolü başarısız oldu.');
    }
    
    // Yetki kontrolü tekrar
    if (!has_full_admin_access($user->ID)) {
        $error_message = 'Bu işlem için yetkiniz bulunmuyor.';
    } else {
        // Input sanitization
        $patron_id = intval($_POST['patron_id'] ?? 0);
        $manager_id = intval($_POST['manager_id'] ?? 0);
        $assistant_ids = isset($_POST['assistant_manager_ids']) ? 
            array_map('intval', array_filter((array)$_POST['assistant_manager_ids'])) : [];
        
        // Validation
        $validation_errors = [];
        
        // Aynı kişi birden fazla rolde olamaz
        if ($patron_id && $manager_id && $patron_id === $manager_id) {
            $validation_errors[] = 'Patron ve Müdür aynı kişi olamaz.';
        }
        
        if ($patron_id && in_array($patron_id, $assistant_ids)) {
            $validation_errors[] = 'Patron aynı zamanda Müdür Yardımcısı olamaz.';
        }
        
        if ($manager_id && in_array($manager_id, $assistant_ids)) {
            $validation_errors[] = 'Müdür aynı zamanda Müdür Yardımcısı olamaz.';
        }
        
        // Seçilen kişilerin gerçekten var olup olmadığını kontrol et
        $valid_rep_ids = array_column($representatives, 'id');
        
        if ($patron_id && !in_array($patron_id, $valid_rep_ids)) {
            $validation_errors[] = 'Seçilen patron geçerli değil.';
        }
        
        if ($manager_id && !in_array($manager_id, $valid_rep_ids)) {
            $validation_errors[] = 'Seçilen müdür geçerli değil.';
        }
        
        foreach ($assistant_ids as $aid) {
            if (!in_array($aid, $valid_rep_ids)) {
                $validation_errors[] = 'Seçilen müdür yardımcılarından biri geçerli değil.';
                break;
            }
        }
        
        if (!empty($validation_errors)) {
            $error_message = implode(' ', $validation_errors);
        } else {
            $new_hierarchy = [
                'patron_id' => $patron_id,
                'manager_id' => $manager_id,
                'assistant_manager_ids' => $assistant_ids
            ];
            
            if (update_management_hierarchy($new_hierarchy)) {
                $success_message = 'Organizasyon yapısı başarıyla güncellendi.';
                $current_hierarchy = $new_hierarchy;
                
                // Rolleri güncelle
                foreach ($representatives as $rep) {
                    $new_role = 5; // Varsayılan olarak temsilci
                    
                    if ($rep->id == $new_hierarchy['patron_id']) {
                        $new_role = 1; // Patron
                    } elseif ($rep->id == $new_hierarchy['manager_id']) {
                        $new_role = 2; // Müdür
                    } elseif (in_array($rep->id, $new_hierarchy['assistant_manager_ids'])) {
                        $new_role = 3; // Müdür Yardımcısı
                    }
                    
                    // Ekip lideri kontrolü
                    if ($new_role == 5) {
                        $is_team_leader = false;
                        foreach ($teams as $team) {
                            if (isset($team['leader_id']) && $team['leader_id'] == $rep->id) {
                                $is_team_leader = true;
                                break;
                            }
                        }
                        
                        if ($is_team_leader) {
                            $new_role = 4; // Ekip Lideri
                        }
                    }
                    
                    // Rol güncelleme
                    if ($rep->role != $new_role) {
                        $update_result = $wpdb->update(
                            $wpdb->prefix . 'insurance_crm_representatives',
                            ['role' => $new_role],
                            ['id' => $rep->id],
                            ['%d'],
                            ['%d']
                        );
                        
                        if ($update_result === false) {
                            error_log('Role update failed for representative ID: ' . $rep->id);
                        }
                    }
                }
                
                // Activity log
                $log_message = sprintf(
                    'Organizasyon yapısı güncellendi - Patron: %d, Müdür: %d, Yardımcılar: %s',
                    $patron_id,
                    $manager_id,
                    implode(',', $assistant_ids)
                );
                error_log($log_message);
                
                // Redirect to prevent resubmission
                $redirect_url = add_query_arg('updated', '1', $_SERVER['REQUEST_URI']);
                echo '<meta http-equiv="refresh" content="1;url=' . esc_url($redirect_url) . '">';
            } else {
                $error_message = 'Organizasyon yapısı güncellenirken bir hata oluştu.';
                error_log('Management hierarchy update failed');
            }
        }
    }
}

// Success message from redirect
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $success_message = 'Organizasyon yapısı başarıyla güncellendi.';
}
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Organizasyon Şeması Yönetimi - <?php bloginfo('name'); ?></title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" crossorigin="anonymous">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* === GLOBAL STYLES === */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: #2d3748;
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* === MODERN HEADER === */
        .org-modern-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 20px 40px rgba(102, 126, 234, 0.2);
            position: relative;
            overflow: hidden;
        }

        .org-modern-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><polygon fill="rgba(255,255,255,0.1)" points="0,0 1000,300 1000,1000 0,700"/></svg>');
            pointer-events: none;
        }

        .header-content-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .header-main-info {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .header-icon-wrapper {
            position: relative;
        }

        .header-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .header-icon i {
            font-size: 36px;
            color: white;
        }

        .header-text-content h1 {
            color: white;
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .header-text-content p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 18px;
            margin-bottom: 15px;
        }

        .header-stats {
            display: flex;
            gap: 20px;
        }

        .stat-item {
            background: rgba(255, 255, 255, 0.15);
            padding: 8px 16px;
            border-radius: 12px;
            color: white;
            font-size: 14px;
            font-weight: 500;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .stat-item i {
            margin-right: 8px;
        }

        .header-actions {
            display: flex;
            gap: 15px;
        }

        /* === MODERN BUTTONS === */
        .btn-modern {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .btn-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn-modern:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4c51bf, #667eea);
            color: white;
            box-shadow: 0 4px 15px rgba(76, 81, 191, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(76, 81, 191, 0.4);
            color: white;
            text-decoration: none;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            transform: translateY(-2px);
            text-decoration: none;
        }

        .btn-success {
            background: linear-gradient(135deg, #48bb78, #38a169);
            color: white;
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 187, 120, 0.4);
        }

        .btn-outline {
            background: transparent;
            color: #4a5568;
            border: 2px solid #e2e8f0;
        }

        .btn-outline:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
            transform: translateY(-2px);
        }

        .btn-icon {
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            color: #718096;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-icon:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #4a5568;
            transform: translateY(-2px);
        }

        /* === WORKSPACE LAYOUT === */
        .organization-workspace {
            margin-top: 30px;
        }

        .workspace-grid {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 30px;
        }

        /* === PANELS === */
        .hierarchy-panel,
        .preview-panel {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .panel-header {
            background: linear-gradient(135deg, #f7fafc, #edf2f7);
            padding: 25px 30px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .panel-header h3 {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .panel-header h3 i {
            color: #667eea;
        }

        .panel-actions {
            display: flex;
            gap: 10px;
        }

        /* === ALERTS === */
        .alert-modern {
            display: flex;
            align-items: flex-start;
            padding: 20px;
            margin: 20px 30px;
            border-radius: 12px;
            gap: 15px;
        }

        .alert-success {
            background: linear-gradient(135deg, #f0fff4, #e6fffa);
            border-left: 4px solid #48bb78;
        }

        .alert-error {
            background: linear-gradient(135deg, #fff5f5, #fed7d7);
            border-left: 4px solid #f56565;
        }

        .alert-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .alert-success .alert-icon {
            color: #48bb78;
        }

        .alert-error .alert-icon {
            color: #f56565;
        }

        .alert-content h4 {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .alert-content p {
            font-size: 14px;
            opacity: 0.9;
        }

        /* === FORM SECTIONS === */
        .hierarchy-form {
            padding: 30px;
        }

        .form-sections {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .form-section {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 25px;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .form-section:hover {
            border-color: #e2e8f0;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .section-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .patron-icon {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
        }

        .manager-icon {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        }

        .assistant-icon {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .section-info h4 {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 4px;
        }

        .section-info p {
            font-size: 14px;
            color: #718096;
        }

        /* === MODERN SELECTS === */
        .select-wrapper {
            position: relative;
        }

        .modern-select {
            width: 100%;
            padding: 15px 50px 15px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            background: white;
            font-size: 16px;
            color: #2d3748;
            appearance: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .modern-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .select-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
            pointer-events: none;
            transition: transform 0.3s ease;
        }

        .modern-select:focus + .select-icon {
            transform: translateY(-50%) rotate(180deg);
        }

        /* === MULTI SELECT === */
        .multi-select-wrapper {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            min-height: 120px;
            padding: 15px;
        }

        .selected-items {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
            min-height: 40px;
        }

        .selected-item {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: slideInItem 0.3s ease;
        }

        .selected-item .remove-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }

        .selected-item .remove-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .add-assistant-btn {
            background: linear-gradient(135deg, #e2e8f0, #cbd5e0);
            color: #4a5568;
            border: 2px dashed #a0aec0;
            border-radius: 12px;
            padding: 15px;
            width: 100%;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-weight: 600;
        }

        .add-assistant-btn:hover {
            background: linear-gradient(135deg, #f7fafc, #edf2f7);
            border-color: #718096;
            transform: translateY(-2px);
        }

        /* === FORM ACTIONS === */
        .form-actions {
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        /* === PREVIEW PANEL === */
        .preview-content {
            padding: 30px;
            min-height: 400px;
        }

        .org-chart-preview {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 25px;
            min-height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .preview-loading {
            text-align: center;
            color: #718096;
        }

        .preview-loading i {
            font-size: 32px;
            margin-bottom: 15px;
            color: #667eea;
        }

        /* === ORGANIZATION CHART === */
        .org-chart {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }

        .org-level {
            display: flex;
            justify-content: center;
            gap: 20px;
            width: 100%;
        }

        .org-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            min-width: 200px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .org-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .org-card.patron-card {
            border-color: #fbbf24;
        }

        .org-card.manager-card {
            border-color: #3b82f6;
        }

        .org-card.assistant-card {
            border-color: #10b981;
        }

        .org-card-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: 700;
        }

        .patron-card .org-card-avatar {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
        }

        .manager-card .org-card-avatar {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        }

        .assistant-card .org-card-avatar {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .org-card-name {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .org-card-title {
            font-size: 14px;
            color: #718096;
            margin-bottom: 10px;
        }

        .org-card-role {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .org-connector {
            width: 2px;
            height: 30px;
            background: linear-gradient(to bottom, #e2e8f0, #cbd5e0);
        }

        /* === STATS GRID === */
        .preview-stats {
            margin-top: 25px;
        }

        .preview-stats h4 {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .stat-box {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-box .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .stat-details {
            flex: 1;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            line-height: 1;
        }

        .stat-label {
            font-size: 12px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* === MODAL === */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-container {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            animation: slideInModal 0.3s ease;
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 20px;
            font-weight: 600;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 30px;
            max-height: 50vh;
            overflow-y: auto;
        }

        .assistant-search {
            margin-bottom: 20px;
        }

        .assistant-search input {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .assistant-search input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .assistant-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .assistant-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .assistant-item:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }

        .assistant-item.selected {
            border-color: #667eea;
            background: linear-gradient(135deg, #f8f9ff, #e6e9ff);
        }

        .assistant-item input[type="checkbox"] {
            margin-right: 15px;
            width: 18px;
            height: 18px;
        }

        .assistant-info {
            flex: 1;
        }

        .assistant-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 3px;
        }

        .assistant-title {
            font-size: 14px;
            color: #718096;
        }

        .modal-footer {
            background: #f8f9fa;
            padding: 20px 30px;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        /* === ANIMATIONS === */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideInModal {
            from { 
                opacity: 0; 
                transform: translateY(-20px) scale(0.95); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0) scale(1); 
            }
        }

        @keyframes slideInItem {
            from { 
                opacity: 0; 
                transform: translateX(-10px); 
            }
            to { 
                opacity: 1; 
                transform: translateX(0); 
            }
        }

        /* === RESPONSIVE === */
        @media (max-width: 1200px) {
            .workspace-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            .org-modern-header {
                padding: 25px;
            }

            .header-content-wrapper {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }

            .header-main-info {
                flex-direction: column;
                gap: 15px;
            }

            .header-text-content h1 {
                font-size: 28px;
            }

            .header-stats {
                flex-direction: column;
                gap: 10px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .form-section {
                padding: 20px;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn-modern {
                width: 100%;
                justify-content: center;
            }

            .modal-container {
                width: 95%;
                margin: 10px;
            }

            .modal-header {
                padding: 20px;
            }

            .modal-body {
                padding: 20px;
            }

            .org-level {
                flex-direction: column;
                align-items: center;
            }

            .org-card {
                min-width: auto;
                width: 100%;
                max-width: 280px;
            }
        }

        @media (max-width: 480px) {
            .org-modern-header {
                padding: 20px;
            }

            .header-text-content h1 {
                font-size: 24px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .panel-header {
                padding: 20px;
            }

            .hierarchy-form {
                padding: 20px;
            }
        }

        /* === LOADING STATES === */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* === CUSTOM SCROLLBAR === */
        .modal-body::-webkit-scrollbar,
        .assistant-list::-webkit-scrollbar {
            width: 8px;
        }

        .modal-body::-webkit-scrollbar-track,
        .assistant-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .modal-body::-webkit-scrollbar-thumb,
        .assistant-list::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        .modal-body::-webkit-scrollbar-thumb:hover,
        .assistant-list::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* === SECURITY NOTICE === */
        .security-notice {
            background: linear-gradient(135deg, #fff3cd, #fef3c7);
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #92400e;
        }

        .security-notice i {
            margin-right: 8px;
            color: #f59e0b;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Modern Header -->
        <div class="org-modern-header">
            <div class="header-content-wrapper">
                <div class="header-main-info">
                    <div class="header-icon-wrapper">
                        <div class="header-icon">
                            <i class="fas fa-sitemap"></i>
                        </div>
                    </div>
                    <div class="header-text-content">
                        <h1>Organizasyon Yönetimi</h1>
                        <p>Şirket hiyerarşinizi düzenleyin ve yönetin</p>
                        <div class="header-stats">
                            <span class="stat-item">
                                <i class="fas fa-users"></i>
                                <?php echo count($representatives); ?> Aktif Personel
                            </span>
                            <span class="stat-item">
                                <i class="fas fa-layer-group"></i>
                                <?php echo count($teams); ?> Ekip
                            </span>
                        </div>
                    </div>
                </div>
                <div class="header-actions">
                    <?php 
                    $dashboard_url = function_exists('generate_panel_url') ? 
                        generate_panel_url('dashboard') : 
                        admin_url('admin.php?page=insurance-crm-dashboard');
                    
                    $personnel_url = function_exists('generate_panel_url') ? 
                        generate_panel_url('all_personnel') : 
                        admin_url('admin.php?page=insurance-crm-representatives');
                    ?>
                    <a href="<?php echo esc_url($dashboard_url); ?>" class="btn-modern btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="<?php echo esc_url($personnel_url); ?>" class="btn-modern btn-primary">
                        <i class="fas fa-users-cog"></i>
                        <span>Personel Yönetimi</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Main Organization Content -->
        <div class="organization-workspace">
            <div class="workspace-grid">
                <!-- Left Panel - Hierarchy Management -->
                <div class="hierarchy-panel">
                    <div class="panel-header">
                        <h3>
                            <i class="fas fa-sitemap"></i>
                            Yönetim Hiyerarşisi
                        </h3>
                        <div class="panel-actions">
                            <button type="button" class="btn-icon" onclick="resetHierarchy()" title="Sıfırla">
                                <i class="fas fa-undo"></i>
                            </button>
                            <button type="button" class="btn-icon" onclick="previewHierarchy()" title="Önizle">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Security Notice -->
                    <div class="security-notice">
                        <i class="fas fa-shield-alt"></i>
                        Bu sayfada yapacağınız değişiklikler tüm sistem yetkilendirmelerini etkileyecektir. Lütfen dikkatli olun.
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($success_message): ?>
                    <div class="alert-modern alert-success">
                        <div class="alert-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="alert-content">
                            <h4>Başarılı!</h4>
                            <p><?php echo esc_html($success_message); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($error_message): ?>
                    <div class="alert-modern alert-error">
                        <div class="alert-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="alert-content">
                            <h4>Hata!</h4>
                            <p><?php echo esc_html($error_message); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Hierarchy Form -->
                    <form action="" method="post" class="hierarchy-form" id="hierarchyForm">
                        <?php wp_nonce_field($nonce_action, $nonce_field); ?>
                        
                        <div class="form-sections">
                            <!-- Patron Section -->
                            <div class="form-section patron-section">
                                <div class="section-header">
                                    <div class="section-icon patron-icon">
                                        <i class="fas fa-crown"></i>
                                    </div>
                                    <div class="section-info">
                                        <h4>Patron</h4>
                                        <p>Şirket sahibi / En üst düzey yönetici</p>
                                    </div>
                                </div>
                                <div class="section-content">
                                    <div class="select-wrapper">
                                        <select name="patron_id" id="patron_id" class="modern-select" onchange="updatePreview()">
                                            <option value="0">Patron seçiniz...</option>
                                            <?php foreach ($representatives as $rep): ?>
                                                <option value="<?php echo esc_attr($rep->id); ?>" 
                                                        data-email="<?php echo esc_attr($rep->user_email); ?>"
                                                        data-title="<?php echo esc_attr($rep->title); ?>"
                                                        data-name="<?php echo esc_attr($rep->display_name); ?>"
                                                        <?php selected($current_hierarchy['patron_id'], $rep->id); ?>>
                                                    <?php echo esc_html($rep->display_name . ' - ' . $rep->title); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="select-icon">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Manager Section -->
                            <div class="form-section manager-section">
                                <div class="section-header">
                                    <div class="section-icon manager-icon">
                                        <i class="fas fa-user-tie"></i>
                                    </div>
                                    <div class="section-info">
                                        <h4>Müdür</h4>
                                        <p>Operasyonel yönetim sorumlusu</p>
                                    </div>
                                </div>
                                <div class="section-content">
                                    <div class="select-wrapper">
                                        <select name="manager_id" id="manager_id" class="modern-select" onchange="updatePreview()">
                                            <option value="0">Müdür seçiniz...</option>
                                            <?php foreach ($representatives as $rep): ?>
                                                <option value="<?php echo esc_attr($rep->id); ?>" 
                                                        data-email="<?php echo esc_attr($rep->user_email); ?>"
                                                        data-title="<?php echo esc_attr($rep->title); ?>"
                                                        data-name="<?php echo esc_attr($rep->display_name); ?>"
                                                        <?php selected($current_hierarchy['manager_id'], $rep->id); ?>>
                                                    <?php echo esc_html($rep->display_name . ' - ' . $rep->title); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="select-icon">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Assistant Managers Section -->
                            <div class="form-section assistant-section">
                                <div class="section-header">
                                    <div class="section-icon assistant-icon">
                                        <i class="fas fa-users-cog"></i>
                                    </div>
                                    <div class="section-info">
                                        <h4>Müdür Yardımcıları</h4>
                                        <p>Birden fazla seçim yapabilirsiniz</p>
                                    </div>
                                </div>
                                <div class="section-content">
                                    <div class="multi-select-wrapper">
                                        <div class="selected-items" id="selectedAssistants">
                                            <!-- Seçilen öğeler buraya dinamik olarak eklenecek -->
                                        </div>
                                        <select name="assistant_manager_ids[]" id="assistant_manager_ids" class="modern-select" multiple style="display: none;">
                                            <?php foreach ($representatives as $rep): ?>
                                                <option value="<?php echo esc_attr($rep->id); ?>" 
                                                        data-name="<?php echo esc_attr($rep->display_name); ?>"
                                                        data-title="<?php echo esc_attr($rep->title); ?>"
                                                        <?php if (in_array($rep->id, $current_hierarchy['assistant_manager_ids'])) echo 'selected'; ?>>
                                                    <?php echo esc_html($rep->display_name . ' - ' . $rep->title); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="add-assistant-btn" onclick="openAssistantSelector()">
                                            <i class="fas fa-plus"></i>
                                            <span>Müdür Yardımcısı Ekle</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="button" class="btn-modern btn-outline" onclick="resetForm()">
                                <i class="fas fa-undo"></i>
                                <span>Sıfırla</span>
                            </button>
                            <button type="submit" name="update_organization" class="btn-modern btn-success">
                                <i class="fas fa-save"></i>
                                <span>Organizasyonu Kaydet</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Right Panel - Live Preview -->
                <div class="preview-panel">
                    <div class="panel-header">
                        <h3>
                            <i class="fas fa-eye"></i>
                            Canlı Önizleme
                        </h3>
                        <div class="panel-actions">
                            <button type="button" class="btn-icon" onclick="refreshPreview()" title="Yenile">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                            <button type="button" class="btn-icon" onclick="exportHierarchy()" title="Dışa Aktar">
                                <i class="fas fa-download"></i>
                            </button>
                        </div>
                    </div>

                    <div class="preview-content">
                        <div class="org-chart-preview" id="orgChartPreview">
                            <!-- Organizasyon şeması buraya dinamik olarak yüklenecek -->
                            <div class="org-chart" id="orgChart">
                                <!-- JavaScript ile doldurulacak -->
                            </div>
                        </div>

                        <!-- Stats Panel -->
                        <div class="preview-stats">
                            <h4>Organizasyon İstatistikleri</h4>
                            <div class="stats-grid">
                                <div class="stat-box">
                                    <div class="stat-icon">
                                        <i class="fas fa-crown"></i>
                                    </div>
                                    <div class="stat-details">
                                        <div class="stat-number" id="patronCount">0</div>
                                        <div class="stat-label">Patron</div>
                                    </div>
                                </div>
                                
                                <div class="stat-box">
                                    <div class="stat-icon">
                                        <i class="fas fa-user-tie"></i>
                                    </div>
                                    <div class="stat-details">
                                        <div class="stat-number" id="managerCount">0</div>
                                        <div class="stat-label">Müdür</div>
                                    </div>
                                </div>
                                
                                <div class="stat-box">
                                    <div class="stat-icon">
                                        <i class="fas fa-users-cog"></i>
                                    </div>
                                    <div class="stat-details">
                                        <div class="stat-number" id="assistantCount">0</div>
                                        <div class="stat-label">Müdür Yardımcısı</div>
                                    </div>
                                </div>
                                
                                <div class="stat-box">
                                    <div class="stat-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div class="stat-details">
                                        <div class="stat-number" id="totalPersonnel"><?php echo count($representatives); ?></div>
                                        <div class="stat-label">Toplam Personel</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Assistant Selector Modal -->
    <div class="modal-overlay" id="assistantModal">
        <div class="modal-container">
            <div class="modal-header">
                <h3>Müdür Yardımcısı Seç</h3>
                <button type="button" class="modal-close" onclick="closeAssistantSelector()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="assistant-search">
                    <input type="text" id="assistantSearch" placeholder="Personel ara..." onkeyup="filterAssistants()">
                </div>
                <div class="assistant-list" id="assistantList">
                    <!-- Liste JavaScript ile doldurulacak -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modern btn-secondary" onclick="closeAssistantSelector()">İptal</button>
                <button type="button" class="btn-modern btn-primary" onclick="addSelectedAssistants()">Seçilenleri Ekle</button>
            </div>
        </div>
    </div>

    <script>
        // === GLOBAL VARIABLES ===
        let selectedAssistants = [];
        let representatives = <?php echo wp_json_encode($representatives ?: []); ?>;
        let currentHierarchy = <?php echo wp_json_encode($current_hierarchy ?: ['patron_id' => 0, 'manager_id' => 0, 'assistant_manager_ids' => []]); ?>;

        // === INITIALIZATION ===
        document.addEventListener('DOMContentLoaded', function() {
            try {
                initializeForm();
                updatePreview();
                loadSelectedAssistants();
                console.log('Organization Scheme Management System Loaded Successfully');
            } catch (error) {
                console.error('Initialization error:', error);
            }
        });

        // === FORM INITIALIZATION ===
        function initializeForm() {
            try {
                // Load current hierarchy
                if (currentHierarchy.patron_id) {
                    const patronSelect = document.getElementById('patron_id');
                    if (patronSelect) {
                        patronSelect.value = currentHierarchy.patron_id;
                    }
                }
                if (currentHierarchy.manager_id) {
                    const managerSelect = document.getElementById('manager_id');
                    if (managerSelect) {
                        managerSelect.value = currentHierarchy.manager_id;
                    }
                }
                
                selectedAssistants = currentHierarchy.assistant_manager_ids || [];
                updateSelectedAssistantsDisplay();
            } catch (error) {
                console.error('Form initialization error:', error);
            }
        }

        // === ASSISTANT MANAGEMENT ===
        function loadSelectedAssistants() {
            try {
                const select = document.getElementById('assistant_manager_ids');
                if (!select) return;
                
                selectedAssistants = [];
                
                for (let option of select.options) {
                    if (option.selected) {
                        selectedAssistants.push(parseInt(option.value));
                    }
                }
                
                updateSelectedAssistantsDisplay();
            } catch (error) {
                console.error('Load assistants error:', error);
                selectedAssistants = [];
            }
        }

        function updateSelectedAssistantsDisplay() {
            try {
                const container = document.getElementById('selectedAssistants');
                if (!container) return;
                
                container.innerHTML = '';
                
                selectedAssistants.forEach(assistantId => {
                    const rep = representatives.find(r => parseInt(r.id) === assistantId);
                    if (rep) {
                        const itemDiv = document.createElement('div');
                        itemDiv.className = 'selected-item';
                        itemDiv.innerHTML = `
                            <span>${escapeHtml(rep.display_name)} - ${escapeHtml(rep.title || '')}</span>
                            <button type="button" class="remove-btn" onclick="removeAssistant(${assistantId})">
                                <i class="fas fa-times"></i>
                            </button>
                        `;
                        container.appendChild(itemDiv);
                    }
                });
                
                // Update hidden select
                const select = document.getElementById('assistant_manager_ids');
                if (select) {
                    for (let option of select.options) {
                        option.selected = selectedAssistants.includes(parseInt(option.value));
                    }
                }
                
                updatePreview();
            } catch (error) {
                console.error('Update assistants display error:', error);
            }
        }

        function removeAssistant(assistantId) {
            selectedAssistants = selectedAssistants.filter(id => id !== assistantId);
            updateSelectedAssistantsDisplay();
        }

        function openAssistantSelector() {
            try {
                const modal = document.getElementById('assistantModal');
                const list = document.getElementById('assistantList');
                
                if (!modal || !list) return;
                
                // Populate assistant list
                list.innerHTML = '';
                representatives.forEach(rep => {
                    // Skip if already selected, patron, or manager
                    const patronId = parseInt(document.getElementById('patron_id').value);
                    const managerId = parseInt(document.getElementById('manager_id').value);
                    
                    if (selectedAssistants.includes(parseInt(rep.id)) || 
                        parseInt(rep.id) === patronId || 
                        parseInt(rep.id) === managerId) {
                        return;
                    }
                    
                    const itemDiv = document.createElement('div');
                    itemDiv.className = 'assistant-item';
                    itemDiv.innerHTML = `
                        <input type="checkbox" id="assistant_${rep.id}" value="${rep.id}">
                        <div class="assistant-info">
                            <div class="assistant-name">${escapeHtml(rep.display_name)}</div>
                            <div class="assistant-title">${escapeHtml(rep.title || '')}</div>
                        </div>
                    `;
                    
                    itemDiv.addEventListener('click', function() {
                        const checkbox = itemDiv.querySelector('input[type="checkbox"]');
                        checkbox.checked = !checkbox.checked;
                        itemDiv.classList.toggle('selected', checkbox.checked);
                    });
                    
                    list.appendChild(itemDiv);
                });
                
                modal.classList.add('active');
            } catch (error) {
                console.error('Open assistant selector error:', error);
            }
        }

        function closeAssistantSelector() {
            const modal = document.getElementById('assistantModal');
            if (modal) {
                modal.classList.remove('active');
            }
        }

        function addSelectedAssistants() {
            try {
                const checkboxes = document.querySelectorAll('#assistantList input[type="checkbox"]:checked');
                checkboxes.forEach(checkbox => {
                    const assistantId = parseInt(checkbox.value);
                    if (!selectedAssistants.includes(assistantId)) {
                        selectedAssistants.push(assistantId);
                    }
                });
                
                updateSelectedAssistantsDisplay();
                closeAssistantSelector();
            } catch (error) {
                console.error('Add selected assistants error:', error);
            }
        }

        function filterAssistants() {
            try {
                const search = document.getElementById('assistantSearch');
                if (!search) return;
                
                const searchValue = search.value.toLowerCase();
                const items = document.querySelectorAll('.assistant-item');
                
                items.forEach(item => {
                    const nameElement = item.querySelector('.assistant-name');
                    const titleElement = item.querySelector('.assistant-title');
                    
                    if (nameElement && titleElement) {
                        const name = nameElement.textContent.toLowerCase();
                        const title = titleElement.textContent.toLowerCase();
                        
                        if (name.includes(searchValue) || title.includes(searchValue)) {
                            item.style.display = 'flex';
                        } else {
                            item.style.display = 'none';
                        }
                    }
                });
            } catch (error) {
                console.error('Filter assistants error:', error);
            }
        }

        // === PREVIEW MANAGEMENT ===
        function updatePreview() {
            try {
                const patronSelect = document.getElementById('patron_id');
                const managerSelect = document.getElementById('manager_id');
                
                if (!patronSelect || !managerSelect) return;
                
                const patronId = parseInt(patronSelect.value);
                const managerId = parseInt(managerSelect.value);
                
                const patronRep = representatives.find(r => parseInt(r.id) === patronId);
                const managerRep = representatives.find(r => parseInt(r.id) === managerId);
                
                // Update organization chart
                renderOrgChart(patronRep, managerRep, selectedAssistants);
                
                // Update stats
                updateStats(patronRep, managerRep, selectedAssistants);
            } catch (error) {
                console.error('Preview update error:', error);
            }
        }

        function renderOrgChart(patron, manager, assistants) {
            try {
                const chartContainer = document.getElementById('orgChart');
                if (!chartContainer) return;
                
                chartContainer.innerHTML = '';
                
                // Patron Level
                if (patron) {
                    const patronLevel = document.createElement('div');
                    patronLevel.className = 'org-level';
                    patronLevel.innerHTML = `
                        <div class="org-card patron-card">
                            <div class="org-card-avatar">
                                ${getInitials(patron.display_name)}
                            </div>
                            <div class="org-card-name">${escapeHtml(patron.display_name)}</div>
                            <div class="org-card-title">${escapeHtml(patron.title || '')}</div>
                            <div class="org-card-role">PATRON</div>
                        </div>
                    `;
                    chartContainer.appendChild(patronLevel);
                    
                    // Connector
                    if (manager || assistants.length > 0) {
                        const connector = document.createElement('div');
                        connector.className = 'org-connector';
                        chartContainer.appendChild(connector);
                    }
                }
                
                // Manager Level
                if (manager) {
                    const managerLevel = document.createElement('div');
                    managerLevel.className = 'org-level';
                    managerLevel.innerHTML = `
                        <div class="org-card manager-card">
                            <div class="org-card-avatar">
                                ${getInitials(manager.display_name)}
                            </div>
                            <div class="org-card-name">${escapeHtml(manager.display_name)}</div>
                            <div class="org-card-title">${escapeHtml(manager.title || '')}</div>
                            <div class="org-card-role">MÜDÜR</div>
                        </div>
                    `;
                    chartContainer.appendChild(managerLevel);
                    
                    // Connector for assistants
                    if (assistants.length > 0) {
                        const connector = document.createElement('div');
                        connector.className = 'org-connector';
                        chartContainer.appendChild(connector);
                    }
                }
                
                // Assistant Manager Level
                if (assistants.length > 0) {
                    const assistantLevel = document.createElement('div');
                    assistantLevel.className = 'org-level';
                    
                    assistants.forEach(assistantId => {
                        const assistant = representatives.find(r => parseInt(r.id) === assistantId);
                        if (assistant) {
                            const assistantCard = document.createElement('div');
                            assistantCard.className = 'org-card assistant-card';
                            assistantCard.innerHTML = `
                                <div class="org-card-avatar">
                                    ${getInitials(assistant.display_name)}
                                </div>
                                <div class="org-card-name">${escapeHtml(assistant.display_name)}</div>
                                <div class="org-card-title">${escapeHtml(assistant.title || '')}</div>
                                <div class="org-card-role">MÜDÜR YARDIMCISI</div>
                            `;
                            assistantLevel.appendChild(assistantCard);
                        }
                    });
                    
                    if (assistantLevel.children.length > 0) {
                        chartContainer.appendChild(assistantLevel);
                    }
                }
                
                // Empty state
                if (!patron && !manager && assistants.length === 0) {
                    chartContainer.innerHTML = `
                        <div class="preview-loading">
                            <i class="fas fa-sitemap"></i>
                            <p>Organizasyon şeması oluşturmak için personel seçiniz</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Org chart render error:', error);
                const chartContainer = document.getElementById('orgChart');
                if (chartContainer) {
                    chartContainer.innerHTML = `
                        <div class="preview-loading">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>Organizasyon şeması yüklenirken hata oluştu</p>
                        </div>
                    `;
                }
            }
        }

        function updateStats(patron, manager, assistants) {
            try {
                // Update stat counters
                const patronCount = document.getElementById('patronCount');
                const managerCount = document.getElementById('managerCount');
                const assistantCount = document.getElementById('assistantCount');
                
                if (patronCount) patronCount.textContent = patron ? 1 : 0;
                if (managerCount) managerCount.textContent = manager ? 1 : 0;
                if (assistantCount) assistantCount.textContent = assistants.length;
            } catch (error) {
                console.error('Stats update error:', error);
            }
        }

        function refreshPreview() {
            const refreshIcon = document.querySelector('.panel-actions .fa-sync-alt');
            if (refreshIcon) {
                refreshIcon.classList.add('spinner');
                setTimeout(() => {
                    refreshIcon.classList.remove('spinner');
                    updatePreview();
                }, 500);
            }
        }

        function previewHierarchy() {
            updatePreview();
            // Smooth scroll to preview panel
            const previewPanel = document.querySelector('.preview-panel');
            if (previewPanel) {
                previewPanel.scrollIntoView({ behavior: 'smooth' });
            }
        }

        // === UTILITY FUNCTIONS ===
        function getInitials(name) {
            if (!name) return '?';
            
            const names = name.trim().split(' ');
            if (names.length === 1) {
                return names[0].charAt(0).toUpperCase();
            } else {
                return (names[0].charAt(0) + names[names.length - 1].charAt(0)).toUpperCase();
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // === FORM RESET FUNCTIONS ===
        function resetForm() {
            if (confirm('Form verilerini sıfırlamak istediğinizden emin misiniz?')) {
                document.getElementById('patron_id').value = '0';
                document.getElementById('manager_id').value = '0';
                selectedAssistants = [];
                updateSelectedAssistantsDisplay();
                updatePreview();
            }
        }

        function resetHierarchy() {
            if (confirm('Organizasyon hiyerarşisini sıfırlamak istediğinizden emin misiniz?')) {
                resetForm();
            }
        }

        // === EXPORT FUNCTION ===
        function exportHierarchy() {
            try {
                const patronSelect = document.getElementById('patron_id');
                const managerSelect = document.getElementById('manager_id');
                
                const patronId = parseInt(patronSelect.value);
                const managerId = parseInt(managerSelect.value);
                
                const patronRep = representatives.find(r => parseInt(r.id) === patronId);
                const managerRep = representatives.find(r => parseInt(r.id) === managerId);
                const assistantReps = selectedAssistants.map(id => 
                    representatives.find(r => parseInt(r.id) === id)
                ).filter(Boolean);
                
                const exportData = {
                    timestamp: new Date().toISOString(),
                    hierarchy: {
                        patron: patronRep ? {
                            id: patronRep.id,
                            name: patronRep.display_name,
                            title: patronRep.title,
                            email: patronRep.user_email
                        } : null,
                        manager: managerRep ? {
                            id: managerRep.id,
                            name: managerRep.display_name,
                            title: managerRep.title,
                            email: managerRep.user_email
                        } : null,
                        assistants: assistantReps.map(rep => ({
                            id: rep.id,
                            name: rep.display_name,
                            title: rep.title,
                            email: rep.user_email
                        }))
                    }
                };
                
                const dataStr = JSON.stringify(exportData, null, 2);
                const dataBlob = new Blob([dataStr], {type: 'application/json'});
                
                const link = document.createElement('a');
                link.href = URL.createObjectURL(dataBlob);
                link.download = `organizasyon_hiyerarsi_${new Date().toISOString().split('T')[0]}.json`;
                link.click();
            } catch (error) {
                console.error('Export error:', error);
                alert('Dışa aktarım sırasında bir hata oluştu.');
            }
        }

        // === EVENT LISTENERS ===
        document.addEventListener('change', function(e) {
            if (e.target.id === 'patron_id' || e.target.id === 'manager_id') {
                setTimeout(() => {
                    updatePreview();
                }, 100);
            }
        });

        // Modal close on background click
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('assistantModal');
            if (e.target === modal) {
                closeAssistantSelector();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // ESC key to close modal
            if (e.key === 'Escape') {
                const modal = document.getElementById('assistantModal');
                if (modal && modal.classList.contains('active')) {
                    closeAssistantSelector();
                }
            }
            
            // Ctrl+S to save form
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                const form = document.getElementById('hierarchyForm');
                if (form) {
                    form.submit();
                }
            }
            
            // Ctrl+R to reset form
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                resetForm();
            }
        });

        // Remove the error handler that was causing the alert
        // The original error was likely caused by missing elements or functions
        
    </script>

    <?php wp_footer(); ?>
</body>
</html>