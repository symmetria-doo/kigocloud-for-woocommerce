<?php
/**
 * Fired during plugin deactivation.
 *
 * Intentionally minimal: we only clear the post-update review notice
 * flag. User settings and the stored kigocloud_version are kept so
 * the next activation does not look like a fresh install (which
 * would re-trigger the pre-1.7.0 migrator and clobber per-gateway
 * settings the admin tuned by hand).
 *
 * Permanent cleanup belongs in uninstall.php, which runs only when
 * the admin deletes the plugin.
 *
 * @package Woo_KigoCloud
 */

class Woo_KigoCloud_Deactivator
{
    public static function deactivate()
    {
        delete_option('kigocloud_show_migration_notice');
    }
}
