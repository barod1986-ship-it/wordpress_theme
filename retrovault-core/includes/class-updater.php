<?php
/**
 * التحديثات من GitHub.
 *
 * الإضافة والقالب يحملان «Update URI» يشير إلى مستودع GitHub، فيسأل ووردبريس هذه الفئة
 * (لا wordpress.org) عن آخر إصدار: آخر Release منشور في المستودع وملفَي التثبيت المرفقين به
 * (retrovault-core.zip و retrovault-theme.zip). النتيجة: التحديث يظهر في «لوحة التحكم ← التحديثات»
 * ويُثبَّت بضغطة واحدة، للإضافة والقالب معاً.
 *
 * - طلب واحد لواجهة GitHub كل 6 ساعات على الأكثر (يُخزَّن الرد)، و«تحقق مرة أخرى» يتجاوز التخزين.
 * - لا يُقبل ملف تحديث إلا من صفحة Releases في المستودع نفسه.
 * - نافذة «عرض التفاصيل» تعرض ملاحظات الإصدار بدل البحث في wordpress.org.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Updater {

	const CACHE      = 'retrovault_github_release';
	const PLUGIN_ZIP = 'retrovault-core.zip';
	const THEME_ZIP  = 'retrovault-theme.zip';

	/** معرّف نافذة تفاصيل القالب (نافذة تفاصيل الإضافات تعرض بيانات أي معرّف نجيب عنه). */
	const THEME_INFO = 'retrovault-theme';

	/** @var array|null|false آخر إصدار في هذا الطلب (false = لم يُجلب بعد). */
	private static $release = false;

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'plugin_update' ), 10, 3 );
		add_filter( 'update_themes_github.com', array( __CLASS__, 'theme_update' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
	}

	/**
	 * «owner/repo» من ترويسة Update URI في الإضافة، أو فارغ إن لم تكن مستودع GitHub.
	 */
	public static function repo() {
		static $repo = null;
		if ( null === $repo ) {
			$headers = get_file_data( RETROVAULT_FILE, array( 'uri' => 'Update URI' ) );
			$repo    = preg_match( '#^https://github\.com/([\w.-]+/[\w.-]+?)/?$#', trim( (string) $headers['uri'] ), $m ) ? $m[1] : '';
		}
		return $repo;
	}

	/**
	 * آخر إصدار منشور في المستودع، أو null (لا يوجد إصدار، أو تعذّر الاتصال).
	 *
	 * @return array{version:string,url:string,notes:string,date:string,plugin:string,theme:string}|null
	 */
	public static function release() {
		if ( false !== self::$release ) {
			return self::$release;
		}
		$repo = self::repo();
		if ( '' === $repo ) {
			self::$release = null;
			return null;
		}
		$cache = get_site_transient( self::CACHE );
		if ( is_array( $cache ) && isset( $cache['repo'] ) && $repo === $cache['repo'] && array_key_exists( 'release', $cache ) && ! self::forced() ) {
			self::$release = $cache['release'];
			return self::$release;
		}

		$release  = null;
		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'RetroVault/' . RETROVAULT_VERSION . '; ' . home_url( '/' ),
				),
			)
		);
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$release = self::parse( json_decode( wp_remote_retrieve_body( $response ), true ), $repo );
		}
		// الفشل يُخزَّن ساعة فقط، حتى لا يتأخر ظهور إصدار نُشر للتو.
		set_site_transient(
			self::CACHE,
			array(
				'repo'    => $repo,
				'release' => $release,
			),
			$release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS
		);
		self::$release = $release;
		return $release;
	}

	/** زر «تحقق مرة أخرى» في صفحة التحديثات يتجاوز الرد المخزّن. */
	private static function forced() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- قراءة فقط، والنتيجة طلب لـ GitHub لا أكثر.
		return is_admin() && isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' );
	}

	/**
	 * @param mixed  $json رد واجهة GitHub.
	 * @param string $repo المستودع.
	 * @return array|null
	 */
	private static function parse( $json, $repo ) {
		if ( ! is_array( $json ) || ! empty( $json['draft'] ) || ! empty( $json['prerelease'] ) || empty( $json['tag_name'] ) ) {
			return null;
		}
		$version = ltrim( (string) $json['tag_name'], 'vV' );
		if ( ! preg_match( '/^\d+(\.\d+){0,3}$/', $version ) ) {
			return null;
		}

		// ملفات التثبيت تُقبل فقط من صفحة Releases في المستودع نفسه.
		$base   = 'https://github.com/' . $repo . '/releases/';
		$assets = array();
		foreach ( isset( $json['assets'] ) ? (array) $json['assets'] : array() as $asset ) {
			$url = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
			if ( isset( $asset['name'] ) && 0 === strpos( $url, $base . 'download/' ) && ( ! isset( $asset['state'] ) || 'uploaded' === $asset['state'] ) ) {
				$assets[ (string) $asset['name'] ] = $url;
			}
		}
		$page = isset( $json['html_url'] ) ? (string) $json['html_url'] : '';

		return array(
			'version' => $version,
			'url'     => 0 === strpos( $page, $base ) ? $page : $base,
			'notes'   => isset( $json['body'] ) ? (string) $json['body'] : '',
			'date'    => isset( $json['published_at'] ) ? (string) $json['published_at'] : '',
			'plugin'  => isset( $assets[ self::PLUGIN_ZIP ] ) ? $assets[ self::PLUGIN_ZIP ] : '',
			'theme'   => isset( $assets[ self::THEME_ZIP ] ) ? $assets[ self::THEME_ZIP ] : '',
		);
	}

	/**
	 * تحديث الإضافة.
	 *
	 * @param array|false $update      الرد (false إن لم يُجب أحد بعد).
	 * @param array       $plugin_data ترويسات الإضافة.
	 * @param string      $plugin_file ملف الإضافة.
	 * @return array|false
	 */
	public static function plugin_update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( RETROVAULT_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::release();
		if ( ! $release || '' === $release['plugin'] ) {
			return $update;
		}
		return array(
			'slug'    => dirname( $plugin_file ),
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['plugin'],
			'icons'   => array(
				'1x' => RETROVAULT_URL . 'assets/icon-192.png',
				'2x' => RETROVAULT_URL . 'assets/icon-512.png',
			),
		);
	}

	/**
	 * تحديث القالب (القالب الذي يحمل Update URI نفسه).
	 *
	 * @param array|false $update     الرد.
	 * @param array       $theme_data ترويسات القالب.
	 * @param string      $stylesheet مجلد القالب.
	 * @return array|false
	 */
	public static function theme_update( $update, $theme_data, $stylesheet ) {
		if ( ! isset( $theme_data['UpdateURI'] ) || ! self::is_ours( $theme_data['UpdateURI'] ) ) {
			return $update;
		}
		$release = self::release();
		if ( ! $release || '' === $release['theme'] ) {
			return $update;
		}
		return array(
			'theme'   => $stylesheet,
			'version' => $release['version'],
			// صفحة GitHub لا تُفتح داخل إطار؛ رابط «التفاصيل» يفتح نافذة ملاحظات الإصدار في لوحة التحكم.
			'url'     => self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . self::THEME_INFO . '&section=changelog' ),
			'package' => $release['theme'],
		);
	}

	/**
	 * @param string $uri ترويسة Update URI.
	 */
	private static function is_ours( $uri ) {
		return '' !== self::repo() && untrailingslashit( trim( (string) $uri ) ) === 'https://github.com/' . self::repo();
	}

	/**
	 * نافذة «عرض التفاصيل» للإضافة والقالب: ملاحظات آخر إصدار من GitHub.
	 * بدونها يبحث ووردبريس في wordpress.org عن إضافة بالاسم نفسه.
	 *
	 * @param false|object|array $result النتيجة.
	 * @param string             $action نوع الطلب.
	 * @param object             $args   الوسائط.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		$slug = dirname( plugin_basename( RETROVAULT_FILE ) );
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || ! in_array( $args->slug, array( $slug, self::THEME_INFO ), true ) || '' === self::repo() ) {
			return $result;
		}

		if ( self::THEME_INFO === $args->slug ) {
			$theme = null;
			foreach ( wp_get_themes() as $candidate ) {
				if ( self::is_ours( $candidate->get( 'UpdateURI' ) ) ) {
					$theme = $candidate;
					break;
				}
			}
			$headers = array(
				'Name'        => $theme ? $theme->get( 'Name' ) : 'RetroVault',
				'Version'     => $theme ? $theme->get( 'Version' ) : '',
				'Author'      => $theme ? $theme->get( 'Author' ) : '',
				'Description' => $theme ? $theme->get( 'Description' ) : '',
				'RequiresWP'  => $theme ? $theme->get( 'RequiresWP' ) : '',
				'RequiresPHP' => $theme ? $theme->get( 'RequiresPHP' ) : '',
			);
		} else {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$headers = get_plugin_data( RETROVAULT_FILE, false, false );
		}

		$release = self::release();
		$page    = $release ? $release['url'] : 'https://github.com/' . self::repo() . '/releases';
		$notes   = $release ? self::markdown( $release['notes'] ) : '<p>' . esc_html__( 'تعذّر جلب ملاحظات الإصدار الآن. حاول لاحقاً.', 'retrovault-core' ) . '</p>';

		$info = (object) array(
			'name'         => $headers['Name'],
			'slug'         => $args->slug,
			'version'      => $release ? $release['version'] : $headers['Version'],
			'author'       => $headers['Author'],
			'homepage'     => 'https://github.com/' . self::repo(),
			'requires'     => $headers['RequiresWP'],
			'requires_php' => $headers['RequiresPHP'],
			'last_updated' => $release ? $release['date'] : '',
			'sections'     => array(
				'description' => '<p>' . esc_html( wp_strip_all_tags( $headers['Description'] ) ) . '</p>',
				'changelog'   => $notes . sprintf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $page ), esc_html__( 'صفحة الإصدار على GitHub', 'retrovault-core' ) ),
			),
		);
		// زر «ثبّت التحديث» في النافذة للإضافة فقط؛ القالب يُحدَّث من صفحة القوالب أو التحديثات.
		if ( $release && $slug === $args->slug && '' !== $release['plugin'] ) {
			$info->download_link = $release['plugin'];
		}
		return $info;
	}

	/**
	 * Markdown ملاحظات الإصدار ← HTML بسيط تسمح به نافذة التفاصيل
	 * (عناوين، قوائم، فقرات، عريض، كود، روابط).
	 *
	 * @param string $text النص.
	 * @return string
	 */
	public static function markdown( $text ) {
		$html = '';
		$list = false;
		// كل التعابير بـ /u: بدونها يُعدّ البايت 0x85 داخل الحرف «م» فاصل سطر.
		foreach ( (array) preg_split( '/\R/u', (string) $text ) as $line ) {
			$line = trim( $line );
			$item = preg_match( '/^(?:[-*+]|\d+\.)\s+(.+)$/u', $line, $m );
			if ( $list && ! $item ) {
				$html .= '</ul>';
				$list  = false;
			}
			if ( '' === $line ) {
				continue;
			}
			if ( $item ) {
				$html .= ( $list ? '' : '<ul>' ) . '<li>' . self::inline( $m[1] ) . '</li>';
				$list  = true;
			} elseif ( preg_match( '/^#{1,6}\s+(.+)$/u', $line, $m ) ) {
				$html .= '<h4>' . self::inline( $m[1] ) . '</h4>';
			} else {
				$html .= '<p>' . self::inline( $line ) . '</p>';
			}
		}
		return $html . ( $list ? '</ul>' : '' );
	}

	/**
	 * @param string $text سطر.
	 * @return string
	 */
	private static function inline( $text ) {
		$text = esc_html( $text );
		$text = preg_replace( '/`([^`]+)`/u', '<code>$1</code>', $text );
		$text = preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text );
		return preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u',
			static function ( $m ) {
				return '<a href="' . esc_url( html_entity_decode( $m[2], ENT_QUOTES ) ) . '">' . $m[1] . '</a>';
			},
			$text
		);
	}
}
