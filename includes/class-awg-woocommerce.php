<?php
if (!defined('ABSPATH'))
    exit;

class AWG_Helper
{
    public static function sanitize_input($input)
    {
        return sanitize_text_field($input);
    }

    public static function format_date($date)
    {
        return date('Y-m-d H:i:s', strtotime($date));
    }
}
