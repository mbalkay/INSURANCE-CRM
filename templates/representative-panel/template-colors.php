<?php
/**
 * Panel renklerini ayarlayan ortak kod parçası
 * @version 1.9.0
 */

// Kullanıcının renk ayarlarını al
$current_user = wp_get_current_user();
$personal_color = get_user_meta($current_user->ID, 'crm_personal_color', true) ?: '#3498db';
$corporate_color = get_user_meta($current_user->ID, 'crm_corporate_color', true) ?: '#4caf50';
$family_color = get_user_meta($current_user->ID, 'crm_family_color', true) ?: '#ff9800';
$vehicle_color = get_user_meta($current_user->ID, 'crm_vehicle_color', true) ?: '#e74c3c';
$home_color = get_user_meta($current_user->ID, 'crm_home_color', true) ?: '#9c27b0';

// Renk opaklığını ayarla (panel arka planları için)
function adjust_color_opacity($hex, $opacity) {
    // Hex kodunu RGB'ye dönüştür
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    
    // RGBA renk kodu döndür
    return "rgba($r, $g, $b, $opacity)";
}

// Paneller için CSS oluştur
$panel_css = "
<style>
/* Panel Renkleri - Kullanıcı Ayarları */
.panel-personal {
    border-left: 3px solid {$personal_color} !important;
    background-color: " . adjust_color_opacity($personal_color, 0.05) . " !important;
}
.panel-personal .ab-panel-header {
    background-color: " . adjust_color_opacity($personal_color, 0.1) . " !important;
}

.panel-corporate {
    border-left: 3px solid {$corporate_color} !important;
    background-color: " . adjust_color_opacity($corporate_color, 0.05) . " !important;
}
.panel-corporate .ab-panel-header {
    background-color: " . adjust_color_opacity($corporate_color, 0.1) . " !important;
}

.panel-family {
    border-left: 3px solid {$family_color} !important;
    background-color: " . adjust_color_opacity($family_color, 0.05) . " !important;
}
.panel-family .ab-panel-header {
    background-color: " . adjust_color_opacity($family_color, 0.1) . " !important;
}

.panel-vehicle {
    border-left: 3px solid {$vehicle_color} !important;
    background-color: " . adjust_color_opacity($vehicle_color, 0.05) . " !important;
}
.panel-vehicle .ab-panel-header {
    background-color: " . adjust_color_opacity($vehicle_color, 0.1) . " !important;
}

.panel-home {
    border-left: 3px solid {$home_color} !important;
    background-color: " . adjust_color_opacity($home_color, 0.05) . " !important;
}
.panel-home .ab-panel-header {
    background-color: " . adjust_color_opacity($home_color, 0.1) . " !important;
}

/* Butonlar için renkler */
.ab-btn-primary {
    background-color: {$corporate_color} !important;
    border-color: " . adjust_color_opacity($corporate_color, 0.8) . " !important;
}
.ab-btn-primary:hover {
    background-color: " . adjust_color_opacity($corporate_color, 0.9) . " !important;
}

.ab-btn-success {
    background-color: {$family_color} !important;
    border-color: " . adjust_color_opacity($family_color, 0.8) . " !important;
    color: white !important;
}
.ab-btn-success:hover {
    background-color: " . adjust_color_opacity($family_color, 0.9) . " !important;
}

.ab-pagination .page-numbers.current {
    background-color: {$corporate_color};
    border-color: " . adjust_color_opacity($corporate_color, 0.8) . ";
}

/* Badge stilleri */
.ab-badge-status-aktif {
    background-color: " . adjust_color_opacity($corporate_color, 0.2) . ";
    color: " . adjust_color_opacity($corporate_color, 0.9) . ";
}

/* Tablo başlık stilleri */
.ab-crm-table th {
    border-bottom: 2px solid " . adjust_color_opacity($corporate_color, 0.2) . ";
}

/* Hover stilleri */
.ab-policy-number:hover,
.ab-task-description:hover,
.ab-info-value a:hover {
    color: {$corporate_color};
}

/* Panel stilleri iyileştirmeleri */
.ab-panel {
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    transition: box-shadow 0.3s ease;
}
.ab-panel:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

/* Form Kartı Stilleri */
.ab-form-card.panel-corporate {
    border-left: 3px solid {$corporate_color};
}
.ab-form-card.panel-corporate .ab-form-section h3 {
    color: " . adjust_color_opacity($corporate_color, 0.9) . ";
}

.ab-form-card.panel-family {
    border-left: 3px solid {$family_color};
}
.ab-form-card.panel-family .ab-form-section h3 {
    color: " . adjust_color_opacity($family_color, 0.9) . ";
}

/* Input odak stilleri */
.ab-input:focus, .ab-select:focus, .ab-textarea:focus {
    border-color: {$corporate_color};
    box-shadow: 0 0 0 3px " . adjust_color_opacity($corporate_color, 0.2) . ";
    outline: none;
}

/* İyileştirilmiş görsel stillemeler */
.ab-actions a:hover {
    background-color: " . adjust_color_opacity($corporate_color, 0.1) . ";
}

.ab-related-link {
    transition: all 0.3s ease;
}
.ab-related-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

/* Özel ikon renkleri */
.customer-icon {
    background-color: " . adjust_color_opacity($personal_color, 0.2) . ";
    color: {$personal_color};
}

.policy-icon {
    background-color: " . adjust_color_opacity($corporate_color, 0.2) . ";
    color: {$corporate_color};
}

.task-icon {
    background-color: " . adjust_color_opacity($family_color, 0.2) . ";
    color: {$family_color};
}
</style>
";

// Global theme colors from boss settings
$settings = get_option('insurance_crm_boss_settings', array());
$theme_colors = isset($settings['site_appearance']) ? $settings['site_appearance'] : array();

// Default mor gradient tema renkleri - preserve original purple gradient theme
$header_color = isset($theme_colors['header_color']) ? $theme_colors['header_color'] : '#6c5ce7';
$submenu_color = isset($theme_colors['submenu_color']) ? $theme_colors['submenu_color'] : '#74b9ff';
$button_color = isset($theme_colors['button_color']) ? $theme_colors['button_color'] : '#a29bfe';
$accent_color = isset($theme_colors['accent_color']) ? $theme_colors['accent_color'] : '#fd79a8';
$link_color = isset($theme_colors['link_color']) ? $theme_colors['link_color'] : '#0984e3';
$background_color = isset($theme_colors['background_color']) ? $theme_colors['background_color'] : '#f8f9fa';
$primary_color = isset($theme_colors['primary_color']) ? $theme_colors['primary_color'] : '#3498db';
$secondary_color = isset($theme_colors['secondary_color']) ? $theme_colors['secondary_color'] : '#ffd93d';
$sidebar_color = isset($theme_colors['sidebar_color']) ? $theme_colors['sidebar_color'] : '#2c3e50';

// Apply global theme colors
$global_theme_css = "
<style>
/* Global Theme Colors - v1.9.0 Enhanced Color System */

/* Primary Elements */
.btn-primary, .button-primary, .ab-btn-primary {
    background-color: {$primary_color} !important;
    border-color: " . adjust_color_opacity($primary_color, 0.8) . " !important;
}

.btn-primary:hover, .button-primary:hover, .ab-btn-primary:hover {
    background-color: " . adjust_color_opacity($primary_color, 0.9) . " !important;
}

/* Headers and Titles */
h1, h2, h3, h4, h5, h6, .page-title, .panel-title, .section-title {
    color: {$header_color} !important;
}

.header-element, .panel-header, .card-header {
    background: linear-gradient(135deg, {$header_color}, " . adjust_color_opacity($header_color, 0.8) . ") !important;
    color: white !important;
}

/* Submenu and Navigation */
.tab-link, .nav-tab, .submenu-item, .nav-item {
    color: {$submenu_color} !important;
    border-color: {$submenu_color} !important;
}

.tab-link.active, .nav-tab.active {
    background-color: {$submenu_color} !important;
    color: white !important;
}

/* Buttons and Actions */
.btn, .button, .action-button, .ab-btn {
    background-color: {$button_color} !important;
    border-color: " . adjust_color_opacity($button_color, 0.8) . " !important;
    color: white !important;
}

.btn:hover, .button:hover, .action-button:hover {
    background-color: " . adjust_color_opacity($button_color, 0.9) . " !important;
}

.btn-success, .ab-btn-success {
    background-color: {$accent_color} !important;
    border-color: " . adjust_color_opacity($accent_color, 0.8) . " !important;
}

/* Sidebar and Left Menu */
.sidebar, .left-menu, .admin-menu, .modern-settings-menu {
    background-color: {$sidebar_color} !important;
}

.menu-item, .sidebar-item, .modern-settings-menu li a {
    background-color: " . adjust_color_opacity($sidebar_color, 0.9) . " !important;
    color: white !important;
}

.menu-item:hover, .sidebar-item:hover, .modern-settings-menu li a:hover {
    background-color: " . adjust_color_opacity($sidebar_color, 0.7) . " !important;
}

/* Links and Interactive Elements */
a, .text-link, .ab-link {
    color: {$link_color} !important;
}

a:hover, .text-link:hover, .ab-link:hover {
    color: " . adjust_color_opacity($link_color, 0.8) . " !important;
}

/* Accent Elements */
.accent, .highlight, .important, .status-active {
    color: {$accent_color} !important;
}

.accent-bg, .highlight-bg {
    background-color: " . adjust_color_opacity($accent_color, 0.1) . " !important;
    border-left: 3px solid {$accent_color} !important;
}

/* Secondary Elements */
.secondary-btn, .btn-secondary {
    background-color: {$secondary_color} !important;
    color: #333 !important;
}

/* Background */
body, .main-content, .content-wrapper {
    background-color: {$background_color} !important;
}

/* Form Elements with Theme Colors */
.form-control:focus, .ab-input:focus {
    border-color: {$primary_color} !important;
    box-shadow: 0 0 0 3px " . adjust_color_opacity($primary_color, 0.2) . " !important;
}

/* Pagination */
.ab-pagination .page-numbers.current {
    background-color: {$primary_color} !important;
    border-color: {$primary_color} !important;
}

/* Cards and Panels with Enhanced Colors */
.card-primary {
    border-left: 4px solid {$primary_color} !important;
}

.card-secondary {
    border-left: 4px solid {$secondary_color} !important;
}

.card-accent {
    border-left: 4px solid {$accent_color} !important;
}

/* Status Indicators */
.status-indicator.active {
    background-color: {$accent_color} !important;
}

.status-indicator.pending {
    background-color: {$submenu_color} !important;
}

/* Enhanced Purple Gradient Theme Preservation */
.gradient-primary {
    background: linear-gradient(135deg, {$header_color}, {$submenu_color}) !important;
}

.gradient-secondary {
    background: linear-gradient(135deg, {$button_color}, {$accent_color}) !important;
}

/* Responsive Theme Adjustments */
@media (max-width: 768px) {
    .mobile-header {
        background-color: {$header_color} !important;
    }
    
    .mobile-menu-toggle {
        color: {$sidebar_color} !important;
    }
}
</style>
";

// CSS'i sayfanın başına ekle
echo $panel_css;
echo $global_theme_css;
?>