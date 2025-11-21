<?php

namespace BlitzCDN\Admin;

class Settings {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_admin_menu() {
        add_options_page(
            'BlitzCDN Settings',
            'BlitzCDN',
            'manage_options',
            'blitzcdn',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('blitzcdn_settings_group', 'blitzcdn_settings');

        add_settings_section(
            'blitzcdn_main_section',
            '🚧 Warning 🚨: Improper configuration may lead to data loss. Please reach out to us for assistance. 🚧',
            null,
            'blitzcdn'
        );

        add_settings_field(
            'account_email',
            'Account Email',
            [$this, 'render_text_field'],
            'blitzcdn',
            'blitzcdn_main_section',
            ['field' => 'account_email', 'description' => 'Required to enable uploads and migrations.']
        );

        add_settings_field(
            'serve_from_cdn',
            'Serve from CDN',
            [$this, 'render_checkbox_field'],
            'blitzcdn',
            'blitzcdn_main_section',
            ['field' => 'serve_from_cdn']
        );

        add_settings_field(
            'safe_delete',
            'Safe Delete Local Files',
            [$this, 'render_checkbox_field'],
            'blitzcdn',
            'blitzcdn_main_section',
            ['field' => 'safe_delete', 'description' => 'Warning: Only enable if you are sure. Deletes local files only after successful upload.']
        );

        add_settings_field(
            'delete_remote',
            'Delete from Appwrite',
            [$this, 'render_checkbox_field'],
            'blitzcdn',
            'blitzcdn_main_section',
            ['field' => 'delete_remote', 'description' => 'When you delete an image from WordPress, delete it from Appwrite as well.']
        );
    }

    public function render_text_field($args) {
        $options = get_option('blitzcdn_settings');
        $field = $args['field'];
        $value = $options[$field] ?? ($args['default'] ?? '');
        $description = $args['description'] ?? '';
        echo "<input type='text' name='blitzcdn_settings[$field]' value='" . esc_attr($value) . "' class='regular-text'>";
        if ($description) {
            echo "<p class='description'>$description</p>";
        }
    }

    public function render_password_field($args) {
        $options = get_option('blitzcdn_settings');
        $field = $args['field'];
        $value = $options[$field] ?? '';
        echo "<input type='password' name='blitzcdn_settings[$field]' value='" . esc_attr($value) . "' class='regular-text'>";
    }

    public function render_checkbox_field($args) {
        $options = get_option('blitzcdn_settings');
        $field = $args['field'];
        $value = $options[$field] ?? false;
        $checked = checked($value, 1, false);
        $description = $args['description'] ?? '';
        echo "<input type='checkbox' name='blitzcdn_settings[$field]' value='1' $checked>";
        if ($description) {
            echo "<p class='description'>$description</p>";
        }
    }

    public function render_settings_page() {
        $options = get_option('blitzcdn_settings', []);
        $account_email = $options['account_email'] ?? '';
        $is_configured = !empty($account_email);
        ?>
        <div class="wrap">
            <h1>BlitzCDN Settings</h1>
            
            <?php if (!$is_configured): ?>
                <div style="background: #fff8e5; border-left: 4px solid #ffb81c; padding: 12px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0; color: #333;">
                        <strong>⚠️ Setup Required</strong><br>
                        Please enter your <strong>Account Email</strong> below to enable uploads and migrations. Without this, all CDN features will be disabled.
                    </p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('blitzcdn_settings_group');
                do_settings_sections('blitzcdn');
                submit_button();
                ?>
            </form>
            
            <hr>
            
            <h2>Migration Tool</h2>
            
            <?php if (!$is_configured): ?>
                <div style="background: #fee; border-left: 4px solid #dc3545; padding: 12px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0; color: #333;">
                        <strong>🔒 Feature Locked</strong><br>
                        The migration tool is disabled until you set your Account Email in the settings above.
                    </p>
                </div>
                <p style="opacity: 0.6; pointer-events: none;">
                    <button class="button button-secondary" disabled>Migrate Existing Media (Browser)</button>
                    <button class="button button-primary" disabled>Start Background Migration</button>
                </p>
            <?php else: ?>
                <p style="color: #28a745; font-weight: 500;">✓ Account email configured: <code><?php echo esc_html($account_email); ?></code></p>
                <p>Migrate existing media to Appwrite.</p>
                <p>
                    <button id="blitzcdn-migrate-btn" class="button button-secondary">Migrate Existing Media (Browser)</button>
                    <button id="blitzcdn-background-migrate-btn" class="button button-primary">Start Background Migration</button>
                    <button id="blitzcdn-stop-background-migrate-btn" class="button button-secondary" style="display:none;">Stop Background Migration</button>
                </p>
                
                <div id="blitzcdn-background-status" style="margin-top: 10px; display: none; padding: 10px; background: #fff; border: 1px solid #ccd0d4;">
                    <p><strong>Background Migration Status:</strong> <span id="blitzcdn-bg-status-text">Idle</span></p>
                    <p>Processed: <span id="blitzcdn-bg-processed">0</span> / <span id="blitzcdn-bg-total">0</span></p>
                </div>

                <div id="blitzcdn-migration-progress" style="margin-top: 20px; display: none;">
                    <div style="background: #f0f0f1; border: 1px solid #ccc; height: 20px; width: 100%;">
                        <div id="blitzcdn-progress-bar" style="background: #2271b1; height: 100%; width: 0%;"></div>
                    </div>
                    <p id="blitzcdn-progress-text">0%</p>
                    <div id="blitzcdn-migration-log" style="max-height: 200px; overflow-y: auto; background: #fff; border: 1px solid #ddd; padding: 10px; margin-top: 10px;"></div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
