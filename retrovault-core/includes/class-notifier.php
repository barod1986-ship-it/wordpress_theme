<?php
/**
 * إشعارات المتابعين: من أضاف لعبة لمفضلته يُبلَّغ عندما
 *   - يتغير رقم إصدارها (تحديث جديد)، أو
 *   - تُنشر تدوينة في يوميات التطوير مرتبطة بها.
 *
 * قناتان:
 *   1. داخل الموقع: «الجديد في ألعابك» في صفحة «حسابي» + شارة عدد في رأس الصفحة.
 *   2. البريد: يُرسل في الخلفية على دفعات (WP-Cron) مع رابط إيقاف بضغطة واحدة.
 *
 * السجل في جدول {prefix}rv_events (حدث لكل لعبة)، ويُقرأ لكل عضو حسب ما يتابعه
 * (الأحداث بعد تاريخ متابعته فقط).
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Notifier {

	const HOOK       = 'retrovault_send_notices';
	const BATCH      = 40;
	const SEEN_META  = '_rv_notices_seen';
	const EMAIL_META = '_rv_notify_email';
	const POST_FLAG  = '_rv_notified';

	/** @var array<int,array> */
	private static $cache = array();

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'send_batch' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'init', array( __CLASS__, 'unsubscribe' ), 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rv_events';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			type varchar(20) NOT NULL,
			ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
			version varchar(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY game_time (game_id,created_at)
			) {$charset};"
		);
	}

	/** عند الترقية: التدوينات المنشورة سابقاً لا تُرسَل عنها إشعارات. */
	public static function mark_existing_posts() {
		$ids = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			add_post_meta( $id, self::POST_FLAG, 1, true );
		}
	}

	/* ---------------------------------------------------------------------
	 * المحفّزات
	 * ------------------------------------------------------------------ */

	/**
	 * تغيّر رقم إصدار لعبة منشورة (يستدعيه حفظ صفحة اللعبة).
	 * البريد مرة واحدة كل 6 ساعات للعبة كحد أقصى، حتى لا تُرهق المتابعين بتحديثات متتالية.
	 *
	 * @param int    $game_id رقم اللعبة.
	 * @param string $version الإصدار الجديد.
	 * @param bool   $email   إرسال بريد.
	 */
	public static function game_version_changed( $game_id, $version, $email ) {
		if ( 'publish' !== get_post_status( $game_id ) || '' === (string) $version ) {
			return;
		}
		$lock  = 'rv_ver_mail_' . (int) $game_id;
		$email = $email && ! get_transient( $lock );
		if ( $email ) {
			set_transient( $lock, 1, 6 * HOUR_IN_SECONDS );
		}
		self::record( array( (int) $game_id ), 'version', 0, (string) $version, $email );
	}

	/**
	 * نُشرت تدوينة مرتبطة بألعاب (مرة واحدة لكل تدوينة).
	 * في محرر المكوّنات تُنشر التدوينة قبل حفظ «الألعاب المرتبطة»، لذلك يُستدعى هذا
	 * عند تغيّر الحالة وعند حفظ الصندوق معاً، ولا يُعلَّم إلا عند وجود ألعاب.
	 *
	 * @param int $post_id رقم التدوينة.
	 */
	public static function devlog_published( $post_id ) {
		if ( 'post' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) || get_post_meta( $post_id, self::POST_FLAG, true ) ) {
			return;
		}
		$games = Devlog::games_for_post( $post_id );
		if ( ! $games ) {
			return;
		}
		update_post_meta( $post_id, self::POST_FLAG, 1 );
		self::record( $games, 'devlog', (int) $post_id, '', true );
	}

	/**
	 * @param string   $new  الحالة الجديدة.
	 * @param string   $old  الحالة السابقة.
	 * @param \WP_Post $post المحتوى.
	 */
	public static function on_transition( $new, $old, $post ) {
		if ( 'publish' === $new && 'publish' !== $old && 'post' === $post->post_type ) {
			self::devlog_published( $post->ID );
		}
	}

	/**
	 * @param int[]  $game_ids الألعاب.
	 * @param string $type     version|devlog.
	 * @param int    $ref_id   رقم التدوينة.
	 * @param string $version  الإصدار.
	 * @param bool   $email    جدولة بريد.
	 */
	private static function record( $game_ids, $type, $ref_id, $version, $email ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		foreach ( $game_ids as $game_id ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'game_id'    => (int) $game_id,
					'type'       => $type,
					'ref_id'     => (int) $ref_id,
					'version'    => substr( $version, 0, 40 ),
					'created_at' => $now,
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
		}
		self::$cache = array();
		do_action( 'retrovault_notice_recorded', $game_ids, $type, $ref_id, $version );

		if ( $email && Settings::get( 'notify_email' ) && Favorites::followers( $game_ids ) ) {
			wp_schedule_single_event(
				time() + 10,
				self::HOOK,
				array(
					array(
						'type'    => $type,
						'games'   => array_map( 'intval', $game_ids ),
						'ref'     => (int) $ref_id,
						'version' => (string) $version,
						'offset'  => 0,
						'job'     => strtolower( wp_generate_password( 8, false ) ),
					),
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * البريد
	 * ------------------------------------------------------------------ */

	/**
	 * دفعة من الرسائل؛ تجدول الدفعة التالية إن بقي متابعون.
	 *
	 * @param array $job بيانات المهمة.
	 */
	public static function send_batch( $job ) {
		$job   = wp_parse_args(
			(array) $job,
			array(
				'type'    => '',
				'games'   => array(),
				'ref'     => 0,
				'version' => '',
				'offset'  => 0,
				'job'     => '',
			)
		);
		$users = Favorites::followers( $job['games'] );
		foreach ( array_slice( $users, (int) $job['offset'], self::BATCH ) as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user && is_email( $user->user_email ) && self::email_enabled( $user_id ) ) {
				self::mail( $user, $job );
			}
		}
		if ( count( $users ) > (int) $job['offset'] + self::BATCH ) {
			$job['offset'] = (int) $job['offset'] + self::BATCH;
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array( $job ) );
		}
	}

	/**
	 * @param \WP_User $user العضو.
	 * @param array    $job  بيانات المهمة.
	 */
	private static function mail( $user, $job ) {
		$msg = array(
			'site'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'heading' => '',
			'intro'   => '',
			'label'   => '',
			'items'   => array(),
			'more'    => '',
			'text'    => '',
			'cta'     => '',
			'url'     => '',
			'image'   => '',
			'wide'    => false,
			'unsub'   => self::unsubscribe_url( $user->ID ),
		);

		if ( 'version' === $job['type'] ) {
			$game = Games::get( (int) $job['games'][0] );
			if ( ! $game ) {
				return;
			}
			/* translators: 1: game, 2: version */
			$subject = sprintf( __( 'تحديث جديد لـ %1$s: الإصدار %2$s', 'retrovault-core' ), $game['title'], $job['version'] );
			// أحدث قسم في سجل التحديثات، نقاطاً بلا علامات «-» التي كتبها صاحب اللعبة.
			$log = Games::changelog_items( $game['changelog'], 5 );
			$msg = array_merge(
				$msg,
				array(
					'heading' => $game['title'],
					/* translators: 1: version, 2: game */
					'intro'   => sprintf( __( 'نزل الإصدار %1$s من «%2$s»، وهي في مفضلتك.', 'retrovault-core' ), $job['version'], $game['title'] ),
					'label'   => __( 'ما الجديد', 'retrovault-core' ),
					'items'   => $log['items'],
					'more'    => $log['more'] ? __( 'والمزيد في سجل التحديثات بصفحة اللعبة.', 'retrovault-core' ) : '',
					'cta'     => __( 'العب الآن', 'retrovault-core' ),
					'url'     => $game['url'],
					'image'   => $game['cover_id'] ? (string) wp_get_attachment_image_url( $game['cover_id'], 'medium' ) : '',
				)
			);
		} else {
			$post = get_post( (int) $job['ref'] );
			if ( ! $post || 'publish' !== $post->post_status ) {
				return;
			}
			$names = array();
			foreach ( $job['games'] as $game_id ) {
				if ( Favorites::has( (int) $game_id, $user->ID ) ) {
					$names[] = get_the_title( (int) $game_id );
				}
			}
			/* translators: %s: post title */
			$subject = sprintf( __( 'من يوميات التطوير: %s', 'retrovault-core' ), get_the_title( $post ) );
			$msg     = array_merge(
				$msg,
				array(
					'heading' => get_the_title( $post ),
					/* translators: %s: game names */
					'intro'   => sprintf( __( 'تدوينة جديدة عن %s من ألعاب مفضلتك.', 'retrovault-core' ), implode( __( ' و', 'retrovault-core' ), $names ) ),
					'text'    => wp_strip_all_tags( get_the_excerpt( $post ) ),
					'cta'     => __( 'اقرأ التدوينة', 'retrovault-core' ),
					'url'     => get_permalink( $post ),
					// صورة التدوينة عريضة غالباً، فتُعرض بعرض الرسالة لا مصغّرة كغلاف اللعبة.
					'image'   => has_post_thumbnail( $post ) ? (string) get_the_post_thumbnail_url( $post, 'medium_large' ) : '',
					'wide'    => true,
				)
			);
		}

		self::send( $user->user_email, $subject, $msg );
	}

	/**
	 * رسالة HTML مع نسخة نصية لبرامج البريد التي لا تعرض HTML (وتقلل تصنيفها رسائل مزعجة)،
	 * واسم الموقع مرسلاً بدل «WordPress» ما لم تحدد إضافة بريد اسماً آخر.
	 *
	 * @param string $to      البريد.
	 * @param string $subject العنوان.
	 * @param array  $msg     محتوى الرسالة.
	 */
	private static function send( $to, $subject, $msg ) {
		$text = self::text( $msg );
		$alt  = static function ( $mailer ) use ( $text ) {
			$mailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- خاصية PHPMailer.
		};
		$from = static function ( $name ) use ( $msg ) {
			return 'WordPress' === $name ? $msg['site'] : $name;
		};
		add_action( 'phpmailer_init', $alt );
		add_filter( 'wp_mail_from_name', $from );
		wp_mail(
			$to,
			$subject,
			self::template( $msg ),
			array(
				'Content-Type: text/html; charset=UTF-8',
				'List-Unsubscribe: <' . $msg['unsub'] . '>',
				'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
			)
		);
		remove_action( 'phpmailer_init', $alt );
		remove_filter( 'wp_mail_from_name', $from );
	}

	/**
	 * النسخة النصية من الرسالة.
	 *
	 * @param array $msg محتوى الرسالة.
	 */
	private static function text( $msg ) {
		$lines = array( $msg['site'], '', $msg['heading'], $msg['intro'], '' );
		if ( $msg['items'] ) {
			if ( $msg['label'] ) {
				$lines[] = $msg['label'] . ':';
			}
			foreach ( $msg['items'] as $item ) {
				$lines[] = '• ' . $item;
			}
			if ( $msg['more'] ) {
				$lines[] = $msg['more'];
			}
			$lines[] = '';
		} elseif ( $msg['text'] ) {
			$lines[] = $msg['text'];
			$lines[] = '';
		}
		$lines[] = $msg['cta'] . ': ' . $msg['url'];
		$lines[] = '';
		$lines[] = '-- ';
		$lines[] = __( 'وصلتك هذه الرسالة لأن اللعبة في مفضلتك.', 'retrovault-core' );
		$lines[] = __( 'إيقاف رسائل التحديثات', 'retrovault-core' ) . ': ' . $msg['unsub'];
		return html_entity_decode( implode( "\n", $lines ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * قالب الرسالة بتنسيق مضمّن (تدعمه برامج البريد).
	 *
	 * @param array $msg محتوى الرسالة.
	 */
	private static function template( $msg ) {
		$rtl   = is_rtl();
		$dir   = $rtl ? 'rtl' : 'ltr';
		$align = $rtl ? 'right' : 'left';
		ob_start();
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $msg['heading'] ); ?></title>
</head>
<body style="margin:0;padding:0;background:#cfcdd4;color:#25232b;font-family:Tahoma,Arial,sans-serif;">
<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#cfcdd4;"><?php echo esc_html( $msg['intro'] ); ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#cfcdd4;"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#e3e1e7;border-radius:16px;border:1px solid #9b98a5;">
<tr><td dir="<?php echo esc_attr( $dir ); ?>" style="padding:24px;text-align:<?php echo esc_attr( $align ); ?>;">
<p style="margin:0 0 8px;font-size:13px;color:#4e4b57;"><?php echo esc_html( $msg['site'] ); ?></p>
		<?php if ( $msg['image'] && $msg['wide'] ) : ?>
<img src="<?php echo esc_url( $msg['image'] ); ?>" alt="" width="472" style="display:block;width:100%;max-width:472px;height:auto;margin:0 0 16px;border-radius:10px;">
		<?php elseif ( $msg['image'] ) : ?>
<img src="<?php echo esc_url( $msg['image'] ); ?>" alt="" width="140" style="display:block;width:140px;max-width:100%;height:auto;margin:0 0 16px;border-radius:8px;border:3px solid #1e1d23;">
		<?php endif; ?>
<h1 style="margin:0 0 10px;font-size:22px;line-height:1.4;"><?php echo esc_html( $msg['heading'] ); ?></h1>
<p style="margin:0 0 16px;font-size:16px;line-height:1.8;"><?php echo esc_html( $msg['intro'] ); ?></p>
		<?php if ( $msg['items'] ) : ?>
			<?php if ( $msg['label'] ) : ?>
<p style="margin:0 0 4px;font-size:13px;font-weight:bold;color:#4e4b57;"><?php echo esc_html( $msg['label'] ); ?></p>
			<?php endif; ?>
<ul style="margin:0 0 <?php echo $msg['more'] ? '8' : '20'; ?>px;padding:12px 14px;padding-<?php echo esc_attr( $align ); ?>:34px;background:#bdbac5;border-radius:10px;font-size:15px;line-height:1.8;">
			<?php foreach ( $msg['items'] as $item ) : ?>
<li style="margin:0 0 2px;"><?php echo esc_html( $item ); ?></li>
			<?php endforeach; ?>
</ul>
			<?php if ( $msg['more'] ) : ?>
<p style="margin:0 0 20px;font-size:13px;color:#4e4b57;"><?php echo esc_html( $msg['more'] ); ?></p>
			<?php endif; ?>
		<?php elseif ( $msg['text'] ) : ?>
<p style="margin:0 0 20px;padding:12px 14px;background:#bdbac5;border-radius:10px;font-size:15px;line-height:1.8;"><?php echo esc_html( $msg['text'] ); ?></p>
		<?php endif; ?>
<a href="<?php echo esc_url( $msg['url'] ); ?>" style="display:inline-block;padding:12px 24px;background:#c8323a;color:#ffffff;text-decoration:none;border-radius:999px;font-weight:bold;"><?php echo esc_html( $msg['cta'] ); ?></a>
</td></tr></table>
<p style="max-width:520px;margin:16px auto 0;font-size:12px;line-height:1.7;color:#4e4b57;">
		<?php esc_html_e( 'وصلتك هذه الرسالة لأن اللعبة في مفضلتك.', 'retrovault-core' ); ?> <a href="<?php echo esc_url( $msg['unsub'] ); ?>" style="color:#2e46a6;"><?php esc_html_e( 'إيقاف رسائل التحديثات', 'retrovault-core' ); ?></a>
</p>
</td></tr></table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * تفضيلات العضو
	 * ------------------------------------------------------------------ */

	/**
	 * @param int $user_id رقم العضو.
	 */
	public static function email_enabled( $user_id ) {
		return '0' !== (string) get_user_meta( $user_id, self::EMAIL_META, true );
	}

	/**
	 * @param int  $user_id رقم العضو.
	 * @param bool $enabled تفعيل.
	 */
	public static function set_email( $user_id, $enabled ) {
		update_user_meta( $user_id, self::EMAIL_META, $enabled ? '1' : '0' );
	}

	/**
	 * @param int $user_id رقم العضو.
	 */
	private static function token( $user_id ) {
		return substr( hash_hmac( 'sha256', 'rv-unsub|' . (int) $user_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * رابط إيقاف الرسائل بدون تسجيل دخول (موقَّع).
	 *
	 * @param int $user_id رقم العضو.
	 */
	public static function unsubscribe_url( $user_id ) {
		return add_query_arg(
			array(
				'rv_unsub' => (int) $user_id,
				't'        => self::token( $user_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * رابط الإيقاف الموقَّع:
	 * - برامج البريد (زر «إلغاء الاشتراك») ترسل POST بضغطة واحدة (RFC 8058): إيقاف فوري بلا صفحة.
	 * - فتح الرابط من الرسالة يعرض زر تأكيد، لأن برامج فحص الروابط في بعض خدمات البريد تفتحه
	 *   وحدها، فكانت توقف الرسائل دون علم العضو.
	 * - بعد الإيقاف زر «تراجع» يعيدها.
	 */
	public static function unsubscribe() {
		// phpcs:disable WordPress.Security.NonceVerification -- الرابط موقَّع بـ HMAC لكل عضو.
		if ( ! isset( $_GET['rv_unsub'], $_GET['t'] ) ) {
			return;
		}
		$user_id = absint( $_GET['rv_unsub'] );
		$token   = sanitize_text_field( wp_unslash( $_GET['t'] ) );
		if ( ! $user_id || ! hash_equals( self::token( $user_id ), $token ) || ! get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'رابط الإيقاف غير صالح.', 'retrovault-core' ), '', array( 'response' => 400 ) );
		}
		$post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		if ( $post && ! isset( $_POST['rv_choice'] ) ) {
			self::set_email( $user_id, false );
			status_header( 200 );
			exit;
		}
		if ( $post ) {
			self::set_email( $user_id, 'on' === sanitize_key( wp_unslash( $_POST['rv_choice'] ) ) );
		}
		// phpcs:enable
		$on   = self::email_enabled( $user_id );
		$home = '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'العودة إلى الموقع', 'retrovault-core' ) . '</a></p>';
		if ( ! $post && $on ) {
			self::page(
				__( 'إيقاف رسائل التحديثات', 'retrovault-core' ),
				'<p>' . esc_html__( 'لن تصلك رسائل عن الإصدارات الجديدة وتدوينات ألعاب مفضلتك، وستبقى تظهر لك داخل الموقع.', 'retrovault-core' ) . '</p>'
				. self::choice_form( 'off', __( 'أوقف الرسائل', 'retrovault-core' ) ) . $home
			);
		}
		if ( $on ) {
			self::page(
				__( 'رسائل التحديثات مفعّلة', 'retrovault-core' ),
				'<p>' . esc_html__( 'ستصلك رسالة عند صدور إصدار جديد أو تدوينة عن ألعاب مفضلتك.', 'retrovault-core' ) . '</p>' . $home
			);
		}
		$account = Account::url();
		self::page(
			__( 'أُوقفت رسائل التحديثات', 'retrovault-core' ),
			'<p>' . esc_html__( 'ستبقى التحديثات تظهر لك داخل الموقع، في «الجديد في ألعابك».', 'retrovault-core' ) . '</p>'
			. self::choice_form( 'on', __( 'تراجع: أعد تفعيل الرسائل', 'retrovault-core' ) )
			. ( $account ? '<p><a href="' . esc_url( $account ) . '">' . esc_html__( 'ويمكنك إعادة تفعيلها لاحقاً من صفحة «حسابي»', 'retrovault-core' ) . '</a></p>' : $home )
		);
	}

	/**
	 * صفحة ووردبريس البسيطة (بلا قالب الموقع، فالعضو قد لا يكون مسجلاً دخوله).
	 *
	 * @param string $title العنوان.
	 * @param string $html  المحتوى (مُهرَّب).
	 */
	private static function page( $title, $html ) {
		wp_die( '<main><h1>' . esc_html( $title ) . '</h1>' . $html . '</main>', esc_html( $title ), array( 'response' => 200 ) );
	}

	/**
	 * زر يرسل الاختيار إلى الرابط الموقَّع نفسه.
	 *
	 * @param string $choice on أو off.
	 * @param string $label  نص الزر.
	 */
	private static function choice_form( $choice, $label ) {
		return '<form method="post"><input type="hidden" name="rv_choice" value="' . esc_attr( $choice ) . '">'
			. '<p><button type="submit" class="button button-large">' . esc_html( $label ) . '</button></p></form>';
	}

	/* ---------------------------------------------------------------------
	 * «الجديد في ألعابك»
	 * ------------------------------------------------------------------ */

	/**
	 * أحداث الألعاب التي يتابعها العضو (بعد تاريخ متابعته)، الأحدث أولاً.
	 *
	 * @param int $user_id رقم العضو.
	 * @param int $limit   العدد.
	 * @return array[]
	 */
	public static function for_user( $user_id, $limit = 10 ) {
		global $wpdb;
		if ( ! $user_id ) {
			return array();
		}
		$key = $user_id . ':' . $limit;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$events  = self::table();
		$follows = Favorites::table();
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT e.id, e.game_id, e.type, e.ref_id, e.version, e.created_at FROM {$events} e INNER JOIN {$follows} f ON f.game_id = e.game_id AND f.user_id = %d WHERE e.created_at >= f.created_at ORDER BY e.created_at DESC, e.id DESC LIMIT %d", $user_id, $limit * 3 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$seen = (int) get_user_meta( $user_id, self::SEEN_META, true );
		$out  = array();
		$done = array();
		foreach ( (array) $rows as $row ) {
			$game = Games::get( (int) $row->game_id );
			if ( ! $game || 'publish' !== get_post_status( $game['id'] ) ) {
				continue;
			}
			$time = (int) strtotime( $row->created_at . ' UTC' );
			if ( 'devlog' === $row->type ) {
				if ( isset( $done[ 'd' . $row->ref_id ] ) || 'publish' !== get_post_status( (int) $row->ref_id ) ) {
					continue;
				}
				$done[ 'd' . $row->ref_id ] = true;
				$text                        = get_the_title( (int) $row->ref_id );
				$url                         = get_permalink( (int) $row->ref_id );
			} else {
				/* translators: %s: version */
				$text = sprintf( __( 'نزل الإصدار %s', 'retrovault-core' ), $row->version );
				$url  = $game['url'];
			}
			$out[] = array(
				'id'         => (int) $row->id,
				'type'       => $row->type,
				'time'       => $time,
				/* translators: %s: human time difference */
				'ago'        => sprintf( __( 'منذ %s', 'retrovault-core' ), human_time_diff( $time, time() ) ),
				'game_id'    => $game['id'],
				'game_title' => $game['title'],
				'system'     => $game['system'],
				'text'       => $text,
				'url'        => $url,
				'new'        => $time > $seen,
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		self::$cache[ $key ] = $out;
		return $out;
	}

	/**
	 * @param int $user_id رقم العضو.
	 */
	public static function unseen_count( $user_id ) {
		return count(
			array_filter(
				self::for_user( $user_id, 20 ),
				static function ( $item ) {
					return $item['new'];
				}
			)
		);
	}

	/**
	 * @param int $user_id رقم العضو.
	 */
	public static function mark_seen( $user_id ) {
		update_user_meta( $user_id, self::SEEN_META, time() );
	}

	/**
	 * @param int $post_id رقم المحتوى المحذوف.
	 */
	public static function on_delete_post( $post_id ) {
		global $wpdb;
		$type = get_post_type( $post_id );
		if ( Post_Types::GAME === $type ) {
			$wpdb->delete( self::table(), array( 'game_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( 'post' === $type ) {
			$wpdb->delete( self::table(), array( 'type' => 'devlog', 'ref_id' => $post_id ), array( '%s', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	public static function routes() {
		register_rest_route(
			Rest::NS,
			'/me/notify',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( $request ) {
					$user_id = get_current_user_id();
					self::set_email( $user_id, rest_sanitize_boolean( $request->get_param( 'email' ) ) );
					return rest_ensure_response( array( 'email' => self::email_enabled( $user_id ) ) );
				},
				'permission_callback' => array( Rest::class, 'logged_in' ),
				'args'                => array(
					'email' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}
}
