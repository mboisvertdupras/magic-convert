<?php

// Formats panel (schema v2 / Phase 2.2)
// -------------------------------------
// Compact, zero-config panel:
//   - WebP is shown as the always-on baseline (its detailed quality/converter settings live in
//     the existing Conversion section, so nothing is duplicated here).
//   - AVIF is a single enable checkbox. Only when enabled, two tucked-away advanced knobs appear:
//     Quality (default 30) and Encoding speed (0-10, default 6). No other AVIF knobs by design.
//
// Defaults come from Config::getDefaultFormats() via loadConfigAndFix(), so $config['formats'] is
// always populated; we still read defensively.

$mcFormats = (isset($config['formats']) && is_array($config['formats'])) ? $config['formats'] : [];
$mcAvif = (isset($mcFormats['avif']) && is_array($mcFormats['avif'])) ? $mcFormats['avif'] : [];

$mcAvifEnabled = isset($mcAvif['enabled']) ? (bool) $mcAvif['enabled'] : false;
$mcAvifQuality = isset($mcAvif['quality']) ? intval($mcAvif['quality']) : 30;
$mcAvifSpeed = isset($mcAvif['speed']) ? intval($mcAvif['speed']) : 6;
?>
<fieldset class="block">
    <h3>Formats</h3>
    <p>
        Choose which image formats Magic Convert generates. <b>WebP</b> is always on — its quality and
        converter settings live in the <i>Conversion</i> section below. <b>AVIF</b> is newer and compresses
        even smaller, but encodes much more slowly, so it is off by default.
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
                        'much slower (often several seconds per image). Generate AVIF using the bulk converter.</p>'
                    ); ?></label>

                    <div id="avif_options_div" class="toggler effect-opacity" style="margin-top:12px;">
                        <div style="margin:6px 0;">
                            <label for="avif_quality">
                                Quality
                                <?php echo helpIcon('AVIF quality 30 looks similar to JPEG quality 75'); ?>
                            </label>
                            <input
                                type="number"
                                min="0"
                                max="100"
                                step="1"
                                id="avif_quality"
                                name="avif-quality"
                                value="<?php echo esc_attr($mcAvifQuality); ?>"
                                style="width:64px; margin-left:6px;"
                            >
                        </div>
                        <div style="margin:6px 0;">
                            <label for="avif_speed">
                                Encoding speed
                                <?php echo helpIcon('Lower = smaller files but much slower. 6 is a good balance'); ?>
                            </label>
                            <input
                                type="number"
                                min="0"
                                max="10"
                                step="1"
                                id="avif_speed"
                                name="avif-speed"
                                value="<?php echo esc_attr($mcAvifSpeed); ?>"
                                style="width:64px; margin-left:6px;"
                            >
                        </div>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</fieldset>
