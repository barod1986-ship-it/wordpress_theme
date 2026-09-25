<?php
/**
 * صفحة اللعبة: ترويسة بعرض الصفحة، ثم المشغّل وتحته أزرار التحكم، وبجانبه التقييم والتفاصيل،
 * ثم الوصف واللقطات وبقية الأقسام تحت المشغّل.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	$rvt_game = rv_get_game();
	if ( ! $rvt_game ) {
		continue;
	}
	$rvt_sys      = $rvt_game['system'];
	$rvt_color    = $rvt_sys ? $rvt_sys['color'] : '#5a5864';
	$rvt_controls = ( $rvt_sys && $rvt_sys['key'] ) ? rv_get_controls( $rvt_sys['key'] ) : array();
	$rvt_rating   = $rvt_game['rating'];
	$rvt_style    = sprintf( '--sys:%1$s;--rv-ratio:%2$s', $rvt_color, $rvt_sys ? $rvt_sys['ratio'] : '4/3' );
	// صور الصفحة (العريضة واللقطات) باستعلامين بدل استعلامين لكل صورة.
	_prime_post_caches( array_filter( array_merge( array( $rvt_game['banner_id'] ), $rvt_game['screenshots'] ) ), false, true );
	?>
	<article id="post-<?php the_ID(); ?>" <?php post_class( 'game' ); ?> style="<?php echo esc_attr( $rvt_style ); ?>">
		<div class="shell">
			<?php rvt_breadcrumbs(); ?>

			<header class="game-hero">
				<div class="cart game-hero__cart" style="--sys:<?php echo esc_attr( $rvt_color ); ?>" aria-hidden="true">
					<div class="cart__shell">
						<div class="cart__label">
							<?php
							if ( $rvt_game['cover_id'] ) {
								echo rvt_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									$rvt_game['cover_id'],
									'rvt-cover',
									array(
										'class'         => 'cart__art',
										'alt'           => '',
										// صورة المشغّل هي الأكبر في الشاشة، فلا تأخذ الخرطوشة الصغيرة أولويتها.
										'fetchpriority' => 'auto',
									),
									'(max-width: 640px) 88px, 120px'
								);
							} else {
								echo '<div class="cart__art cart__art--blank"><span>' . esc_html( $rvt_sys ? $rvt_sys['short'] : '?' ) . '</span></div>';
							}
							?>
						</div>
					</div>
				</div>

				<div class="game-hero__head">
					<div class="game-badges">
						<?php
						echo rvt_system_chip( $rvt_sys ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo rvt_status_chip( $rvt_game ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						if ( $rvt_game['version'] ) {
							/* translators: %s: version */
							echo '<span class="chip">' . esc_html( sprintf( __( 'الإصدار %s', 'retrovault' ), $rvt_game['version'] ) ) . '</span>';
						}
						if ( function_exists( 'rv_pwa_enabled' ) && rv_pwa_enabled() ) {
							printf(
								'<span class="chip chip--offline" data-rv-offline-badge data-key="%1$s" data-version="%4$s" hidden>%2$s%3$s</span>',
								esc_attr( rv_offline_key( $rvt_game['id'] ) ),
								rvt_icon( 'check' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								esc_html__( 'تعمل بدون إنترنت على هذا الجهاز', 'retrovault' ),
							esc_attr( $rvt_game['rom']['ver'] )
							);
						}
						?>
					</div>
					<h1 class="game-title"><?php the_title(); ?></h1>
				</div>

				<?php if ( ! empty( $rvt_game['lede'] ) ) : ?>
					<p class="game-lede"><?php echo esc_html( $rvt_game['lede'] ); ?></p>
				<?php endif; ?>

				<ul class="game-facts">
					<li>
						<a class="game-score" href="#rate">
							<?php echo rvt_stars( $rvt_rating['average'], array( 'live' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<strong data-rating-avg><?php echo $rvt_rating['count'] ? esc_html( number_format_i18n( $rvt_rating['average'], 1 ) ) : '—'; ?></strong>
							<span data-rating-count><?php echo esc_html( rvt_count( $rvt_rating['count'], 'ratings' ) ); ?></span>
						</a>
					</li>
					<li>
						<?php
						echo rvt_icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo esc_html(
							$rvt_game['plays']
								/* translators: %s: play count, e.g. "5 مرات" */
								? sprintf( __( 'لُعبت %s', 'retrovault' ), rvt_count( $rvt_game['plays'], 'plays' ) )
								: rvt_count( 0, 'plays' )
						);
						?>
					</li>
				</ul>

				<div class="game-actions">
					<?php rvt_favorite_button( $rvt_game ); ?>
					<button type="button" class="btn btn--ghost btn--sm" data-share data-title="<?php echo esc_attr( $rvt_game['title'] ); ?>" data-url="<?php echo esc_url( $rvt_game['url'] ); ?>">
						<?php echo rvt_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php esc_html_e( 'مشاركة', 'retrovault' ); ?>
					</button>
					<?php if ( $rvt_game['downloadable'] ) : ?>
						<a class="btn btn--pill btn--sm" href="<?php echo esc_url( $rvt_game['download_url'] ); ?>" rel="nofollow">
							<?php echo rvt_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php
							esc_html_e( 'تنزيل الملف', 'retrovault' );
							if ( $rvt_game['rom']['ext'] ) {
								echo ' <bdi dir="ltr">.' . esc_html( $rvt_game['rom']['ext'] ) . '</bdi>';
							}
							?>
						</a>
					<?php endif; ?>
				</div>
			</header>

			<div class="game-layout">
				<div class="game-stage">
					<?php rv_player(); ?>
					<?php if ( $rvt_game['playable'] ) : ?>
						<p class="game-stage__tip"><?php rvt_stage_tip( $rvt_game ); ?></p>

						<section class="panel game-keys" id="controls" aria-labelledby="controls-title">
							<h2 class="panel__title" id="controls-title"><?php esc_html_e( 'التحكم بلوحة المفاتيح', 'retrovault' ); ?></h2>
							<?php if ( $rvt_controls ) : ?>
								<dl class="keymap">
									<?php foreach ( $rvt_controls as $rvt_row ) : ?>
										<div class="keymap__item">
											<dt><?php echo esc_html( $rvt_row['label'] ); ?></dt>
											<dd>
												<?php foreach ( $rvt_row['keys'] as $rvt_key ) : ?>
													<kbd><?php echo esc_html( $rvt_key ); ?></kbd>
												<?php endforeach; ?>
											</dd>
										</div>
									<?php endforeach; ?>
								</dl>
							<?php endif; ?>
							<?php if ( $rvt_game['controls'] ) : ?>
								<div class="game-keys__notes"><?php echo wp_kses_post( wpautop( $rvt_game['controls'] ) ); ?></div>
							<?php endif; ?>
							<p class="game-keys__pad">
								<?php echo rvt_icon( 'pad' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span><?php esc_html_e( 'يد التحكم تعمل تلقائياً عند توصيلها، وعلى الجوال تظهر أزرار لمس. لتغيير الأزرار افتح إعدادات التحكم من شريط المحاكي.', 'retrovault' ); ?></span>
							</p>
						</section>
					<?php endif; ?>
				</div>

				<aside class="game-side">
					<section class="panel" id="rate">
						<?php rvt_rating_box( $rvt_game ); ?>
					</section>

					<section class="panel" aria-labelledby="specs-title">
						<h2 class="panel__title" id="specs-title"><?php esc_html_e( 'تفاصيل', 'retrovault' ); ?></h2>
						<dl class="specs">
							<?php if ( $rvt_sys ) : ?>
								<dt><?php esc_html_e( 'النظام', 'retrovault' ); ?></dt>
								<dd><a href="<?php echo esc_url( $rvt_sys['link'] ); ?>"><?php echo esc_html( $rvt_sys['name'] ); ?></a></dd>
							<?php endif; ?>
							<?php if ( $rvt_game['genres'] ) : ?>
								<dt><?php esc_html_e( 'النوع', 'retrovault' ); ?></dt>
								<dd>
									<?php
									$rvt_links = array();
									foreach ( $rvt_game['genres'] as $rvt_genre ) {
										$rvt_links[] = '<a href="' . esc_url( $rvt_genre['link'] ) . '">' . esc_html( $rvt_genre['name'] ) . '</a>';
									}
									echo implode( '، ', $rvt_links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</dd>
							<?php endif; ?>
							<dt><?php esc_html_e( 'اللاعبون', 'retrovault' ); ?></dt>
							<dd><?php echo esc_html( rv_players_label( $rvt_game['players'] ) ); ?></dd>
							<?php if ( $rvt_game['year'] ) : ?>
								<dt><?php esc_html_e( 'سنة الإصدار', 'retrovault' ); ?></dt>
								<dd><?php echo esc_html( $rvt_game['year'] ); ?></dd>
							<?php endif; ?>
							<?php if ( $rvt_game['languages'] ) : ?>
								<dt><?php esc_html_e( 'اللغات', 'retrovault' ); ?></dt>
								<dd><?php echo esc_html( $rvt_game['languages'] ); ?></dd>
							<?php endif; ?>
							<dt><?php esc_html_e( 'آخر تحديث', 'retrovault' ); ?></dt>
							<dd><bdi><time datetime="<?php echo esc_attr( get_the_modified_date( 'c' ) ); ?>"><?php echo esc_html( get_the_modified_date() ); ?></time></bdi></dd>
							<?php if ( $rvt_game['rom']['size'] ) : ?>
								<dt><?php esc_html_e( 'حجم الملف', 'retrovault' ); ?></dt>
								<dd><bdi><?php echo esc_html( size_format( $rvt_game['rom']['size'], 1 ) ); ?></bdi></dd>
							<?php endif; ?>
						</dl>
					</section>
				</aside>

				<div class="game-main">
					<?php if ( ! empty( $rvt_game['has_content'] ) ) : ?>
						<section class="game-section" aria-labelledby="about-title">
							<h2 class="game-section__title" id="about-title"><?php esc_html_e( 'عن اللعبة', 'retrovault' ); ?></h2>
							<div class="entry"><?php the_content(); ?></div>
						</section>
					<?php endif; ?>

					<?php if ( $rvt_game['screenshots'] ) : ?>
						<section class="game-section" aria-labelledby="shots-title">
							<h2 class="game-section__title" id="shots-title"><?php esc_html_e( 'لقطات من اللعبة', 'retrovault' ); ?></h2>
							<div class="shots">
								<?php
								foreach ( $rvt_game['screenshots'] as $rvt_n => $rvt_shot ) :
									$rvt_full = wp_get_attachment_image_url( $rvt_shot, 'full' );
									if ( ! $rvt_full ) {
										continue;
									}
									$rvt_pixel = rv_is_pixel_image( $rvt_shot );
									/* translators: 1: game title, 2: shot number */
									$rvt_alt = sprintf( __( 'لقطة %2$s من %1$s', 'retrovault' ), $rvt_game['title'], number_format_i18n( $rvt_n + 1 ) );
									?>
									<a class="shot<?php echo $rvt_pixel ? ' is-pixel' : ''; ?>" href="<?php echo esc_url( $rvt_full ); ?>" data-shot data-alt="<?php echo esc_attr( $rvt_alt ); ?>">
										<?php
										echo rvt_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
											$rvt_shot,
											$rvt_pixel ? 'full' : 'medium_large',
											array(
												'alt'     => $rvt_alt,
												'loading' => 'lazy',
												'class'   => $rvt_pixel ? 'is-pixel' : '',
											),
											'(max-width: 640px) 50vw, 260px'
										);
										?>
									</a>
								<?php endforeach; ?>
							</div>
							<dialog class="lightbox" data-lightbox aria-label="<?php esc_attr_e( 'عارض اللقطات', 'retrovault' ); ?>">
								<img class="lightbox__img" src="data:," alt="">
								<div class="lightbox__bar">
									<button type="button" class="lightbox__btn" data-lb-prev aria-label="<?php esc_attr_e( 'السابقة', 'retrovault' ); ?>"><?php echo rvt_icon( 'prev', 'rvt-icon--flip' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
									<span class="lightbox__count" data-lb-count></span>
									<button type="button" class="lightbox__btn" data-lb-next aria-label="<?php esc_attr_e( 'التالية', 'retrovault' ); ?>"><?php echo rvt_icon( 'next', 'rvt-icon--flip' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
									<button type="button" class="lightbox__btn lightbox__close" data-lb-close aria-label="<?php esc_attr_e( 'إغلاق', 'retrovault' ); ?>"><?php echo rvt_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
								</div>
							</dialog>
						</section>
					<?php endif; ?>

					<?php if ( $rvt_game['trailer'] ) : ?>
						<section class="game-section" aria-labelledby="trailer-title">
							<h2 class="game-section__title" id="trailer-title"><?php esc_html_e( 'فيديو العرض', 'retrovault' ); ?></h2>
							<div class="embed">
								<?php
								global $wp_embed;
								echo $wp_embed->shortcode( array(), $rvt_game['trailer'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- oEmbed من مزود موثوق أو رابط مُهرَّب.
								?>
							</div>
						</section>
					<?php endif; ?>

					<?php if ( $rvt_game['changelog'] ) : ?>
						<section class="game-section">
							<details class="log">
								<summary><h2 class="game-section__title"><?php esc_html_e( 'سجل التحديثات', 'retrovault' ); ?></h2></summary>
								<div class="entry"><?php echo wp_kses_post( function_exists( 'rv_changelog_html' ) ? rv_changelog_html( $rvt_game['changelog'] ) : wpautop( $rvt_game['changelog'] ) ); ?></div>
							</details>
						</section>
					<?php endif; ?>

					<?php
					$rvt_log = rv_game_devlog( get_the_ID(), 3 );
					if ( $rvt_log->have_posts() ) :
						?>
						<section class="game-section" aria-labelledby="devlog-title">
							<h2 class="game-section__title" id="devlog-title"><?php esc_html_e( 'من يوميات التطوير', 'retrovault' ); ?></h2>
							<ol class="timeline timeline--compact">
								<?php foreach ( $rvt_log->posts as $rvt_post ) : ?>
									<li class="timeline__item" style="--dot:<?php echo esc_attr( $rvt_color ); ?>">
										<?php rvt_log_card( $rvt_post, array( 'chips' => false ) ); ?>
									</li>
								<?php endforeach; ?>
							</ol>
							<?php if ( rv_devlog_url() ) : ?>
								<p class="log-more"><a href="<?php echo esc_url( rv_devlog_url() ); ?>"><?php esc_html_e( 'كل يوميات التطوير', 'retrovault' ); ?></a></p>
							<?php endif; ?>
						</section>
					<?php endif; ?>

					<?php if ( $rvt_game['credits'] ) : ?>
						<section class="game-section" aria-labelledby="credits-title">
							<h2 class="game-section__title" id="credits-title"><?php esc_html_e( 'الشكر والمساهمون', 'retrovault' ); ?></h2>
							<div class="entry"><?php echo wp_kses_post( wpautop( $rvt_game['credits'] ) ); ?></div>
						</section>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<?php
		get_template_part(
			'template-parts/shelf',
			null,
			array(
				'id'    => 'shelf-related',
				'title' => __( 'ألعاب مشابهة', 'retrovault' ),
				'query' => rv_related_games( get_the_ID(), 6 ),
			)
		);
		?>

		<?php if ( comments_open() || get_comments_number() ) : ?>
			<div class="shell">
				<?php comments_template(); ?>
			</div>
		<?php endif; ?>
	</article>
	<?php
endwhile;

get_footer();
