<?php
/**
 * Plugin Name: Vishavjeet Age Consent Banner - Restrict Website Access by Age Verification
 * Plugin URI: https://www.vishavjeet.in/age-consent-banner/
 * Description: A professional age verification plugin that restricts website access until users confirm their age. Fully customizable from admin panel with session-based verification - perfect for adult content, age-restricted products, or compliance requirements.
 * Version: 1.0.0
 * Author: Vishavjeet Choubey
 * Author URI: https://vishavjeet.in/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: vishavjeet-age-consent-banner
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants with proper prefixing
define('VJACB_VERSION', '1.0.0');
define('VJACB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VJACB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VJACB_PLUGIN_BASENAME', plugin_basename(__FILE__));

class Vishavjeet_Age_Consent_Banner {
    
    private $table_name;
    private $option_name = 'vjacb_settings';
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'vjacb_age_consent_sessions';
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('Vishavjeet_Age_Consent_Banner', 'uninstall'));
        
        // Admin hooks
        add_action('admin_menu', array($this, 'vjacb_add_admin_menu'));
        add_action('admin_init', array($this, 'vjacb_register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'vjacb_enqueue_admin_scripts'));
        add_filter('plugin_action_links_' . VJACB_PLUGIN_BASENAME, array($this, 'vjacb_add_settings_link'));
        
        // Frontend hooks
        add_action('template_redirect', array($this, 'vjacb_check_age_verification'));
        add_action('wp_enqueue_scripts', array($this, 'vjacb_enqueue_frontend_scripts'));
        add_action('wp_ajax_vjacb_verify_age', array($this, 'vjacb_verify_age'));
        add_action('wp_ajax_nopriv_vjacb_verify_age', array($this, 'vjacb_verify_age'));
        
    }
    
    /**
     * Activate plugin
     */
    public function activate() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(64) NOT NULL,
            verified_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Set default options with proper prefixing
        if (!get_option($this->option_name)) {
            $defaults = array(
                'enabled' => 1,
                'heading' => 'Age Verification Required',
                'description' => 'You must be 18 years or older to access this website. Please confirm your age to continue.',
                'button_text' => 'I am 18 or Older',
                'button_cancel' => 'I am Under 18',
                'underage_message' => 'Sorry, you must be 18 or older to access this website. Please exit this page.',
                'background_color' => '#1a1a1a',
                'text_color' => '#ffffff',
                'button_color' => '#4CAF50',
                'button_text_color' => '#ffffff',
                'session_duration' => 30,
                'exclude_pages' => '',
                'minimum_age' => 18,
            );
            add_option($this->option_name, $defaults);
        }
        
        update_option('vjacb_version', VJACB_VERSION);
        
        flush_rewrite_rules();
    }
    
    /**
     * Deactivate plugin
     */
    public function deactivate() {
        wp_clear_scheduled_hook('vjacb_cleanup_sessions');
        flush_rewrite_rules();
    }
    
    /**
     * Uninstall plugin
     */
    public static function uninstall() {
        global $wpdb;
        
        // Delete options
        delete_option('vjacb_settings');
        delete_option('vjacb_version');
        
        // Drop table
        $table_name = $wpdb->prefix . 'vjacb_age_consent_sessions';
        $wpdb->query("DROP TABLE IF EXISTS `" . esc_sql($table_name) . "`");
    }
    
    /**
     * Add settings link on plugins page
     */
    public function vjacb_add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=vishavjeet-age-consent-banner">' . esc_html__('Settings', 'vishavjeet-age-consent-banner') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Add admin menu
     */
    public function vjacb_add_admin_menu() {
        add_menu_page(
            esc_html__('wpvishavjeet Age Consent', 'vishavjeet-age-consent-banner'),
            esc_html__('Age Consent', 'vishavjeet-age-consent-banner'),
            'manage_options',
            'vishavjeet-age-consent-banner',
            array($this, 'vjacb_admin_page'),
            'dashicons-shield-alt',
            80
        );
        
        add_submenu_page(
            'vishavjeet-age-consent-banner',
            esc_html__('Settings', 'vishavjeet-age-consent-banner'),
            esc_html__('Settings', 'vishavjeet-age-consent-banner'),
            'manage_options',
            'vishavjeet-age-consent-banner',
            array($this, 'vjacb_admin_page')
        );
        
        add_submenu_page(
            'vishavjeet-age-consent-banner',
            esc_html__('Session Statistics', 'vishavjeet-age-consent-banner'),
            esc_html__('Statistics', 'vishavjeet-age-consent-banner'),
            'manage_options',
            'wpvishavjeet-age-consent-stats',
            array($this, 'vjacb_stats_page')
        );
    }
    
    /**
     * Register settings
     */
    public function vjacb_register_settings() {
        register_setting('vjacb_settings_group', $this->option_name, array($this, 'vjacb_sanitize_settings'));
    }
    
    /**
     * Sanitize settings
     */
    public function vjacb_sanitize_settings($input) {
        $sanitized = array();
        
        $sanitized['enabled'] = isset($input['enabled']) ? 1 : 0;
        $sanitized['heading'] = sanitize_text_field($input['heading']);
        $sanitized['description'] = sanitize_textarea_field($input['description']);
        $sanitized['button_text'] = sanitize_text_field($input['button_text']);
        $sanitized['button_cancel'] = sanitize_text_field($input['button_cancel']);
        $sanitized['underage_message'] = sanitize_textarea_field($input['underage_message']);
        $sanitized['background_color'] = sanitize_hex_color($input['background_color']);
        $sanitized['text_color'] = sanitize_hex_color($input['text_color']);
        $sanitized['button_color'] = sanitize_hex_color($input['button_color']);
        $sanitized['button_text_color'] = sanitize_hex_color($input['button_text_color']);
        $sanitized['session_duration'] = absint($input['session_duration']);
        $sanitized['exclude_pages'] = sanitize_textarea_field($input['exclude_pages']);
        $sanitized['minimum_age'] = absint($input['minimum_age']);
        
        if ($sanitized['session_duration'] < 1) {
            $sanitized['session_duration'] = 1;
        }
        if ($sanitized['minimum_age'] < 1) {
            $sanitized['minimum_age'] = 18;
        }
        
        return $sanitized;
    }
    
    /**
     * Admin page
     */
    public function vjacb_admin_page() {
        $settings = get_option($this->option_name);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>            
            <form method="post" action="options.php">
                <?php
                settings_fields('vjacb_settings_group');
                ?>
                
                <h2 class="nav-tab-wrapper">
                    <a href="#general" class="nav-tab nav-tab-active"><?php esc_html_e('General Settings', 'vishavjeet-age-consent-banner'); ?></a>
                    <a href="#appearance" class="nav-tab"><?php esc_html_e('Appearance', 'vishavjeet-age-consent-banner'); ?></a>
                    <a href="#advanced" class="nav-tab"><?php esc_html_e('Advanced', 'vishavjeet-age-consent-banner'); ?></a>
                </h2>
                
                <div id="general" class="tab-content" style="display: block;">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable Age Verification', 'vishavjeet-age-consent-banner'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[enabled]" value="1" <?php checked($settings['enabled'], 1); ?>>
                                    <?php esc_html_e('Enable age verification on website', 'vishavjeet-age-consent-banner'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Minimum Age Required', 'vishavjeet-age-consent-banner'); ?></th>
                            <td>
                                <input type="number" name="<?php echo esc_attr($this->option_name); ?>[minimum_age]" value="<?php echo esc_attr($settings['minimum_age']); ?>" min="1" max="99" class="small-text">
                                <?php esc_html_e('years', 'vishavjeet-age-consent-banner'); ?>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Heading Text', 'vishavjeet-age-consent-banner'); ?></th>
                            <td>
                                <input type="text" name="<?php echo esc_attr($this->option_name); ?>[heading]" value="<?php echo esc_attr($settings['heading']); ?>" class="regular-text">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Description Text', 'vishavjeet-age-consent-banner'); ?></th>
                            <td>
                                <textarea name="<?php echo esc_attr($this->option_name); ?>[description]" rows="3" class="large-text"><?php echo esc_textarea($settings['description']); ?></textarea>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Confirm Button Text', 'vishavjeet-age-consent-banner'); ?></th>
                            <td>
                                <input type="text" name="<?php echo esc_attr($this->option_name); ?>[button_text]" value="<?php echo esc_attr($settings['button_text']); ?>" class="regular-text">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Cancel Button Text', 'vishavjeet-age-consent-banner'); ?></th>
                            <td>
                                <input type="text" name="<?php echo esc_attr($this->option_name); ?>[button_cancel]" value="<?php echo esc_attr($settings['button_cancel']); ?>" class="regular-text">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Underage Message', 'vishavjeet-age-consent-banner'); ?></th>
                            <td>
                                <textarea name="<?php echo esc_attr($this->option_name); ?>[underage_message]" rows="2" class="large-text"><?php echo esc_textarea($settings['underage_message']); ?></textarea>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="appearance" class="tab-content" style="display: none;">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Background Color', 'vishavjeet-age-consent-banner'); ?></th>
                            <td>
                                <input type="color" name="<?php echo esc_attr($this->option_name); ?>[background_color]" value="<?php echo esc_attr($settings['background_color']); ?>" class="wpvjacb-color-picker">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Text Color', 'vishavjeet-age-consent-banner'); ?></th>
                            <td>
                                <input type="color" name="<?php echo esc_attr($this->option_name); ?>[text_color]" value="<?php echo esc_attr($settings['text_color']); ?>" class="wpvjacb-color-picker">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Button Background Color', 'vishavjeet-age-consent-banner'); ?></th>
                            <td>
                                <input type="color" name="<?php echo esc_attr($this->option_name); ?>[button_color]" value="<?php echo esc_attr($settings['button_color']); ?>" class="wpvjacb-color-picker">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Button Text Color', 'vishavjeet-age-consent-banner'); ?></th>
                            <td>
                                <input type="color" name="<?php echo esc_attr($this->option_name); ?>[button_text_color]" value="<?php echo esc_attr($settings['button_text_color']); ?>" class="wpvjacb-color-picker">
                            </td>
                        </tr>
                    </table>
                    
                    <h3><?php esc_html_e('Preview', 'vishavjeet-age-consent-banner'); ?></h3>
                    <div style="background: <?php echo esc_attr($settings['background_color']); ?>; color: <?php echo esc_attr($settings['text_color']); ?>; padding: 40px; text-align: center; border-radius: 8px;">
                        <h2 style="color: <?php echo esc_attr($settings['text_color']); ?>; margin-bottom: 15px;"><?php echo esc_html($settings['heading']); ?></h2>
                        <p style="margin-bottom: 25px; opacity: 0.9;"><?php echo esc_html($settings['description']); ?></p>
                        <button style="background: <?php echo esc_attr($settings['button_color']); ?>; color: <?php echo esc_attr($settings['button_text_color']); ?>; padding: 12px 35px; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; margin-right: 10px;"><?php echo esc_html($settings['button_text']); ?></button>
                        <button style="background: transparent; color: <?php echo esc_attr($settings['text_color']); ?>; padding: 12px 35px; border: 2px solid currentColor; border-radius: 5px; font-size: 16px; cursor: pointer;"><?php echo esc_html($settings['button_cancel']); ?></button>
                    </div>
                </div>
                
                <div id="advanced" class="tab-content" style="display: none;">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Session Duration (Days)', 'vishavjeet-age-consent-banner'); ?></th>
                            <td>
                                <input type="number" name="<?php echo esc_attr($this->option_name); ?>[session_duration]" value="<?php echo esc_attr($settings['session_duration']); ?>" min="1" max="365" class="small-text">
                                <?php esc_html_e('days', 'vishavjeet-age-consent-banner'); ?>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Exclude Pages', 'vishavjeet-age-consent-banner'); ?></th>
                            <td>
                                <textarea name="<?php echo esc_attr($this->option_name); ?>[exclude_pages]" rows="5" class="large-text" placeholder="privacy-policy&#10;terms-of-service"><?php echo esc_textarea($settings['exclude_pages']); ?></textarea>
                                <p class="description"><?php esc_html_e('Enter page slugs (one per line) to exclude from age verification.', 'vishavjeet-age-consent-banner'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button(esc_html__('Save Settings', 'vishavjeet-age-consent-banner'), 'primary large'); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Statistics page
     */
    public function vjacb_stats_page() {
        global $wpdb;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Vishavjeet Age Consent Banner - Statistics', 'vishavjeet-age-consent-banner'); ?></h1>
            
            <div class="wpvjacb-stats-grid">
                <?php
                // Table name is not user input, but we use esc_sql for safety. $wpdb->prepare() does not support placeholders for identifiers.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely escaped and not user input.
                $table = esc_sql($this->table_name);
                $total = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE expires_at > NOW()");
                $today = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE DATE(verified_at) = CURDATE()");
                $this_week = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE verified_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
                $this_month = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE verified_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
                ?>
                
                <div class="wpvjacb-stat-box">
                    <h3><?php esc_html_e('Active Sessions', 'vishavjeet-age-consent-banner'); ?></h3>
                    <p class="wpvjacb-stat-number"><?php echo esc_html($total); ?></p>
                </div>
                
                <div class="wpvjacb-stat-box">
                    <h3><?php esc_html_e('Today', 'vishavjeet-age-consent-banner'); ?></h3>
                    <p class="wpvjacb-stat-number"><?php echo esc_html($today); ?></p>
                </div>
                
                <div class="wpvjacb-stat-box">
                    <h3><?php esc_html_e('This Week', 'vishavjeet-age-consent-banner'); ?></h3>
                    <p class="wpvjacb-stat-number"><?php echo esc_html($this_week); ?></p>
                </div>
                
                <div class="wpvjacb-stat-box">
                    <h3><?php esc_html_e('This Month', 'vishavjeet-age-consent-banner'); ?></h3>
                    <p class="wpvjacb-stat-number"><?php echo esc_html($this_month); ?></p>
                </div>
            </div>
            
            <h2><?php esc_html_e('Recent Verifications', 'vishavjeet-age-consent-banner'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'vishavjeet-age-consent-banner'); ?></th>
                        <th><?php esc_html_e('Verified At', 'vishavjeet-age-consent-banner'); ?></th>
                        <th><?php esc_html_e('Expires At', 'vishavjeet-age-consent-banner'); ?></th>
                        <th><?php esc_html_e('Status', 'vishavjeet-age-consent-banner'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely escaped and not user input.
                    $recent = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY verified_at DESC LIMIT 20");
                    
                    if ($recent) {
                        foreach ($recent as $row) {
                            $is_active = strtotime($row->expires_at) > time();
                            ?>
                            <tr>
                                <td><?php echo esc_html($row->id); ?></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->verified_at))); ?></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->expires_at))); ?></td>
                                <td>
                                    <span class="wpvjacb-status <?php echo $is_active ? 'wpvjacb-status-active' : 'wpvjacb-status-expired'; ?>">
                                        <?php echo $is_active ? esc_html__('Active', 'vishavjeet-age-consent-banner') : esc_html__('Expired', 'vishavjeet-age-consent-banner'); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        ?>
                        <tr>
                            <td colspan="5" style="text-align: center;"><?php esc_html_e('No sessions found.', 'vishavjeet-age-consent-banner'); ?></td>
                        </tr>
                        <?php
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
        
        // Enqueue inline stats style

    }
    
    /**
     * Enqueue admin scripts
     */
    public function vjacb_enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_vishavjeet-age-consent-banner' && $hook !== 'age-consent_page_vishavjeet-age-consent-stats') {
            return;
        }
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        // Enqueue admin CSS and JS for plugin admin only
        wp_enqueue_style('wpvjacb-admin', VJACB_PLUGIN_URL . 'assets/admin/admin.css', array(), VJACB_VERSION);
        wp_enqueue_script('wpvjacb-admin', VJACB_PLUGIN_URL . 'assets/admin/admin.js', array('jquery'), VJACB_VERSION, true);
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function vjacb_enqueue_frontend_scripts() {
        if ($this->vjacb_is_verified()) {
            return;
        }
        
        $settings = get_option($this->option_name);
        
        wp_enqueue_style('wpvjacb-frontend', VJACB_PLUGIN_URL . 'assets/frontend.css', array(), VJACB_VERSION);
        wp_enqueue_script('wpvjacb-frontend', VJACB_PLUGIN_URL . 'assets/frontend.js', array('jquery'), VJACB_VERSION, true);
        
        wp_localize_script('wpvjacb-frontend', 'wpvjacbData', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('vjacb_verify_nonce'),
        ));
        
        // Inline styles with proper enqueue
        $custom_css = sprintf(
            "#wpvjacb-overlay { background-color: %s; color: %s; }
            #wpvjacb-confirm-btn { background-color: %s; color: %s; }
            #wpvjacb-cancel-btn { color: %s; border-color: %s; }",
            esc_attr($settings['background_color']),
            esc_attr($settings['text_color']),
            esc_attr($settings['button_color']),
            esc_attr($settings['button_text_color']),
            esc_attr($settings['text_color']),
            esc_attr($settings['text_color'])
        );
        wp_add_inline_style('wpvjacb-frontend', $custom_css);
    }
    
    /**
     * Check age verification
     */
    public function vjacb_check_age_verification() {
        $settings = get_option($this->option_name);
        
        if (!$settings['enabled']) {
            return;
        }
        
        if (is_admin() || $this->vjacb_is_verified() || $this->vjacb_is_excluded_page()) {
            return;
        }
        
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        
        $this->vjacb_display_verification_overlay();
        exit;
    }
    
    /**
     * Check if current page is excluded
     */
    private function vjacb_is_excluded_page() {
        $settings = get_option($this->option_name);
        
        if (empty($settings['exclude_pages'])) {
            return false;
        }
        
        $excluded_slugs = array_filter(array_map('trim', explode("\n", $settings['exclude_pages'])));
        $current_slug = get_post_field('post_name', get_queried_object_id());
        
        return in_array($current_slug, $excluded_slugs);
    }
    
    /**
     * Check if user is verified
     */
    private function vjacb_is_verified() {
        global $wpdb;
        
        if (!isset($_COOKIE['vjacb_session'])) {
            return false;
        }
        $session_id = sanitize_text_field(wp_unslash($_COOKIE['vjacb_session']));
        // Table name is not user input, but we use esc_sql for safety. $wpdb->prepare() does not support placeholders for identifiers.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely escaped and not user input.
        $table = esc_sql($this->table_name);
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE session_id = %s AND expires_at > NOW()",
            $session_id
        ));
        return $result !== null;
    }
    
    /**
     * Display verification overlay
     */
    private function vjacb_display_verification_overlay() {
        $settings = get_option($this->option_name);
        
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title><?php echo esc_html($settings['heading']); ?> - <?php bloginfo('name'); ?></title>
            <?php wp_head(); ?>
        </head>
        <body class="wpvjacb-verification-page">
            <div id="wpvjacb-overlay">
                <div id="wpvjacb-content">
                    <div class="wpvjacb-logo">
                        <?php if (has_custom_logo()) : ?>
                            <?php the_custom_logo(); ?>
                        <?php else : ?>
                            <h2><?php bloginfo('name'); ?></h2>
                        <?php endif; ?>
                    </div>
                    
                    <h1><?php echo esc_html($settings['heading']); ?></h1>
                    <p><?php echo esc_html($settings['description']); ?></p>
                    
                    <div id="wpvjacb-buttons">
                        <button id="wpvjacb-confirm-btn" data-original-text="<?php echo esc_attr($settings['button_text']); ?>">
                            <?php echo esc_html($settings['button_text']); ?>
                        </button>
                        <button id="wpvjacb-cancel-btn">
                            <?php echo esc_html($settings['button_cancel']); ?>
                        </button>
                    </div>
                    
                    <div id="wpvjacb-underage-message" style="display:none;">
                        <p><?php echo esc_html($settings['underage_message']); ?></p>
                    </div>
                    
                </div>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }
    
    /**
     * Verify age via AJAX
     */
    public function vjacb_verify_age() {
        check_ajax_referer('vjacb_verify_nonce', 'nonce');
        
        global $wpdb;
        
        $settings = get_option($this->option_name);
        $session_id = wp_generate_password(32, false);
        $expires_at = gmdate('Y-m-d H:i:s', strtotime("+{$settings['session_duration']} days"));
        
        $inserted = $wpdb->insert(
            $this->table_name,
            array(
                'session_id' => $session_id,
                'verified_at' => current_time('mysql'),
                'expires_at' => $expires_at,
            ),
            array('%s', '%s', '%s', '%s')
        );
        
        if ($inserted) {
            setcookie('vjacb_session', $session_id, strtotime($expires_at), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            wp_send_json_success(array(
                'message' => esc_html__('Age verified successfully!', 'vishavjeet-age-consent-banner')
            ));
        } else {
            wp_send_json_error(array(
                'message' => esc_html__('Verification failed. Please try again.', 'vishavjeet-age-consent-banner')
            ));
        }
    }
}

// Initialize plugin
new Vishavjeet_Age_Consent_Banner();