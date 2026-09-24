<?php
/**
 * شاشة «الملتي كارت»: تلفاز فيه قائمة ألعاب مرقّمة كخراطيش «8 في 1».
 * بدون JavaScript هي قائمة روابط عادية؛ مع JavaScript تتنقل بالأسهم وتتغير المعاينة.
 *
 * @package RetroVault
 *
 * @var array $args games (WP_Post[]).
 */

defined( 'ABSPATH' ) || exit;

$rvt_games = isset( $args['games'] ) ? (array) $args['games'] : array();
$rvt_items = array();
foreach ( $rvt_games as $rvt_post ) {
	$rvt_g = rv_get_game( $rvt_post );
	if ( ! $rvt_g ) {
		continue;
	}
	$rvt_items[] = array(
		'game' => $rvt_g,
		'art'  => $rvt_g['cover_id'] ? (string) wp_get_attachment_image_url( $rvt_g['cover_id'], 'rvt-cover' ) : '',
	);
}
$rvt_first = $rvt_items ? $rvt_items[0] : null;
?>
<div class="tv tv--hero">
	<div class="tv__screen">
		<?php if ( ! $rvt_items ) : ?>
			<div class="mc mc--empty">
				<p class="mc__title"><span><?php echo esc_html( rvt_mod( 'rvt_menu_title' ) ); ?></span></p>
				<p class="mc__empty"><?php esc_html_e( 'لا توجد ألعاب بعد', 'retrovault' ); ?></p>
				<?php if ( current_user_can( 'edit_posts' ) ) : ?>
					<p><a class="mc__add" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=rv_game' ) ); ?>"><?php esc_html_e( 'أضف أول لعبة', 'retrovault' ); ?></a></p>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<div class="mc" data-multicart>
				<p class="mc__title">
					<span><?php echo esc_html( rvt_mod( 'rvt_menu_title' ) ); ?></span>
					<span class="mc__count">
						<?php
						/* translators: %s: number of games in the menu */
						echo esc_html( sprintf( __( '%s في 1', 'retrovault' ), number_format_i18n( count( $rvt_items ) ) ) );
						?>
					</span>
				</p>
				<ol class="mc__list">
					<?php foreach ( $rvt_items as $rvt_i => $rvt_item ) : ?>
						<?php
						$rvt_g   = $rvt_item['game'];
						$rvt_sys = $rvt_g['system'];
						?>
						<li class="mc__item<?php echo 0 === $rvt_i ? ' is-active' : ''; ?>">
							<a href="<?php echo esc_url( $rvt_g['url'] ); ?>"
								data-mc-item
								data-art="<?php echo esc_url( $rvt_item['art'] ); ?>"
								data-sys="<?php echo esc_attr( $rvt_sys ? $rvt_sys['short'] : '' ); ?>"
								data-sys-color="<?php echo esc_attr( $rvt_sys ? $rvt_sys['color'] : '' ); ?>"
								data-genre="<?php echo esc_attr( $rvt_g['genres'] ? $rvt_g['genres'][0]['name'] : '' ); ?>"
								tabindex="<?php echo 0 === $rvt_i ? '0' : '-1'; ?>">
								<span class="mc__cursor" aria-hidden="true"><?php echo rvt_icon( 'play', 'rvt-icon--flip' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<span class="mc__num" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $rvt_i + 1 ) ); ?></span>
								<span class="mc__name"><?php echo esc_html( $rvt_g['title'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ol>
				<div class="mc__preview" aria-hidden="true">
					<div class="mc__frame">
						<img class="mc__art" data-mc-art src="<?php echo esc_url( $rvt_first['art'] ); ?>" alt="" <?php echo $rvt_first['art'] ? '' : 'hidden'; ?>>
						<span class="mc__blank" data-mc-blank <?php echo $rvt_first['art'] ? 'hidden' : ''; ?>><?php echo esc_html( $rvt_first['game']['system'] ? $rvt_first['game']['system']['short'] : '' ); ?></span>
					</div>
					<p class="mc__meta">
						<span class="mc__sys" data-mc-sys style="--sys:<?php echo esc_attr( $rvt_first['game']['system'] ? $rvt_first['game']['system']['color'] : '' ); ?>"><?php echo esc_html( $rvt_first['game']['system'] ? $rvt_first['game']['system']['short'] : '' ); ?></span>
						<span data-mc-genre><?php echo esc_html( $rvt_first['game']['genres'] ? $rvt_first['game']['genres'][0]['name'] : '' ); ?></span>
					</p>
				</div>
				<p class="mc__hint">
					<span class="mc__hint--keys"><?php esc_html_e( '↑ ↓ للتنقل — Enter للعب', 'retrovault' ); ?></span>
					<span class="mc__hint--touch"><?php esc_html_e( 'اضغط على اسم لعبة لفتحها', 'retrovault' ); ?></span>
				</p>
			</div>
		<?php endif; ?>
	</div>
	<div class="tv__foot" aria-hidden="true">
		<span class="tv__led"></span>
		<span class="tv__brand"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
		<span class="tv__knobs"><i></i><i></i></span>
	</div>
</div>
