<?php

use \MagicConvert\NginxPanel;
use \MagicConvert\NginxRules;
use \MagicConvert\NginxDownload;
use \MagicConvert\PlatformInfo;
use \MagicConvert\State;

if (!current_user_can('manage_options')) {
    return;
}

$mc_isNginx = PlatformInfo::isNginx();

$mc_maps   = NginxRules::generateMapsFileFromPaths($config);
$mc_server = NginxRules::generateServerFileFromPaths($config);
$mc_single = NginxRules::generateSingleFileFromPaths($config);

$mc_state = State::getState('nginx-rules', null);
$mc_fingerprint = is_array($mc_state) && isset($mc_state['fingerprint']) ? $mc_state['fingerprint'] : '';
$mc_generatedAt = is_array($mc_state) && isset($mc_state['generated-at']) ? $mc_state['generated-at'] : '';
$mc_shortFp = NginxPanel::shortFingerprint($mc_fingerprint);

$mc_downloadNonce = wp_create_nonce(NginxDownload::NONCE_ACTION);
$mc_downloadUrl = function ($artifactKey) use ($mc_downloadNonce) {
    return add_query_arg(
        [
            'action'   => 'magicconvert_nginx_download',
            'artifact' => $artifactKey,
            '_wpnonce' => $mc_downloadNonce,
        ],
        admin_url('admin-post.php')
    );
};

$mc_renderArtifactCard = function ($title, $whereGuidance, $textareaId, $body, $downloadKey)
        use ($mc_downloadUrl) {
    echo '<div class="magicconvert-nginx-card" style="border:1px solid #ccd0d4; border-radius:4px; '
        . 'padding:12px 16px; margin:0 0 18px 0; background:#fff;">';
    echo '<h4 style="margin-top:0;">' . esc_html($title) . '</h4>';
    echo '<p style="margin-top:0; color:#50575e;">' . wp_kses_post($whereGuidance) . '</p>';
    echo '<textarea id="' . esc_attr($textareaId) . '" readonly rows="12" spellcheck="false" '
        . 'onclick="this.select();" '
        . 'style="width:100%; font-family:Menlo,Consolas,monospace; font-size:12px; '
        . 'white-space:pre; overflow:auto; background:#f6f7f7;">'
        . esc_textarea($body) . '</textarea>';
    echo '<p style="margin-bottom:0;">';
    echo '<button type="button" class="button" '
        . 'onclick="magicconvertCopyNginx(\'' . esc_js($textareaId) . '\', this)">Copy</button> ';
    echo '<a class="button button-primary" href="' . esc_url($mc_downloadUrl($downloadKey)) . '">Download</a>';
    echo '</p>';
    echo '</div>';
};

$mc_renderPanelBody = function ()
        use ($mc_maps, $mc_server, $mc_single, $mc_shortFp, $mc_generatedAt,
             $mc_renderArtifactCard) {
    ?>
    <p>
        nginx does <strong>not read <i>.htaccess</i></strong>, so Magic Convert cannot install its
        rewrite rules for you the way it does on Apache. Instead it generates ready-to-include nginx
        configuration below, kept in sync with your settings. Pick the setup that matches your host,
        then paste or download the file(s) and reload nginx.
    </p>

    <p style="color:#50575e;">
        <strong>Rules version:</strong> <code><?php echo esc_html($mc_shortFp); ?></code>
        <?php if ($mc_generatedAt !== '') : ?>
            (generated <?php echo esc_html($mc_generatedAt); ?>)
        <?php endif; ?>
    </p>

    <p>
        <strong>Installed the rules and reloaded nginx?</strong>
        Run a live test to confirm nginx is actually serving webp/avif by Accept, that the
        <code>Vary: Accept</code> header is present, and that the installed rules match your current settings.
        <button type="button" class="button button-secondary" style="margin-left:8px;"
            onclick="MagicConvert.SelfTest.openPopup('nginxLiveTest')">Run live test</button>
    </p>

    <h4>Choose your setup</h4>

    <?php
    echo '<h4 style="margin-bottom:4px;">Standard (two files)</h4>';
    echo '<p style="margin-top:0; color:#50575e;">Recommended. One file declares the '
        . 'Accept-negotiation <code>map</code>s (http context); the other holds the location '
        . 'blocks (server context).</p>';

    $mc_renderArtifactCard(
        'magic-convert-maps.conf',
        'Goes in the <strong>http context</strong> — e.g. <code>/etc/nginx/conf.d/magic-convert-maps.conf</code> '
            . '(files in <code>conf.d/</code> are included inside <code>http { }</code> automatically).',
        'magicconvert-nginx-maps',
        $mc_maps,
        'maps'
    );
    $mc_renderArtifactCard(
        'magic-convert-server.conf',
        'Goes in the <strong>server context</strong> — add <code>include /etc/nginx/snippets/magic-convert-server.conf;</code> '
            . 'inside your site\'s <code>server { }</code> block (usually under <code>/etc/nginx/sites-available/</code>). '
            . 'Requires <code>magic-convert-maps.conf</code> to be included in the http context.',
        'magicconvert-nginx-server',
        $mc_server,
        'server'
    );

    echo '<h4 style="margin-bottom:4px;">Single file (control panels)</h4>';
    echo '<p style="margin-top:0; color:#50575e;">For GridPane / RunCloud / Plesk-style hosts whose '
        . 'per-site include slot only accepts <strong>server-context</strong> directives (no http-context '
        . '<code>map</code>s). One self-contained file — paste it into the per-site nginx include slot.</p>';

    $mc_renderArtifactCard(
        'magic-convert.conf',
        'Goes in your panel\'s <strong>per-site nginx include slot</strong> (server context). No separate '
            . 'maps file is needed — negotiation is inlined.',
        'magicconvert-nginx-single',
        $mc_single,
        'single'
    );
    ?>

    <div class="magicconvert-nginx-warning notice notice-warning inline" style="margin:0 0 18px 0; padding:8px 12px;">
        <p style="margin:0;">
            <strong>Heads up:</strong> these blocks use <code>add_header Vary Accept;</code> inside a
            <code>location</code>. In nginx, <code>add_header</code> in a location <em>replaces</em> any
            headers inherited from the surrounding server/http context. If you rely on inherited headers
            (HSTS, CSP, X-Frame-Options&hellip;), re-declare them inside these location blocks, or they
            will be dropped for image responses.
        </p>
    </div>

    <h4>Install instructions</h4>
    <div class="magicconvert-nginx-instructions">
        <details open>
            <summary><strong>Vanilla VPS</strong> (you manage <code>/etc/nginx</code> directly)</summary>
            <ol>
                <li>Save <code>magic-convert-maps.conf</code> to <code>/etc/nginx/conf.d/</code>.</li>
                <li>Save <code>magic-convert-server.conf</code> to <code>/etc/nginx/snippets/</code> and add
                    <code>include /etc/nginx/snippets/magic-convert-server.conf;</code> inside your site's
                    <code>server { }</code> block.</li>
                <li>Test: <code>nginx -t</code></li>
                <li>Reload: <code>systemctl reload nginx</code></li>
            </ol>
        </details>
        <details>
            <summary><strong>Docker (compose)</strong> &mdash; first-class</summary>
            <ol>
                <li>Save the file(s) next to your <code>docker-compose.yml</code>.</li>
                <li>Mount them into the nginx container, e.g.
                    <code>./magic-convert-server.conf:/etc/nginx/snippets/magic-convert-server.conf:ro</code>
                    (and the maps file into <code>/etc/nginx/conf.d/</code>).</li>
                <li>Reference the snippet from your server block, then reload:
                    <code>docker compose exec nginx nginx -t &amp;&amp; docker compose exec nginx nginx -s reload</code>.</li>
            </ol>
        </details>
        <details>
            <summary><strong>Control panel</strong> (GridPane / RunCloud / Plesk)</summary>
            <ol>
                <li>Use the <strong>Single file</strong> artifact above.</li>
                <li>Paste it into your panel's per-site nginx include slot (the field that accepts custom
                    server-context directives).</li>
                <li>Apply / reload nginx using the panel's control.</li>
            </ol>
        </details>
        <details>
            <summary><strong>No nginx config access?</strong></summary>
            <p>
                If your host does not let you add nginx includes at all, you cannot use redirect-based
                serving. Use <strong>Alter HTML &rarr; picture tags</strong> instead: Magic Convert rewrites
                your HTML to offer webp/avif via <code>&lt;picture&gt;</code> elements, which needs no server
                rules. See the <em>Alter HTML</em> section further down this page.
            </p>
        </details>
    </div>
    <?php
};

?>
<fieldset class="block" id="magicconvert-nginx-panel">
<?php if ($mc_isNginx) : ?>
    <h2>nginx configuration</h2>
    <?php $mc_renderPanelBody(); ?>
<?php else : ?>
    <details>
        <summary><strong>Running nginx?</strong> &mdash; generate native nginx rules</summary>
        <p style="color:#50575e;">
            This server does not look like nginx, so Magic Convert installs <i>.htaccess</i> rules for you.
            If nginx serves your images (e.g. a reverse proxy in front of PHP), generate native nginx rules here.
        </p>
        <?php $mc_renderPanelBody(); ?>
    </details>
<?php endif; ?>
</fieldset>

<script>
function magicconvertCopyNginx(textareaId, btn) {
    var el = document.getElementById(textareaId);
    if (!el) { return; }
    var done = function () {
        var orig = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(function () { btn.textContent = orig; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(el.value).then(done, function () {
            el.select(); document.execCommand('copy'); done();
        });
    } else {
        el.select(); document.execCommand('copy'); done();
    }
}
</script>
