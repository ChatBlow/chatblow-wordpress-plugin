<?php
/**
 * Plugin Name: ChatBlow
 * Plugin URI: https://chatblow.com/
 * Description: The official ChatBlow WordPress plugin to embed the AI assistant on your site.
 * Version: 1.0.1
 * Author: ChatBlow
 * Author URI: https://chatblow.com/
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

class ChatBlowPlugin {

    public function __construct() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('wp_ajax_chatblow_verify_site', array($this, 'ajax_verify_site'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assistant_script'));
        add_filter('script_loader_tag', array($this, 'add_script_attributes'), 10, 2);
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function register_settings() {
        register_setting('chatblow_settings', 'chatblow_enabled', array('sanitize_callback' => 'absint'));
        register_setting('chatblow_settings', 'chatblow_widget_position', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('chatblow_settings', 'chatblow_pass_user_meta', array('sanitize_callback' => 'absint'));
    }

    public function register_admin_menu() {
        add_menu_page(
            'ChatBlow Settings',
            'ChatBlow',
            'manage_options',
            'chatblow-settings',
            array($this, 'render_admin_page'),
            'dashicons-format-chat',
            100
        );
    }

    public function render_admin_page() {
        $enabled = get_option('chatblow_enabled', '1');
        $position = get_option('chatblow_widget_position', 'bottom-right');
        $pass_meta = get_option('chatblow_pass_user_meta', '0');
        $site_url = site_url();
        
        $positions = array(
            'bottom-right' => 'Bottom Right',
            'bottom-left' => 'Bottom Left',
            'bottom-center' => 'Bottom Center',
            'top-right' => 'Top Right',
            'top-left' => 'Top Left',
            'top-center' => 'Top Center',
            'left-center' => 'Left Center',
            'right-center' => 'Right Center',
        );

        ?>
        <div class="wrap">
            <h1>ChatBlow Configuration</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('chatblow_settings'); ?>
                <?php do_settings_sections('chatblow_settings'); ?>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Enable ChatBlow</th>
                        <td>
                            <label>
                                <input type="checkbox" name="chatblow_enabled" value="1" <?php checked($enabled, '1'); ?> />
                                Enable the ChatBlow widget on your site.
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Widget Position</th>
                        <td>
                            <select name="chatblow_widget_position">
                                <?php foreach ($positions as $val => $label): ?>
                                    <option value="<?php echo esc_attr($val); ?>" <?php selected($position, $val); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Track User on ChatBlow</th>
                        <td>
                            <label>
                                <input type="checkbox" name="chatblow_pass_user_meta" value="1" <?php checked($pass_meta, '1'); ?> />
                                Automatically send logged-in WordPress user's ID, Name, and Email to ChatBlow.
                            </label>
                            <p class="description" style="margin-top: 10px;">
                                <strong>Developer Note:</strong> You can alter the metadata object sent to ChatBlow using the <code>chatblow_metadata</code> filter hook in your theme's functions.php.<br/>
                                <em>Note: The first two values in the metadata object will be visible directly in chat threads on ChatBlow, and for others you need to click the 'more' icon to see them.</em>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>

            <hr/>

            <h2>Status & Verification</h2>
            <p>Your WordPress site origin is: <strong><?php echo esc_html($site_url); ?></strong></p>
            
            <div id="chatblow-status-panel" style="margin-top: 20px; padding: 15px; border: 1px solid #ccc; background: #fff; max-width: 600px;">
                <p>Checking registration status...</p>
            </div>

            <script>
            jQuery(document).ready(function($) {
                $.post(ajaxurl, {
                    action: 'chatblow_verify_site',
                    nonce: '<?php echo wp_create_nonce('chatblow_verify'); ?>'
                }, function(response) {
                    const panel = $('#chatblow-status-panel');
                    
                    if (response.success && response.data) {
                        const data = response.data;
                        if (data.registered) {
                            panel.html(`
                                <h3 style="color: green; margin-top: 0;">✅ Site Registered Successfully</h3>
                                <p><strong>Plan:</strong> ${data.plan}</p>
                                <p><strong>Available Tokens:</strong> ${data.tokens}</p>
                            `);
                        } else {
                            panel.html(`
                                <h3 style="color: #d63638; margin-top: 0;">❌ Site Not Registered</h3>
                                <p>This domain (<strong><?php echo esc_js($site_url); ?></strong>) is not registered on ChatBlow, or there are no available tokens.</p>
                                <p>Please go to <a href="https://chatblow.com" target="_blank">ChatBlow.com</a> to register this website and activate your plan.</p>
                            `);
                        }
                    } else {
                        panel.html('<p style="color: #d63638;">Failed to connect to ChatBlow API.</p>');
                    }
                }).fail(function() {
                    $('#chatblow-status-panel').html('<p style="color: #d63638;">An error occurred while verifying the site.</p>');
                });
            });
            </script>
        </div>
        <?php
    }

    public function ajax_verify_site() {
        check_ajax_referer('chatblow_verify', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $api_url = 'https://chatblow.com/api/plugin/verify';

        $response = wp_remote_post($api_url, array(
            'body' => array(
                'domain' => site_url()
            ),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            // Sanitize API data to prevent XSS
            if (isset($data['data']['plan'])) {
                $data['data']['plan'] = sanitize_text_field($data['data']['plan']);
            }
            if (isset($data['data']['tokens'])) {
                $data['data']['tokens'] = absint($data['data']['tokens']);
            }
            if (isset($data['plan'])) {
                $data['plan'] = sanitize_text_field($data['plan']);
            }
            if (isset($data['tokens'])) {
                $data['tokens'] = absint($data['tokens']);
            }

            // The API response already wraps the data in 'success' and 'data'
            if (isset($data['success']) && $data['success'] && isset($data['data'])) {
                wp_send_json_success($data['data']);
            } else {
                wp_send_json_success($data);
            }
        } else {
            wp_send_json_error('Invalid JSON response from ChatBlow API');
        }
    }

    public function enqueue_assistant_script() {
        $enabled = get_option('chatblow_enabled', '1');
        if (!$enabled) {
            return;
        }

        $script_url = 'https://chatblow.com/static/assistant.js';
        wp_enqueue_script('chatblow-assistant', $script_url, array(), '1.0.1', true);
    }

    public function add_script_attributes($tag, $handle) {
        if ('chatblow-assistant' !== $handle) {
            return $tag;
        }

        $position = get_option('chatblow_widget_position', 'bottom-right');
        $pass_meta = get_option('chatblow_pass_user_meta', '0');

        $metadata = array();

        if ($pass_meta && is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $metadata['name'] = $current_user->display_name;
            $metadata['email'] = $current_user->user_email;
            $metadata['user_id'] = $current_user->ID;
        }

        $metadata = apply_filters('chatblow_metadata', $metadata);

        $meta_attr = '';
        if (!empty($metadata)) {
            $meta_attr = ' data-metadata="' . esc_attr(json_encode($metadata)) . '"';
        }

        $position_attr = ' data-position="' . esc_attr($position) . '"';

        // Inject the attributes into the script tag
        $tag = str_replace(' src', $position_attr . $meta_attr . ' src', $tag);

        return $tag;
    }
}

new ChatBlowPlugin();
