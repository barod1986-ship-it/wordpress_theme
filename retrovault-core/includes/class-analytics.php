<?php
/**
 * الإحصائيات.
 *
 * - جدول يومي {prefix}rv_stats_daily (يوم + لعبة): مرات اللعب والتنزيل، يزيد مع كل مرة محسوبة.
 * - صفحة «الألعاب ← الإحصائيات»: رسوم SVG تُرسم في الخادم (بلا مكتبات)، أعلى الألعاب،
 *   توزيع التقييمات، جدول لكل لعبة، وتصدير CSV.
 * - «الرائجة»: مرات اللعب في آخر 7 أيام تُحفظ في _rv_trend_score (تُحدَّث كل ساعة).
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Analytics {

	const HOOK   = 'retrovault_recompute_trends';
	const TREND  = '_rv_trend_score';
	const SINCE  = 'retrovault_stats_since';
	const PAGE   = 'retrovault-stats';
	const PERIOD = array( 7, 30, 90, 365 );

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'recompute_trends' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'export' ) );
		}
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rv_stats_daily';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			day date NOT NULL,
			game_id bigint(20) unsigned NOT NULL,
			plays int(10) unsigned NOT NULL DEFAULT 0,
			downloads int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,game_id),
			KEY game_day (game_id,day)
			) {$charset};"
		);
		if ( ! get_option( self::SINCE ) ) {
			update_option( self::SINCE, wp_date( 'Y-m-d' ), false );
		}
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * زيادة عدّاد اليوم (يُستدعى لكل مرة محسوبة فقط).
	 *
	 * @param int    $game_id رقم اللعبة.
	 * @param string $field   plays|downloads.
	 */
	public static function record( $game_id, $field ) {
		global $wpdb;
		if ( ! in_array( $field, array( 'plays', 'downloads' ), true ) ) {
			return;
		}
		$table = self::table();
		$day   = wp_date( 'Y-m-d' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$sql = $wpdb->prepare( "UPDATE {$table} SET {$field} = {$field} + 1 WHERE day = %s AND game_id = %d", $day, $game_id );
		if ( ! $wpdb->query( $sql ) ) {
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (day, game_id, plays, downloads) VALUES (%s, %d, %d, %d)", $day, $game_id, 'plays' === $field ? 1 : 0, 'downloads' === $field ? 1 : 0 ) );
			if ( ! $inserted ) {
				$wpdb->query( $sql ); // سبقنا طلب متزامن بإنشاء الصف.
			}
		}
		if ( 'plays' === $field ) {
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s", $game_id, self::TREND ) );
			if ( ! $updated ) {
				add_post_meta( $game_id, self::TREND, 1, true );
			}
			wp_cache_delete( $game_id, 'post_meta' );
		}
		// phpcs:enable
	}

	/** «الرائجة» = مرات اللعب في آخر 7 أيام (تُحدَّث كل ساعة). */
	public static function recompute_trends() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT game_id, SUM(plays) AS s FROM {$table} WHERE day > %s GROUP BY game_id", self::day( -7 ) ), OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids   = get_posts(
			array(
				'post_type'      => Post_Types::GAME,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			update_post_meta( $id, self::TREND, isset( $rows[ $id ] ) ? (int) $rows[ $id ]->s : 0 );
		}
		Games::flush();
	}

	/* ---------------------------------------------------------------------
	 * الاستعلامات
	 * ------------------------------------------------------------------ */

	/**
	 * تاريخ بعد/قبل اليوم بعدد أيام (بتوقيت الموقع).
	 *
	 * @param int $offset الإزاحة بالأيام.
	 */
	private static function day( $offset ) {
		return wp_date( 'Y-m-d', time() + $offset * DAY_IN_SECONDS );
	}

	/**
	 * بداية يوم بتوقيت الموقع محوّلة إلى UTC (لأعمدة الجداول المخزنة بـ UTC).
	 *
	 * @param string $day Y-m-d.
	 */
	private static function utc( $day ) {
		return get_gmt_from_date( $day . ' 00:00:00' );
	}

	/**
	 * سلسلة يومية مكتملة بالأصفار (الأقدم أولاً).
	 *
	 * @param string $field   plays|downloads.
	 * @param int    $days    عدد الأيام.
	 * @param int    $game_id لعبة محددة أو 0 للكل.
	 * @param int    $shift   إزاحة (للفترة السابقة).
	 * @return array<string,int>
	 */
	public static function series( $field, $days, $game_id = 0, $shift = 0 ) {
		global $wpdb;
		$field = 'downloads' === $field ? 'downloads' : 'plays';
		$table = self::table();
		$from  = self::day( -$days + 1 - $shift );
		$to    = self::day( -$shift );
		$where = $wpdb->prepare( 'day BETWEEN %s AND %s', $from, $to ) . ( $game_id ? $wpdb->prepare( ' AND game_id = %d', $game_id ) : '' );
		$rows  = $wpdb->get_results( "SELECT day, SUM({$field}) AS v FROM {$table} WHERE {$where} GROUP BY day", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$out   = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d         = self::day( -$i - $shift );
			$out[ $d ] = isset( $rows[ $d ] ) ? (int) $rows[ $d ]->v : 0;
		}
		return $out;
	}

	/**
	 * @param int $days    الفترة.
	 * @param int $game_id لعبة أو 0.
	 * @param int $shift   إزاحة.
	 * @return array<string,int> plays, downloads, ratings, followers, comments, members.
	 */
	public static function totals( $days, $game_id = 0, $shift = 0 ) {
		global $wpdb;
		$from = self::utc( self::day( -$days + 1 - $shift ) );
		$to   = self::utc( self::day( 1 - $shift ) );
		$g    = $game_id ? (int) $game_id : 0;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ratings   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Ratings::table() . ' WHERE created_at >= %s AND created_at < %s' . ( $g ? ' AND game_id = %d' : '' ), ...( $g ? array( $from, $to, $g ) : array( $from, $to ) ) ) );
		$followers = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Favorites::table() . ' WHERE created_at >= %s AND created_at < %s' . ( $g ? ' AND game_id = %d' : '' ), ...( $g ? array( $from, $to, $g ) : array( $from, $to ) ) ) );
		$comments  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} c INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID WHERE p.post_type = %s AND c.comment_approved = '1' AND c.comment_date_gmt >= %s AND c.comment_date_gmt < %s" . ( $g ? ' AND c.comment_post_ID = %d' : '' ), ...( $g ? array( Post_Types::GAME, $from, $to, $g ) : array( Post_Types::GAME, $from, $to ) ) ) );
		$members   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s AND user_registered < %s", $from, $to ) );
		// phpcs:enable
		return array(
			'plays'     => array_sum( self::series( 'plays', $days, $g, $shift ) ),
			'downloads' => array_sum( self::series( 'downloads', $days, $g, $shift ) ),
			'ratings'   => $ratings,
			'followers' => $followers,
			'comments'  => $comments,
			'members'   => $members,
		);
	}

	/**
	 * لكل لعبة في الفترة.
	 *
	 * @param int $days الفترة.
	 * @return array[]
	 */
	public static function per_game( $days ) {
		global $wpdb;
		$table = self::table();
		$from  = self::day( -$days + 1 );
		$utc   = self::utc( $from );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$stats    = $wpdb->get_results( $wpdb->prepare( "SELECT game_id, SUM(plays) AS plays, SUM(downloads) AS downloads FROM {$table} WHERE day >= %s GROUP BY game_id", $from ), OBJECT_K );
		$comments = $wpdb->get_results( $wpdb->prepare( "SELECT comment_post_ID AS id, COUNT(*) AS n FROM {$wpdb->comments} WHERE comment_approved = '1' AND comment_date_gmt >= %s GROUP BY comment_post_ID", $utc ), OBJECT_K );
		// phpcs:enable
		$out = array();
		foreach ( get_posts( array( 'post_type' => Post_Types::GAME, 'post_status' => 'publish', 'posts_per_page' => -1 ) ) as $post ) {
			$game  = Games::get( $post );
			$out[] = array(
				'id'        => $game['id'],
				'title'     => $game['title'],
				'system'    => $game['system'] ? $game['system']['short'] : '',
				'plays'     => isset( $stats[ $game['id'] ] ) ? (int) $stats[ $game['id'] ]->plays : 0,
				'total'     => $game['plays'],
				'downloads' => isset( $stats[ $game['id'] ] ) ? (int) $stats[ $game['id'] ]->downloads : 0,
				'rating'    => $game['rating']['average'],
				'votes'     => $game['rating']['count'],
				'followers' => Favorites::count( $game['id'] ),
				'comments'  => isset( $comments[ $game['id'] ] ) ? (int) $comments[ $game['id'] ]->n : 0,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $b['plays'], $b['total'] ) <=> array( $a['plays'], $a['total'] );
			}
		);
		return $out;
	}

	/**
	 * @param int $game_id لعبة أو 0.
	 * @return array<int,int> 5 => n ... 1 => n
	 */
	public static function rating_histogram( $game_id = 0 ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT rating, COUNT(*) AS n FROM ' . Ratings::table() . ( $game_id ? $wpdb->prepare( ' WHERE game_id = %d', $game_id ) : '' ) . ' GROUP BY rating', OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$out  = array();
		for ( $i = 5; $i >= 1; $i-- ) {
			$out[ $i ] = isset( $rows[ $i ] ) ? (int) $rows[ $i ]->n : 0;
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * الرسم (SVG من الخادم)
	 * ------------------------------------------------------------------ */

	/**
	 * أعمدة زمنية. في RTL يبدأ الزمن من اليمين.
	 *
	 * @param array<string,int> $series التاريخ => القيمة (الأقدم أولاً).
	 * @param array             $args   width, height, color, label, axis.
	 * @return string SVG
	 */
	public static function bars( $series, $args = array() ) {
		$a = wp_parse_args(
			$args,
			array(
				'width'  => 760,
				'height' => 220,
				'color'  => '#c8323a',
				'label'  => '',
				'axis'   => true,
			)
		);
		$values = array_values( $series );
		$labels = array_keys( $series );
		$n      = max( 1, count( $values ) );
		$max    = self::nice( max( 1, max( $values ? $values : array( 0 ) ) ) );
		$rtl    = is_rtl();
		// عرض أرقام المحور حسب أطولها (كان «20,000» يُقص إلى «20,00»).
		$pad = $a['axis'] ? array( 'x' => max( 34, 10 + 7 * mb_strlen( number_format_i18n( $max ) ) ), 't' => 8, 'b' => 22 ) : array( 'x' => 0, 't' => 2, 'b' => 2 );
		$plot_w = $a['width'] - $pad['x'] - 4;
		$plot_h = $a['height'] - $pad['t'] - $pad['b'];
		$bw     = $plot_w / $n;
		$gap    = $bw > 6 ? max( 1, $bw * 0.18 ) : 0.5;
		$x0     = $rtl ? 4 : $pad['x'];  // في RTL تكون أرقام المحور على اليمين.

		// الأرقام والتواريخ LTR دائماً؛ وإلا قلبت صفحة RTL معنى text-anchor.
		$svg = sprintf( '<svg class="rv-chart" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s" direction="ltr">', $a['width'], $a['height'], esc_attr( $a['label'] ) );
		if ( $a['axis'] ) {
			for ( $i = 0; $i <= 4; $i++ ) {
				$y    = $pad['t'] + $plot_h - $plot_h * $i / 4;
				$svg .= sprintf( '<line x1="%1$.1f" x2="%2$.1f" y1="%3$.1f" y2="%3$.1f" class="rv-chart__grid"/>', $x0, $x0 + $plot_w, $y );
				$svg .= sprintf( '<text x="%1$.1f" y="%2$.1f" class="rv-chart__y" text-anchor="%3$s">%4$s</text>', $rtl ? $x0 + $plot_w + 6 : $pad['x'] - 6, $y + 4, $rtl ? 'start' : 'end', esc_html( number_format_i18n( $max * $i / 4 ) ) );
			}
		}
		$step = max( 1, (int) ceil( $n / 8 ) );
		foreach ( $values as $i => $v ) {
			$h    = $plot_h * $v / $max;
			$slot = $rtl ? ( $n - 1 - $i ) : $i;
			$x    = $x0 + $slot * $bw + $gap / 2;
			$svg .= sprintf(
				'<rect x="%1$.2f" y="%2$.2f" width="%3$.2f" height="%4$.2f" fill="%5$s"><title>%6$s: %7$s</title></rect>',
				$x,
				$pad['t'] + $plot_h - $h,
				max( 0.5, $bw - $gap ),
				max( $v ? 1 : 0, $h ),
				esc_attr( $a['color'] ),
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $labels[ $i ] ) ) ),
				esc_html( number_format_i18n( $v ) )
			);
			if ( $a['axis'] && 0 === ( $n - 1 - $i ) % $step ) {
				$svg .= sprintf( '<text x="%1$.1f" y="%2$d" class="rv-chart__x" text-anchor="middle">%3$s</text>', $x + ( $bw - $gap ) / 2, $a['height'] - 6, esc_html( date_i18n( 'j/n', strtotime( $labels[ $i ] ) ) ) );
			}
		}
		return $svg . '</svg>';
	}

	/**
	 * سقف مستدير للمحور قريب من أعلى قيمة، يقسمه خطوط الشبكة الأربعة إلى أعداد صحيحة
	 * (سقوف 1-2-5 وحدها تترك نصف الرسم فارغاً أحياناً: 2,300 كانت ترسم على محور 5,000).
	 *
	 * @param float $v القيمة القصوى.
	 * @return int
	 */
	private static function nice( $v ) {
		$v   = max( 1, (float) $v );
		$exp = pow( 10, floor( log10( $v ) ) );
		for ( $i = 0; $i < 3; $i++, $exp *= 10 ) {
			foreach ( array( 1, 1.2, 1.6, 2, 2.4, 3, 4, 5, 6, 8 ) as $m ) {
				$top = (int) round( $m * $exp );
				if ( $top >= $v && 0 === $top % 4 ) {
					return $top;
				}
			}
		}
		return (int) ceil( $v / 4 ) * 4;
	}

	/**
	 * تجميع أسبوعي للفترات الطويلة (سنة = 52 عموداً بدل 365)، أسابيع كاملة تنتهي باليوم: الأيام
	 * الزائدة في أول الفترة لا تُرسم عموداً ناقصاً يبدو هبوطاً (مجموعها في الأرقام أعلى الصفحة).
	 *
	 * @param array<string,int> $series السلسلة اليومية (الأقدم أولاً).
	 * @return array<string,int> آخر يوم في كل أسبوع => مجموعه (الأقدم أولاً).
	 */
	public static function weekly( $series ) {
		$out = array();
		foreach ( array_chunk( array_reverse( $series, true ), 7, true ) as $week ) {
			if ( count( $week ) < 7 && $out ) {
				break;
			}
			$out[ array_key_first( $week ) ] = array_sum( $week );
		}
		return array_reverse( $out, true );
	}

	/* ---------------------------------------------------------------------
	 * الصفحة
	 * ------------------------------------------------------------------ */

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Post_Types::GAME,
			__( 'إحصائيات المكتبة', 'retrovault-core' ),
			__( 'الإحصائيات', 'retrovault-core' ),
			'edit_others_posts',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * @return array{days:int,game:int}
	 */
	private static function params() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- عرض فقط.
		$days = isset( $_GET['period'] ) ? absint( $_GET['period'] ) : 30;
		$game = isset( $_GET['game'] ) ? absint( $_GET['game'] ) : 0;
		// phpcs:enable
		return array(
			'days' => in_array( $days, self::PERIOD, true ) ? $days : 30,
			'game' => ( $game && Post_Types::GAME === get_post_type( $game ) ) ? $game : 0,
		);
	}

	/**
	 * @param int $now  القيمة الحالية.
	 * @param int $prev القيمة السابقة.
	 */
	private static function delta( $now, $prev ) {
		if ( ! $prev ) {
			return $now ? '<span class="rv-delta rv-delta--up">' . esc_html__( 'جديد', 'retrovault-core' ) . '</span>' : '';
		}
		$pct = round( ( $now - $prev ) / $prev * 100 );
		$cls = $pct > 0 ? 'up' : ( $pct < 0 ? 'down' : 'flat' );
		return sprintf( '<span class="rv-delta rv-delta--%1$s">%2$s%3$s%%</span>', $cls, $pct > 0 ? '+' : '', esc_html( number_format_i18n( $pct ) ) );
	}

	public static function page() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		$p      = self::params();
		$days   = $p['days'];
		$game   = $p['game'];
		$now    = self::totals( $days, $game );
		$prev   = self::totals( $days, $game, $days );
		$since  = (string) get_option( self::SINCE );
		$series = self::series( 'plays', $days, $game );
		if ( $since ) {
			// الأيام قبل بدء السجل اليومي ليست «صفراً»، فلا تُرسم.
			$series = array_filter(
				$series,
				static function ( $day ) use ( $since ) {
					return $day >= $since;
				},
				ARRAY_FILTER_USE_KEY
			);
		}
		$weekly = count( $series ) > 90;
		$chart  = $weekly ? self::weekly( $series ) : $series;
		// مرات اللعب والتنزيلات من السجل اليومي: لا مقارنة بفترة سابقة بدأت قبله.
		$compare = ! $since || $since <= self::day( -2 * $days + 1 );
		$rows    = self::per_game( $days );
		$hist    = self::rating_histogram( $game );
		$games  = get_posts( array( 'post_type' => Post_Types::GAME, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$labels = array(
			7   => __( 'آخر 7 أيام', 'retrovault-core' ),
			30  => __( 'آخر 30 يوماً', 'retrovault-core' ),
			90  => __( 'آخر 90 يوماً', 'retrovault-core' ),
			365 => __( 'آخر سنة', 'retrovault-core' ),
		);
		$kpis   = array(
			'plays'     => __( 'مرات اللعب', 'retrovault-core' ),
			'ratings'   => __( 'تقييمات جديدة', 'retrovault-core' ),
			'followers' => __( 'إضافات للمفضلة', 'retrovault-core' ),
			'comments'  => __( 'تعليقات', 'retrovault-core' ),
			'downloads' => __( 'تنزيلات', 'retrovault-core' ),
		);
		if ( ! $game ) {
			$kpis['members'] = __( 'أعضاء جدد', 'retrovault-core' );
		}
		?>
		<div class="wrap rv-stats">
			<h1><?php esc_html_e( 'إحصائيات المكتبة', 'retrovault-core' ); ?></h1>

			<form method="get" class="rv-stats__filters">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( Post_Types::GAME ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<label class="screen-reader-text" for="rv-stats-period"><?php esc_html_e( 'الفترة', 'retrovault-core' ); ?></label>
				<select name="period" id="rv-stats-period" onchange="this.form.submit()">
					<?php foreach ( $labels as $d => $label ) : ?>
						<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $days, $d ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="rv-stats-game"><?php esc_html_e( 'اللعبة', 'retrovault-core' ); ?></label>
				<select name="game" id="rv-stats-game" onchange="this.form.submit()">
					<option value="0"><?php esc_html_e( 'كل الألعاب', 'retrovault-core' ); ?></option>
					<?php foreach ( $games as $g ) : ?>
						<option value="<?php echo esc_attr( $g->ID ); ?>" <?php selected( $game, $g->ID ); ?>><?php echo esc_html( get_the_title( $g ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><button class="button"><?php esc_html_e( 'عرض', 'retrovault-core' ); ?></button></noscript>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'rv_export' => 'csv', 'period' => $days ), admin_url( 'edit.php?post_type=' . Post_Types::GAME . '&page=' . self::PAGE ) ), 'rv_export' ) ); ?>"><?php esc_html_e( 'تصدير CSV', 'retrovault-core' ); ?></a>
			</form>

			<ul class="rv-kpis">
				<?php foreach ( $kpis as $key => $label ) : ?>
					<li>
						<span class="rv-kpis__label"><?php echo esc_html( $label ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( $now[ $key ] ) ); ?></strong>
						<?php
						if ( $compare || ! in_array( $key, array( 'plays', 'downloads' ), true ) ) {
							echo self::delta( $now[ $key ], $prev[ $key ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="description">
				<?php
				echo esc_html( sprintf( /* translators: %s: period */ __( 'النسبة مقارنة بالفترة السابقة المساوية (%s قبلها).', 'retrovault-core' ), $labels[ $days ] ) );
				if ( ! $compare ) {
					echo ' ' . esc_html__( 'مرات اللعب والتنزيلات بلا مقارنة، لأن السجل اليومي بدأ بعد بداية الفترة السابقة.', 'retrovault-core' );
				}
				?>
			</p>

			<div class="postbox rv-box">
				<h2 class="hndle"><?php echo esc_html( $weekly ? __( 'مرات اللعب أسبوعياً', 'retrovault-core' ) : __( 'مرات اللعب يومياً', 'retrovault-core' ) ); ?></h2>
				<div class="inside">
					<?php // على الشاشات الضيقة يُمرَّر الرسم، ويبدأ من جهة أحدث الأيام (يسار الرسم في RTL). ?>
					<div class="rv-scroll" dir="<?php echo is_rtl() ? 'ltr' : 'rtl'; ?>" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'رسم مرات اللعب', 'retrovault-core' ); ?>">
						<?php echo self::bars( $chart, array( 'label' => __( 'مرات اللعب', 'retrovault-core' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<?php if ( $since ) : ?>
						<p class="description">
							<?php
							/* translators: %s: date */
							echo esc_html( sprintf( __( 'السجل اليومي يبدأ من %s؛ ما قبله محسوب في الإجمالي فقط.', 'retrovault-core' ), date_i18n( get_option( 'date_format' ), strtotime( $since ) ) ) );
							?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="rv-stats__cols">
				<div class="postbox rv-box">
					<h2 class="hndle"><?php esc_html_e( 'الأكثر لعباً في الفترة', 'retrovault-core' ); ?></h2>
					<div class="inside">
						<?php
						$top  = array_slice( array_filter( $rows, static function ( $r ) { return $r['plays'] > 0; } ), 0, 8 );
						$peak = $top ? max( wp_list_pluck( $top, 'plays' ) ) : 1;
						if ( ! $top ) {
							echo '<p>' . esc_html__( 'لا توجد مرات لعب في هذه الفترة.', 'retrovault-core' ) . '</p>';
						}
						foreach ( $top as $r ) :
							?>
							<div class="rv-hbar<?php echo $game === $r['id'] ? ' is-current' : ''; ?>">
								<a class="rv-hbar__label" href="<?php echo esc_url( add_query_arg( 'game', $r['id'] ) ); ?>"><?php echo esc_html( $r['title'] ); ?></a>
								<span class="rv-hbar__track"><span style="width:<?php echo esc_attr( round( $r['plays'] / $peak * 100, 1 ) ); ?>%"></span></span>
								<span class="rv-hbar__value"><?php echo esc_html( number_format_i18n( $r['plays'] ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="postbox rv-box">
					<h2 class="hndle"><?php echo esc_html( $game ? __( 'توزيع تقييمات اللعبة', 'retrovault-core' ) : __( 'توزيع كل التقييمات', 'retrovault-core' ) ); ?></h2>
					<div class="inside">
						<?php
						$peak = max( 1, max( $hist ) );
						foreach ( $hist as $stars => $count ) :
							?>
							<div class="rv-hbar rv-hbar--stars">
								<span class="rv-hbar__label"><?php echo esc_html( str_repeat( '★', $stars ) ); ?></span>
								<span class="rv-hbar__track"><span style="width:<?php echo esc_attr( round( $count / $peak * 100, 1 ) ); ?>%"></span></span>
								<span class="rv-hbar__value"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<div class="postbox rv-box">
				<h2 class="hndle" id="rv-all-games"><?php esc_html_e( 'كل الألعاب', 'retrovault-core' ); ?></h2>
				<div class="inside">
					<div class="rv-scroll" tabindex="0" role="region" aria-labelledby="rv-all-games">
					<table class="widefat striped rv-stats__table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'اللعبة', 'retrovault-core' ); ?></th>
								<th><?php esc_html_e( 'اللعب (الفترة)', 'retrovault-core' ); ?></th>
								<th><?php esc_html_e( 'اللعب (الإجمالي)', 'retrovault-core' ); ?></th>
								<th><?php esc_html_e( 'التقييم', 'retrovault-core' ); ?></th>
								<th><?php esc_html_e( 'المتابعون', 'retrovault-core' ); ?></th>
								<th><?php esc_html_e( 'تعليقات (الفترة)', 'retrovault-core' ); ?></th>
								<th><?php esc_html_e( 'تنزيلات (الفترة)', 'retrovault-core' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $r ) : ?>
								<tr<?php echo $game === $r['id'] ? ' class="is-current" aria-current="true"' : ''; ?>>
									<td><a href="<?php echo esc_url( add_query_arg( 'game', $r['id'] ) ); ?>"><?php echo esc_html( $r['title'] ); ?></a> <span class="rv-sys"><?php echo esc_html( $r['system'] ); ?></span></td>
									<td><?php echo esc_html( number_format_i18n( $r['plays'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $r['total'] ) ); ?></td>
									<td><?php echo $r['votes'] ? esc_html( number_format_i18n( $r['rating'], 1 ) . ' ★ (' . number_format_i18n( $r['votes'] ) . ')' ) : '—'; ?></td>
									<td><?php echo esc_html( number_format_i18n( $r['followers'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $r['comments'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $r['downloads'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * نص في خلية CSV: عنوان يبدأ بـ = أو + أو - أو @ ينفّذه Excel معادلةً عند فتح الملف.
	 *
	 * @param string $text النص.
	 */
	public static function csv_text( $text ) {
		$text = (string) $text;
		return ( '' !== $text && false !== strpos( "=+-@\t\r", $text[0] ) ) ? "'" . $text : $text;
	}

	/** تصدير CSV (بعلامة BOM ليفتحه Excel بالعربية صحيحاً). */
	public static function export() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['rv_export'], $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}
		check_admin_referer( 'rv_export' );
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'غير مسموح.', 'retrovault-core' ), '', array( 'response' => 403 ) );
		}
		$days = self::params()['days'];
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="retrovault-stats-' . $days . 'd-' . wp_date( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		// حرف الهروب الفارغ = CSV قياسي (RFC 4180)، وتمريره صراحةً يتجنب تحذير PHP 8.4.
		fputcsv( $out, array( 'id', __( 'اللعبة', 'retrovault-core' ), __( 'النظام', 'retrovault-core' ), __( 'اللعب (الفترة)', 'retrovault-core' ), __( 'اللعب (الإجمالي)', 'retrovault-core' ), __( 'التقييم', 'retrovault-core' ), __( 'عدد التقييمات', 'retrovault-core' ), __( 'المتابعون', 'retrovault-core' ), __( 'تعليقات (الفترة)', 'retrovault-core' ), __( 'تنزيلات (الفترة)', 'retrovault-core' ) ), ',', '"', '' );
		foreach ( self::per_game( $days ) as $r ) {
			fputcsv( $out, array( $r['id'], self::csv_text( $r['title'] ), self::csv_text( $r['system'] ), $r['plays'], $r['total'], $r['rating'], $r['votes'], $r['followers'], $r['comments'], $r['downloads'] ), ',', '"', '' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
