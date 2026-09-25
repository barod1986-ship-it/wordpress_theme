<?php
/**
 * تحسينات لوحة التحكم.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public static function init() {
		$type = Post_Types::GAME;
		add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_filter( "manage_edit-{$type}_sortable_columns", array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'sort_query' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'system_filter' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notices' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard' ) );
	}

	/**
	 * @param array $columns الأعمدة.
	 */
	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new['rv_cover'] = '<span class="screen-reader-text">' . esc_html__( 'الغلاف', 'retrovault-core' ) . '</span>';
			}
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['rv_status'] = __( 'الحالة', 'retrovault-core' );
				$new['rv_rating'] = __( 'التقييم', 'retrovault-core' );
				$new['rv_plays']  = __( 'مرات اللعب', 'retrovault-core' );
			}
		}
		return $new;
	}

	/**
	 * @param string $column  العمود.
	 * @param int    $post_id رقم اللعبة.
	 */
	public static function column( $column, $post_id ) {
		$game = Games::get( $post_id );
		if ( ! $game ) {
			return;
		}
		switch ( $column ) {
			case 'rv_cover':
				echo $game['cover_id'] ? wp_get_attachment_image( $game['cover_id'], array( 45, 60 ), false, array( 'class' => 'rv-admin-cover' ) ) : '<span class="rv-admin-cover rv-admin-cover--empty"></span>';
				break;
			case 'rv_status':
				echo esc_html( $game['status_label'] );
				if ( $game['featured'] ) {
					echo ' <span class="rv-admin-badge">' . esc_html__( 'مميزة', 'retrovault-core' ) . '</span>';
				}
				if ( ! $game['playable'] ) {
					$problems = Game_Meta::problems( $game );
					echo '<br><span class="rv-admin-warn">' . esc_html( $problems ? implode( '، ', $problems ) : __( 'غير قابلة للتشغيل', 'retrovault-core' ) ) . '</span>';
				} elseif ( Game_Meta::ext_mismatch( $game ) ) {
					echo '<br><span class="rv-admin-warn">' . esc_html__( 'امتداد ملفها غير معتاد لنظامها', 'retrovault-core' ) . '</span>';
				}
				break;
			case 'rv_rating':
				echo $game['rating']['count'] ? esc_html( number_format_i18n( $game['rating']['average'], 1 ) . ' ★ (' . number_format_i18n( $game['rating']['count'] ) . ')' ) : '—';
				break;
			case 'rv_plays':
				echo esc_html( number_format_i18n( $game['plays'] ) );
				break;
		}
	}

	/**
	 * @param array $columns الأعمدة القابلة للفرز.
	 */
	public static function sortable( $columns ) {
		$columns['rv_rating'] = 'rv_rating';
		$columns['rv_plays']  = 'rv_plays';
		return $columns;
	}

	/**
	 * @param \WP_Query $query الاستعلام.
	 */
	public static function sort_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || Post_Types::GAME !== $query->get( 'post_type' ) ) {
			return;
		}
		$map     = array(
			'rv_rating' => '_rv_rating_score',
			'rv_plays'  => '_rv_play_count',
		);
		$orderby = $query->get( 'orderby' );
		if ( is_string( $orderby ) && isset( $map[ $orderby ] ) ) {
			$query->set( 'meta_key', $map[ $orderby ] );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * @param string $post_type نوع المحتوى.
	 */
	public static function system_filter( $post_type ) {
		if ( Post_Types::GAME !== $post_type ) {
			return;
		}
		wp_dropdown_categories(
			array(
				'taxonomy'        => Post_Types::SYSTEM,
				'name'            => 'system',
				'value_field'     => 'slug',
				'show_option_all' => __( 'كل الأنظمة', 'retrovault-core' ),
				'selected'        => get_query_var( 'system' ),
				'hide_empty'      => false,
				'hierarchical'    => true,
			)
		);
	}

	/**
	 * @param array    $actions الإجراءات.
	 * @param \WP_Post $post    المقالة.
	 */
	public static function row_actions( $actions, $post ) {
		if ( Post_Types::GAME === $post->post_type ) {
			$game = Games::get( $post );
			if ( $game && $game['playable'] ) {
				$actions['rv_play'] = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $game['player_url'] ), esc_html__( 'تجربة المشغّل', 'retrovault-core' ) );
			}
		}
		return $actions;
	}

	/**
	 * تنبيهات الإعداد الأساسية، تظهر فقط في شاشات الألعاب.
	 */
	public static function setup_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Post_Types::GAME !== $screen->post_type || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notes = array();
		if ( ! get_option( 'users_can_register' ) ) {
			$notes[] = sprintf(
				/* translators: %s: settings URL */
				__( 'التقييم والتعليق للأعضاء فقط، لكن التسجيل مغلق حالياً. فعّل «يستطيع أي شخص التسجيل» من <a href="%s">الإعدادات ← عام</a>.', 'retrovault-core' ),
				esc_url( admin_url( 'options-general.php' ) )
			);
		} elseif ( 'subscriber' !== get_option( 'default_role' ) ) {
			$notes[] = sprintf(
				/* translators: %s: settings URL */
				__( 'دور المستخدم الجديد ليس «مشترك». لأمان الموقع اجعله «مشترك» من <a href="%s">الإعدادات ← عام</a>.', 'retrovault-core' ),
				esc_url( admin_url( 'options-general.php' ) )
			);
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			$notes[] = sprintf(
				/* translators: %s: permalinks URL */
				__( 'الروابط الجميلة غير مفعّلة. اختر «اسم المقالة» من <a href="%s">الإعدادات ← الروابط الدائمة</a> لتظهر روابط مثل /games/اسم-اللعبة/.', 'retrovault-core' ),
				esc_url( admin_url( 'options-permalink.php' ) )
			);
		}
		foreach ( $notes as $note ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses( $note, array( 'a' => array( 'href' => array() ) ) ) . '</p></div>';
		}
	}

	public static function dashboard() {
		if ( current_user_can( 'edit_posts' ) ) {
			wp_add_dashboard_widget( 'rv_dashboard', __( 'مكتبة الألعاب', 'retrovault-core' ), array( __CLASS__, 'dashboard_widget' ) );
		}
	}

	public static function dashboard_widget() {
		$totals = Stats::totals();
		$top    = Games::query(
			array(
				'sort'        => 'plays',
				'number'      => 5,
				'played_only' => true,
			)
		);
		?>
		<div class="rv-dash-spark" title="<?php esc_attr_e( 'مرات اللعب في آخر 14 يوماً', 'retrovault-core' ); ?>">
			<?php echo Analytics::bars( Analytics::series( 'plays', 14 ), array( 'width' => 400, 'height' => 60, 'axis' => false, 'label' => __( 'مرات اللعب في آخر 14 يوماً', 'retrovault-core' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<ul class="rv-dash-totals">
			<li><strong><?php echo esc_html( number_format_i18n( $totals['games'] ) ); ?></strong> <?php esc_html_e( 'لعبة منشورة', 'retrovault-core' ); ?></li>
			<li><strong><?php echo esc_html( number_format_i18n( $totals['plays'] ) ); ?></strong> <?php esc_html_e( 'مرة لعب', 'retrovault-core' ); ?></li>
			<li><strong><?php echo esc_html( number_format_i18n( $totals['ratings'] ) ); ?></strong> <?php esc_html_e( 'تقييم', 'retrovault-core' ); ?></li>
		</ul>
		<?php if ( $top->have_posts() ) : ?>
			<h3><?php esc_html_e( 'الأكثر لعباً', 'retrovault-core' ); ?></h3>
			<ol>
				<?php
				foreach ( $top->posts as $post ) :
					$game = Games::get( $post );
					?>
					<li><a href="<?php echo esc_url( get_edit_post_link( $post ) ); ?>"><?php echo esc_html( $game['title'] ); ?></a> — <?php echo esc_html( number_format_i18n( $game['plays'] ) ); ?></li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Post_Types::GAME ) ); ?>"><?php esc_html_e( 'إضافة لعبة', 'retrovault-core' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Types::GAME . '&page=' . Analytics::PAGE ) ); ?>"><?php esc_html_e( 'كل الإحصائيات', 'retrovault-core' ); ?></a>
		</p>
		<?php
	}
}
