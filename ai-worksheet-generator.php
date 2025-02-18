<?php
/**
 * Plugin Name: AI Worksheet Generator
 * Plugin URI:  https://github.com/lewis-2000/ai-worksheet-generator
 * Description: Generate AI-powered worksheets.
 * Version:     1.0.1
 * Author:      Lewis Ng'ang'a
 * License:     GPL2
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

function get_user_pdfs($user_id)
{
    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'application/pdf',
        'posts_per_page' => -1,
        'author' => $user_id,
    );

    $pdfs = get_posts($args);
    $user_pdfs = array();

    foreach ($pdfs as $pdf) {
        $user_pdfs[] = array(
            'title' => $pdf->post_title,
            'url' => wp_get_attachment_url($pdf->ID),
        );
    }

    return $user_pdfs;
}

require_once ABSPATH . 'wp-admin/includes/file.php'; // Needed for file handling
require_once ABSPATH . 'wp-admin/includes/media.php'; // Needed for uploading files
require_once ABSPATH . 'wp-admin/includes/image.php'; // Needed for attachment metadata

// Include dompdf for PDF generation
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';


use Dompdf\Dompdf;
use Dompdf\Options;

class AI_Worksheet_Generator
{

    private $generated_html;
    private $temp_user_id;
    private $user_manager;

    private static $instance = null;

    public $database;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }



    public function enqueue_assets()
    {
        // ✅ Tailwind CSS
        wp_enqueue_script('tailwind-config', 'https://cdn.tailwindcss.com', array(), null, false);
        wp_add_inline_script('tailwind-config', 'tailwind.config = { theme: { extend: {} } }');

        // ✅ Font Awesome
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', array(), null, false);

        // ✅ PDF.js (Core Library Only)
        wp_enqueue_script('pdfjs-lib', 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js', array(), null, true);

        // ✅ Plugin styles
        wp_enqueue_style('awg-styles', plugin_dir_url(__FILE__) . 'css/styles.css');

        // ✅ Localize AJAX
        wp_localize_script('awg-scripts', 'awg_ajax', [
            'ajax_url' => admin_url('admin-ajax.php')
        ]);

        // ✅ Plugin Scripts
        wp_enqueue_script('awg-scripts', plugin_dir_url(__FILE__) . 'js/awg-scripts.js', array('jquery', 'pdfjs-lib'), null, true);
    }




    public function __construct()
    {
        //Test db
        // require_once plugin_dir_path(__FILE__) . 'includes/class-awg-database.php';
        // require_once plugin_dir_path(__FILE__) . 'includes/class-awg-user-manager.php';

        // $this->user_manager = new AWG_User_Manager();


        add_action('init', [$this, 'awg_test_user_operations']);


        // Enqueue Scripts & Styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']); // If using front-end

        // Admin Menu & Settings
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'admin_init_actions']); // Combined multiple `admin_init` calls

        // AJAX Actions (Authenticated Users)
        add_action('wp_ajax_generate_ai_response', [$this, 'generate_ai_response']);
        add_action('wp_ajax_fetch_latest_pdf', [$this, 'fetch_latest_pdf']);
        add_action('wp_ajax_fetch_generated_html', [$this, 'fetch_generated_html']);
        add_action('wp_ajax_convert_to_pdf', [$this, 'convert_to_pdf']);
        add_action('wp_ajax_save_custom_template', [$this, 'save_custom_template']);

        // AJAX Actions (Non-Authenticated Users)
        add_action('wp_ajax_nopriv_generate_ai_response', [$this, 'generate_ai_response']);
        add_action('wp_ajax_nopriv_fetch_generated_html', [$this, 'fetch_generated_html']);
        add_action('wp_ajax_nopriv_convert_to_pdf', [$this, 'convert_to_pdf']); // Fixed wrong function name

        // Cart & Checkout AJAX
        add_action('wp_ajax_awg_add_to_cart', [$this, 'awg_add_to_cart']);
        add_action('wp_ajax_nopriv_awg_add_to_cart', [$this, 'awg_add_to_cart']);
        add_action('wp_ajax_awg_remove_from_cart', [$this, 'awg_remove_from_cart']);
        add_action('wp_ajax_nopriv_awg_remove_from_cart', [$this, 'awg_remove_from_cart']);
        add_action('wp_ajax_awg_checkout', [$this, 'awg_checkout']);





        //Transfer Correct pdf url
        // Shortcodes
        add_shortcode('awg_generate_html', [$this, 'display_html_response']);
        add_shortcode('awg_view_pdf', [$this, 'view_pdf']);

    }

    // public function awg_test_user_operations()
    // {
    //     $user_id = 1; // Replace with an actual user ID
    //     $action = 'Test Action';

    //     // Test tracking an action
    //     $this->user_manager->track_action($user_id, $action, true, false, 'subscribed', '2025-12-31');

    //     // Test fetching user data
    //     $user_data = $this->user_manager->get_user_data($user_id);

    //     // Output for testing (this should be removed in production)
    //     error_log(print_r($user_data, true));
    // }



    public function admin_init_actions()
    {
        $this->register_settings();
        $this->handle_template_upload();
    }

    public function save_custom_template()
    {
        if (isset($_POST['html'])) {
            $customHtml = stripslashes($_POST['html']);
            $filePath = WP_CONTENT_DIR . '/uploads/custom_template.html';
            file_put_contents($filePath, $customHtml);
            echo "Template saved!";
        }
        wp_die();
    }


    public function add_admin_menu()
    {
        add_menu_page(
            'AI Worksheet Generator',
            'Worksheet AI',
            'manage_options',
            'ai-worksheet-generator',
            array($this, 'admin_page'),
            'dashicons-smiley'
        );
    }

    public function admin_page()
    {
        ?>
        <div class="wrap">
            <h2>AI Worksheet Generator</h2>

            <!-- Tab Navigation -->
            <ul class="nav-tab-wrapper">
                <li><a href="#" class="nav-tab  nav-tab-active" data-tab="settings">Settings</a></li>
                <li><a href="#" class="nav-tab" data-tab="content">Content</a></li>
                <li><a href="#" class="nav-tab" data-tab="statistics">Statistics</a></li>
                <li><a href="#" class="nav-tab" data-tab="docs">Documentation</a></li>

            </ul>

            <!-- Tab Content -->
            <div class="tab-content">
                <!-- Settings Tab -->
                <div class="p-6 mb-4 bg-white shadow-lg rounded-lg">
                    <p class="text-lg text-gray-700 mb-2">
                        <strong class="font-semibold">Powered by Gemini AI</strong>
                    </p>
                    <p class="text-lg text-gray-700">
                        Model being used is <strong class="font-semibold">Gemini Pro</strong>
                    </p>
                </div>

                <div id="settings" class="tab-pane active p-6">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('awg_settings_group');
                        do_settings_sections('awg_settings_page');
                        submit_button();
                        ?>
                    </form>
                </div>
            </div>


            <!-- Content Tab -->
            <div id="content" class="tab-pane">
                <h3>Manage Templates & Premade Worksheets</h3>
                <form method="post" enctype="multipart/form-data">
                    <label for="template_name">Name:</label>
                    <input type="text" name="template_name" required>

                    <label for="template_type">Type:</label>
                    <select name="template_type">
                        <option value="template">Template</option>
                        <option value="premadeworksheet">Premade Worksheet</option>
                    </select>

                    <label for="template_category">Category:</label>
                    <select name="template_category">
                        <option value="math">Math</option>
                        <option value="science">Science</option>
                        <option value="history">History</option>
                        <option value="language">Language</option>
                        <option value="other">Other</option>
                    </select>

                    <label for="template_file">Upload HTML File:</label>
                    <input type="file" name="template_file" accept=".html" required>

                    <label for="template_image">Upload Preview Image:</label>
                    <input type="file" name="template_image" accept="image/*" required>

                    <input type="submit" name="upload_template" value="Upload">
                </form>

                <!-- Display Existing Templates -->
                <h3>Existing Templates & Worksheets</h3>

                <ul>
                    <?php
                    $templates = get_option('awg_templates', []);
                    foreach ($templates as $template) {
                        echo "<li>{$template['name']} ({$template['type']}) - <a href='{$template['file']}'>View</a> | <img src='{$template['image']}' width='50'></li>";
                    }
                    ?>
                </ul>
            </div>

            <!-- Statistics Tab -->
            <div id="statistics" class="tab-pane p-6">
                <h3 class="text-2xl font-semibold text-gray-900 mb-4">Usage & Payment Statistics</h3>
                <table class="wp-list-table widefat striped mt-2 border border-gray-300">
                    <thead class="bg-blue-100">
                        <tr>
                            <th class="px-4 py-2 text-left">User</th>
                            <th class="px-4 py-2 text-left">Usage</th>
                            <th class="px-4 py-2 text-left">Payment Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        global $wpdb;

                        // Fetch logged-in users from session tokens
                        $sessions = $wpdb->get_col("
                SELECT user_id FROM {$wpdb->usermeta} 
                WHERE meta_key = 'session_tokens'
            ");

                        if (!empty($sessions)) {
                            $users = get_users(['include' => $sessions]);

                            foreach ($users as $user) {
                                // Fetch user meta for usage and payment status
                                $usage_count = get_user_meta($user->ID, 'usage_count', true) ?: '0';
                                $payment_status = get_user_meta($user->ID, 'payment_status', true) ?: 'Unpaid';

                                echo "<tr class='hover:bg-blue-50'>
                        <td class='px-4 py-2'>{$user->display_name}</td>
                        <td class='px-4 py-2'>{$usage_count} worksheets</td>
                        <td class='px-4 py-2'>{$payment_status}</td>
                    </tr>";
                            }
                        } else {
                            echo "<tr><td colspan='3' class='px-4 py-2 text-center text-gray-500'>No active users</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>



            <!-- Documentation Tab -->
            <div id="docs" class="tab-pane p-6">
                <h3 class="text-2xl font-semibold text-gray-900 mb-4">Documentation & Help</h3>

                <p class="text-lg text-gray-700 mb-4">
                    The AI Worksheet Generator Plugin helps you create AI-powered worksheets directly from your WordPress
                    site.
                    The plugin uses <strong>Gemini AI</strong>, specifically the <strong>Gemini Pro model</strong>, to
                    generate high-quality,
                    customized worksheets in seconds.
                </p>

                <h4 class="text-xl font-medium text-gray-800 mt-4 mb-2">API Key</h4>
                <p class="text-lg text-gray-700 mb-4">
                    To start using the AI Worksheet Generator, you'll need to provide an API key. This key is essential for
                    generating
                    AI-powered worksheets with the Gemini Pro model. Please follow the steps below to configure your API
                    key:
                </p>
                <ul class="list-disc pl-6 text-lg text-gray-700 mb-4">
                    <li>Sign up for an OpenAI account (or other supported AI service, such as Gemini).</li>
                    <li>Obtain your API key from the service's dashboard.</li>
                    <li>Navigate to the plugin settings page and enter your API key in the provided field.</li>
                </ul>

                <h4 class="text-xl font-medium text-gray-800 mt-4 mb-2">Shortcode Usage</h4>
                <p class="text-lg text-gray-700 mb-4">
                    The plugin works through a simple shortcode that you can add to any post, page, or widget. To generate a
                    worksheet,
                    simply insert the following shortcode where you want the form to appear:
                </p>
                <pre class="bg-gray-100 p-4 rounded-lg text-gray-800 text-lg mb-4">[awg_generate_html]</pre>
                <p class="text-lg text-gray-700 mb-4">
                    This shortcode will create a form that allows users to interact with the plugin, customize worksheets,
                    and generate them
                    directly on the front-end.
                </p>

                <h4 class="text-xl font-medium text-gray-800 mt-4 mb-2">Gemini AI Model</h4>
                <p class="text-lg text-gray-700 mb-4">
                    The plugin utilizes the <strong>Gemini Pro model</strong> for generating AI-powered worksheets. Gemini
                    is known for its advanced
                    language processing and ability to tailor content according to user specifications. This ensures that
                    your worksheets are
                    high-quality, relevant, and accurately reflect the content you provide.
                </p>

                <h4 class="text-xl font-medium text-gray-800 mt-4 mb-2">GitHub Repository</h4>
                <p class="text-lg text-gray-700 mb-4">
                    For more information, troubleshooting, and updates, please visit the official documentation on GitHub:
                </p>
                <p>
                    <a href="https://github.com/lewis-2000/ai-worksheet-generator" class="text-blue-600 hover:text-blue-700"
                        target="_blank">
                        https://github.com/lewis-2000/ai-worksheet-generator
                    </a>
                </p>

                <h4 class="text-xl font-medium text-gray-800 mt-4 mb-2">Questions & Support</h4>
                <p class="text-lg text-gray-700 mb-4">
                    If you have any questions or encounter issues while using the plugin, feel free to check the issues
                    section or ask your question
                    directly on the GitHub repository:
                </p>
                <p>
                    <a href="https://github.com/lewis-2000/ai-worksheet-generator/issues"
                        class="text-blue-600 hover:text-blue-700" target="_blank">
                        https://github.com/lewis-2000/ai-worksheet-generator/issues
                    </a>
                </p>

                <p class="text-lg text-gray-700 mt-6">
                    We are always working to improve the plugin. Stay tuned for upcoming updates and new features!
                </p>
            </div>


        </div>
        </div>

        <style>
            /* Style only inputs inside #content */
            #content input[type="text"],
            #content select,
            #content input[type="file"] {
                width: 100%;
                padding: 8px;
                margin: 5px 0 15px;
                border: 1px solid #ccc;
                border-radius: 4px;
                font-size: 14px;
                background: #fff;
            }

            /* Make the dropdowns and text fields uniform */
            #content select {
                cursor: pointer;
            }

            /* Style labels */
            #content label {
                font-weight: bold;
                display: block;
                margin-top: 10px;
            }

            /* Style the upload button */
            #content input[type="submit"] {
                background: #0073aa;
                color: white;
                padding: 10px 15px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                transition: background 0.3s ease;
            }

            #content input[type="submit"]:hover {
                background: #005e8a;
            }

            /* Style the template list */
            #content ul {
                list-style: none;
                padding: 0;
            }

            #content ul li {
                background: #f9f9f9;
                padding: 10px;
                border-left: 4px solid #0073aa;
                display: flex;
                align-items: center;
                justify-content: space-between;
                border-radius: 4px;
                margin-top: 10px;

            }

            #content ul li img {
                border-radius: 4px;
                width: 50px;
                height: auto;
                margin-left: 10px;
            }
        </style>

        <!-- JavaScript for Tab Switching -->
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                let tabs = document.querySelectorAll('.nav-tab');
                let panes = document.querySelectorAll('.tab-pane');

                function showTab(tabId) {
                    panes.forEach(pane => {
                        pane.style.display = pane.id === tabId ? 'block' : 'none';
                    });

                    tabs.forEach(tab => {
                        tab.classList.toggle('nav-tab-active', tab.dataset.tab === tabId);
                    });
                }

                // Set initial tab (first tab active)
                showTab('settings');

                tabs.forEach(tab => {
                    tab.addEventListener('click', function (event) {
                        event.preventDefault();
                        showTab(this.dataset.tab);
                    });
                });
            });
        </script>

        <?php
    }


    public function register_settings()
    {
        register_setting('awg_settings_group', 'openai_api_key');
        register_setting('awg_settings_group', 'awg_templates'); // Store templates
        add_settings_section('awg_main_section', 'API Settings', null, 'awg_settings_page');
        add_settings_field('openai_api_key', 'API Key', array($this, 'api_key_field'), 'awg_settings_page', 'awg_main_section');
    }

    public function api_key_field()
    {
        $api_key = get_option('openai_api_key', '');
        echo "<input type='text' name='openai_api_key' value='" . esc_attr($api_key) . "' style='width: 100%;'>";
    }

    // Handle template uploads
    public function handle_template_upload()
    {
        if (isset($_POST['upload_template'])) {
            $name = sanitize_text_field($_POST['template_name']);
            $type = sanitize_text_field($_POST['template_type']);
            $prefix = ($type == 'template') ? 'template-' : 'premadeworksheet-';

            // Handle file upload
            if (!empty($_FILES['template_file']['name']) && !empty($_FILES['template_image']['name'])) {
                $file_id = media_handle_upload('template_file', 0);
                $image_id = media_handle_upload('template_image', 0);

                if (!is_wp_error($file_id) && !is_wp_error($image_id)) {
                    $file_url = wp_get_attachment_url($file_id);
                    $image_url = wp_get_attachment_url($image_id);

                    // Ensure templates is an array
                    $templates = get_option('awg_templates', []);
                    if (!is_array($templates)) {
                        $templates = []; // Convert to an array if it's a string or something else
                    }

                    $templates[] = [
                        'name' => $prefix . $name,
                        'type' => $type,
                        'file' => $file_url,
                        'image' => $image_url
                    ];

                    update_option('awg_templates', $templates);
                }
            }
        }
    }




    public function generate_ai_response()
    {
        if (!isset($_POST['prompt'])) {
            wp_send_json_error(['message' => 'No prompt provided']);
        }

        $api_key = get_option('openai_api_key');
        if (!$api_key) {
            wp_send_json_error(['message' => 'API key is not set']);
        }

        $user_prompt = sanitize_text_field($_POST['prompt']);

        // 🔹 Injected Guideline for AI to ensure worksheet generation
        $ai_guideline = "Generate a well-structured worksheet in HTML format with the following details and styles:
        - Use semantic HTML elements (e.g., <header>, <section>, <table>).
        - Include a clear heading for the worksheet title.
        - Use <table> elements for structured questions (if needed).
        - Ensure proper spacing and alignment for readability.
        - Avoid including external scripts, only use pure HTML and inline CSS.
        - Keep it clean and minimalistic.";

        // Combine guideline with user prompt
        $prompt = $ai_guideline . "\n\nUser Input: " . $user_prompt;

        $response = wp_remote_post('https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=' . $api_key, array(
            'body' => json_encode(array(
                'contents' => array(
                    array(
                        'parts' => array(array("text" => $prompt))
                    )
                )
            )),
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'API request failed: ' . $response->get_error_message()]);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!$body || !isset($body['candidates'][0]['content']['parts'][0]['text'])) {
            wp_send_json_error(['message' => 'Invalid response from Gemini. Response: ' . print_r($body, true)]);
        }

        // Extract generated content and clean it
        $this->generated_html = trim($body['candidates'][0]['content']['parts'][0]['text']);
        $this->generated_html = preg_replace('/^```html|```$/m', '', $this->generated_html); // Remove markdown fences

        // Extract inline CSS and move to <style> tags
        $updated_html = $this->extract_and_move_inline_css($this->generated_html);

        // Generate PDF from the cleaned HTML
        $pdf_url = $this->generate_pdf($updated_html);

        wp_send_json_success(['html' => $updated_html, 'pdf_url' => $pdf_url]);
    }


    /**
     * Extracts inline CSS from style attributes and moves them into a <style> block.
     */
    private function extract_and_move_inline_css($html)
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true); // Suppress parsing errors
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $styles = [];
        foreach ($dom->getElementsByTagName('*') as $element) {
            if ($element->hasAttribute('style')) {
                $tag = $element->nodeName;
                $style = $element->getAttribute('style');
                $class_name = 'custom-' . uniqid(); // Unique class name
                $element->removeAttribute('style');
                $element->setAttribute('class', $class_name);
                $styles[] = ".$class_name { $style }";
            }
        }

        $css = implode("\n", $styles);
        $style_tag = "<style>\n$css\n</style>\n";

        return $style_tag . $dom->saveHTML();
    }



    private function generate_pdf($html_content)
    {
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html_content);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $upload_dir = wp_upload_dir();
        $current_user = wp_get_current_user();
        $username = sanitize_file_name($current_user->user_login);
        $pdf_path = $upload_dir['path'] . '/' . $username . '-' . time() . '.pdf';
        file_put_contents($pdf_path, $dompdf->output());

        $file_array = array(
            'name' => basename($pdf_path),
            'tmp_name' => $pdf_path,
        );

        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            return '';
        }

        // Get the URL of the PDF file
        $pdf_url = wp_get_attachment_url($attachment_id);

        // Add the generated PDF to the cart
        $this->awg_add_to_cart_after_pdf($pdf_url, $username);

        return $pdf_url;
    }

    private function awg_add_to_cart_after_pdf($pdf_url, $username)
    {
        // Create the worksheet item to add to the cart
        $worksheet = [
            'id' => sanitize_text_field($username . '-' . time()), // You can create a unique ID for the PDF
            'name' => 'Generated Worksheet', // You can change this name as needed
            'price' => 10, // Set a default price or dynamically calculate it
            'url' => esc_url($pdf_url)
        ];

        // Add to cart based on user login status
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $cart = get_user_meta($user_id, 'awg_cart', true) ?: [];
            $cart[] = $worksheet;
            update_user_meta($user_id, 'awg_cart', $cart);
        } else {
            // Add to session if the user is not logged in
            session_start();
            $_SESSION['awg_cart'][] = $worksheet;
        }
    }


    function convert_html_to_pdf()
    {
        // Check if function runs
        error_log("AJAX function convert_to_pdf() called!");

        if (!isset($_POST['html']) || empty($_POST['html'])) {
            wp_send_json_error(['error' => 'No HTML content received.']);
        }

        $html_content = stripslashes($_POST['html']);

        // Debug received HTML
        error_log("Received HTML: " . substr($html_content, 0, 500));

        $pdf_url = $this->generate_pdf($html_content);

        if (!$pdf_url) {
            error_log("PDF Generation Failed!");
            wp_send_json_error(['error' => 'Failed to generate PDF.']);
        }

        error_log("PDF Successfully Generated: " . $pdf_url);
        wp_send_json_success(['pdf_url' => $pdf_url]);
    }

    public function convert_to_pdf()
    {
        ob_clean(); // Ensure no extra output

        if (!isset($_POST['html'])) {
            error_log("convert_to_pdf: No HTML content provided");
            wp_send_json_error(['message' => 'No HTML content provided']);
        }

        $html_content = stripslashes($_POST['html']);
        $html_content = trim($html_content);

        $processed_html = $this->extract_and_move_inline_css($html_content);

        $pdf_url = $this->generate_pdf($processed_html);

        if (!$pdf_url) {
            error_log("convert_to_pdf: Failed to generate PDF");
            wp_send_json_error(['message' => 'Failed to generate PDF']);
        }

        error_log("convert_to_pdf: PDF generated successfully -> " . $pdf_url);

        wp_send_json_success(['html' => $processed_html, 'pdf_url' => $pdf_url]);

        // Ensure script stops after JSON response
        die();
    }




    public function fetch_generated_html()
    {
        if (!isset($this->generated_html)) {
            wp_send_json_error(['message' => 'No generated content available']);
        }

        wp_send_json_success(['html' => $this->generated_html]);
    }



    public function fetch_latest_pdf()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'User not logged in']);
        }

        $current_user = wp_get_current_user();
        $username = sanitize_file_name($current_user->user_login);

        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'application/pdf',
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => array(
                array(
                    'key' => '_wp_attached_file',
                    'value' => $username . '-', // Ensure it matches username in filename
                    'compare' => 'LIKE'
                )
            )
        );

        $latest_pdf = get_posts($args);

        if (empty($latest_pdf)) {
            wp_send_json_error(['message' => 'No PDFs found']);
        }

        $pdf_url = wp_get_attachment_url($latest_pdf[0]->ID);
        wp_send_json_success(['pdf_url' => $pdf_url]);
    }

    public function get_user_pdfs($user_id)
    {
        // Example: Assuming PDFs are stored as posts of a custom post type "pdf"
        $args = array(
            'post_type' => 'pdf', // Change to your custom post type
            'posts_per_page' => -1,
            'author' => $user_id, // Filter by author (user ID)
            'meta_query' => array(
                array(
                    'key' => '_pdf_user', // Custom field to store user ID (if applicable)
                    'value' => $user_id,
                    'compare' => '=',
                ),
            ),
        );

        $query = new WP_Query($args);
        $pdfs = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $pdfs[] = array(
                    'title' => get_the_title(),
                    'url' => get_permalink(), // Assuming PDF is linked to the post
                );
            }
            wp_reset_postdata();
        }

        return $pdfs;
    }


    // Cart and Payment System
    function awg_add_to_cart()
    {
        session_start();

        $worksheet = [
            'id' => sanitize_text_field($_POST['worksheet_id']),
            'name' => sanitize_text_field($_POST['worksheet_name']),
            'price' => sanitize_text_field($_POST['worksheet_price']),
            'url' => esc_url($_POST['worksheet_url'])
        ];

        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $cart = get_user_meta($user_id, 'awg_cart', true) ?: [];
            $cart[] = $worksheet;
            update_user_meta($user_id, 'awg_cart', $cart);
        } else {
            $_SESSION['awg_cart'][] = $worksheet;
        }

        wp_send_json_success(['message' => 'Worksheet added to cart']);
    }

    // Remove item from cart
    public function awg_remove_from_cart()
    {
        session_start();

        $worksheet_id = sanitize_text_field($_POST['worksheet_id']);

        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $cart = get_user_meta($user_id, 'awg_cart', true) ?: [];

            // Filter out the item by ID
            $cart = array_filter($cart, function ($item) use ($worksheet_id) {
                return $item['id'] !== $worksheet_id;
            });

            update_user_meta($user_id, 'awg_cart', array_values($cart));
        } else {
            if (!empty($_SESSION['awg_cart'])) {
                $_SESSION['awg_cart'] = array_values(array_filter($_SESSION['awg_cart'], function ($item) use ($worksheet_id) {
                    return $item['id'] !== $worksheet_id;
                }));
            }
        }

        wp_send_json_success(['message' => 'Worksheet removed from cart']);
    }




    // Track Guest Downloads

    function awg_track_guest_download()
    {
        session_start();
        $_SESSION['awg_free_downloads'] = ($_SESSION['awg_free_downloads'] ?? 0) + 1;
    }

    //Check If Guest Can Download
    function awg_can_guest_download()
    {
        session_start();
        return ($_SESSION['awg_free_downloads'] ?? 0) < 1;
    }




    public function display_html_response()
    {
        ob_start(); ?>

        <!-- Hero Section -->
        <div class="hero-sm-section max-w-6xl mx-auto flex flex-col p-10 shadow-lg rounded-xl bg-white animate-fade-up relative bg-white shadow-lg rounded-xl p-10 before:absolute before:inset-0 
    before:bg-blue-400 before:blur-lg before:opacity-10 before:rounded-xl before:pointer-events-none">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-12 items-center">
                <!-- Text Section -->
                <div class="text-center md:text-left">
                    <h1 class="text-4xl font-bold text-gray-900 leading-tight mb-5">
                        AI-Powered Worksheet Creation
                    </h1>
                    <p class="text-lg text-gray-700 mb-6">
                        Generate fully customized worksheets in seconds using AI.
                        Select a style, define your content, and let AI do the rest—fast, efficient, and tailored to your needs.
                    </p>
                    <button id="create-with-ai-btn"
                        class="relative z-10 bg-blue-600 text-white text-lg font-medium px-6 py-3 rounded-lg shadow-md hover:bg-blue-700">
                        Generate with AI
                    </button>
                </div>

                <!-- Image Section -->
                <div class="relative">
                    <img src="<?php echo plugin_dir_url(__FILE__) . 'images/bgh.png'; ?>"
                        class="w-full rounded-lg object-cover shadow-lg transform transition duration-500 hover:scale-105"
                        alt="Worksheet Image">
                    <div class="absolute top-0 left-0 w-full h-full bg-gradient-to-r from-blue-500 to-transparent 
                opacity-20 rounded-lg"></div>
                </div>
            </div>
        </div>







        <div id="ai-overlay"
            class="fixed z-20 flex-col bg-black/30 backdrop-blur-md inset-0 shadow-2xl m-auto container p-3 hidden animate-fade-up">
            <!-- Overlay Content -->
            <!-- Navigation Bar -->
            <?php
            $current_user = wp_get_current_user();
            $is_logged_in = is_user_logged_in();
            $google_avatar = get_user_meta($current_user->ID, 'nsl_user_avatar', true); // Nextend Social Login stores the avatar here
            ?>

            <nav class="flex justify-between items-center bg-gray-100 p-3 rounded-md rounded-t-lg rounded-b-none">
                <!-- Tabs (with icons for mobile) -->
                <div class="flex space-x-4 items-center">
                    <button
                        class="tab-button text-blue-600 text-sm font-semibold items-center space-x-2 hover:bg-gray-200 rounded-md p-2 hidden md:flex"
                        data-tab="templates">
                        <i class="fas fa-th text-blue-600"></i>
                        <span>Premade Templates</span>
                    </button>
                    <button
                        class="tab-button text-blue-600 text-sm font-semibold items-center space-x-2 hover:bg-gray-200 rounded-md p-2 hidden md:flex"
                        data-tab="worksheets">
                        <i class="fas fa-file-alt text-blue-600"></i>
                        <span>Premade Worksheets</span>
                    </button>
                    <button
                        class="tab-button text-blue-600 text-sm font-semibold items-center space-x-2 hover:bg-gray-200 rounded-md p-2 hidden md:flex"
                        data-tab="ai-generation">
                        <i class="fas fa-robot text-blue-600"></i>
                        <span>AI Generation</span>
                    </button>
                    <button
                        class="tab-button text-blue-600 text-sm font-semibold items-center space-x-2 hover:bg-gray-200 rounded-md p-2 hidden md:flex"
                        data-tab="account">
                        <i class="fas fa-user-circle text-blue-600"></i>
                        <span>Account</span>
                    </button>

                    <!-- Icons for smaller screens -->
                    <div class="flex md:hidden space-x-2">
                        <button
                            class="tab-button text-blue-600 text-sm font-semibold flex items-center space-x-2 hover:bg-gray-200"
                            data-tab="templates">
                            <i class="fas fa-th text-blue-600"></i>
                        </button>
                        <button
                            class="tab-button text-blue-600 text-sm font-semibold flex items-center space-x-2 hover:bg-gray-200"
                            data-tab="worksheets">
                            <i class="fas fa-file-alt text-blue-600"></i>
                        </button>
                        <button
                            class="tab-button text-blue-600 text-sm font-semibold flex items-center space-x-2 hover:bg-gray-200"
                            data-tab="ai-generation">
                            <i class="fas fa-robot text-blue-600"></i>
                        </button>
                        <button
                            class="tab-button text-blue-600 text-sm font-semibold flex items-center space-x-2 hover:bg-gray-200"
                            data-tab="account">
                            <i class="fas fa-user-circle text-blue-600"></i>
                        </button>
                    </div>
                </div>

                <div>
                    <!-- Google Login / User Avatar -->
                    <div id="google-login" class="flex items-center gap-2 cursor-pointer">
                        <?php if ($is_logged_in): ?>
                            <!-- Show user avatar when logged in -->
                            <div class="flex items-center gap-2 bg-gray-100 px-3 py-1 rounded-lg shadow-sm">
                                <img src="<?php echo get_avatar_url($current_user->ID); ?>" alt="Profile"
                                    class="w-10 h-10 rounded-full shadow-md"> <span class="text-gray-700 font-medium text-sm">
                                    <?php echo esc_html($current_user->display_name); ?>
                                </span>
                            </div>
                            <a href="<?php echo wp_logout_url(home_url()); ?>"
                                class="bg-red-500 text-white text-sm font-medium px-3 py-1 rounded-md hover:bg-red-600 transition">
                                Logout
                            </a>
                        <?php else: ?>
                            <!-- Show Google login button when logged out -->
                            <a href="http://localhost/mywordpress/wp-login.php?loginSocial=google" data-plugin="nsl"
                                data-action="connect" data-redirect="current" data-provider="google" data-popupwidth="600"
                                data-popupheight="600">
                                <i class="fa-solid fa-circle-user text-blue-500"></i>
                            </a>
                        <?php endif; ?>
                        <!-- Close Button -->
                        <i id="close-ai-overlay"
                            class="fa-solid fa-circle-xmark text-md font-semibold rounded-full p-1 text-blue-500">
                        </i>


                    </div>



                </div>


            </nav>
            <div class="bg-white w-full p-4 rounded-b-lg shadow-lg mx-auto my-auto h-full">



                <!-- Dynamic Content Area -->
                <div class="bg-white p-2 rounded-md h-full overflow-y-auto">
                    <!-- Premade Templates Tab -->
                    <div id="templates" class="tab-content min-h-full mb-10 flex flex-col p-4 bg-white rounded-lg shadow-md">
                        <p class="text-sm font-semibold text-blue-700 mb-2">Customize Template</p>

                        <div class="flex flex-col md:flex-row gap-4 flex-grow">
                            <!-- Left Panel: Customization Options -->
                            <div class="w-full md:w-1/3 p-3 bg-gray-50 rounded-md shadow flex flex-col">
                                <label for="template_select" class="block text-sm text-gray-700 font-medium mb-1">Select
                                    Template:</label>
                                <select id="template_select"
                                    class="w-full p-2 text-sm border rounded-md bg-white shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">-- Select a Template --</option>
                                    <?php
                                    $templates = get_option('awg_templates', []);
                                    foreach ($templates as $template) {
                                        echo "<option value='{$template['file']}'>" . esc_html($template['name']) . "</option>";
                                    }
                                    ?>
                                </select>

                                <button id="load_template"
                                    class="bg-blue-600 text-white text-sm px-3 py-2 rounded-md mt-3 w-full hover:bg-blue-700 transition">
                                    Load Template
                                </button>

                                <!-- Sidebar for Editing -->
                                <div id="editorSidebar" class="w-full p-2 bg-white rounded-md shadow mt-3 hidden overflow-auto">
                                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Edit Element</h3>

                                    <label for="elementEditor" class="block text-sm text-gray-600 font-medium">Text
                                        Content:</label>
                                    <textarea id="elementEditor"
                                        class="w-full h-20 text-sm border p-2 rounded-md resize-none focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>

                                    <label for="colorPicker" class="block text-sm text-gray-600 font-medium mt-2">Text
                                        Color:</label>
                                    <input type="color" id="colorPicker"
                                        class="w-full h-8 p-1 border rounded-md cursor-pointer focus:outline-none">

                                    <button id="applyChanges"
                                        class="bg-green-600 text-white text-sm px-3 py-2 mt-4 w-full rounded-md shadow hover:bg-green-700 transition">
                                        Apply Changes
                                    </button>
                                </div>
                            </div>

                            <!-- Right Panel: Live Preview -->
                            <div class="w-full md:w-2/3 p-3 bg-white rounded-md shadow flex flex-col">
                                <h3 class="text-sm font-semibold text-gray-800 mb-2">Template Preview</h3>
                                <div id="template_preview"
                                    class="w-full flex-grow border bg-gray-100 p-2 rounded-md shadow-sm overflow-auto text-sm">
                                    <!-- Live preview content will be injected here -->
                                </div>
                            </div>
                        </div>

                        <!-- Save Button -->
                        <button id="save_template"
                            class="bg-blue-600 text-white text-sm px-4 py-2 rounded-md mt-4 w-full shadow hover:bg-blue-700 transition">
                            Save Customized Template
                        </button>
                    </div>

                    <!-- Premade Worksheets Tab -->
                    <div id="worksheets" class="tab-content hidden p-4 bg-white rounded-lg shadow-md">
                        <div class="flex flex-col min-h-full md:flex-row gap-3">
                            <!-- Grid of Templates -->
                            <div id="template-grid"
                                class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 w-full md:w-2/3">
                                <!-- Templates are injected here by JavaScript -->
                            </div>

                            <!-- Preview Section -->
                            <div class="w-full md:w-1/3 h-auto bg-gray-50 p-3 rounded-md shadow-sm">
                                <h3 class="text-sm font-semibold mb-2 text-blue-700">Template Preview</h3>
                                <img id="template-preview"
                                    src="<?php echo plugin_dir_url(__FILE__) . 'images/placeholder.png'; ?>" alt="Preview"
                                    class="w-full rounded-md border mb-2 shadow-sm">
                                <p id="template-name" class="text-center text-sm text-gray-500">Select a template</p>
                            </div>
                        </div>
                    </div>



                    <!-- AI Generation Tab -->
                    <div id="ai-generation" class="tab-content hidden overflow-auto">
                        <div
                            class="ai-content flex flex-col md:flex-row gap-4 p-3 bg-gray-100 rounded-md shadow-md min-h-full mb-10 ">

                            <!-- AI Input Section -->
                            <div class="ai-input-section w-full md:w-1/2 bg-white p-3 rounded-md shadow-sm flex flex-col">
                                <h2 class="text-sm font-semibold mb-2 text-gray-700">Enter Your Prompt</h2>

                                <!-- Guidelines Section -->
                                <div class="bg-blue-50 border-l-4 border-blue-500 p-2 rounded-md mb-2 text-sm">
                                    <h3 class="text-blue-700 font-semibold">Guidelines:</h3>
                                    <p class="text-gray-700">Clearly define the worksheet layout, structure, and colors. Use
                                        HTML where possible.</p>
                                    <ul class="list-disc pl-5 text-gray-700">
                                        <li>Specify **sections**: **Header, Questions, Answers, Footer**.</li>
                                        <li>Define **colors**: Background, text, table borders.</li>
                                        <li>Provide **sample HTML** for clarity.</li>
                                    </ul>
                                    </pre>
                                </div>

                                <textarea id="awg-prompt"
                                    class="w-full p-2 border-2 border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 resize-none min-h-[110px] flex-1 text-sm text-gray-500"
                                    onfocus="if (this.value === this.defaultValue) this.value = '';">
                                                                                                                                                                                                                                                        Exmaple: Create a mathematics worksheet for primary school students. The worksheet should have:
                                                                                                                                                                                                                                                        - A title: "Basic Math Practice"
                                                                                                                                                                                                                                                        - A subtitle: "Addition and Subtraction (Ages 6-8)"
                                                                                                                                                                                                                                                        - A section with simple **addition problems** (e.g., 5 + 3 = __)
                                                                                                                                                                                                                                                        - A section with simple **subtraction problems** (e.g., 9 - 4 = __)
                                                                                                                                                                                                                                                        - A space for the student's name and date at the top
                                                                                                                                                                                                                                                        - A footer with "Good luck!" centered at the bottom
                                                                                                                                                                                                                                                        - A simple, readable font with a clear layout using a <table> for questions
                                                                                                                                                                                                                                                        </textarea>

                                <button id="awg-generate-btn"
                                    class="w-full mt-2 bg-blue-500 text-white py-2 px-3 rounded-md hover:bg-blue-600 transition text-sm">
                                    Generate Worksheet
                                </button>
                            </div>

                            <!-- AI Preview Section -->
                            <div
                                class="ai-preview-section w-full md:w-1/2 bg-white p-3 rounded-md shadow-sm relative flex flex-col">
                                <h2 class="text-sm font-semibold mb-2 text-gray-700">Preview</h2>

                                <!-- Download & Print Strip -->
                                <div id="awg-action-strip"
                                    class="hidden justify-between items-center bg-gray-200 p-2 rounded-md mb-2 text-sm">
                                    <span class="text-gray-700 font-medium">Download or Print:</span>
                                    <div class="flex gap-2">
                                        <button id="awg-print-btn"
                                            class="bg-blue-500 text-white px-3 py-1 rounded-md hover:bg-blue-600 text-sm">
                                            Print
                                        </button>
                                        <a id="awg-download-pdf"
                                            class="bg-green-500 text-white px-3 py-1 rounded-md hover:bg-green-600 transition text-sm"
                                            href="#" target="_blank" download>
                                            Download PDF
                                        </a>
                                    </div>
                                </div>

                                <!-- Live Preview with Loading -->
                                <div id="awg-html-output"
                                    class="preview-box border border-gray-300 p-2 rounded-md flex-1 bg-gray-50 flex justify-center items-center relative overflow-auto">
                                    <img id="awg-placeholder"
                                        src="<?php echo plugin_dir_url(__FILE__) . 'images/placeholder.png'; ?>"
                                        alt="Worksheet Preview Placeholder" class="max-w-full h-auto">
                                    <div id="awg-loading" class="absolute hidden">
                                        <div
                                            class="animate-spin inline-block w-6 h-6 border-4 border-blue-500 border-t-transparent rounded-full">
                                        </div>
                                        <p class="mt-2 text-gray-600 text-sm">Generating worksheet...</p>
                                    </div>
                                </div>

                                <!-- PDF Container -->
                                <div id="awg-pdf-container" class="mt-2 hidden">
                                    <h3 class="text-center text-sm font-semibold text-gray-700">Generated Worksheet PDF</h3>
                                    <iframe id="awg-pdf-frame" class="w-full h-[400px] border-none"></iframe>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Account Tab -->
                    <div id="account" class="tab-content hidden p-4 bg-white rounded-lg shadow-md h-auto">
                        <?php if (is_user_logged_in()):
                            $current_user = wp_get_current_user();
                            $user_pdfs = get_user_pdfs($current_user->ID); // Fetch user's PDFs
                            ?>
                            <!-- Logged-in User Section -->
                            <div class="flex items-center gap-3 border-b pb-3 mb-3">
                                <img src="<?php echo get_avatar_url($current_user->ID); ?>" alt="Profile"
                                    class="w-12 h-12 rounded-full shadow-md">
                                <div>
                                    <p class="text-blue-600 font-semibold text-sm">
                                        <?php echo esc_html($current_user->display_name); ?>
                                    </p>
                                    <p class="text-gray-500 text-xs">Payment Status: <strong class="text-blue-500">Pending</strong>
                                    </p>
                                </div>
                            </div>

                            <!-- Cart Section -->
                            <h3 class="text-sm font-semibold text-gray-700 mt-4 mb-2">Your Cart</h3>
                            <?php
                            $cart = is_user_logged_in() ? get_user_meta(get_current_user_id(), 'awg_cart', true) : ($_SESSION['awg_cart'] ?? []);
                            if (!empty($cart)): ?>
                                <ul class="space-y-2 text-sm">
                                    <?php foreach ($cart as $item): ?>
                                        <li class="flex items-center justify-between bg-gray-100 p-2 rounded-md shadow-sm">
                                            <span class="text-gray-600 truncate"><?php echo esc_html($item['name']); ?></span>
                                            <button class="text-red-500 text-xs font-medium hover:underline remove-from-cart"
                                                data-id="<?php echo esc_attr($item['id']); ?>">Remove</button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                </button>
                            <?php else: ?>
                                <p class="text-xs text-gray-500">Your cart is empty.</p>
                            <?php endif; ?>

                            <!-- Upgrade Button -->
                            <button id="upgrade-account-btn"
                                class="bg-blue-500 text-white text-sm px-3 py-1.5 rounded-lg mt-3 hover:bg-blue-600 transition">
                                Upgrade Account
                            </button>

                            <div class="flex flex-wrap md:flex-nowrap justify-between items-start gap-4 mt-3">
                                <!-- PDF List -->
                                <div class="w-full md:w-1/3">
                                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Your Generated PDFs</h3>
                                    <?php if (!empty($user_pdfs)): ?>
                                        <ul class="space-y-2 text-sm">
                                            <?php foreach ($user_pdfs as $pdf): ?>
                                                <li class="flex items-center justify-between bg-gray-100 p-2 rounded-md shadow-sm">
                                                    <span class="text-gray-600 truncate"><?php echo esc_html($pdf['title']); ?></span>
                                                    <button class="text-blue-500 text-xs font-medium hover:underline view-pdf"
                                                        data-url="<?php echo esc_url($pdf['url']); ?>">
                                                        View
                                                    </button>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <p class="text-xs text-gray-500">You have not generated any PDFs yet.</p>
                                    <?php endif; ?>
                                </div>

                                <!-- PDF Display Area -->
                                <div class="w-full md:w-2/3 bg-white p-4 shadow-md rounded-md">
                                    <h3 class="text-sm font-semibold text-gray-700 mb-2">PDF Viewer</h3>
                                    <div id="pdf-viewer-container"
                                        class="w-full h-[600px] overflow-auto border rounded-md flex flex-col items-center justify-start p-2">
                                        <p class="text-xs text-gray-500 text-center">Click "View" to display a PDF here.</p>
                                    </div>
                                </div>






                            <?php else: ?>
                                <!-- Not Logged-in Section -->
                                <div class="text-center">
                                    <p class="text-sm text-gray-600 mb-2">Login is free and allows you to store and manage your
                                        generated PDFs.</p>


                                    <div class="w-full border-b mt-4 mb-4 flex flex-col items-center">
                                        <!-- Show Google login button when logged out -->
                                        <a href="http://localhost/mywordpress/wp-login.php?loginSocial=google" data-plugin="nsl"
                                            data-action="connect" data-redirect="current" data-provider="google"
                                            data-popupwidth="600" data-popupheight="600">
                                            <i class="fa-solid fa-circle-user text-4xl text-blue-500"></i>
                                        </a>

                                        <p class="text-sm text-gray-500 mt-2">Don't have a Google account?</p>
                                    </div>

                                <?php endif; ?>

                            </div>



                        </div>



                    </div>
                </div>
            </div>

            <script>
                document.addEventListener("DOMContentLoaded", function () {
                    const overlay = document.getElementById("ai-overlay");
                    const body = document.body;

                    // Open AI Overlay
                    document.getElementById("create-with-ai-btn").addEventListener("click", function () {
                        overlay.style.display = "flex";
                        body.classList.add("no-scroll");
                    });

                    // Close AI Overlay
                    document.getElementById("close-ai-overlay")?.addEventListener("click", function () {
                        overlay.style.display = "none";
                        body.classList.remove("no-scroll");
                    });

                    // Handle tab navigation
                    document.querySelectorAll('.tab-button').forEach(button => {
                        button.addEventListener('click', () => {
                            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('bg-gray-300', 'text-gray-900'));
                            button.classList.add('bg-gray-300', 'text-gray-900');

                            document.querySelectorAll('.tab-content').forEach(content => content.classList.add('hidden'));
                            document.getElementById(button.getAttribute('data-tab')).classList.remove('hidden');
                        });
                    });

                    // Default open ai tab
                    document.querySelector('.tab-button[data-tab="ai-generation"]').click();

                    // Template Selection Logic
                    function setupTemplateSelection() {
                        document.getElementById('template-grid').addEventListener('click', (event) => {
                            const card = event.target.closest('.template-card');
                            if (!card) return;

                            const templateName = card.getAttribute('data-template');
                            const imageSrc = card.querySelector('img').src;

                            document.getElementById('template-preview').src = imageSrc;
                            document.getElementById('template-name').innerText = templateName.replace('template-', 'Template ').replace('premadeworksheet-', 'Worksheet ');
                        });
                    }

                    function loadTemplates() {
                        const templates = <?php echo json_encode(get_option('awg_templates', [])); ?>;
                        const templateGrid = document.getElementById('template-grid');
                        templateGrid.innerHTML = '';

                        if (templates.length === 0) {
                            templateGrid.innerHTML = "<p class='text-gray-500 text-center col-span-3'>No templates available.</p>";
                            return;
                        }

                        templates.forEach(template => {
                            const card = document.createElement('div');
                            card.className = "template-card h-fit bg-white p-3 rounded-lg shadow-md hover:shadow-lg transition border border-gray-200 hover:border-blue-500 flex flex-col items-center text-center";

                            card.setAttribute('data-template', template.name);

                            card.innerHTML = `
            <!-- Template Image -->
                <img src="${template.image}" alt="${template.name}" class="w-full h-32 object-cover rounded-md border border-gray-300">

                <!-- Template Name -->
                <p class="mt-3 text-sm font-semibold text-gray-700">${template.name.replace('template-', 'Template ').replace('premadeworksheet-', 'Worksheet ')}</p>

                <!-- Action Buttons -->
                <div class="flex justify-center gap-3 mt-3 w-full">
                    <!-- View Button -->
                    <button class="bg-blue-500 text-white text-xs px-3 py-1.5 rounded-lg hover:bg-blue-600 transition">
                        <i class="fas fa-eye"></i> View
                    </button>

                    <!-- Send to Cart Button -->
                    <button class="bg-green-500 text-white text-xs px-3 py-1.5 rounded-lg hover:bg-green-600 transition send-to-cart"
                            data-id="${template.id}"
                            data-name="${template.name}"
                            data-image="${template.image}">
                        <i class="fas fa-cart-plus"></i> Add
                    </button>
                </div>
            
        `;

                            templateGrid.appendChild(card);
                        });

                        document.addEventListener("DOMContentLoaded", function () {
                            document.querySelectorAll('.send-to-cart').forEach(button => {
                                button.addEventListener('click', function () {
                                    const worksheetData = {
                                        action: 'awg_add_to_cart',
                                        worksheet_id: this.getAttribute('data-id'),
                                        worksheet_name: this.getAttribute('data-name'),
                                        worksheet_price: this.getAttribute('data-price'),
                                        worksheet_url: this.getAttribute('data-url')
                                    };

                                    // Send AJAX request to add item to cart
                                    fetch(awg_ajax.ajax_url, {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: new URLSearchParams(worksheetData)
                                    })
                                        .then(response => response.json())
                                        .then(data => {
                                            if (data.success) {
                                                // Create a temporary success message
                                                const message = document.createElement("p");
                                                message.textContent = "Added to cart!";
                                                message.className = "text-green-500 text-xs font-semibold mt-1 fade-out";

                                                // Insert message after button
                                                this.parentElement.appendChild(message);

                                                // Remove message after delay
                                                setTimeout(() => {
                                                    message.remove();
                                                }, 1000);

                                                // Optionally, update cart UI dynamically here (if needed) TODO Later..
                                            }
                                        })
                                        .catch(error => console.error('Error:', error));
                                });
                            });
                        });

                    }

                    //Remove from cart
                    document.querySelectorAll('.remove-from-cart').forEach(button => {
                        button.addEventListener("click", function () {
                            const worksheetId = this.getAttribute("data-id");
                            const listItem = this.closest("li"); // Get the <li> element

                            // Send AJAX request to remove item
                            fetch(awg_ajax.ajax_url, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({
                                    action: 'awg_remove_from_cart',
                                    worksheet_id: worksheetId
                                })
                            })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        // Show confirmation message
                                        const message = document.createElement("p");
                                        message.textContent = "Removed from cart";
                                        message.className = "text-red-500 text-xs font-semibold mt-1 fade-out";
                                        listItem.appendChild(message);

                                        // Remove item after a delay
                                        setTimeout(() => {
                                            listItem.remove(); // Remove item from UI
                                        }, 1000); // Wait 1s before removing
                                    }
                                })
                                .catch(error => console.error("Error:", error));
                        });
                    });



                    // Initialize functions
                    loadTemplates();
                    setupTemplateSelection();

                    //AI Overlay Tab
                    document.getElementById("awg-generate-btn").addEventListener("click", function () {
                        let userPrompt = document.getElementById("awg-prompt").value;
                        if (!userPrompt) return;

                        document.getElementById("awg-loading").style.display = "block";
                        document.getElementById("awg-html-output").style.display = "none";
                        document.getElementById("awg-pdf-container").style.display = "none";

                        fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                            method: "POST",
                            headers: { "Content-Type": "application/x-www-form-urlencoded" },
                            body: "action=generate_ai_response&prompt=" + encodeURIComponent(userPrompt)
                        })
                            .then(response => response.json())
                            .then(jsonData => {
                                document.getElementById("awg-loading").style.display = "none";

                                if (jsonData.success) {
                                    // console.log("JSON data: ", jsonData);
                                    document.getElementById("awg-html-output").innerHTML = jsonData.data.html;
                                    // console.log("html: ", jsonData.html);
                                    document.getElementById("awg-html-output").style.display = "block";

                                    let checkPdfInterval = setInterval(() => {
                                        fetch("<?php echo admin_url('admin-ajax.php'); ?>?action=fetch_latest_pdf")
                                            .then(response => response.json())
                                            .then(pdfData => {
                                                if (pdfData.success) {
                                                    clearInterval(checkPdfInterval);
                                                    document.getElementById("awg-pdf-frame").src = pdfData.pdf_url;
                                                    document.getElementById("awg-download-pdf").href = pdfData.pdf_url;
                                                    document.getElementById("awg-pdf-container").style.display = "block";
                                                }
                                            });
                                    }, 3000);
                                } else {
                                    alert("Error: " + jsonData.message);
                                }
                            })
                            .catch(error => {
                                document.getElementById("awg-loading").style.display = "none";
                                console.error("Fetch Error:", error);
                                alert("An unexpected error occurred.");
                            });
                    });

                    fetch("<?php echo admin_url('admin-ajax.php'); ?>?action=check_payment_status")
                        .then(response => response.json())
                        .then(jsonData => {
                            if (jsonData.success && jsonData.paid) {
                                document.getElementById("awg-action-strip").classList.remove("hidden");
                            }
                        })
                        .catch(error => console.error("Error checking payment status:", error));




                });

            </script>

            <script>
                document.addEventListener("DOMContentLoaded", function () {
                    const body = document.body;

                    // Template Selection
                    const templateSelect = document.getElementById("template_select");
                    const loadButton = document.getElementById("load_template");
                    const templatePreview = document.getElementById("template_preview");
                    const saveButton = document.getElementById("save_template");

                    // Sidebar Editing Elements
                    const editorSidebar = document.getElementById("editorSidebar");
                    const elementEditor = document.getElementById("elementEditor");
                    const colorPicker = document.getElementById("colorPicker");
                    const applyChangesButton = document.getElementById("applyChanges");

                    let selectedElement = null; // Stores clicked element

                    // 🎯 Load Template into Preview
                    loadButton.addEventListener("click", function () {
                        const selectedTemplate = templateSelect.value;

                        if (selectedTemplate) {
                            fetch(selectedTemplate)
                                .then(response => response.text())
                                .then(data => {
                                    // console.log(" Template Data: ", data);
                                    templatePreview.innerHTML = data; // Load template into preview
                                    setTimeout(() => enableEditing(), 300); // Enable editing after load
                                })
                                .catch(err => alert('Error loading template: ' + err));
                        }
                    });

                    // ✏️ Enable Editing Functionality
                    function enableEditing() {
                        // 🖱️ Click to Edit Text
                        templatePreview.addEventListener("click", function (event) {
                            selectedElement = event.target;

                            // If it's a text-based element
                            if (selectedElement.tagName === "P" || selectedElement.tagName === "H1" || selectedElement.tagName === "H2" || selectedElement.tagName === "SPAN") {
                                elementEditor.value = selectedElement.innerText;
                                colorPicker.value = getComputedStyle(selectedElement).color; // Get current color
                                editorSidebar.classList.remove("hidden"); // Show sidebar
                            }
                        });
                    }

                    // ✅ Apply Text & Color Changes
                    applyChangesButton.addEventListener("click", function () {
                        if (selectedElement) {
                            selectedElement.innerText = elementEditor.value;
                            selectedElement.style.color = colorPicker.value;
                            editorSidebar.classList.add("hidden"); // Hide sidebar after applying
                        }
                    });

                    // 💾 Save as PDF & Upload to WordPress
                    saveButton.addEventListener("click", function () {
                        const newHtml = document.getElementById("template_preview").innerHTML;

                        fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                            method: "POST",
                            headers: { "Content-Type": "application/x-www-form-urlencoded" },
                            body: new URLSearchParams({
                                action: "convert_to_pdf", // Required for WordPress AJAX
                                html: document.getElementById("template_preview").innerHTML // Sending HTML content
                            })
                        })
                            .then(response => response.json()) // Parse JSON response
                            .then(data => {
                                console.log("AJAX Response:", data);

                                if (data.success) {
                                    alert("Template saved as PDF! View it here: " + data.pdf_url);
                                } else {
                                    console.error("Error saving PDF:", data.error);
                                    alert("Error saving PDF: " + (data.error || 'Unknown error'));
                                }
                            })
                            .catch(error => console.error("Fetch Error:", error));
                    });

                });



            </script>


            <?php return ob_get_clean();
    }


    public function customize_template_page()
    {
        ?>
            <div class="wrap">
                <h2>Customize Template</h2>

                <label for="template_select">Select Template:</label>
                <select id="template_select">
                    <option value="">-- Select a Template --</option>
                    <?php
                    $templates = get_option('awg_templates', []);
                    foreach ($templates as $template) {
                        echo "<option value='{$template['file']}'>{$template['name']}</option>";
                    }
                    ?>
                </select>

                <button id="load_template">Load Template</button>

                <h3>Template Preview & Customization</h3>
                <div id="template_editor">
                    <iframe id="template_frame" style="width:100%; height:400px; border:1px solid #ccc;"></iframe>
                </div>

                <h3>Add Sections</h3>
                <button class="add-section" data-type="text">Add Text Block</button>
                <button class="add-section" data-type="image">Add Image</button>
                <button class="add-section" data-type="table">Add Table</button>

                <button id="save_template">Save Customized Template</button>
            </div>

            <script>
                document.addEventListener("DOMContentLoaded", function () {
                    // Elements
                    const template_overlay = document.getElementById("template-overlay");
                    const openBtn = document.getElementById("select-template-btn");
                    const closeBtn = document.getElementById("close-overlay");

                    const templateSelect = document.getElementById("template_select");
                    const loadButton = document.getElementById("load_template");
                    const templateFrame = document.getElementById("template_frame");
                    const saveButton = document.getElementById("save_template");

                    const sidebarEditor = document.getElementById("editorSidebar"); // Sidebar panel
                    const elementEditor = document.getElementById("elementEditor"); // Textarea
                    const applyChangesBtn = document.getElementById("applyChanges"); // Apply Button

                    let templateDoc;
                    let selectedElement = null;

                    // Show overlay
                    openBtn.addEventListener("click", () => {
                        template_overlay.classList.remove("hidden");
                        document.body.classList.add("no-scroll");
                    });

                    // Close overlay
                    closeBtn.addEventListener("click", () => {
                        template_overlay.classList.add("hidden");
                        document.body.classList.remove("no-scroll");
                    });

                    // Load the selected template
                    loadButton.addEventListener("click", function () {
                        const selectedTemplate = templateSelect.value;
                        if (selectedTemplate) {
                            fetch(selectedTemplate)
                                .then(response => response.text())
                                .then(html => {
                                    // Load HTML into iframe
                                    templateFrame.contentDocument.open();
                                    templateFrame.contentDocument.write(html);
                                    templateFrame.contentDocument.close();
                                    templateDoc = templateFrame.contentDocument;

                                    // Enable selection after loading the template
                                    templateFrame.contentWindow.addEventListener('click', function (event) {
                                        selectedElement = event.target;
                                        event.stopPropagation(); // Prevent overlay close

                                        // Check if it's an editable element
                                        if (["H1", "H2", "H3", "P", "SPAN", "BUTTON"].includes(selectedElement.tagName)) {
                                            selectedElement.style.outline = "2px solid blue"; // Highlight
                                            sidebarEditor.classList.remove("hidden"); // Show sidebar
                                            elementEditor.value = selectedElement.innerText; // Load text
                                        }
                                    });
                                });
                        }
                    });

                    // Apply changes to selected element
                    applyChangesBtn.addEventListener("click", function () {
                        if (selectedElement) {
                            selectedElement.innerText = elementEditor.value;
                        }
                    });

                    // Save template with modified HTML
                    saveButton.addEventListener("click", function () {
                        if (templateDoc) {
                            const newHtml = templateDoc.documentElement.outerHTML;
                            fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                                method: "POST",
                                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                                body: "html=" + encodeURIComponent(newHtml),
                            })
                                .then(response => response.text())
                                .then(data => alert("Template Saved!"));
                        }
                    });
                });

            </script>



            <style>
                #template_editor {
                    border: 1px solid #ccc;
                    padding: 10px;
                    background: #f9f9f9;
                }

                button {
                    background: #0073aa;
                    color: white;
                    padding: 8px 12px;
                    border: none;
                    cursor: pointer;
                    margin-right: 5px;
                    transition: 0.3s;
                }

                button:hover {
                    background: #005e8a;
                }
            </style>
            <?php
    }



    public function view_pdf()
    {
        $pdfs = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => 'application/pdf',
            'numberposts' => 5
        ]);

        if (empty($pdfs)) {
            return "<p>No PDFs available.</p>";
        }

        $output = "<div class='container mt-4 view-pdf'>
                       <div class='card shadow-sm'>
                           <h2 class='text-center mb-3'>Available PDFs</h2>
                           <ul class='awg-pdf-list'>";
        foreach ($pdfs as $pdf) {
            $output .= "<li><a href='" . esc_url(wp_get_attachment_url($pdf->ID)) . "' target='_blank' class='awg-pdf-item'>" . esc_html($pdf->post_title) . ".pdf" . "</a></li>";
        }
        $output .= "    </ul>
                       </div>
                   </div>";

        return $output;
    }






}

// Plugin activation hook - Runs when the plugin is activated
function awg_activate_plugin()
{
    require_once plugin_dir_path(__FILE__) . 'includes/class-awg-database.php';
    $database = new AWG_Database();
    $database->create_tables(); // Call the updated method
}
register_activation_hook(__FILE__, 'awg_activate_plugin');


// Initialize the plugin
AI_Worksheet_Generator::instance();

?>