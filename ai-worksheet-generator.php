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

            <!-- API Key Section -->
            <form method="post" action="options.php">
                <?php
                settings_fields('awg_settings_group');
                do_settings_sections('awg_settings_page');
                submit_button();
                ?>
            </form>

            <!-- Template & Premade Worksheet Upload Section -->
            <h3>Manage Templates & Premade Worksheets</h3>
            <form method="post" enctype="multipart/form-data">
                <label for="template_name">Name:</label>
                <input type="text" name="template_name" required>

                <label for="template_type">Type:</label>
                <select name="template_type">
                    <option value="template">Template</option>
                    <option value="premadeworksheet">Premade Worksheet</option>
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
        <?php
    }

    public function register_settings()
    {
        register_setting('awg_settings_group', 'openai_api_key');
        register_setting('awg_settings_group', 'awg_templates'); // Store templates
        add_settings_section('awg_main_section', 'API Settings', null, 'awg_settings_page');
        add_settings_field('openai_api_key', 'OpenAI API Key', array($this, 'api_key_field'), 'awg_settings_page', 'awg_main_section');
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
            wp_send_json_error(['message' => 'OpenAI API key is not set']);
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
            'timeout' => 30 // Increase timeout to 30 seconds
        ));




        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'API request failed: ' . $response->get_error_message()]);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!$body || !isset($body['candidates'][0]['content']['parts'][0]['text'])) {
            wp_send_json_error(['message' => 'Invalid response from Gemini. Response: ' . print_r($body, true)]);
        }

        $generated_html = wp_kses_post(nl2br($body['candidates'][0]['content']['parts'][0]['text']));

        $pdf_url = $this->generate_pdf($generated_html);

        wp_send_json_success(['html' => $generated_html, 'pdf_url' => $pdf_url]);
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

        <div class="hero-section max-w-5xl mx-auto p-6 shadow-lg rounded-lg">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
                <div>
                    <h1 class="text-4xl font-bold">Create Your Worksheet</h1>
                    <p class="text-lg text-gray-600 my-4">
                        Generate AI-powered worksheets or select from our templates to get started quickly.
                    </p>
                    <button id="select-template-btn" class="bg-gray-500 text-white px-4 py-2 rounded-lg mr-2">
                        Select Template
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

        <div id="ai-overlay" class="fixed flex-col bg-black/30 backdrop-blur-md inset-0 shadow-2xl m-auto container p-3 hidden">
            <!-- Overlay Content -->
            <div class="bg-white w-full p-4 rounded-lg shadow-lg mx-auto h-full overflow-hidden">
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
                        <img src="google-icon.png" alt="Google" class="w-6 h-6">
                        <button class="text-blue-600 font-semibold">Sign in with Google</button>
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
                    </div>

                    <!-- AI Generation Tab -->
                    <div id="ai-generation" class="tab-content hidden">
                        <p class="text-gray-700">AI Generation content here...</p>
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
            });
        </script>

        <?php return ob_get_clean();
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