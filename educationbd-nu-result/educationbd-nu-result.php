<?php
/**
 * Plugin Name: EducationBD NU Result
 * Description: Native EducationBD NU result checker. Fetches configuration/session from the official NU result server without iframe or rendering the NU form.
 * Version: 1.3.0
 * Author: EducationBD
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) exit;

define('EDUBD_NU_RESULT_VERSION', '1.3.0');
define('EDUBD_NU_RESULT_URL', plugin_dir_url(__FILE__));

function edubd_nu_result_types() {
    return array(
        'honours'      => 'Honours',
        'degree'       => 'Degree Pass',
        'masters'      => "Master's",
        'professional' => 'Professional',
        'revaluation'  => 'Re-evaluation',
    );
}

function edubd_nu_result_official_urls() {
    return array(
        'honours'      => 'https://results.nu.ac.bd/honours',
        'degree'       => 'https://results.nu.ac.bd/degree',
        'masters'      => 'https://results.nu.ac.bd/masters',
        'professional' => 'https://results.nu.ac.bd/professional',
        'revaluation'  => 'https://results.nu.ac.bd/revaluation',
    );
}

add_action('wp_enqueue_scripts', function() {
    wp_register_style('edubd-nu-result', EDUBD_NU_RESULT_URL . 'assets/style.css', array(), EDUBD_NU_RESULT_VERSION);
});

function edubd_nu_result_http_args($cookies = array()) {
    return array(
        'timeout'     => 30,
        'redirection' => 5,
        'sslverify'   => true,
        'cookies'     => $cookies,
        'headers'     => array(
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/154 Safari/537.36',
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9,bn;q=0.8',
        ),
    );
}

function edubd_nu_result_resolve_url($base, $relative) {
    $relative = trim((string)$relative);
    if ($relative === '') return $base;
    if (preg_match('#^https?://#i', $relative)) return $relative;
    $p = wp_parse_url($base);
    if (!$p || empty($p['host'])) return $relative;
    $scheme = isset($p['scheme']) ? $p['scheme'] : 'https';
    $origin = $scheme . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strpos($relative, '//') === 0) return $scheme . ':' . $relative;
    if ($relative[0] === '/') return $origin . $relative;
    $path = isset($p['path']) ? $p['path'] : '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    $full = $dir . $relative;
    $parts = array();
    foreach (explode('/', $full) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') { array_pop($parts); continue; }
        $parts[] = $part;
    }
    return $origin . '/' . implode('/', $parts);
}

function edubd_nu_result_cookie_pack($cookies) {
    $out = array();
    foreach ((array)$cookies as $c) {
        if (!is_object($c) || !isset($c->name)) continue;
        $out[] = array(
            'name' => (string)$c->name,
            'value' => isset($c->value) ? (string)$c->value : '',
            'expires' => isset($c->expires) ? (int)$c->expires : null,
            'path' => isset($c->path) ? (string)$c->path : '/',
            'domain' => isset($c->domain) ? (string)$c->domain : 'results.nu.ac.bd',
        );
    }
    return $out;
}

function edubd_nu_result_cookie_unpack($packed) {
    $out = array();
    foreach ((array)$packed as $c) {
        if (empty($c['name'])) continue;
        $out[] = new WP_Http_Cookie(array(
            'name' => $c['name'],
            'value' => isset($c['value']) ? $c['value'] : '',
            'expires' => isset($c['expires']) ? $c['expires'] : null,
            'path' => isset($c['path']) ? $c['path'] : '/',
            'domain' => isset($c['domain']) ? $c['domain'] : 'results.nu.ac.bd',
        ));
    }
    return $out;
}

function edubd_nu_result_cookie_merge($old, $new) {
    $map = array();
    foreach ((array)$old as $c) if (!empty($c['name'])) $map[$c['name']] = $c;
    foreach ((array)$new as $c) if (!empty($c['name'])) $map[$c['name']] = $c;
    return array_values($map);
}

function edubd_nu_result_dom($html) {
    if (!class_exists('DOMDocument')) return new WP_Error('dom_missing', 'PHP DOM extension is required on the server.');
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $wrapped = '<?xml encoding="utf-8" ?>' . $html;
    if (!$dom->loadHTML($wrapped, LIBXML_NOWARNING | LIBXML_NOERROR)) {
        libxml_clear_errors();
        return new WP_Error('html_parse', 'Could not read the NU response.');
    }
    libxml_clear_errors();
    return $dom;
}

function edubd_nu_result_inner_html($node) {
    $html = '';
    foreach ($node->childNodes as $child) $html .= $node->ownerDocument->saveHTML($child);
    return $html;
}

function edubd_nu_result_text_key($value) {
    return strtolower(trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string)$value))));
}

function edubd_nu_result_field_key($name, $id, $label = '') {
    $s = edubd_nu_result_text_key($name . ' ' . $id . ' ' . $label);
    if (preg_match('/captcha|verification|security|code|capture/', $s)) return 'captcha';
    if (preg_match('/registration|reg[_ -]?no|regno/', $s)) return 'registration';
    if (preg_match('/roll/', $s)) return 'roll';
    if (preg_match('/exam[_ -]?year|examyear|year/', $s)) return 'exam_year';
    if (preg_match('/exam|course|program|year|part|semester/', $s)) return 'exam_name';
    return '';
}

function edubd_nu_result_find_label($dom, $element) {
    $id = trim($element->getAttribute('id'));
    if ($id) {
        $labels = $dom->getElementsByTagName('label');
        foreach ($labels as $label) {
            if (trim($label->getAttribute('for')) === $id) return trim(preg_replace('/\s+/', ' ', $label->textContent));
        }
    }
    // Avoid using the entire parent text (which includes every option in a select).
    $prev = $element->previousSibling;
    while ($prev) {
        if ($prev->nodeType === XML_ELEMENT_NODE && strtolower($prev->nodeName) === 'label') return trim(preg_replace('/\s+/', ' ', $prev->textContent));
        $prev = $prev->previousSibling;
    }
    return '';
}

function edubd_nu_result_fetch_captcha_data($image_url, $cookies, $referer = '') {
    if (!$image_url) return '';
    $args = edubd_nu_result_http_args($cookies);
    if ($referer) $args['headers']['Referer'] = $referer;
    $args['headers']['Accept'] = 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8';
    $response = wp_remote_get($image_url, $args);
    if (is_wp_error($response)) return '';
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($code < 200 || $code >= 400 || $body === '') return '';
    $type = wp_remote_retrieve_header($response, 'content-type');
    if (!$type || strpos(strtolower($type), 'text/html') !== false) return '';
    if (strpos(strtolower($type), 'image/') !== 0 && strpos(strtolower($type), 'application/octet-stream') !== 0) {
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            $detected = $f ? finfo_buffer($f, $body) : '';
            if ($f) finfo_close($f);
            if (!$detected || strpos($detected, 'image/') !== 0) return '';
            $type = $detected;
        } else {
            return '';
        }
    }
    return 'data:' . $type . ';base64,' . base64_encode($body);
}

function edubd_nu_result_extract_config($html, $base_url) {
    $dom = edubd_nu_result_dom($html);
    if (is_wp_error($dom)) return $dom;
    $forms = $dom->getElementsByTagName('form');
    if (!$forms->length) return new WP_Error('no_form', 'NU result form was not found.');

    $form = null;
    foreach ($forms as $candidate) {
        $txt = strtolower($candidate->textContent);
        if ($candidate->getElementsByTagName('input')->length || $candidate->getElementsByTagName('select')->length) {
            if (strpos($txt, 'result') !== false || strpos($txt, 'registration') !== false || strpos($txt, 'roll') !== false) { $form = $candidate; break; }
        }
    }
    if (!$form) $form = $forms->item(0);

    $action = edubd_nu_result_resolve_url($base_url, $form->getAttribute('action') ?: $base_url);
    $method = strtolower($form->getAttribute('method') ?: 'post');
    $hidden = array();
    $fields = array();
    $selects = array();
    $captcha = array('image_url'=>'','image_data'=>'','input_name'=>'','input_id'=>'','question'=>'');

    foreach ($form->getElementsByTagName('input') as $input) {
        $name = trim($input->getAttribute('name'));
        if ($name === '') continue;
        $type = strtolower($input->getAttribute('type') ?: 'text');
        $id = $input->getAttribute('id');
        $label = edubd_nu_result_find_label($dom, $input);
        $key = edubd_nu_result_field_key($name, $id, $label);
        if (in_array($type, array('hidden','submit','button'), true)) {
            if ($type === 'hidden') $hidden[$name] = $input->getAttribute('value');
        } elseif (in_array($type, array('checkbox','radio'), true)) {
            if ($input->hasAttribute('checked')) $hidden[$name] = $input->getAttribute('value');
        } else {
            if ($key && empty($fields[$key])) $fields[$key] = array('name'=>$name,'id'=>$id,'label'=>trim($label),'type'=>$type,'placeholder'=>$input->getAttribute('placeholder'));
        }
        if ($key === 'captcha' && empty($captcha['input_name'])) {
            $captcha['input_name'] = $name;
            $captcha['input_id'] = $id;
            $parent = $input->parentNode;
            // Text-based question (e.g. "9 + 5 = ?"): show it to the visitor, who must answer it.
            $anc = $input->parentNode; $depth = 0;
            while ($anc && $anc->nodeType === XML_ELEMENT_NODE && $depth < 4) {
                $t = trim(preg_replace('/\s+/', ' ', $anc->textContent));
                if (strpos($t, '=') !== false && strlen($t) < 160) { $captcha['question'] = $t; break; }
                $anc = $anc->parentNode; $depth++;
            }
            if ($parent) {
                foreach ($parent->getElementsByTagName('img') as $img) { $captcha['image_url'] = edubd_nu_result_resolve_url($base_url, $img->getAttribute('src')); break; }
            }
        }
    }

    foreach ($form->getElementsByTagName('select') as $select) {
        $name = trim($select->getAttribute('name')); if ($name === '') continue;
        $id = $select->getAttribute('id');
        $label = edubd_nu_result_find_label($dom, $select);
        $key = edubd_nu_result_field_key($name, $id, $label);
        $options = array();
        foreach ($select->getElementsByTagName('option') as $option) {
            $value = $option->getAttribute('value'); $text = trim(preg_replace('/\s+/', ' ', $option->textContent));
            if ($value === '' && $text === '') continue;
            $options[] = array('value'=>$value,'label'=>$text,'selected'=>$option->hasAttribute('selected'));
        }
        $selects[] = array('name'=>$name,'id'=>$id,'label'=>trim($label),'key'=>$key,'options'=>$options);
        $default = '';
        foreach ($options as $o) { if (!empty($o['selected'])) { $default = $o['value']; break; } }
        if (!isset($hidden[$name])) $hidden[$name] = $default;
        if ($key && empty($fields[$key])) $fields[$key] = array('name'=>$name,'id'=>$id,'label'=>trim($label),'type'=>'select');
    }

    if (empty($fields['captcha'])) {
        foreach ($form->getElementsByTagName('img') as $img) {
            $alt = edubd_nu_result_text_key($img->getAttribute('alt').' '.$img->getAttribute('class').' '.$img->getAttribute('id'));
            if (preg_match('/captcha|code|security/', $alt)) { $captcha['image_url'] = edubd_nu_result_resolve_url($base_url, $img->getAttribute('src')); break; }
        }
    }

    if (!empty($captcha['image_url'])) {
        // The browser cannot access NU's captcha image because it belongs to the server-side session.
        // The caller may replace image_data after the session cookies are known.
    }

    return array(
        'action'=>$action,
        'method'=>$method,
        'hidden'=>$hidden,
        'fields'=>$fields,
        'selects'=>$selects,
        'captcha'=>$captcha,
        'base'=>$base_url,
    );
}

function edubd_nu_result_allowed_html() {
    $global = array('class'=>true,'id'=>true,'title'=>true,'style'=>true,'role'=>true,'align'=>true,'valign'=>true,'width'=>true,'height'=>true,'bgcolor'=>true,'dir'=>true,'lang'=>true,'aria-label'=>true,'aria-hidden'=>true,'data-*'=>true);
    $cell = array_merge($global, array('colspan'=>true,'rowspan'=>true,'scope'=>true,'nowrap'=>true,'headers'=>true,'abbr'=>true));
    $tags = array();
    foreach (array('div','span','p','section','article','main','header','footer','aside','nav','center','h1','h2','h3','h4','h5','h6','thead','tbody','tfoot','tr','ul','ol','li','dl','dt','dd','strong','b','em','i','u','s','strike','small','big','sub','sup','hr','pre','code','blockquote','label','fieldset','legend','nobr','mark','abbr','font','figure','figcaption') as $t) $tags[$t] = $global;
    $tags['font'] = array_merge($global, array('color'=>true,'size'=>true,'face'=>true));
    $tags['br'] = array();
    $tags['table'] = array_merge($global, array('border'=>true,'cellpadding'=>true,'cellspacing'=>true,'summary'=>true,'frame'=>true,'rules'=>true));
    $tags['caption'] = $global;
    $tags['colgroup'] = array_merge($global, array('span'=>true));
    $tags['col'] = array_merge($global, array('span'=>true));
    $tags['th'] = $cell;
    $tags['td'] = $cell;
    $tags['a'] = array_merge($global, array('href'=>true,'target'=>true,'rel'=>true));
    $tags['img'] = array_merge($global, array('src'=>true,'alt'=>true,'border'=>true));
    return $tags;
}

function edubd_nu_result_safe_css($css) {
    return array_merge((array)$css, array('width','height','min-width','max-width','text-align','vertical-align','font-weight','font-style','font-size','font-family','color','background','background-color','border','border-collapse','border-spacing','border-top','border-bottom','border-left','border-right','border-color','border-width','border-style','padding','padding-top','padding-bottom','padding-left','padding-right','margin','margin-top','margin-bottom','margin-left','margin-right','display','text-decoration','white-space','line-height','float'));
}

function edubd_nu_result_kses_protocols($p) { $p[] = 'data'; return $p; }

// Download an image (e.g. student photo) through the NU session so it displays on our page.
function edubd_nu_result_inline_image($url, $cookies, $referer) {
    if (!preg_match('#^https?://[^/]*nu\.ac\.bd/#i', $url)) return $url;
    $data = edubd_nu_result_fetch_captcha_data($url, $cookies, $referer);
    return $data ? $data : $url;
}

function edubd_nu_result_extract_response($html, $base_url, $cookies = array()) {
    $dom = edubd_nu_result_dom($html);
    if (is_wp_error($dom)) return $dom;
    foreach (array('script','style','link','meta','noscript','iframe','object','embed') as $tag) {
        $nodes = array(); foreach ($dom->getElementsByTagName($tag) as $n) $nodes[] = $n;
        foreach ($nodes as $n) if ($n->parentNode) $n->parentNode->removeChild($n);
    }
    // Keep content that lives inside <form> (many NU result pages wrap results in a form): unwrap it, drop controls.
    foreach (array('input','button','select','textarea') as $tag) {
        $nodes = array(); foreach ($dom->getElementsByTagName($tag) as $n) $nodes[] = $n;
        foreach ($nodes as $n) if ($n->parentNode) $n->parentNode->removeChild($n);
    }
    $nodes = array(); foreach ($dom->getElementsByTagName('form') as $n) $nodes[] = $n;
    foreach ($nodes as $n) {
        if (!$n->parentNode) continue;
        while ($n->firstChild) $n->parentNode->insertBefore($n->firstChild, $n);
        $n->parentNode->removeChild($n);
    }
    // Absolute links and inlined images.
    $nodes = array(); foreach ($dom->getElementsByTagName('img') as $n) $nodes[] = $n;
    foreach ($nodes as $img) {
        $src = trim($img->getAttribute('src'));
        if ($src === '' || stripos($src, 'data:') === 0) continue;
        $abs = edubd_nu_result_resolve_url($base_url, $src);
        $img->setAttribute('src', edubd_nu_result_inline_image($abs, $cookies, $base_url));
    }
    $nodes = array(); foreach ($dom->getElementsByTagName('a') as $n) $nodes[] = $n;
    foreach ($nodes as $a) {
        $href = trim($a->getAttribute('href'));
        if ($href === '' || $href[0] === '#' || stripos($href, 'javascript:') === 0) { $a->removeAttribute('href'); continue; }
        $a->setAttribute('href', edubd_nu_result_resolve_url($base_url, $href));
        $a->setAttribute('target', '_blank'); $a->setAttribute('rel', 'noopener noreferrer');
    }
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) return new WP_Error('no_body', 'NU returned an unreadable response.');
    add_filter('safe_style_css', 'edubd_nu_result_safe_css');
    add_filter('kses_allowed_protocols', 'edubd_nu_result_kses_protocols');
    $out = wp_kses(edubd_nu_result_inner_html($body), edubd_nu_result_allowed_html());
    remove_filter('safe_style_css', 'edubd_nu_result_safe_css');
    remove_filter('kses_allowed_protocols', 'edubd_nu_result_kses_protocols');
    if (trim(wp_strip_all_tags($out)) === '' && stripos($out, '<img') === false) {
        return new WP_Error('empty', 'NU returned no result. Check your information and verification code, then try again.');
    }
    return $out;
}

function edubd_nu_result_ajax_load() {
    check_ajax_referer('edubd_nu_proxy', 'security');
    $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : 'honours';
    $urls = edubd_nu_result_official_urls();
    if (!isset($urls[$type])) wp_send_json_error(array('message'=>'Invalid result category.'),400);
    $response = wp_remote_get($urls[$type], edubd_nu_result_http_args());
    if (is_wp_error($response)) wp_send_json_error(array('message'=>'Could not connect to NU server: '.$response->get_error_message()),502);
    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 400) wp_send_json_error(array('message'=>'NU server returned HTTP '.$code.'.'),502);
    $config = edubd_nu_result_extract_config(wp_remote_retrieve_body($response), $urls[$type]);
    if (is_wp_error($config)) wp_send_json_error(array('message'=>$config->get_error_message()),500);
    $cookies = edubd_nu_result_cookie_pack(wp_remote_retrieve_cookies($response));
    $config['captcha']['image_data'] = !empty($config['captcha']['image_url']) ? edubd_nu_result_fetch_captcha_data($config['captcha']['image_url'], edubd_nu_result_cookie_unpack($cookies), $urls[$type]) : '';
    $token = wp_generate_password(40,false,false);
    set_transient('edubd_nu_'.$token,array(
        'action'=>$config['action'],'method'=>$config['method'],'base'=>$config['base'],'hidden'=>$config['hidden'],
        'fields'=>$config['fields'],'cookies'=>$cookies,
    ),10*MINUTE_IN_SECONDS);
    wp_send_json_success(array('config'=>$config,'token'=>$token));
}
add_action('wp_ajax_edubd_nu_load','edubd_nu_result_ajax_load');
add_action('wp_ajax_nopriv_edubd_nu_load','edubd_nu_result_ajax_load');

function edubd_nu_result_ajax_captcha() {
    check_ajax_referer('edubd_nu_proxy', 'security');
    $token = isset($_POST['token']) ? preg_replace('/[^A-Za-z0-9]/','',wp_unslash($_POST['token'])) : '';
    $session = $token ? get_transient('edubd_nu_'.$token) : false;
    if (!$session) wp_send_json_error(array('message'=>'NU session expired. Reload the result form.'),410);
    $response = wp_remote_get($session['base'], edubd_nu_result_http_args(edubd_nu_result_cookie_unpack($session['cookies'])));
    if (is_wp_error($response)) wp_send_json_error(array('message'=>'Could not refresh NU verification image.'),502);
    $config = edubd_nu_result_extract_config(wp_remote_retrieve_body($response), $session['base']);
    if (is_wp_error($config)) wp_send_json_error(array('message'=>$config->get_error_message()),500);
    $new_cookie_pack = edubd_nu_result_cookie_pack(wp_remote_retrieve_cookies($response));
    $config['captcha']['image_data'] = !empty($config['captcha']['image_url']) ? edubd_nu_result_fetch_captcha_data($config['captcha']['image_url'], edubd_nu_result_cookie_unpack($new_cookie_pack), $session['base']) : '';
    $session['action']=$config['action']; $session['method']=$config['method']; $session['hidden']=$config['hidden']; $session['fields']=$config['fields']; $session['cookies']=$new_cookie_pack;
    set_transient('edubd_nu_'.$token,$session,10*MINUTE_IN_SECONDS);
    wp_send_json_success(array('config'=>$config));
}
add_action('wp_ajax_edubd_nu_captcha','edubd_nu_result_ajax_captcha');
add_action('wp_ajax_nopriv_edubd_nu_captcha','edubd_nu_result_ajax_captcha');

function edubd_nu_result_ajax_submit() {
    check_ajax_referer('edubd_nu_proxy', 'security');
    $token = isset($_POST['token']) ? preg_replace('/[^A-Za-z0-9]/','',wp_unslash($_POST['token'])) : '';
    $session = $token ? get_transient('edubd_nu_'.$token) : false;
    if (!$session || empty($session['action'])) wp_send_json_error(array('message'=>'NU session expired. Reload the result form.'),410);
    $values = array();
    if (!empty($_POST['values']) && is_array($_POST['values'])) {
        foreach (wp_unslash($_POST['values']) as $key=>$value) $values[sanitize_key($key)] = sanitize_text_field($value);
    }
    $fields = isset($session['hidden']) ? $session['hidden'] : array();
    foreach (array('roll','registration','exam_year','exam_name','captcha') as $key) {
        if (isset($session['fields'][$key])) {
            $name = $session['fields'][$key]['name'];
            if (isset($values[$key])) $fields[$name] = $values[$key];
        }
    }
    if (!empty($_POST['named']) && is_array($_POST['named'])) {
        foreach (wp_unslash($_POST['named']) as $name=>$value) {
            $name = sanitize_text_field($name);
            if ($name !== '' && array_key_exists($name, $fields)) $fields[$name] = sanitize_text_field($value);
        }
    }
    if (isset($values['captcha']) && empty($session['fields']['captcha'])) {
        wp_send_json_error(array('message'=>'NU verification field could not be detected. Please refresh the form.'),400);
    }
    if (empty($fields)) wp_send_json_error(array('message'=>'No NU form fields were detected.'),400);
    $cookies = edubd_nu_result_cookie_unpack(isset($session['cookies'])?$session['cookies']:array());
    $args = edubd_nu_result_http_args($cookies);
    $args['headers']['Referer'] = $session['base'];
    $args['headers']['Origin'] = preg_replace('#^(https?://[^/]+).*#i','$1',$session['base']);
    $args['timeout'] = 45;
    $args['body'] = $fields;
    $response = wp_remote_post($session['action'],$args);
    if (is_wp_error($response)) wp_send_json_error(array('message'=>'NU result request failed: '.$response->get_error_message()),502);
    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 400) wp_send_json_error(array('message'=>'NU server returned HTTP '.$code.'.'),502);
    $new_cookies = wp_remote_retrieve_cookies($response);
    if ($new_cookies) { $session['cookies']=edubd_nu_result_cookie_merge($session['cookies'], edubd_nu_result_cookie_pack($new_cookies)); set_transient('edubd_nu_'.$token,$session,10*MINUTE_IN_SECONDS); }
    $html = edubd_nu_result_extract_response(wp_remote_retrieve_body($response),$session['action'],edubd_nu_result_cookie_unpack($session['cookies']));
    if (is_wp_error($html)) wp_send_json_error(array('message'=>$html->get_error_message()),500);
    wp_send_json_success(array('html'=>$html));
}
add_action('wp_ajax_edubd_nu_submit','edubd_nu_result_ajax_submit');
add_action('wp_ajax_nopriv_edubd_nu_submit','edubd_nu_result_ajax_submit');

function edubd_nu_result_shortcode($atts = array()) {
    wp_enqueue_style('edubd-nu-result');
    $atts = shortcode_atts(array('type'=>''), $atts, 'educationbd_nu_result');
    $fixed = sanitize_key($atts['type']);
    if (!isset(edubd_nu_result_types()[$fixed])) $fixed = '';
    $types=edubd_nu_result_types(); $nonce=wp_create_nonce('edubd_nu_proxy'); $ajax=admin_url('admin-ajax.php');
    ob_start(); ?>
    <div class="edubd-nu-result" id="edubd-nu-result-app">
      <div class="edubd-hero"><div class="edubd-badge">NATIONAL UNIVERSITY</div><h1><?php echo $fixed ? esc_html(edubd_nu_result_types()[$fixed]).' Result' : 'NU Result'; ?></h1><p>Check your result directly from the National University server.</p></div>
      <div class="edubd-tabs" role="tablist" <?php echo $fixed ? 'style="display:none"' : ''; ?>>
        <?php foreach($types as $key=>$label): ?><button type="button" class="edubd-tab <?php echo $key===($fixed?:'honours')?'active':''; ?>" data-result-type="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></button><?php endforeach; ?>
      </div>
      <div id="edubd-status" class="edubd-status loading">Connecting to NU server…</div>
      <div id="edubd-native-form"></div>
      <div id="edubd-result-output"></div>
      <div class="edubd-help"><strong>Security:</strong> NU verification/captcha, when required, must be completed manually. No iframe is used and NU security is not bypassed.</div>
    </div>
    <script>
    (function(){
      const app=document.getElementById('edubd-nu-result-app'); if(!app)return;
      const ajaxUrl=<?php echo wp_json_encode($ajax); ?>, security=<?php echo wp_json_encode($nonce); ?>;
      const status=document.getElementById('edubd-status'), formRoot=document.getElementById('edubd-native-form'), output=document.getElementById('edubd-result-output');
      let token='', active=<?php echo wp_json_encode($fixed?:'honours'); ?>, config=null;
      const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
      const post=async data=>{const r=await fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams(data)});return r.json();};
      function msg(t,k=''){status.className='edubd-status '+k;status.textContent=t;status.style.display='block';}
      function field(key,label,placeholder,type='text'){const f=config.fields&&config.fields[key]; if(!f)return ''; return `<div class="edubd-field"><label>${esc(label||f.label||key)}</label><input data-key="${key}" type="${type}" placeholder="${esc(placeholder||f.placeholder||'')}" autocomplete="off"></div>`;}
      function selectHtml(s){const key=(s.key&&s.key!=='captcha')?s.key:('select_'+s.name); const label=s.label||'Select'; return `<div class="edubd-field"><label>${esc(label)}</label><select data-key="${esc(key)}" data-name="${esc(s.name)}"><option value="">Select ${esc(label)}</option>${s.options.map(o=>`<option value="${esc(o.value)}" ${o.selected?'selected':''}>${esc(o.label)}</option>`).join('')}</select></div>`;}
      function render(){
        const selects=(config.selects||[]).filter(s=>s.options&&s.options.length);
        let examSelect=selects.find(s=>s.key==='exam_name')||selects.find(s=>/exam|course|year|part|semester/i.test((s.label||'')+' '+(s.name||'')));
        let html='<div class="edubd-form"><div class="edubd-form-grid">';
        const yf=config.fields&&config.fields.exam_year;
        selects.forEach(s=>{ if(s===examSelect){s.label=s.label||'Examination Name';} html+=selectHtml(s); });
        if(yf&&yf.type!=='select') html+=field('exam_year','Exam Year','e.g. 2024','number');
        html+=field('roll','Roll Number','Enter Roll Number','text');
        html+=field('registration','Registration Number','Enter Registration Number','text');
        if(config.captcha&&config.fields&&config.fields.captcha){html+=`<div class="edubd-field edubd-captcha"><label>${config.captcha.question?esc(config.captcha.question):'Verification Code'}</label><div class="edubd-captcha-row">${config.captcha.image_data?`<img id="edubd-captcha-img" src="${config.captcha.image_data}" alt="NU verification">`:''}<button type="button" id="edubd-captcha-refresh" class="edubd-refresh">↻</button><input data-key="captcha" type="text" placeholder="Enter code"></div></div>`;}
        html+='</div><div class="edubd-actions"><button type="button" class="edubd-submit" id="edubd-search">🔍 View Result</button><button type="button" class="edubd-reset" id="edubd-reset">Reset</button></div></div>';
        formRoot.innerHTML=html;
        document.getElementById('edubd-search').onclick=submit;
        document.getElementById('edubd-reset').onclick=()=>{formRoot.querySelectorAll('input').forEach(i=>i.value=''); formRoot.querySelectorAll('select').forEach(s=>s.selectedIndex=0); output.innerHTML='';};
        const refresh=document.getElementById('edubd-captcha-refresh'); if(refresh)refresh.onclick=refreshCaptcha;
      }
      async function load(type){active=type;token='';config=null;output.innerHTML='';formRoot.innerHTML='';msg('Connecting to NU server…','loading');document.querySelectorAll('.edubd-tab').forEach(b=>b.classList.toggle('active',b.dataset.resultType===type));
        try{const j=await post({action:'edubd_nu_load',security,type});if(!j.success)throw new Error(j.data?.message||'Could not load NU configuration.');config=j.data.config;token=j.data.token;render();msg('Ready — enter your result information.','success');}
        catch(e){msg(e.message||'Could not connect to NU server.','error');}
      }
      async function refreshCaptcha(){if(!token)return;msg('Refreshing NU verification…','loading');try{const j=await post({action:'edubd_nu_captcha',security,token});if(!j.success)throw new Error(j.data?.message||'Could not refresh verification.');config=j.data.config;render();msg('Verification refreshed.','success');}catch(e){msg(e.message||'Could not refresh verification.','error');}}
      async function submit(){
        const values={};formRoot.querySelectorAll('[data-key]').forEach(el=>{const key=el.dataset.key;if(key)values[key]=el.value.trim();});
        if(!values.roll&&!values.registration){msg('Enter Roll Number or Registration Number.','error');return;}
        if(config.fields?.exam_year&&!values.exam_year){msg('Enter the Exam Year.','error');return;}
        if(config.fields?.captcha&&!values.captcha){msg('Enter the verification code.','error');return;}
        const data={action:'edubd_nu_submit',security,token};Object.entries(values).forEach(([k,v])=>data[`values[${k}]`]=v);formRoot.querySelectorAll('select[data-name]').forEach(el=>{if(el.value!=='')data[`named[${el.dataset.name}]`]=el.value;});
        msg('Fetching result from NU server…','loading');output.innerHTML='';
        try{const j=await post(data);if(!j.success)throw new Error(j.data?.message||'NU result request failed.');status.style.display='none';output.innerHTML='<div class="edubd-proxy-result"><div class="edubd-result-title">National University Result</div>'+j.data.html+'</div>';output.scrollIntoView({behavior:'smooth',block:'start'});}
        catch(e){msg(e.message||'NU result request failed.','error');}
      }
      document.querySelectorAll('.edubd-tab').forEach(b=>b.addEventListener('click',()=>load(b.dataset.resultType)));
      load(active);
    })();
    </script>
    <?php return ob_get_clean();
}
add_shortcode('educationbd_nu_result','edubd_nu_result_shortcode');
