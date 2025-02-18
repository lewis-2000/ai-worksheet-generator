<?php
if (!defined('ABSPATH'))
    exit;

class AWG_User_Manager
{
    private $database;

    public function __construct()
    {
        $this->database = new AWG_Database();
    }

    public function track_action($user_id, $action, $generated = false, $downloaded = false, $paid_status = null, $end_of_payment = null)
    {
        if (!$user_id) {
            error_log("Invalid user ID provided for tracking action.");
            return;
        }

        // Log user action
        $this->database->insert_usage($user_id, $action);

        // Update user stats (if provided)
        if ($generated || $downloaded || $paid_status || $end_of_payment) {
            $this->update_user_data($user_id, $generated, $downloaded, $paid_status, $end_of_payment);
        }
    }

    public function update_user_data($user_id, $generated = false, $downloaded = false, $paid_status = null, $end_of_payment = null)
    {
        global $wpdb;
        $users_table = $wpdb->prefix . "awg_users";

        $update_data = [];
        $update_format = [];

        if ($generated) {
            $update_data['generated_pdfs'] = $wpdb->prepare("generated_pdfs + 1");
        }
        if ($downloaded) {
            $update_data['downloaded_pdfs'] = $wpdb->prepare("downloaded_pdfs + 1");
        }
        if ($paid_status !== null) {
            $update_data['paid_status'] = $paid_status;
            $update_format[] = '%s';
        }
        if ($end_of_payment !== null) {
            $update_data['end_of_payment'] = $end_of_payment;
            $update_format[] = '%s';
        }

        if (!empty($update_data)) {
            $wpdb->update($users_table, $update_data, ['user_id' => $user_id], $update_format, ['%d']);
        }

        if ($wpdb->last_error) {
            error_log("Database Update Error: " . $wpdb->last_error);
        }
    }

    public function get_user_data($user_id)
    {
        global $wpdb;
        $users_table = $wpdb->prefix . "awg_users";

        $query = $wpdb->prepare("SELECT * FROM $users_table WHERE user_id = %d", $user_id);
        $result = $wpdb->get_row($query, ARRAY_A);

        if ($wpdb->last_error) {
            error_log("Database Select Error: " . $wpdb->last_error);
            return null;
        }

        return $result;
    }
}
