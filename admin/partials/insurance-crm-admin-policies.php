<?php
/**
 * Poliçeler Sayfası
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/admin/partials
 * @author     Anadolu Birlik
 * @since      1.0.0 (2025-05-02)
 */

if (!defined('WPINC')) {
    die;
}

$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$policy = new Insurance_CRM_Policy();
$customer = new Insurance_CRM_Customer();

// ÖNEMLİ: Silme işlemini gerçekleştiren kod bloğu - Bu blok eksikti
if ($action === 'delete' && $id > 0) {
    // Güvenlik kontrolü
    check_admin_referer('delete_policy_' . $id);
    
    // Poliçeyi sil
    $result = $policy->delete($id);
    
    if (is_wp_error($result)) {
        $error_message = $result->get_error_message();
    } else {
        wp_redirect(admin_url('admin.php?page=insurance-crm-policies&message=deleted'));
        exit;
    }
}

// Form gönderildiğinde
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['insurance_crm_nonce'])) {
    if (!wp_verify_nonce($_POST['insurance_crm_nonce'], 'insurance_crm_save_policy')) {
        wp_die(__('Güvenlik doğrulaması başarısız', 'insurance-crm'));
    }

    $data = array(
        'customer_id' => intval($_POST['customer_id']),
        'representative_id' => !empty($_POST['representative_id']) ? intval($_POST['representative_id']) : null,
        'policy_number' => sanitize_text_field($_POST['policy_number']),
        'policy_type' => sanitize_text_field($_POST['policy_type']),
        'insurance_company' => sanitize_text_field($_POST['insurance_company']),
        'start_date' => sanitize_text_field($_POST['start_date']),
        'end_date' => sanitize_text_field($_POST['end_date']),
        'premium_amount' => floatval($_POST['premium_amount']),
        'status' => sanitize_text_field($_POST['status'])
    );

    // Dosya yükleme işlemi
    if (!empty($_FILES['document']['name'])) {
        // Dosya bilgisini add/update işlemine iletiyoruz
        $data['document_file'] = $_FILES['document'];
    }

    if (!isset($error_message)) {
        if ($id > 0) {
            $result = $policy->update($id, $data);
        } else {
            $result = $policy->add($data);
        }

        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
        } else {
            wp_redirect(admin_url('admin.php?page=insurance-crm-policies&message=' . ($id ? 'updated' : 'added')));
            exit;
        }
    }
}

// Mesaj gösterimi
if (isset($_GET['message'])) {
    $message = '';
    switch ($_GET['message']) {
        case 'added':
            $message = __('Poliçe başarıyla eklendi.', 'insurance-crm');
            break;
        case 'updated':
            $message = __('Poliçe başarıyla güncellendi.', 'insurance-crm');
            break;
        case 'deleted':
            $message = __('Poliçe başarıyla silindi.', 'insurance-crm');
            break;
    }
    if ($message) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}

// Hata gösterimi
if (isset($error_message)) {
    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_message) . '</p></div>';
}

// Sigorta firmaları listesini al
$settings = get_option('insurance_crm_settings');
$insurance_companies = isset($settings['insurance_companies']) ? $settings['insurance_companies'] : array();
?>

<div class="wrap insurance-crm-wrap">
    <div class="insurance-crm-header" style="display: flex; align-items: center;">
        <h1 style="margin-right: 15px;">
            <?php 
            if ($action === 'new') {
                _e('Yeni Poliçe', 'insurance-crm');
            } elseif ($action === 'edit') {
                _e('Poliçe Düzenle', 'insurance-crm');
            } else {
                _e('Poliçeler', 'insurance-crm');
            }
            ?>
        </h1>
        <?php if ($action === 'list'): ?>
            <a href="<?php echo admin_url('admin.php?page=insurance-crm-policies&action=new'); ?>" class="page-title-action">
                <?php _e('Yeni Ekle', 'insurance-crm'); ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($action === 'list'): ?>
        
        <!-- Filtre Formu -->
        <div class="tablenav top">
            <form method="get" class="insurance-crm-filter-form">
                <input type="hidden" name="page" value="insurance-crm-policies">
                
                <div class="alignleft actions">
                    <input type="search" name="s" value="<?php echo isset($_GET['s']) ? esc_attr($_GET['s']) : ''; ?>" placeholder="<?php _e('Poliçe Ara...', 'insurance-crm'); ?>">
                    
                    <select name="customer_id">
                        <option value=""><?php _e('Tüm Müşteriler', 'insurance-crm'); ?></option>
                        <?php
                        $customers = $customer->get_all();
                        foreach ($customers as $c) {
                            echo sprintf(
                                '<option value="%d" %s>%s</option>',
                                $c->id,
                                selected(isset($_GET['customer_id']) ? $_GET['customer_id'] : '', $c->id, false),
                                esc_html($c->first_name . ' ' . $c->last_name)
                            );
                        }
                        ?>
                    </select>
                    
                    <select name="policy_type">
                        <option value=""><?php _e('Tüm Poliçe Türleri', 'insurance-crm'); ?></option>
                        <?php
                        $policy_types = $settings['default_policy_types'];
                        foreach ($policy_types as $type) {
                            echo sprintf(
                                '<option value="%s" %s>%s</option>',
                                $type,
                                selected(isset($_GET['policy_type']) ? $_GET['policy_type'] : '', $type, false),
                                ucfirst($type)
                            );
                        }
                        ?>
                    </select>

                    <!-- Sigorta Firması filtresi -->
                    <select name="insurance_company">
                        <option value=""><?php _e('Tüm Sigorta Firmaları', 'insurance-crm'); ?></option>
                        <?php
                        foreach ($insurance_companies as $company) {
                            echo sprintf(
                                '<option value="%s" %s>%s</option>',
                                $company,
                                selected(isset($_GET['insurance_company']) ? $_GET['insurance_company'] : '', $company, false),
                                esc_html($company)
                            );
                        }
                        ?>
                    </select>
                    
                    <select name="status">
                        <option value=""><?php _e('Tüm Durumlar', 'insurance-crm'); ?></option>
                        <option value="aktif" <?php selected(isset($_GET['status']) ? $_GET['status'] : '', 'aktif'); ?>><?php _e('Aktif', 'insurance-crm'); ?></option>
                        <option value="pasif" <?php selected(isset($_GET['status']) ? $_GET['status'] : '', 'pasif'); ?>><?php _e('Pasif', 'insurance-crm'); ?></option>
                    </select>
                    
                    <?php submit_button(__('Filtrele', 'insurance-crm'), 'action', '', false); ?>
                </div>
            </form>
        </div>

        <!-- Poliçe Listesi -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Poliçe No', 'insurance-crm'); ?></th>
                    <th><?php _e('Müşteri', 'insurance-crm'); ?></th>
                    <th><?php _e('Müşteri Temsilcisi', 'insurance-crm'); ?></th>
                    <th><?php _e('Poliçe Türü', 'insurance-crm'); ?></th>
                    <th><?php _e('Sigorta Firması', 'insurance-crm'); ?></th>
                    <th><?php _e('Başlangıç', 'insurance-crm'); ?></th>
                    <th><?php _e('Bitiş', 'insurance-crm'); ?></th>
                    <th><?php _e('Prim', 'insurance-crm'); ?></th>
                    <th><?php _e('Durum', 'insurance-crm'); ?></th>
                    <th><?php _e('Döküman', 'insurance-crm'); ?></th>
                    <th><?php _e('İşlemler', 'insurance-crm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $args = array(
                    'search' => isset($_GET['s']) ? $_GET['s'] : '',
                    'customer_id' => isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0,
                    'policy_type' => isset($_GET['policy_type']) ? $_GET['policy_type'] : '',
                    'insurance_company' => isset($_GET['insurance_company']) ? $_GET['insurance_company'] : '',
                    'status' => isset($_GET['status']) ? $_GET['status'] : ''
                );
                
                $policies = $policy->get_all($args);
                
                if (!empty($policies)):
                    foreach ($policies as $item):
                        $edit_url = admin_url('admin.php?page=insurance-crm-policies&action=edit&id=' . $item->id);
                        $delete_url = wp_nonce_url(admin_url('admin.php?page=insurance-crm-policies&action=delete&id=' . $item->id), 'delete_policy_' . $item->id);
                ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo $edit_url; ?>"><?php echo esc_html($item->policy_number); ?></a></strong>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-customers&action=edit&id=' . $item->customer_id); ?>">
                                <?php echo esc_html($item->first_name . ' ' . $item->last_name); ?>
                            </a>
                        </td>
                        <td>
                            <?php 
                            if (!empty($item->representative_id)) {
                                global $wpdb;
                                $rep_user_id = $wpdb->get_var($wpdb->prepare(
                                    "SELECT user_id FROM {$wpdb->prefix}insurance_crm_representatives WHERE id = %d",
                                    $item->representative_id
                                ));
                                
                                if ($rep_user_id) {
                                    $user_info = get_userdata($rep_user_id);
                                    echo $user_info ? esc_html($user_info->display_name) : '';
                                }
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html($item->policy_type); ?></td>
                        <td><?php echo esc_html($item->insurance_company); ?></td>
                        <td><?php echo date_i18n('d.m.Y', strtotime($item->start_date)); ?></td>
                        <td><?php echo date_i18n('d.m.Y', strtotime($item->end_date)); ?></td>
                        <td><?php echo number_format($item->premium_amount, 2) . ' ₺'; ?></td>
                        <td>
                            <?php
                            $status_class = $item->status === 'aktif' ? 'insurance-crm-badge-success' : 'insurance-crm-badge-danger';
                            echo '<span class="insurance-crm-badge ' . $status_class . '">' . esc_html($item->status) . '</span>';
                            ?>
                        </td>
                        <td>
                            <?php if (!empty($item->document_path)): ?>
                                <a href="<?php echo esc_url($item->document_path); ?>" target="_blank" class="button button-small">
                                    <?php _e('Görüntüle', 'insurance-crm'); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo $edit_url; ?>" class="button button-small"><?php _e('Düzenle', 'insurance-crm'); ?></a>
                            <a href="<?php echo $delete_url; ?>" class="button button-small button-link-delete insurance-crm-delete" onclick="return confirm('<?php _e('Bu poliçeyi silmek istediğinizden emin misiniz?', 'insurance-crm'); ?>')">
                                <?php _e('Sil', 'insurance-crm'); ?>
                            </a>
                        </td>
                    </tr>
                <?php
                    endforeach;
                else:
                ?>
                    <tr>
                        <td colspan="11"><?php _e('Poliçe bulunamadı.', 'insurance-crm'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    <?php else: ?>
        
        <!-- Poliçe Formu -->
        <?php
        $policy_data = new stdClass();
        if ($action === 'edit') {
            $policy_data = $policy->get($id);
            if (!$policy_data) {
                wp_die(__('Poliçe bulunamadı.', 'insurance-crm'));
            }
        }
        ?>
        
        <form method="post" class="insurance-crm-form" enctype="multipart/form-data">
            <?php wp_nonce_field('insurance_crm_save_policy', 'insurance_crm_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="customer_id"><?php _e('Müşteri', 'insurance-crm'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <select name="customer_id" id="customer_id" class="regular-text" required>
                            <option value=""><?php _e('Müşteri Seçin', 'insurance-crm'); ?></option>
                            <?php
                            $customers = $customer->get_all();
                            foreach ($customers as $c) {
                                echo sprintf(
                                    '<option value="%d" %s>%s</option>',
                                    $c->id,
                                    selected(isset($policy_data->customer_id) ? $policy_data->customer_id : '', $c->id, false),
                                    esc_html($c->first_name . ' ' . $c->last_name)
                                );
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="representative_id"><?php _e('Müşteri Temsilcisi', 'insurance-crm'); ?></label>
                    </th>
                    <td>
                        <select name="representative_id" id="representative_id" class="regular-text">
                            <option value=""><?php _e('Temsilci Seçin', 'insurance-crm'); ?></option>
                            <?php
                            global $wpdb;
                            $representatives = $wpdb->get_results("SELECT id, user_id, title FROM {$wpdb->prefix}insurance_crm_representatives WHERE status = 'active' ORDER BY id");
                            
                            if (!empty($representatives)) {
                                foreach ($representatives as $rep) {
                                    $user_info = get_userdata($rep->user_id);
                                    $name = $user_info ? $user_info->display_name : "Temsilci #" . $rep->id;
                                    
                                    echo sprintf(
                                        '<option value="%d" %s>%s (%s)</option>',
                                        $rep->id,
                                        selected(isset($policy_data->representative_id) ? $policy_data->representative_id : '', $rep->id, false),
                                        esc_html($name),
                                        esc_html($rep->title)
                                    );
                                }
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="policy_number"><?php _e('Poliçe No', 'insurance-crm'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="text" name="policy_number" id="policy_number" class="regular-text" 
                               value="<?php echo isset($policy_data->policy_number) ? esc_attr($policy_data->policy_number) : ''; ?>" required>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="policy_type"><?php _e('Poliçe Türü', 'insurance-crm'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <select name="policy_type" id="policy_type" required>
                            <?php
                            $policy_types = $settings['default_policy_types'];
                            foreach ($policy_types as $type) {
                                echo sprintf(
                                    '<option value="%s" %s>%s</option>',
                                    $type,
                                    selected(isset($policy_data->policy_type) ? $policy_data->policy_type : '', $type, false),
                                    ucfirst($type)
                                );
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="insurance_company"><?php _e('Sigorta Firması', 'insurance-crm'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <select name="insurance_company" id="insurance_company" required>
                            <option value=""><?php _e('Sigorta Firması Seçin', 'insurance-crm'); ?></option>
                            <?php
                            foreach ($insurance_companies as $company) {
                                echo sprintf(
                                    '<option value="%s" %s>%s</option>',
                                    $company,
                                    selected(isset($policy_data->insurance_company) ? $policy_data->insurance_company : '', $company, false),
                                    esc_html($company)
                                );
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="start_date"><?php _e('Başlangıç Tarihi', 'insurance-crm'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="date" name="start_date" id="start_date" class="regular-text" 
                               value="<?php echo isset($policy_data->start_date) ? esc_attr($policy_data->start_date) : ''; ?>" required>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="end_date"><?php _e('Bitiş Tarihi', 'insurance-crm'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="date" name="end_date" id="end_date" class="regular-text" 
                               value="<?php echo isset($policy_data->end_date) ? esc_attr($policy_data->end_date) : ''; ?>" required>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="premium_amount"><?php _e('Prim Tutarı', 'insurance-crm'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="number" name="premium_amount" id="premium_amount" class="regular-text" step="0.01" min="0" 
                               value="<?php echo isset($policy_data->premium_amount) ? esc_attr($policy_data->premium_amount) : ''; ?>" required>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="status"><?php _e('Durum', 'insurance-crm'); ?></label>
                    </th>
                    <td>
                        <select name="status" id="status">
                            <option value="aktif" <?php selected(isset($policy_data->status) ? $policy_data->status : '', 'aktif'); ?>><?php _e('Aktif', 'insurance-crm'); ?></option>
                            <option value="pasif" <?php selected(isset($policy_data->status) ? $policy_data->status : '', 'pasif'); ?>><?php _e('Pasif', 'insurance-crm'); ?></option>
                        </select>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="document"><?php _e('Döküman', 'insurance-crm'); ?></label>
                    </th>
                    <td>
                        <?php if (isset($policy_data->document_path) && !empty($policy_data->document_path)): ?>
                            <p>
                                <a href="<?php echo esc_url($policy_data->document_path); ?>" target="_blank" class="button">
                                    <?php _e('Mevcut Dökümanı Görüntüle', 'insurance-crm'); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                        <input type="file" name="document" id="document" accept=".pdf,.doc,.docx">
                        <p class="description"><?php _e('İzin verilen dosya türleri: PDF, DOC, DOCX', 'insurance-crm'); ?></p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo $action === 'edit' ? __('Güncelle', 'insurance-crm') : __('Ekle', 'insurance-crm'); ?>">
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-policies'); ?>" class="button"><?php _e('İptal', 'insurance-crm'); ?></a>
            </p>
        </form>

    <?php endif; ?>
</div>

<!-- Silme işleminin doğru çalıştığından emin olmak için jQuery eklentisi -->
<script type="text/javascript">
jQuery(document).ready(function($) {
    // Silme butonuna extra güvenlik önlemi
    $('.insurance-crm-delete').on('click', function(e) {
        var confirmResult = confirm('<?php _e('Bu poliçeyi silmek istediğinizden emin misiniz?', 'insurance-crm'); ?>');
        if (!confirmResult) {
            e.preventDefault();
            return false;
        }
        return true;
    });
});
</script>

<div style="display: flex; align-items: center; margin-bottom: 10px;">
        <span style="font-size: 16px; font-weight: 600; color: #333; display: flex; align-items: center;">
            <i class="dashicons dashicons-admin-network" style="font-size: 18px; margin-right: 6px;"></i>
            Poliçe Numarası
        </span>
    </div>