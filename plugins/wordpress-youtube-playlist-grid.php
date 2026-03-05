<?php
/**
 * Plugin Name: AB YouTube Playlist Grid (Elementor Shortcode)
 * Description: Shortcode [yt_playlist_grid] outputs YouTube playlist layouts for Elementor: player+list, grid banner (with optional lightbox), or single latest video. Includes responsive grid carousel mode + skip_first option.
 * Version: 1.9.1
 * Author: Abundant Life
 */

if (!defined('ABSPATH')) exit;

class AB_YT_Playlist_Grid {
  const TRANSIENT_PREFIX = 'abyt_pl_';
  const OPTION_API_KEY   = 'ab_youtube_api_key';

  public function __construct() {
    add_shortcode('yt_playlist_grid', [$this, 'shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'assets']);

    add_action('admin_menu', [$this, 'settings_menu']);
    add_action('admin_init', [$this, 'register_settings']);

    add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'action_links']);
    add_filter('plugin_row_meta', [$this, 'row_meta_links'], 10, 2);
  }

  /* ---------------- Plugin row links ---------------- */

  public function action_links($links) {
    $settings_url = admin_url('options-general.php?page=ab-yt-playlist');
    $docs_url     = admin_url('options-general.php?page=ab-yt-playlist-docs');

    $settings = '<a href="' . esc_url($settings_url) . '">Settings</a>';
    $docs     = '<a href="' . esc_url($docs_url) . '">Docs / FAQ</a>';

    array_unshift($links, $settings, $docs);
    return $links;
  }

  public function row_meta_links($links, $file) {
    if ($file !== plugin_basename(__FILE__)) return $links;
    $docs_url = admin_url('options-general.php?page=ab-yt-playlist-docs');
    $links[]  = '<a href="' . esc_url($docs_url) . '">Usage Examples</a>';
    return $links;
  }

  /* ---------------- Admin pages ---------------- */

  public function settings_menu() {
    add_options_page(
      'YouTube Playlist Settings',
      'YouTube Playlist',
      'manage_options',
      'ab-yt-playlist',
      [$this, 'settings_page']
    );

    add_options_page(
      'YouTube Playlist Docs',
      'YouTube Playlist Docs',
      'manage_options',
      'ab-yt-playlist-docs',
      [$this, 'docs_page']
    );
  }

  public function register_settings() {
    register_setting('abyt_settings', self::OPTION_API_KEY);
  }

  public function settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
      <h1>YouTube Playlist Settings</h1>
      <p>Paste your YouTube Data API key below. This plugin uses it to fetch playlist titles + thumbnails.</p>

      <form method="post" action="options.php">
        <?php settings_fields('abyt_settings'); ?>
        <table class="form-table">
          <tr>
            <th scope="row">YouTube Data API Key</th>
            <td>
              <input type="text"
                     name="<?php echo esc_attr(self::OPTION_API_KEY); ?>"
                     value="<?php echo esc_attr($this->api_key()); ?>"
                     class="regular-text"
                     placeholder="AIza..." />
              <p class="description">Used server-side. Not exposed in page source.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('Save API Key'); ?>
      </form>

      <hr />
      <p><strong>Need examples?</strong> See <a href="<?php echo esc_url(admin_url('options-general.php?page=ab-yt-playlist-docs')); ?>">Docs / FAQ</a>.</p>
    </div>
    <?php
  }

  public function docs_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
      <h1>YouTube Playlist Shortcode — Docs & FAQ</h1>

      <h2>Layouts</h2>

      <h3>1) Player + clickable list (default)</h3>
      <pre>[yt_playlist_grid list="PLxxxx" max="12" columns="2"]</pre>

      <h3>2) Banner/Grid only — open in lightbox (4 across)</h3>
      <pre>[yt_playlist_grid list="PLxxxx" layout="grid" columns="4" max="8" titles="1" aspect="16:9" dense="1" title_lines="1" grid_click="lightbox"]</pre>

      <h3>2b) Responsive Grid Carousel — Desktop 4 / Tablet 2 / Mobile 1 (page-at-a-time)</h3>
      <pre>[yt_playlist_grid list="PLxxxx" layout="grid" grid_mode="carousel" max="20" titles="1" aspect="16:9" title_lines="1" grid_click="lightbox"]</pre>

      <h3>Hide the newest sermon in the grid below (common for /sermons)</h3>
      <pre>[yt_playlist_grid list="PLxxxx" layout="grid" grid_mode="carousel" grid_click="lightbox" skip_first="1"]</pre>

      <h3>3) Single video (auto picks newest from playlist)</h3>
      <pre>[yt_playlist_grid list="PLxxxx" layout="single" cache_minutes="60"]</pre>

      <h2>Options</h2>
      <ul>
        <li><code>skip_first="1"</code> hides the first playlist item <em>only in grid/carousel</em> (does not affect the main single/player embed).</li>
      </ul>

      <h2>Notes</h2>
      <ul>
        <li>If changes don’t show immediately after updating a playlist, lower <code>cache_minutes</code> temporarily (1–5) and purge SiteGround/Cloudflare cache.</li>
      </ul>
    </div>
    <?php
  }

  /* ---------------- Helpers ---------------- */

  private function api_key() {
    $k = get_option(self::OPTION_API_KEY);
    return is_string($k) ? trim($k) : '';
  }

  private function aspect_to_padding($aspect) {
    $aspect = trim((string)$aspect);
    if ($aspect === '1:1') return '100%';
    if ($aspect === '4:3') return '75%';
    return '56.25%'; // 16:9
  }

  private function yt_api_get($url) {
    $res = wp_remote_get($url, [
      'timeout' => 12,
      'headers' => ['Accept' => 'application/json'],
    ]);

    if (is_wp_error($res)) return $res;

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);

    if ($code < 200 || $code >= 300) {
      return new WP_Error('abyt_http', 'YouTube API HTTP ' . $code);
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
      return new WP_Error('abyt_json', 'Invalid JSON from YouTube API');
    }

    return $json;
  }

  private function fetch_playlist_items($playlist_id, $max_results, $order, $cache_seconds) {
    $api_key = $this->api_key();
    if ($api_key === '') {
      return new WP_Error('abyt_no_key', 'Missing YouTube API key. Go to Settings → YouTube Playlist.');
    }

    $playlist_id   = trim((string)$playlist_id);
    $max_results   = max(1, min(50, (int)$max_results));
    $cache_seconds = max(60, (int)$cache_seconds);

    $order = ($order === 'reverse') ? 'reverse' : 'default';

    $cache_key = self::TRANSIENT_PREFIX . md5($playlist_id . '|' . $max_results . '|' . $order);
    $cached = get_transient($cache_key);
    if (is_array($cached)) return $cached;

    $url = add_query_arg([
      'part'       => 'snippet',
      'playlistId' => $playlist_id,
      'maxResults' => $max_results,
      'key'        => $api_key,
    ], 'https://www.googleapis.com/youtube/v3/playlistItems');

    $data = $this->yt_api_get($url);
    if (is_wp_error($data)) return $data;

    $items = [];
    foreach (($data['items'] ?? []) as $it) {
      $sn  = $it['snippet'] ?? [];
      $rid = $sn['resourceId'] ?? [];
      $vid = $rid['videoId'] ?? '';
      if (!$vid) continue;

      $title = (string)($sn['title'] ?? '');
      if ($title === 'Private video' || $title === 'Deleted video') continue;

      $thumbs = $sn['thumbnails'] ?? [];
      $thumb  = $thumbs['medium']['url'] ?? ($thumbs['default']['url'] ?? '');
      $pos    = (int)($sn['position'] ?? 0);

      $items[] = [
        'videoId'  => (string)$vid,
        'title'    => $title,
        'thumb'    => (string)$thumb,
        'position' => $pos,
      ];
    }

    // Newest at TOP => position 0 first for order="default".
    usort($items, function($a, $b) use ($order) {
      $pa = (int)($a['position'] ?? 0);
      $pb = (int)($b['position'] ?? 0);
      return ($order === 'reverse') ? ($pb <=> $pa) : ($pa <=> $pb);
    });

    set_transient($cache_key, $items, $cache_seconds);
    return $items;
  }

  /* ---------------- Frontend assets ---------------- */

  public function assets() {
    $css = <<<'CSS'
.abyt-wrap{max-width:100%}

.abyt-player{position:relative;width:100%;padding-top:56.25%;background:#000;border-radius:5px;overflow:hidden}
.abyt-player iframe{position:absolute;inset:0;width:100%;height:100%;border:0}

.abyt-grid{display:grid;gap:10px;margin-top:16px}

.abyt-item{display:flex;gap:12px;align-items:flex-start;text-decoration:none;border:1px solid rgba(0,0,0,.10);border-radius:5px;overflow:hidden;padding:10px;background:#fff;transition:transform .08s ease}
.abyt-item:hover{transform:translateY(-1px)}

.abyt-thumb{width:140px;max-width:40%;flex:0 0 auto;border-radius:5px;overflow:hidden;background:#eee}
.abyt-thumb img{width:100%;height:auto;display:block}

.abyt-meta{flex:1 1 auto;min-width:0}
.abyt-title{font-size:14px;line-height:1.35;margin:2px 0 0;color:#111;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

/* Grid/Banner tweaks */
.abyt-wrap[data-layout="grid"] .abyt-item{flex-direction:column;gap:10px;padding:10px}
.abyt-wrap[data-layout="grid"][data-dense="1"] .abyt-grid{gap:10px;margin-top:12px}
.abyt-wrap[data-layout="grid"][data-dense="1"] .abyt-item{padding:8px;gap:8px}
.abyt-wrap[data-layout="grid"] .abyt-thumb{width:100%;max-width:100%}

.abyt-wrap[data-layout="grid"] .abyt-thumb{position:relative}
.abyt-wrap[data-layout="grid"] .abyt-thumb::before{content:"";display:block;padding-top:var(--abyt-aspect,56.25%)}
.abyt-wrap[data-layout="grid"] .abyt-thumb img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}

.abyt-wrap[data-layout="grid"] .abyt-title{-webkit-line-clamp:var(--abyt-title-lines,2)}
.abyt-wrap[data-layout="grid"][data-title-lines="0"] .abyt-meta{display:none}

/* Play icon overlay for lightbox mode */
.abyt-wrap[data-layout="grid"][data-grid-click="lightbox"] .abyt-thumb::after{content:"";position:absolute;inset:0;background:rgba(0,0,0,.18);opacity:0;transition:opacity .12s ease}
.abyt-wrap[data-layout="grid"][data-grid-click="lightbox"] .abyt-item:hover .abyt-thumb::after{opacity:1}
.abyt-wrap[data-layout="grid"][data-grid-click="lightbox"] .abyt-play{position:absolute;left:50%;top:50%;width:56px;height:56px;transform:translate(-50%,-50%);border-radius:999px;background:rgba(0,0,0,.45);border:1px solid rgba(255,255,255,.35);opacity:.92;pointer-events:none;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px)}
.abyt-wrap[data-layout="grid"][data-grid-click="lightbox"] .abyt-play::before{content:"";display:block;width:0;height:0;border-left:14px solid rgba(255,255,255,.95);border-top:9px solid transparent;border-bottom:9px solid transparent;margin-left:3px}

.abyt-wrap[data-layout="single"] .abyt-player{max-width:1100px;margin:0 auto}

.abyt-error{padding:12px;border:1px solid rgba(255,0,0,.25);background:rgba(255,0,0,.05);border-radius:5px}

/* Lightbox */
.abyt-lightbox{position:fixed;inset:0;background:rgba(0,0,0,.72);display:none;align-items:center;justify-content:center;padding:24px;z-index:999999}
.abyt-lightbox.is-open{display:flex}
.abyt-lightbox__panel{width:min(1100px,100%);background:#111;border-radius:5px;overflow:hidden;position:relative;box-shadow:0 10px 30px rgba(0,0,0,.35)}
.abyt-lightbox__close{position:absolute;top:10px;right:10px;border:0;background:rgba(255,255,255,.10);color:#fff;font-size:18px;line-height:1;padding:10px 12px;border-radius:5px;cursor:pointer}
.abyt-lightbox__close:hover{background:rgba(255,255,255,.16)}
.abyt-lightbox__player{position:relative;width:100%;padding-top:56.25%;background:#000}
.abyt-lightbox__player iframe{position:absolute;inset:0;width:100%;height:100%;border:0}

/* Grid Carousel mode */
.abyt-carousel{margin-top:16px}
.abyt-carousel__frame{display:flex;align-items:center;gap:10px}
.abyt-carousel__btn{
  width:44px;height:44px;flex:0 0 auto;
  border-radius:999px;
  border:1px solid rgba(0,0,0,.14);
  background:#fff;
  cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  transition:transform .08s ease, background .12s ease;
}
.abyt-carousel__btn:hover{transform:translateY(-1px)}
.abyt-carousel__btn:active{transform:translateY(0)}
.abyt-carousel__viewport{flex:1 1 auto;overflow:hidden}
.abyt-carousel__track{display:flex;transition:transform .25s ease;will-change:transform}
.abyt-carousel__slide{flex:0 0 100%} /* mobile default */
.abyt-carousel__slide .abyt-item{margin:0 5px;}

/* Tablet: 2 across */
@media (min-width:768px){
  .abyt-carousel__slide{flex:0 0 50%}
}

/* Desktop: 4 across */
@media (min-width:1024px){
  .abyt-carousel__slide{flex:0 0 25%}
}

/* Mobile tweaks: more spacing + smaller arrows */
@media (max-width:767px){
  .abyt-grid{grid-template-columns:1fr!important}
  .abyt-thumb{width:120px}
  .abyt-lightbox{padding:12px}

  .abyt-carousel{margin-top:20px;margin-bottom:18px}
  .abyt-carousel__frame{gap:8px}
  .abyt-carousel__slide .abyt-item{padding:10px}

  .abyt-wrap[data-layout="grid"][data-grid-click="lightbox"] .abyt-play{width:46px;height:46px}
  .abyt-wrap[data-layout="grid"][data-grid-click="lightbox"] .abyt-play::before{border-left-width:12px;border-top-width:8px;border-bottom-width:8px}

  .abyt-carousel__btn{width:32px;height:32px;font-size:16px}
}
CSS;

    wp_register_style('abyt-style', false);
    wp_enqueue_style('abyt-style');
    wp_add_inline_style('abyt-style', $css);

    $js = <<<'JS'
(function(){
  function closest(el, sel){
    while(el && el.nodeType === 1){
      if(el.matches(sel)) return el;
      el = el.parentElement;
    }
    return null;
  }

  // Player layout: click-to-swap
  document.addEventListener("click", function(e){
    var a = closest(e.target, "[data-abyt-video]");
    if(!a) return;

    var wrap = closest(a, ".abyt-wrap");
    if(!wrap) return;

    var iframe = wrap.querySelector("iframe[data-abyt-iframe]");
    if(!iframe) return;

    e.preventDefault();

    var videoId = a.getAttribute("data-abyt-video");
    var base = iframe.getAttribute("data-abyt-base");
    var params = iframe.getAttribute("data-abyt-params") || "";
    iframe.setAttribute("src", base + videoId + params);
  }, true);

  // Lightbox
  var lightbox = null;
  var lightboxIframe = null;

  function ensureLightbox(){
    if(lightbox) return;

    lightbox = document.createElement("div");
    lightbox.className = "abyt-lightbox";
    lightbox.innerHTML =
      '<div class="abyt-lightbox__panel" role="dialog" aria-modal="true" aria-label="Video player">' +
        '<button type="button" class="abyt-lightbox__close" aria-label="Close">✕</button>' +
        '<div class="abyt-lightbox__player">' +
          '<iframe allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy"></iframe>' +
        '</div>' +
      '</div>';
    document.body.appendChild(lightbox);

    lightboxIframe = lightbox.querySelector("iframe");

    lightbox.addEventListener("click", function(ev){
      if(ev.target === lightbox) closeLightbox();
    });

    var btn = lightbox.querySelector(".abyt-lightbox__close");
    if(btn) btn.addEventListener("click", closeLightbox);

    document.addEventListener("keydown", function(ev){
      if(ev.key === "Escape") closeLightbox();
    });
  }

  function openLightbox(src){
    ensureLightbox();
    if(!lightboxIframe) return;
    lightboxIframe.setAttribute("src", src);
    lightbox.classList.add("is-open");
  }

  function closeLightbox(){
    if(!lightbox) return;
    lightbox.classList.remove("is-open");
    if(lightboxIframe) lightboxIframe.setAttribute("src", "");
  }

  document.addEventListener("click", function(e){
    var a = closest(e.target, "[data-abyt-lightbox]");
    if(!a) return;

    var src = a.getAttribute("data-abyt-lightbox");
    if(!src) return;

    e.preventDefault();
    openLightbox(src);
  }, true);

  // Responsive, page-at-a-time carousel
  function initCarousels(){
    var carousels = document.querySelectorAll('.abyt-carousel[data-abyt-carousel="1"]');
    if(!carousels.length) return;

    function perView(){
      var w = window.innerWidth || 1024;
      if(w >= 1024) return 4;
      if(w >= 768) return 2;
      return 1;
    }

    carousels.forEach(function(c){
      var track = c.querySelector(".abyt-carousel__track");
      var slides = c.querySelectorAll(".abyt-carousel__slide");
      var prev = c.querySelector("[data-abyt-prev]");
      var next = c.querySelector("[data-abyt-next]");
      if(!track || !slides.length || !prev || !next) return;

      var page = 0;

      function maxPage(){
        var pv = perView();
        return Math.max(0, Math.ceil(slides.length / pv) - 1);
      }

      function update(){
        var mp = maxPage();
        if(page > mp) page = mp;

        var x = -(page * 100);
        track.style.transform = "translateX(" + x + "%)";

        if(mp === 0){
          prev.style.display = "none";
          next.style.display = "none";
        } else {
          prev.style.display = "";
          next.style.display = "";
        }
      }

      function go(delta){
        var mp = maxPage();
        var n = page + delta;
        if(n < 0) n = mp;
        if(n > mp) n = 0;
        page = n;
        update();
      }

      prev.addEventListener("click", function(){ go(-1); });
      next.addEventListener("click", function(){ go(1); });

      c.addEventListener("keydown", function(ev){
        if(ev.key === "ArrowLeft") { ev.preventDefault(); go(-1); }
        if(ev.key === "ArrowRight"){ ev.preventDefault(); go(1); }
      });

      update();
      window.addEventListener("resize", update);
    });
  }

  if(document.readyState === "loading"){
    document.addEventListener("DOMContentLoaded", initCarousels);
  } else {
    initCarousels();
  }
})();
JS;

    wp_register_script('abyt-script', false, [], null, true);
    wp_enqueue_script('abyt-script');
    wp_add_inline_script('abyt-script', $js);
  }

  /* ---------------- Shortcode ---------------- */

  public function shortcode($atts) {
    $atts = shortcode_atts([
      'list'          => '',
      'video'         => '',
      'max'           => 12,
      'columns'       => 2,
      'order'         => 'default',   // newest at top
      'privacy'       => '1',
      'titles'        => '1',
      'start'         => '',
      'rel'           => '0',
      'controls'      => '1',
      'layout'        => 'player',    // player | grid | single
      'target'        => '_blank',
      'cache_minutes' => 60,
      'hide_current'  => '1',

      'aspect'        => '16:9',
      'dense'         => '0',
      'title_lines'   => '2',
      'grid_click'    => 'youtube',   // youtube | lightbox
      'grid_mode'     => 'grid',      // grid | carousel

      'skip_first'    => '0',         // NEW: grid/carousel only
    ], $atts, 'yt_playlist_grid');

    $playlist_id = trim((string)$atts['list']);
    if ($playlist_id === '') {
      return '<div class="abyt-wrap abyt-error"><strong>Missing playlist ID.</strong> Use [yt_playlist_grid list="PLxxxx"]</div>';
    }

    $layout  = in_array($atts['layout'], ['player', 'grid', 'single'], true) ? $atts['layout'] : 'player';
    $order   = ($atts['order'] === 'reverse') ? 'reverse' : 'default';
    $privacy = ($atts['privacy'] === '1');

    $cols = max(1, min(6, (int)$atts['columns']));
    $grid_style = 'grid-template-columns: repeat(' . $cols . ', minmax(0, 1fr));';

    $show_titles   = ($atts['titles'] === '1');
    $cache_seconds = max(1, (int)$atts['cache_minutes']) * MINUTE_IN_SECONDS;

    $items = $this->fetch_playlist_items($playlist_id, $atts['max'], $order, $cache_seconds);
    if (is_wp_error($items)) {
      return '<div class="abyt-wrap abyt-error"><strong>Error:</strong> ' . esc_html($items->get_error_message()) . '</div>';
    }
    if (!is_array($items) || count($items) === 0) {
      return '<div class="abyt-wrap abyt-error"><strong>No videos found.</strong></div>';
    }

    $host = $privacy ? 'https://www.youtube-nocookie.com' : 'https://www.youtube.com';
    $base = $host . '/embed/';

    // Add playlist context to bias "pause overlay" to your content
    $query = add_query_arg([
      'listType'       => 'playlist',
      'list'           => $playlist_id,
      'rel'            => (int)$atts['rel'],
      'controls'       => (int)$atts['controls'],
      'modestbranding' => 1,
    ], '');

    if ($query !== '' && $query[0] !== '?') {
      $query = '?' . ltrim($query, '?');
    }

    $dense          = ($atts['dense'] === '1') ? '1' : '0';
    $title_lines    = (string) max(0, min(3, (int)$atts['title_lines']));
    $aspect_padding = $this->aspect_to_padding($atts['aspect']);
    $grid_click     = ($atts['grid_click'] === 'lightbox') ? 'lightbox' : 'youtube';
    $grid_mode      = ($atts['grid_mode'] === 'carousel') ? 'carousel' : 'grid';
    $target         = in_array($atts['target'], ['_blank', '_self'], true) ? $atts['target'] : '_blank';

    $skip_first = ($atts['skip_first'] === '1');

    // SINGLE (newest only)
    if ($layout === 'single') {
      $video_id = trim((string)$atts['video']);
      if ($video_id === '') $video_id = (string)($items[0]['videoId'] ?? '');
      if ($video_id === '') return '<div class="abyt-wrap abyt-error"><strong>No video to embed.</strong></div>';

      $src = $base . rawurlencode($video_id) . $query;

      ob_start(); ?>
      <div class="abyt-wrap" data-layout="single">
        <div class="abyt-player">
          <iframe
            src="<?php echo esc_url($src); ?>"
            title="YouTube video"
            loading="lazy"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            allowfullscreen></iframe>
        </div>
      </div>
      <?php
      return ob_get_clean();
    }

    // GRID (grid or carousel)
    if ($layout === 'grid') {
      $grid_items = $items;
      if ($skip_first && count($grid_items) > 1) {
        $grid_items = array_slice($grid_items, 1);
      }

      ob_start(); ?>
      <div class="abyt-wrap"
           data-layout="grid"
           data-dense="<?php echo esc_attr($dense); ?>"
           data-title-lines="<?php echo esc_attr($title_lines); ?>"
           data-grid-click="<?php echo esc_attr($grid_click); ?>"
           data-grid-mode="<?php echo esc_attr($grid_mode); ?>"
           style="<?php echo esc_attr('--abyt-aspect:' . $aspect_padding . '; --abyt-title-lines:' . $title_lines . ';'); ?>">

        <?php if ($grid_mode === 'carousel'): ?>
          <div class="abyt-carousel" data-abyt-carousel="1" tabindex="0">
            <div class="abyt-carousel__frame">
              <button type="button" class="abyt-carousel__btn" data-abyt-prev aria-label="Previous sermons">‹</button>

              <div class="abyt-carousel__viewport">
                <div class="abyt-carousel__track">
                  <?php foreach ($grid_items as $v):
                    $vid = (string)($v['videoId'] ?? '');
                    if ($vid === '') continue;

                    $title = (string)($v['title'] ?? '');
                    $thumb = (string)($v['thumb'] ?? '');

                    $yt_href = 'https://www.youtube.com/watch?v=' . rawurlencode($vid) . '&list=' . rawurlencode($playlist_id);
                    $lb_src  = $base . rawurlencode($vid) . $query;
                    ?>
                    <div class="abyt-carousel__slide">
                      <a class="abyt-item"
                         href="<?php echo esc_url($yt_href); ?>"
                         <?php if ($grid_click === 'youtube'): ?>
                           target="<?php echo esc_attr($target); ?>" rel="noopener"
                         <?php else: ?>
                           data-abyt-lightbox="<?php echo esc_url($lb_src); ?>"
                         <?php endif; ?>>
                        <span class="abyt-thumb">
                          <?php if ($thumb !== ''): ?>
                            <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" />
                          <?php endif; ?>
                          <?php if ($grid_click === 'lightbox'): ?>
                            <span class="abyt-play" aria-hidden="true"></span>
                          <?php endif; ?>
                        </span>

                        <?php if ($show_titles && $title_lines !== '0'): ?>
                          <span class="abyt-meta">
                            <span class="abyt-title"><?php echo esc_html($title); ?></span>
                          </span>
                        <?php endif; ?>
                      </a>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <button type="button" class="abyt-carousel__btn" data-abyt-next aria-label="Next sermons">›</button>
            </div>
          </div>
        <?php else: ?>
          <div class="abyt-grid" style="<?php echo esc_attr($grid_style); ?>">
            <?php foreach ($grid_items as $v):
              $vid = (string)($v['videoId'] ?? '');
              if ($vid === '') continue;

              $title = (string)($v['title'] ?? '');
              $thumb = (string)($v['thumb'] ?? '');

              $yt_href = 'https://www.youtube.com/watch?v=' . rawurlencode($vid) . '&list=' . rawurlencode($playlist_id);
              $lb_src  = $base . rawurlencode($vid) . $query;
              ?>
              <a class="abyt-item"
                 href="<?php echo esc_url($yt_href); ?>"
                 <?php if ($grid_click === 'youtube'): ?>
                   target="<?php echo esc_attr($target); ?>" rel="noopener"
                 <?php else: ?>
                   data-abyt-lightbox="<?php echo esc_url($lb_src); ?>"
                 <?php endif; ?>>
                <span class="abyt-thumb">
                  <?php if ($thumb !== ''): ?>
                    <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" />
                  <?php endif; ?>
                  <?php if ($grid_click === 'lightbox'): ?>
                    <span class="abyt-play" aria-hidden="true"></span>
                  <?php endif; ?>
                </span>

                <?php if ($show_titles && $title_lines !== '0'): ?>
                  <span class="abyt-meta">
                    <span class="abyt-title"><?php echo esc_html($title); ?></span>
                  </span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      </div>
      <?php
      return ob_get_clean();
    }

    // PLAYER + LIST
    $start_vid = trim((string)$atts['start']);
    if ($start_vid === '') $start_vid = (string)($items[0]['videoId'] ?? '');
    if ($start_vid === '') return '<div class="abyt-wrap abyt-error"><strong>No video to embed.</strong></div>';

    $hide_current = ($atts['hide_current'] === '1');
    $player_src = $base . rawurlencode($start_vid) . $query;

    ob_start(); ?>
    <div class="abyt-wrap" data-layout="player">
      <div class="abyt-player">
        <iframe
          data-abyt-iframe
          data-abyt-base="<?php echo esc_attr($base); ?>"
          data-abyt-params="<?php echo esc_attr($query); ?>"
          src="<?php echo esc_url($player_src); ?>"
          title="YouTube playlist player"
          loading="lazy"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
          allowfullscreen></iframe>
      </div>

      <div class="abyt-grid" style="<?php echo esc_attr($grid_style); ?>">
        <?php foreach ($items as $v):
          $vid = (string)($v['videoId'] ?? '');
          if ($vid === '') continue;
          if ($hide_current && $vid === $start_vid) continue;

          $title = (string)($v['title'] ?? '');
          $thumb = (string)($v['thumb'] ?? '');
          ?>
          <a class="abyt-item" href="#" data-abyt-video="<?php echo esc_attr($vid); ?>">
            <span class="abyt-thumb">
              <?php if ($thumb !== ''): ?>
                <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" />
              <?php endif; ?>
            </span>
            <span class="abyt-meta">
              <?php if ($show_titles): ?>
                <span class="abyt-title"><?php echo esc_html($title); ?></span>
              <?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }
}

new AB_YT_Playlist_Grid();