<?php
if (!defined('ABSPATH'))
    exit;

class AWG_Usage_Tracker
{
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . "awg_usage";
    }

    public function log_usage($user_id, $action)
    {
        global $wpdb;
        $wpdb->insert(
            $this->table_name,
            ['user_id' => $user_id, 'action' => $action],
            ['%d', '%s']
        );
    }

    public function get_usage_stats()
    {
        global $wpdb;
        $results = $wpdb->get_results("SELECT action, COUNT(*) as count FROM {$this->table_name} GROUP BY action", ARRAY_A);
        return array_column($results, 'count', 'action');
    }
}
