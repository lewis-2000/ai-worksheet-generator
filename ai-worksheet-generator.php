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
        wp_enqueue_style('bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css');
        wp_enqueue_script('bootstrap-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js', array('jquery'), null, true);
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

            <form method="post" action="options.php">
                <?php
                settings_fields('awg_settings_group');
                do_settings_sections('awg_settings_page');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings()
    {
        register_setting('awg_settings_group', 'openai_api_key');
        add_settings_section('awg_main_section', 'API Settings', null, 'awg_settings_page');
        add_settings_field('openai_api_key', 'OpenAI API Key', array($this, 'api_key_field'), 'awg_settings_page', 'awg_main_section');
    }

    public function api_key_field()
    {
        $api_key = get_option('openai_api_key', '');
        echo "<input type='text' name='openai_api_key' value='" . esc_attr($api_key) . "' style='width: 100%;'>";
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
        <div class="hero-section container mt-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="display-4">Create Your Worksheet</h1>
                    <p class="lead">Generate AI-powered worksheets or select from our templates to get started quickly.</p>
                    <button id="select-template-btn" class="btn btn-secondary me-2 function-button">Select Template</button>
                    <button id="create-with-ai-btn" class="btn btn-primary function-button">Create with AI</button>
                </div>
                <div class="col-md-6 hero-image-container">
                    <img src="<?php echo plugin_dir_url(__FILE__) . 'images/bgh.png'; ?>" class="hero-image"
                        alt="Worksheet Image">
                </div>
            </div>
        </div>

        <!-- Full-Screen AI Overlay -->
        <div id="ai-overlay" class="ai-overlay container">
            <div class="ai-top-bar">
                <span class="ai-logo">AI Worksheet Generator</span>
                <div class="auth-links">
                    <a href="#" class="login-link">Login</a>
                    <a href="#" class="signup-link">Sign Up</a>
                    <span class="close-btn" id="close-ai-overlay">&times;</span>
                </div>
            </div>
            <div class="ai-content">
                <div class="ai-input-section">
                    <h2>Enter Your Prompt</h2>
                    <input type="text" id="awg-prompt" class="form-control" placeholder="Enter worksheet topic...">
                    <button id="awg-generate-btn" class="btn btn-primary w-100 mt-2">Generate Worksheet</button>


                </div>
                <div class="ai-preview-section">
                    <h2>Live Preview</h2>
                    <div id="awg-html-output" class="preview-box"></div>
                    <div id="awg-pdf-container" class="mt-4" style="display: none;">
                        <h3 class="text-center">Generated Worksheet PDF</h3>
                        <iframe id="awg-pdf-frame" class="w-100" style="height: 500px; border: none;">
                            <div id="awg-loading" class="text-center mt-3" style="display: none;">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Generating...</span>
                                </div>
                                <p class="mt-2">Generating worksheet, please wait...</p>
                            </div>
                        </iframe>
                        <a id="awg-download-pdf" class="btn btn-success mt-3 w-100" href="#" target="_blank" download>Download
                            PDF</a>
                    </div>
                </div>
            </div>
        </div>

        <script>
            document.getElementById("create-with-ai-btn").addEventListener("click", function () {
                document.getElementById("ai-overlay").style.display = "flex";
            });

            document.getElementById("close-ai-overlay").addEventListener("click", function () {
                document.getElementById("ai-overlay").style.display = "none";
            });

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
                            document.getElementById("awg-html-output").innerHTML = jsonData.html;
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