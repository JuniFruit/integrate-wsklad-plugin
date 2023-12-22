<?php


defined('ABSPATH') or die('Not allowed!');

class SettingsInit
{
    public function __construct()
    {

    }

    public function start()
    {
        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);

    }

    public function activate()
    {
        // Code to execute on activation.
        add_option(HOOK_PREFIX . 'sync', 'stopped');
        add_option(HOOK_PREFIX . 'img_queue', array());
        add_option(HOOK_PREFIX . 'acf_fields_queue', array());

    }

    public function deactivate()
    {
        delete_option(HOOK_PREFIX . 'sync');
        delete_option(HOOK_PREFIX . 'img_queue');
        delete_option(HOOK_PREFIX . 'acf_fields_queue');
    }

    public function addSettingsPage()
    {
        add_options_page(
            'Integrate WSKLAD Settings',
            'Integrate WSKLAD',
            'manage_options',
            'integrate_wsklad_plugin',
            [$this, 'renderSettingsPage']
        );
    }

    public function renderSettingsPage()
    {
        ?>
        <div class="wrap">
            <h2>Integrate WSKLAD</h2>
            <form method="post" action="options.php">
                <?php settings_fields('integrate_wsklad_plugin_options'); ?>
                <?php do_settings_sections('integrate_wsklad_plugin'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">WSKLAD Login:</th>
                        <td><input type="text" name="integrate_wsklad_login" required
                                value="<?php echo esc_attr(get_option('integrate_wsklad_login')); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">WSKLAD Password:</th>
                        <td><input type="password" name="integrate_wsklad_password" required
                                value="<?php echo esc_attr(get_option('integrate_wsklad_password')); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Pic download reroute server endpoint (optional):</th>
                        <td><input type="text" name="integrate_wsklad_reroute_server"
                                value="<?php echo esc_attr(get_option('integrate_wsklad_reroute_server')); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <td>
                            <?php if (!empty(get_option('integrate_wsklad_reroute_server'))) {
                                echo '<a target="_blank" href="' . explode("/", get_option('integrate_wsklad_reroute_server'))[0] . "//" . explode("/", get_option('integrate_wsklad_reroute_server'))[2] . '">Wake up server</a>';
                            } ?>
                        </td>

                    </tr>


                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Managing</h2>

            <h4> Sync may take a while. Do not try to stop it early. All Woocommerce draft products will be deleted. </h4>



            <form method="post" action="admin-post.php" novalidate="novalidate">
                <input type="hidden" name="action" value="integrate_wsklad_sync"></input>


                <button type="submit">
                    <?php echo get_option(HOOK_PREFIX . 'sync') == 'running' ? 'Stop sync' : 'Start sync' ?>
                </button>
            </form>

        </div>
        <?php
    }

    public function registerSettings()
    {
        register_setting(HOOK_PREFIX . 'plugin_options', HOOK_PREFIX . 'login');
        register_setting(HOOK_PREFIX . 'plugin_options', HOOK_PREFIX . 'password');
        register_setting(HOOK_PREFIX . 'plugin_options', HOOK_PREFIX . 'reroute_server');

    }


}



?>