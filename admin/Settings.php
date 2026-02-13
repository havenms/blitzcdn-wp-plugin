<?php

namespace BlitzCDN\Admin;

class Settings
{

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Check an environment flag as boolean.
     * Accepts 1/true/yes (case-insensitive) as truthy.
     */
    private function env_flag_true($name)
    {
        $val = getenv($name);
        if ($val === false && isset($_ENV[$name])) {
            $val = $_ENV[$name];
        }
        if ($val === false && isset($_SERVER[$name])) {
            $val = $_SERVER[$name];
        }
        if ($val === false || $val === null)
            return false;
        $val = strtolower(trim((string) $val));
        return in_array($val, ['1', 'true', 'yes'], true);
    }

    public function add_admin_menu()
    {
        add_options_page(
            'BlitzCDN Settings',
            'BlitzCDN',
            'manage_options',
            'blitzcdn',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings()
    {
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

        // Conditionally hide classic migration batch size fields via env flag
        $hide_classic_migration = $this->env_flag_true('BLITZCDN_HIDE_CLASSIC_MIGRATION');
        if (!$hide_classic_migration) {
            add_settings_field(
                'migration_batch_size',
                'Migration Batch Size',
                [$this, 'render_number_field'],
                'blitzcdn',
                'blitzcdn_main_section',
                ['field' => 'migration_batch_size', 'default' => 20, 'min' => 1, 'max' => 100, 'description' => 'Number of attachments to process per batch in browser migration. Lower values are safer for slower servers. (Default: 20)']
            );

            add_settings_field(
                'redownload_batch_size',
                'Redownload Batch Size',
                [$this, 'render_number_field'],
                'blitzcdn',
                'blitzcdn_main_section',
                ['field' => 'redownload_batch_size', 'default' => 5, 'min' => 1, 'max' => 50, 'description' => 'Number of attachments to process per batch in goodbye procedure. Downloads are heavier, so default is lower. (Default: 5)']
            );
        }

        // Zip Migration Settings Section
        add_settings_section(
            'blitzcdn_zip_migration_section',
            'Fast Migration (Zip-based)',
            [$this, 'render_zip_migration_section_description'],
            'blitzcdn'
        );

        add_settings_field(
            'middleware_url',
            'Middleware URL',
            [$this, 'render_text_field'],
            'blitzcdn',
            'blitzcdn_zip_migration_section',
            ['field' => 'middleware_url', 'description' => 'URL of the BlitzCDN middleware service (e.g., https://middleware.example.com). Required for zip-based migration.', 'placeholder' => 'https://middleware.example.com']
        );

        add_settings_field(
            'middleware_api_key',
            'Middleware API Key',
            [$this, 'render_password_field'],
            'blitzcdn',
            'blitzcdn_zip_migration_section',
            ['field' => 'middleware_api_key', 'description' => 'API key for authenticating with the middleware service. Optional but recommended.']
        );

        add_settings_field(
            'webhook_secret',
            'Webhook Secret',
            [$this, 'render_password_field'],
            'blitzcdn',
            'blitzcdn_zip_migration_section',
            ['field' => 'webhook_secret', 'description' => 'Secret token for authenticating webhook callbacks from middleware. Auto-generated if empty.', 'auto_generate' => true]
        );

        add_settings_field(
            'zip_batch_size',
            'Zip Batch Size',
            [$this, 'render_number_field'],
            'blitzcdn',
            'blitzcdn_zip_migration_section',
            ['field' => 'zip_batch_size', 'default' => 100, 'min' => 1, 'max' => 10000, 'description' => 'Maximum number of attachments to include per zip file. Each attachment may contain multiple assets (original + sizes). The system enforces a hard cap of 10,000 total files (originals + sizes) per zip to ensure stable processing. For 18,000 files, multiple migration runs may be needed. (Default: 100)']
        );
    }

    public function render_zip_migration_section_description()
    {
        echo '<p>Configure the middleware service for high-performance zip-based migration. The middleware processes files in parallel for faster uploads.</p>';
        echo '<p><strong>Important:</strong> Once the zip is uploaded to the middleware, the migration continues in the background. You can safely close your browser after seeing the confirmation message.</p>';
    }

    public function render_text_field($args)
    {
        $options = get_option('blitzcdn_settings');
        $field = $args['field'];
        $value = $options[$field] ?? ($args['default'] ?? '');
        $description = $args['description'] ?? '';
        $placeholder = $args['placeholder'] ?? '';
        echo "<input type='text' name='blitzcdn_settings[$field]' value='" . esc_attr($value) . "' class='regular-text' placeholder='" . esc_attr($placeholder) . "'>";
        if ($description) {
            echo "<p class='description'>" . esc_html($description) . "</p>";
        }
    }

    public function render_password_field($args)
    {
        $options = get_option('blitzcdn_settings');
        $field = $args['field'];
        $value = $options[$field] ?? '';
        $description = $args['description'] ?? '';
        $auto_generate = $args['auto_generate'] ?? false;

        // Auto-generate webhook secret if empty and auto_generate is true
        if ($auto_generate && empty($value) && $field === 'webhook_secret') {
            $value = wp_generate_password(32, false);
            // Save the generated value
            $options[$field] = $value;
            update_option('blitzcdn_settings', $options);
        }

        echo "<input type='password' name='blitzcdn_settings[$field]' value='" . esc_attr($value) . "' class='regular-text'>";
        if ($auto_generate) {
            echo " <button type='button' class='button button-secondary blitzcdn-regenerate-secret' data-field='" . esc_attr($field) . "'>Regenerate</button>";
        }
        if ($description) {
            echo "<p class='description'>" . esc_html($description) . "</p>";
        }
    }

    public function render_checkbox_field($args)
    {
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

    public function render_number_field($args)
    {
        $options = get_option('blitzcdn_settings');
        $field = $args['field'];
        $default = $args['default'] ?? 1;
        $value = isset($options[$field]) ? intval($options[$field]) : $default;
        $min = $args['min'] ?? 1;
        $max = $args['max'] ?? 1000;
        $description = $args['description'] ?? '';
        echo "<input type='number' name='blitzcdn_settings[$field]' value='" . esc_attr($value) . "' min='" . esc_attr($min) . "' max='" . esc_attr($max) . "' class='small-text'>";
        if ($description) {
            echo "<p class='description'>$description</p>";
        }
    }

    public function render_settings_page()
    {
        $options = get_option('blitzcdn_settings', []);
        $account_email = $options['account_email'] ?? '';
        $is_configured = !empty($account_email);
        $hide_classic_migration = $this->env_flag_true('BLITZCDN_HIDE_CLASSIC_MIGRATION');
        ?>
        <div class="wrap">
            <h1>BlitzCDN Settings</h1>

            <?php if (!$is_configured): ?>
                <div
                    style="background: #fff8e5; border-left: 4px solid #ffb81c; padding: 12px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0; color: #333;">
                        <strong>⚠️ Setup Required</strong><br>
                        Please enter your <strong>Account Email</strong> below to enable uploads and migrations. Without this, all
                        CDN features will be disabled.
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

            <?php if (!$hide_classic_migration): ?>
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
                    <p style="color: #28a745; font-weight: 500;">✓ Account email configured:
                        <code><?php echo esc_html($account_email); ?></code></p>
                    <p>Migrate existing media to Appwrite.</p>

                    <div
                        style="background: #e7f3ff; border-left: 4px solid #2271b1; padding: 12px; margin: 15px 0; border-radius: 4px;">
                        <p style="margin: 0 0 8px 0; color: #333; font-weight: 500;">
                            <strong>📊 Understanding Progress Tracking</strong>
                        </p>
                        <p style="margin: 0; color: #555; font-size: 13px; line-height: 1.6;">
                            Each image in your media library has multiple sizes that need to be uploaded separately: the original file
                            plus generated sizes (thumbnail, medium, large, etc.). Each of these files—the original and each size—is
                            counted as an <strong>asset</strong>. The progress bar shows the total number of assets being processed, not
                            just the number of images. For example, if you have 100 images, you might see progress tracking 400 assets
                            (100 images × 4 assets each on average).
                        </p>
                    </div>

                    <p>
                        <button type="button" id="blitzcdn-migrate-btn" class="button button-secondary"
                            data-nonce="<?php echo wp_create_nonce('blitzcdn_migration_nonce'); ?>"
                            data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>">Migrate Existing Media (Browser)</button>
                        <button type="button" id="blitzcdn-background-migrate-btn" class="button button-primary"
                            data-nonce="<?php echo wp_create_nonce('blitzcdn_migration_nonce'); ?>"
                            data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>">Start Background Migration</button>
                        <button type="button" id="blitzcdn-stop-background-migrate-btn" class="button button-secondary"
                            style="display:none;" data-nonce="<?php echo wp_create_nonce('blitzcdn_migration_nonce'); ?>"
                            data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>">Stop Background Migration</button>
                    </p>

                    <div id="blitzcdn-background-status"
                        style="margin-top: 10px; display: none; padding: 10px; background: #fff; border: 1px solid #ccd0d4;">
                        <p><strong>Background Migration Status:</strong> <span id="blitzcdn-bg-status-text">Idle</span></p>
                        <p>Processed: <span id="blitzcdn-bg-processed">0</span> / <span id="blitzcdn-bg-total">0</span></p>
                    </div>

                    <div id="blitzcdn-migration-progress" style="margin-top: 20px; display: none;">
                        <div
                            style="background: #f0f0f1; border: 1px solid #ccc; height: 20px; width: 100%; border-radius: 4px; overflow: hidden;">
                            <div id="blitzcdn-progress-bar"
                                style="background: linear-gradient(90deg, #2271b1, #3794ff); height: 100%; width: 0%; transition: width 0.3s ease;">
                            </div>
                        </div>
                        <p id="blitzcdn-progress-text" style="margin-top: 10px; font-weight: 500;">0%</p>
                        <div id="blitzcdn-migration-log"
                            style="max-height: 300px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; border: 1px solid #333; padding: 15px; margin-top: 15px; font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; border-radius: 4px;">
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <hr>

            <?php $this->render_zip_migration_section($is_configured, $options); ?>

            <hr>

            <h2>Goodbye Procedure</h2>
            <p>Leaving BlitzCDN? This tool will redownload all your media files from Appwrite back to WordPress, restore local
                URLs, and optionally clean up files on Appwrite.</p>

            <?php if (!$is_configured): ?>
                <div style="background: #fee; border-left: 4px solid #dc3545; padding: 12px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0; color: #333;">
                        <strong>🔒 Feature Locked</strong><br>
                        The goodbye procedure is disabled until you set your Account Email in the settings above.
                    </p>
                </div>
            <?php else: ?>
                <div
                    style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0 0 10px 0; color: #333;">
                        <strong>Important Notes:</strong>
                    </p>
                    <ul style="margin: 0; padding-left: 20px; color: #555;">
                        <li>This process will download all files from Appwrite and store them in your
                            <code>wp-content/uploads</code> folder</li>
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
                    <button type="button" id="blitzcdn-redownload-btn" class="button button-secondary"
                        style="background: #dc3545; border-color: #dc3545; color: #fff;"
                        data-nonce="<?php echo wp_create_nonce('blitzcdn_migration_nonce'); ?>"
                        data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>">
                        Start Goodbye Procedure
                    </button>
                    <button type="button" id="blitzcdn-cancel-redownload-btn" class="button button-secondary"
                        style="display: none;">
                        Cancel
                    </button>
                </p>

                <div id="blitzcdn-redownload-progress" style="margin-top: 20px; display: none;">
                    <div
                        style="background: #f0f0f1; border: 1px solid #ccc; height: 20px; width: 100%; border-radius: 4px; overflow: hidden;">
                        <div id="blitzcdn-redownload-progress-bar"
                            style="background: linear-gradient(90deg, #dc3545, #fd7e14); height: 100%; width: 0%; transition: width 0.3s ease;">
                        </div>
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

                    <div id="blitzcdn-redownload-log"
                        style="max-height: 300px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; border: 1px solid #333; padding: 15px; margin-top: 15px; font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; border-radius: 4px;">
                    </div>
                </div>
            <?php endif; ?>

            <hr>

            <h2>Download Media by Email</h2>
            <p>Download all media files associated with a specific email address from Appwrite. This creates new WordPress attachments with proper directory structure and naming conventions.</p>

            <?php if (!$is_configured): ?>
                <div style="background: #fee; border-left: 4px solid #dc3545; padding: 12px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0; color: #333;">
                        <strong>🔒 Feature Locked</strong><br>
                        This feature is disabled until Appwrite is properly configured.
                    </p>
                </div>
            <?php else: ?>
                <div style="background: #e7f3ff; border-left: 4px solid #2271b1; padding: 12px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0 0 10px 0; color: #333;">
                        <strong>How it works:</strong>
                    </p>
                    <ul style="margin: 0; padding-left: 20px; color: #555;">
                        <li>Enter an email address to query files in Appwrite Database</li>
                        <li>Downloads all files associated with that email</li>
                        <li>Creates WordPress attachments with proper metadata</li>
                        <li>Files are saved in year/month directory structure</li>
                        <li>Skips files that already exist as WordPress attachments</li>
                        <li>Generates thumbnail sizes automatically</li>
                    </ul>
                </div>

                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 20px 0; border-radius: 4px;">
                    <h3 style="margin-top: 0;">Email Address</h3>
                    <p>
                        <input type="email" id="blitzcdn-email-input" 
                            placeholder="user@example.com"
                            style="width: 100%; max-width: 400px; padding: 8px;"
                            value="">
                    </p>
                    <p>
                        <button type="button" id="blitzcdn-check-email-btn" class="button button-primary">
                            Check Files for Email
                        </button>
                    </p>

                    <div id="blitzcdn-email-stats" style="display: none; margin-top: 15px; padding: 15px; background: #f9f9f9; border-left: 4px solid #2271b1;">
                        <p style="margin: 0;"><strong>Found <span id="blitzcdn-email-files-count">0</span> file(s)</strong></p>
                    </div>

                    <p style="margin-top: 15px;">
                        <strong>Operation Mode:</strong><br>
                        <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; margin: 8px 0;">
                            <input type="radio" name="blitzcdn-email-mode" value="link" checked style="margin-top: 3px;">
                            <span>
                                <strong>Link Only (Browser)</strong> - Create WordPress attachments pointing to CDN URLs<br>
                                <span style="color: #666; font-size: 13px;">
                                    Files stay on Appwrite/CDN. Processes in your browser. Keep page open until complete.
                                </span>
                            </span>
                        </label>
                        <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; margin: 8px 0;">
                            <input type="radio" name="blitzcdn-email-mode" value="link-background" style="margin-top: 3px;">
                            <span>
                                <strong>Link Only (Background via WP-Cron) 🚀</strong> - Background processing with WP-Cron<br>
                                <span style="color: #666; font-size: 13px;">
                                    Creates attachments pointing to CDN. Runs in background via WP-Cron. Close browser anytime! Best for large batches.
                                </span>
                            </span>
                        </label>
                        <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; margin: 8px 0;">
                            <input type="radio" name="blitzcdn-email-mode" value="download" style="margin-top: 3px;">
                            <span>
                                <strong>Download (Slow)</strong> - Download files and create WordPress attachments<br>
                                <span style="color: #666; font-size: 13px;">
                                    Downloads actual files to your server. Slower but gives you local copies.
                                </span>
                            </span>
                        </label>
                    </p>
                </div>

                <!-- Background Linking Status -->
                <div id="blitzcdn-background-link-status" style="display: none; margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3 style="margin-top: 0;">Background Linking Status</h3>
                    <p><strong>Status:</strong> <span id="blitzcdn-bg-link-status-text">Idle</span></p>
                    <p><strong>Email:</strong> <span id="blitzcdn-bg-link-email">-</span></p>
                    
                    <div style="margin: 15px 0;">
                        <div style="background: #f0f0f1; border: 1px solid #ccc; height: 20px; width: 100%; border-radius: 4px; overflow: hidden;">
                            <div id="blitzcdn-bg-link-progress-bar" 
                                style="background: linear-gradient(90deg, #2271b1, #3794ff); height: 100%; width: 0%; transition: width 0.3s ease;">
                            </div>
                        </div>
                        <p id="blitzcdn-bg-link-progress-text" style="margin-top: 8px; font-weight: 500;">0%</p>
                    </div>

                    <div style="display: flex; gap: 15px; margin-top: 15px;">
                        <div style="background: #d4edda; padding: 10px 15px; border-radius: 4px; flex: 1; text-align: center;">
                            <div style="font-size: 20px; font-weight: bold; color: #155724;" id="blitzcdn-bg-link-processed">0</div>
                            <div style="font-size: 12px; color: #155724;">Processed</div>
                        </div>
                        <div style="background: #e7f3ff; padding: 10px 15px; border-radius: 4px; flex: 1; text-align: center;">
                            <div style="font-size: 20px; font-weight: bold; color: #2271b1;" id="blitzcdn-bg-link-successful">0</div>
                            <div style="font-size: 12px; color: #2271b1;">Successful</div>
                        </div>
                        <div style="background: #fff3cd; padding: 10px 15px; border-radius: 4px; flex: 1; text-align: center;">
                            <div style="font-size: 20px; font-weight: bold; color: #856404;" id="blitzcdn-bg-link-skipped">0</div>
                            <div style="font-size: 12px; color: #856404;">Skipped</div>
                        </div>
                        <div style="background: #f8d7da; padding: 10px 15px; border-radius: 4px; flex: 1; text-align: center;">
                            <div style="font-size: 20px; font-weight: bold; color: #721c24;" id="blitzcdn-bg-link-failed">0</div>
                            <div style="font-size: 12px; color: #721c24;">Failed</div>
                        </div>
                    </div>

                    <p style="margin-top: 15px;">
                        <button type="button" id="blitzcdn-stop-background-link-btn" class="button button-secondary"
                            data-nonce="<?php echo wp_create_nonce('blitzcdn_migration_nonce'); ?>"
                            data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>">
                            Stop Background Linking
                        </button>
                        <button type="button" id="blitzcdn-refresh-background-link-btn" class="button"
                            data-nonce="<?php echo wp_create_nonce('blitzcdn_migration_nonce'); ?>"
                            data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>">
                            Refresh Status
                        </button>
                    </p>
                </div>

                <p>
                    <button type="button" id="blitzcdn-email-redownload-btn" class="button button-primary"
                        style="display: none;"
                        data-nonce="<?php echo wp_create_nonce('blitzcdn_migration_nonce'); ?>"
                        data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>">
                        Start Process
                    </button>
                    <button type="button" id="blitzcdn-cancel-email-redownload-btn" class="button button-secondary"
                        style="display: none;">
                        Cancel
                    </button>
                </p>

                <div id="blitzcdn-email-redownload-progress" style="margin-top: 20px; display: none;">
                    <div
                        style="background: #f0f0f1; border: 1px solid #ccc; height: 20px; width: 100%; border-radius: 4px; overflow: hidden;">
                        <div id="blitzcdn-email-redownload-progress-bar"
                            style="background: linear-gradient(90deg, #2271b1, #3794ff); height: 100%; width: 0%; transition: width 0.3s ease;">
                        </div>
                    </div>
                    <p id="blitzcdn-email-redownload-progress-text" style="margin-top: 10px; font-weight: 500;">0%</p>

                    <div id="blitzcdn-email-redownload-stats" style="display: flex; gap: 20px; margin-top: 15px;">
                        <div style="background: #d4edda; padding: 10px 15px; border-radius: 4px; flex: 1; text-align: center;">
                            <div style="font-size: 24px; font-weight: bold; color: #155724;" id="blitzcdn-email-success-count">0</div>
                            <div style="font-size: 12px; color: #155724;">Processed</div>
                        </div>
                        <div style="background: #fff3cd; padding: 10px 15px; border-radius: 4px; flex: 1; text-align: center;">
                            <div style="font-size: 24px; font-weight: bold; color: #856404;" id="blitzcdn-email-skipped-count">0</div>
                            <div style="font-size: 12px; color: #856404;">Already Exist</div>
                        </div>
                        <div style="background: #f8d7da; padding: 10px 15px; border-radius: 4px; flex: 1; text-align: center;">
                            <div style="font-size: 24px; font-weight: bold; color: #721c24;" id="blitzcdn-email-error-count">0</div>
                            <div style="font-size: 12px; color: #721c24;">Errors</div>
                        </div>
                    </div>

                    <div id="blitzcdn-email-redownload-log"
                        style="max-height: 300px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; border: 1px solid #333; padding: 15px; margin-top: 15px; font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; border-radius: 4px;">
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- WooCommerce Image Reconnection Section -->
        <div class="wrap" style="max-width: 1200px; margin-top: 40px; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
            <h2>🛒 Fix WooCommerce Product Images</h2>
            <p>If you already have media in your library linked to CDN, but WooCommerce products still show broken images, 
            use this tool to reconnect existing CDN attachments to products.</p>
            
            <div style="background: #e7f3ff; border-left: 4px solid #2271b1; padding: 12px; margin: 20px 0; border-radius: 4px;">
                <p style="margin: 0; color: #333;">
                    <strong>ℹ️ What this does:</strong><br>
                    Scans all CDN-linked attachments in your media library and updates WooCommerce products to use the correct attachment IDs.
                    This fixes featured images, gallery images, and product variation images.
                </p>
            </div>

            <p>
                <button type="button" id="blitzcdn-reconnect-woocommerce-btn" class="button button-primary"
                    data-nonce="<?php echo wp_create_nonce('blitzcdn_migration_nonce'); ?>"
                    data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>">
                    Reconnect WooCommerce Images
                </button>
            </p>

            <div id="blitzcdn-reconnect-woocommerce-progress" style="margin-top: 20px; display: none;">
                <div style="background: #f0f0f1; border: 1px solid #ccc; height: 20px; width: 100%; border-radius: 4px; overflow: hidden;">
                    <div id="blitzcdn-reconnect-woocommerce-progress-bar"
                        style="background: linear-gradient(90deg, #2271b1, #3794ff); height: 100%; width: 0%; transition: width 0.3s ease;">
                    </div>
                </div>
                <p id="blitzcdn-reconnect-woocommerce-progress-text" style="margin-top: 10px; font-weight: 500;">Processing...</p>
                
                <div id="blitzcdn-reconnect-woocommerce-log"
                    style="max-height: 300px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; border: 1px solid #333; padding: 15px; margin-top: 15px; font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; border-radius: 4px;">
                </div>
            </div>

            <div id="blitzcdn-reconnect-woocommerce-status" style="margin-top: 20px; display: none;">
                <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 12px; border-radius: 4px;">
                    <div id="blitzcdn-reconnect-woocommerce-message" style="font-weight: 500; color: #155724;"></div>
                    <div id="blitzcdn-reconnect-woocommerce-stats" style="margin-top: 10px; color: #155724;"></div>
                </div>
            </div>

            <div id="blitzcdn-reconnect-woocommerce-error" style="margin-top: 20px; display: none;">
                <div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 12px; border-radius: 4px;">
                    <div style="font-weight: 500; color: #721c24;">❌ Error</div>
                    <div id="blitzcdn-reconnect-woocommerce-error-message" style="margin-top: 5px; color: #721c24;"></div>
                </div>
            </div>
        </div>

        <?php
    }

    /**
     * Render the zip-based migration UI section.
     * 
     * @param bool $is_configured Whether the account is configured.
     * @param array $options Plugin settings.
     */
    private function render_zip_migration_section($is_configured, $options)
    {
        $middleware_url = $options['middleware_url'] ?? '';
        $middleware_configured = !empty($middleware_url);
        ?>
        <h2>⚡ Fast Migration (Zip-based)</h2>
        <p>High-performance migration that packages files into a zip, uploads to a middleware service, and processes files in
            parallel.</p>

        <?php if (!$is_configured): ?>
            <div style="background: #fee; border-left: 4px solid #dc3545; padding: 12px; margin: 20px 0; border-radius: 4px;">
                <p style="margin: 0; color: #333;">
                    <strong>🔒 Feature Locked</strong><br>
                    Fast migration is disabled until you set your Account Email in the settings above.
                </p>
            </div>
        <?php elseif (!$middleware_configured): ?>
            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 20px 0; border-radius: 4px;">
                <p style="margin: 0; color: #333;">
                    <strong>⚙️ Middleware Not Configured</strong><br>
                    To use fast migration, configure the Middleware URL in the "Fast Migration (Zip-based)" settings section above.
                </p>
            </div>
        <?php else: ?>
            <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 12px; margin: 15px 0; border-radius: 4px;">
                <p style="margin: 0 0 8px 0; color: #155724; font-weight: 500;">
                    <strong>✓ Middleware Configured</strong>
                </p>
                <p style="margin: 0; color: #155724; font-size: 13px;">
                    Endpoint: <code><?php echo esc_html($middleware_url); ?></code>
                </p>
            </div>



            <div id="blitzcdn-zip-stats" style="display: flex; gap: 20px; margin: 15px 0;">
                <div style="background: #f0f0f1; padding: 15px 20px; border-radius: 4px; flex: 1;">
                    <div style="font-size: 28px; font-weight: bold; color: #2271b1;" id="blitzcdn-zip-total-attachments">-</div>
                    <div style="font-size: 12px; color: #666;">Attachments to migrate</div>
                </div>
                <div style="background: #f0f0f1; padding: 15px 20px; border-radius: 4px; flex: 1;">
                    <div style="font-size: 28px; font-weight: bold; color: #2271b1;" id="blitzcdn-zip-total-assets">-</div>
                    <div style="font-size: 12px; color: #666;">Total assets (files)</div>
                </div>
            </div>

            <p>
                <button type="button" id="blitzcdn-zip-migrate-btn" class="button button-primary" disabled
                    title="Loading attachment count..."
                    data-nonce="<?php echo esc_attr(wp_create_nonce('blitzcdn_migration_nonce')); ?>"
                    data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                    🚀 Start Fast Migration
                </button>
                <button type="button" id="blitzcdn-zip-check-status-btn" class="button button-secondary"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('blitzcdn_migration_nonce')); ?>"
                    data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                    🔄 Check Status
                </button>
                <button type="button" id="blitzcdn-zip-cancel-btn" class="button"
                    style="display: none; background: #dc3545; color: #fff; border-color: #dc3545;"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('blitzcdn_migration_nonce')); ?>"
                    data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                    🛑 Cancel Migration
                </button>
                <button type="button" id="blitzcdn-zip-reset-btn" class="button button-secondary" style="display: none;"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('blitzcdn_migration_nonce')); ?>"
                    data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                    Reset Migration
                </button>
            </p>

            <div id="blitzcdn-zip-migration-status" style="margin-top: 15px; display: none;">
                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <h4 style="margin: 0 0 10px 0;">Migration Status</h4>

                    <!-- Cleaner progress bar -->
                    <div style="margin-bottom: 12px;">
                        <div
                            style="background: #f0f0f1; border: 1px solid #ccc; height: 18px; width: 100%; border-radius: 4px; overflow: hidden;">
                            <div id="blitzcdn-zip-progress-bar"
                                style="background: linear-gradient(90deg, #2271b1, #4ec9b0); height: 100%; width: 0%; transition: width 0.3s ease;">
                            </div>
                        </div>
                        <div
                            style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; font-weight:500;">
                            <div id="blitzcdn-zip-progress-text">-</div>
                            <div style="font-size:12px; color:#666;">Total assets: <span
                                    id="blitzcdn-zip-total-assets-inline">-</span></div>
                        </div>
                    </div>

                    <!-- Stat cards -->
                    <div style="display:flex; gap:12px; margin-bottom: 8px;">
                        <div style="flex:1; background:#e9f7ef; padding:10px; border-radius:4px; text-align:center;">
                            <div style="font-size:20px; font-weight:bold; color:#155724;" id="blitzcdn-zip-uploaded-count">-</div>
                            <div style="font-size:12px; color:#155724;">Uploaded</div>
                        </div>
                        <div style="flex:1; background:#fff3cd; padding:10px; border-radius:4px; text-align:center;">
                            <div style="font-size:20px; font-weight:bold; color:#856404;" id="blitzcdn-zip-processed-count">-</div>
                            <div style="font-size:12px; color:#856404;">Processed</div>
                        </div>
                        <div style="flex:1; background:#f8d7da; padding:10px; border-radius:4px; text-align:center;">
                            <div style="font-size:20px; font-weight:bold; color:#721c24;" id="blitzcdn-zip-failed-count">-</div>
                            <div style="font-size:12px; color:#721c24;">Failed</div>
                        </div>
                    </div>

                    <table class="widefat" style="max-width: 600px; margin-top:6px;">
                        <tr>
                            <td><strong>Status:</strong></td>
                            <td><span id="blitzcdn-zip-status-text">-</span></td>
                        </tr>
                        <tr>
                            <td><strong>Migration ID:</strong></td>
                            <td><code id="blitzcdn-zip-migration-id">-</code></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Hidden detailed log (only used for errors / debugging) -->
            <div id="blitzcdn-zip-migration-log"
                style="max-height: 300px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; border: 1px solid #333; padding: 15px; margin-top: 15px; font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; border-radius: 4px; display: none;">
            </div>

            <!-- Safe-to-quit modal -->
            <div id="blitzcdn-zip-safe-modal"
                style="display:none; position:fixed; left:0; right:0; top:0; bottom:0; background:rgba(0,0,0,0.5); z-index:10000;">
                <div
                    style="max-width:520px; margin:60px auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.3);">
                    <h3 style="margin-top:0;">All zip batches uploaded</h3>
                    <p>The middleware has received all zip batches and processing will continue in the background. You can safely
                        close this page.</p>
                    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:18px;">
                        <button type="button" id="blitzcdn-zip-modal-stay" class="button">Stay and Monitor</button>
                        <button type="button" id="blitzcdn-zip-modal-close" class="button button-primary">Close Page</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php
    }
}
