<?php

$mcFormats = (isset($config['formats']) && is_array($config['formats'])) ? $config['formats'] : [];
$mcAvif = (isset($mcFormats['avif']) && is_array($mcFormats['avif'])) ? $mcFormats['avif'] : [];

$mcAvifEnabled = isset($mcAvif['enabled']) ? (bool) $mcAvif['enabled'] : false;
?>
<fieldset class="block">
    <h3>Formats</h3>
    <p>
        Choose which image formats Magic Convert generates. <b>WebP</b> is always on — its quality and
        converter settings live in the <i>Conversion</i> section below. <b>AVIF</b> is newer and compresses
        even smaller, but encodes much more slowly, so it is off by default. AVIF quality, encoding speed
        and conversion methods are configured in the <i>AVIF</i> section below.
    </p>
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row">WebP</th>
                <td>
                    <input type="checkbox" checked="checked" disabled="disabled">
                    <label>Enabled<?php echo helpIcon(
                        'WebP is the baseline output format and is always enabled. ' .
                        'Its quality, encoding and converter settings are configured in the <i>Conversion</i> section.'
                    ); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row">AVIF</th>
                <td>
                    <input
                        type="checkbox"
                        id="avif_enabled"
                        name="avif-enabled"
                        value="true"
                        <?php echo ($mcAvifEnabled ? 'checked="checked"' : ''); ?>
                    >
                    <label for="avif_enabled">Enable AVIF<?php echo helpIcon(
                        '<p>AVIF typically produces smaller files than WebP at similar quality, but encoding is ' .
                        'much slower (often several seconds per image). Generate AVIF using the bulk converter.</p>' .
                        '<p>Quality, encoding speed and conversion methods are configured in the <i>AVIF</i> section below.</p>'
                    ); ?></label>
                </td>
            </tr>
        </tbody>
    </table>
</fieldset>
