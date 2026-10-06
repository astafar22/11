<?php
/**
 * শর্টকোড: [nu_new_result_portal]  (Honours)
 * Theme-এর functions.php বা Code Snippets প্লাগইনে বসান।
 *
 * পরিবর্তন:
 *  - পুরো রেজাল্ট শিট (নাম, রোল, রেজিস্ট্রেশন, কলেজ, সেশন, বিষয় + কোর্স টেবিল + GPA) দেখায়, শুধু <table> নয়।
 *  - NU-এর যাচাই প্রশ্ন ("9 + 5 = ?") স্টুডেন্টকে দেখানো হয়, উত্তর স্টুডেন্ট নিজে দেয় (স্বয়ংক্রিয় সমাধান নেই)।
 *  - প্রতি ভিজিটরের আলাদা সেশন/কুকি (আগে একটাই কুকি-ফাইল সবার জন্য ছিল)।
 *  - SSL verify চালু।
 */
add_shortcode('nu_new_result_portal', 'nu_new_result_fetcher');

function nu_new_result_curl($url, $cookie_file, $post = null, $referer = '') {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookie_file,
        CURLOPT_COOKIEFILE     => $cookie_file,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_ENCODING       => '',
    ));
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($body, $code);
}

// ফর্ম পেজ খুলে: নতুন সেশন + CSRF টোকেন + যাচাই প্রশ্ন
function nu_new_result_start($url) {
    $dir = trailingslashit(wp_upload_dir()['basedir']) . 'nu_tmp/';
    wp_mkdir_p($dir);
    $sid = wp_generate_password(24, false, false);
    $cookie = $dir . $sid . '.txt';
    list($html, $code) = nu_new_result_curl($url, $cookie);
    if (!$html || $code != 200) return array('', '', '');
    $csrf = preg_match('/name="_token"\s+value="([^"]+)"/i', $html, $m) ? $m[1] : '';
    $q = preg_match('/(\d+\s*[+\-×x*]\s*\d+)\s*=/u', $html, $m) ? trim($m[1]) : '';
    set_transient('nu_sess_' . $sid, array('csrf' => $csrf, 'cookie' => $cookie), 15 * MINUTE_IN_SECONDS);
    return array($sid, $q, $csrf);
}

// পুরো রেজাল্ট শিট বের করা: যে অংশে "Name of Student" ও কোর্স টেবিল দুটোই আছে
function nu_new_result_extract($html, $base) {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    foreach ($xp->query('//script|//style|//form|//button|//input|//select|//textarea|//nav|//noscript|//iframe') as $n) {
        if ($n->parentNode) $n->parentNode->removeChild($n);
    }
    $root = null;
    $hit = $xp->query("//*[contains(translate(text(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'name of student') or contains(translate(text(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'examination roll')]")->item(0);
    if ($hit) {
        $n = $hit;
        while ($n && $n->nodeName !== 'body') {
            if ($n->nodeType === XML_ELEMENT_NODE && $n->getElementsByTagName('table')->length) { $root = $n; break; }
            $n = $n->parentNode;
        }
    }
    if (!$root) {
        $t = $xp->query('//table')->item(0);
        if ($t) $root = $dom->getElementsByTagName('body')->item(0);
    }
    if (!$root) return '';
    $p = wp_parse_url($base);
    $origin = $p['scheme'] . '://' . $p['host'];
    foreach ($root->getElementsByTagName('img') as $img) {
        $s = $img->getAttribute('src');
        if ($s && !preg_match('#^(https?:|data:)#i', $s)) $img->setAttribute('src', $s[0] === '/' ? $origin . $s : $origin . '/' . $s);
    }
    $out = '';
    foreach ($root->childNodes as $c) $out .= $dom->saveHTML($c);
    // শিটের মূল অংশ যদি root নিজেই হয় (সব সন্তান নিয়ে), পুরোটাই রাখা হলো
    $allowed = wp_kses_allowed_html('post');
    foreach (array('table','thead','tbody','tfoot','tr','th','td','caption','colgroup','col') as $t) {
        $allowed[$t] = array('class'=>true,'id'=>true,'style'=>true,'colspan'=>true,'rowspan'=>true,'align'=>true,'width'=>true,'span'=>true);
    }
    add_filter('kses_allowed_protocols', function($p){ $p[]='data'; return $p; });
    return wp_kses($out, $allowed);
}

function nu_new_result_fetcher() {
    ob_start();
    $target = 'https://results.nu.ac.bd/honours';
    $result_html = '';
    $error_msg = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nu_search'])) {
        $sid  = preg_replace('/[^A-Za-z0-9]/', '', wp_unslash($_POST['nu_sess'] ?? ''));
        $sess = $sid ? get_transient('nu_sess_' . $sid) : false;
        if (!$sess) {
            $error_msg = 'সেশন শেষ হয়ে গেছে। আবার চেষ্টা করুন।';
        } else {
            $post = array(
                '_token'           => $sess['csrf'],
                'examination_name' => sanitize_text_field($_POST['nu_exam_name'] ?? ''),
                'year'             => sanitize_text_field($_POST['nu_year'] ?? ''),
                'examination_roll' => sanitize_text_field($_POST['nu_roll'] ?? ''),
                'registration_no'  => sanitize_text_field($_POST['nu_reg'] ?? ''),
                'captcha'          => sanitize_text_field($_POST['nu_captcha'] ?? ''),
            );
            list($response, $code) = nu_new_result_curl($target, $sess['cookie'], $post, $target);
            @unlink($sess['cookie']);
            delete_transient('nu_sess_' . $sid);
            if ($code == 200 && $response) {
                $result_html = nu_new_result_extract($response, $target);
                if (!$result_html) $error_msg = 'কোনো রেজাল্ট পাওয়া যায়নি। রোল, রেজিস্ট্রেশন, সাল ও যাচাই উত্তর সঠিক কি না দেখুন।';
            } else {
                $error_msg = "সার্ভারের সাথে সংযোগ করা যায়নি (Error $code)।";
            }
        }
    }

    // প্রতিবার ফর্মের জন্য নতুন সেশন ও যাচাই প্রশ্ন
    list($sid, $question) = nu_new_result_start($target);
    ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        .nu-result-container{max-width:860px;margin:0 auto;background:#f8f9fa;padding:20px;border-radius:10px}
        .nu-sheet{overflow-x:auto;font-size:14px;color:#1d2b36}
        .nu-sheet table{width:100%;margin:14px 0;border-collapse:collapse;background:#fff}
        .nu-sheet th,.nu-sheet td{border:1px solid #dee2e6;padding:9px 12px;text-align:left}
        .nu-sheet th{background:#cfe2ff;font-weight:700}
        .nu-sheet img{max-width:100%;height:auto}
        .nu-sheet h1,.nu-sheet h2,.nu-sheet h3,.nu-sheet h4{font-size:18px;margin:10px 0}
    </style>
    <div class="nu-result-container">
        <form method="POST" action="" class="p-4 rounded-4 shadow-sm bg-white">
            <h5 class="fw-semibold text-secondary mb-4 text-center"><i class="bi bi-mortarboard-fill me-2"></i> Search Your Result for Bachelor Degree (Honours)</h5>
            <?php if ($error_msg): ?><div class="alert alert-danger text-center fw-bold"><?php echo esc_html($error_msg); ?></div><?php endif; ?>
            <input type="hidden" name="nu_sess" value="<?php echo esc_attr($sid); ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><b>Examination Name</b></label>
                    <select name="nu_exam_name" class="form-select form-select-lg" required>
                        <option value="">----Select Examination----</option>
                        <option value="2201">Bachelor Degree Honours 1st Year</option>
                        <option value="2202">Bachelor Degree Honours 2nd Year</option>
                        <option value="2203">Bachelor Degree Honours 3rd Year</option>
                        <option value="2204">Bachelor Degree Honours 4th Year</option>
                        <option value="2205">Bachelor Degree Honours Consolidated Result</option>
                    </select>
                </div>
                <div class="col-md-6"><label class="form-label"><b>Examination Year</b></label><input type="text" name="nu_year" class="form-control form-control-lg" placeholder="e.g. 2018" required></div>
                <div class="col-md-6"><label class="form-label"><b>Exam Roll</b></label><input type="text" name="nu_roll" class="form-control form-control-lg" placeholder="Enter Exam Roll"></div>
                <div class="col-md-6"><label class="form-label"><b>Registration No.</b></label><input type="text" name="nu_reg" class="form-control form-control-lg" placeholder="Enter Registration No" required></div>
                <div class="col-12 text-center">
                    <label class="form-label"><b>Solve this to verify you're human: <?php echo esc_html($question ?: ''); ?> =</b></label>
                    <input type="text" name="nu_captcha" class="form-control form-control-lg mx-auto" style="max-width:160px" placeholder="?" required autocomplete="off">
                </div>
            </div>
            <div class="text-center mt-4"><button type="submit" name="nu_search" class="btn btn-success px-4 py-2 fw-semibold rounded-pill"><i class="bi bi-search me-1"></i> Search Result</button></div>
        </form>
        <?php if ($result_html): ?>
            <div class="mt-4 p-3 rounded-4 shadow-sm bg-white nu-sheet"><?php echo $result_html; ?></div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
