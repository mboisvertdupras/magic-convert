window.avifConvertersMap = {};

function getAvifConversionMethodDescription(converterId) {
    var descriptions = {
        'imagick': 'Imagick extension',
        'vips': 'libvips (heifsave)',
        'gd': 'GD imageavif()',
        'magick-binary': 'ImageMagick binary',
        'avifenc': 'avifenc (libavif)',
        'cavif': 'cavif (rav1e)',
    };
    if (descriptions[converterId]) {
        return descriptions[converterId];
    }
    return converterId;
}

function generateAvifConverterHTML(converter) {
    var id = converter['id'];
    var html = '<li data-id="' + id + '" class="' + (converter.deactivated ? 'deactivated' : '') + ' ' + (converter.working ? 'operational' : 'not-operational') + '">';

    html += '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18"><path d="M2 13.5h14V12H2v1.5zm0-4h14V8H2v1.5zM2 4v1.5h14V4H2z"/></svg>';
    html += '<div class="text">';
    html += getAvifConversionMethodDescription(converter['converter']);
    html += '</div>';
    html += '<a class="test-converter btn" onclick="testAvifConverter(\'' + id + '\')">test</a>';

    if (converter.deactivated) {
        html += '<a class="activate-converter btn" onclick=activateAvifConverter(\'' + id + '\')>activate</a>';
    } else {
        html += '<a class="deactivate-converter btn" onclick=deactivateAvifConverter(\'' + id + '\')>deactivate</a>';
    }

    html += '<div class="status">';
    if (converter['error']) {
        html += '<svg id="status_not_ok" width="19" height="19" title="not operational" version="1.0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 500.000000 500.000000" preserveAspectRatio="xMidYMid meet">';
        html += '<g fill="currentcolor" stroke="none" transform="translate(0.000000,500.000000) scale(0.100000,-0.100000)"><path d="M2315 4800 c-479 -35 -928 -217 -1303 -527 -352 -293 -615 -702 -738 -1151 -104 -380 -104 -824 0 -1204 107 -389 302 -724 591 -1013 354 -354 785 -572 1279 -646 196 -30 476 -30 672 0 494 74 925 292 1279 646 354 354 571 784 646 1279 30 197 30 475 0 672 -75 495 -292 925 -646 1279 -289 289 -624 484 -1013 591 -228 62 -528 91 -767 74z m353 -511 c458 -50 874 -272 1170 -624 417 -497 536 -1174 308 -1763 -56 -145 -176 -367 -235 -434 -4 -4 -566 552 -1250 1236 l-1243 1243 94 60 c354 229 754 327 1156 282z m864 -3200 c-67 -59 -289 -179 -434 -235 -946 -366 -2024 172 -2322 1158 -47 155 -66 276 -73 453 -13 362 84 704 290 1023 l60 94 1243 -1243 c684 -684 1240 -1246 1236 -1250z"/></g></svg>';
        html += '<div class="popup">';
        html += magicconvert_escapeHTML(converter['error']);
        html += '</div>';
    } else if (converter.working) {
        html += '<svg id="status_ok" width="19" height="19" version="1.0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256.000000 256.000000" preserveAspectRatio="xMidYMid meet">';
        html += '<g fill="currentcolor" stroke="none" transform="translate(0.000000,256.000000) scale(0.100000,-0.100000)"><path d="M1064 2545 c-406 -72 -744 -324 -927 -690 -96 -193 -127 -333 -127 -575 0 -243 33 -387 133 -585 177 -351 518 -606 907 -676 118 -22 393 -17 511 8 110 24 252 78 356 136 327 183 569 525 628 887 19 122 19 338 0 460 -81 498 -483 914 -990 1025 -101 22 -389 28 -491 10z m814 -745 c39 -27 73 -59 77 -70 9 -27 10 -25 -372 -590 -345 -510 -357 -524 -420 -512 -19 4 -98 74 -250 225 -123 121 -225 228 -228 238 -3 10 1 31 9 47 20 40 125 132 149 132 11 0 79 -59 162 -140 79 -77 146 -140 149 -140 3 0 38 48 78 108 95 143 465 678 496 720 35 46 64 42 150 -18z"/></g></svg>';
        html += '<div class="popup">Operational</div>';
    }
    html += '</div>';

    html += '</li>';
    return html;
}

function setTemporaryIdsOnAvifConverters() {
    if (window.avifConverters == undefined) {
        console.log('window.avifConverters is undefined. Strange. Please report!');
        return;
    }
    for (var i = 0; i < window.avifConverters.length; i++) {
        window.avifConverters[i]['id'] = window.avifConverters[i]['converter'];
    }
    updateAvifConvertersMap();
}

function updateAvifConvertersMap() {
    var map = {};
    for (var i = 0; i < window.avifConverters.length; i++) {
        var converter = window.avifConverters[i];
        map[converter['id']] = converter;
    }
    window.avifConvertersMap = map;
}

function reorderAvifConverters(order) {
    var result = [];
    for (var i = 0; i < order.length; i++) {
        result.push(window.avifConvertersMap[order[i]]);
    }
    window.avifConverters = result;
    updateAvifInputValue();
}

function updateAvifInputValue() {
    var inputEl = document.getElementsByName('avif-converters')[0];
    if (!inputEl) {
        return;
    }
    var clean = [];
    for (var i = 0; i < window.avifConverters.length; i++) {
        var c = window.avifConverters[i];
        var entry = { converter: c['converter'] };
        if (c.deactivated) {
            entry.deactivated = true;
        }
        clean.push(entry);
    }
    inputEl.value = JSON.stringify(clean);
}

function setAvifConvertersHTML() {
    var html = '';

    setTemporaryIdsOnAvifConverters();

    var el = document.getElementById('avif-converters');
    if (el == null) {
        return;
    }

    for (var i = 0; i < window.avifConverters.length; i++) {
        html += generateAvifConverterHTML(window.avifConverters[i]);
    }
    el.innerHTML = html;

    Sortable.create(el, {
        onChoose: function() {
            document.getElementById('avif-converters').className = 'dragging';
        },
        onUnchoose: function() {
            document.getElementById('avif-converters').className = '';
        },
        store: {
            get: function() {
                var order = [];
                for (var i = 0; i < window.avifConverters.length; i++) {
                    order.push(window.avifConverters[i]['id']);
                }
                return order;
            },
            set: function(sortable) {
                reorderAvifConverters(sortable.toArray());
            }
        }
    });
    updateAvifInputValue();
}

document.addEventListener('DOMContentLoaded', function() {
    setAvifConvertersHTML();
});

function deactivateAvifConverter(id) {
    window.avifConvertersMap[id].deactivated = true;
    setAvifConvertersHTML();
}

function activateAvifConverter(id) {
    delete window.avifConvertersMap[id].deactivated;
    setAvifConvertersHTML();
}

function testAvifConverter(id) {
    var converter = window.avifConvertersMap[id];
    var converterId = converter ? converter['converter'] : id;
    var label = getAvifConversionMethodDescription(converterId);

    var html = '<div id="avif_tc_result"><h2>Testing ' + magicconvert_escapeHTML(label) + '…</h2><p>Encoding a sample image to AVIF — please wait (AVIF can take a few seconds)…</p></div>';
    document.getElementById('avif_tc_content').innerHTML = html;

    var w = Math.min(900, Math.max(200, document.documentElement.clientWidth - 100));
    var h = Math.max(250, document.documentElement.clientHeight - 80);
    tb_show('Testing AVIF conversion', '#TB_inline?inlineId=avif_tc_popup&width=' + w + '&height=' + h);

    var image = 'architecture-q85-w600.jpg';
    var data = {
        'action': 'convert_file',
        'nonce': window.magicConvert['ajax-nonces']['convert'],
        'filename': window.magicConvertPaths['filePaths']['magicConvertRoot'] + '/test/' + image,
        'format': 'avif',
        'converter': converterId
    };

    jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: data,
        success: function(response) {
            renderAvifTestResult(response, label);
        },
        error: function() {
            document.getElementById('avif_tc_result').innerHTML = '<h2 style="color:red">An error occurred</h2>';
        }
    });
}

function renderAvifTestResult(response, label) {
    var target = document.getElementById('avif_tc_result');
    if (!target) {
        return;
    }

    if ((typeof response == 'string') && (response.length > 0) && (response[0] != '{')) {
        target.innerHTML = '<h2 style="color:red">Response was not JSON</h2><pre style="white-space:pre-wrap">' + magicconvert_escapeHTML(response) + '</pre>';
        return;
    }

    var result;
    try {
        result = (typeof response == 'string') ? JSON.parse(response) : response;
    } catch (e) {
        target.innerHTML = '<h2 style="color:red">Could not parse response</h2>';
        return;
    }

    var html = '<h2>' + magicconvert_escapeHTML(label) + ': ';
    if (result['success'] === true) {
        html += '<span style="color:green">Success</span></h2>';

        var orgSize = result['filesize-original'];
        var avifSize = result['filesize-webp'];
        if (orgSize && avifSize) {
            var reduction = Math.round((orgSize - avifSize) / orgSize * 100);
            function fmt(n) { return (n < 10000) ? (n + ' bytes') : (Math.round(n / 1024) + ' kb'); }
            html += '<p><b>Reduction: ' + reduction + '%</b> (from ' + fmt(orgSize) + ' to ' + fmt(avifSize) + ')</p>';
        }
    } else {
        html += '<span style="color:red">Failure</span></h2>';
        if (result['msg']) {
            html += '<p style="color:red; font-weight:bold">' + magicconvert_escapeHTML(result['msg']) + '</p>';
        }
    }

    if (result['log']) {
        html += '<h3>Conversion log:</h3>';
        html += '<pre style="white-space:pre-wrap">' + magicconvert_escapeHTML(result['log']) + '</pre>';
    }

    target.innerHTML = html;
}
