<?php
if (!defined('ABSPATH'))
    exit;

class AWG_Database
{
    private $users_table;
    private $usage_table;

    public function __construct()
    {
        global $wpdb;
        $this->users_table = $wpdb->prefix . "awg_users";
        $this->usage_table = $wpdb->prefix . "awg_usage";
    }

    public function create_tables()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        // Users Table (Tracks generated PDFs, downloads, and payment info)
        $sql_users = "CREATE TABLE IF NOT EXISTS {$this->users_table} (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            generated_pdfs INT DEFAULT 0,
            downloaded_pdfs INT DEFAULT 0,
            paid_status ENUM('free', 'subscribed', 'credits') DEFAULT 'free',
            end_of_payment DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES {$wpdb->prefix}users(ID) ON DELETE CASCADE
        ) $charset_collate;";

        // Usage Table (Tracks user actions separately)
        $sql_usage = "CREATE TABLE IF NOT EXISTS {$this->usage_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES {$wpdb->prefix}users(ID) ON DELETE CASCADE
        ) $charset_collate;";

        dbDelta($sql_users);
        dbDelta($sql_usage);
    }

    public function insert_usage($user_id, $action)
    {
        global $wpdb;
        $wpdb->insert(
            $this->usage_table,
            ['user_id' => $user_id, 'action' => $action, 'created_at' => current_time('mysql')],
            ['%d', '%s', '%s']
        );

        if ($wpdb->last_error) {
            error_log("Database Insert Error: " . $wpdb->last_error);
        }
    }
}
