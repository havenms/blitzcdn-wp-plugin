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
            
            <hr>
            
            <h2>Goodbye Procedure</h2>
            <p>Leaving BlitzCDN? This tool will redownload all your media files from Appwrite back to WordPress, restore local URLs, and optionally clean up files on Appwrite.</p>
            
            <?php if (!$is_configured): ?>
                <div style="background: #fee; border-left: 4px solid #dc3545; padding: 12px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0; color: #333;">
                        <strong>🔒 Feature Locked</strong><br>
                        The goodbye procedure is disabled until you set your Account Email in the settings above.
                    </p>
                </div>
            <?php else: ?>
                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0 0 10px 0; color: #333;">
                        <strong>Important Notes:</strong>
                    </p>
                    <ul style="margin: 0; padding-left: 20px; color: #555;">
                        <li>This process will download all files from Appwrite and store them in your <code>wp-content/uploads</code> folder</li>
                        <li>Files will be saved to their original paths (where WordPress originally placed them)</li>
                        <li>If a file already exists locally, it will be skipped (no overwrite)</li>
                        <li>After successful download, CDN metadata will be cleared so URLs point to local files</li>
                        <li>Keep this browser tab open until the process completes</li>
                    </ul>
                </div>
                
                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 20px 0; border-radius: 4px;">
                    <h3 style="margin-top: 0;">Options</h3>
                    
                    <p>
                        <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="blitzcdn-delete-after-redownload" value="1" style="margin-top: 3px;">
                            <span>
                                <strong>Delete files from Appwrite after successful redownload</strong><br>
                                <span style="color: #666; font-size: 13px;">
                                    Only deletes files after they have been successfully downloaded and saved locally. 
                                    If any download fails, files will NOT be deleted from Appwrite for that attachment.
                                </span>
                            </span>
                        </label>
                    </p>
                </div>
                
                <p>
                    <button id="blitzcdn-redownload-btn" class="button button-secondary" style="background: #dc3545; border-color: #dc3545; color: #fff;">
                        Start Goodbye Procedure
                    </button>
                    <button id="blitzcdn-cancel-redownload-btn" class="button button-secondary" style="display: none;">
                        Cancel
                    </button>
                </p>
                
                <div id="blitzcdn-redownload-progress" style="margin-top: 20px; display: none;">
                    <div style="background: #f0f0f1; border: 1px solid #ccc; height: 20px; width: 100%; border-radius: 4px; overflow: hidden;">
                        <div id="blitzcdn-redownload-progress-bar" style="background: linear-gradient(90deg, #dc3545, #fd7e14); height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                    </div>
                    <p id="blitzcdn-redownload-progress-text" style="margin-top: 10px; font-weight: 500;">0%</p>
                    
                    <div id="blitzcdn-redownload-stats" style="display: flex; gap: 20px; margin-top: 15px;">
                        <div style="background: #d4edda; padding: 10px 15px; border-radius: 4px; flex: 1; text-align: center;">
                            <div style="font-size: 24px; font-weight: bold; color: #155724;" id="blitzcdn-success-count">0</div>
                            <div style="font-size: 12px; color: #155724;">Downloaded</div>
                        </div>
                        <div style="background: #fff3cd; padding: 10px 15px; border-radius: 4px; flex: 1; text-align: center;">
                            <div style="font-size: 24px; font-weight: bold; color: #856404;" id="blitzcdn-skipped-count">0</div>
                            <div style="font-size: 12px; color: #856404;">Already Local</div>
                        </div>
                        <div style="background: #f8d7da; padding: 10px 15px; border-radius: 4px; flex: 1; text-align: center;">
                            <div style="font-size: 24px; font-weight: bold; color: #721c24;" id="blitzcdn-error-count">0</div>
                            <div style="font-size: 12px; color: #721c24;">Errors</div>
                        </div>
                    </div>
                    
                    <div id="blitzcdn-redownload-log" style="max-height: 300px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; border: 1px solid #333; padding: 15px; margin-top: 15px; font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; border-radius: 4px;"></div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
