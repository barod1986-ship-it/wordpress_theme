<?php
/**
 * صفحة «حسابي» للأعضاء: الحفظ السحابي، المفضلة، التقييمات، وإعدادات الحساب.
 *
 * صفحة ووردبريس عادية تحتوي الرمز [retrovault_account] (تُنشأ تلقائياً)،
 * فتستطيع تغيير عنوانها ورابطها وإضافتها للقوائم كأي صفحة.
 * القالب يرسم محتواها عبر الخطاف retrovault_account، وإلا يظهر عرض بسيط افتراضي.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Account {

	const OPTION    = 'retrovault_account_page';
	const SHORTCODE = 'retrovault_account';
	const NAME_MAX  = 50;

	/**
	 * إرسال نموذج الإعدادات الذي لم يُحفظ في هذا الطلب: تُعرض الصفحة بما كتبه العضو والأخطاء.
	 *
	 * @var array{input:array,errors:array<string,string>}|null
	 */
	private static $submitted = null;

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'save_settings' ), 9 );
		add_action( 'template_redirect', array( __CLASS__, 'guard' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	public static function page_id() {
		$id = (int) get_option( self::OPTION );
		return ( $id && 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) ? $id : 0;
	}

	public static function url() {
		$id = self::page_id();
		return $id ? (string) get_permalink( $id ) : '';
	}

	public static function is_page() {
		$id = self::page_id();
		return $id && is_page( $id );
	}

	/** إنشاء الصفحة إن لم تكن موجودة (عند التفعيل أو الترقية). */
	public static function ensure_page() {
		if ( self::page_id() ) {
			return;
		}
		$existing = get_page_by_path( 'account' );
		if ( $existing && 'publish' === $existing->post_status && has_shortcode( $existing->post_content, self::SHORTCODE ) ) {
			update_option( self::OPTION, (int) $existing->ID );
			return;
		}
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => __( 'حسابي', 'retrovault-core' ),
				'post_name'      => 'account',
				'post_content'   => '[' . self::SHORTCODE . ']',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( self::OPTION, (int) $id );
		}
	}

	/** الزائر يُحوَّل لتسجيل الدخول ثم يعود للصفحة. والصفحة لا تُعرض داخل إطار من موقع آخر. */
	public static function guard() {
		if ( ! self::is_page() ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}
		Guard::no_framing();
	}

	/** حفظ «إعدادات الحساب»: عند النجاح تحويل للصفحة نفسها برسالة، وعند الخطأ تُعرض بالأخطاء. */
	public static function save_settings() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- يُتحقق من الرمز أدناه قبل أي تغيير.
		if ( ! isset( $_POST['rv_action'] ) || 'account_settings' !== $_POST['rv_action'] || ! is_user_logged_in() || ! self::is_page() ) {
			return;
		}
		$input = array(
			'name'             => isset( $_POST['rv_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rv_name'] ) ) : '',
			'email'            => isset( $_POST['rv_email'] ) ? sanitize_email( wp_unslash( $_POST['rv_email'] ) ) : '',
			// كلمات المرور كما وصلت، كما يقارنها wp_signon عند الدخول (انظر Signup::$password).
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- كلمات مرور لا تُعدَّل.
			'password'         => isset( $_POST['rv_pass_new'] ) && is_string( $_POST['rv_pass_new'] ) ? $_POST['rv_pass_new'] : '',
			'current_password' => isset( $_POST['rv_pass_current'] ) && is_string( $_POST['rv_pass_current'] ) ? $_POST['rv_pass_current'] : '',
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput
		);
		$nonce = isset( $_POST['_rv_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rv_nonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! wp_verify_nonce( $nonce, 'rv_account_settings' ) ) {
			self::$submitted = array(
				'input'  => $input,
				'errors' => array( 'form' => __( 'انتهت صلاحية الصفحة. أرسل النموذج مرة أخرى.', 'retrovault-core' ) ),
			);
			return;
		}
		$result = self::update_settings( wp_get_current_user(), $input );
		if ( $result['errors'] ) {
			self::$submitted = array(
				'input'  => $input,
				'errors' => $result['errors'],
			);
			return;
		}
		wp_safe_redirect( add_query_arg( 'rv_account', $result['password'] ? 'password' : 'saved', self::url() ) . '#settings' );
		exit;
	}

	/**
	 * يتحقق من إعدادات الحساب ويحفظها. تغيير البريد أو كلمة المرور يحتاج كلمة المرور الحالية.
	 *
	 * @param \WP_User $user  العضو.
	 * @param array    $input name وemail منقّحان، وpassword وcurrent_password كما وصلا في الطلب.
	 * @return array{errors:array<string,string>,password:bool} الأخطاء لكل حقل، وهل تغيّرت كلمة المرور.
	 */
	public static function update_settings( $user, $input ) {
		$input    = wp_parse_args(
			$input,
			array(
				'name'             => '',
				'email'            => '',
				'password'         => '',
				'current_password' => '',
			)
		);
		$name     = trim( (string) $input['name'] );
		$email    = trim( (string) $input['email'] );
		$password = (string) $input['password'];
		$errors   = array();

		$length = mb_strlen( $name, 'UTF-8' );
		if ( $length < 2 || $length > self::NAME_MAX ) {
			/* translators: %d: maximum name length */
			$errors['name'] = sprintf( __( 'اكتب اسماً يظهر للآخرين، من حرفين إلى %d حرفاً.', 'retrovault-core' ), self::NAME_MAX );
		}

		$new_email = 0 !== strcasecmp( $email, $user->user_email );
		$owner     = $new_email && is_email( $email ) ? (int) email_exists( $email ) : 0;
		if ( ! is_email( $email ) ) {
			$errors['email'] = __( 'اكتب بريداً إلكترونياً صحيحاً، مثل name@example.com.', 'retrovault-core' );
		} elseif ( $owner && $owner !== (int) $user->ID ) {
			$errors['email'] = __( 'هذا البريد مستخدم في حساب آخر.', 'retrovault-core' );
		}

		if ( '' !== $password && Signup::too_short( $password ) ) {
			/* translators: %d: minimum password length */
			$errors['password'] = sprintf( __( 'كلمة المرور الجديدة قصيرة: %d أحرف على الأقل.', 'retrovault-core' ), Signup::MIN_LENGTH );
		}

		if ( $new_email || '' !== $password ) {
			$current = (string) $input['current_password'];
			if ( '' === $current ) {
				$errors['current_password'] = __( 'اكتب كلمة مرورك الحالية لتغيير البريد أو كلمة المرور.', 'retrovault-core' );
			} elseif ( Guard::blocked( 'current-password', (string) $user->ID, 5 ) ) {
				// من فتح جلسة غيره (جهاز مشترك مثلاً) لا يجرّب كلمات المرور هنا بلا حد.
				$errors['current_password'] = __( 'محاولات خاطئة كثيرة. انتظر 15 دقيقة ثم حاول مرة أخرى.', 'retrovault-core' );
			} elseif ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
				Guard::fail( 'current-password', (string) $user->ID );
				$errors['current_password'] = __( 'كلمة المرور الحالية غير صحيحة.', 'retrovault-core' );
			}
		}

		if ( $errors ) {
			return array(
				'errors'   => $errors,
				'password' => false,
			);
		}

		// wp_update_user يزيل الشرطات المائلة من هذه الحقول، فتُمرَّر كما يصل الطلب.
		$data = array( 'ID' => $user->ID );
		if ( $name !== $user->display_name ) {
			$data['display_name'] = wp_slash( $name );
		}
		if ( $new_email ) {
			$data['user_email'] = wp_slash( $email );
		}
		if ( '' !== $password ) {
			$data['user_pass'] = $password;
		}
		if ( count( $data ) > 1 ) {
			// عند تغيّر البريد أو كلمة المرور يرسل ووردبريس تنبيهاً للبريد السابق، ويجدّد جلسة هذا الجهاز.
			$result = wp_update_user( $data );
			if ( is_wp_error( $result ) ) {
				return array(
					'errors'   => array( 'form' => wp_strip_all_tags( $result->get_error_message() ) ),
					'password' => false,
				);
			}
			if ( '' !== $password ) {
				\WP_Session_Tokens::get_instance( $user->ID )->destroy_others( wp_get_session_token() );
			}
		}
		return array(
			'errors'   => array(),
			'password' => '' !== $password,
		);
	}

	/**
	 * ما يعرضه نموذج الإعدادات: القيم الحالية أو ما كتبه العضو، والأخطاء أو رسالة الحفظ.
	 *
	 * @return array{login:string,name:string,email:string,errors:array<string,string>,notice:string}
	 */
	public static function settings_state() {
		$user  = wp_get_current_user();
		$state = array(
			'login'  => $user->user_login,
			'name'   => $user->display_name,
			'email'  => $user->user_email,
			'errors' => array(),
			'notice' => '',
		);
		if ( self::$submitted ) {
			$state['name']   = self::$submitted['input']['name'];
			$state['email']  = self::$submitted['input']['email'];
			$state['errors'] = self::$submitted['errors'];
			return $state;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- رسالة عرض فقط.
		$done = isset( $_GET['rv_account'] ) ? sanitize_key( $_GET['rv_account'] ) : '';
		if ( 'password' === $done ) {
			$state['notice'] = __( 'حُفظت إعدادات حسابك. تغيّرت كلمة المرور، وخرج حسابك من أجهزتك الأخرى.', 'retrovault-core' );
		} elseif ( 'saved' === $done ) {
			$state['notice'] = __( 'حُفظت إعدادات حسابك.', 'retrovault-core' );
		}
		return $state;
	}

	/**
	 * نموذج «إعدادات الحساب». القالب يضعه في قسمه ذي المعرّف settings ويتولى تنسيقه.
	 */
	public static function settings_form() {
		if ( ! is_user_logged_in() || ! self::page_id() ) {
			return;
		}
		$state  = self::settings_state();
		$errors = $state['errors'];
		$ids    = array(
			'name'             => 'rv-name',
			'email'            => 'rv-email',
			'password'         => 'rv-pass-new',
			'current_password' => 'rv-pass-current',
		);
		?>
		<form class="rv-form rv-account-form" method="post" action="<?php echo esc_url( self::url() . '#settings' ); ?>" novalidate>
			<?php if ( $state['notice'] ) : ?>
				<p class="rv-form__notice is-ok" role="status"><?php echo esc_html( $state['notice'] ); ?></p>
			<?php elseif ( $errors ) : ?>
				<div class="rv-form__notice is-error" role="alert">
					<p><?php esc_html_e( 'لم تُحفظ التغييرات:', 'retrovault-core' ); ?></p>
					<ul>
						<?php foreach ( $errors as $key => $message ) : ?>
							<li>
								<?php if ( isset( $ids[ $key ] ) ) : ?>
									<a href="#<?php echo esc_attr( $ids[ $key ] ); ?>"><?php echo esc_html( $message ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $message ); ?>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<input type="hidden" name="rv_action" value="account_settings">
			<?php wp_nonce_field( 'rv_account_settings', '_rv_nonce', false ); ?>
			<?php
			self::field(
				'login',
				'rv-login',
				__( 'اسم المستخدم', 'retrovault-core' ),
				array(
					'type'         => 'text',
					'value'        => $state['login'],
					'readonly'     => true,
					'autocomplete' => 'username',
					'dir'          => 'ltr',
				),
				esc_html__( 'تدخل به، ولا يتغيّر.', 'retrovault-core' ),
				$errors
			);
			self::field(
				'name',
				$ids['name'],
				__( 'الاسم الظاهر', 'retrovault-core' ),
				array(
					'type'         => 'text',
					'name'         => 'rv_name',
					'value'        => $state['name'],
					'maxlength'    => (string) self::NAME_MAX,
					'autocomplete' => 'nickname',
					'required'     => true,
				),
				esc_html__( 'يظهر للآخرين مع تعليقاتك.', 'retrovault-core' ),
				$errors
			);
			self::field(
				'email',
				$ids['email'],
				__( 'البريد الإلكتروني', 'retrovault-core' ),
				array(
					'type'         => 'email',
					'name'         => 'rv_email',
					'value'        => $state['email'],
					'autocomplete' => 'email',
					'spellcheck'   => 'false',
					'dir'          => 'ltr',
					'required'     => true,
				),
				esc_html__( 'لاستعادة كلمة المرور ورسائل الموقع، ولا يظهر لأحد.', 'retrovault-core' ),
				$errors
			);
			self::field(
				'password',
				$ids['password'],
				__( 'كلمة مرور جديدة', 'retrovault-core' ),
				array(
					'type'         => 'password',
					'name'         => 'rv_pass_new',
					'value'        => '',
					'autocomplete' => 'new-password',
					'spellcheck'   => 'false',
				),
				/* translators: %d: minimum password length */
				esc_html( sprintf( __( 'اتركها فارغة لتبقى كلمة مرورك كما هي. %d أحرف على الأقل.', 'retrovault-core' ), Signup::MIN_LENGTH ) ),
				$errors
			);
			self::field(
				'current_password',
				$ids['current_password'],
				__( 'كلمة المرور الحالية', 'retrovault-core' ),
				array(
					'type'         => 'password',
					'name'         => 'rv_pass_current',
					'value'        => '',
					'autocomplete' => 'current-password',
					'spellcheck'   => 'false',
				),
				esc_html__( 'مطلوبة فقط لتغيير البريد أو كلمة المرور.', 'retrovault-core' ) . ' ' . sprintf( '<a href="%1$s">%2$s</a>', esc_url( wp_lostpassword_url( self::url() ) ), esc_html__( 'نسيت كلمة المرور؟', 'retrovault-core' ) ),
				$errors
			);
			?>
			<p class="rv-form__submit"><button type="submit" class="rv-form__button"><?php esc_html_e( 'حفظ التغييرات', 'retrovault-core' ); ?></button></p>
		</form>
		<?php
		wp_print_inline_script_tag( Signup::toggle_script() );
		if ( ! current_theme_supports( 'retrovault' ) ) {
			// تنسيق أساسي للقوالب الأخرى؛ قالب RetroVault ينسّق النموذج بنفسه.
			echo '<style>.rv-account-form{display:grid;gap:1.1em;max-width:32rem}.rv-field__label{display:block;margin-bottom:.35em;font-weight:600}.rv-field__input{box-sizing:border-box;width:100%;padding:.55em .75em;font:inherit}.rv-field__pass{position:relative;display:block}.rv-field__pass .rv-field__input{padding-inline-end:5.5em}.rv-field__toggle{position:absolute;top:50%;inset-inline-end:.4em;transform:translateY(-50%)}.rv-field__hint,.rv-field__error{margin:.35em 0 0;font-size:.875em}.rv-field__error{padding-inline-start:.6em;border-inline-start:3px solid #c8323a}.rv-form__notice{margin:0;padding:.75em 1em;border-inline-start:4px solid #267a4b;background:rgba(38,122,75,.1)}.rv-form__notice.is-error{border-inline-start-color:#c8323a;background:rgba(200,50,58,.1)}.rv-form__notice ul{margin:.4em 0 0}</style>';
		}
	}

	/**
	 * حقل واحد: العنوان، الخطأ إن وُجد، ثم التوضيح. حقول كلمة المرور معها زر «إظهار».
	 *
	 * @param string $key    مفتاح الخطأ.
	 * @param string $id     معرّف الحقل.
	 * @param string $label  العنوان.
	 * @param array  $attrs  خصائص الحقل (true لخاصية بلا قيمة).
	 * @param string $hint   التوضيح (HTML مُهرَّب).
	 * @param array  $errors الأخطاء.
	 */
	private static function field( $key, $id, $label, $attrs, $hint, $errors ) {
		$error     = isset( $errors[ $key ] ) ? $errors[ $key ] : '';
		$described = array_filter( array( $error ? $id . '-error' : '', $hint ? $id . '-hint' : '' ) );
		$attrs     = array_merge( array( 'id' => $id ), $attrs, array( 'class' => 'rv-field__input' ) );
		if ( $described ) {
			$attrs['aria-describedby'] = implode( ' ', $described );
		}
		if ( $error ) {
			$attrs['aria-invalid'] = 'true';
		}
		$password = 'password' === $attrs['type'];
		echo '<div class="rv-field' . ( $error ? ' has-error' : '' ) . '">';
		printf( '<label class="rv-field__label" for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( $label ) );
		echo $password ? '<span class="rv-field__pass">' : '';
		echo '<input';
		foreach ( $attrs as $attr => $value ) {
			if ( true === $value ) {
				echo ' ' . esc_attr( $attr );
			} else {
				printf( ' %1$s="%2$s"', esc_attr( $attr ), esc_attr( $value ) );
			}
		}
		echo '>';
		if ( $password ) {
			printf(
				'<button type="button" class="rv-field__toggle" data-rv-toggle-pass aria-controls="%1$s" aria-pressed="false" aria-label="%2$s" hidden>%3$s</button></span>',
				esc_attr( $id ),
				/* translators: %s: field label */
				esc_attr( sprintf( __( 'إظهار %s', 'retrovault-core' ), $label ) ),
				esc_html__( 'إظهار', 'retrovault-core' )
			);
		}
		if ( $error ) {
			printf( '<p class="rv-field__error" id="%1$s-error">%2$s</p>', esc_attr( $id ), esc_html( $error ) );
		}
		if ( $hint ) {
			printf( '<p class="rv-field__hint" id="%1$s-hint">%2$s</p>', esc_attr( $id ), $hint ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- مُهرَّب عند الاستدعاء.
		}
		echo '</div>';
	}

	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		ob_start();
		if ( has_action( 'retrovault_account' ) ) {
			do_action( 'retrovault_account', get_current_user_id() );
		} else {
			self::fallback( get_current_user_id() );
		}
		return (string) ob_get_clean();
	}

	/**
	 * عرض بسيط يعمل مع أي قالب.
	 *
	 * @param int $user_id رقم العضو.
	 */
	private static function fallback( $user_id ) {
		$saves = Saves::enabled() ? Saves::for_user( $user_id ) : array();
		$favs  = Favorites::get( $user_id );
		echo '<div class="rv-account">';
		if ( $saves ) {
			echo '<h2>' . esc_html__( 'استكمل اللعب', 'retrovault-core' ) . '</h2><ul>';
			foreach ( $saves as $save ) {
				printf( '<li><a href="%1$s#resume">%2$s</a> — %3$s</li>', esc_url( get_permalink( $save['game_id'] ) ), esc_html( get_the_title( $save['game_id'] ) ), esc_html( $save['ago'] ) );
			}
			echo '</ul>';
		}
		echo '<h2>' . esc_html__( 'مفضلتي', 'retrovault-core' ) . '</h2>';
		if ( $favs ) {
			echo '<ul>';
			foreach ( $favs as $id ) {
				printf( '<li><a href="%1$s">%2$s</a></li>', esc_url( get_permalink( $id ) ), esc_html( get_the_title( $id ) ) );
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'لا توجد ألعاب في مفضلتك بعد.', 'retrovault-core' ) . '</p>';
		}
		echo '<h2 id="settings">' . esc_html__( 'إعدادات الحساب', 'retrovault-core' ) . '</h2>';
		self::settings_form();
		echo '</div>';
	}

	/**
	 * @param string[] $states حالات الصفحة.
	 * @param \WP_Post $post   الصفحة.
	 */
	public static function post_state( $states, $post ) {
		if ( (int) $post->ID === self::page_id() ) {
			$states['rv_account'] = __( 'صفحة حساب الأعضاء', 'retrovault-core' );
		}
		return $states;
	}

	/**
	 * @param array $robots توجيهات robots.
	 */
	public static function robots( $robots ) {
		if ( self::is_page() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}
}
