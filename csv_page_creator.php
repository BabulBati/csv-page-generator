<?php
/**
 * Plugin Name: CSV Page Generator with Dynamic Placeholders & Sync
 * Description: Generate, delete, and update pages from a WPBakery template using CSV files with meta title and description.
 * Version: 1.9.2
 */

// Block direct access
if (!defined('ABSPATH')) exit;

// === Check for WPBakery ===
add_action('admin_init', function () {
    if (!class_exists('Vc_Manager')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>CSV Page Generator</strong> requires WPBakery Page Builder.</p></div>';
        });
    }
});

// === CSV Parsing ===
function parse_csv($csv_file) {
    $rows = [];

    if (($handle = fopen($csv_file, "r")) !== FALSE) {
        $headers = fgetcsv($handle, 0, ",");
        if (!$headers) return new WP_Error('invalid_csv', 'Error reading CSV headers.');

        $headers = array_map('trim', $headers);

        while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
            if (count($headers) === count($data)) {
                $data = array_map(function ($value) {
                    $value = trim($value);
                    if (!mb_detect_encoding($value, 'UTF-8', true)) {
                        $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
                    }
                    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
                    $value = str_replace(["‘", "’", "“", "”", "–", "—", "…", "\xC2\xA0"], ["'", "'", '"', '"', "-", "-", "...", " "], $value);
                    return $value;
                }, $data);

                $rows[] = array_combine($headers, $data);
            }
        }
        fclose($handle);
    }

    return $rows;
}

// === Placeholder Replacement ===
function replace_placeholders($content, $data) {
    foreach ($data as $key => $value) {
        $content = str_replace('{{' . $key . '}}', wp_kses_post($value), $content);
    }
    return $content;
}

// === Page Generation ===
function generate_pages_from_template($template_page_id, $csv_file, $post_status = 'publish', $csv_filename = '', $parent_page_id = 0) {
    $template_page = get_post($template_page_id);
    if (!$template_page) return new WP_Error('invalid_template', 'Template not found.');

    $template_content = $template_page->post_content;
    $csv_data = parse_csv($csv_file);

    foreach ($csv_data as $row) {
        if (!isset($row['title'])) continue;

        $row_id = md5(json_encode($row));
        $page_id = wp_insert_post([
            'post_title'   => sanitize_text_field($row['title']),
            'post_content' => replace_placeholders($template_content, $row),
            'post_status'  => $post_status,
            'post_type'    => 'page',
            'post_parent'  => intval($parent_page_id),
        ]);

        if ($page_id) {
            add_post_meta($page_id, '_generated_by_csv_page_generator', true);
            add_post_meta($page_id, '_csv_source_filename', sanitize_file_name($csv_filename));
            add_post_meta($page_id, '_csv_row_id', $row_id);
            // Save meta title and description as post meta
            if (isset($row['meta_title'])) {
                update_post_meta($page_id, '_csv_meta_title', sanitize_text_field($row['meta_title'])); // Changed to 'meta_title' from 'sector_title'
            }
            if (isset($row['meta_description'])) {
                update_post_meta($page_id, '_csv_meta_description', sanitize_text_field($row['meta_description']));
            }
        }
    }

    update_option('csv_page_generator_last_template', $template_page_id);
}

// === Page Updater ===
function update_pages_from_csv($template_page_id, $csv_file, $csv_filename = '', $parent_page_id = 0) {
    $template_page = get_post($template_page_id);
    if (!$template_page) return new WP_Error('invalid_template', 'Template page not found');

    $template_content = $template_page->post_content;
    $csv_data = parse_csv($csv_file);

    if (is_wp_error($csv_data)) return $csv_data;

    foreach ($csv_data as $row) {
        if (!isset($row['title'])) continue;


        // Get all pages generated from the same CSV file
        $existing_pages = get_posts([
            'post_type'      => 'page',
            'posts_per_page' => 1,
            'title'          => sanitize_text_field($row['title']),
            'meta_key'       => '_generated_by_csv_page_generator',
        ]);

        // Match by title
        foreach ($existing_pages as $page) {
            if ($page->post_title === sanitize_text_field($row['title'])) {
                $page_id = $page->ID;
                break;
            }
        }

        if (!empty($existing_pages)) {
            $page_id = $existing_pages[0]->ID;

            $updated_post = [
                'ID'           => $page_id,
                'post_title'   => sanitize_text_field($row['title']),
                'post_content' => replace_placeholders($template_content, $row),
                'post_parent'  => intval($parent_page_id),
            ];

            $result = wp_update_post($updated_post, true);

            if (is_wp_error($result)) {
                error_log('CSV Page Generator Update Error: ' . $result->get_error_message());
            } else {
                update_post_meta($page_id, '_csv_source_filename', sanitize_file_name($csv_filename));
                update_post_meta($page_id, '_csv_row_id', md5(json_encode($row)));
                // Update meta title and description
                if (isset($row['meta_title'])) {
                    update_post_meta($page_id, '_csv_meta_title', sanitize_text_field($row['meta_title']));
                }
                if (isset($row['meta_description'])) {
                    update_post_meta($page_id, '_csv_meta_description', sanitize_text_field($row['meta_description']));
                }
            }
        }
    }
}

// === Inject Meta Tags into <head> ===
add_action('wp_head', function () {
    if (is_page()) {
        $page_id = get_the_ID();
        
        // Try Rank Math meta first
        $meta_title = get_post_meta($page_id, 'rank_math_title', true);
        $meta_description = get_post_meta($page_id, 'rank_math_description', true);

        // Fallback to CSV values if Rank Math ones are not set
        if (empty($meta_title)) {
            $meta_title = get_post_meta($page_id, '_csv_meta_title', true);
        }
        if (empty($meta_description)) {
            $meta_description = get_post_meta($page_id, '_csv_meta_description', true);
        }

        // Output
        if (!empty($meta_title)) {
            echo '<title>' . esc_html($meta_title) . '</title>' . PHP_EOL;
        }
        if (!empty($meta_description)) {
            echo '<meta name="description" content="' . esc_attr($meta_description) . '">' . PHP_EOL;
        }
    }
}, 1); // Priority 1 to override default title if needed

// === Delete All Pages ===
add_action('admin_post_delete_generated_pages', function () {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $pages = get_posts([
        'post_type' => 'page',
        'posts_per_page' => -1,
        'meta_key' => '_generated_by_csv_page_generator'
    ]);

    foreach ($pages as $page) {
        wp_delete_post($page->ID, true);
    }

    wp_redirect(admin_url('admin.php?page=csv-page-generator&deleted=1'));
    exit;
});

// === Delete by CSV ===
add_action('admin_post_delete_pages_by_csv', function () {
    check_admin_referer('csv_delete_pages_action');

    $filename = sanitize_file_name($_POST['csv_filename'] ?? '');
    $pages = get_posts([
        'post_type'     => 'page',
        'posts_per_page'=> -1,
        'meta_query'    => [
            ['key' => '_csv_source_filename', 'value' => $filename]
        ]
    ]);

    foreach ($pages as $page) {
        wp_delete_post($page->ID, true);
    }

    wp_redirect(admin_url('admin.php?page=csv-page-generator&deleted_batch=1&csv=' . urlencode($filename)));
    exit;
});

// === Admin Menu ===
add_action('admin_menu', function () {
    add_menu_page(
        'CSV Page Generator',
        'CSV Page Generator',
        'manage_options',
        'csv-page-generator',
        'csv_page_generator_page',
        'dashicons-upload',
        30
    );
});

// === Admin Page UI ===
function csv_page_generator_page() {
    $last_template_id = get_option('csv_page_generator_last_template', '');
    ?>
    <div class="wrap">
        <h1>CSV Page Generator</h1>

        <?php
        if (isset($_GET['deleted'])) {
            echo '<div class="updated"><p>All generated pages deleted.</p></div>';
        }
        if (isset($_GET['deleted_batch']) && $_GET['csv']) {
            echo '<div class="updated"><p>Pages from <strong>' . esc_html($_GET['csv']) . '</strong> deleted.</p></div>';
        }

        // === Generate Pages Handler
        if (isset($_POST['submit'])) {
            if (!empty($_FILES['csv_file']['tmp_name']) && !empty($_POST['template_page_id'])) {
                $csv_file = $_FILES['csv_file']['tmp_name'];
                $template_page_id = intval($_POST['template_page_id']);
                $csv_filename = basename($_FILES['csv_file']['name']);
                $parent_page_id = isset($_POST['parent_page_id']) ? intval($_POST['parent_page_id']) : 0;

                $result = generate_pages_from_template($template_page_id, $csv_file, 'publish', $csv_filename, $parent_page_id);

                echo is_wp_error($result)
                    ? '<div class="error"><p>' . $result->get_error_message() . '</p></div>'
                    : '<div class="updated"><p>Pages generated from <strong>' . esc_html($csv_filename) . '</strong>.</p></div>';
            }
        }

        // === Update Pages Handler
        if (isset($_POST['update_pages_submit'])) {
            if (!empty($_FILES['update_csv_file']['tmp_name']) && !empty($_POST['update_template_page_id'])) {
                $csv_file = $_FILES['update_csv_file']['tmp_name'];
                $template_page_id = intval($_POST['update_template_page_id']);
                $csv_filename = basename($_FILES['update_csv_file']['name']);
                $parent_page_id = isset($_POST['update_parent_page_id']) ? intval($_POST['update_parent_page_id']) : 0;

                $result = update_pages_from_csv($template_page_id, $csv_file, $csv_filename, $parent_page_id);

                echo is_wp_error($result)
                    ? '<div class="error"><p>' . $result->get_error_message() . '</p></div>'
                    : '<div class="updated"><p>Pages updated from <strong>' . esc_html($csv_filename) . '</strong>.</p></div>';
            }
        }
        ?>

        <!-- Generate Pages Form -->
         <div class="card">
        <h2>Generate Pages from CSV</h2>
        <form method="POST" enctype="multipart/form-data">
            <table class="form-table">
                <tr>
                    <th><label for="csv_file">CSV File</label></th>
                    <td><input type="file" name="csv_file" required></td>
                </tr>
                <tr>
                    <th><label for="template_page_id">Template Page</label></th>
                    <td>
                        <select name="template_page_id" required>
                            <?php foreach (get_pages() as $page): ?>
                                <option value="<?php echo $page->ID; ?>" <?php selected($last_template_id, $page->ID); ?>>
                                    <?php echo esc_html($page->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($last_template_id): ?>
                            <p><strong>Last Used:</strong> <?php echo get_the_title($last_template_id); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="parent_page_id">Parent Page</label></th>
                    <td>
                        <select name="parent_page_id">
                            <option value="0">No Parent (Top Level)</option>
                            <?php foreach (get_pages() as $page): ?>
                                <option value="<?php echo $page->ID; ?>">
                                    <?php echo esc_html($page->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="submit" class="button-primary" value="Generate Pages"></p>
        </form>
                            </div>

                            <div class="card">
        <!-- Update Pages Form -->
        <h2>Update Existing Pages from CSV</h2>
        <form method="POST" enctype="multipart/form-data">
            <table class="form-table">
                <tr>
                    <th><label for="update_csv_file">CSV File</label></th>
                    <td><input type="file" name="update_csv_file" required></td>
                </tr>
                <tr>
                    <th><label for="update_template_page_id">Template Page</label></th>
                    <td>
                        <select name="update_template_page_id" required>
                            <?php foreach (get_pages() as $page): ?>
                                <option value="<?php echo $page->ID; ?>"><?php echo esc_html($page->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="update_parent_page_id">Parent Page</label></th>
                    <td>
                        <select name="update_parent_page_id">
                            <option value="0">No Parent (Top Level)</option>
                            <?php foreach (get_pages() as $page): ?>
                                <option value="<?php echo $page->ID; ?>">
                                    <?php echo esc_html($page->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="update_pages_submit" class="button-primary" value="Update Pages from CSV"></p>
        </form>
                            </div>

                            <div class="card">
        <!-- Delete All -->
        <h2>Delete All Generated Pages</h2>
        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="delete_generated_pages">
            <p><input type="submit" class="button-secondary" value="Delete All Pages"
                onclick="return confirm('Delete ALL pages generated by this plugin?');"></p>
        </form>

                            </div>
                            <div class="card">
        <!-- Delete Pages by CSV -->
<h2>Delete Pages by CSV File</h2>
<form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
    <?php wp_nonce_field('csv_delete_pages_action'); ?>
    <input type="hidden" name="action" value="delete_pages_by_csv">
    <p>
        <select name="csv_filename" required>
            <option value="" disabled selected>Select a CSV source file...</option>
            <?php
            $args = [
                'post_type'      => 'page',
                'posts_per_page' => -1,
                'meta_key'       => '_csv_source_filename',
                'fields'         => 'ids',
            ];
            $pages = get_posts($args);
            $filenames = [];
            
            // Collect unique filenames from the pages' metadata
            foreach ($pages as $page_id) {
                $filename = get_post_meta($page_id, '_csv_source_filename', true);
                if ($filename && !in_array($filename, $filenames)) {
                    $filenames[] = $filename;
                }
            }

            // Display the filenames as options in the dropdown
            foreach ($filenames as $filename) {
                echo '<option value="' . esc_attr($filename) . '">' . esc_html($filename) . '</option>';
            }
            ?>
        </select>
        <input type="submit" class="button-secondary" value="Delete Pages from Selected CSV"
               onclick="return confirm('Are you sure you want to delete these pages?');">
    </p>
</form>
        </div>

    </div>
    <?php
}