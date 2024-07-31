<?php
/*
Plugin Name: Checkout Sentinel
Plugin URI: https://paladine.com.au
Description: Dedicated to fortifying WooCommerce websites against spam and DDoS attacks during the checkout process
Version: 0.9.2
Author: Paladine Pty Ltd
Author URI: https://paladine.com.au
Requires at least: 6.0
Requires PHP: 7.0
*/

if (!defined('ABSPATH')) {
    exit;
}

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_filter('woocommerce_checkout_process', 'checkout_sentinel_disable');
    
    // Check if WooCommerce Blocks is active and the integration class exists
    if (class_exists('Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface')) {
        add_action('woocommerce_blocks_checkout_block_registration', 'checkout_sentinel_blocks_integration');
    } else {
        // Fallback for environments without WooCommerce Blocks
        add_action('wp_enqueue_scripts', 'checkout_sentinel_enqueue_fallback_script');
    }
    
    function checkout_sentinel_disable()
    {
        if (checkout_sentinel_try_debounce()) {
            $redirect_url = get_option('checkout_sentinel_redirect_url', '');
            if (!empty($redirect_url)) {
                wp_redirect($redirect_url);
                exit;
            } else {
                wc_add_notice(__('Error: We have detected too many attempts and we have disabled payment methods to prevent spam or DDOS. Please wait a while and try again', 'woocommerce'), 'error');
            }
        }
    }

    function checkout_sentinel_blocks_integration($integration_registry)
    {
        if (class_exists('Checkout_Sentinel_Blocks_Integration')) {
            $integration_registry->register(new Checkout_Sentinel_Blocks_Integration());
        }
    }

    function checkout_sentinel_enqueue_fallback_script()
    {
        if (is_checkout()) {
            wp_enqueue_script(
                'checkout-sentinel-fallback',
                plugins_url('checkout-sentinel-fallback.js', __FILE__),
                array('jquery'),
                '1.0.0',
                true
            );
            wp_localize_script('checkout-sentinel-fallback', 'checkoutSentinelData', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
            ));
        }
    }

    if (class_exists('Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface')) {
        class Checkout_Sentinel_Blocks_Integration extends \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface
        {
            public function get_name()
            {
                return 'checkout-sentinel';
            }

            public function initialize()
            {
                add_action('woocommerce_blocks_checkout_block_before_submit_button', array($this, 'add_checkout_sentinel_validation'));
            }

            public function add_checkout_sentinel_validation()
            {
                wp_enqueue_script(
                    'checkout-sentinel-blocks-integration',
                    plugins_url('checkout-sentinel-blocks.js', __FILE__),
                    array('wc-blocks-registry'),
                    '1.0.0',
                    true
                );
            }

            public function get_script_handles()
            {
                return array('checkout-sentinel-blocks-integration');
            }

            public function get_editor_script_handles()
            {
                return array();
            }

            public function get_script_data()
            {
                return array(
                    'maxAttempts' => get_option('checkout_sentinel_max_attempts', 3),
                    'timeframe' => get_option('checkout_sentinel_timeframe', 120),
                    'redirectUrl' => get_option('checkout_sentinel_redirect_url', ''),
                );
            }
        }
    }

    function checkout_sentinel_try_debounce()
    {
        $error = false;
        $maxAttemptsForTimeframe = get_option('checkout_sentinel_max_attempts', 3);
        $timeframeInSeconds = get_option('checkout_sentinel_timeframe', 120);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'UNKNOWN';

        if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            $error = true;
        }
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false) {
            $error = true;
        }
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            $error = true;
        }
        if ($error) {
            wc_add_notice(__('Error: There is some issue with your provided information', 'woocommerce'), 'error');
            return true;
        }

        $timeInMinutes = floor(time() / $timeframeInSeconds) * $timeframeInSeconds;
        $key = 'checkout_sentinel_debounce_' . $ipAddress . '_' . $timeInMinutes;

        $counter = get_transient($key) ?: 0;
        $counter++;
        set_transient($key, $counter, 60 * 60);

        $error = $counter > $maxAttemptsForTimeframe;
        if ($error) {
            wc_add_notice(__('We have detected too many attempts and we have disabled payment methods to prevent spam or DDOS', 'woocommerce'), 'error');
            checkout_sentinel_log_attack($ipAddress);
        }
        return $error;
    }

    function checkout_sentinel_log_attack($ipAddress)
    {
        $attacks = get_option('checkout_sentinel_attacks', array());
        $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'unknown';

        if (!isset($attacks[$ipAddress])) {
            $attacks[$ipAddress] = array(
                'first_attack' => current_time('mysql'),
                'count' => 0,
                'payment_method' => $payment_method
            );
        }

        $attacks[$ipAddress]['count']++;
        update_option('checkout_sentinel_attacks', $attacks);
    }

    add_action('admin_menu', 'checkout_sentinel_settings_page');
    add_action('admin_init', 'checkout_sentinel_settings');

    function checkout_sentinel_settings_page()
    {
        add_submenu_page(
            'woocommerce',
            __('Checkout Sentinel Settings', 'woocommerce'),
            __('Checkout Sentinel', 'woocommerce'),
            'manage_options',
            'checkout_sentinel_settings',
            'checkout_sentinel_settings_html'
        );
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'checkout_sentinel_settings_link');
    }

    function checkout_sentinel_settings_link($links)
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=checkout_sentinel_settings') . '">' . __('Settings', 'woocommerce') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    function checkout_sentinel_settings()
    {
        register_setting('checkout_sentinel_settings', 'checkout_sentinel_max_attempts', 'intval');
        register_setting('checkout_sentinel_settings', 'checkout_sentinel_timeframe', 'intval');
        register_setting('checkout_sentinel_settings', 'checkout_sentinel_redirect_url', 'esc_url_raw');
    }

    function checkout_sentinel_settings_html()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['clear_attacks'])) {
            update_option('checkout_sentinel_attacks', array());
            echo '<div class="updated"><p>Attack list cleared.</p></div>';
        }

        if (isset($_POST['export_csv'])) {
            checkout_sentinel_export_csv();
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php settings_fields('checkout_sentinel_settings'); ?>
                <?php do_settings_sections('checkout_sentinel_settings'); ?>
                <a href="https://paladine.com.au/" target="_blank"><img src="<?php echo plugins_url('img/paladine-logo.png', __FILE__); ?>" alt="Paladine"></a>
                <p>Checkout Sentinel is a WordPress plugin dedicated to fortifying WooCommerce websites against spam and DDoS attacks during the checkout process. With its intelligent debounce mechanism, Checkout Sentinel diligently guards against excessive order attempts from a single IP address within a specified timeframe. By swiftly disabling payment methods when suspicious activity is detected, this vigilant plugin safeguards your online store and ensures a secure and seamless checkout experience for genuine customers.</p>
                <table class="form-table">
                    <tr>
                        <th><label for="checkout_sentinel_max_attempts">Max Attempts:</label></th>
                        <td><input type="number" min=2 max=20 id="checkout_sentinel_max_attempts" name="checkout_sentinel_max_attempts" value="<?php echo esc_attr(get_option('checkout_sentinel_max_attempts', 3)); ?>" required /> <em>(Default: 3)</em></td>
                    </tr>
                    <tr>
                        <th><label for="checkout_sentinel_timeframe">Timeframe (seconds):</label></th>
                        <td><input type="number" min=30 max=3600 step=5 id="checkout_sentinel_timeframe" name="checkout_sentinel_timeframe" value="<?php echo esc_attr(get_option('checkout_sentinel_timeframe', 120)); ?>" required /> <em>(Default: 120)</em></td>
                    </tr>
                    <tr>
                        <th><label for="checkout_sentinel_redirect_url">Redirect URL:</label></th>
                        <td><input type="url" id="checkout_sentinel_redirect_url" name="checkout_sentinel_redirect_url" value="<?php echo esc_url(get_option('checkout_sentinel_redirect_url', '')); ?>" /> <em>(Optional: Leave blank to disable redirection)</em></td>
                    </tr>
                </table>
                <?php submit_button('Save Changes'); ?>
            </form>

            <h2>Attack Summary</h2>
            <form method="post">
                <?php submit_button('Clear Attack List', 'delete', 'clear_attacks'); ?>
                <?php submit_button('Export CSV', 'secondary', 'export_csv'); ?>
            </form>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>IP Address</th>
                        <th>First Attack</th>
                        <th>Total Attacks</th>
                        <th>Payment Method</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $attacks = get_option('checkout_sentinel_attacks', array());
                    foreach ($attacks as $ip => $data) {
                        echo "<tr>";
                        echo "<td>" . esc_html($ip) . "</td>";
                        echo "<td>" . esc_html($data['first_attack']) . "</td>";
                        echo "<td>" . esc_html($data['count']) . "</td>";
                        echo "<td>" . esc_html($data['payment_method']) . "</td>";
                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    function checkout_sentinel_export_csv()
    {
        $attacks = get_option('checkout_sentinel_attacks', array());
        $filename = 'checkout_sentinel_attacks_' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fputcsv($output, array('IP Address', 'First Attack', 'Total Attacks', 'Payment Method'));

        foreach ($attacks as $ip => $data) {
            fputcsv($output, array($ip, $data['first_attack'], $data['count'], $data['payment_method']));
        }

        fclose($output);
        exit;
    }

    add_action('wp_ajax_checkout_sentinel_check', 'checkout_sentinel_ajax_check');
    add_action('wp_ajax_nopriv_checkout_sentinel_check', 'checkout_sentinel_ajax_check');

    function checkout_sentinel_ajax_check() {
        $result = array('error' => false);
        if (checkout_sentinel_try_debounce()) {
            $result['error'] = true;
            $result['message'] = __('Error: We have detected too many attempts and we have disabled payment methods to prevent spam or DDOS. Please wait a while and try again', 'woocommerce');
        }
        wp_send_json($result);
    }
} else {
    add_action('admin_notices', 'woocommerce_missing_notice');
    function woocommerce_missing_notice()
    {
        echo '<div class="error"><p>';
        echo __('The Checkout Sentinel plugin requires WooCommerce to be installed and activated.', 'woocommerce');
        echo '</p></div>';
    }
}