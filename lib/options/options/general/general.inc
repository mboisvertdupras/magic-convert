<fieldset class="block">
    <button onclick="MagicConvert.SelfTest.openPopup('allInfo')" class="button button-secondary" type="button" style="position: absolute; right: 15px; top:15px">
        System info
    </button>
    <h3>General</h3>

    <!--<div><i>The options here affects the rules created in the .htaccess. <?php echo helpIcon('And so does some other options. If "Redirect directly to converted image" is set, the "Destination folder" and "File Extension" and "Caching" options will be used'); ?></i></div>-->
    <table class="form-table">
        <tbody>
            <?php
            if (($config['operation-mode'] != 'no-conversion')) {
                include_once 'scope.inc';
            }
            include_once 'image-types.inc';

            if (($config['operation-mode'] != 'no-conversion')) {
                include_once 'destination-folder.inc';
            }
            include_once 'destination-extension.inc';

            if (($config['operation-mode'] != 'no-conversion')) {
                include_once 'destination-structure.inc';
            }

            // TODO:
            // Perhaps also show cache control in cdn-friendly mode?
            if (($config['operation-mode'] == 'tweaked') || ($config['operation-mode'] == 'varied-image-responses') || ($config['operation-mode'] == 'cdn-friendly')) {
                include_once 'cache-control.inc';
            }

            include_once 'prevent-using-webps-larger-than-original.inc';

            // Cross-format behaviour settings (moved here from the WebP "Conversion"
            // section). These are NOT WebP-specific — they apply to every enabled output
            // format: "convert on upload" generates each enabled format (WebP + AVIF),
            // bulk convert iterates them, and logging is written per-format. Hidden in
            // no-conversion mode, exactly as before (they are meaningless without conversion).
            if (($config['operation-mode'] != 'no-conversion')) {
                include_once 'convert-on-upload.inc';
                include_once 'logging.inc';
                include_once 'bulk-convert.inc';
            }

            ?>
        </tbody>
    </table>
</fieldset>
