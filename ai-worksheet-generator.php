<?php
/**
 * Plugin Name: AI Worksheet Generator
 * Plugin URI:  https://yourwebsite.com
 * Description: Generate AI-powered worksheets.
 * Version:     1.0.1
 * Author:      Lewis Ng'ang'a
 * Author URI:  https://yourwebsite.com
 * License:     GPL2
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
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


    public function enqueue_assets()
    {
        // Enqueue Tailwind CSS from the official CDN
        wp_enqueue_script('tailwind-config', 'https://cdn.tailwindcss.com', array(), null, false);

        // Add inline script to configure Tailwind before it's used
        wp_add_inline_script('tailwind-config', 'tailwind.config = { theme: { extend: {} } }', 'before');
        wp_enqueue_style('awg-styles', plugin_dir_url(__FILE__) . 'css/styles.css');

    }

    public function __construct()
    {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets')); // If using front-end
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_generate_ai_response', array($this, 'generate_ai_response'));
        add_action('wp_ajax_fetch_latest_pdf', array($this, 'fetch_latest_pdf'));
        add_action('wp_ajax_nopriv_generate_ai_response', array($this, 'generate_ai_response'));
        add_action('admin_init', array($this, 'handle_template_upload'));
        add_action('wp_ajax_fetch_generated_html', [$this, 'fetch_generated_html']);
        add_action('wp_ajax_convert_to_pdf', [$this, 'convert_to_pdf']);

        add_action('wp_ajax_nopriv_convert_to_pdf', [$this, 'convert_html_to_pdf']); // For non-logged-in users

        add_action('wp_ajax_save_custom_template', function () {
            if (isset($_POST['html'])) {
                $customHtml = stripslashes($_POST['html']);
                $filePath = WP_CONTENT_DIR . '/uploads/custom_template.html';
                file_put_contents($filePath, $customHtml);
                echo "Template saved!";
            }
            wp_die();
        });

        add_action('wp_ajax_nopriv_fetch_generated_html', [$this, 'fetch_generated_html']); // Allow non-logged-in users if needed
        add_shortcode('awg_generate_html', array($this, 'display_html_response'));
        add_shortcode('awg_view_pdf', array($this, 'view_pdf'));
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
                <li><a href="#" class="nav-tab nav-tab-active" data-tab="settings">Settings</a></li>
                <li><a href="#" class="nav-tab" data-tab="content">Content</a></li>
                <li><a href="#" class="nav-tab" data-tab="statistics">Statistics</a></li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content">
                <!-- Settings Tab -->
                <div id="settings" class="tab-pane active">
                    <h3>API Key Settings</h3>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('awg_settings_group');
                        do_settings_sections('awg_settings_page');
                        submit_button();
                        ?>
                    </form>
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
                            echo "<li>{$template['name']} ({$template['type']} - {$template['category']}) - 
                            <a href='{$template['file']}'>View</a> | 
                            <img src='{$template['image']}' width='50'></li>";
                        }
                        ?>
                    </ul>
                </div>

                <!-- Statistics Tab -->
                <div id="statistics" class="tab-pane">
                    <h3>Usage & Payment Statistics</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Usage</th>
                                <th>Payment Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $users = get_option('awg_users', []);
                            foreach ($users as $user) {
                                echo "<tr>
                                        <td>{$user['name']}</td>
                                        <td>{$user['usage_count']} worksheets</td>
                                        <td>{$user['payment_status']}</td>
                                      </tr>";
                            }
                            ?>
                        </tbody>
                    </table>
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

                    $templates = get_option('awg_templates', []);
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

        $prompt = sanitize_text_field($_POST['prompt']);

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

        return wp_get_attachment_url($attachment_id);
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



    public function display_html_response()
    {
        ob_start(); ?>

        <div class="hero-section max-w-5xl mx-auto flex flex-col p-6 shadow-lg rounded-lg">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
                <div>
                    <h1 class="text-4xl font-bold">Create Your Worksheet</h1>
                    <p class="text-lg text-gray-600 my-4">
                        Generate AI-powered worksheets or select from our templates to get started quickly.
                    </p>
                    <button id="select-template-btn" class="bg-gray-500 text-white px-4 py-2 rounded-lg mr-2">
                        Start with a Template
                    </button>
                    <button id="create-with-ai-btn" class="bg-blue-600 text-white px-4 py-2 rounded-lg">
                        Create with AI
                    </button>
                </div>
                <div class="hero-image-container">
                    <img src="<?php echo plugin_dir_url(__FILE__) . 'images/bgh.png'; ?>" class="w-full rounded-lg object-cover"
                        alt="Worksheet Image">
                </div>
            </div>
        </div>


        <!-- Template Overlay -->
        <div id="template-overlay"
            class="fixed flex flex-col bg-black/30 backdrop-blur-md inset-0 shadow-2xl m-auto container p-3 hidden">
            <div class="bg-white p-5 rounded-lg shadow-lg w-full max-w-5xl mx-auto relative">
                <button id="close-overlay" class="absolute top-3 right-3 text-black text-lg">✖</button>

                <h2 class="text-xl font-bold mb-3">Customize Template</h2>

                <div class="flex flex-col md:flex-row gap-4">
                    <!-- Left Panel: Customization Options -->
                    <div class="w-full md:w-1/3 p-3 bg-gray-100 rounded-lg shadow">
                        <label for="template_select" class="block font-medium">Select Template:</label>
                        <select id="template_select" class="w-full p-2 border rounded">
                            <option value="">-- Select a Template --</option>
                            <?php
                            $templates = get_option('awg_templates', []);
                            foreach ($templates as $template) {
                                echo "<option value='{$template['file']}'>" . esc_html($template['name']) . "</option>";
                            }
                            ?>
                        </select>

                        <button id="load_template" class="bg-blue-500 text-white px-4 py-2 rounded-lg w-full mt-2">
                            Load Template
                        </button>

                        <!-- New Sidebar Below Load Button -->
                        <div id="editorSidebar" class="w-full p-4 bg-white rounded-lg shadow-md mt-4 hidden">
                            <h2 class="text-lg font-semibold text-gray-700 mb-2">Edit Element</h2>

                            <label for="elementEditor" class="block font-medium text-gray-600">Text Content:</label>
                            <textarea id="elementEditor" class="w-full h-24 border p-2 rounded-md"></textarea>

                            <label for="colorPicker" class="block font-medium text-gray-600 mt-2">Text Color:</label>
                            <input type="color" id="colorPicker" class="w-full h-10 p-1 border rounded-md cursor-pointer">

                            <button id="applyChanges"
                                class="bg-green-500 text-white px-4 py-2 mt-3 w-full rounded-lg shadow hover:bg-green-600 transition">
                                Apply Changes
                            </button>
                        </div>
                    </div>

                    <!-- Right Panel: Live Preview -->
                    <div class="w-full md:w-2/3 p-3 bg-white rounded-lg shadow">
                        <h3 class="text-lg font-semibold">Template Preview</h3>
                        <div id="template_preview" class="w-full h-96 border overflow-auto bg-gray-50 p-3 rounded-md shadow">
                        </div>
                    </div>
                </div>

                <button id="save_template"
                    class="bg-blue-600 text-white px-4 py-2 rounded-lg mt-3 w-full shadow hover:bg-blue-700 transition">
                    Save Customized Template
                </button>
            </div>
        </div>

        <div id="ai-overlay" class="fixed flex-col bg-black/30 backdrop-blur-md inset-0 shadow-2xl m-auto container p-3 hidden">
            <!-- Overlay Content -->
            <div class="bg-white w-full p-4 rounded-lg shadow-lg mx-auto h-full overflow-y-auto overflow-x-hidden">
                <!-- Navigation Bar -->
                <nav class="flex justify-between items-center bg-gray-100 p-3 rounded-md">
                    <!-- Tabs -->
                    <div class="flex space-x-4">
                        <button class="tab-button active px-4 py-2 rounded-md text-gray-700 font-semibold hover:bg-gray-200"
                            data-tab="templates">
                            Premade Templates
                        </button>
                        <button class="tab-button px-4 py-2 rounded-md text-gray-700 font-semibold hover:bg-gray-200"
                            data-tab="worksheets">
                            Premade Worksheets
                        </button>
                        <button class="tab-button px-4 py-2 rounded-md text-gray-700 font-semibold hover:bg-gray-200"
                            data-tab="ai-generation">
                            AI Generation
                        </button>
                    </div>

                    <!-- Google Login -->
                    <div id="google-login" class="flex items-center space-x-2 cursor-pointer">
                        <!-- <img src="google-icon.png" alt="Google" class="w-6 h-6">
                        <button class="text-blue-600 font-semibold">Sign in with Google</button> -->
                        <a href="http://localhost/mywordpress/wp-login.php?loginSocial=google" data-plugin="nsl"
                            data-action="connect" data-redirect="current" data-provider="google" data-popupwidth="600"
                            data-popupheight="600">
                            <img src="Image url" alt="" />
                        </a>
                    </div>

                    <!-- Close Button -->
                    <button id="close-ai-overlay" class="text-gray-600 text-2xl">&times;</button>
                </nav>

                <!-- Dynamic Content Area -->
                <div id="tab-content" class="bg-white p-4 rounded-md mt-2 h-full overflow-y-auto w-full">
                    <!-- Premade Templates Tab -->
                    <div id="templates" class="tab-content">
                        <div class="flex gap-4">
                            <!-- Grid of Templates -->
                            <div id="template-grid" class="grid grid-cols-3 h-auto gap-4 w-2/3">
                                <!-- Templates are injected here by JavaScript -->
                            </div>

                            <!-- Preview Section -->
                            <div class="w-1/3 h-auto bg-gray-100 p-4 rounded-md">
                                <h3 class="text-lg font-semibold mb-2">Template Preview</h3>
                                <img id="template-preview"
                                    src="<?php echo plugin_dir_url(__FILE__) . 'images/placeholder.png'; ?>" alt="Preview"
                                    class="w-full rounded-md border">
                                <p id="template-name" class="text-center mt-2 text-gray-700">Select a template</p>
                            </div>
                        </div>
                    </div>

                    <!-- Premade Worksheets Tab -->
                    <div id="worksheets" class="tab-content hidden">
                        <p class="text-gray-700">Worksheets content here...</p>
                        <div class="wp-block-algori-pdf-viewer-block-algori-pdf-viewer"><iframe
                                class="wp-block-algori-pdf-viewer-block-algori-pdf-viewer-iframe"
                                src="http://localhost/mywordpress/wp-content/plugins/algori-pdf-viewer/dist/web/viewer.html?file=http%3A%2F%2Flocalhost%2Fmywordpress%2Fwp-content%2Fuploads%2F2025%2F02%2F1739602797.pdf"
                                style="width:600px;height:300px"></iframe></div>
                    </div>

                    <!-- AI Generation Tab -->
                    <div id="ai-generation" class="tab-content hidden overflow-auto h-full">
                        <div
                            class="ai-content flex flex-col md:flex-row gap-4 p-4 bg-gray-100 rounded-lg shadow-lg h-full md:overflow-y-auto">

                            <!-- AI Input Section -->
                            <div class="ai-input-section w-full md:w-1/2 bg-white p-4 rounded-lg shadow-md flex flex-col">
                                <h2 class="text-lg font-semibold mb-3">Enter Your Prompt</h2>

                                <!-- Guidelines Section -->
                                <div class="bg-blue-50 border-l-4 border-blue-500 p-3 rounded-md mb-3">
                                    <h3 class="text-blue-700 font-semibold">Guidelines:</h3>
                                    <ul class="list-disc pl-5 text-sm text-gray-700">
                                        <li>Specify the topic clearly.</li>
                                        <li>Define the number and type of questions.</li>
                                        <li>Mention difficulty level (Beginner, Intermediate, Advanced).</li>
                                        <li>Request additional elements like solutions or explanations.</li>
                                    </ul>
                                </div>

                                <textarea id="awg-prompt"
                                    class="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 resize-none min-h-[130px] flex-1"
                                    placeholder="Example: \n- Topic: College-Level Grammar\n- Questions: 5 sentence correction + 5 fill-in-the-blanks\n- Difficulty: Advanced\n- Include an explanation for answers"></textarea>
                                <button id="awg-generate-btn"
                                    class="w-full mt-2 bg-blue-500 text-white py-2 px-4 rounded-md hover:bg-blue-600 transition">
                                    Generate Worksheet
                                </button>
                            </div>

                            <!-- AI Preview Section -->
                            <div
                                class="ai-preview-section w-full md:w-1/2 bg-white p-4 rounded-lg shadow-md relative flex flex-col">
                                <h2 class="text-lg font-semibold mb-3">Preview</h2>

                                <!-- Download & Print Strip -->
                                <div id="awg-action-strip"
                                    class="hidden  justify-between items-center bg-gray-200 p-2 rounded-md mb-3">
                                    <span class="text-gray-700 font-medium">Download or Print:</span>
                                    <div class="flex gap-2">
                                        <button id="awg-print-btn"
                                            class="bg-blue-500 text-white px-3 py-1 rounded-md hover:bg-blue-600">
                                            Print
                                        </button>
                                        <a id="awg-download-pdf"
                                            class="bg-green-500 text-white px-3 py-1 rounded-md hover:bg-green-600 transition"
                                            href="#" target="_blank" download>
                                            Download PDF
                                        </a>
                                    </div>
                                </div>

                                <!-- Live Preview with Loading -->
                                <div id="awg-html-output"
                                    class="preview-box border border-gray-300 p-3 rounded-md flex-1 bg-gray-50 flex justify-center items-center relative overflow-auto">
                                    <img id="awg-placeholder"
                                        src="<?php echo plugin_dir_url(__FILE__) . 'images/placeholder.png'; ?>"
                                        alt="Worksheet Preview Placeholder" class="max-w-full h-auto">
                                    <div id="awg-loading" class="absolute hidden">
                                        <div
                                            class="animate-spin inline-block w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full">
                                        </div>
                                        <p class="mt-2 text-gray-600">Generating worksheet...</p>
                                    </div>
                                </div>

                                <!-- PDF Container -->
                                <div id="awg-pdf-container" class="mt-3 hidden">
                                    <h3 class="text-center text-md font-semibold">Generated Worksheet PDF</h3>
                                    <iframe id="awg-pdf-frame" class="w-full h-[500px] border-none"></iframe>
                                </div>
                            </div>
                        </div>
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

                // Default open first tab
                document.querySelector('.tab-button').click();

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
                        card.className = "template-card bg-white p-3 h-fit rounded-lg shadow hover:shadow-lg transition-all cursor-pointer";
                        card.setAttribute('data-template', template.name);

                        card.innerHTML = `<img src="${template.image}" alt="${template.name}" class="w-full h-32 object-cover rounded-md border border-gray-300">
                                            <p class="text-center mt-2 font-medium text-gray-800">${template.name.replace('template-', 'Template ').replace('premadeworksheet-', 'Worksheet ')}</p> `;

                        templateGrid.appendChild(card);
                    });
                }

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
                                console.log("JSON data: ", jsonData);
                                document.getElementById("awg-html-output").innerHTML = jsonData.data.html;
                                console.log("html: ", jsonData.html);
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

                // Template Overlay Elements
                const templateOverlay = document.getElementById("template-overlay");
                const openBtn = document.getElementById("select-template-btn");
                const closeBtn = document.getElementById("close-overlay");

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

                // 🚀 Open Overlay
                openBtn.addEventListener("click", () => {
                    templateOverlay.classList.remove("hidden");
                    body.classList.add("no-scroll");
                });

                // ❌ Close Overlay
                closeBtn.addEventListener("click", () => {
                    templateOverlay.classList.add("hidden");
                    body.classList.remove("no-scroll");
                });

                // 🎯 Load Template into Preview
                loadButton.addEventListener("click", function () {
                    const selectedTemplate = templateSelect.value;

                    if (selectedTemplate) {
                        fetch(selectedTemplate)
                            .then(response => response.text())
                            .then(data => {
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

// Initialize the plugin
new AI_Worksheet_Generator();
?>