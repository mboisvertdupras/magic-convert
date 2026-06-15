<?php

$mcFormats = (isset($config['formats']) && is_array($config['formats'])) ? $config['formats'] : [];
$mcAvif = (isset($mcFormats['avif']) && is_array($mcFormats['avif'])) ? $mcFormats['avif'] : [];

$mcAvifQuality = isset($mcAvif['quality']) ? intval($mcAvif['quality']) : 30;
$mcAvifSpeed = isset($mcAvif['speed']) ? intval($mcAvif['speed']) : 6;
?>
<fieldset class="block">
    <button onclick="MagicConvert.SelfTest.openPopup('avifInfo')" class="button button-secondary" type="button" style="position: absolute; right: 15px; top:15px">
        System AVIF capabilities
    </button>
    <h3>AVIF</h3>
    <p>
        Settings for AVIF output. These apply when <b>AVIF</b> is enabled in the <i>Formats</i> section
        above. AVIF typically produces smaller files than WebP at similar quality, but encodes much
        more slowly — generate AVIF using the bulk converter.
    </p>
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row"><label for="avif_quality">Quality</label></th>
                <td>
                    <input
                        type="number"
                        min="0"
                        max="100"
                        step="1"
                        id="avif_quality"
                        name="avif-quality"
                        value="<?php echo esc_attr($mcAvifQuality); ?>"
                        style="width:64px;"
                    >
                    <?php echo helpIcon('AVIF quality 30 looks similar to JPEG quality 75'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="avif_speed">Encoding speed</label></th>
                <td>
                    <input
                        type="number"
                        min="0"
                        max="10"
                        step="1"
                        id="avif_speed"
                        name="avif-speed"
                        value="<?php echo esc_attr($mcAvifSpeed); ?>"
                        style="width:64px;"
                    >
                    <?php echo helpIcon('Lower = smaller files but much slower. 6 is a good balance'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    Conversion method
                    <?php
                    echo helpIcon(
                        'Drag to reorder. The conversion method on top is tried first; should it fail, ' .
                        'the next is used, and so on. <b>Deactivate</b> a method to skip it for AVIF ' .
                        '(this is independent of the WebP conversion methods above). Use <b>test</b> to ' .
                        'encode a sample image with that method and see the result.'
                    ); ?>
                </th>
                <td>
                    <input type='text' name='avif-converters' value='' style='visibility:hidden; height:0' />
                    <ul id="avif-converters" style="margin-top: -13px"></ul>
                    <div id="avif_tc_popup" style="display:none;">
                        <div id="avif_tc_content"></div>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</fieldset>
