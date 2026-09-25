<?php
/**
 * صفحة «حسابي»: استكمل اللعب (الحفظ السحابي)، المفضلة، التقييمات، إعدادات الحساب.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

$rvt_user    = wp_get_current_user();
$rvt_cloud   = rv_cloud_saves_enabled();
$rvt_saves   = rv_get_saves( $rvt_user->ID );
$rvt_favs    = rv_get_favorites( $rvt_user->ID );
$rvt_ratings = rv_get_user_ratings( $rvt_user->ID );
$rvt_notices = function_exists( 'rv_get_notices' ) ? rv_get_notices( $rvt_user->ID, 10 ) : array();
$rvt_form    = function_exists( 'rv_account_settings_form' );
$rvt_space   = ( $rvt_cloud && function_exists( 'rv_saves_usage' ) ) ? rv_saves_usage( $rvt_user->ID ) : null;
?>
<div class="account-page">
	<header class="account-head">
		<?php echo get_avatar( $rvt_user->ID, 80, '', '', array( 'class' => 'account-head__avatar' ) ); ?>
		<div class="account-head__text">
			<h1 class="account-head__name"><?php echo esc_html( $rvt_user->display_name ); ?></h1>
			<p class="account-head__meta">
				<?php
				/* translators: %s: registration date */
				echo esc_html( sprintf( __( 'عضو منذ %s', 'retrovault' ), wp_date( get_option( 'date_format' ), strtotime( $rvt_user->user_registered . ' UTC' ) ) ) );
				?>
			</p>
		</div>
		<div class="account-head__actions">
			<a class="btn btn--pill btn--sm" href="<?php echo esc_url( $rvt_form ? '#settings' : get_edit_profile_url( $rvt_user->ID ) ); ?>"><?php esc_html_e( 'إعدادات الحساب', 'retrovault' ); ?></a>
			<a class="account-head__logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'تسجيل الخروج', 'retrovault' ); ?></a>
		</div>
	</header>

	<?php if ( function_exists( 'rv_get_notices' ) ) : ?>
		<section class="account-section" id="updates" aria-labelledby="updates-title">
			<h2 class="account-section__title" id="updates-title">
				<?php echo rvt_icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'الجديد في ألعابك', 'retrovault' ); ?>
			</h2>
			<?php if ( $rvt_notices ) : ?>
				<ol class="notices">
					<?php
					foreach ( $rvt_notices as $rvt_notice ) :
						$rvt_is_log = 'devlog' === $rvt_notice['type'];
						$rvt_title  = $rvt_is_log ? $rvt_notice['text'] : $rvt_notice['game_title'];
						/* translators: %s: game title */
						$rvt_desc = $rvt_is_log ? sprintf( __( 'تدوينة جديدة عن %s', 'retrovault' ), $rvt_notice['game_title'] ) : $rvt_notice['text'];
						?>
						<li class="notice-item<?php echo $rvt_notice['new'] ? ' is-new' : ''; ?>" style="--sys:<?php echo esc_attr( $rvt_notice['system'] ? $rvt_notice['system']['color'] : '#5a5864' ); ?>">
							<span class="notice-item__kind" aria-hidden="true"><?php echo rvt_icon( $rvt_is_log ? 'chat' : 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="notice-item__body">
								<a class="notice-item__title" href="<?php echo esc_url( $rvt_notice['url'] ); ?>"><?php echo esc_html( $rvt_title ); ?></a>
								<span class="notice-item__desc"><?php echo esc_html( $rvt_desc . ' — ' . $rvt_notice['ago'] ); ?></span>
							</span>
							<?php if ( $rvt_notice['new'] ) : ?>
								<span class="notice-item__new"><?php esc_html_e( 'جديد', 'retrovault' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<p class="account-empty"><?php esc_html_e( 'لا توجد تحديثات بعد. أضف ألعاباً لمفضلتك، وستظهر هنا إصداراتها الجديدة وتدويناتها.', 'retrovault' ); ?></p>
			<?php endif; ?>
			<?php if ( rv_follower_emails_enabled() ) : ?>
				<label class="notify-toggle">
					<input type="checkbox" data-notify-toggle <?php checked( rv_notify_email_enabled( $rvt_user->ID ) ); ?>>
					<?php esc_html_e( 'أرسل لي بريداً عند صدور تحديث لألعاب مفضلتي', 'retrovault' ); ?>
				</label>
			<?php endif; ?>
		</section>
		<?php rv_mark_notices_seen( $rvt_user->ID ); ?>
	<?php endif; ?>

	<?php if ( $rvt_cloud ) : ?>
		<section class="account-section" id="saves" aria-labelledby="saves-title">
			<h2 class="account-section__title" id="saves-title">
				<?php echo rvt_icon( 'cloud' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'استكمل اللعب', 'retrovault' ); ?>
				<?php if ( $rvt_saves ) : ?>
					<span class="count-badge"><?php echo esc_html( number_format_i18n( count( $rvt_saves ) ) ); ?></span>
				<?php endif; ?>
			</h2>
			<?php if ( $rvt_space && $rvt_space['used'] > 0 ) : ?>
				<?php $rvt_full = $rvt_space['used'] >= 0.8 * $rvt_space['quota']; ?>
				<p class="save-space<?php echo $rvt_full ? ' is-full' : ''; ?>">
					<label for="save-space"><?php esc_html_e( 'مساحة الحفظ', 'retrovault' ); ?></label>
					<meter id="save-space" min="0" max="<?php echo esc_attr( $rvt_space['quota'] ); ?>" high="<?php echo esc_attr( (int) ( 0.8 * $rvt_space['quota'] ) ); ?>" value="<?php echo esc_attr( $rvt_space['used'] ); ?>"></meter>
					<span>
						<?php
						echo wp_kses(
							/* translators: 1: used size, 2: quota */
							sprintf( esc_html__( '%1$s من %2$s', 'retrovault' ), '<bdi>' . esc_html( size_format( $rvt_space['used'], 1 ) ) . '</bdi>', '<bdi>' . esc_html( size_format( $rvt_space['quota'] ) ) . '</bdi>' ),
							array( 'bdi' => array() )
						);
						if ( $rvt_full ) {
							echo ' — ' . esc_html__( 'احذف الحفظات القديمة لتبقى مساحة للجديدة.', 'retrovault' );
						}
						?>
					</span>
				</p>
			<?php endif; ?>
			<?php if ( $rvt_saves ) : ?>
				<div class="save-grid">
					<?php
					foreach ( $rvt_saves as $rvt_group ) :
						$rvt_game = rv_get_game( $rvt_group['game_id'] );
						if ( ! $rvt_game ) {
							continue;
						}
						$rvt_sys    = $rvt_game['system'];
						$rvt_states = isset( $rvt_group['states'] ) ? $rvt_group['states'] : array();
						$rvt_sram   = isset( $rvt_group['sram'] ) ? $rvt_group['sram'] : null;
						$rvt_older  = array_slice( $rvt_states, 1 );
						$rvt_resume = $rvt_game['url'] . '#resume';
						?>
						<article class="save-card" data-save-card<?php echo $rvt_sram ? ' data-has-sram' : ''; ?> style="--sys:<?php echo esc_attr( $rvt_sys ? $rvt_sys['color'] : '#5a5864' ); ?>;--rv-ratio:<?php echo esc_attr( $rvt_sys ? $rvt_sys['ratio'] : '4/3' ); ?>">
							<a class="save-card__screen" href="<?php echo esc_url( $rvt_states ? $rvt_resume : $rvt_game['url'] ); ?>" tabindex="-1" aria-hidden="true">
								<span class="save-card__blank"><?php echo esc_html( $rvt_sys ? $rvt_sys['short'] : '' ); ?></span>
								<?php if ( $rvt_states && $rvt_group['shot_url'] ) : ?>
									<img data-state-ui src="<?php echo esc_url( $rvt_group['shot_url'] ); ?>" alt="" loading="lazy">
								<?php endif; ?>
							</a>
							<div class="save-card__body">
								<h3 class="save-card__title"><a href="<?php echo esc_url( $rvt_states ? $rvt_resume : $rvt_game['url'] ); ?>"><?php echo esc_html( $rvt_game['title'] ); ?></a></h3>
								<p class="save-card__meta">
									<?php echo rvt_system_chip( $rvt_sys, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php if ( $rvt_states ) : ?>
										<span data-state-ui>
											<?php
											/* translators: %s: time ago */
											echo esc_html( sprintf( __( 'آخر حفظ %s', 'retrovault' ), $rvt_group['ago'] ) );
											?>
										</span>
										<bdi data-state-ui><?php echo esc_html( size_format( $rvt_group['size'], 1 ) ); ?></bdi>
									<?php endif; ?>
								</p>
								<?php if ( $rvt_sram ) : ?>
									<p class="save-card__sram">
										<?php echo rvt_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php
										/* translators: %s: time ago */
										echo esc_html( sprintf( __( 'حفظ اللعبة الداخلي متزامن مع حسابك (آخر تحديث %s)', 'retrovault' ), $rvt_sram['ago'] ) );
										?>
									</p>
								<?php endif; ?>
								<?php if ( $rvt_states && $rvt_group['outdated'] ) : ?>
									<p class="save-card__warn" data-state-ui>
										<?php
										/* translators: 1: saved version, 2: current version */
										echo esc_html( sprintf( __( 'آخر حفظ على الإصدار %1$s واللعبة الآن على %2$s، وقد لا يعمل الاستكمال.', 'retrovault' ), $rvt_group['version'], $rvt_game['version'] ) );
										?>
									</p>
								<?php endif; ?>
								<div class="save-card__actions">
									<?php if ( $rvt_states ) : ?>
										<a class="btn btn--a btn--sm" data-state-ui href="<?php echo esc_url( $rvt_resume ); ?>"><?php echo rvt_icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'استكمل', 'retrovault' ); ?></a>
									<?php endif; ?>
									<a class="btn btn--a btn--sm" data-play-link href="<?php echo esc_url( $rvt_game['url'] ); ?>" <?php echo $rvt_states ? 'hidden' : ''; ?>><?php echo rvt_icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'العب', 'retrovault' ); ?></a>
									<?php if ( $rvt_states ) : ?>
										<button type="button" class="save-card__delete" data-state-ui data-delete-save data-game="<?php echo esc_attr( $rvt_game['id'] ); ?>"><?php echo rvt_icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'حذف الحفظات', 'retrovault' ); ?></button>
									<?php endif; ?>
								</div>
								<?php if ( $rvt_older ) : ?>
									<details class="save-slots" data-state-ui>
										<summary>
											<?php
											/* translators: %s: number of older saves */
											echo esc_html( sprintf( __( 'حفظات أقدم (%s)', 'retrovault' ), number_format_i18n( count( $rvt_older ) ) ) );
											?>
										</summary>
										<ol class="save-slots__list">
											<?php foreach ( $rvt_older as $rvt_state ) : ?>
												<li class="save-slot" data-save-slot>
													<span class="save-slot__screen">
														<?php if ( $rvt_state['shot_url'] ) : ?>
															<img src="<?php echo esc_url( $rvt_state['shot_url'] ); ?>" alt="" loading="lazy">
														<?php endif; ?>
													</span>
													<span class="save-slot__time"><?php echo esc_html( $rvt_state['ago'] ); ?></span>
													<a class="save-slot__resume" href="<?php echo esc_url( $rvt_game['url'] . '#resume=' . $rvt_state['slot'] ); ?>"><?php esc_html_e( 'استكمل من هنا', 'retrovault' ); ?></a>
													<button type="button" class="save-slot__delete" data-delete-save data-game="<?php echo esc_attr( $rvt_game['id'] ); ?>" data-slot="<?php echo esc_attr( $rvt_state['slot'] ); ?>" aria-label="<?php esc_attr_e( 'حذف هذا الحفظ', 'retrovault' ); ?>"><?php echo rvt_icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
												</li>
											<?php endforeach; ?>
										</ol>
									</details>
								<?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="account-empty"><?php esc_html_e( 'لا يوجد حفظ في حسابك بعد. أثناء اللعب اضغط «حفظ الحالة» في شريط المحاكي، والألعاب التي تحفظ من قائمتها تُزامَن تلقائياً؛ ستظهر هنا لتكمل منها من أي جهاز.', 'retrovault' ); ?></p>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<section class="account-section" id="favorites" aria-labelledby="favorites-title">
		<h2 class="account-section__title" id="favorites-title">
			<?php echo rvt_icon( 'heart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php esc_html_e( 'مفضلتي', 'retrovault' ); ?>
			<?php if ( $rvt_favs ) : ?>
				<span class="count-badge"><?php echo esc_html( number_format_i18n( count( $rvt_favs ) ) ); ?></span>
			<?php endif; ?>
		</h2>
		<?php if ( $rvt_favs ) : ?>
			<div class="cart-grid">
				<?php foreach ( $rvt_favs as $rvt_id ) : ?>
					<div class="fav-item" data-fav-item>
						<?php get_template_part( 'template-parts/game-card', null, array( 'post' => $rvt_id ) ); ?>
						<button type="button" class="fav-item__remove" data-favorite data-remove-on-off data-game="<?php echo esc_attr( $rvt_id ); ?>" aria-pressed="true">
							<?php echo rvt_icon( 'heart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'إزالة من المفضلة', 'retrovault' ); ?>
						</button>
					</div>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="account-empty">
				<?php esc_html_e( 'لا توجد ألعاب في مفضلتك بعد. اضغط «أضف للمفضلة» في صفحة أي لعبة لتجدها هنا.', 'retrovault' ); ?>
				<a href="<?php echo esc_url( rvt_library_url() ); ?>"><?php esc_html_e( 'تصفّح المكتبة', 'retrovault' ); ?></a>
			</p>
		<?php endif; ?>
	</section>

	<section class="account-section" id="ratings" aria-labelledby="ratings-title">
		<h2 class="account-section__title" id="ratings-title">
			<?php echo rvt_icon( 'star' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php esc_html_e( 'تقييماتي', 'retrovault' ); ?>
			<?php if ( $rvt_ratings ) : ?>
				<span class="count-badge"><?php echo esc_html( number_format_i18n( count( $rvt_ratings ) ) ); ?></span>
			<?php endif; ?>
		</h2>
		<?php if ( $rvt_ratings ) : ?>
			<ol class="my-ratings">
				<?php foreach ( $rvt_ratings as $rvt_rating ) : ?>
					<li class="my-ratings__item">
						<a class="my-ratings__title" href="<?php echo esc_url( get_permalink( $rvt_rating['game_id'] ) . '#rate' ); ?>"><?php echo esc_html( get_the_title( $rvt_rating['game_id'] ) ); ?></a>
						<?php echo rvt_stars( $rvt_rating['rating'], array( 'size' => 'sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<time class="my-ratings__date" datetime="<?php echo esc_attr( gmdate( 'c', $rvt_rating['time'] ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ), $rvt_rating['time'] ) ); ?></time>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php else : ?>
			<p class="account-empty"><?php esc_html_e( 'لم تقيّم أي لعبة بعد. تقييمك يساعد الآخرين يعرفون من أين يبدؤون.', 'retrovault' ); ?></p>
		<?php endif; ?>
	</section>

	<?php if ( $rvt_form ) : ?>
		<section class="account-section" id="settings" aria-labelledby="settings-title">
			<h2 class="account-section__title" id="settings-title">
				<?php echo rvt_icon( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'إعدادات الحساب', 'retrovault' ); ?>
			</h2>
			<?php rv_account_settings_form(); ?>
		</section>
	<?php endif; ?>
</div>
