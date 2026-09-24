<?php
/**
 * يوميات التطوير: التدوينات العادية في ووردبريس، مع ربط كل تدوينة بلعبة أو أكثر.
 *
 * - صندوق «الألعاب المرتبطة» في محرر التدوينة (يُحفظ كصف مستقل لكل لعبة في _rv_game
 *   ليكون الاستعلام عنه سريعاً).
 * - التدوينة تظهر في صفحة كل لعبة مرتبطة ضمن «من يوميات التطوير».
 * - صفحة «يوميات التطوير» تُنشأ تلقائياً وتُعيَّن صفحةً للمقالات.
 * - رابط «اكتب تدوينة عن هذه اللعبة» في صفحة تحرير اللعبة يفتح تدوينة واللعبة محددة مسبقاً.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Devlog {

	const META   = '_rv_game';
	const NOTICE = 'retrovault_devlog_notice';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 7 );
		add_filter( 'post_type_labels_post', array( __CLASS__, 'labels' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
	}

	public static function register() {
		register_post_meta(
			'post',
			self::META,
			array(
				'type'          => 'integer',
				'single'        => false,
				'show_in_rest'  => true,
				'auth_callback' => static function ( $allowed, $key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	/**
	 * «المقالات» تصبح «يوميات التطوير».
	 *
	 * @param object $labels التسميات.
	 */
	public static function labels( $labels ) {
		$labels->name          = __( 'يوميات التطوير', 'retrovault-core' );
		$labels->menu_name     = __( 'يوميات التطوير', 'retrovault-core' );
		$labels->singular_name = __( 'تدوينة', 'retrovault-core' );
		$labels->all_items     = __( 'كل التدوينات', 'retrovault-core' );
		$labels->add_new_item  = __( 'تدوينة جديدة', 'retrovault-core' );
		$labels->edit_item     = __( 'تعديل التدوينة', 'retrovault-core' );
		$labels->new_item      = __( 'تدوينة جديدة', 'retrovault-core' );
		$labels->view_item     = __( 'عرض التدوينة', 'retrovault-core' );
		return $labels;
	}

	public static function box() {
		add_meta_box( 'rv_devlog_games', __( 'الألعاب المرتبطة', 'retrovault-core' ), array( __CLASS__, 'render' ), 'post', 'side', 'default' );
	}

	/**
	 * @param \WP_Post $post التدوينة.
	 */
	public static function render( $post ) {
		wp_nonce_field( 'rv_devlog', 'rv_devlog_nonce' );
		$selected = self::games_for_post( $post->ID, false );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- تحديد مسبق من رابط «اكتب تدوينة».
		if ( ! $selected && isset( $_GET['rv_game'] ) ) {
			$selected = array( absint( $_GET['rv_game'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$games = get_posts(
			array(
				'post_type'      => Post_Types::GAME,
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		if ( ! $games ) {
			echo '<p>' . esc_html__( 'لا توجد ألعاب بعد.', 'retrovault-core' ) . '</p>';
			return;
		}
		echo '<div class="rv-devlog-games">';
		foreach ( $games as $game ) {
			$data = Games::get( $game );
			printf(
				'<label><input type="checkbox" name="rv_devlog_games[]" value="%1$d" %2$s> %3$s%4$s</label>',
				(int) $game->ID,
				checked( in_array( (int) $game->ID, $selected, true ), true, false ),
				esc_html( get_the_title( $game ) ),
				$data && $data['system'] ? ' <small>(' . esc_html( $data['system']['short'] ) . ')</small>' : ''
			);
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'تظهر التدوينة في صفحات هذه الألعاب ضمن «من يوميات التطوير».', 'retrovault-core' ) . '</p>';
	}

	/**
	 * @param int      $post_id رقم التدوينة.
	 * @param \WP_Post $post    التدوينة.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['rv_devlog_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rv_devlog_nonce'] ) ), 'rv_devlog' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$ids = isset( $_POST['rv_devlog_games'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['rv_devlog_games'] ) ) : array();
		$ids = array_unique(
			array_filter(
				$ids,
				static function ( $id ) {
					return $id && Post_Types::GAME === get_post_type( $id );
				}
			)
		);
		delete_post_meta( $post_id, self::META );
		foreach ( $ids as $id ) {
			add_post_meta( $post_id, self::META, (int) $id );
		}
		Notifier::devlog_published( $post_id );
	}

	/**
	 * الألعاب المرتبطة بتدوينة.
	 *
	 * @param int  $post_id        رقم التدوينة.
	 * @param bool $published_only المنشورة فقط.
	 * @return int[]
	 */
	public static function games_for_post( $post_id, $published_only = true ) {
		$ids = array_map( 'absint', (array) get_post_meta( $post_id, self::META, false ) );
		return array_values(
			array_filter(
				array_unique( $ids ),
				static function ( $id ) use ( $published_only ) {
					return Post_Types::GAME === get_post_type( $id ) && ( ! $published_only || 'publish' === get_post_status( $id ) );
				}
			)
		);
	}

	/**
	 * تدوينات لعبة معينة، الأحدث أولاً.
	 *
	 * @param int $game_id رقم اللعبة.
	 * @param int $number  العدد.
	 * @return \WP_Query
	 */
	public static function for_game( $game_id, $number = 3 ) {
		return new \WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => $number,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'meta_key'            => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'          => (int) $game_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	/**
	 * @param int $game_id رقم اللعبة.
	 */
	public static function count_for_game( $game_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %d AND p.post_type = 'post' AND p.post_status = 'publish'", self::META, $game_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * أحدث التدوينات.
	 *
	 * @param int $number العدد.
	 * @return \WP_Query
	 */
	public static function latest( $number = 3 ) {
		return new \WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => $number,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);
	}

	/** رابط صفحة اليوميات (صفحة المقالات). */
	public static function url() {
		$id = (int) get_option( 'page_for_posts' );
		return ( $id && 'publish' === get_post_status( $id ) ) ? (string) get_permalink( $id ) : '';
	}

	/**
	 * إنشاء صفحة «يوميات التطوير» وتعيينها صفحةً للمقالات (إن لم تكن هناك صفحة مقالات).
	 * ووردبريس لا يعرض صفحة مقالات مستقلة إلا مع صفحة رئيسية ثابتة، فننشئ صفحة «الرئيسية»
	 * فارغة إن لزم؛ شكل الرئيسية لا يتغير لأن القالب يرسمها (front-page.php).
	 */
	public static function ensure_pages() {
		if ( (int) get_option( 'page_for_posts' ) ) {
			return;
		}
		$blog = self::page( 'devlog', __( 'يوميات التطوير', 'retrovault-core' ) );
		if ( ! $blog ) {
			return;
		}
		if ( 'page' !== get_option( 'show_on_front' ) || ! (int) get_option( 'page_on_front' ) ) {
			$home = self::page( 'home', __( 'الرئيسية', 'retrovault-core' ) );
			if ( ! $home ) {
				return;
			}
			update_option( 'page_on_front', (int) $home );
			update_option( 'show_on_front', 'page' );
		}
		update_option( 'page_for_posts', (int) $blog );
		update_option( self::NOTICE, 1 );
	}

	/**
	 * صفحة منشورة بهذا الرابط، أو إنشاؤها.
	 *
	 * @param string $slug  الرابط.
	 * @param string $title العنوان.
	 * @return int
	 */
	private static function page( $slug, $title ) {
		$existing = get_page_by_path( $slug );
		if ( $existing && 'publish' === $existing->post_status ) {
			return (int) $existing->ID;
		}
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => $title,
				'post_name'      => $slug,
				'comment_status' => 'closed',
			)
		);
		return ( $id && ! is_wp_error( $id ) ) ? (int) $id : 0;
	}

	/** تنبيه لمرة واحدة يشرح التغيير. */
	public static function setup_notice() {
		if ( ! get_option( self::NOTICE ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_option( self::NOTICE );
		printf(
			'<div class="notice notice-info is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'أُنشئت صفحة «يوميات التطوير» لعرض تدويناتك، وأصبحت الصفحة الرئيسية صفحة ثابتة يرسمها القالب (شكلها لم يتغير). اربط كل تدوينة بألعابها من صندوق «الألعاب المرتبطة».', 'retrovault-core' ),
			esc_url( admin_url( 'options-reading.php' ) ),
			esc_html__( 'إعدادات القراءة', 'retrovault-core' )
		);
	}
}
