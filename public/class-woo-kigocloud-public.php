<?php
/**
 * Public-facing functionality.
 *
 * Currently a thin placeholder. R1 checkout fields live in
 * Woo_KigoCloud_R1 (includes/class-woo-kigocloud-r1.php).
 *
 * @package Woo_KigoCloud
 */

class Woo_KigoCloud_Public
{
    /** @var string */
    private $plugin_name;

    /** @var string */
    private $version;

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
    }

    public function enqueue_styles()
    {
        // Reserved for future public stylesheet.
    }

    public function enqueue_scripts()
    {
        // Reserved for future public script.
    }
}
