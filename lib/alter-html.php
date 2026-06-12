<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

function magicConvertAlterHtml($content) {
    // Don't do anything with the RSS feed.
    if (is_feed()) {
        return $content;
    }

    if (get_option('magic-convert-alter-html-replacement') == 'picture') {
        if(function_exists('is_amp_endpoint') && is_amp_endpoint()) {
            //for AMP pages the <picture> tag is not allowed
            return $content;
        }
        /*if (is_admin() ) {
            return $content;
        }*/
        require_once __DIR__ . '/classes/AlterHtmlPicture.php';
        return \MagicConvert\AlterHtmlPicture::alter($content);
    } else {
        require_once __DIR__ . '/classes/AlterHtmlImageUrls.php';
        return \MagicConvert\AlterHtmlImageUrls::alter($content);

    }
}

function magicConvertOutputBuffer() {
    if (!is_admin() || (function_exists("wp_doing_ajax") && wp_doing_ajax()) || (defined( 'DOING_AJAX' ) && DOING_AJAX)) {
        ob_start('magicConvertAlterHtml');
    }
}

function magicConvertAddPictureJs() {
    // Don't do anything with the RSS feed.
    // - and no need for PictureJs in the admin
    if ( is_feed() || is_admin() ) { return; }

    echo '<script>'
       . 'document.createElement( "picture" );'
       . 'if(!window.HTMLPictureElement && document.addEventListener) {'
            . 'window.addEventListener("DOMContentLoaded", function() {'
                . 'var s = document.createElement("script");'
                . 's.src = "' . plugins_url('/js/picturefill.min.js', __FILE__) . '";'
                . 'document.body.appendChild(s);'
            . '});'
        . '}'
       . '</script>';
}


if (get_option('magic-convert-alter-html-replacement') == 'picture') {
    add_action( 'wp_head', 'magicConvertAddPictureJs');
}

if (get_option('magic-convert-alter-html-hooks', 'ob') == 'ob') {
    /* TODO:
       Which hook should we use, and should we make it optional?
       - Cache enabler uses 'template_redirect'
       - ShortPixes uses 'init'

       We go with template_redirect now, because it is the "innermost".
       This lowers the risk of problems with plugins used rewriting URLs to point to CDN.
       (We need to process the output *before* the other plugin has rewritten the URLs,
        if the "Only for webps that exists" feature is enabled)
    */
    add_action( 'init', 'magicConvertOutputBuffer', 1 );
    //add_action( 'template_redirect', 'magicConvertOutputBuffer', 1 );

} else {
    add_filter( 'the_content', 'magicConvertAlterHtml', 10000 ); // priority big, so it will be executed last
    add_filter( 'the_excerpt', 'magicConvertAlterHtml', 10000 );
    add_filter( 'post_thumbnail_html', 'magicConvertAlterHtml');
}

//echo wp_doing_ajax() ? 'ajax' : 'no ajax'; exit;
//echo is_feed() ? 'feed' : 'no feed'; exit;
