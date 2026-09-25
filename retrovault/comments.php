<?php
/**
 * التعليقات.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

if ( post_password_required() ) {
	return;
}

$rvt_is_game = 'rv_game' === get_post_type();
$rvt_members = function_exists( 'rv_members_only_comments' ) ? rv_members_only_comments() : $rvt_is_game;
$rvt_count   = (int) get_comments_number();
?>
<section id="comments" class="comments" aria-labelledby="comments-title">
	<h2 class="comments__title" id="comments-title">
		<?php echo rvt_icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo esc_html( rvt_count( $rvt_count, 'comments' ) ); ?>
	</h2>

	<?php if ( have_comments() ) : ?>
		<ol class="comment-list">
			<?php
			wp_list_comments(
				array(
					'callback'    => 'rvt_comment',
					'style'       => 'ol',
					'avatar_size' => 48,
				)
			);
			?>
		</ol>
		<?php
		the_comments_pagination(
			array(
				'prev_text' => rvt_icon( 'prev', 'rvt-icon--flip' ) . '<span class="screen-reader-text">' . esc_html__( 'تعليقات أقدم', 'retrovault' ) . '</span>',
				'next_text' => rvt_icon( 'next', 'rvt-icon--flip' ) . '<span class="screen-reader-text">' . esc_html__( 'تعليقات أحدث', 'retrovault' ) . '</span>',
			)
		);
		?>
	<?php endif; ?>

	<?php if ( ! comments_open() ) : ?>
		<?php if ( $rvt_count ) : ?>
			<p class="comments__closed"><?php esc_html_e( 'التعليقات مغلقة.', 'retrovault' ); ?></p>
		<?php endif; ?>
	<?php elseif ( $rvt_members && ! is_user_logged_in() ) : ?>
		<div class="members-only">
			<p>
				<?php
				echo esc_html(
					$rvt_is_game
						? __( 'التعليقات للأعضاء المسجّلين. سجّل دخولك لتشارك رأيك في اللعبة أو تبلّغ عن مشكلة واجهتك.', 'retrovault' )
						: __( 'التعليقات للأعضاء المسجّلين. سجّل دخولك لتشارك رأيك في هذه التدوينة.', 'retrovault' )
				);
				?>
			</p>
			<div class="members-only__actions">
				<a class="btn btn--a btn--sm" href="<?php echo esc_url( wp_login_url( get_permalink() . '#comments' ) ); ?>"><?php esc_html_e( 'دخول', 'retrovault' ); ?></a>
				<?php if ( get_option( 'users_can_register' ) ) : ?>
					<a class="btn btn--pill btn--sm" href="<?php echo esc_url( rvt_register_url( get_permalink() . '#comments' ) ); ?>"><?php esc_html_e( 'حساب جديد', 'retrovault' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	<?php else : ?>
		<?php
		comment_form(
			array(
				'title_reply'          => $rvt_is_game ? __( 'شارك رأيك في اللعبة', 'retrovault' ) : __( 'أضف تعليقاً', 'retrovault' ),
				/* translators: %s: author name */
				'title_reply_to'       => __( 'رد على %s', 'retrovault' ),
				'cancel_reply_link'    => __( 'إلغاء الرد', 'retrovault' ),
				'label_submit'         => __( 'نشر التعليق', 'retrovault' ),
				'class_submit'         => 'btn btn--a',
				'comment_notes_before' => '',
				'logged_in_as'         => '',
				'comment_field'        => sprintf(
					'<p class="comment-form-comment"><label for="comment">%1$s</label><textarea id="comment" name="comment" class="input" rows="5" maxlength="65525" required></textarea></p>',
					esc_html__( 'تعليقك', 'retrovault' )
				),
			)
		);
		?>
	<?php endif; ?>
</section>
