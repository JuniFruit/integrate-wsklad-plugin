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
        add_option(HOOK_PREFIX . 'product_attributes_queue', array());
        add_option(HOOK_PREFIX . 'product_variations_queue', array());
        add_option(HOOK_PREFIX . 'acf_fields_queue', array());
        add_option(HOOK_PREFIX . 'debug_log', array());
        add_option(HOOK_PREFIX . 'product_batch', 50);
        add_option(HOOK_PREFIX . 'sync_step', NULL);
    }

    public function deactivate()
    {
        delete_option(HOOK_PREFIX . 'sync');
        delete_option(HOOK_PREFIX . 'img_queue');
        delete_option(HOOK_PREFIX . 'product_variations_queue');
        delete_option(HOOK_PREFIX . 'product_attributes_queue');
        delete_option(HOOK_PREFIX . 'acf_fields_queue');
        delete_option(HOOK_PREFIX . 'debug_log');
        delete_option(HOOK_PREFIX . 'product_batch');
        delete_option(HOOK_PREFIX . 'sync_step');
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
                        <td><input type="text" name="integrate_wsklad_login" required value="<?php echo esc_attr(get_option('integrate_wsklad_login')); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">WSKLAD Password:</th>
                        <td><input type="password" name="integrate_wsklad_password" required value="<?php echo esc_attr(get_option('integrate_wsklad_password')); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Pic download reroute server endpoint (optional):</th>
                        <td><input type="text" name="integrate_wsklad_reroute_server" value="<?php echo esc_attr(get_option('integrate_wsklad_reroute_server')); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <td>
                            <?php if (!empty(get_option('integrate_wsklad_reroute_server'))) {
                                echo '<a target="_blank" href="' . explode("/", get_option('integrate_wsklad_reroute_server'))[0] . "//" . explode("/", get_option('integrate_wsklad_reroute_server'))[2] . '">Wake up server</a>';
                            } ?>
                        </td>

                    </tr>
                    <tr valign="top">
                        <th scope="row">Products per hook:</th>
                        <td><input type="number" name="integrate_wsklad_product_batch" min="0" value="<?php echo esc_attr(get_option('integrate_wsklad_product_batch')); ?>" /></td>
                    </tr>


                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Managing</h2>

            <h4> Sync may take a while. Do not try to stop it early. To update debug log restart the page </h4>



            <form onsubmit="document.getElementById('syncButton').setAttribute('disabled', true); return true;" method="post" action="admin-post.php" novalidate="novalidate">
                <input type="hidden" name="action" value="integrate_wsklad_sync"></input>
                <button  id="syncButton" type="submit">
                    <?php echo get_option(HOOK_PREFIX . 'sync') == 'running' ? 'Stop sync' : 'Start sync' ?>
                </button>
            </form>
            <form onsubmit="document.getElementById('continueButton').setAttribute('disabled', true); return true;" method="post" action="admin-post.php" novalidate="novalidate">
                <input type="hidden" name="action" value="integrate_wsklad_continue_sync"></input>
                <button id="continueButton" style="margin-top:.85rem;" type="submit" <?php echo get_option(HOOK_PREFIX . 'sync') === 'running' || !get_option(HOOK_PREFIX . 'sync_step') ? "disabled" : "" ?>>
                    Continue sync
                </button>
            </form>

            <h2>Debug Log</h2>

            <div style="width:60rem;height:40rem;overflow-y:scroll;overflow-x:scroll;border:2px solid rgba(0,0,0,0.4);">
                <pre>
                    <?php $debug_log = get_option(HOOK_PREFIX . 'debug_log');
                    if (!$debug_log || gettype($debug_log) !== 'array') {
                        echo 'No messages in log';
                        return;
                    }
                    foreach ($debug_log as $c_key => $c_value) {
                        echo $c_value . "\n";
                    }
                    ?> 
                </pre>
            </div>

        </div>
<?php
    }

    public function registerSettings()
    {
        register_setting(HOOK_PREFIX . 'plugin_options', HOOK_PREFIX . 'login');
        register_setting(HOOK_PREFIX . 'plugin_options', HOOK_PREFIX . 'password');
        register_setting(HOOK_PREFIX . 'plugin_options', HOOK_PREFIX . 'reroute_server');
        register_setting(HOOK_PREFIX . 'plugin_options', HOOK_PREFIX . 'product_batch');
    }
}



?>