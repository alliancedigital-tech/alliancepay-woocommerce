<?php
if (!defined('ABSPATH')) exit;

class ALB_Admin {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'settings']);
    }

    public static function menu() {
        // 1) Топ-левел меню (іконку можна змінити)
        add_menu_page(
            'AlliancePay',          // page_title
            'AlliancePay',              // menu_title (те, що видно в меню)
            'manage_options',             // capability
            'alb-hpp',                    // menu slug (головна сторінка = Налаштування)
            [__CLASS__, 'render_page'],   // callback
            'dashicons-money',            // іконка (або data:image/svg+xml;base64,...)
            56                             // позиція (за бажанням)
        );

        // 2) Сабменю: Налаштування (дублює головну, щоб було два пункти)
        add_submenu_page(
            'alb-hpp',                    // parent slug
            __( 'Settings', 'alliancepay' ),               // page_title
            __( 'Settings', 'alliancepay' ),               // menu_title
            'manage_options',             // capability
            'alb-hpp',                    // same slug -> показує render_page()
            [__CLASS__, 'render_page']    // callback
        );

		// 3) Сабменю: Мануал
		add_submenu_page(
			'alb-hpp',
			__( 'Documentation', 'alliancepay' ),
			__( 'Documentation', 'alliancepay' ),
			'manage_options',
			'alb-hpp-manual',
			function () {
				$manual_url = plugins_url('../docs/manual.html', __FILE__);
				echo '<div class="wrap"><h1>' . esc_html__( 'AlliancePay Plugin — Documentation', 'alliancepay' ) . '</h1>';
				echo '<iframe src="' . esc_url($manual_url) . '" style="width:100%;height:80vh;border:1px solid #ccd0d4;border-radius:8px;background:#fff"></iframe>';
				echo '</div>';
			}
	    );

    }

    public static function settings() {
		register_setting('alb-hpp', ALB_HPP_OPT, [
		  'capability' => 'manage_options',
		  'sanitize_callback' => function ($input) {
			$saved = get_option(ALB_HPP_OPT, []);
			$input = is_array($input) ? $input : [];
			foreach (['baseUrl','serviceCode','merchantId','successUrl','failUrl'] as $k) {
				if (isset($input[$k])) $input[$k] = trim((string)$input[$k]);
			}

			// Private JWK — лишаємо як JSON-рядок (НЕ перетворюємо у масив тут)
			if (isset($input['privateJwk'])) {
				$input['privateJwk'] = trim((string)$input['privateJwk']);
			}
			// API версія та мова — статичні
			$input['apiVersion'] = 'v1';
			$input['language']   = 'uk';

			// ОХОРОНА службових ключів авторизації: якщо їх немає у формі, беремо зі збережених (щоб не «зникли»)
			foreach (['deviceId','refreshToken','alb_token_issued_at','alb_token_expires_at'] as $k) {
				if (!array_key_exists($k, $input) && array_key_exists($k, $saved)) {
					$input[$k] = $saved[$k];
				}
			}
			// Повертаємо МЕРДЖ: нові значення поверх старих
			return array_merge($saved, $input);
		  }
		]);

        add_settings_section('alb_hpp_main', __( 'Main settings', 'alliancepay' ), '__return_false', 'alb-hpp');

        $fields = [
            'baseUrl'         => __( 'Base URL API (provided by the bank) *', 'alliancepay' ),
			'merchantId'      => __( 'Merchant ID (provided by the bank) *', 'alliancepay' ),
            'serviceCode'     => __( 'Service Code (provided by the bank) *', 'alliancepay' ),
            'privateJwk'      => __( 'Private JWK (provided by the bank) *', 'alliancepay' ),
            'deviceId'        => __( 'Device ID', 'alliancepay' ),
            'refreshToken'    => __( 'Refresh Token', 'alliancepay' ),
            'successUrl'      => __( 'Success URL', 'alliancepay' ),
            'failUrl'         => __( 'Fail URL', 'alliancepay' ),
        ];

        foreach ($fields as $key=>$label) {
            add_settings_field($key, esc_html($label), function() use ($key) {
                $opt = get_option(ALB_HPP_OPT, []);
                $val_raw = $opt[$key] ?? '';
                if ($key === 'privateJwk') {
                    $val = esc_textarea(is_array($val_raw) ? wp_json_encode($val_raw) : $val_raw);
                    echo '<textarea style="width:500px;height:140px" name="'.ALB_HPP_OPT.'['.$key.']" placeholder=\'{"kty":"EC","crv":"P-384","d":"...","x":"...","y":"...","alg":"ECDH-ES+A256KW","use":"enc"}\'>'.$val.'</textarea>';
                    echo '<p class="description">' . esc_html__( 'Private key for decrypting JWE. Not public.', 'alliancepay' ) . '</p>';
                } elseif ($key === 'deviceId' || $key === 'refreshToken') {
                    $val = esc_attr(is_array($val_raw) ? implode(',', $val_raw) : $val_raw);
                    echo '<input type="text" style="width:460px" value="'.$val.'" readonly disabled />';
                    echo '<p class="description">' . esc_html__( 'Information field. Updates after authorization.', 'alliancepay' ) . '</p>';
                } else {
                    $val = esc_attr(is_array($val_raw) ? implode(',', $val_raw) : $val_raw);
                    echo '<input type="text" style="width:460px" name="'.ALB_HPP_OPT.'['.$key.']" value="'.$val.'" />';
                    if ($key === 'successUrl' || $key === 'failUrl') {
                        echo '<p class="description">' . esc_html__( 'Page to return the user after payment', 'alliancepay' ) . '</p>';
                    }
                }
            }, 'alb-hpp', 'alb_hpp_main');
        }

    }

    public static function render_page() {
	    if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__( 'You are not allowed to view this page.', 'alliancepay' ) );
        }
        $opt = get_option(ALB_HPP_OPT, []);

        echo '<div class="wrap"><h1>' . esc_html__( 'AlliancePay — Settings', 'alliancepay' ) . '</h1>';
        // Інфо-блок зі статусом і кнопкою переавторизації
        $exp   = (int)($opt['alb_token_expires_at'] ?? 0);
        $exp_hours  = $exp ? floor(($exp - time()) / HOUR_IN_SECONDS) : null;

        $exp_print = $exp ? esc_html( wp_date('Y-m-d H:i:s', $exp, wp_timezone()) ) : '—';

        echo '<div class="alb-info" style="margin:12px 0;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff">';
        echo '<p style="margin:4px 0 0 0"><strong>' . esc_html__( 'Token valid until:', 'alliancepay' ) . '</strong> '.$exp_print;
        echo " <i style=\"color:#6b7280\">(" . esc_html__( 'Auto-update every 12 hours, ', 'alliancepay' );
		if ($exp) {
            $left_color = ($exp_hours<=2)?'#dc2626':(($exp_hours<=4)?'#d97706':'#6b7280');
            echo ' <span style="color:'.$left_color.'"> ' . sprintf( esc_html__( 'left ~%d hrs.', 'alliancepay' ), (int)$exp_hours ) . '</span>';
        }
        echo ')</i>';
		echo '</p>';
        echo '<p style="margin:10px 0 0 0"><button type="button" class="button button-primary" id="alb-reauthorize-now">' . esc_html__( 'Reauthorize now', 'alliancepay' ) . '</button> <span id="alb-reauth-msg" style="margin-left:8px;color:#6b7280"></span></p>';
        echo '</div>';
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
          const btn = document.getElementById('alb-reauthorize-now');
          if (!btn) return;
          const msg = document.getElementById('alb-reauth-msg');
          const url = '<?php echo esc_js( rest_url('alb/v1/reauthorize-now') ); ?>';
          const nonce = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';

          // Локалізовані рядки для JS
          const strReauthorizing = '<?php echo esc_js( __( 'Reauthorizing...', 'alliancepay' ) ); ?>';
          const strDone = '<?php echo esc_js( __( 'Done', 'alliancepay' ) ); ?>';
          const strError = '<?php echo esc_js( __( 'Error', 'alliancepay' ) ); ?>';
          const strNetworkError = '<?php echo esc_js( __( 'Network error', 'alliancepay' ) ); ?>';

          btn.addEventListener('click', async ()=>{
            btn.disabled = true; const old = btn.textContent; btn.textContent = strReauthorizing;
            msg.textContent = '';
            try {
              const r = await fetch(url, {method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':nonce}});
              const j = await r.json();
              if (j && j.ok) { msg.style.color = '#16a34a'; msg.textContent = strDone; setTimeout(()=>location.reload(), 800); }
              else { msg.style.color = '#dc2626'; msg.textContent = (j && j.error) ? j.error : strError; }
            } catch(e) { msg.style.color = '#dc2626'; msg.textContent = e.message || strNetworkError; }
            finally { btn.disabled = false; btn.textContent = old; }
          });
        });
        </script>
        <?php

        echo '<form method="post" action="options.php">';

		settings_fields('alb-hpp');
        do_settings_sections('alb-hpp');
        submit_button();
	    echo '<p><strong>' . esc_html__( 'How to use:', 'alliancepay' ) . '</strong> ' . __( 'The checkout page must contain the shortcode <code>[woocommerce_checkout]</code>. ', 'alliancepay' );
        echo __( 'Make sure <code>Merchant ID</code>, <code>Service Code</code>, and <code>Private JWK</code> are filled in.<br> ', 'alliancepay' );
		echo __( 'On the first connection, click "Reauthorize now", verify that the <code>Device ID</code> and <code>Refresh Token</code> fields are filled automatically.<br>', 'alliancepay' );
        echo __( 'Set up the <code>Success URL</code> and <code>Fail URL</code> pages for redirection after successful (or unsuccessful) payment from the bank website.<br>', 'alliancepay' );
		echo '</p>';

        echo '</form></div>';
?>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const base = '<?php echo esc_js( rest_url('alb/v1') ); ?>';
  const nonce = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';

  // Локалізовані рядки для JS
  const strSyncError = '<?php echo esc_js( __( 'Synchronization error', 'alliancepay' ) ); ?>';
  const strUpdating = '<?php echo esc_js( __( 'Updating...', 'alliancepay' ) ); ?>';

  async function post(url, body){
    const r = await fetch(url, {method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':nonce}, body: JSON.stringify(body||{})});
    return await r.json();
  }
  document.querySelectorAll('.alb-sync-one').forEach(btn=>{
     btn.addEventListener('click', async ()=>{
        btn.disabled = true; const old = btn.textContent; btn.textContent='...';
        const id = btn.getAttribute('data-id');
        const res = await post(base + '/sync-order', {id: Number(id)});
        btn.disabled = false; btn.textContent = old;
        if(res && res.ok){ location.reload(); } else { alert(res.error||strSyncError); }
     });
  });
  const bulk = document.querySelector('.alb-sync-bulk');
  if (bulk){
    bulk.addEventListener('click', async ()=>{
      bulk.disabled=true; const old=bulk.textContent; bulk.textContent=strUpdating;
      const res = await post(base + '/sync-pending', {limit: 50});
      bulk.disabled=false; bulk.textContent=old;
      if(res && res.ok){ location.reload(); } else { alert(res.error||strSyncError); }
    });
  }
});
</script>
<?php

    }

}
ALB_Admin::init();
