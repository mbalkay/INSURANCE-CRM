<?php
/**
 * Insurance CRM
 *
 * @package     Insurance_CRM
 * @author      Mehmet BALKAY | Anadolu Birlik
 * @copyright   2025 Anadolu Birlik
 * @license     GPL-2.0+
 *
 * Plugin Name: Insurance CRM
 * Plugin URI:  https://github.com/anadolubirlik/insurance-crm
 * Description: Sigorta acenteleri için müşteri, poliçe ve görev yönetim sistemi.
 * Plugin Version:     1.4.9
 * Pagename : functions.php
 * Page Version: 1.2.0
 * Author:      Mehmet BALKAY | Anadolu Birlik
 * Author URI:  https://www.balkay.net
 */

/**
 * Yardımcı fonksiyonlar
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/includes
 */

if (!defined('WPINC')) {
    die;
}



/**
 * Müşteriyi görüntülemek için yönlendirme fonksiyonu
 */
function insurance_crm_redirect_customer_links() {
    if (!is_admin() || !current_user_can('read_insurance_crm')) {
        return;
    }
    
    // URL'de müşteri ismi geçiyorsa ayrıntılar sayfasına yönlendir
    if (isset($_GET['page']) && $_GET['page'] === 'insurance-crm-customers') {
        if (isset($_GET['customer_name']) && isset($_GET['id'])) {
            $customer_id = intval($_GET['id']);
            wp_redirect(admin_url('admin.php?page=insurance-crm-customers&action=view&id=' . $customer_id));
            exit;
        }
    }
    
    // Poliçe sayfasından müşteri adına tıklandığında
    if (isset($_GET['page']) && $_GET['page'] === 'insurance-crm-policies' && isset($_GET['view_customer']) && isset($_GET['customer_id'])) {
        $customer_id = intval($_GET['customer_id']);
        wp_redirect(admin_url('admin.php?page=insurance-crm-customers&action=view&id=' . $customer_id));
        exit;
    }
}
add_action('admin_init', 'insurance_crm_redirect_customer_links');

// Alternative login form implementation with fallback mechanism
function temsilci_panel_shortcode() {
    ob_start();
    
    // Process traditional form submission if POST data is present
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['traditional_login'])) {
        $login_result = process_traditional_login();
        if ($login_result['success']) {
            // Redirect on success
            echo '<script>window.location.href = "' . esc_js($login_result['redirect']) . '";</script>';
            return;
        } else {
            // Show error message
            $message = '<div style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #f5c6cb;">' . esc_html($login_result['message']) . '</div>';
        }
    } else {
        // Check for timeout or logout messages
        $message = '';
        if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
            $message = '<div style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #f5c6cb;">Oturumunuz 60 dakika hareketsizlik nedeniyle sona erdi. Lütfen tekrar giriş yapın.</div>';
        } elseif (isset($_GET['logout']) && $_GET['logout'] == '1') {
            $message = '<div style="background: #d1ecf1; color: #0c5460; padding: 10px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #bee5eb;">Güvenli bir şekilde çıkış yaptınız.</div>';
        } elseif (isset($_GET['error']) && $_GET['error'] == 'license_expired') {
            $message = '<div style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #f5c6cb;">Lisans süresi dolmuş. Lütfen yöneticinize başvurun.</div>';
        }
    }
    ?>
    <div class="login-container" style="max-width: 450px; margin: 50px auto; background: #fff; border-radius: 5px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 30px;">
        <div class="login-logo" style="text-align: center; margin-bottom: 30px;">
            <img src="<?php echo get_template_directory_uri(); ?>/assets/images/logo.png" alt="Anadolu Birlik Sigorta" style="max-width: 200px; height: auto;">
            <?php if (!file_exists(get_template_directory() . '/assets/images/logo.png')): ?>
                <h2 style="color: #2c3e50; font-size: 24px;">Anadolu Birlik Sigorta CRM</h2>
            <?php endif; ?>
        </div>
        <?php echo $message; ?>
        <div class="login-form">
            <h2 style="color: #2c3e50; text-align: center; margin-bottom: 20px; font-size: 22px;">Müşteri Temsilcisi Girişi</h2>
            
            <!-- AJAX Login Form -->
            <form id="ajax-login-form" style="margin-bottom: 20px;">
                <?php wp_nonce_field('insurance_crm_ajax_login', 'login_nonce'); ?>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="ajax_username" style="display: block; margin-bottom: 5px; color: #555; font-weight: 500;">Kullanıcı Adı</label>
                    <input type="text" id="ajax_username" name="username" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="ajax_password" style="display: block; margin-bottom: 5px; color: #555; font-weight: 500;">Şifre</label>
                    <input type="password" id="ajax_password" name="password" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                </div>
                <div class="form-actions" style="display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 14px;">
                    <div class="remember-me">
                        <input type="checkbox" id="ajax_remember" name="remember">
                        <label for="ajax_remember" style="color: #555;">Beni Hatırla</label>
                    </div>
                    <a href="#" class="forgot-password" style="color: #3498db; text-decoration: none;">Şifremi Unuttum</a>
                </div>
                <button type="submit" class="btn-ajax-login" style="width: 100%; padding: 12px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 16px; font-weight: 500; cursor: pointer; transition: background 0.3s;">Hızlı Giriş (AJAX)</button>
            </form>
            
            <div style="text-align: center; margin: 20px 0; color: #999; font-size: 14px;">── VEYA ──</div>
            
            <!-- Traditional Form Login -->
            <form method="post" id="traditional-login-form">
                <?php wp_nonce_field('insurance_crm_traditional_login', 'traditional_login_nonce'); ?>
                <input type="hidden" name="traditional_login" value="1">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="trad_username" style="display: block; margin-bottom: 5px; color: #555; font-weight: 500;">Kullanıcı Adı</label>
                    <input type="text" id="trad_username" name="username" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="trad_password" style="display: block; margin-bottom: 5px; color: #555; font-weight: 500;">Şifre</label>
                    <input type="password" id="trad_password" name="password" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                </div>
                <div class="form-actions" style="display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 14px;">
                    <div class="remember-me">
                        <input type="checkbox" id="trad_remember" name="remember">
                        <label for="trad_remember" style="color: #555;">Beni Hatırla</label>
                    </div>
                </div>
                <button type="submit" class="btn-traditional-login" style="width: 100%; padding: 12px; background: #27ae60; color: white; border: none; border-radius: 4px; font-size: 16px; font-weight: 500; cursor: pointer; transition: background 0.3s;">Geleneksel Giriş</button>
            </form>
            
            <div style="text-align: center; margin-top: 20px; font-size: 12px; color: #888;">
                <p>AJAX giriş çalışmıyorsa geleneksel giriş yöntemini deneyin</p>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // AJAX Login Handler
        document.getElementById('ajax-login-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const username = document.getElementById('ajax_username').value;
            const password = document.getElementById('ajax_password').value;
            const remember = document.getElementById('ajax_remember').checked;
            const submitButton = this.querySelector('button[type="submit"]');
            const nonce = this.querySelector('input[name="login_nonce"]').value;
            
            // Disable button and show loading state
            submitButton.disabled = true;
            submitButton.textContent = 'Giriş yapılıyor...';
            
            // Simple AJAX login - no complex nonce fetching
            const formData = new FormData();
            formData.append('action', 'insurance_crm_simple_ajax_login');
            formData.append('username', username);
            formData.append('password', password);
            formData.append('remember', remember ? '1' : '0');
            formData.append('login_nonce', nonce);
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Giriş başarılı! Yönlendiriliyorsunuz...');
                    window.location.href = data.data.redirect;
                } else {
                    alert(data.data.message || 'Giriş başarısız. Lütfen bilgilerinizi kontrol edin.');
                    // Re-enable button
                    submitButton.disabled = false;
                    submitButton.textContent = 'Hızlı Giriş (AJAX)';
                }
            })
            .catch(error => {
                console.error('AJAX Login error:', error);
                alert('AJAX giriş başarısız. Lütfen geleneksel giriş yöntemini deneyin.');
                // Re-enable button
                submitButton.disabled = false;
                submitButton.textContent = 'Hızlı Giriş (AJAX)';
            });
        });
        
        // Traditional form gets natural form submission
        document.getElementById('traditional-login-form').addEventListener('submit', function(e) {
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.textContent = 'Giriş yapılıyor...';
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('temsilci_panel', 'temsilci_panel_shortcode');

// Traditional form login processing function
function process_traditional_login() {
    // Verify nonce
    if (!isset($_POST['traditional_login_nonce']) || !wp_verify_nonce($_POST['traditional_login_nonce'], 'insurance_crm_traditional_login')) {
        return array('success' => false, 'message' => 'Güvenlik doğrulaması başarısız.');
    }
    
    $username = sanitize_text_field($_POST['username']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']);
    
    if (empty($username) || empty($password)) {
        return array('success' => false, 'message' => 'Kullanıcı adı ve şifre gereklidir.');
    }
    
    // Convert email to username if needed
    if (is_email($username)) {
        $user_data = get_user_by('email', $username);
        if ($user_data) {
            $username = $user_data->user_login;
        }
    }
    
    $creds = array(
        'user_login' => $username,
        'user_password' => $password,
        'remember' => $remember
    );
    
    $user = wp_signon($creds, is_ssl());
    
    if (is_wp_error($user)) {
        return array('success' => false, 'message' => 'Geçersiz kullanıcı adı veya şifre.');
    }
    
    // Check if user is administrator
    if (in_array('administrator', (array)$user->roles)) {
        update_user_meta($user->ID, '_user_last_activity', time());
        return array('success' => true, 'redirect' => home_url('/boss-panel/'));
    }
    
    // Check if user is insurance representative
    if (!in_array('insurance_representative', (array)$user->roles)) {
        wp_logout();
        return array('success' => false, 'message' => 'Bu sisteme giriş yetkiniz bulunmamaktadır.');
    }
    
    // Check representative status
    global $wpdb;
    $rep_status = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user->ID
    ));
    
    if ($rep_status !== 'active') {
        wp_logout();
        return array('success' => false, 'message' => 'Hesabınız aktif değil. Lütfen yöneticinize başvurun.');
    }
    
    // Update last activity for session management
    update_user_meta($user->ID, '_user_last_activity', time());
    
    return array('success' => true, 'redirect' => home_url('/temsilci-paneli/'));
}

// Simple AJAX login handler - single step, no complex nonce fetching
add_action('wp_ajax_nopriv_insurance_crm_simple_ajax_login', 'handle_simple_ajax_login');
add_action('wp_ajax_insurance_crm_simple_ajax_login', 'handle_simple_ajax_login');

function handle_simple_ajax_login() {
    // Verify nonce
    if (!isset($_POST['login_nonce']) || !wp_verify_nonce($_POST['login_nonce'], 'insurance_crm_ajax_login')) {
        wp_send_json_error(array('message' => 'Güvenlik doğrulaması başarısız.'));
        return;
    }
    
    $username = sanitize_text_field($_POST['username']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']) && $_POST['remember'] == '1';
    
    if (empty($username) || empty($password)) {
        wp_send_json_error(array('message' => 'Kullanıcı adı ve şifre gereklidir.'));
        return;
    }
    
    // Convert email to username if needed
    if (is_email($username)) {
        $user_data = get_user_by('email', $username);
        if ($user_data) {
            $username = $user_data->user_login;
        }
    }
    
    $creds = array(
        'user_login' => $username,
        'user_password' => $password,
        'remember' => $remember
    );
    
    $user = wp_signon($creds, is_ssl());
    
    if (is_wp_error($user)) {
        wp_send_json_error(array('message' => 'Geçersiz kullanıcı adı veya şifre.'));
        return;
    }
    
    // Check if user is administrator
    if (in_array('administrator', (array)$user->roles)) {
        update_user_meta($user->ID, '_user_last_activity', time());
        wp_send_json_success(array(
            'message' => 'Giriş başarılı! Boss paneline yönlendiriliyorsunuz...',
            'redirect' => home_url('/boss-panel/')
        ));
        return;
    }
    
    // Check if user is insurance representative
    if (!in_array('insurance_representative', (array)$user->roles)) {
        wp_logout();
        wp_send_json_error(array('message' => 'Bu sisteme giriş yetkiniz bulunmamaktadır.'));
        return;
    }
    
    // Check representative status
    global $wpdb;
    $rep_status = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user->ID
    ));
    
    if ($rep_status !== 'active') {
        wp_logout();
        wp_send_json_error(array('message' => 'Hesabınız aktif değil. Lütfen yöneticinize başvurun.'));
        return;
    }
    
    // Update last activity for session management
    update_user_meta($user->ID, '_user_last_activity', time());
    
    wp_send_json_success(array(
        'message' => 'Giriş başarılı! Dashboard\'a yönlendiriliyorsunuz...',
        'redirect' => home_url('/temsilci-paneli/')
    ));
}

// Session timeout functionality - 60 minutes of inactivity
add_action('wp_loaded', 'insurance_crm_check_session_timeout');
add_action('wp_ajax_insurance_crm_check_session', 'handle_insurance_crm_session_check');
add_action('wp_ajax_nopriv_insurance_crm_check_session', 'handle_insurance_crm_session_check');

function insurance_crm_check_session_timeout() {
    // Only check for logged-in insurance representatives
    if (!is_user_logged_in()) {
        return;
    }
    
    $user = wp_get_current_user();
    if (!in_array('insurance_representative', (array)$user->roles)) {
        return;
    }
    
    $last_activity = get_user_meta($user->ID, '_user_last_activity', true);
    if (empty($last_activity)) {
        // First time login, set current time
        update_user_meta($user->ID, '_user_last_activity', time());
        return;
    }
    
    $timeout_minutes = 60; // 60 minutes timeout
    $timeout_seconds = $timeout_minutes * 60;
    
    if ((time() - $last_activity) > $timeout_seconds) {
        // Session has timed out
        wp_logout();
        
        // Redirect to login page with timeout message
        if (!is_admin()) {
            wp_safe_redirect(home_url('/temsilci-girisi/?timeout=1'));
            exit;
        }
    } else {
        // Update last activity
        update_user_meta($user->ID, '_user_last_activity', time());
    }
}

function handle_insurance_crm_session_check() {
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Oturum bulunamadı.', 'timeout' => true));
        return;
    }
    
    $user = wp_get_current_user();
    if (!in_array('insurance_representative', (array)$user->roles)) {
        wp_send_json_error(array('message' => 'Geçersiz kullanıcı.', 'timeout' => true));
        return;
    }
    
    $last_activity = get_user_meta($user->ID, '_user_last_activity', true);
    $timeout_minutes = 60;
    $timeout_seconds = $timeout_minutes * 60;
    
    if (empty($last_activity) || (time() - $last_activity) > $timeout_seconds) {
        wp_send_json_error(array('message' => 'Oturumunuz zaman aşımına uğradı.', 'timeout' => true));
        return;
    }
    
    // Update last activity
    update_user_meta($user->ID, '_user_last_activity', time());
    
    $remaining_seconds = $timeout_seconds - (time() - $last_activity);
    wp_send_json_success(array(
        'remaining_seconds' => $remaining_seconds,
        'timeout_minutes' => $timeout_minutes
    ));
}


// Müşteri temsilcileri için giriş kontrolü ve yönlendirme
add_action('wp_login', 'redirect_insurance_representative_after_login', 10, 2);
function redirect_insurance_representative_after_login($user_login, $user) {
    // Kullanıcının rollerini kontrol et
    if (in_array('insurance_representative', (array)$user->roles)) {
        // Müşteri temsilcisinin durumunu kontrol et
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives 
             WHERE user_id = %d",
            $user->ID
        ));
        
        // Eğer status aktifse, dashboarda yönlendir
        if ($status === 'active') {
            wp_redirect(home_url('/temsilci-paneli/'));
            exit;
        }
    }
}

// Login sayfası özelleştirmesi
add_filter('login_redirect', 'custom_login_redirect', 10, 3);
function custom_login_redirect($redirect_to, $requested_redirect_to, $user) {
    // Kullanıcı giriş yapmışsa ve müşteri temsilcisiyse panele yönlendir
    if (!is_wp_error($user) && in_array('insurance_representative', (array)$user->roles)) {
        // Müşteri temsilcisinin durumunu kontrol et
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives 
             WHERE user_id = %d",
            $user->ID
        ));
        
        // Eğer status aktifse, dashboarda yönlendir
        if ($status === 'active') {
            return home_url('/temsilci-paneli/');
        }
    }
    
    // Diğer kullanıcılar için normal yönlendirme
    return $redirect_to;
}

/**
 * Poliçe tabanlı müşteri görünürlüğü için WHERE clause oluşturur
 * Policy-based customer visibility WHERE clause generator
 * 
 * @param string $access_level Kullanıcının erişim seviyesi (patron, mudur, ekip_lideri, temsilci)
 * @param int $current_user_rep_id Mevcut kullanıcının temsilci ID'si
 * @param array $team_members Ekip üyeleri (varsa)
 * @param string $view_type Görünüm tipi (team_customers vb.)
 * @return array ['where_clause' => string, 'join_clause' => string]
 */
function build_policy_based_customer_visibility($access_level, $current_user_rep_id, $team_members = array(), $view_type = '') {
    global $wpdb;
    
    $where_clause = '';
    $join_clause = '';
    
    // Patron, Müdür ve Müdür Yardımcısı tüm müşterileri görebilir
    if (in_array($access_level, ['patron', 'mudur', 'mudur_yardimcisi'])) {
        return array('where_clause' => '', 'join_clause' => '');
    }
    
    // Ekip lideri için görünürlük
    if ($access_level == 'ekip_lideri') {
        if ($view_type == 'team_customers' && !empty($team_members)) {
            // Ekip görünümü: Ekipteki tüm temsilcilerin müşterilerini göster
            $valid_members = array();
            foreach ($team_members as $member_id) {
                if (!empty($member_id) && is_numeric($member_id)) {
                    $valid_members[] = (int) $member_id;
                }
            }
            
            if (!empty($valid_members)) {
                $placeholders1 = implode(',', array_fill(0, count($valid_members), '%d'));
                $placeholders2 = implode(',', array_fill(0, count($valid_members), '%d'));
                $where_clause = $wpdb->prepare(" AND (c.representative_id IN ($placeholders1) OR EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}insurance_crm_policies p 
                    WHERE p.customer_id = c.id AND p.representative_id IN ($placeholders2)
                ))", array_merge($valid_members, $valid_members));
            } else {
                // Ekipte geçerli üye yoksa sadece kendi müşterilerini göster
                $where_clause = $wpdb->prepare(" AND (c.representative_id = %d OR EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}insurance_crm_policies p 
                    WHERE p.customer_id = c.id AND p.representative_id = %d
                ))", $current_user_rep_id, $current_user_rep_id);
            }
        } else {
            // Normal görünüm: Sadece kendi müşterilerini göster
            $where_clause = $wpdb->prepare(" AND (c.representative_id = %d OR EXISTS (
                SELECT 1 FROM {$wpdb->prefix}insurance_crm_policies p 
                WHERE p.customer_id = c.id AND p.representative_id = %d
            ))", $current_user_rep_id, $current_user_rep_id);
        }
    }
    // Müşteri temsilcisi için görünürlük
    elseif ($access_level == 'temsilci' && $current_user_rep_id) {
        // Sadece poliçe kestikleri müşterileri görebilir (eski atama + yeni poliçe tabanlı)
        $where_clause = $wpdb->prepare(" AND (c.representative_id = %d OR EXISTS (
            SELECT 1 FROM {$wpdb->prefix}insurance_crm_policies p 
            WHERE p.customer_id = c.id AND p.representative_id = %d
        ))", $current_user_rep_id, $current_user_rep_id);
    }
    
    return array('where_clause' => $where_clause, 'join_clause' => $join_clause);
}