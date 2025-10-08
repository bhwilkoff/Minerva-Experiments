<?php
/**
 * Plugin Name: Multisite to Single Site Migrator (Merge Mode)
 * Plugin URI: https://example.com
 * Description: Export a multisite subsite and MERGE it into an existing single WordPress installation
 * Version: 2.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

class Multisite_To_Single_Migrator {
    
    private $plugin_slug = 'ms-to-single-migrator';
    private $export_dir;
    private $id_map = array(); // Track old ID to new ID mappings
    
    public function __construct() {
        $this->export_dir = WP_CONTENT_DIR . '/ms-migration-exports/';
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ms_export_subsite', array($this, 'ajax_export_subsite'));
        add_action('wp_ajax_ms_import_site', array($this, 'ajax_import_site'));
        
        // Increase execution time for large imports
        set_time_limit(600); // 10 minutes
    }
    
    public function add_admin_menu() {
        if (is_multisite()) {
            add_menu_page(
                'MS to Single Site',
                'MS Migrator',
                'manage_network',
                $this->plugin_slug,
                array($this, 'render_export_page'),
                'dashicons-admin-site',
                80
            );
        } else {
            add_menu_page(
                'Import from Multisite',
                'MS Importer',
                'manage_options',
                $this->plugin_slug,
                array($this, 'render_import_page'),
                'dashicons-download',
                80
            );
        }
    }
    
    public function enqueue_scripts($hook) {
        if (strpos($hook, $this->plugin_slug) === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
    }
    
    public function render_export_page() {
        if (!is_multisite()) {
            echo '<div class="wrap"><h1>This feature is only available on multisite installations</h1></div>';
            return;
        }
        
        $sites = get_sites(array('number' => 1000));
        
        ?>
        <div class="wrap">
            <h1>Export Multisite Subsite to Single Site</h1>
            
            <div class="notice notice-info">
                <p><strong>Export Mode:</strong> This will create an export package that can be MERGED into an existing single site without replacing existing content.</p>
            </div>
            
            <div class="card">
                <h2>Step 1: Select Subsite to Export</h2>
                <form id="export-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="site-select">Select Subsite:</label></th>
                            <td>
                                <select name="site_id" id="site-select" required style="min-width: 300px;">
                                    <option value="">-- Choose a site --</option>
                                    <?php foreach ($sites as $site): ?>
                                        <option value="<?php echo $site->blog_id; ?>">
                                            <?php echo get_blog_details($site->blog_id)->blogname; ?> 
                                            (<?php echo $site->domain . $site->path; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="export-btn">
                            Export Subsite
                        </button>
                    </p>
                </form>
            </div>
            
            <div id="export-status" style="display: none; margin-top: 20px;">
                <div class="card">
                    <h2>Export Progress</h2>
                    <div id="export-messages"></div>
                    <div id="export-result" style="margin-top: 20px;"></div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#export-form').on('submit', function(e) {
                e.preventDefault();
                
                var siteId = $('#site-select').val();
                
                if (!siteId) {
                    alert('Please select a subsite');
                    return;
                }
                
                $('#export-btn').prop('disabled', true).text('Exporting...');
                $('#export-status').show();
                $('#export-messages').html('<p>Starting export process...</p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ms_export_subsite',
                        site_id: siteId,
                        nonce: '<?php echo wp_create_nonce('ms_export_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#export-messages').append('<p style="color: green;">✓ Export completed successfully!</p>');
                            $('#export-result').html(
                                '<div class="notice notice-success"><p><strong>Export Package Created!</strong></p>' +
                                '<p>Download the following files and transfer them to your single site:</p>' +
                                '<ul style="list-style: disc; margin-left: 20px;">' +
                                '<li><a href="' + response.data.data_url + '" class="button" download>Download Export Data (JSON)</a></li>' +
                                '<li><a href="' + response.data.media_url + '" class="button" download>Download Media Files (ZIP)</a></li>' +
                                '</ul>' +
                                '<p><strong>Export Stats:</strong></p>' +
                                '<ul style="list-style: disc; margin-left: 20px;">' +
                                '<li>Posts: ' + response.data.stats.posts + '</li>' +
                                '<li>Pages: ' + response.data.stats.pages + '</li>' +
                                '<li>Comments: ' + response.data.stats.comments + '</li>' +
                                '<li>Users: ' + response.data.stats.users + '</li>' +
                                '<li>Media Files: ' + response.data.stats.media + '</li>' +
                                '</ul>' +
                                '<p><strong>Next Steps:</strong></p>' +
                                '<ol style="margin-left: 20px;">' +
                                '<li>Install this plugin on your target single WordPress site</li>' +
                                '<li>Upload these files to <code>wp-content/ms-migration-exports/</code> on the target site</li>' +
                                '<li>Go to the MS Importer page on the target site</li>' +
                                '<li>Select the import package and configure merge options</li>' +
                                '<li>Click "Merge Content" to import</li>' +
                                '</ol></div>'
                            );
                        } else {
                            $('#export-messages').append('<p style="color: red;">✗ Error: ' + response.data + '</p>');
                        }
                        $('#export-btn').prop('disabled', false).text('Export Subsite');
                    },
                    error: function() {
                        $('#export-messages').append('<p style="color: red;">✗ Ajax request failed</p>');
                        $('#export-btn').prop('disabled', false).text('Export Subsite');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function render_import_page() {
        if (is_multisite()) {
            echo '<div class="wrap"><h1>This feature is only available on single site installations</h1></div>';
            return;
        }
        
        $export_files = $this->get_available_exports();
        
        ?>
        <div class="wrap">
            <h1>Merge Content from Multisite</h1>
            
            <div class="notice notice-info">
                <p><strong>Merge Mode:</strong> This will ADD content from the multisite subsite to your existing site. Your current content will be preserved!</p>
            </div>
            
            <div class="notice notice-warning">
                <p><strong>Important:</strong> Always backup your database before importing! While this preserves existing content, it's still a major operation.</p>
            </div>
            
            <div class="card">
                <h2>Upload Export Files</h2>
                <p>Upload the export files to: <code><?php echo $this->export_dir; ?></code></p>
                <p>The files should be named: <code>export-TIMESTAMP.json</code> and <code>media-TIMESTAMP.zip</code></p>
            </div>
            
            <?php if (!empty($export_files)): ?>
            <div class="card">
                <h2>Available Import Packages</h2>
                <form id="import-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="import-select">Select Import Package:</label></th>
                            <td>
                                <select name="import_id" id="import-select" required style="min-width: 300px;">
                                    <option value="">-- Choose a package --</option>
                                    <?php foreach ($export_files as $timestamp => $files): ?>
                                        <option value="<?php echo $timestamp; ?>">
                                            Export from <?php echo date('Y-m-d H:i:s', $timestamp); ?>
                                            <?php echo isset($files['data']) ? '(Data ✓)' : '(Data ✗)'; ?>
                                            <?php echo isset($files['media']) ? '(Media ✓)' : '(Media ✗)'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="slug-conflict">Handle Slug Conflicts:</label></th>
                            <td>
                                <select name="slug_conflict" id="slug-conflict" required>
                                    <option value="rename">Rename imported content (add suffix)</option>
                                    <option value="skip">Skip items with conflicting slugs</option>
                                </select>
                                <p class="description">What to do if an imported post/page has the same slug as existing content</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="user-handling">User Handling:</label></th>
                            <td>
                                <select name="user_handling" id="user-handling" required>
                                    <option value="merge">Merge by email (recommended)</option>
                                    <option value="import_all">Import all as new users</option>
                                    <option value="assign_admin">Assign all content to current admin</option>
                                </select>
                                <p class="description">How to handle users from the imported site</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="new-url">New Site URL:</label></th>
                            <td>
                                <input type="url" name="new_url" id="new-url" 
                                       value="<?php echo get_site_url(); ?>" required style="min-width: 300px;">
                                <p class="description">URLs in content will be updated to this domain</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="import-status-opt">Import Status:</label></th>
                            <td>
                                <select name="import_status" id="import-status-opt">
                                    <option value="preserve">Preserve original status</option>
                                    <option value="draft">Import all as drafts</option>
                                    <option value="publish">Import all as published</option>
                                </select>
                                <p class="description">Publication status for imported posts/pages</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="import-btn">
                            Merge Content Into This Site
                        </button>
                    </p>
                </form>
            </div>
            
            <div id="import-status" style="display: none; margin-top: 20px;">
                <div class="card">
                    <h2>Import Progress</h2>
                    <div id="import-messages"></div>
                    <div id="import-result" style="margin-top: 20px;"></div>
                </div>
            </div>
            <?php else: ?>
            <div class="notice notice-info">
                <p>No export packages found. Please upload your export files first.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#import-form').on('submit', function(e) {
                e.preventDefault();
                
                if (!confirm('This will ADD content to your existing site. Have you backed up your database? Continue?')) {
                    return;
                }
                
                var importId = $('#import-select').val();
                var newUrl = $('#new-url').val();
                var slugConflict = $('#slug-conflict').val();
                var userHandling = $('#user-handling').val();
                var importStatus = $('#import-status-opt').val();
                
                if (!importId) {
                    alert('Please select an import package');
                    return;
                }
                
                $('#import-btn').prop('disabled', true).text('Merging content...');
                $('#import-status').show();
                $('#import-messages').html('<p>Starting merge process...</p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ms_import_site',
                        import_id: importId,
                        new_url: newUrl,
                        slug_conflict: slugConflict,
                        user_handling: userHandling,
                        import_status: importStatus,
                        nonce: '<?php echo wp_create_nonce('ms_import_nonce'); ?>'
                    },
                    timeout: 600000, // 10 minutes
                    success: function(response) {
                        if (response.success) {
                            $('#import-messages').append('<p style="color: green;">✓ Content merged successfully!</p>');
                            $('#import-result').html(
                                '<div class="notice notice-success"><p><strong>Migration Complete!</strong></p>' +
                                '<p><strong>Import Summary:</strong></p>' +
                                '<ul style="list-style: disc; margin-left: 20px;">' +
                                '<li>Posts imported: ' + response.data.posts_imported + '</li>' +
                                '<li>Pages imported: ' + response.data.pages_imported + '</li>' +
                                '<li>Comments imported: ' + response.data.comments_imported + '</li>' +
                                '<li>Users merged/created: ' + response.data.users_processed + '</li>' +
                                '<li>Media files imported: ' + response.data.media_imported + '</li>' +
                                '<li>Terms imported: ' + response.data.terms_imported + '</li>' +
                                '</ul>' +
                                '<p><strong>Next Steps:</strong></p>' +
                                '<ol style="margin-left: 20px;">' +
                                '<li>Go to Settings → Permalinks and click "Save Changes" to flush rewrite rules</li>' +
                                '<li>Review your Posts and Pages to verify the import</li>' +
                                '<li>Check that media files display correctly</li>' +
                                '<li>Update any plugin/theme settings as needed</li>' +
                                '</ol>' +
                                '<p><a href="' + newUrl + '/wp-admin/edit.php" class="button button-primary">View Posts</a> ' +
                                '<a href="' + newUrl + '/wp-admin/edit.php?post_type=page" class="button">View Pages</a></p>' +
                                '</div>'
                            );
                        } else {
                            $('#import-messages').append('<p style="color: red;">✗ Error: ' + response.data + '</p>');
                        }
                        $('#import-btn').prop('disabled', false).text('Merge Content Into This Site');
                    },
                    error: function(xhr, status, error) {
                        $('#import-messages').append('<p style="color: red;">✗ Ajax request failed: ' + error + '</p>');
                        $('#import-btn').prop('disabled', false).text('Merge Content Into This Site');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function ajax_export_subsite() {
        check_ajax_referer('ms_export_nonce', 'nonce');
        
        if (!current_user_can('manage_network')) {
            wp_send_json_error('Permission denied');
        }
        
        $site_id = intval($_POST['site_id']);
        
        if (!$site_id) {
            wp_send_json_error('Invalid site ID');
        }
        
        try {
            $result = $this->export_subsite($site_id);
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    private function export_subsite($site_id) {
        global $wpdb;
        
        // Switch to the site
        switch_to_blog($site_id);
        
        // Create export directory
        if (!file_exists($this->export_dir)) {
            wp_mkdir_p($this->export_dir);
        }
        
        $timestamp = time();
        $data_file = $this->export_dir . 'export-' . $timestamp . '.json';
        $media_file = $this->export_dir . 'media-' . $timestamp . '.zip';
        
        // Get site details
        $blog_details = get_blog_details($site_id);
        $old_url = untrailingslashit($blog_details->siteurl);
        
        $export_data = array(
            'meta' => array(
                'export_date' => current_time('mysql'),
                'site_id' => $site_id,
                'site_url' => $old_url,
                'site_name' => $blog_details->blogname,
                'wp_version' => get_bloginfo('version'),
            ),
            'posts' => array(),
            'comments' => array(),
            'terms' => array(),
            'users' => array(),
            'options' => array(),
        );
        
        // Export posts and pages
        $posts = get_posts(array(
            'post_type' => array('post', 'page', 'attachment'),
            'post_status' => 'any',
            'numberposts' => -1,
        ));
        
        foreach ($posts as $post) {
            $post_data = (array) $post;
            
            // Get post meta
            $post_data['meta'] = get_post_meta($post->ID);
            
            // Get terms
            $post_data['terms'] = array();
            $taxonomies = get_object_taxonomies($post->post_type);
            foreach ($taxonomies as $taxonomy) {
                $terms = wp_get_post_terms($post->ID, $taxonomy);
                if (!is_wp_error($terms)) {
                    $post_data['terms'][$taxonomy] = $terms;
                }
            }
            
            $export_data['posts'][] = $post_data;
        }
        
        // Export comments
        $comments = get_comments(array('status' => 'all', 'number' => 0));
        foreach ($comments as $comment) {
            $comment_data = (array) $comment;
            $comment_data['meta'] = get_comment_meta($comment->comment_ID);
            $export_data['comments'][] = $comment_data;
        }
        
        // Export terms
        $taxonomies = get_taxonomies(array(), 'objects');
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(array(
                'taxonomy' => $taxonomy->name,
                'hide_empty' => false,
            ));
            
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $term_data = (array) $term;
                    $term_data['meta'] = get_term_meta($term->term_id);
                    $export_data['terms'][] = $term_data;
                }
            }
        }
        
        // Export users
        $users = get_users(array('blog_id' => $site_id));
        foreach ($users as $user) {
            $user_data = array(
                'ID' => $user->ID,
                'user_login' => $user->user_login,
                'user_email' => $user->user_email,
                'user_nicename' => $user->user_nicename,
                'display_name' => $user->display_name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'user_registered' => $user->user_registered,
                'role' => $user->roles,
                'meta' => array_map(function($a) { return $a[0]; }, get_user_meta($user->ID)),
            );
            $export_data['users'][] = $user_data;
        }
        
        // Export key options
        $key_options = array(
            'blogname', 'blogdescription', 'siteurl', 'home',
            'template', 'stylesheet', 'posts_per_page', 'date_format',
            'time_format', 'timezone_string', 'permalink_structure',
        );
        
        foreach ($key_options as $option) {
            $value = get_option($option);
            if ($value !== false) {
                $export_data['options'][$option] = $value;
            }
        }
        
        // Calculate stats
        $stats = array(
            'posts' => 0,
            'pages' => 0,
            'comments' => count($export_data['comments']),
            'users' => count($export_data['users']),
            'media' => 0,
        );
        
        foreach ($export_data['posts'] as $post) {
            if ($post['post_type'] === 'post') $stats['posts']++;
            if ($post['post_type'] === 'page') $stats['pages']++;
            if ($post['post_type'] === 'attachment') $stats['media']++;
        }
        
        // Save export data
        file_put_contents($data_file, json_encode($export_data, JSON_PRETTY_PRINT));
        
        // Export media files
        $this->export_media_files($site_id, $media_file);
        
        restore_current_blog();
        
        return array(
            'data_url' => content_url('ms-migration-exports/export-' . $timestamp . '.json'),
            'media_url' => content_url('ms-migration-exports/media-' . $timestamp . '.zip'),
            'timestamp' => $timestamp,
            'stats' => $stats,
        );
    }
    
    private function export_media_files($site_id, $zip_file) {
        // Get upload directory for this site
        if ($site_id > 1) {
            $media_path = WP_CONTENT_DIR . '/uploads/sites/' . $site_id . '/';
        } else {
            $media_path = WP_CONTENT_DIR . '/uploads/';
        }
        
        if (!file_exists($media_path)) {
            return;
        }
        
        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Could not create zip file');
        }
        
        $this->add_directory_to_zip($zip, $media_path, '');
        $zip->close();
    }
    
    private function add_directory_to_zip($zip, $path, $relative_path) {
        if (!is_dir($path)) {
            return;
        }
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_file_path = $relative_path . substr($file_path, strlen($path));
                $zip->addFile($file_path, $relative_file_path);
            }
        }
    }
    
    private function get_available_exports() {
        if (!file_exists($this->export_dir)) {
            return array();
        }
        
        $files = scandir($this->export_dir);
        $exports = array();
        
        foreach ($files as $file) {
            if (preg_match('/^export-(\d+)\.json$/', $file, $matches)) {
                $timestamp = $matches[1];
                $exports[$timestamp]['data'] = $file;
            } elseif (preg_match('/^media-(\d+)\.zip$/', $file, $matches)) {
                $timestamp = $matches[1];
                $exports[$timestamp]['media'] = $file;
            }
        }
        
        krsort($exports);
        return $exports;
    }
    
    public function ajax_import_site() {
        check_ajax_referer('ms_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $import_id = sanitize_text_field($_POST['import_id']);
        $new_url = esc_url_raw($_POST['new_url']);
        $slug_conflict = sanitize_text_field($_POST['slug_conflict']);
        $user_handling = sanitize_text_field($_POST['user_handling']);
        $import_status = sanitize_text_field($_POST['import_status']);
        
        if (!$import_id || !$new_url) {
            wp_send_json_error('Missing required parameters');
        }
        
        try {
            $result = $this->import_site($import_id, $new_url, $slug_conflict, $user_handling, $import_status);
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    private function import_site($import_id, $new_url, $slug_conflict, $user_handling, $import_status) {
        global $wpdb;
        
        $data_file = $this->export_dir . 'export-' . $import_id . '.json';
        $media_file = $this->export_dir . 'media-' . $import_id . '.zip';
        
        if (!file_exists($data_file)) {
            throw new Exception('Export data file not found');
        }
        
        // Load export data
        $export_data = json_decode(file_get_contents($data_file), true);
        if (!$export_data) {
            throw new Exception('Failed to parse export data');
        }
        
        $old_url = untrailingslashit($export_data['meta']['site_url']);
        $new_url = untrailingslashit($new_url);
        
        $stats = array(
            'posts_imported' => 0,
            'pages_imported' => 0,
            'comments_imported' => 0,
            'users_processed' => 0,
            'media_imported' => 0,
            'terms_imported' => 0,
        );
        
        // Reset ID mapping
        $this->id_map = array(
            'posts' => array(),
            'comments' => array(),
            'terms' => array(),
            'users' => array(),
        );
        
        // Import users first
        foreach ($export_data['users'] as $user_data) {
            $new_user_id = $this->import_user($user_data, $user_handling);
            if ($new_user_id) {
                $this->id_map['users'][$user_data['ID']] = $new_user_id;
                $stats['users_processed']++;
            }
        }
        
        // Import terms
        foreach ($export_data['terms'] as $term_data) {
            $new_term_id = $this->import_term($term_data);
            if ($new_term_id) {
                $this->id_map['terms'][$term_data['term_id']] = $new_term_id;
                $stats['terms_imported']++;
            }
        }
        
        // Import posts and pages
        foreach ($export_data['posts'] as $post_data) {
            $new_post_id = $this->import_post($post_data, $slug_conflict, $import_status, $old_url, $new_url);
            if ($new_post_id) {
                $this->id_map['posts'][$post_data['ID']] = $new_post_id;
                
                if ($post_data['post_type'] === 'post') $stats['posts_imported']++;
                if ($post_data['post_type'] === 'page') $stats['pages_imported']++;
                if ($post_data['post_type'] === 'attachment') $stats['media_imported']++;
            }
        }
        
        // Import comments
        foreach ($export_data['comments'] as $comment_data) {
            $new_comment_id = $this->import_comment($comment_data);
            if ($new_comment_id) {
                $this->id_map['comments'][$comment_data['comment_ID']] = $new_comment_id;
                $stats['comments_imported']++;
            }
        }
        
        // Import media files
        if (file_exists($media_file)) {
            $this->import_media_files($media_file);
        }
        
        // Update relationships
        $this->update_relationships($old_url, $new_url);
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        return $stats;
    }
    
    private function import_user($user_data, $user_handling) {
        // Check if user already exists
        $existing_user = get_user_by('email', $user_data['user_email']);
        
        if ($user_handling === 'merge' && $existing_user) {
            return $existing_user->ID;
        }
        
        if ($user_handling === 'assign_admin') {
            return get_current_user_id();
        }
        
        // Import as new user (user_handling === 'import_all' or no existing user)
        if ($existing_user) {
            // User exists but we want to import as new - modify username
            $user_data['user_login'] = $user_data['user_login'] . '_imported';
            $user_data['user_email'] = 'imported_' . $user_data['user_email'];
        }
        
        $user_id = wp_insert_user(array(
            'user_login' => $user_data['user_login'],
            'user_email' => $user_data['user_email'],
            'user_nicename' => $user_data['user_nicename'],
            'display_name' => $user_data['display_name'],
            'first_name' => $user_data['first_name'],
            'last_name' => $user_data['last_name'],
            'user_registered' => $user_data['user_registered'],
            'role' => !empty($user_data['role']) ? $user_data['role'][0] : 'subscriber',
        ));
        
        if (is_wp_error($user_id)) {
            return false;
        }
        
        // Import user meta
        if (!empty($user_data['meta'])) {
            foreach ($user_data['meta'] as $key => $value) {
                if (strpos($key, 'wp_') !== 0 || strpos($key, $GLOBALS['wpdb']->prefix) === 0) {
                    update_user_meta($user_id, $key, $value);
                }
            }
        }
        
        return $user_id;
    }
    
    private function import_term($term_data) {
        // Check if term already exists
        $existing_term = term_exists($term_data['slug'], $term_data['taxonomy']);
        
        if ($existing_term) {
            return $existing_term['term_id'];
        }
        
        $args = array(
            'description' => $term_data['description'],
            'slug' => $term_data['slug'],
        );
        
        if ($term_data['parent'] > 0 && isset($this->id_map['terms'][$term_data['parent']])) {
            $args['parent'] = $this->id_map['terms'][$term_data['parent']];
        }
        
        $result = wp_insert_term($term_data['name'], $term_data['taxonomy'], $args);
        
        if (is_wp_error($result)) {
            return false;
        }
        
        $new_term_id = $result['term_id'];
        
        // Import term meta
        if (!empty($term_data['meta'])) {
            foreach ($term_data['meta'] as $key => $values) {
                foreach ($values as $value) {
                    add_term_meta($new_term_id, $key, maybe_unserialize($value));
                }
            }
        }
        
        return $new_term_id;
    }
    
    private function import_post($post_data, $slug_conflict, $import_status, $old_url, $new_url) {
        // Check for slug conflict
        if ($slug_conflict === 'skip') {
            $existing = get_page_by_path($post_data['post_name'], OBJECT, $post_data['post_type']);
            if ($existing) {
                return false;
            }
        } elseif ($slug_conflict === 'rename') {
            $existing = get_page_by_path($post_data['post_name'], OBJECT, $post_data['post_type']);
            if ($existing) {
                $post_data['post_name'] = $post_data['post_name'] . '-imported-' . time();
            }
        }
        
        // Map author to new user ID
        $author_id = get_current_user_id();
        if (isset($this->id_map['users'][$post_data['post_author']])) {
            $author_id = $this->id_map['users'][$post_data['post_author']];
        }
        
        // Map parent post if applicable
        $parent_id = 0;
        if ($post_data['post_parent'] > 0 && isset($this->id_map['posts'][$post_data['post_parent']])) {
            $parent_id = $this->id_map['posts'][$post_data['post_parent']];
        }
        
        // Update URLs in content
        $content = str_replace($old_url, $new_url, $post_data['post_content']);
        $excerpt = str_replace($old_url, $new_url, $post_data['post_excerpt']);
        
        // Determine post status
        $post_status = $post_data['post_status'];
        if ($import_status === 'draft') {
            $post_status = 'draft';
        } elseif ($import_status === 'publish') {
            $post_status = 'publish';
        }
        
        $new_post = array(
            'post_title' => $post_data['post_title'],
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status' => $post_status,
            'post_type' => $post_data['post_type'],
            'post_name' => $post_data['post_name'],
            'post_author' => $author_id,
            'post_parent' => $parent_id,
            'post_date' => $post_data['post_date'],
            'post_date_gmt' => $post_data['post_date_gmt'],
            'comment_status' => $post_data['comment_status'],
            'ping_status' => $post_data['ping_status'],
            'menu_order' => $post_data['menu_order'],
            'post_mime_type' => $post_data['post_mime_type'],
        );
        
        $new_post_id = wp_insert_post($new_post);
        
        if (is_wp_error($new_post_id)) {
            return false;
        }
        
        // Import post meta
        if (!empty($post_data['meta'])) {
            foreach ($post_data['meta'] as $key => $values) {
                foreach ($values as $value) {
                    $value = maybe_unserialize($value);
                    
                    // Update URLs in serialized data
                    if (is_string($value)) {
                        $value = str_replace($old_url, $new_url, $value);
                    }
                    
                    // Update attachment URLs for _wp_attached_file
                    if ($key === '_wp_attached_file' && $post_data['post_type'] === 'attachment') {
                        $value = basename($value);
                    }
                    
                    add_post_meta($new_post_id, $key, $value);
                }
            }
        }
        
        // Set terms
        if (!empty($post_data['terms'])) {
            foreach ($post_data['terms'] as $taxonomy => $terms) {
                $term_ids = array();
                foreach ($terms as $term) {
                    if (isset($this->id_map['terms'][$term->term_id])) {
                        $term_ids[] = $this->id_map['terms'][$term->term_id];
                    }
                }
                if (!empty($term_ids)) {
                    wp_set_object_terms($new_post_id, $term_ids, $taxonomy);
                }
            }
        }
        
        return $new_post_id;
    }
    
    private function import_comment($comment_data) {
        // Map to new post ID
        if (!isset($this->id_map['posts'][$comment_data['comment_post_ID']])) {
            return false;
        }
        
        $new_post_id = $this->id_map['posts'][$comment_data['comment_post_ID']];
        
        // Map parent comment if applicable
        $parent_id = 0;
        if ($comment_data['comment_parent'] > 0 && isset($this->id_map['comments'][$comment_data['comment_parent']])) {
            $parent_id = $this->id_map['comments'][$comment_data['comment_parent']];
        }
        
        // Map user if applicable
        $user_id = 0;
        if ($comment_data['user_id'] > 0 && isset($this->id_map['users'][$comment_data['user_id']])) {
            $user_id = $this->id_map['users'][$comment_data['user_id']];
        }
        
        $new_comment = array(
            'comment_post_ID' => $new_post_id,
            'comment_author' => $comment_data['comment_author'],
            'comment_author_email' => $comment_data['comment_author_email'],
            'comment_author_url' => $comment_data['comment_author_url'],
            'comment_author_IP' => $comment_data['comment_author_IP'],
            'comment_date' => $comment_data['comment_date'],
            'comment_date_gmt' => $comment_data['comment_date_gmt'],
            'comment_content' => $comment_data['comment_content'],
            'comment_karma' => $comment_data['comment_karma'],
            'comment_approved' => $comment_data['comment_approved'],
            'comment_agent' => $comment_data['comment_agent'],
            'comment_type' => $comment_data['comment_type'],
            'comment_parent' => $parent_id,
            'user_id' => $user_id,
        );
        
        $new_comment_id = wp_insert_comment($new_comment);
        
        if (!$new_comment_id) {
            return false;
        }
        
        // Import comment meta
        if (!empty($comment_data['meta'])) {
            foreach ($comment_data['meta'] as $key => $values) {
                foreach ($values as $value) {
                    add_comment_meta($new_comment_id, $key, maybe_unserialize($value));
                }
            }
        }
        
        return $new_comment_id;
    }
    
    private function import_media_files($zip_file) {
        $zip = new ZipArchive();
        if ($zip->open($zip_file) !== true) {
            throw new Exception('Could not open media zip file');
        }
        
        $upload_dir = wp_upload_dir();
        $extract_path = $upload_dir['basedir'] . '/';
        
        $zip->extractTo($extract_path);
        $zip->close();
    }
    
    private function update_relationships($old_url, $new_url) {
        global $wpdb;
        
        // Update any remaining URLs in post content that reference imported posts
        foreach ($this->id_map['posts'] as $old_id => $new_id) {
            // Update internal links
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} 
                SET post_content = REPLACE(post_content, %s, %s)",
                '?p=' . $old_id,
                '?p=' . $new_id
            ));
        }
        
        // Update attachment URLs in post meta
        foreach ($this->id_map['posts'] as $old_id => $new_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} 
                SET meta_value = REPLACE(meta_value, %s, %s)
                WHERE meta_value LIKE %s",
                '"' . $old_id . '"',
                '"' . $new_id . '"',
                '%"' . $old_id . '"%'
            ));
        }
    }
}

// Initialize plugin
new Multisite_To_Single_Migrator();
